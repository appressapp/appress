<?php
namespace Appress\Controllers\Broadcast;

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

		if ( get_option( 'appress_db_broadcast_version' ) === self::DB_VERSION ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$broadcast_table = $wpdb->prefix . 'appress_broadcast';
		$sql = "CREATE TABLE $broadcast_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL,
			body text,
			payload longtext,
			target_apps longtext,
			target_platforms varchar(255),
			stats longtext,
			status varchar(50) DEFAULT 'draft',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) $charset_collate;";

		dbDelta( $sql );

		// Per-recipient queue so the cron can chunk-drain large campaigns
		// instead of doing every DB insert + FCM call in a single run (which
		// would timeout on campaigns with more than a few hundred users).
		// One row = one FCM call's worth of work (a user's token set, or the
		// shared guest bucket for an app).
		$queue_table = $wpdb->prefix . 'appress_broadcast_queue';
		$queue_sql = "CREATE TABLE $queue_table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL,
			app_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tokens longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY campaign_id (campaign_id)
		) $charset_collate;";

		dbDelta( $queue_sql );

		update_option( 'appress_db_broadcast_version', self::DB_VERSION );
	}
}
