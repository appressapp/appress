<?php

namespace Appress\Integration\Translatepress\Controllers;

use Appress\Controllers\Base_Controller;
use Appress\Integration\Translatepress\Services\Url_Translator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-language enrichment of the `app.boot` payload.
 *
 * The boot endpoint returns the same response for every device — the
 * Frontend cache layer keys by `update_time_hash` only, with no
 * per-device dimension. Server therefore cannot know (and does NOT
 * encode) which language a given device is currently displaying.
 * That `current` is native-side state, persisted in UserDefaults /
 * SharedPreferences and changed only by the in-app language switcher.
 *
 * Output shape — each variant ships a `config` subtree that MIRRORS the
 * root config shape so native can override with a single deep-merge
 * pass instead of walking the tree field by field:
 *
 *   translatepress: {
 *     default_language: "en_US",
 *     languages: {
 *       en_US: {
 *         host:   "https://site.com",               // shortcut accessor
 *         config: {                                  // mergeable override
 *           build_config: { website_url },
 *           bottom_navigation: { items: [ ... ] },   // full translated array
 *           app_screens:       [ ... ],              // full translated array
 *           first_launch:      { url }
 *         }
 *       },
 *       vi: { ... }
 *     }
 *   }
 *
 * Native switch-language pseudo-code:
 *
 *   const variant = config.translatepress.languages[newLang];
 *   AppressRootUrl.set(variant.host);                // before any reload
 *   deepMerge(config, variant.config);               // override language paths
 *   reloadMaster();                                  // single render pass
 *
 * Root payload is LEFT UNTOUCHED — it holds the admin's source values
 * (default-language text + URLs). Native applies the active variant on
 * top of that root when rendering, so a single cached boot response
 * serves every language switch without a re-fetch.
 *
 * Per-site emission policy: as soon as TRP is active + has ≥ 1
 * published language, every boot response carries the variants. The
 * per-app `enabled` toggle in Settings_Controller narrows ONLY label
 * translation — those values come from admins typing per-language text
 * in the TRP detail page, so they only exist when opted in. URL
 * translation is TRP-rule driven and applies unconditionally.
 *
 * Caching: single layer in Frontend_Controller's `update_time_hash`
 * version check. Settings_Controller bumps that hash on every admin
 * TRP save, so the next boot returns fresh variants automatically.
 * This filter has no internal cache.
 */
class Boot_Config_Controller extends Base_Controller {

	/** @var Url_Translator */
	private $translator;

	public function __construct() {
		$this->translator = new Url_Translator();
		parent::__construct();
	}

	protected function hooks() {
		$this->filter( 'appress/app/live_config', '@enrich', 10, 2 );
	}

	/**
	 * @param array $live_config
	 * @param int   $app_id
	 * @return array
	 */
	protected function enrich( $live_config, $app_id ) {
		$ctx = $this->build_context( $live_config, (int) $app_id );
		if ( $ctx === null ) {
			return $live_config;
		}

		// Per-language `slug` + a global `url_mode` — native rewrites
		// every baked URL at boot without needing a full per-language
		// `config` subtree. Saves kilobytes per language in the
		// binary AND lets translation text fixes propagate live
		// (native rewrites URL → customer server renders translated
		// content based on URL → no rebuild needed for content
		// edits). `labels` carries the admin-typed bottom-nav title
		// override map (keyed by tab `_id`) since those don't follow
		// a URL pattern — native swaps them in-place during the
		// active-language apply.
		$languages = [];
		foreach ( $ctx['languages'] as $code ) {
			$code = (string) $code;
			$languages[ $code ] = [
				'slug'   => $this->resolve_slug( $code, $ctx ),
				'host'   => $this->resolve_host( $code, $ctx ),
				'labels' => $this->resolve_nav_labels( $code, $ctx ),
			];
		}

		$live_config['translatepress'] = [
			'default_language' => $ctx['default'],
			'url_mode'         => $this->resolve_url_mode( $ctx ),
			'languages'        => $languages,
		];

		return $live_config;
	}

	/**
	 * Per-tab title override map for the active language. Pulled from
	 * the admin's `translatepress.strings` field (Vue tab editor) so
	 * native can swap nav titles in-place without a full subtree
	 * deep-merge. Returns `{ "<tab_id>": "<translated title>" }`.
	 */
	private function resolve_nav_labels( string $code, array $ctx ): array {
		$out = [];
		foreach ( $ctx['nav_items'] as $item ) {
			$id = isset( $item['_id'] ) ? (string) $item['_id'] : '';
			if ( $id === '' ) continue;
			$translated = $ctx['labels'][ $id ][ $code ] ?? '';
			if ( $translated !== '' ) {
				$out[ $id ] = (string) $translated;
			}
		}
		return $out;
	}

	/**
	 * Resolve TRP's URL slug for a language code. TRP stores this in
	 * its own settings — empty string for the default language (no
	 * prefix), the language code (or admin-customized slug) otherwise.
	 *
	 * @param string $code   Language code (vd `vi`, `de_DE`).
	 * @param array  $ctx    Context built from {@see build_context}.
	 * @return string Slug or empty for default language.
	 */
	private function resolve_slug( string $code, array $ctx ): string {
		if ( ! empty( $ctx['default'] ) && $code === $ctx['default'] ) {
			return '';
		}
		$url_converter = $this->trp_component( 'url_converter' );
		if ( $url_converter && method_exists( $url_converter, 'get_url_slug' ) ) {
			return (string) $url_converter->get_url_slug( $code );
		}
		return $code;
	}

