<?php
namespace Appress\Controllers\Biometric;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// $t comes from $this->table() which returns $wpdb->prefix . 'appress_biometric_tokens'
// — a fixed, hardcoded table name with no user input, so interpolating it into
// the SQL string is safe and the only practical option (table names can't be
// bound via %s/%d placeholders).
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX surface + server-side lifecycle for biometric (Face ID / Touch ID) login.
 *
 * Three endpoints + two hooks:
 *
 *   auth.biometric.issue_token   (logged-in)
 *     Called by native after a successful password login in the webview.
 *     Mints a random 64-hex token, stores only its SHA-256 hash, and returns
 *     the plaintext ONCE to the caller. Native side immediately stores the
 *     plaintext in Keychain / EncryptedSharedPreferences behind the biometric
 *     gate — it never re-enters the PHP layer again.
 *
 *   auth.biometric.exchange      (nopriv)
 *     Called by native after a successful biometric prompt. Takes the
 *     plaintext token + device_install_uuid, looks up the hash, verifies
 *     the device fingerprint matches, sets the WP auth cookie, and returns
 *     success. From the user's POV the webview now loads authenticated.
 *
 *   auth.biometric.revoke        (logged-in OR nopriv with token)
 *     Clears the token for this device. Fired on app logout or when the
 *     user toggles biometric off.
 *
 * Server-side auto-revoke hooks:
 *
 *   after_password_reset + profile_update (password change)
 *     → revoke ALL tokens for that user across ALL devices. Any device
 *     still holding a stale token will get exchange=failed and fall back
 *     to password login — which is exactly right, the password is the
 *     new source of truth.
 *
 * Multi-device limit: 5 rows per (user_id, app_id). Oldest rows pruned
 * (hard delete) before insert so the new device fits in.
 *
 * Rate limit: exchange failures counted per device_install_uuid; >= 5
 * failures in 15 min → 429 Too Many Requests. Success clears the counter.
 */
class Ajax_Controller extends \Appress\Controllers\Base_Controller {

	const MAX_DEVICES_PER_APP_USER = 5;
	const EXCHANGE_RATE_LIMIT      = 5;     // fail attempts
	const EXCHANGE_RATE_WINDOW_SEC = 15 * 60;

	protected function hooks() {
		// All three endpoints are mobile-only (in-app biometric pairing,
		// token exchange, and revoke). Register on each app's
		// `<class_id>_ajax_*` prefix + legacy `appress_ajax_*` for
		// backward compat with apps shipped before unique_class. The
		// `issue_token` path has no `nopriv` registration semantically
		// (minting a token requires an authenticated session); `on_mobile`
		// registers nopriv too but the handler bails on un-auth'd calls,
		// so the extra registration is harmless.
		$this->on_mobile( 'auth.biometric.issue_token', '@handle_issue_token' );
		$this->on_mobile( 'auth.biometric.exchange',    '@handle_exchange' );
		$this->on_mobile( 'auth.biometric.revoke',      '@handle_revoke' );

		// Auto-revoke on password change. When the user resets or changes
		// their password, every previously-paired biometric device is
		// invalidated — the password is the source of truth, and rotating
		// it should sever any cached shortcuts.
		$this->on( 'after_password_reset', '@on_password_reset', 10, 1 );
		$this->on( 'profile_update',       '@on_profile_update', 10, 2 );

		// Multi-user shared-device guard. On every successful WP/WC login
		// we delete any biometric tokens on the CURRENT device that belong
		// to a different user — otherwise the new user would inherit the
		// previous user's pairing (tap Face ID → sign in as them). The
		// current device is identified by the `appress_device_uuid`
		// cookie which native sets once at app init; PHP never touches it.
		$this->on( 'wp_login', '@on_login_purge_mismatched', 10, 2 );
	}

