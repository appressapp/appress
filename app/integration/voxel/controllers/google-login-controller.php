<?php

namespace Appress\Integration\Voxel\Controllers;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
if ( ! defined('ABSPATH') ) {
	exit;
}

class Google_Login_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Feed Voxel-specific configuration into the core Appress login flow.
		add_filter( 'appress/auth/google/button_selector', [ $this, 'get_voxel_button_selector' ] );
		add_filter( 'appress/auth/google/default_role', [ $this, 'get_voxel_default_role' ] );

		// Sync Voxel profile metadata whenever the user logs in or registers.
		add_action( 'appress/auth/google/user_registered', [ $this, 'on_voxel_user_registered' ], 10, 2 );
		add_action( 'appress/auth/google/user_logged_in', [ $this, 'on_voxel_user_logged_in' ], 10, 2 );
	}

	public function get_voxel_button_selector( $default ) {
		// Voxel's Google login button uses class .ts-google-btn and a direct google.com href.
		return 'a.ts-google-btn[href^="https://accounts.google.com/o/oauth"]';
	}

	public function get_voxel_default_role( $default ) {
		if ( function_exists( 'apply_filters' ) ) {
			return apply_filters( 'voxel/social-login/default-role', 'subscriber' );
		}
		return $default;
	}

	public function on_voxel_user_registered( $user_id, $email ) {
		update_user_meta( $user_id, 'voxel:google_auth_id', $email );

		do_action( 'voxel/user-registered', $user_id );
		if ( class_exists('\Voxel\Events\Membership\User_Registered_Event') ) {
			( new \Voxel\Events\Membership\User_Registered_Event )->dispatch( $user_id );
		}
	}

	public function on_voxel_user_logged_in( $user_id, $email ) {
		update_user_meta( $user_id, 'voxel:google_auth_id', $email );
	}
}
