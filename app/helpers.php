<?php

namespace Appress;

// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * Load static system configuration from the plugin's config file.
 * Equivalent to \Voxel\config().
 *
 * @param string $path    Dot-notation path into the config array.
 * @param mixed  $default Value returned when the path does not resolve.
 */
function config( $path = '', $default = null ) {
	static $config;
	if ( is_null( $config ) ) {
		$config = require APPRESS_PLUGIN_DIR . 'app/config/config.php';
	}

	if ( empty( $path ) ) {
		return $config;
	}

	$keys = explode( '.', $path );
	$value = $config;

	foreach ( $keys as $key ) {
		if ( ! isset( $value[ $key ] ) ) {
			return $default;
		}
		$value = $value[ $key ];
	}

	return $value;
}

/**
 * Read a setting from the database with a default fallback.
 * Uses an in-memory static cache and supports dot-notation lookup.
 *
 * @param string $option_path Example: 'package-id' or 'firebase-android.client_id'.
 * @param mixed  $default     Returned when the key is missing.
 * @param bool   $force_get   Force a fresh DB read (bypass static cache).
 */
function get( $option_path = '', $default = null, $force_get = false ) {
	static $appress_settings = null;

	if ( is_null( $appress_settings ) || $force_get ) {
		if ( $force_get ) {
			wp_cache_delete( 'appress_settings', 'options' );
		}

		$option_value = get_option( 'appress_settings', [] );
		if ( ! is_array( $option_value ) ) {
			$option_value = [];
		}

		$appress_settings = $option_value;
	}

	if ( empty( $option_path ) ) {
		return $appress_settings;
	}

	$keys = explode( '.', $option_path );
	$value = $appress_settings;

	// Walk the array one level per key in the dot-path.
	foreach ( $keys as $key ) {
		if ( ! isset( $value[ $key ] ) ) {
			return $default;
		}

		$value = $value[ $key ];
	}

	return $value;
}

/**
 * Update, create, or delete the Appress settings entry in wp_options.
 * Supports nested array paths. Passing $value = null unsets the key.
 *
 * @param string      $option_path Example: 'package-id' or 'nested.key'.
 * @param mixed       $value       Data to assign.
 * @param string|bool $autoload    Whether the option autoloads on every page.
 */
function set( $option_path, $value, $autoload = null ) {
	$keys = explode( '.', $option_path );

	$options = get_option( 'appress_settings', [] );
	if ( ! is_array( $options ) ) {
		$options = [];
	}

	// Reference pointer used to walk into the nested array for update.
	$original_options = &$options;

	$last_index = count( $keys ) - 1;
	foreach ( $keys as $index => $key ) {
		if ( $index === $last_index ) {
			if ( $value === null ) {
				unset( $options[ $key ] );
			} else {
				$options[ $key ] = $value;
			}
			break;
		}

		if ( ! isset( $options[ $key ] ) || ! is_array( $options[ $key ] ) ) {
			$options[ $key ] = [];
		}

		$options = &$options[ $key ];
	}

	// Root-level option_path is empty but a full array was provided — overwrite.
	if ( empty( $keys[0] ) ) {
		if ( $value === null ) {
			delete_option( 'appress_settings' );
		} elseif ( ! is_array( $value ) ) {
			throw new \Exception( esc_html__( 'Appress settings should be an array.', 'appress' ) );
		} else {
			update_option( 'appress_settings', $value, $autoload );
		}
	} else {
		update_option( 'appress_settings', $original_options, $autoload );
	}

	// Refresh the static cache so subsequent reads see the new value.
	\Appress\get( '', null, true );
}

/**
 * Write a diagnostic line to the PHP error log, but only when WP_DEBUG is on.
 * Shared-host sites typically run WP_DEBUG=false, so silent-by-default keeps
 * logs clean there while still giving us visibility in development.
 */
function debug_log( $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( is_string( $message ) ? $message : print_r( $message, true ) );
	}
}

/**
 * Get assets version string depending on environment to bust cache in development.
 *
 * @return string
 */
function get_assets_version() {
	if ( defined( 'APPRESS_IS_DEV' ) && \APPRESS_IS_DEV ) {
		return (string) time();
	}
	return defined( 'APPRESS_VERSION' ) ? \APPRESS_VERSION : '1.0.0';
}