	public function on_login_purge_mismatched( $user_login, $user ) {
		if ( ! ( $user instanceof \WP_User ) ) {
			return;
		}
		$uuid = isset( $_COOKIE['appress_device_uuid'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['appress_device_uuid'] ) )
			: '';
		if ( $uuid === '' ) {
			return;
		}
		global $wpdb;
		$t = $this->table();
		// Hard DELETE — there's no recovery value in keeping orphaned
		// rows for a device now owned by someone else. The plaintext
		// token lives only in the previous user's Keychain / prefs
		// and is useless once the server row is gone (exchange will
		// return `success: false` → native self-heals via clearToken).
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$t} WHERE device_install_uuid = %s AND user_id != %d",
			$uuid, (int) $user->ID
		) );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'appress_biometric_tokens';
	}

	private function require_int( string $key, string $source = 'request' ): int {
		// Nonce verified by per-endpoint guards (handle_revoke) or by the fact
		// that the endpoint IS the authentication (handle_exchange/issue_token
		// — token + device_install_uuid match is the credential itself).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$bag = $source === 'get' ? $_GET : $_POST;
		$v = isset( $bag[ $key ] ) ? intval( $bag[ $key ] ) : 0;
		if ( $v <= 0 ) {
			throw new \Exception( esc_html( sprintf( '%s is required.', $key ) ) );
		}
		return $v;
	}

	private function require_string( string $key, int $max_len = 256 ): string {
		// See require_int() for the nonce rationale.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$v = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		if ( $v === '' ) {
			throw new \Exception( esc_html( sprintf( '%s is required.', $key ) ) );
		}
		if ( strlen( $v ) > $max_len ) {
			throw new \Exception( esc_html( sprintf( '%s is too long.', $key ) ) );
		}
		return $v;
	}

	private function hash_token( string $plaintext ): string {
		return hash( 'sha256', $plaintext );
	}

	private function generate_token(): string {
		// 32 random bytes → 64 hex chars → ~256 bits of entropy. Plenty for
		// a bearer credential that only the paired device ever sees.
		return bin2hex( random_bytes( 32 ) );
	}

	private function rate_limit_key( string $device_install_uuid ): string {
		return 'appress_bio_fail_' . md5( $device_install_uuid );
	}

	private function is_rate_limited( string $device_install_uuid ): bool {
		$fails = (int) get_transient( $this->rate_limit_key( $device_install_uuid ) );
		return $fails >= self::EXCHANGE_RATE_LIMIT;
	}

	private function bump_failure_counter( string $device_install_uuid ): void {
		$k = $this->rate_limit_key( $device_install_uuid );
		$fails = (int) get_transient( $k );
		set_transient( $k, $fails + 1, self::EXCHANGE_RATE_WINDOW_SEC );
	}

	private function clear_failure_counter( string $device_install_uuid ): void {
		delete_transient( $this->rate_limit_key( $device_install_uuid ) );
	}

	// ── Endpoint: issue_token ────────────────────────────────────────────────

	protected function handle_issue_token() {
		try {
			if ( ! is_user_logged_in() ) {
				throw new \Exception( 'Login required.' );
			}
			$user_id = (int) get_current_user_id();
			$app_id  = $this->require_int( 'app_id' );
			$device_install_uuid = $this->require_string( 'device_install_uuid', 64 );
			$device_platform     = $this->require_string( 'device_platform', 16 );
			// Nonce-equivalent: this endpoint requires login + the issued token
			// is bound to device_install_uuid, which is the auth handshake.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$device_label        = isset( $_POST['device_label'] ) ? sanitize_text_field( wp_unslash( $_POST['device_label'] ) ) : '';

			// Re-pairing the same device: replace any existing row rather than
			// issuing a second token. Keeps the "1 row per installed app on a
			// device" invariant clean.
			global $wpdb;
			$t = $this->table();
			$wpdb->delete( $t, [
				'user_id'             => $user_id,
				'app_id'              => $app_id,
				'device_install_uuid' => $device_install_uuid,
			] );

			// Enforce per-user-per-app device cap. Oldest rows prune first so a
			// user hopping between devices doesn't grow unbounded.
			$this->prune_to_device_cap( $user_id, $app_id );

			$token_plain = $this->generate_token();
			$token_hash  = $this->hash_token( $token_plain );

			$wpdb->insert( $t, [
				'user_id'             => $user_id,
				'app_id'              => $app_id,
				'token_hash'          => $token_hash,
				'device_install_uuid' => $device_install_uuid,
				'device_platform'     => $device_platform,
				'device_label'        => $device_label !== '' ? $device_label : null,
				'created_at'          => current_time( 'mysql', true ),
			] );

			if ( ! $wpdb->insert_id ) {
				throw new \Exception( 'Failed to store token.' );
			}

			return wp_send_json( [
				'success' => true,
				'message' => 'Token issued.',
				'data'    => [ 'token' => $token_plain ],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	private function prune_to_device_cap( int $user_id, int $app_id ): void {
		global $wpdb;
		$t = $this->table();
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE user_id = %d AND app_id = %d AND revoked_at IS NULL",
			$user_id, $app_id
		) );
		// Make room for the one we're about to insert — so the threshold is
		// `MAX - 1`, not `MAX`.
		$slots_to_free = $existing - ( self::MAX_DEVICES_PER_APP_USER - 1 );
		if ( $slots_to_free <= 0 ) {
			return;
		}
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE user_id = %d AND app_id = %d AND revoked_at IS NULL
			 ORDER BY COALESCE(last_used_at, created_at) ASC LIMIT %d",
			$user_id, $app_id, $slots_to_free
		) );
		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated dynamically (one %d per id) and bound below.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE id IN ({$placeholders})", $ids ) );
		}
	}

	// ── Endpoint: exchange ───────────────────────────────────────────────────

	protected function handle_exchange() {
		try {
			$token = $this->require_string( 'token', 128 );
			$device_install_uuid = $this->require_string( 'device_install_uuid', 64 );
			$app_id = $this->require_int( 'app_id' );

			if ( $this->is_rate_limited( $device_install_uuid ) ) {
				status_header( 429 );
				throw new \Exception( 'Too many attempts. Try again later.' );
			}

			global $wpdb;
			$t = $this->table();
			$hash = $this->hash_token( $token );

			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t} WHERE token_hash = %s AND revoked_at IS NULL LIMIT 1",
				$hash
			), ARRAY_A );

			if ( ! $row ) {
				$this->bump_failure_counter( $device_install_uuid );
				throw new \Exception( 'Token not recognized.' );
			}

			// Cross-device theft guard: the install UUID is pinned at issue
			// time. A stolen token replayed from a different device is
			// rejected even if the hash lookup hits.
			if ( (string) $row['device_install_uuid'] !== $device_install_uuid ) {
				$this->bump_failure_counter( $device_install_uuid );
				throw new \Exception( 'Device mismatch.' );
			}

			if ( (int) $row['app_id'] !== $app_id ) {
				// Also guard against an app's token being replayed against
				// another app on the same device. Shouldn't happen in practice
				// — rejects cleanly if it does.
				$this->bump_failure_counter( $device_install_uuid );
				throw new \Exception( 'App mismatch.' );
			}

			$user = get_userdata( (int) $row['user_id'] );
			if ( ! $user ) {
				// User deleted server-side → implicit revoke.
				$wpdb->update( $t, [ 'revoked_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $row['id'] ] );
				$this->bump_failure_counter( $device_install_uuid );
				throw new \Exception( 'Account no longer exists.' );
			}

			// Success path: set the WP auth cookie on this request's response
			// and update last_used_at for audit + LRU eviction.
			$wpdb->update( $t, [ 'last_used_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $row['id'] ] );
			$this->clear_failure_counter( $device_install_uuid );

			wp_clear_auth_cookie();
			wp_set_current_user( (int) $user->ID );
			// `remember = true` → ~14 day cookie, matching "stay signed in"
			// behavior the biometric flow is effectively replacing.
			wp_set_auth_cookie( (int) $user->ID, true );

			return wp_send_json( [
				'success' => true,
				'message' => 'Authenticated.',
				'data'    => [
					'user_id'      => (int) $user->ID,
					'display_name' => $user->display_name,
				],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	// ── Endpoint: revoke ─────────────────────────────────────────────────────

	protected function handle_revoke() {
		try {
			global $wpdb;
			$t = $this->table();
			$now = current_time( 'mysql', true );

			// Two revoke modes: by plaintext token (native calls with token
			// it holds) OR by logged-in user + app (web-side "sign out all
			// biometric devices" action).
			if ( ! empty( $_POST['token'] ) ) {
				$token = $this->require_string( 'token', 128 );
				$wpdb->update( $t,
					[ 'revoked_at' => $now ],
					[ 'token_hash' => $this->hash_token( $token ), 'revoked_at' => null ]
				);
				return wp_send_json( [ 'success' => true, 'message' => 'Token revoked.' ] );
			}

			if ( ! is_user_logged_in() ) {
				throw new \Exception( 'Login required.' );
			}
			// CSRF guard — cookie auth alone isn't enough for a destructive
			// action. Nonce is minted on page render (see
			// Biometric\Shortcode_Controller::localize_strings) and threaded
			// through the localized config.
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'appress_biometric_revoke' ) ) {
				throw new \Exception( 'Invalid security token.' );
			}
			$user_id = (int) get_current_user_id();
			$app_id  = $this->require_int( 'app_id' );

			$wpdb->query( $wpdb->prepare(
				"UPDATE {$t} SET revoked_at = %s WHERE user_id = %d AND app_id = %d AND revoked_at IS NULL",
				$now, $user_id, $app_id
			) );

			return wp_send_json( [ 'success' => true, 'message' => 'All biometric devices revoked for this app.' ] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	// ── Auto-revoke on password change ───────────────────────────────────────

	public function on_password_reset( $user ) {
		if ( $user instanceof \WP_User ) {
			$this->revoke_all_for_user( (int) $user->ID );
		}
	}

	public function on_profile_update( $user_id, $old_user ) {
		if ( ! $old_user instanceof \WP_User ) {
			return;
		}
		$new = get_userdata( (int) $user_id );
		if ( ! $new ) {
			return;
		}
		// `wp_update_user` hashes the new password before calling profile_update,
		// so comparing hashed values is the reliable change detector that works
		// regardless of where the change came from (admin UI, REST, etc.).
		if ( isset( $new->user_pass, $old_user->user_pass ) && $new->user_pass !== $old_user->user_pass ) {
			$this->revoke_all_for_user( (int) $user_id );
		}
	}

	private function revoke_all_for_user( int $user_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$this->table()} SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL",
			current_time( 'mysql', true ), $user_id
		) );
	}
}
