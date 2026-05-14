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
						'ajaxUrl'   => home_url( '/?appress=1' ),
						'homeUrl'   => home_url(),
						'pluginUrl' => APPRESS_PLUGIN_URL,
						'schema'    => \Appress\config( 'schema' ) ?: [],
						// Vue admin UI translation dict — sourced from
						// `app/i18n/vue-strings.php` which calls __()
						// on every `t()` argument so xgettext / Loco
						// scan picks them up. `useI18n.js` reads this
						// dict at runtime; missing keys fall back to
						// the source string.
						'i18n'      => file_exists( APPRESS_PLUGIN_DIR . 'app/i18n/vue-strings.php' )
							? require APPRESS_PLUGIN_DIR . 'app/i18n/vue-strings.php'
							: [],
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
}
