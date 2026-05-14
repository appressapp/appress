<?php
namespace Appress\Controllers\Biometric;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent storage for biometric (Face ID / Touch ID) login tokens.
 *
 * Each row represents one paired device for one user within one app.
 * Tokens are stored hashed (SHA-256) — the plaintext only exists on the
 * native side in Keychain / EncryptedSharedPreferences, gated behind the
 * OS biometric prompt.
 *
 * Lookup hot path is `token_hash` (every exchange call), so the unique
 * index on that column is load-bearing. Per-user revoke queries go
 * through `user_id + app_id` index.
 */
class Database_Controller extends \Appress\Controllers\Base_Controller {

	const DB_VERSION = '1.0.0';

	protected function hooks() {
		$this->on( 'admin_init', '@maybe_install' );
	}

	protected function maybe_install() {
		self::install();
	}

	public static function install() {
		global $wpdb;

		if ( get_option( 'appress_db_biometric_tokens_version' ) === self::DB_VERSION ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name = $wpdb->prefix . 'appress_biometric_tokens';

		// token_hash: SHA-256 hex of the plaintext token → 64 chars.
		// device_install_uuid: random UUID generated once per install and kept
		//   in app-local storage (lost on reinstall). Pairs exchange requests
		//   to the specific install that was issued the token — stolen token
		//   without matching UUID is rejected.
		// device_label: free-form ("iPhone 15 Pro", "Pixel 8"), collected from
		//   native at issue-time. Purely for the Phase 2 "manage paired devices"
		//   admin UI; no auth decision is made off it.
		// revoked_at: soft-delete. Keep the row so audit trails (last_used_at,
		//   created_at) survive revocation. Exchange queries filter WHERE
		//   revoked_at IS NULL.
		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			app_id bigint(20) unsigned NOT NULL,
			token_hash char(64) NOT NULL,
			device_install_uuid varchar(64) NOT NULL,
			device_platform varchar(16) NOT NULL,
			device_label varchar(128) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_used_at datetime DEFAULT NULL,
			revoked_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_app (user_id, app_id),
			KEY device_install_uuid (device_install_uuid)
		) $charset_collate;";

		dbDelta( $sql );

		update_option( 'appress_db_biometric_tokens_version', self::DB_VERSION );
	}
}
