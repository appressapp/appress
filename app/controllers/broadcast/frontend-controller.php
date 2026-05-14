<?php
namespace Appress\Controllers\Broadcast;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.Security.NonceVerification.Missing
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frontend_Controller extends \Appress\Controllers\Base_Controller {

	const SIGNATURE_TS_TOLERANCE = 300;

	protected function hooks() {
		// Native App endpoint to track notification taps
		$this->on( 'appress_ajax_nopriv_app.broadcast.track_read', '@handle_track_read' );
		$this->on( 'appress_ajax_app.broadcast.track_read', '@handle_track_read' );
	}

	protected function handle_track_read() {
		try {
			// Read raw strings for the HMAC canonical so server matches exactly
			// what native concatenated. `intval('') === 0` would inject a "0"
			// segment that native (which uses an empty string) didn't sign,
			// causing every campaign-less push (Voxel events, etc.) to fail
			// signature. Validate as int separately AFTER canonical is built.
			$campaign_id_raw = isset( $_POST['campaign_id'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_id'] ) ) : '';
			$app_id_raw      = isset( $_POST['app_id'] ) ? sanitize_text_field( wp_unslash( $_POST['app_id'] ) ) : '';
			$campaign_id     = (int) $campaign_id_raw;
			$app_id          = (int) $app_id_raw;
			$device_token    = isset( $_POST['device_token'] ) ? sanitize_text_field( wp_unslash( $_POST['device_token'] ) ) : '';
			// Notification row id (or `voxel-<id>` for Voxel-forwarded events)
			// — empty for pushes without a persisted feed row. Folded into
			// the HMAC canonical so a MITM can't swap which row gets marked
			// read (still confined to the same user via device_token, but
			// integrity of *which* row matters for accurate UX state).
			$source_id    = isset( $_POST['source_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_id'] ) ) : '';

			// app_id + device_token are always required (HMAC + user resolve).
			// At least one of campaign_id / source_id must be present — campaign-only
			// pushes (admin broadcasts) need just campaign_id; integration pushes
			// (Voxel events, future plugins) only have source_id; persisted Appress
			// notifications carry both. Stats counter and mark-read each run only
			// when their respective id is present.
			if ( $app_id <= 0 || $device_token === '' ) {
				throw new \Exception( esc_html__( 'Missing required fields.', 'appress' ) );
			}
			if ( $campaign_id <= 0 && $source_id === '' ) {
				throw new \Exception( esc_html__( 'Either campaign_id or source_id is required.', 'appress' ) );
			}

			global $wpdb;
			$apps_table = $wpdb->prefix . 'appress_apps';
			$app_row    = $wpdb->get_row( $wpdb->prepare( "SELECT signing_secret FROM {$apps_table} WHERE id = %d", $app_id ), ARRAY_A );

			if ( ! $app_row ) {
				throw new \Exception( esc_html__( 'Unknown app_id.', 'appress' ) );
			}

			$signing_secret = \Appress\decrypt( (string) ( $app_row['signing_secret'] ?? '' ) );
			if ( $signing_secret === '' ) {
				throw new \Exception( esc_html__( 'App is missing its signing secret.', 'appress' ) );
			}

			$sig = isset( $_SERVER['HTTP_X_APPRESS_SIG'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_APPRESS_SIG'] ) ) : '';
			$ts  = isset( $_SERVER['HTTP_X_APPRESS_TS'] ) ? (int) ( $_SERVER['HTTP_X_APPRESS_TS'] ?? 0 ) : 0;

			if ( $sig === '' || $ts <= 0 ) {
				throw new \Exception( esc_html__( 'Signed request required.', 'appress' ) );
			}

			if ( abs( time() - $ts ) > self::SIGNATURE_TS_TOLERANCE ) {
				throw new \Exception( esc_html__( 'Request timestamp out of window.', 'appress' ) );
			}

			// Canonical: ts|app_id|campaign_id|source_id|device_token. Use the
			// raw strings (not intval'd) so empty campaign_id stays empty —
			// matches native which signs `"" + "|" + sourceId` for Voxel pushes.
			$canonical = $ts . '|' . $app_id_raw . '|' . $campaign_id_raw . '|' . $source_id . '|' . $device_token;
			$expected  = hash_hmac( 'sha256', $canonical, $signing_secret );
			if ( ! hash_equals( $expected, $sig ) ) {
				throw new \Exception( esc_html__( 'Invalid signature.', 'appress' ) );
			}

			// Resolve the user behind this device. Required for both the
			// per-user mark-read (below) and the bell-indicator cache bust.
			// The cross-origin native call has no cookies, so device_token →
			// user_id via `wp_appress_fcm_devices` is the only path.
			$devices_tbl = $wpdb->prefix . 'appress_fcm_devices';
			$user_id     = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$devices_tbl} WHERE token = %s LIMIT 1",
				$device_token
			) );

			// Per-user notification mark-read. Routed through the SAME filter
			// chain that `notifications.mark_read` uses for the in-app feed
			// click — Voxel hook claims `voxel-<id>`, default DB UPDATE handles
			// numeric appress notification ids. Idempotent (filter implementations
			// no-op if already read), so safe to run on every tap, even on the
			// dedup-blocked second tap below.
			if ( $user_id > 0 && $source_id !== '' ) {
				$handled = (bool) apply_filters( 'appress/notifications/mark_read', false, $source_id, $user_id );
				if ( ! $handled && ctype_digit( $source_id ) ) {
					$wpdb->update(
						$wpdb->prefix . 'appress_notifications',
						[ 'is_read' => 1 ],
						[ 'id' => (int) $source_id, 'user_id' => $user_id ],
						[ '%d' ],
						[ '%d', '%d' ]
					);
				}
				// Bell indicator caches unread count per user. Invalidate so
				// the next indicator poll returns the post-read count.
				delete_transient( 'appress_ind_' . $user_id );
			}

			// Stats counter only runs for campaign-attributed pushes — integration
			// pushes (Voxel events, etc.) skip this branch since they aren't tied
			// to a wp_appress_broadcast row.
			if ( $campaign_id > 0 ) {
				// Deduplicate per (campaign, device_token) so a user who re-opens
				// the notification only increments once per day.
				$dedup_key = 'appress_read_' . $campaign_id . '_' . md5( $device_token );
				if ( get_transient( $dedup_key ) ) {
					return wp_send_json( [ 'success' => true, 'message' => 'Already tracked.' ] );
				}
				set_transient( $dedup_key, 1, DAY_IN_SECONDS );

				$table_name = $wpdb->prefix . 'appress_broadcast';
				// Atomic increment to avoid race conditions
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$table_name} SET stats = JSON_SET(COALESCE(stats, '{}'), '$.read', COALESCE(JSON_EXTRACT(stats, '$.read'), 0) + 1) WHERE id = %d",
					$campaign_id
				) );
			}

			return wp_send_json( [ 'success' => true ] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}
}
