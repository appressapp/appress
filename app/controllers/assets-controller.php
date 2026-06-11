<?php

namespace Appress\Controllers;

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * Single source of truth for every Appress asset registration.
 *
 * Four buckets in assets.config.php, each mapped to one source folder:
 *   - `dist_styles`  → `assets/dist/css/` (Vite admin CSS).
 *   - `dist_scripts` → `assets/dist/js/`  (Vite Vue bundles — auto-localized
 *                       with `Apppress_Config`, emitted as `type="module"`).
 *   - `styles`       → `assets/css/`      (plain CSS for shortcodes / widgets).
 *   - `scripts`      → `assets/js/`       (plain JS — integrations inject their
 *                       own localize payload via filter
 *                       `appress/assets/localize/{handle}`).
 *
 * Handle convention: `appress:{filename}`.
 *
 * Registered ONCE on `init` priority 1 — earliest universal hook that fires
 * in every request context (admin pages, wp_enqueue_scripts frontend,
 * Elementor editor which uses its own enqueue pipeline outside the
 * admin_enqueue_scripts chain, Bricks builder frontend takeover, REST,
 * AJAX, cron). After init:1 the whole handle table is live; callers just
 * `wp_enqueue_*` by handle.
 */
class Assets_Controller extends Base_Controller {

	/** Handles that point at `assets/dist/js/`. Keyed set; used by the module-tag filter. */
	private array $dist_script_handles = [];

	protected function hooks() {
		// WP canonical register hooks — one per request context. Priority 1
		// guarantees handles exist before any default-priority (10) consumer
		// runs (Elementor editor enqueue, Bricks builder enqueue, shortcodes).
		// The two hooks cover admin and frontend; WP only fires the one that
		// matches the request, so there's no double-register cost.
		$this->on( 'admin_enqueue_scripts', '@register_all', 1 );
		$this->on( 'wp_enqueue_scripts',    '@register_all', 1 );
		add_filter( 'script_loader_tag', [ $this, 'module_tag_for_dist_scripts' ], 10, 3 );
	}

	public function register_all() {
		$version = \Appress\get_assets_version();
		$cfg     = \Appress\config( 'assets' ) ?: [];
		$dir     = APPRESS_PLUGIN_DIR . 'assets/';
		$url     = APPRESS_PLUGIN_URL . 'assets/';

		// Styles — dist + plain share identical logic; only the sub-dir differs.
		foreach ( [ 'dist/css/' => $cfg['dist_styles'] ?? [], 'css/' => $cfg['styles'] ?? [] ] as $sub => $files ) {
			foreach ( $files as $filename ) {
				$handle = 'appress:' . $filename;
				if ( wp_style_is( $handle, 'registered' ) || ! file_exists( $dir . $sub . $filename ) ) continue;
				wp_register_style( $handle, $url . $sub . $filename, [], $version );
			}
		}

		// Scripts — dist bundles get shared `Apppress_Config` + module-tag
		// tracking; plain scripts get the per-integration localize filter. Shared
		// config is built on the first dist hit (skipped entirely if none).
		$shared = null;
		foreach ( [ 'dist/js/' => [ $cfg['dist_scripts'] ?? [], true ], 'js/' => [ $cfg['scripts'] ?? [], false ] ] as $sub => [ $files, $is_dist ] ) {
			foreach ( $files as $filename ) {
				$handle = 'appress:' . $filename;
				if ( wp_script_is( $handle, 'registered' ) || ! file_exists( $dir . $sub . $filename ) ) continue;
				wp_register_script( $handle, $url . $sub . $filename, [], $version, true );

				if ( $is_dist ) {
					$this->dist_script_handles[ $handle ] = true;
					$shared ??= [
						'ajaxUrl'      => home_url( '/?appress=1' ),
						'homeUrl'      => home_url(),
						'pluginUrl'    => APPRESS_PLUGIN_URL,
						'schema'       => \Appress\config( 'schema' ) ?: [],
						// Vue admin UI translation dict — sourced from
						// `app/i18n/vue-strings.php` which calls __()
						// on every `t()` argument so xgettext / Loco
						// scan picks them up. `useI18n.js` reads this
						// dict at runtime; missing keys fall back to
						// the source string.
						'i18n'         => file_exists( APPRESS_PLUGIN_DIR . 'app/i18n/vue-strings.php' )
							? require APPRESS_PLUGIN_DIR . 'app/i18n/vue-strings.php'
							: [],
						// Per-integration runtime status (host plugin
						// installed? plugin-specific context the admin
						// Vue layer needs to gate UI on). Shared across
						// every dist-script entry so any admin page can
						// decide whether to render an integration's
						// configuration UI without a second AJAX round
						// trip. Currently consumed by:
						//   - SingleAppView's TranslatePress sub-tab gate
						//   - getNativeFeatureToggles skip filter for
						//     features tagged `ui.requires_plugin`.
						'integrations' => self::collect_integration_status(),
						// Public post-types the admin can pick screen URLs
						// from. Used by `PostSearchPicker.vue` (Home Screen,
						// First Launch, Auth Gate, Side Menus, Bottom Nav
						// items) to build the Content Source dropdown — admins
						// can pick a Page / Post / Product / etc. without
						// hand-typing the URL. The list is filtered via
						// `appress/admin/pickable_post_types` so 3rd-party
						// integrations (Voxel post types, WooCommerce
						// variations, custom CPTs) can opt in.
						'post_types'   => self::collect_pickable_post_types(),
					];
					wp_localize_script( $handle, 'Apppress_Config', $shared );
					continue;
				}

				$payload = apply_filters( 'appress/assets/localize/' . $handle, [], $handle );
				if ( ! is_array( $payload ) ) continue;
				foreach ( $payload as $var => $data ) {
					if ( is_string( $var ) && is_array( $data ) ) {
						wp_localize_script( $handle, $var, $data );
					}
				}
			}
		}
	}

