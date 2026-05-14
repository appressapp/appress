<?php

namespace Appress\Controllers\App;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks per-user app usage heartbeat via the `appress/app/booted` hook.
 *
 * Publishes three derived signals that extensions (Automator triggers,
 * analytics, custom code) can listen to:
 *
 *   appress/automator/opened_today   ($user_id, $app_id)
 *     → fires exactly once per user per calendar day
 *
 *   appress/automator/user_returned  ($user_id, $gap_days, $app_id)
 *     → fires when a user opens the app at least one full day after the
 *       previous open (useful for re-engagement rewards)
 *
 *   appress/automator/user_inactive  ($user_id, $days_inactive)
 *     → fires once per user per new inactivity day, emitted from a daily
 *       cron — not from the boot hook itself, since inactive users by
 *       definition aren't opening the app
 *
 * User meta fields used:
 *   _appress_last_active            (int, unix timestamp)
 *   _appress_last_active_date       (string, YYYY-MM-DD, site timezone)
 *   _appress_last_inactive_fired    (int, days — max inactivity value already
 *                                    fired for this user; prevents re-firing
 *                                    the same day-count value)
 */
class Heartbeat_Controller extends Base_Controller {

	const CRON_HOOK = 'appress/heartbeat/check_inactive';

	protected function hooks() {
		$this->on( 'appress/app/booted', '@on_app_booted', 10, 3 );

		// Daily cron — registers on init, dispatched from the hook below.
		$this->on( 'init', '@maybe_schedule_cron' );
		$this->on( self::CRON_HOOK, '@check_inactive_users' );
	}

	/**
	 * Heartbeat writer. Updates last-active meta and publishes derived signals
	 * for same-day / returning-user recipes. Skips guest boots (user_id = 0).
	 */
	public function on_app_booted( $app_id, $user_id, $platform ) {
		$user_id = (int) $user_id;
		$app_id  = (int) $app_id;
		if ( $user_id <= 0 ) {
			return;
		}

		$old_last = (int) get_user_meta( $user_id, '_appress_last_active', true );
		$old_date = (string) get_user_meta( $user_id, '_appress_last_active_date', true );

		$now      = time();
		$today    = current_time( 'Y-m-d' );
		$gap_days = $old_last > 0 ? (int) floor( ( $now - $old_last ) / DAY_IN_SECONDS ) : 0;

		update_user_meta( $user_id, '_appress_last_active', $now );
		update_user_meta( $user_id, '_appress_last_active_date', $today );

		// First open of the calendar day — fires once per user per day.
		if ( $old_date !== $today ) {
			do_action( 'appress/automator/opened_today', $user_id, $app_id );
		}

		// User returned after at least one full day off — useful for
		// "welcome back" recipes. gap_days is the exact day count so
		// recipes can match on "returned after exactly X days".
		if ( $gap_days >= 1 ) {
			do_action( 'appress/automator/user_returned', $user_id, $gap_days, $app_id );
			// Reset the inactivity counter — they're active again, so any
			// future inactivity fires should start fresh from day 1.
			delete_user_meta( $user_id, '_appress_last_inactive_fired' );
		}
	}

	protected function maybe_schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Daily sweep: for every user who has a heartbeat on record, compute
	 * their current days_inactive. If it has advanced since the last fire,
	 * emit `appress/automator/user_inactive` with the new value (at most
	 * one fire per user per day — the counter only increments by 1/day).
	 *
	 * This is the ONLY place inactivity events originate; the boot hook
	 * resets the counter so returning users don't over-fire.
	 */
	public function check_inactive_users() {
		global $wpdb;

		// Pull everyone we've ever tracked. Typical shop/app scale (<100k
		// users) fits comfortably in memory; at hyperscale this should be
		// paginated, but that's a later-day problem.
		$rows = $wpdb->get_results(
			"SELECT user_id, meta_value AS last_active
			 FROM {$wpdb->usermeta}
			 WHERE meta_key = '_appress_last_active'",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		$now = time();
		foreach ( $rows as $row ) {
			$user_id     = (int) $row['user_id'];
			$last_active = (int) $row['last_active'];
			if ( $user_id <= 0 || $last_active <= 0 ) {
				continue;
			}

			$days_inactive = (int) floor( ( $now - $last_active ) / DAY_IN_SECONDS );
			if ( $days_inactive <= 0 ) {
				continue;
			}

			$last_fired = (int) get_user_meta( $user_id, '_appress_last_inactive_fired', true );
			if ( $days_inactive <= $last_fired ) {
				// Already fired for this or a later day-count — nothing new.
				continue;
			}

			do_action( 'appress/automator/user_inactive', $user_id, $days_inactive );
			update_user_meta( $user_id, '_appress_last_inactive_fired', $days_inactive );
		}
	}
}
