<?php
namespace Appress\Controllers\Firebase;

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

	public static function install() {
		global $wpdb;

		if ( get_option( 'appress_db_firebase_version' ) === self::DB_VERSION ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$fcm_table = $wpdb->prefix . 'appress_fcm_devices';
		$sql = "CREATE TABLE $fcm_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token varchar(255) NOT NULL,
			app_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned DEFAULT 0,
			platform varchar(50) DEFAULT '',
			country varchar(10) DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY app_id (app_id),
			KEY user_id (user_id),
			KEY platform (platform),
			KEY country (country)
		) $charset_collate;";

		dbDelta( $sql );

		update_option( 'appress_db_firebase_version', self::DB_VERSION );
	}
}