	/**
	 * Emit `type="module"` on dist bundles so Vite's code-split commons chunk
	 * resolves via native ES imports. Handle-set lookup (not URL substring
	 * match) so a plain file whose name happens to contain "dist" can't
	 * trip the tag rewrite.
	 */
	public function module_tag_for_dist_scripts( $tag, $handle, $src ) {
		if ( ! isset( $this->dist_script_handles[ $handle ] ) ) {
			return $tag;
		}
		// `$tag` is the FULL output WP_Scripts::do_item built — main src
		// tag PLUS any `wp_localize_script` / `wp_add_inline_script` (before
		// + after) blocks attached to this handle. Rewriting `$tag` with
		// just the src tag silently DROPS those inline scripts — integrations
		// that pass per-request config via inline-before (e.g.
		// `window.appressEventsPanel`) get an empty `window` and the Vue
		// panels load with no nonce/ajaxUrl, so every fetch silently
		// fails.
		//
		// Rewrite ONLY the `<script ... src="...">` substring to inject
		// `type="module"`, preserve everything else WP emitted around it.
		return preg_replace(
			'#<script\s+([^>]*?\bsrc=[\'\"][^\'\"]+[\'\"][^>]*)>#',
			'<script type="module" $1>',
			$tag,
			1
		);
	}

	/**
	 * Detect installed integrations whose admin Vue layer wants to
	 * gate UI on plugin availability. Each entry exposes `installed`
	 * (the host plugin is active) plus integration-specific context
	 * the Vue layer needs to render configuration UI without a
	 * second AJAX round-trip.
	 *
	 * Currently:
	 *   - `translatepress.installed`
	 *   - `translatepress.default_language`
	 *   - `translatepress.languages`
	 *   - `translatepress.language_names` (code → human-readable name)
	 */
	/**
	 * Public post-types the admin can pick a screen URL from. The
	 * default list is every `public => true` post type WP knows about
	 * (`get_post_types(['public' => true], 'objects')`), minus
	 * `attachment` which doesn't make sense as a screen target.
	 *
	 * Each entry returns the fields the Vue picker needs to render a
	 * grouped Content Source dropdown:
	 *   - `slug`   — post type key (`page`, `post`, `appress_screen`, …)
	 *   - `label`  — admin-facing name (`Pages`, `Posts`, …)
	 *   - `singular` — singular label for placeholder copy
	 *
	 * Integrations (Voxel, WooCommerce subscriptions, custom CPTs) can
	 * filter the list via `appress/admin/pickable_post_types` to add
	 * non-`public` types or override labels.
	 */
	public static function collect_pickable_post_types(): array {
		// Tighter selector than `public => true` alone: also require
		// `show_in_nav_menus => true` so we catch user-facing content
		// CPTs (Page, Post, Product, Voxel city/person, appress_screen)
		// while excluding template / utility post types (Elementor
		// templates, WP block library, navigation menu items, theme
		// templates) that registered themselves `public` for preview
		// purposes only.
		$types = get_post_types( [
			'public'            => true,
			'show_in_nav_menus' => true,
		], 'objects' );

		// Hard blocklist for known template / internal post types that
		// still slip past the `show_in_nav_menus` filter on some plugin
		// versions. Customers can extend via the filter below.
		$blocklist = [
			'attachment',
			'elementor_library',
			'e-floating-buttons',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_navigation',
		];

		$result = [];
		foreach ( (array) $types as $slug => $obj ) {
			if ( in_array( $slug, $blocklist, true ) ) continue;
			$result[] = [
				'slug'     => (string) $slug,
				'label'    => isset( $obj->labels->name ) ? (string) $obj->labels->name : (string) $slug,
				'singular' => isset( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : (string) $slug,
			];
		}
		// Stable alphabetical order so the dropdown reads predictably
		// across sites + after admin adds / removes CPT plugins.
		usort( $result, function ( $a, $b ) { return strcasecmp( $a['label'], $b['label'] ); } );

		/**
		 * Filter the post types offered in the admin's Content Source
		 * dropdown. Integrations can prepend their own entries or strip
		 * built-ins they want to hide.
		 *
		 * @param array $result Default list (`public` + `show_in_nav_menus` CPTs minus the template blocklist).
		 */
		$result = (array) apply_filters( 'appress/admin/pickable_post_types', $result );
		return array_values( $result );
	}

	public static function collect_integration_status(): array {
		$status = [
			'translatepress' => [ 'installed' => false ],
		];

		if ( class_exists( '\\Appress\\Integration\\Translatepress\\Services\\Url_Translator' ) ) {
			$translator = new \Appress\Integration\Translatepress\Services\Url_Translator();
			if ( $translator->is_active() ) {
				$trp_settings    = $translator->get_settings();
				$default_lang    = (string) ( $trp_settings['default_language'] ?? '' );
				$languages       = array_values( (array) ( $trp_settings['languages'] ?? [] ) );
				$codes_for_lookup = array_values( array_unique( array_filter( array_merge( [ $default_lang ], $languages ) ) ) );

				$status['translatepress'] = [
					'installed'        => true,
					'default_language' => $default_lang,
					'languages'        => $languages,
					'language_names'   => (object) $translator->get_language_names( $codes_for_lookup ),
				];
			}
		}

		return $status;
	}
}