	/**
	 * Detect TRP URL routing mode — `subdirectory` (the default,
	 * `example.com/vi/page`), `subdomain` (`vi.example.com/page`), or
	 * `custom` (per-language host map, no programmatic transform). The
	 * native side switches its rewrite function based on this.
	 *
	 * Detection prefers TRP's `add_subdirectory_to_default_language`
	 * setting heuristic but falls back to inspecting the languages'
	 * host map vs default host.
	 *
	 * @param array $ctx Context built from {@see build_context}.
	 * @return string `subdirectory` | `subdomain` | `custom`.
	 */
	private function resolve_url_mode( array $ctx ): string {
		if ( ! empty( $ctx['has_per_language_hosts'] ) ) {
			return 'subdomain';
		}
		return 'subdirectory';
	}

	/**
	 * Get a named TRP runtime component (vd `url_converter`) without
	 * tight-coupling to TRP's internal singleton names. Returns null
	 * when TRP isn't loaded or the component lookup misses.
	 *
	 * @param string $name TRP component key.
	 * @return object|null Component instance or null.
	 */
	private function trp_component( string $name ) {
		if ( ! class_exists( '\\TRP_Translate_Press' ) ) {
			return null;
		}
		$trp = \TRP_Translate_Press::get_trp_instance();
		if ( ! $trp || ! method_exists( $trp, 'get_component' ) ) {
			return null;
		}
		try {
			return $trp->get_component( $name );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	// ── Context ──────────────────────────────────────────────────────────

	/**
	 * @return array{default:string, languages:array, labels:array, website:string, nav_items:array, screens:array, first_launch_url:string}|null
	 */
	private function build_context( array $live_config, int $app_id ): ?array {
		if ( ! $this->translator->is_active() ) {
			return null;
		}
		// Per-app opt-in gate. Source of truth = the `translatepress`
		// object in the app's `build_config` (managed by the per-app
		// TranslatePress sub-tab + the Native Features toggle). Toggle
		// OFF here means: don't emit a `translatepress` block, native
		// falls back to TRP's own server-side resolver. Symmetric with
		// the customer's mental model: "I turned it off → app is
		// untouched by TRP."
		$tp_config = (array) ( $live_config['translatepress'] ?? [] );
		if ( empty( $tp_config['enabled'] ) ) {
			return null;
		}
		$settings = $this->translator->get_settings();
		if ( empty( $settings['languages'] ) || empty( $settings['default_language'] ) ) {
			return null;
		}

		$labels = (array) ( $tp_config['strings'] ?? [] );

		return [
			'default'          => (string) $settings['default_language'],
			'languages'        => (array) $settings['languages'],
			'labels'           => $labels,
			'website'          => isset( $live_config['build_config']['website_url'] )
				? (string) $live_config['build_config']['website_url']
				: home_url(),
			'nav_items'        => (array) ( $live_config['bottom_navigation']['items'] ?? [] ),
			'screens'          => (array) ( $live_config['app_screens'] ?? [] ),
			'first_launch_url' => (string) ( $live_config['first_launch']['url'] ?? '' ),
		];
	}

	// `build_variant_config`, `translate_nav_items`,
	// `translate_screen_rows` retired — the v1.0.0.32+ emit shape
	// ships `slug` + `url_mode` + `labels` only. Native rewrites
	// URLs in-place via slug/mode and swaps nav titles via the
	// labels map. No more per-language deep-merge subtree.

	/**
	 * Translated app_screens rows — retained only as a deprecated
	 * helper for external integrations that may still reflect on
	 * it. Internal callers (boot enrich, variant build) no longer
	 * emit `app_screens` in the variant payload.
	 *
	 * @deprecated 1.4.0 schema split removed `app_screens`. Kept to
	 *             preserve binary compat for plugins extending this
	 *             class; will be removed in a future release.
	 */
	private function translate_screen_rows( string $code, array $ctx ): array {
		$out = [];
		foreach ( $ctx['screens'] as $screen ) {
			$copy = $screen;
			$type = isset( $screen['type'] ) ? (string) $screen['type'] : 'custom_url';

			if ( $type === 'appress_screen' && ! empty( $screen['wp_id'] ) ) {
				$permalink = get_permalink( (int) $screen['wp_id'] );
				if ( $permalink ) {
					$copy['url'] = $this->translator->translate_url( (string) $permalink, $code );
				}
			} elseif ( ! empty( $screen['url'] ) ) {
				$copy['url'] = $this->translator->translate_url( (string) $screen['url'], $code );
			}

			$out[] = $copy;
		}
		return $out;
	}

	// ── Host resolution ──────────────────────────────────────────────────

	/**
	 * Per-language root host. Default language anchors on the admin-typed
	 * `build_config.website_url` so it matches what admins entered
	 * in App Information, even when TRP's
	 * `add-subdirectory-to-default-language=yes` would otherwise inject
	 * a redundant `/en` prefix. Non-default langs route through TRP.
	 */
	private function resolve_host( string $code, array $ctx ): string {
		if ( $code === $ctx['default'] ) {
			return untrailingslashit( $ctx['website'] );
		}
		return untrailingslashit( $this->translator->translate_url( $ctx['website'], $code ) );
	}
}