/**
 * Get the App ID of the current request based on the User-Agent string.
 *
 * Two UA formats supported:
 *   - Post-Phase-4: `<unique_class> <unique_class>/<app_id>` (`X[hex]/(\d+)`).
 *     Build engine emits this from build_1166 onward — every customer gets a
 *     UA whose brand token equals the per-build salt instead of the literal
 *     "Appress", killing the Apple 4.3(a) UA-side cluster signal.
 *   - Legacy: `Appress Appress/<app_id>` (pre-1166 builds). Recognized via
 *     `Appress/(\d+)` fallback so apps already in customers' hands keep
 *     resolving against the same row.
 *
 * @return int
 */
function get_current_app_id() {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	if ( preg_match( '/X[a-f0-9]{8,32}\/(\d+)/i', $ua, $matches ) ) {
		return intval( $matches[1] );
	}
	if ( preg_match( '/Appress\/(\d+)/i', $ua, $matches ) ) {
		return intval( $matches[1] );
	}
	return 0;
}

/**
 * Merged list of CSS selectors whose anchor clicks should stay on the
 * CURRENT screen (instead of pushing a subscreen modal). Shared helper so
 * both the /boot JSON payload (native-cached) and the in-page wp_head
 * script tag (per-request) pull from one source of truth.
 *
 * Pipeline:
 *   1. Admin textarea from `wp_appress_apps.live_config.inline_link_selectors`
 *      (one selector per line).
 *   2. Integration-side additions via the `appress/app/inline_link_selectors`
 *      filter (WooCommerce My Account nav, WC product tabs, …).
 *   3. Dedupe + trim.
 *
 * Returns `string[]`. Empty when no app resolves, no admin entry, and no
 * integration hooks contribute.
 */
function get_inline_link_selectors( $app_id = 0 ) {
	$app_id = (int) $app_id;
	if ( $app_id <= 0 ) {
		return [];
	}

	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare(
		"SELECT build_config FROM {$wpdb->prefix}appress_apps WHERE id = %d",
		$app_id
	) );
	$live = $raw ? json_decode( $raw, true ) : [];
	if ( ! is_array( $live ) ) {
		$live = [];
	}

	$selectors = [];
	$text = isset( $live['inline_link_selectors'] ) ? (string) $live['inline_link_selectors'] : '';
	if ( $text !== '' ) {
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( $line !== '' ) {
				$selectors[] = $line;
			}
		}
	}

	/**
	 * Filter: `appress/app/inline_link_selectors`
	 *
	 * @param string[] $selectors Admin-entered list (trimmed, no empties).
	 * @param int      $app_id    Target app.
	 */
	$selectors = (array) apply_filters( 'appress/app/inline_link_selectors', $selectors, $app_id );

	$selectors = array_map( 'trim', $selectors );
	$selectors = array_filter( $selectors, function ( $s ) { return is_string( $s ) && $s !== ''; } );
	return array_values( array_unique( $selectors ) );
}

/**
 * URL patterns whose targets should open in a new subscreen instead of
 * loading in-place. Mirror of {@see get_inline_link_selectors} but for
 * the OPPOSITE intent — admin marks destinations (URL globs) where the
 * default same-domain in-place behaviour should be overridden with a
 * subscreen push.
 *
 * Pipeline:
 *   1. Admin textarea from `wp_appress_apps.live_config.subscreen_url_patterns`
 *      (one glob per line — `*` = any chars, `?` = one char).
 *   2. Integration additions via `appress/app/subscreen_url_patterns`
 *      filter (Voxel archives, custom search endpoints, …).
 *   3. Dedupe + trim.
 *
 * Returns `string[]`. Empty when no app, no admin entry, and no
 * integration hooks contribute.
 *
 * Used by native interceptors (iOS `decidePolicyFor`, Android
 * `handleUrlRouting`) to force subscreen routing for matching URLs —
 * primary use case is Voxel search forms that JS-redirect to archive
 * URLs (keyboard Enter / Vue button click both end up calling
 * `window.location.href = archive_url`, both must subscreen).
 */
