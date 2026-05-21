<?php
namespace Appress\Controllers\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Controller extends \Appress\Controllers\Base_Controller {

	const DB_VERSION = '1.1.0';

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
		$sql = "CREATE TABLE $apps_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			app_name text NOT NULL,
			connection_token text NOT NULL,
			connection_token_lookup char(64) DEFAULT NULL,
			central_app_id bigint(20) unsigned DEFAULT 0,
			unique_class varchar(20) DEFAULT NULL,
			build_information longtext,
			live_config longtext,
			credentials longtext,
			signing_secret text,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY connection_token_lookup (connection_token_lookup),
			KEY unique_class (unique_class)
		) $charset_collate;";

		dbDelta( $sql );

		update_option( 'appress_db_app_version', self::DB_VERSION );
	}
}
