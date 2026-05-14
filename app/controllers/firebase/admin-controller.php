<?php

namespace Appress\Controllers\Firebase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Display Metabox with FCM Tokens in User Profile (Backend)
		$this->on( 'show_user_profile', '@display_fcm_tokens_in_profile', 99 );
		$this->on( 'edit_user_profile', '@display_fcm_tokens_in_profile', 99 );
	}

	private function scrub_token_globally( $token ) {
		if ( empty( $token ) ) { return; }
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'appress_fcm_devices', [ 'token' => $token ] );
	}

	public function display_fcm_tokens_in_profile( $user ) {
		// TODO: Implement UI to display connected FCM tokens from appress_fcm_devices for this $user->ID
	}
}
