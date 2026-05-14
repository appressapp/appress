<?php
namespace Appress\Controllers\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin shell for `?page=appress-settings`.
 *
 * Site-wide configuration that doesn't belong to any one app or
 * integration — Smart App Banner today, Universal Links / debug /
 * routing rules later. Sits as its own submenu under the "Appress"
 * parent so it's discoverable next to Apps / Integrations / Broadcast
 * without polluting any integration card with global toggles.
 *
 * Position 50 lands the row between Broadcast (auto-appended) and
 * Screens (pinned at 99 by `App\Admin_Controller`).
 */
class Admin_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		if ( is_admin() ) {
			// Priority 11 fires AFTER every other Appress submenu hook
			// (Integrations / Broadcast / etc. all register at the default
			// priority 10). Pinning numeric positions doesn't work for
			// "true bottom": Broadcast registers without a position, so
			// WP gives it `max(int_keys) + 1` — beat any number we hand
			// Settings. Hooking later instead, with `null` position,
			// guarantees Settings is the LAST `add_submenu_page` call
			// and therefore lands at the highest auto-key.
			$this->on( 'admin_menu', '@register_menus', 11 );
			$this->on( 'admin_enqueue_scripts', '@enqueue_scripts' );
		}
	}

	protected function register_menus() {
		add_submenu_page(
			'appress',
			__( 'Settings', 'appress' ),
			__( 'Settings', 'appress' ),
			'manage_options',
			'appress-settings',
			[ $this, 'render_page' ]
		);
	}

	protected function enqueue_scripts() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( $page !== 'appress-settings' ) {
			return;
		}

		wp_enqueue_style( 'appress:admin.css' );
		wp_enqueue_script( 'appress:settings.js' );

		// Inline `before` payload so `window.appressConfig` lands
		// in scope before settings.js evaluates its Vue mount —
		// matches the pattern used by the Integrations + Broadcast bundles.
		$payload = wp_json_encode( $this->build_localize() );
		wp_add_inline_script(
			'appress:settings.js',
			'window.appressConfig=' . $payload . ';window.appConfig=window.appressConfig;',
			'before'
		);
	}

	private function build_localize(): array {
		// Vue UI translation dict — runtime values from
		// `app/i18n/vue-strings.php` (PHP `__()` against `appress` text
		// domain). Inlined into `appConfig` so `useI18n` finds it on
		// the very first source it checks; relying on the
		// `Apppress_Config` fallback alone left strings untranslated
		// when the per-page payload short-circuited the lookup.
		$i18n = file_exists( APPRESS_PLUGIN_DIR . 'app/i18n/vue-strings.php' )
			? require APPRESS_PLUGIN_DIR . 'app/i18n/vue-strings.php'
			: [];

		return [
			'nonce'    => wp_create_nonce( 'appress_admin_action' ),
			'page'     => 'appress-settings',
			'settings' => (object) $this->load_settings(),
			'apps'     => $this->load_apps(),
			'i18n'     => $i18n,
			// Bootstrap the currently-installed plugin version into the
			// page payload so the Rollback tab can render the "current"
			// label on first paint — without this the UI shows "—" until
			// the lazy GitHub release fetch completes a beat later.
			'currentVersion' => defined( 'APPRESS_VERSION' ) ? (string) APPRESS_VERSION : '',
		];
	}

	/**
	 * Pull the current `appress_settings.site_settings` blob plus
	 * defaults for any key the admin hasn't saved yet. Shipping
	 * defaults from PHP keeps the Vue layer dumb (no need to know
	 * the schema) — Vue just renders whatever it gets.
	 */
	private function load_settings(): array {
		$saved = \Appress\get( 'site_settings', [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		$defaults = [
			'smart_banner' => [
				'enabled' => false,
				'app_id'  => 0,
			],
			'qr_login' => [
				'enabled' => false,
			],
		];
		// Shallow merge per-section so future sections added to defaults
		// auto-appear without forcing a re-save.
		$out = $defaults;
		foreach ( $saved as $section => $values ) {
			if ( isset( $defaults[ $section ] ) && is_array( $values ) ) {
				$out[ $section ] = array_merge( $defaults[ $section ], $values );
			} else {
				$out[ $section ] = $values;
			}
		}
		return $out;
	}

	/**
	 * Lean app list for the Smart Banner picker — id + name +
	 * apple_store_id, nothing else. Vue uses apple_store_id to gate
	 * the "Save" button: an app without a published store id can be
	 * selected, but the banner won't actually emit until the id is
	 * filled in, so the form warns ahead of time.
	 */
	private function load_apps(): array {
		global $wpdb;
		// `$wpdb->prefix` is configured in wp-config.php (trusted) and
		// 'appress_apps' is a literal — the composed table name is safe
		// to interpolate. `$wpdb->prepare()` placeholders cover values,
		// not identifiers, so a manual ignore is the canonical pattern
		// used by WP core itself for this construction.
		$table = $wpdb->prefix . 'appress_apps';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( "SELECT id, app_name, build_information FROM {$table} ORDER BY id ASC", ARRAY_A );
		$out = [];
		foreach ( (array) $rows as $row ) {
			$bi = json_decode( $row['build_information'] ?? '{}', true ) ?: [];
			$out[] = [
				'id'             => (int) $row['id'],
				'app_name'       => (string) $row['app_name'],
				'apple_store_id' => (string) ( $bi['apple_store_id'] ?? '' ),
				'package_id'     => (string) ( $bi['package_id'] ?? '' ),
			];
		}
		return $out;
	}

	public function render_page() {
		?>
		<div class="wrap" style="margin: 0; padding: 0;">
			<div id="appress-settings-app"></div>
		</div>
		<?php
	}
}
