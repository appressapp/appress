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
 *           build_information: { website_url },
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

		$variants = [];
		foreach ( $ctx['languages'] as $code ) {
			$code              = (string) $code;
			$variants[ $code ] = [
				'host'   => $this->resolve_host( $code, $ctx ),
				'config' => $this->build_variant_config( $code, $ctx ),
			];
		}

		$live_config['translatepress'] = [
			'default_language' => $ctx['default'],
			'languages'        => $variants,
		];

		return $live_config;
	}

	// ── Context ──────────────────────────────────────────────────────────

	/**
	 * @return array{default:string, languages:array, labels:array, website:string, nav_items:array, screens:array, first_launch_url:string}|null
	 */
	private function build_context( array $live_config, int $app_id ): ?array {
		if ( ! $this->translator->is_active() ) {
			return null;
		}
		// Per-app opt-in gate. Site-wide TRP can be running (other plugins
		// or the customer's own web frontend rely on it), but if the
		// Appress admin has the per-app integration toggle OFF, the
		// mobile app should not see the `translatepress` block at all.
		// Without this gate, the native side's
		// `applyVariantForActiveLanguage` still resolves the device
		// locale to a TRP variant and points AppressRootUrl at the
		// translated host — so an en_US device on a ka_GE-default site
		// would see English content even though the admin explicitly
		// disabled the integration. Returning null here means native
		// falls back to the bundle URL and TRP's own server-side
		// resolver picks the right language from cookies / browser
		// hints / site default. Symmetric with the customer's mental
		// model: "I turned it off → app is untouched by TRP."
		if ( ! Settings_Controller::is_enabled_for_app( $app_id ) ) {
			return null;
		}
		$settings = $this->translator->get_settings();
		if ( empty( $settings['languages'] ) || empty( $settings['default_language'] ) ) {
			return null;
		}

		$admin  = Settings_Controller::get_settings( $app_id );
		$labels = ! empty( $admin['enabled'] ) ? (array) ( $admin['strings'] ?? [] ) : [];

		return [
			'default'          => (string) $settings['default_language'],
			'languages'        => (array) $settings['languages'],
			'labels'           => $labels,
			'website'          => isset( $live_config['build_information']['website_url'] )
				? (string) $live_config['build_information']['website_url']
				: home_url(),
			'nav_items'        => (array) ( $live_config['bottom_navigation']['items'] ?? [] ),
			'screens'          => (array) ( $live_config['app_screens'] ?? [] ),
			'first_launch_url' => (string) ( $live_config['first_launch']['url'] ?? '' ),
		];
	}

	// ── Variant build ────────────────────────────────────────────────────

	/**
	 * Build the deep-merge override subtree for a language. Each section
	 * is a full snapshot of the language-dependent root paths — Object-
	 * assign / Object.merge / deepMerge style replacements on the native
	 * side simply overwrite those branches without walking child fields.
	 *
	 * Non-translated bottom_navigation settings (colors, font, etc.) stay
	 * on the root config; we only ship `bottom_navigation.items` here.
	 * Same logic for other sections: only the subkeys that actually carry
	 * language-dependent values get emitted, so deep-merge doesn't wipe
	 * sibling settings.
	 */
	private function build_variant_config( string $code, array $ctx ): array {
		$config = [
			'build_information' => [
				'website_url' => $this->resolve_host( $code, $ctx ),
			],
			'bottom_navigation' => [
				'items' => $this->translate_nav_items( $code, $ctx ),
			],
			'app_screens' => $this->translate_screen_rows( $code, $ctx ),
		];

		if ( $ctx['first_launch_url'] !== '' ) {
			$config['first_launch'] = [
				'url' => $this->translator->translate_url( $ctx['first_launch_url'], $code ),
			];
		}

		return $config;
	}

	/**
	 * Translated bottom-nav items. Only `title` is language-dependent
	 * here — the Live App Builder UI for bottom nav offers `screen`
	 * (linked via screen_id to an app_screens row) and `menu_toggle`
	 * (opens the side menu drawer). Neither path uses tab.url, so the
	 * url field is left untouched. URL translation for tab destinations
	 * happens in {@see translate_screen_rows} (covers screen-linked
	 * tabs) and the side-menu / role lookups native does at runtime.
	 *
	 * Every original field (icon, screen_id, type, indicator, custom_
	 * indicator_*, ...) carries over via the copy so a native deep-
	 * merge ends up with a fully usable item list — no orphan fields.
	 */
	private function translate_nav_items( string $code, array $ctx ): array {
		$out = [];
		foreach ( $ctx['nav_items'] as $item ) {
			$copy = $item;
			$id   = isset( $item['_id'] ) ? (string) $item['_id'] : '';
			if ( $id !== '' && ! empty( $ctx['labels'][ $id ][ $code ] ) ) {
				$copy['title'] = (string) $ctx['labels'][ $id ][ $code ];
			}
			$out[] = $copy;
		}
		return $out;
	}

	/**
	 * Translated app_screens rows. Two screen shapes need different URL
	 * resolution; both end up with a final `url` field native can load
	 * straight after the deep-merge:
	 *
	 *   - type=`custom_url` — admin pasted a URL into `screen.url`.
	 *     Translate it directly.
	 *
	 *   - type=`appress_screen` — admin picked a `Screens` CPT post via
	 *     `screen.wp_id`. Native can't compute permalinks (PHP-only), so
	 *     we resolve the post permalink here, then translate that. We
	 *     also fold the resolved URL into `copy.url` so native treats
	 *     both screen types uniformly (no `if (type == ...)` branches in
	 *     mobile code).
	 *
	 * All other fields (wp_id, type, role, reload_on_click, ...) carry
	 * over via the copy so native lookups by index keep working
	 * post-merge.
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
	 * `build_information.website_url` so it matches what admins entered
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