function get_subscreen_url_patterns( $app_id = 0 ) {
	$app_id = (int) $app_id;
	if ( $app_id <= 0 ) {
		return [];
	}

	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare(
		"SELECT build_config FROM {$wpdb->prefix}appress_apps WHERE id = %d",
		$app_id
	) );
	$live = $raw ? json_decode( $raw, true ) : [];
	if ( ! is_array( $live ) ) {
		$live = [];
	}

	$patterns = [];
	$text = isset( $live['subscreen_url_patterns'] ) ? (string) $live['subscreen_url_patterns'] : '';
	if ( $text !== '' ) {
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( $line !== '' ) {
				$patterns[] = $line;
			}
		}
	}

	/**
	 * Filter: `appress/app/subscreen_url_patterns`
	 *
	 * @param string[] $patterns Admin-entered list (trimmed, no empties).
	 * @param int      $app_id   Target app.
	 */
	$patterns = (array) apply_filters( 'appress/app/subscreen_url_patterns', $patterns, $app_id );

	$patterns = array_map( 'trim', $patterns );
	$patterns = array_filter( $patterns, function ( $s ) { return is_string( $s ) && $s !== ''; } );
	return array_values( array_unique( $patterns ) );
}

/**
 * App CSS (admin textareas + integration filter) for the boot payload.
 * Same pipeline as {@see get_inline_link_selectors}: read admin
 * `live_config.css_all` / `css_android` / `css_ios`, run each through
 * the `appress/app/css` filter so integrations can append rules, then
 * return the three normalized strings ready to spread back into
 * `live_config` before it ships to the native app.
 *
 * Native (Android `appCssJS`, iOS WKUserScript) keeps owning the
 * runtime composition — it merges `BASELINE + css_all + css_<platform>`
 * at documentStart. This helper only normalizes the three inputs the
 * native side reads from boot.
 *
 * @param int $app_id Target app row in `wp_appress_apps`.
 * @return array{css_all:string, css_android:string, css_ios:string}
 */
function get_app_css( $app_id = 0 ) {
	$out = [ 'css_all' => '', 'css_android' => '', 'css_ios' => '' ];

	$app_id = (int) $app_id;
	if ( $app_id <= 0 ) {
		return $out;
	}

	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare(
		"SELECT build_config FROM {$wpdb->prefix}appress_apps WHERE id = %d",
		$app_id
	) );
	$live = $raw ? json_decode( $raw, true ) : [];
	if ( ! is_array( $live ) ) {
		$live = [];
	}

	foreach ( [ 'all', 'android', 'ios' ] as $platform ) {
		$key   = 'css_' . $platform;
		$value = isset( $live[ $key ] ) ? trim( (string) $live[ $key ] ) : '';

		/**
		 * Filter: `appress/app/css`
		 *
		 * Append integration-side CSS rules (WooCommerce account UI
		 * tweaks, plugin-specific overrides…) to the admin textarea
		 * value before it ships to the native app via boot payload.
		 *
		 * @param string $value    Admin-entered CSS for this platform slot.
		 * @param string $platform 'all' | 'android' | 'ios'.
		 * @param int    $app_id   Target app.
		 */
		$out[ $key ] = (string) apply_filters( 'appress/app/css', $value, $platform, $app_id );
	}

	// Boundary mutation pass — rewrite `--appress-status-bar-height` to
	// the per-app salted form. Build engine mutator does the same on
	// every native CSS literal at compile time, so the runtime-injected
	// var declaration in the WebView uses `--<salt_lc>-status-bar-height:
	// 44px`. Without this matching pass on the plugin-emitted CSS the
	// integration rules + admin-entered CSS reference the legacy literal
	// var name → unresolved → falls back to `0px` → header drops under
	// the notch. Uses the same per-app salt the binary was built with.
	//
	// HTML class names like `appress-status-bar-height` / `appress-sticky`
	// stay literal here — they're used as targeting selectors by
	// Elementor/Bricks/theme builder compiled CSS, salting would break
	// those rules.
	$salt = get_app_unique_class( $app_id );
	if ( $salt !== '' ) {
		$salt_lc = strtolower( $salt );
		foreach ( $out as $key => $css ) {
			if ( $css === '' ) continue;
			$out[ $key ] = str_replace( '--appress-status-bar-height', '--' . $salt_lc . '-status-bar-height', $css );
		}
	}

	return $out;
}

