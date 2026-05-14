<?php
namespace Appress\Controllers\Account_Deletion;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side lifecycle for the account-deletion widget — OTP code flow.
 *
 * Two-step pattern modelled after Discord / Notion / GitHub for
 * destructive in-app actions: user stays inside the app the whole
 * time, no browser context switch.
 *
 *   auth.account_deletion.request_code  (logged-in, nonce-checked)
 *     User tapped "Delete my account". We generate a 6-digit numeric
 *     code, store only `sha256(user_id|code)` hash in a 30-minute
 *     transient (so two users minting `123456` at the same time don't
 *     collide), and email the plaintext code to the user's registered
 *     address. Subject + body are plain text so iOS Mail surfaces the
 *     code via the OS-level `oneTimeCode` autofill suggestion above
 *     the keyboard.
 *
 *   auth.account_deletion.verify_code   (logged-in, nonce-checked)
 *     User typed / autofilled the code and tapped Confirm. We re-hash
 *     `user_id|submitted_code` and look up the transient. Match →
 *     burn the transient, `wp_delete_user()` with content reassigned
 *     to the first administrator, `wp_logout()` to clear the auth
 *     cookie from THIS browser (cookie still lives even after the
 *     user row is gone otherwise), return success JSON. Mismatch →
 *     increment a wrong-attempts counter; 5 wrong attempts within
 *     one code's TTL invalidates the code and forces the user to
 *     request a new one.
 *
 * Required-by-design defaults (Apple Guideline 5.1.1(v) + GDPR Art. 17):
 *   - Admin role blocked from self-delete via this widget. Defense in
 *     depth at both endpoints.
 *   - Content reassigned to the first site administrator (preserves
 *     orders, comments, posts).
 *   - Wrong-code lockout: 5 wrong attempts on a given code invalidate
 *     it and force a fresh request_code. No per-hour mint cap — the
 *     UI's 60s resend cooldown is enough soft throttle for the
 *     legitimate user, and the active-code-replace logic means
 *     issuing a fresh code naturally invalidates the previous one.
 *
 * Why OTP code, not email link:
 *   - Mobile-first: user never leaves the app (link would open
 *     Safari, dropping session continuity).
 *   - iOS OTP autofill (`autocomplete="one-time-code"`) → 1-tap
 *     verification straight from the Mail-app keyboard suggestion.
 *   - Apple private email relay (`@privaterelay.appleid.com`) is
 *     code-only friendly — link forwarding sometimes breaks, but the
 *     6-digit code is just text the user reads + types.
 *
 * Storage shape:
 *   transient `appress_acd_<sha256(user_id|code)>` → {
 *     'user_id' => int,
 *     'email'   => string  // captured at mint time so we can show it
 *                          // post-deletion when `get_userdata()` is gone
 *   }
 *
 * Why hash includes user_id: two users minting `123456` simultaneously
 * would otherwise produce the same key and the second `set_transient`
 * would clobber the first. With `user_id|code` the keys are unique.
 */
class Ajax_Controller extends Base_Controller {

	const TRANSIENT_PREFIX   = 'appress_acd_';
	const TTL_SECONDS        = 1800;          // 30 minutes
	const MAX_WRONG_ATTEMPTS = 5;
	const ATTEMPTS_META      = 'appress_acd_verify_attempts';
	const ACTIVE_HASH_META   = 'appress_acd_active_hash';

	protected function hooks() {
		// TEMPORARY: while iCloud deliverability is being sorted out (and
		// to give Apple App Review a frictionless re-test path), the JS
		// widget calls the no-OTP `delete` endpoint below. The OTP
		// endpoints stay registered so we can flip back to email-confirm
		// by swapping the JS `deleteUrl` → `requestUrl` + showing the
		// verify screen again. Do NOT delete the OTP code paths.
		$this->on( 'appress_ajax_auth.account_deletion.delete',       '@handle_delete' );
		$this->on( 'appress_ajax_auth.account_deletion.request_code', '@handle_request_code' );
		$this->on( 'appress_ajax_auth.account_deletion.verify_code',  '@handle_verify_code' );
	}

	/**
	 * Single-shot delete — no email, no OTP. Called by the widget when
	 * the user accepts a `window.confirm()` prompt in the app. Nonce
	 * + same-user (`get_current_user_id`) is the auth for now; the
	 * destructive action is gated by the confirm dialog the user just
	 * accepted client-side.
	 *
	 * Keep this method as a clean parallel to `handle_verify_code` —
	 * same admin block, same reassign-to-admin, same logout + delete
	 * order — minus the code-validation step. When we revert to the
	 * OTP flow this stays callable from server-side scripts / tests
	 * but the widget JS no longer points at it.
	 */
	protected function handle_delete() {
		try {
			if ( ! is_user_logged_in() ) {
				throw new \Exception( esc_html__( 'You must be logged in.', 'appress' ) );
			}
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'appress_account_deletion_request' ) ) {
				throw new \Exception( esc_html__( 'Security check failed. Please refresh the page.', 'appress' ) );
			}

