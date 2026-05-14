<?php
namespace Appress\Controllers\Preview;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preview-app AJAX endpoints.
 *
 * Storage model — TWO transient layers, no custom DB table:
 *
 *   Layer 1 — pairing CODE (10 min TTL, one-time-use):
 *     key  : `appress_preview_code_{6-digit}`
 *     value: { app_id, created_at }
 *     usage: admin generates → app pairs → consumed (deleted) on first
 *            successful pair. Short window because the QR/code can be
 *            screen-captured and replayed.
 *
 *   Layer 2 — paired DEVICE TOKEN (30 day TTL, refreshed on use):
 *     key  : `appress_preview_token_{sha256(plaintext)}`
 *     value: { app_id, device_label, device_platform, created_at, last_seen_at }
 *     usage: app stores plaintext locally + sends Authorization: Bearer
 *            on every preview.config / preview.refresh_info hit. Each
 *            authenticated request bumps last_seen_at AND resets the
 *            30-day TTL — active devices stay paired indefinitely;
 *            forgotten ones auto-cleanup.
 *
 * Why no DB table: preview pairing is ephemeral conversion-funnel data.
 * Free user pairs to evaluate, decides to buy/skip, drops the preview.
 * Custom table = orphan rows forever, audit trail nobody reads. Transients
 * piggyback on the WP options table + auto-GC + (when present) Redis or
 * Memcached object cache.
 *
 * Endpoints:
 *
 *   /?appress=1&action=preview.generate_code  (admin, nonce)
 *      → mints a 6-digit code (Layer 1).
 *
 *   /?appress=1&action=preview.pair  (public)
 *      → consumes code, mints token (Layer 2), returns plaintext token + app meta.
 *
 *   /?appress=1&action=preview.config  (Bearer token auth)
 *      → returns the same `live_config` payload as `app.boot`.
 *
 *   /?appress=1&action=preview.refresh_info  (Bearer token auth)
 *      → returns just `build_information` (lighter, used by home-tile refresh).
 *
 *   /?appress=1&action=preview.list_devices  (admin, nonce)
 *      → scans Layer 2 transients, returns devices for an app_id.
 *
 *   /?appress=1&action=preview.revoke  (admin, nonce)
 *      → deletes a Layer 2 transient by its token_hash (= the row id
 *        the admin UI received from list_devices).
 */
class Ajax_Controller extends \Appress\Controllers\Base_Controller {

	const CODE_TRANSIENT_PREFIX  = 'appress_preview_code_';
	const TOKEN_TRANSIENT_PREFIX = 'appress_preview_token_';
	const CODE_TTL               = 600;        // 10 minutes
	const TOKEN_TTL              = 30 * DAY_IN_SECONDS;  // 30 days, refreshed on every authenticated hit
	const PAIR_RATE_LIMIT        = 5;          // attempts
	const PAIR_RATE_WINDOW       = 60;         // seconds