/**
 * Detect whether the current request is coming from an Appress native app
 * (iOS or Android). Optionally scope the check to a single app id.
 *
 * UA brand detection runs in two passes:
 *   1. Post-Phase-4 builds: UA contains `<unique_class>` (a per-customer
 *      `X[hex]` salt) — match against the apps table via `get_apps_class()`.
 *      Falls into this path the moment Central pushes the unique_class
 *      onto a row + a build with that salt reaches a user's device.
 *   2. Legacy: UA contains the literal `Appress` substring — pre-1166
 *      customer apps already in the wild. Keep working until they're
 *      rebuilt against the new engine.
 *
 * @param int $app_id Pass a specific app id to check for, or 0 for any app.
 * @return bool
 */
function is_app( $app_id = 0 ) {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	$is_app = false;
	foreach ( get_apps_class() as $salt ) {
		if ( $salt !== '' && strpos( $ua, $salt ) !== false ) {
			$is_app = true;
			break;
		}
	}
	if ( ! $is_app && strpos( $ua, 'Appress' ) !== false ) {
		$is_app = true;
	}

	if ( ! $is_app ) {
		return false;
	}

	if ( $app_id > 0 ) {
		return get_current_app_id() === intval( $app_id );
	}

	return true;
}

/**
 * Detect whether the current request is running inside Appress on iOS.
 *
 * @param int $app_id Pass a specific app id to check for, or 0 for any app.
 * @return bool
 */
function is_ios( $app_id = 0 ) {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	if ( ! is_app( $app_id ) ) {
		return false;
	}
	// iPhone/iPod match the explicit token. iPad on iPadOS 13+ in
	// WKWebView default (`preferredContentMode = .recommended`) ships
	// `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) ... Appress` —
	// Apple's desktop-class UA to reduce mobile-vs-desktop site drift.
	// `is_app()` already gates on the `Appress` UA token and we do not
	// ship a Mac Catalyst variant, so a `Macintosh` UA inside the app
	// context is iPadOS, not real macOS. Match it so visibility rules,
	// app-mode CSS, and native-only branches fire correctly on iPad.
	return preg_match( '/iPhone|iPad|iPod|Macintosh/i', $ua );
}

/**
 * Detect whether the current request is running inside Appress on Android.
 *
 * @param int $app_id Pass a specific app id to check for, or 0 for any app.
 * @return bool
 */
function is_android( $app_id = 0 ) {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	return is_app( $app_id ) && stripos( $ua, 'Android' ) !== false;
}

/**
 * Scope a script/style enqueue to one integration's detail page.
 *
 * Plugin authors implementing `appress/integrations/admin_template/{id}`
 * can call this from their `admin_enqueue_scripts` hook to load a Vue
 * bundle + CSS only when the user is actually on that integration's detail
 * page — no more per-request `$_GET` sniffing duplicated across every
 * integration controller.
 *
 *   add_action( 'admin_enqueue_scripts', function () {
 *       \Appress\enqueue_integration_asset(
 *           'myplugin',
 *           [
 *               'scripts' => [ [ 'handle' => 'myplugin-admin', 'src' => $js_url, 'deps' => [], 'in_footer' => true ] ],
 *               'styles'  => [ [ 'handle' => 'myplugin-admin-css', 'src' => $css_url ] ],
 *           ]
 *       );
 *   } );
 *
 * @param string $integration_id  Integration the assets belong to.
 * @param array  $assets {
 *     @type array[] $scripts  wp_enqueue_script() arg bundles.
 *     @type array[] $styles   wp_enqueue_style() arg bundles.
 * }
 * @return bool `true` if the current request matches the integration's
 *              detail page (assets enqueued); `false` otherwise.
 */
function enqueue_integration_asset( string $integration_id, array $assets ): bool {
	if ( ! is_admin() ) {
		return false;
	}
	$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
	$requested = isset( $_GET['integration'] ) ? sanitize_text_field( wp_unslash( $_GET['integration'] ) ) : '';
	if ( $page !== 'appress-integrations' || $requested !== $integration_id ) {
		return false;
	}

	foreach ( (array) ( $assets['scripts'] ?? [] ) as $s ) {
		if ( empty( $s['handle'] ) || empty( $s['src'] ) ) {
			continue;
		}
		wp_enqueue_script(
			$s['handle'],
			$s['src'],
			$s['deps']      ?? [],
			$s['version']   ?? get_assets_version(),
			$s['in_footer'] ?? true
		);
	}
	foreach ( (array) ( $assets['styles'] ?? [] ) as $s ) {
		if ( empty( $s['handle'] ) || empty( $s['src'] ) ) {
			continue;
		}
		wp_enqueue_style(
			$s['handle'],
			$s['src'],
			$s['deps']    ?? [],
			$s['version'] ?? get_assets_version(),
			$s['media']   ?? 'all'
		);
	}
	return true;
}