			$user_id = get_current_user_id();
			$user    = get_userdata( $user_id );
			if ( ! $user instanceof \WP_User ) {
				throw new \Exception( esc_html__( 'User not found.', 'appress' ) );
			}

			if ( in_array( 'administrator', (array) $user->roles, true ) ) {
				return wp_send_json( [
					'success' => false,
					'code'    => 'admin',
					'message' => __( 'Administrators cannot delete account', 'appress' ),
				] );
			}

			// Snapshot the email BEFORE wp_delete_user wipes the user row,
			// so we can render it in the success message.
			$email = (string) $user->user_email;

			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}

			$reassign_to = $this->resolve_reassign_target( $user_id );
			$ok          = wp_delete_user( $user_id, $reassign_to );

			// Clear the auth cookie from THIS browser. Must run AFTER
			// `wp_delete_user` so the `delete_user` hooks fire while the
			// user is still resolvable.
			wp_logout();

			if ( ! $ok ) {
				return wp_send_json( [
					'success' => false,
					'code'    => 'generic',
					'message' => __( 'We could not delete the account. Please contact support.', 'appress' ),
				] );
			}

			return wp_send_json( [
				'success' => true,
				'code'    => 'deleted',
				'message' => $email !== ''
					? sprintf(
						/* translators: %s: user's email address */
						__( 'Your account (%s) has been permanently deleted.', 'appress' ),
						$email
					)
					: __( 'Your account has been permanently deleted.', 'appress' ),
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'code'    => 'generic',
				'message' => $e->getMessage(),
			] );
		}
	}

	/**
	 * Generate a 6-digit code, email it, return masked email so the
	 * widget can render "We sent a code to t***@gmail.com".
	 *
	 * Response shape `{success, code?, message, email_masked?}`:
	 *   - success=true  + code=sent          → user sees verify screen
	 *   - success=false + code=admin         → admin role blocked
	 *   - success=false + code=rate_limit    → too many requests this hour
	 *   - success=false + code=generic       → unexpected failure
	 */
	protected function handle_request_code() {
		try {
			if ( ! is_user_logged_in() ) {
				throw new \Exception( esc_html__( 'You must be logged in.', 'appress' ) );
			}
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'appress_account_deletion_request' ) ) {
				throw new \Exception( esc_html__( 'Security check failed. Please refresh the page.', 'appress' ) );
			}

			$user_id = get_current_user_id();
			$user    = get_userdata( $user_id );
			if ( ! $user instanceof \WP_User ) {
				throw new \Exception( esc_html__( 'User not found.', 'appress' ) );
			}

			if ( in_array( 'administrator', (array) $user->roles, true ) ) {
				return wp_send_json( [
					'success' => false,
					'code'    => 'admin',
					'message' => __( 'Administrators cannot delete account', 'appress' ),
				] );
			}

			// Burn any previously-active code for this user. Prevents the
			// case where a user requests a second code (resend) and the
			// old code is still acceptable — we want exactly one active
			// code at a time so wrong-attempts counting is unambiguous.
			$prev_hash = (string) get_user_meta( $user_id, self::ACTIVE_HASH_META, true );
			if ( $prev_hash !== '' ) {
				delete_transient( self::TRANSIENT_PREFIX . $prev_hash );
			}

			$plain_code = $this->generate_code();
			$hash       = hash( 'sha256', $user_id . '|' . $plain_code );
			set_transient(
				self::TRANSIENT_PREFIX . $hash,
				[ 'user_id' => $user_id, 'email' => $user->user_email ],
				self::TTL_SECONDS
			);
			update_user_meta( $user_id, self::ACTIVE_HASH_META, $hash );
			// Reset wrong-attempts counter on fresh code — each code gets
			// its own 5-attempts budget independent of previous codes.
			delete_user_meta( $user_id, self::ATTEMPTS_META );

			$this->send_email( $user, $plain_code );

			return wp_send_json( [
				'success'      => true,
				'code'         => 'sent',
				'message'      => __( 'Verification code sent.', 'appress' ),
				'email_masked' => $this->mask_email( $user->user_email ),
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'code'    => 'generic',
				'message' => $e->getMessage(),
			] );
		}
	}

	/**
	 * Verify the submitted code and delete the user on match.
	 *
	 * Response shape `{success, code?, message, attempts_left?}`:
	 *   - success=true  + code=deleted          → user gone, JS shows farewell
	 *   - success=false + code=invalid_input    → code missing / wrong shape
	 *   - success=false + code=wrong            → mismatch, attempts_left field set
	 *   - success=false + code=locked           → exceeded attempts, must re-request
	 *   - success=false + code=expired          → no active code or transient expired
	 *   - success=false + code=admin            → defense in depth, role escalation guard
	 *   - success=false + code=generic          → unexpected failure
	 */
	protected function handle_verify_code() {
		try {
			if ( ! is_user_logged_in() ) {
				throw new \Exception( esc_html__( 'You must be logged in.', 'appress' ) );
			}
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'appress_account_deletion_request' ) ) {
				throw new \Exception( esc_html__( 'Security check failed. Please refresh the page.', 'appress' ) );
			}

			$submitted = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
			// Strip whitespace + non-digits so paste-from-email (e.g.
			// "123 456" or "123-456") still verifies. We require exactly
			// 6 digits after normalisation.
			$submitted = preg_replace( '/\D+/', '', $submitted );
			if ( ! is_string( $submitted ) || strlen( $submitted ) !== 6 ) {
				return wp_send_json( [
					'success' => false,
					'code'    => 'invalid_input',
					'message' => __( 'Enter the 6-digit code from the email.', 'appress' ),
				] );
			}

			$user_id = get_current_user_id();
			$user    = get_userdata( $user_id );
			if ( ! $user instanceof \WP_User ) {
				throw new \Exception( esc_html__( 'User not found.', 'appress' ) );
			}

			if ( in_array( 'administrator', (array) $user->roles, true ) ) {
				return wp_send_json( [
					'success' => false,
					'code'    => 'admin',
					'message' => __( 'Administrators cannot delete account', 'appress' ),
				] );
			}

			$active_hash = (string) get_user_meta( $user_id, self::ACTIVE_HASH_META, true );
			if ( $active_hash === '' ) {
				return wp_send_json( [
					'success' => false,
					'code'    => 'expired',
					'message' => __( 'No active code. Please request a new one.', 'appress' ),
				] );
			}

			$submitted_hash = hash( 'sha256', $user_id . '|' . $submitted );
			$payload        = get_transient( self::TRANSIENT_PREFIX . $active_hash );
			if ( ! is_array( $payload ) || empty( $payload['user_id'] ) || (int) $payload['user_id'] !== $user_id ) {
				// Transient expired between request and verify (TTL hit, or
				// WP transient cache layer evicted it). Clean stale meta.
				delete_user_meta( $user_id, self::ACTIVE_HASH_META );
				delete_user_meta( $user_id, self::ATTEMPTS_META );
				return wp_send_json( [
					'success' => false,
					'code'    => 'expired',
					'message' => __( 'Code expired. Please request a new one.', 'appress' ),
				] );
			}

			// Constant-time compare so the wrong-code path can't be timed
			// to extract bytes of the active hash. The user_id-prefix
			// already prevents cross-user collisions, but timing safety
			// is cheap so we apply it everywhere code paths compare hashes.
			if ( ! hash_equals( $active_hash, $submitted_hash ) ) {
				$attempts = (int) get_user_meta( $user_id, self::ATTEMPTS_META, true ) + 1;
				if ( $attempts >= self::MAX_WRONG_ATTEMPTS ) {
					// Burn the code + meta so a successful brute force at
					// attempt 1,000 against an evicted transient can't land.
					delete_transient( self::TRANSIENT_PREFIX . $active_hash );
					delete_user_meta( $user_id, self::ACTIVE_HASH_META );
					delete_user_meta( $user_id, self::ATTEMPTS_META );
					return wp_send_json( [
						'success' => false,
						'code'    => 'locked',
						'message' => __( 'Too many wrong attempts. Please request a new code.', 'appress' ),
					] );
				}
				update_user_meta( $user_id, self::ATTEMPTS_META, $attempts );
				return wp_send_json( [
					'success'       => false,
					'code'          => 'wrong',
					'message'       => __( 'Incorrect code. Please check the email and try again.', 'appress' ),
					'attempts_left' => self::MAX_WRONG_ATTEMPTS - $attempts,
				] );
			}

			// Burn the token + meta IMMEDIATELY so a double-tap or replay
			// can't trigger a second deletion attempt against whoever owns
			// the recycled user_id after the row is gone.
			delete_transient( self::TRANSIENT_PREFIX . $active_hash );
			delete_user_meta( $user_id, self::ACTIVE_HASH_META );
			delete_user_meta( $user_id, self::ATTEMPTS_META );

			$email = isset( $payload['email'] ) ? (string) $payload['email'] : '';

			// `wp_delete_user` lives in admin scope. Load the helper so
			// the public-facing endpoint can call it.
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}

			$reassign_to = $this->resolve_reassign_target( $user_id );
			$ok          = wp_delete_user( $user_id, $reassign_to );

			// Clear the auth cookie from THIS browser. The user row is
			// gone, but without `wp_logout` the cookie still authenticates
			// the next page load and WP throws a "user not found" warning
			// in the request lifecycle. Logout must run AFTER delete so
			// `delete_user` hooks fire while the user is still resolvable.
			wp_logout();

			if ( ! $ok ) {
				return wp_send_json( [
					'success' => false,
					'code'    => 'generic',
					'message' => __( 'We could not delete the account. Please contact support.', 'appress' ),
				] );
			}

			return wp_send_json( [
				'success' => true,
				'code'    => 'deleted',
				'message' => $email !== ''
					? sprintf(
						/* translators: %s: user's email address */
						__( 'Your account (%s) has been permanently deleted.', 'appress' ),
						$email
					)
					: __( 'Your account has been permanently deleted.', 'appress' ),
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'code'    => 'generic',
				'message' => $e->getMessage(),
			] );
		}
	}

	/**
	 * 6-digit numeric, zero-padded. `random_int` over `mt_rand` for
	 * CSPRNG quality — code lives in email so a guessing attacker has
	 * 1M combos with 30-min TTL + 5-attempts lockout = effectively
	 * unguessable, but no reason to ship a weaker generator.
	 */
	private function generate_code(): string {
		return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Mask the email for the verify screen: `j***@gmail.com`. First
	 * character + domain stay clear; middle chars become asterisks.
	 * Empty / malformed addresses fall through to the raw value so the
	 * masking helper never blocks the success response.
	 */
	private function mask_email( string $email ): string {
		$at = strpos( $email, '@' );
		if ( $at === false || $at < 1 ) {
			return $email;
		}
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at );
		if ( strlen( $local ) === 1 ) {
			return $local . '***' . $domain;
		}
		return $local[0] . str_repeat( '*', max( 1, strlen( $local ) - 1 ) ) . $domain;
	}

	private function resolve_reassign_target( int $excluding_user_id ): ?int {
		$admins = get_users( [
			'role'    => 'administrator',
			'number'  => 5,
			'fields'  => [ 'ID' ],
			'orderby' => 'ID',
			'order'   => 'ASC',
		] );
		foreach ( (array) $admins as $a ) {
			$id = (int) $a->ID;
			if ( $id > 0 && $id !== $excluding_user_id ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Transactional email — plain text, professional tone modelled after
	 * Stripe / Linear / GitHub deletion confirmations.
	 *
	 * Phrasing constraint — do not reword: the body line
	 * "Your verification code is NNNNNN" is the pattern iOS Mail scans
	 * to surface the digits as a `oneTimeCode` keyboard suggestion when
	 * the user returns to the app, enabling 1-tap autofill into the
	 * verify input.
	 *
	 * Subject is intentionally short + action-only (no code, no
	 * brackets) — code in subject leaks the digits into iOS lock-screen
	 * notification preview, which is undesirable for a destructive
	 * action. User opens the email to get the code; opening the email
	 * also serves as a soft "are you sure" gate for the deletion.
	 */
	private function send_email( \WP_User $user, string $plain_code ): void {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = __( 'Account deletion request', 'appress' );

		$display_name = $user->display_name !== '' ? $user->display_name : $user->user_login;

		$body_lines = [
			sprintf(
				/* translators: %s: user display name */
				__( 'Hello %s,', 'appress' ),
				$display_name
			),
			'',
			sprintf(
				/* translators: %s: site name */
				__( 'We received a request to permanently delete your %s account associated with this email address.', 'appress' ),
				$site_name
			),
			'',
			__( 'To proceed, enter the verification code below in the app:', 'appress' ),
			'',
			// Bare "Your verification code is NNNNNN" line triggers iOS
			// Mail's OTP auto-detect. Do not reformat — moving the
			// digits to a separate line or wrapping with markup breaks
			// the keyboard-autofill suggestion.
			sprintf(
				/* translators: %s: 6-digit verification code */
				__( 'Your verification code is %s', 'appress' ),
				$plain_code
			),
			'',
			sprintf(
				/* translators: %d: minutes until the code expires */
				__( 'This code expires in %d minutes. Once your account is deleted, the action cannot be undone and your data cannot be recovered.', 'appress' ),
				(int) ( self::TTL_SECONDS / 60 )
			),
			'',
			__( 'If you did not request this, please ignore this email — no changes will be made to your account.', 'appress' ),
			'',
			__( 'Thanks,', 'appress' ),
			$site_name,
		];

		wp_mail( $user->user_email, $subject, implode( "\r\n", $body_lines ) );
	}
}