	protected function hooks() {
		$this->on( 'appress_ajax_preview.generate_code', '@handle_generate_code' );
		$this->on( 'appress_ajax_preview.list_devices',  '@handle_list_devices' );

		$this->on( 'appress_ajax_preview.pair',          '@handle_pair' );
		$this->on( 'appress_ajax_nopriv_preview.pair',   '@handle_pair' );

		$this->on( 'appress_ajax_preview.config',        '@handle_config' );
		$this->on( 'appress_ajax_nopriv_preview.config', '@handle_config' );

		$this->on( 'appress_ajax_preview.refresh_info',        '@handle_refresh_info' );
		$this->on( 'appress_ajax_nopriv_preview.refresh_info', '@handle_refresh_info' );

		$this->on( 'appress_ajax_preview.revoke',        '@handle_revoke' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// 1. Generate pairing code (admin)
	// ─────────────────────────────────────────────────────────────────────

	protected function handle_generate_code() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				throw new \Exception( esc_html__( 'Unauthorized.', 'appress' ) );
			}
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'appress_admin_action' ) ) {
				throw new \Exception( esc_html__( 'Invalid nonce.', 'appress' ) );
			}
			$app_id = isset( $_POST['app_id'] ) ? absint( $_POST['app_id'] ) : 0;
			if ( $app_id <= 0 ) {
				throw new \Exception( esc_html__( 'App ID is required.', 'appress' ) );
			}

			// Defence-in-depth: confirm the app exists on this site so we
			// don't mint codes for orphaned IDs (admin pasted wrong number).
			global $wpdb;
			// Custom plugin table — no cache layer to use, must hit DB.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}appress_apps WHERE id = %d",
				$app_id
			) );
			if ( ! $exists ) {
				throw new \Exception( esc_html__( 'Application not found.', 'appress' ) );
			}

			$code = self::mint_unique_code();
			set_transient( self::CODE_TRANSIENT_PREFIX . $code, [
				'app_id'     => $app_id,
				'created_at' => time(),
			], self::CODE_TTL );

			return wp_send_json( [
				'success' => true,
				'data'    => [
					'code'       => $code,
					'expires_in' => self::CODE_TTL,
					'site_url'   => home_url( '/' ),
					'qr_payload' => wp_json_encode( [
						'site_url' => home_url( '/' ),
						'code'     => $code,
						'app_id'   => $app_id,
					] ),
				],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}

	private static function mint_unique_code() {
		// 6-digit numeric, leading zeros allowed (string). Loop until we
		// hit one that's not currently in transient — collision is
		// vanishingly rare at <100 active codes / minute.
		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
			if ( false === get_transient( self::CODE_TRANSIENT_PREFIX . $code ) ) {
				return $code;
			}
		}
		// Fallback: append millis. Still 6 chars-ish but prevents infinite loops.
		return substr( (string) ( random_int( 100000, 999999 ) ), 0, 6 );
	}

	// ─────────────────────────────────────────────────────────────────────
	// 2. Pair (public)
	// ─────────────────────────────────────────────────────────────────────

	protected function handle_pair() {
		try {
			if ( ! self::pair_rate_allowed() ) {
				throw new \Exception( esc_html__( 'Too many pairing attempts. Please wait and try again.', 'appress' ) );
			}

			// PUBLIC endpoint — auth comes from the 6-digit one-time-use
			// code (validated below) + per-IP rate limit (above), NOT a WP
			// nonce. Mobile app has no WP cookie session to mint nonces from.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
			$code = preg_replace( '/[^0-9]/', '', (string) $code );
			if ( strlen( $code ) !== 6 ) {
				throw new \Exception( esc_html__( 'Invalid pairing code.', 'appress' ) );
			}

			$transient_key = self::CODE_TRANSIENT_PREFIX . $code;
			$payload       = get_transient( $transient_key );
			if ( ! is_array( $payload ) || empty( $payload['app_id'] ) ) {
				throw new \Exception( esc_html__( 'Pairing code expired or already used.', 'appress' ) );
			}
			$app_id = (int) $payload['app_id'];

			// One-time use — delete BEFORE issuing token so a parallel
			// retry can't double-pair on the same code.
			delete_transient( $transient_key );

			global $wpdb;
			// Custom plugin table — must hit DB for fresh build_information.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT build_information FROM {$wpdb->prefix}appress_apps WHERE id = %d",
				$app_id
			), ARRAY_A );
			if ( ! $row ) {
				throw new \Exception( esc_html__( 'Application not found.', 'appress' ) );
			}
			$build_info = ! empty( $row['build_information'] ) ? json_decode( $row['build_information'], true ) : [];

			// Paired token: random 48 chars (URL-safe), hashed SHA-256 for storage.
			$paired_token = wp_generate_password( 48, false, false );
			$token_hash   = hash( 'sha256', $paired_token );

			// Display-only metadata — no auth decision uses these.
			// Public endpoint, see nonce note above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$device_label    = isset( $_POST['device_label'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['device_label'] ) ), 0, 128 ) : null;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$device_platform = isset( $_POST['device_platform'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['device_platform'] ) ), 0, 16 ) : null;

			$now = time();
			set_transient( self::TOKEN_TRANSIENT_PREFIX . $token_hash, [
				'app_id'          => $app_id,
				'device_label'    => $device_label,
				'device_platform' => $device_platform,
				'created_at'      => $now,
				'last_seen_at'    => $now,
			], self::TOKEN_TTL );

			return wp_send_json( [
				'success' => true,
				'data'    => [
					'site_url'          => home_url( '/' ),
					'app_id'            => $app_id,
					'build_information' => self::shape_build_information( $build_info ),
					'paired_token'      => $paired_token,
				],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}

	private static function pair_rate_allowed() {
		// Cheap per-IP throttle. Transient key includes IP so attacker
		// brute-forcing 6-digit codes from one host is bounded to
		// PAIR_RATE_LIMIT/window before the transient blocks them.
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key  = 'appress_preview_pair_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::PAIR_RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $hits + 1, self::PAIR_RATE_WINDOW );
		return true;
	}

	// ─────────────────────────────────────────────────────────────────────
	// 3. Config (paired_token auth) — returns the same payload shape as app.boot
	// ─────────────────────────────────────────────────────────────────────

	protected function handle_config() {
		try {
			$device = self::authenticate_device();

			global $wpdb;
			// Live config — preview pill explicitly fetches fresh on every
			// "Reload config" tap; cache would defeat the purpose.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT live_config FROM {$wpdb->prefix}appress_apps WHERE id = %d",
				$device['app_id']
			), ARRAY_A );
			if ( ! $row ) {
				throw new \Exception( esc_html__( 'Application not found.', 'appress' ) );
			}
			$live_config = ! empty( $row['live_config'] ) ? json_decode( $row['live_config'], true ) : [];

			// Hydrate the same dynamic field the app.boot endpoint adds, so
			// `inline_link_selectors` etc. are present. Single source of truth.
			$live_config['inline_link_selectors']  = \Appress\get_inline_link_selectors( (int) $device['app_id'] );
			$live_config['subscreen_url_patterns'] = \Appress\get_subscreen_url_patterns( (int) $device['app_id'] );
			$live_config['preview_mode']           = true;

			return wp_send_json( [
				'success' => true,
				'status'  => 'updated',
				'data'    => $live_config,
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// 4. Refresh build_information (paired_token auth)
	// ─────────────────────────────────────────────────────────────────────

	protected function handle_refresh_info() {
		try {
			$device = self::authenticate_device();

			global $wpdb;
			// Same fresh-data argument as handle_config — used by home-tile
			// metadata refresh, must reflect latest admin edits.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT build_information FROM {$wpdb->prefix}appress_apps WHERE id = %d",
				$device['app_id']
			), ARRAY_A );
			if ( ! $row ) {
				throw new \Exception( esc_html__( 'Application not found.', 'appress' ) );
			}
			$build_info = ! empty( $row['build_information'] ) ? json_decode( $row['build_information'], true ) : [];

			return wp_send_json( [
				'success' => true,
				'data'    => [
					'build_information' => self::shape_build_information( $build_info ),
				],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// 5. Revoke (admin)
	// ─────────────────────────────────────────────────────────────────────

	protected function handle_revoke() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				throw new \Exception( esc_html__( 'Unauthorized.', 'appress' ) );
			}
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'appress_admin_action' ) ) {
				throw new \Exception( esc_html__( 'Invalid nonce.', 'appress' ) );
			}
			// Admin UI sends the token_hash (received from list_devices as
			// the row id). Defence-in-depth: validate hex64 shape before
			// using it as a transient key.
			$token_hash = isset( $_POST['device_id'] ) ? sanitize_text_field( wp_unslash( $_POST['device_id'] ) ) : '';
			if ( ! preg_match( '/^[0-9a-f]{64}$/', $token_hash ) ) {
				throw new \Exception( esc_html__( 'Device ID is required.', 'appress' ) );
			}

			delete_transient( self::TOKEN_TRANSIENT_PREFIX . $token_hash );

			return wp_send_json( [ 'success' => true ] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// 6. List paired devices for an app (admin)
	// ─────────────────────────────────────────────────────────────────────

	protected function handle_list_devices() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				throw new \Exception( esc_html__( 'Unauthorized.', 'appress' ) );
			}
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'appress_admin_action' ) ) {
				throw new \Exception( esc_html__( 'Invalid nonce.', 'appress' ) );
			}
			$app_id = isset( $_POST['app_id'] ) ? absint( $_POST['app_id'] ) : 0;
			if ( $app_id <= 0 ) {
				throw new \Exception( esc_html__( 'App ID is required.', 'appress' ) );
			}

			// Scan all Layer 2 transients. Volume per site is typically
			// dozens; a LIKE scan over wp_options is fine. If the site
			// uses a persistent object cache, transients short-circuit
			// out of wp_options entirely — list_devices then can't find
			// them via DB scan, so fall back to a paranoid full scan.
			global $wpdb;
			$prefix    = '_transient_' . self::TOKEN_TRANSIENT_PREFIX;
			// LIKE scan over wp_options to enumerate paired-device transients.
			// Object cache wouldn't help: keys are hashed, no enumerable index.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows      = $wpdb->get_results( $wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			), ARRAY_A );

			$devices = [];
			foreach ( $rows as $r ) {
				$value = maybe_unserialize( $r['option_value'] );
				if ( ! is_array( $value ) ) continue;
				if ( (int) ( $value['app_id'] ?? 0 ) !== $app_id ) continue;
				$token_hash = substr( $r['option_name'], strlen( $prefix ) );
				$devices[] = [
					'id'              => $token_hash,
					'device_label'    => $value['device_label'] ?? null,
					'device_platform' => $value['device_platform'] ?? null,
					'created_at'      => isset( $value['created_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $value['created_at'] ) : null,
					'last_seen_at'    => isset( $value['last_seen_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $value['last_seen_at'] ) : null,
				];
			}
			// Most-recently-seen first — matches admin's mental model.
			usort( $devices, function( $a, $b ) {
				return strcmp( $b['last_seen_at'] ?? '', $a['last_seen_at'] ?? '' );
			} );

			return wp_send_json( [
				'success' => true,
				'data'    => [
					'devices' => $devices,
				],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Resolve `Authorization: Bearer …` (or `paired_token` body/query
	 * fallback for clients that strip the header) to a paired-device
	 * record. Side-effect: bumps last_seen_at AND resets the 30-day TTL
	 * so active devices keep their pairing indefinitely while abandoned
	 * ones auto-cleanup after 30 days of inactivity.
	 */
	private static function authenticate_device() {
		$token = self::extract_paired_token();
		if ( empty( $token ) ) {
			throw new \Exception( esc_html__( 'Missing pairing token.', 'appress' ) );
		}
		$hash = hash( 'sha256', $token );
		$key  = self::TOKEN_TRANSIENT_PREFIX . $hash;

		$device = get_transient( $key );
		if ( ! is_array( $device ) || empty( $device['app_id'] ) ) {
			throw new \Exception( esc_html__( 'Invalid or revoked token.', 'appress' ) );
		}

		// Touch + refresh TTL — single set_transient is one wp_options
		// write OR one cache hit if persistent object cache is present.
		$device['last_seen_at'] = time();
		set_transient( $key, $device, self::TOKEN_TTL );

		return $device;
	}

	private static function extract_paired_token() {
		$headers = function_exists( 'getallheaders' ) ? getallheaders() : [];
		foreach ( $headers as $k => $v ) {
			if ( strcasecmp( $k, 'Authorization' ) === 0 && stripos( $v, 'Bearer ' ) === 0 ) {
				return trim( substr( $v, 7 ) );
			}
		}
		// Server-side env fallback (some SAPIs don't expose headers via getallheaders).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Bearer token auth, not nonce-based.
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
			if ( stripos( $auth, 'Bearer ' ) === 0 ) {
				return trim( substr( $auth, 7 ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// Body / query fallback for HTTP clients that strip Authorization
		// (older RN / some Capacitor versions). Token IS the auth — comparing
		// the SHA-256 hash against the stored transient is the verification.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Bearer token auth, not nonce-based.
		if ( isset( $_REQUEST['paired_token'] ) ) {
			return sanitize_text_field( wp_unslash( $_REQUEST['paired_token'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return '';
	}

	/**
	 * Project the customer's `build_information` JSON down to the keys the
	 * preview app actually consumes — name, logo URL, version. Keeps the
	 * payload small and avoids leaking signing secrets / API keys that
	 * also live in `build_information` for the build-engine.
	 */
	private static function shape_build_information( array $info ) {
		return [
			'name'     => (string) ( $info['title'] ?? $info['app_name'] ?? '' ),
			'logo_url' => (string) ( $info['logo'] ?? '' ),
			'version'  => (string) ( $info['app_version'] ?? '' ),
		];
	}
}
