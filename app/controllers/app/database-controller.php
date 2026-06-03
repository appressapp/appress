<?php
namespace Appress\Controllers\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Controller extends \Appress\Controllers\Base_Controller {

	const DB_VERSION = '1.3.1';

	protected function hooks() {
		// Run the schema migration synchronously at controller boot rather
		// than deferring to `admin_init`. Frontend requests never hit
		// admin_init, so the legacy hook left non-admin traffic running
		// against the pre-1.1.0 table — and any controller that selects
		// from `wp_appress_apps` (Firebase mobile router, app.boot, …)
		// crashed with "Unknown column 'unique_class'" until the first
		// wp-admin page view. The version-option short-circuit inside
		// install() makes the repeated calls cheap once the migration
		// has actually run.
		//
		// REQUIRES that this controller appear BEFORE every consumer in
		// `app/config/controllers.config.php` — otherwise the consumer's
		// own hooks() runs first and queries against the stale schema.
		self::install();
	}

	/**
	 * Create the apps table. Idempotent — version-option guard returns
	 * early once the schema is in place.
	 *
	 * Schema notes:
	 *   - connection_token: encrypted envelope of the plaintext token.
	 *   - connection_token_lookup: deterministic HMAC of the plaintext,
	 *     used for lookup queries (the encrypted form isn't searchable).
	 *   - signing_secret: 64-char hex HMAC key that the native app uses
	 *     to sign FCM token-sync requests back to this site.
	 *   - unique_class: Central-derived per-app identifier (e.g. "Xa1b2c3d4")
	 *     used by Build Engine to rename Appress* native classes and to key
	 *     mobile-facing endpoints. Same package_id → same value forever.
	 *     Populated by handle_onboard / refresh_essentials when the client
	 *     plugin syncs with Central. NULL while a row is still in
	 *     pre-connect state.
	 */
	public static function install() {
		global $wpdb;

		if ( get_option( 'appress_db_app_version' ) === self::DB_VERSION ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$apps_table = $wpdb->prefix . 'appress_apps';

		// Backward-compatible 1.1.0 → 1.2.0 migration: rename the column
		// `build_information` → `build_config` in place AND relocate the
		// fields that the 2026-06-02 schema refactor moved out of
		// `live_config` into `build_config` (bottom_navigation, app_screens,
		// css_*, default_config toggles, side_menu, right_menu, first_launch,
		// require_auth_to_open, disable_web_ads*). Customer sites
		// running plugin <= 1.1.0 ship with the old column + the moved
		// fields still nested under live_config — the install() flow has
		// to preserve their data without an admin intervention. Idempotent:
		// re-running after a successful migration is a no-op.
		$existing_columns = $wpdb->get_col( "SHOW COLUMNS FROM $apps_table" );
		$has_old_column = is_array( $existing_columns ) && in_array( 'build_information', $existing_columns, true );
		$has_new_column = is_array( $existing_columns ) && in_array( 'build_config', $existing_columns, true );

		if ( $has_old_column && ! $has_new_column ) {
			$wpdb->query( "ALTER TABLE $apps_table CHANGE build_information build_config LONGTEXT" );
		}

		$sql = "CREATE TABLE $apps_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			app_name text NOT NULL,
			connection_token text NOT NULL,
			connection_token_lookup char(64) DEFAULT NULL,
			central_app_id bigint(20) unsigned DEFAULT 0,
			unique_class varchar(20) DEFAULT NULL,
			build_config longtext,
			credentials longtext,
			signing_secret text,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY connection_token_lookup (connection_token_lookup),
			KEY unique_class (unique_class)
		) $charset_collate;";

		dbDelta( $sql );

		// 1.3.0 collapses the historical `live_config` column into
		// `build_config`. Customer mobile apps boot from baked config and
		// never refresh at runtime, so the runtime-mutable distinction was
		// imaginary — every change forces a rebuild anyway. Lift every
		// remaining live_config field into build_config row-by-row, then
		// DROP the column. dbDelta cannot drop columns; the explicit ALTER
		// below is necessary and idempotent (column-existence guard).
		if ( get_option( 'appress_db_app_version' ) !== self::DB_VERSION ) {
			// 1.3.1: strip the killed IAP keys from every row's build_config
			// blob so admin re-opens don't surface stale defaults; lifted
			// outside the live_config collapse so the cleanup still runs
			// even when the column drop was already finished on an earlier
			// version bump.
			$dead_iap_keys = [ 'iap_enabled_ios', 'iap_enabled_android', 'iap_sandbox', 'iap_apple_app_id', 'iap_google_play_app_id', 'iap_google_play_developer_id' ];
			$iap_rows = $wpdb->get_results( "SELECT id, build_config FROM $apps_table WHERE build_config IS NOT NULL", ARRAY_A );
			foreach ( (array) $iap_rows as $row ) {
				$cfg = json_decode( (string) $row['build_config'], true );
				if ( ! is_array( $cfg ) ) continue;
				$touched = false;
				foreach ( $dead_iap_keys as $dk ) {
					if ( array_key_exists( $dk, $cfg ) ) { unset( $cfg[ $dk ] ); $touched = true; }
				}
				if ( $touched ) {
					$wpdb->update( $apps_table, [ 'build_config' => wp_json_encode( $cfg ) ], [ 'id' => (int) $row['id'] ] );
				}
			}

			$cols_now = $wpdb->get_col( "SHOW COLUMNS FROM $apps_table" );
			$still_has_live = is_array( $cols_now ) && in_array( 'live_config', $cols_now, true );

			if ( $still_has_live ) {
				$rows = $wpdb->get_results( "SELECT id, build_config, live_config FROM $apps_table", ARRAY_A );
				foreach ( (array) $rows as $row ) {
					$build = json_decode( (string) ( $row['build_config'] ?? '' ), true );
					$live  = json_decode( (string) ( $row['live_config']  ?? '' ), true );
					if ( ! is_array( $build ) ) $build = [];
					if ( ! is_array( $live ) )  $live  = [];

					// build_config wins on key collision — admin's most
					// recent build_config save is fresher than whatever
					// live_config snapshot is still on disk from the
					// pre-1.2.0 split.
					$merged = array_merge( $live, $build );

					$wpdb->update(
						$apps_table,
						[ 'build_config' => wp_json_encode( $merged ) ],
						[ 'id' => (int) $row['id'] ]
					);
				}
				$wpdb->query( "ALTER TABLE $apps_table DROP COLUMN live_config" );
			}
		}

		update_option( 'appress_db_app_version', self::DB_VERSION );
	}
}
