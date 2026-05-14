<?php
namespace Appress\Controllers\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Controller extends \Appress\Controllers\Base_Controller {

	const DB_VERSION = '1.0.0';

	protected function hooks() {
		$this->on( 'admin_init', '@maybe_install' );
	}

	protected function maybe_install() {
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
			build_information longtext,
			live_config longtext,
			credentials longtext,
			signing_secret text,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY connection_token_lookup (connection_token_lookup)
		) $charset_collate;";

		dbDelta( $sql );

		update_option( 'appress_db_app_version', self::DB_VERSION );
	}
}
