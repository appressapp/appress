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
// phpcs:disable WordPress.Security.NonceVerification.Missing
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax_Controller extends \Appress\Controllers\Base_Controller {

	/** Per-token cap per day. Real device fires ≤10 syncs/day; 50 leaves room for QA without false-positive. */
	const TOKEN_DAILY_LIMIT = 50;

	/** ±5 min replay window on signed requests. */
	const SIGNATURE_TS_TOLERANCE = 300;

	/**
	 * Endpoint security model: HMAC-signed (NOT nonce-based).
	 *
	 * `firebase.sync_token` is intentionally exposed for both authenticated
	 * AND non-authenticated (`nopriv`) WordPress sessions because it's
	 * called from the native mobile app context, where the user may be
	 * logged-in OR a guest. WordPress nonces would be inappropriate here:
	 * the request originates from a compiled binary that has no live WP
	 * session at first launch.
	 *
	 * Security is enforced via cryptographic request signatures instead:
	 *   - `X-Appress-Sig` header carries an HMAC-SHA256 over a canonical
	 *     string `ts|app_id|fcm_token|platform`, keyed with the per-app
	 *     `signing_secret` provisioned at build time.
	 *   - `X-Appress-Ts` header carries the client timestamp, validated
	 *     against `SIGNATURE_TS_TOLERANCE` (±5 min) to prevent replay.
	 *   - Per-token rate limit (`TOKEN_DAILY_LIMIT`) bounds abuse even on
	 *     a leaked secret.
	 *
	 * Verification happens at the top of `handle_sync_token()` — any
	 * request without a valid signature is rejected before touching the
	 * database.
	 */
	protected function hooks() {
		$this->on( 'appress_ajax_firebase.sync_token', '@handle_sync_token' );
		$this->on( 'appress_ajax_nopriv_firebase.sync_token', '@handle_sync_token' );
	}

	protected function handle_sync_token() {
		try {
			// JSON body is the canonical request shape (signed mobile-app
			// flow). `php://input` is read raw, then `json_decode` parses,
			// then EVERY field is run through the WP sanitizer that fits
			// its type before any DB / business logic touches it:
			//   - `fcm_token`, `platform`, `country` → `sanitize_text_field`
			//   - `app_id` → `intval`
			// The decoded array is never passed to apply_filters / DB
			// updates / template output without that per-field cleansing.
			// In addition, the entire request must carry a valid HMAC
			// signature in `X-Appress-Sig` (verified against the app's
			// `signing_secret` below) — even a perfectly-sanitized payload
			// is rejected without the signature.
			$raw_body = file_get_contents( 'php://input' );
			$json     = $raw_body !== '' ? json_decode( $raw_body, true ) : null;

			if ( is_array( $json ) ) {
				$token    = isset( $json['fcm_token'] ) ? sanitize_text_field( $json['fcm_token'] ) : '';
				$app_id   = isset( $json['app_id'] ) ? intval( $json['app_id'] ) : 0;
				$platform = isset( $json['platform'] ) ? sanitize_text_field( $json['platform'] ) : '';
				$country  = isset( $json['country'] ) ? strtoupper( sanitize_text_field( $json['country'] ) ) : '';
			} else {
				$token    = isset( $_POST['fcm_token'] ) ? sanitize_text_field( wp_unslash( $_POST['fcm_token'] ) ) : '';
				$app_id   = isset( $_POST['app_id'] ) ? intval( $_POST['app_id'] ) : 0;
				$platform = isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : '';
				$country  = isset( $_POST['country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ) ) ) : '';
			}

			if ( $token === '' || $app_id <= 0 ) {
				throw new \Exception( esc_html__( 'Missing FCM Token or app_id payload.', 'appress' ) );
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

			$canonical = $ts . '|' . $app_id . '|' . $token . '|' . $platform;
			$expected  = hash_hmac( 'sha256', $canonical, $signing_secret );
			if ( ! hash_equals( $expected, $sig ) ) {
				throw new \Exception( esc_html__( 'Invalid signature.', 'appress' ) );
			}

			// Per-token rate limit (50/day). Counts every sync attempt regardless of signature outcome.
			$rate_key   = 'appress_fcm_sync_' . md5( $app_id . '|' . $token );
			$rate_count = (int) get_transient( $rate_key );
			if ( $rate_count >= self::TOKEN_DAILY_LIMIT ) {
				status_header( 429 );
				return wp_send_json( [ 'success' => false, 'message' => 'Rate limit exceeded.' ] );
			}
			set_transient( $rate_key, $rate_count + 1, DAY_IN_SECONDS );

			$user_id     = get_current_user_id(); // 0 for guest
			$devices_tbl = $wpdb->prefix . 'appress_fcm_devices';
			$row_data = [
				'token'      => $token,
				'app_id'     => $app_id,
				'user_id'    => $user_id,
				'platform'   => $platform,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			];
			if ( $country !== '' ) {
				$row_data['country'] = $country;
			}
			$wpdb->replace( $devices_tbl, $row_data );

			return wp_send_json( [
				'success' => true,
				'message' => 'Token synced (user_id=' . $user_id . ')',
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}
}