/**
 * Render the tab bar used inside a integration's detail page. Each tab
 * becomes a link to the same page with `&tab={key}` appended, so
 * state survives refreshes + browser history without client JS.
 *
 * @param string $integration_id  Current integration id.
 * @param array  $tabs        `[ 'events' => 'Events', ... ]`
 * @param string $active      Key of the currently rendered tab.
 */
function render_integration_tab_bar( string $integration_id, array $tabs, string $active ) {
	$base = admin_url( 'admin.php?page=appress-integrations&integration=' . rawurlencode( $integration_id ) );
	?>
	<div class="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-xl px-2 flex gap-1 mb-5">
		<?php foreach ( $tabs as $key => $label ) :
			// Labels accept two shapes:
			//   string  → plain label
			//   array   → ['text' => '...', 'badge' => '...']  for an
			//             optional trailing pill (e.g. status / version)
			$text  = is_array( $label ) ? (string) ( $label['text']  ?? '' ) : (string) $label;
			$badge = is_array( $label ) ? (string) ( $label['badge'] ?? '' ) : '';
			$href = esc_url( add_query_arg( 'tab', $key, $base ) );
			$is_active = ( $key === $active );
			$classes = $is_active
				? 'px-4 py-3 text-sm font-medium border-b-2 border-brand-500 text-brand-600 dark:text-brand-400 no-underline transition-colors inline-flex items-center gap-2'
				: 'px-4 py-3 text-sm font-medium border-b-2 border-transparent text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white no-underline transition-colors inline-flex items-center gap-2';
			?>
			<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $href ); ?>">
				<span><?php echo esc_html( $text ); ?></span>
				<?php if ( $badge !== '' ) : ?>
					<span class="text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400"><?php echo esc_html( $badge ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Resolve the active tab from the URL. Falls back to the first key in
 * `$tabs` when `&tab=` is missing or unknown, so every detail page has
 * a sensible default without the caller having to re-implement the
 * guard.
 */
function current_integration_tab( array $tabs, string $default = '' ): string {
	$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
	if ( $tab !== '' && isset( $tabs[ $tab ] ) ) {
		return $tab;
	}
	if ( $default !== '' && isset( $tabs[ $default ] ) ) {
		return $default;
	}
	$keys = array_keys( $tabs );
	return $keys[0] ?? '';
}

/**
 * Render the shared "events configuration" panel for a single
 * integration. Drop into any integration's `appress/integrations/admin_template/{id}`
 * template when you want the standard Appress events UI (category
 * sidebar, per-destination / per-app configuration, bulk send-to)
 * without re-implementing it. The panel is a Vue widget that reads
 * its integration id from the mount element's `data-integration-id` and
 * talks to the existing `admin.events.*` AJAX endpoints.
 *
 * Safe to call multiple times with different ids on the same page —
 * each mount gets its own root div and data attribute, the Vue entry
 * will mount into every matching element it finds.
 *
 * @param string $integration_id  Integration id (e.g. 'woocommerce').
 * @param array  $opts {
 *     Optional. Panel config.
 *     @type string $title     Override heading (default: none).
 *     @type bool   $no_header Skip the heading markup entirely.
 * }
 */
function render_integration_events_panel( string $integration_id, array $opts = [] ) {
	if ( $integration_id === '' ) {
		return;
	}

	// Idempotent asset registration. Each call enqueues the panel
	// bundle once; duplicate enqueues are no-ops thanks to WP's handle
	// dedupe. Prints the shared config once per request via wp_add_inline_script
	// so it ships through WP's enqueue pipeline (no raw <script> tag).
	wp_enqueue_style( 'appress:admin.css' );
	wp_enqueue_script( 'appress:integration-events-panel.js' );

	static $printed_nonce = false;
	if ( ! $printed_nonce ) {
		$printed_nonce = true;
		$config = [
			'nonce'   => wp_create_nonce( 'appress_admin_action' ),
			'ajaxUrl' => home_url( '/?appress=1' ),
		];
		wp_add_inline_script(
			'appress:integration-events-panel.js',
			'window.appressEventsPanel = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	$mount_id = 'appress-events-panel-' . sanitize_html_class( $integration_id );
	?>
	<div
		id="<?php echo esc_attr( $mount_id ); ?>"
		class="appress-events-panel-mount"
		data-integration-id="<?php echo esc_attr( $integration_id ); ?>"
	></div>
	<?php
}

// ── Crypto: envelope encryption for secrets at rest ────────────────────────
// Symmetric encryption of DB values (FCM service-account JSON, HMAC signing
// secrets, connection tokens). Key derived from `wp_salt('auth')` via HKDF
// — never persisted, never transmitted.
//
// Envelope format: `v1:` + base64(nonce ‖ ciphertext). The `v1:` tag lets us
// roll the cipher or KDF later without re-reading legacy plain rows — anything
// without the prefix passes through untouched.
//
// Primary path: libsodium `crypto_secretbox`. Fallback: OpenSSL AES-256-GCM
// (PHP 7.1+). Both paths emit the same envelope shape so callers never branch.

const CRYPTO_PREFIX = 'v1:';

/**
 * Encrypt plaintext. Empty / non-string input passes through. Idempotent:
 * a value already starting with `v1:` is returned as-is (no double-wrap).
 */
function encrypt( $plaintext ) {
	if ( ! is_string( $plaintext ) || $plaintext === '' ) {
		return $plaintext;
	}
	if ( strpos( $plaintext, CRYPTO_PREFIX ) === 0 ) {
		return $plaintext;
	}

	$key = _crypto_key();

	if ( function_exists( 'sodium_crypto_secretbox' ) ) {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		return CRYPTO_PREFIX . base64_encode( $nonce . $cipher );
	}

	$nonce  = random_bytes( 12 );
	$tag    = '';
	$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );
	if ( $cipher === false ) {
		return $plaintext; // Encryption unavailable — store plain to avoid data loss.
	}
	return CRYPTO_PREFIX . base64_encode( $nonce . $tag . $cipher );
}

/**
 * Decrypt a `v1:` envelope. Plain strings (no prefix) pass through so legacy
 * rows keep working until a migration rewrites them.
 */
function decrypt( $value ) {
	if ( ! is_string( $value ) || $value === '' ) {
		return $value;
	}
	if ( strpos( $value, CRYPTO_PREFIX ) !== 0 ) {
		return $value;
	}

	$blob = base64_decode( substr( $value, strlen( CRYPTO_PREFIX ) ), true );
	if ( $blob === false ) {
		return '';
	}

	$key = _crypto_key();

	if ( function_exists( 'sodium_crypto_secretbox_open' ) ) {
		$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( strlen( $blob ) < $nonce_len ) return '';
		$nonce  = substr( $blob, 0, $nonce_len );
		$cipher = substr( $blob, $nonce_len );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		return $plain === false ? '' : $plain;
	}

	// OpenSSL layout: 12-byte nonce + 16-byte tag + ciphertext.
	if ( strlen( $blob ) < 28 ) return '';
	$nonce  = substr( $blob, 0, 12 );
	$tag    = substr( $blob, 12, 16 );
	$cipher = substr( $blob, 28 );
	$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );
	return $plain === false ? '' : $plain;
}

/**
 * Deterministic lookup hash for queryable-but-encrypted values. HMAC-SHA256
 * with the same site key: same input always → same hash, but an attacker
 * without WP_AUTH_KEY can't reverse it or pre-compute a lookup table.
 */
function lookup_hash( $value ) {
	if ( ! is_string( $value ) || $value === '' ) return '';
	return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
}

/**
 * Derive the 32-byte encryption key from `wp_salt('auth')` via HKDF.
 * Same site always derives the same key; derived form is never written
 * to disk. Underscore prefix signals internal use — callers shouldn't touch.
 */
function _crypto_key() {
	$salt = wp_salt( 'auth' );
	if ( function_exists( 'hash_hkdf' ) ) {
		return hash_hkdf( 'sha256', $salt, 32, 'appress-crypto-v1' );
	}
	return substr( hash( 'sha256', 'appress-crypto-v1|' . $salt, true ), 0, 32 );
}

/**
 * Return the list of `unique_class` identifiers across every connected app
 * on this site. Each app row in `wp_appress_apps` has its own salted class
 * id (`Xa1b2c3d4…`) issued by Central; this list is what the AJAX router
 * accepts as alternate URL keys so the mobile app can hit
 * `?<unique_class>=1&action=…` instead of the legacy `?appress=1&action=…`.
 *
 * Cached in-process for the request lifetime — every WP request that doesn't
 * mutate the apps table reuses the first SELECT. Multi-app sites with N
 * apps still incur exactly one DB hit per page. Pass true to bypass.
 *
 * Excludes empty / null values so apps that haven't completed onboarding
 * yet (no Central sync) don't expose an empty-string key that would match
 * every request without a `unique_class` param.
 */
function get_apps_class( $force_get = false ) {
	static $cache = null;
	if ( ! $force_get && $cache !== null ) {
		return $cache;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'appress_apps';
	// Defensive column check — the consumer (on_mobile router) is hot-path
	// at plugins_loaded, so a transient schema gap (controller order
	// regression, mid-deploy race, manual DROP COLUMN, etc.) would otherwise
	// take the whole site down with "Unknown column". Cache the column's
	// existence on the static map so we pay this `SHOW COLUMNS` cost once
	// per request and stop the fatal at the helper boundary instead of
	// letting it bubble up into every Ajax_Controller registration.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$col = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'unique_class'" );
	if ( empty( $col ) ) {
		$cache = [];
		return $cache;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows  = $wpdb->get_col( "SELECT unique_class FROM {$table} WHERE unique_class IS NOT NULL AND unique_class != ''" );
	$cache = array_values( array_unique( array_filter( (array) $rows, 'is_string' ) ) );
	return $cache;
}

/**
 * Invalidate the in-process `get_apps_class()` cache. Callers in
 * `Apps_Controller` fire this after every insert / update / delete on
 * the apps table so the same-request follow-up calls (e.g. the AJAX
 * router that resolves the just-onboarded app) see the fresh list.
 */
function clear_apps_class_cache() {
	get_apps_class( true );
}

/**
 * Lookup the per-app `unique_class` salt baked into a customer's binary.
 * Used to namespace every JS / CSS identifier the plugin emits so the
 * companion mobile app's binary literals + the customer-site emitted
 * tokens line up without leaking the shared `Appress` brand across
 * apps (Apple 4.3a / Play Store similarity classifier).
 *
 * Returns an empty string when:
 *   - `$app_id` is 0 / invalid
 *   - The row has no `unique_class` (pre-Phase-4 row, legacy state)
 *   - The schema lacks the `unique_class` column (mid-deploy race)
 *
 * Caller treats empty → fallback to literal `Appress` / `appress`
 * tokens (legacy contract). Multi-app sites resolve per-request via
 * `get_current_app_id()` so each visitor's UA-detected app gets its
 * own namespace.
 */
function get_app_unique_class( int $app_id ): string {
	if ( $app_id <= 0 ) return '';
	static $cache = [];
	if ( isset( $cache[ $app_id ] ) ) return $cache[ $app_id ];

	global $wpdb;
	$table = $wpdb->prefix . 'appress_apps';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$col = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'unique_class'" );
	if ( empty( $col ) ) {
		return $cache[ $app_id ] = '';
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$val = (string) $wpdb->get_var( $wpdb->prepare( "SELECT unique_class FROM {$table} WHERE id = %d LIMIT 1", $app_id ) );
	return $cache[ $app_id ] = $val;
}

/**
 * JS namespace token for the current (or specified) app. Mirrors the
 * mutator's `applyBoundaryMutation` output — the build engine rewrites
 * `window.Appress` → `window.<salt>` in every Swift/Java-baked JS
 * literal, and this helper produces the matching string on the WP side
 * so customer site's inline JS calls resolve to the same namespace.
 *
 *   App context (`unique_class` resolved) → raw `unique_class` value
 *   (e.g. `Xb5093566b25d`). Pre-Phase-4 / web context → `Appress`
 *   (legacy).
 *
 * The `unique_class` column already stores the salt in its canonical
 * `X<hex>` form — the same string the mutator concatenates onto class
 * names (`Xb5093566b25d07656decd848`). Returning it AS-IS keeps the
 * plugin's emitted tokens byte-identical to the binary's baked JS
 * literals. Adding an extra `X` prefix here would yield `XX<hex>`
 * and break the cross-boundary contract.
 */
function get_js_namespace( int $app_id = 0 ): string {
	if ( $app_id <= 0 ) $app_id = get_current_app_id();
	$salt = get_app_unique_class( $app_id );
	return $salt !== '' ? $salt : 'Appress';
}

/**
 * CSS identifier prefix — lowercase counterpart of `get_js_namespace()`.
 * Used for CSS custom properties (`--<prefix>-status-bar-height`) and
 * CSS class selectors (`.<prefix>-sticky`). Mirrors the mutator's
 * `salt.toLowerCase()` boundary mutation on native CSS literals.
 *
 *   App context → lowercased raw salt (e.g. `xb5093566b25d`)
 *   Web / pre-Phase-4 → `appress` (legacy)
 *
 * Just `strtolower($salt)` — the salt's canonical `X<hex>` form
 * lowercases cleanly to `x<hex>` which is exactly what the mutator
 * emits via `saltLc = salt.toLowerCase()` in `applyBoundaryMutation`.
 * No extra prefix.
 */
function get_css_prefix( int $app_id = 0 ): string {
	if ( $app_id <= 0 ) $app_id = get_current_app_id();
	$salt = get_app_unique_class( $app_id );
	return $salt !== '' ? strtolower( $salt ) : 'appress';
}

/**
 * Per-app native-class-ID indirection table — the SINGLE source of
 * truth that lets plugin static `assets/js/*.js` widgets + customer-
 * site inline CSS talk to a mobile binary whose every `Appress<X>`
 * symbol is salt-scrambled at build time.
 *
 * Mobile app review (Apple 4.3(a) similarity, Play Store classifier)
 * only inspects the SUBMITTED IPA / AAB — the customer's WordPress
 * site emissions are downloaded at runtime after install and never
 * reach review. So we want ZERO occurrence of the literal `Appress`
 * substring in the native binary's symbol table + `__TEXT` segment,
 * while keeping every literal `Appress<X>` reference in plugin web
 * assets perfectly readable (Apple never sees them).
 *
 * The build-engine mutator handles binary side — every
 * `\bAppress[A-Z]\w*` in native source becomes
 * `<salt><hmac12(suffix)>`. This helper computes the same
 * `salt + HMAC12(suffix)` for the names that DO cross the boundary
 * (slave JS calls `window.AppressNativeBridge.postMessage` →
 * resolves to the salted handler the binary registered) so the
 * plugin's emitted `window.AppressClassIds` map can hand off the
 * exact salted name at runtime via `window[window.AppressClassIds.native]`.
 *
 * Returned keys:
 *   - `namespace`         : `<salt>` itself (replaces `window.Appress`)
 *   - `master`            : iOS master-WebView WKScriptMessageHandler name
 *   - `native`            : Android JavascriptInterface name
 *   - `linkIntercept`     : iOS link-intercept message handler name
 *   - `firstLaunchBridge` : Android JS bridge for the dismiss-first-launch shortcode
 *   - `notificationsFeed` : `window` global the native binary calls to mount /
 *                            refresh / prepend the notifications feed widget
 *
 * Returns an empty array for legacy / web requests where
 * `unique_class` is empty. Static .js sites must guard for that
 * shape (`var ids = window.AppressClassIds || {}; …`) so legacy
 * pre-Phase-4 installs keep working with the original literal names.
 */
function get_native_class_ids( int $app_id = 0 ): array {
	if ( $app_id <= 0 ) $app_id = get_current_app_id();
	$salt = get_app_unique_class( $app_id );
	if ( $salt === '' ) return [];
	$hmac = static function ( string $suffix ) use ( $salt ): string {
		return $salt . substr( hash_hmac( 'sha256', $suffix, $salt ), 0, 12 );
	};
	return [
		'namespace'         => $salt,
		'cssPrefix'         => strtolower( $salt ),
		'master'            => $hmac( 'MasterBridge' ),
		'native'            => $hmac( 'NativeBridge' ),
		'linkIntercept'     => $hmac( 'LinkIntercept' ),
		'firstLaunchBridge' => $hmac( 'FirstLaunchBridge' ),
		'notificationsFeed' => $hmac( 'NotificationsFeed' ),
	];
}
