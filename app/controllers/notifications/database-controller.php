<?php
namespace Appress\Controllers\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->on( 'admin_init', '@maybe_install' );
	}

	protected function maybe_install() {
		self::install();
	}

	const DB_VERSION = '1.0.0';

	public static function install() {
		global $wpdb;

		if ( get_option( 'appress_db_notifications_version' ) === self::DB_VERSION ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name = $wpdb->prefix . 'appress_notifications';
		// `campaign_id` is the broadcast campaign that produced this row (NULL
		// for ad-hoc / event-driven notifications). Needed so `notifications.
		// mark_read` can clear an entire campaign via its id when the user
		// taps the FCM push — without a direct column we'd have to JSON-
		// unpack the payload on every mutation.
		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			campaign_id bigint(20) unsigned DEFAULT NULL,
			title text NOT NULL,
			body longtext NOT NULL,
			payload longtext,
			is_read tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY is_read (is_read),
			KEY campaign_id (campaign_id)
		) $charset_collate;";

		dbDelta( $sql );

		update_option( 'appress_db_notifications_version', self::DB_VERSION );
	}
}
