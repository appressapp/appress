<?php
namespace Appress\Controllers\Account_Deletion;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Account-deletion widget renderer — `[appress_account_deletion]`.
 *
 * Two-step in-app OTP flow (no browser context switch, no password
 * required). Logged-IN state renders both screens in the same root
 * element; JS toggles visibility:
 *
 *   1. REQUEST screen (visible by default)
 *      Optional title + description + a single "Delete my account"
 *      button. Tapping the button POSTs to
 *      `auth.account_deletion.request_code` which mints a 6-digit
 *      code (only the SHA-256 of `user_id|code` stored as a 30-min
 *      transient) and emails the plaintext to the user.
 *
 *   2. VERIFY screen (hidden by default — JS reveals it after the
 *      request succeeds, with the masked email rendered into the
 *      `[data-email-placeholder]` slot)
 *      `inputmode="numeric"` + `autocomplete="one-time-code"` so iOS
 *      Mail's `Your code is …` auto-detect surfaces the digits in
 *      the keyboard suggestion bar — single tap to fill. Confirm
 *      button calls `auth.account_deletion.verify_code` which
 *      validates + runs `wp_delete_user()` + `wp_logout()` in one
 *      round-trip; success state shows a "deleted" message inline.
 *      Resend (60s cooldown) + Cancel return to the request screen.
 *
 * Logged-OUT state renders nothing — there's nothing to delete.
 *
 * Required-by-design defaults (Apple Guideline 5.1.1(v) + GDPR Art. 17):
 *   - Admin role blocked from self-delete via this widget. The widget
 *     still renders for admins but both endpoints reject them.
 *   - Content reassigned to the first site administrator (preserves
 *     orders, comments, posts).
 *   - Rate limit: 3 code mints per user per hour + 5 wrong-code
 *     attempts before the active code is invalidated.
 *
 * Styling parallels the biometric widget — CSS custom properties on
 * the root for Elementor / Bricks builders to recolour / resize via
 * one-line selector overrides. Single render entry point keeps the
 * shortcode, Elementor widget, and Bricks element byte-identical.
 */
class Shortcode_Controller extends Base_Controller {

	const ROOT_CLASS = 'appress-account-deletion';
	const CSS_HANDLE = 'appress:account-deletion-widget.css';
	const JS_HANDLE  = 'appress:account-deletion-widget.js';

	protected function hooks() {
		$this->on( 'init', '@register_shortcode' );
		$this->filter( 'appress/assets/localize/' . self::JS_HANDLE, '@localize_strings' );
	}

	protected function register_shortcode() {
		add_shortcode( 'appress_account_deletion', [ __CLASS__, 'render' ] );
	}

	public function localize_strings( $payload ) {
		$payload['AppressAccountDeletion'] = [
			// TEMPORARY confirm-only flow: widget posts directly to
			// `deleteUrl` after a `window.confirm()`. The OTP endpoints
			// stay below — flip the JS back to `requestUrl` + verify
			// screen when iCloud deliverability is sorted. One nonce
			// serves all three endpoints (same action context).
			'deleteUrl'  => home_url( '/?appress=1&action=auth.account_deletion.delete' ),
			'requestUrl' => home_url( '/?appress=1&action=auth.account_deletion.request_code' ),
			'verifyUrl'  => home_url( '/?appress=1&action=auth.account_deletion.verify_code' ),
			'nonce'      => wp_create_nonce( 'appress_account_deletion_request' ),
			// Resend cooldown in seconds. Server-side rate limit is the
			// hard cap (3/hour); this is a soft UX hint so users don't
			// hammer the button and waste a slot on the hourly budget.
			'resendCooldown' => 60,
			'i18n'           => [
				// Confirm dialog (current confirm-only flow)
				'confirm_delete'    => __( 'This will permanently delete your account. Are you sure you want to continue?', 'appress' ),
				// Request screen
				'sending'           => __( 'Sending…', 'appress' ),
				// Verify screen
				'verify_heading'    => __( 'Please enter the code sent to your account email', 'appress' ),
				'verify_hint'       => __( 'The code expires in 30 minutes.', 'appress' ),
				'verifying'         => __( 'Verifying…', 'appress' ),
				'btn_confirm'       => __( 'Confirm deletion', 'appress' ),
				'btn_resend'        => __( 'Resend code', 'appress' ),
				'btn_resend_wait'   => __( 'Resend in %ds', 'appress' ),
				'btn_cancel'        => __( 'Cancel', 'appress' ),
				// Result + error strings
				'success_deleted'   => __( 'Your account has been deleted. Goodbye!', 'appress' ),
				'redirecting'       => __( 'Redirecting…', 'appress' ),
				'err_network'       => __( 'Network error. Please try again.', 'appress' ),
				'err_generic'       => __( 'Something went wrong. Please try again later.', 'appress' ),
				'err_admin'         => __( 'Administrators cannot delete account', 'appress' ),
				'err_invalid_input' => __( 'Enter the 6-digit code from the email.', 'appress' ),
				/* translators: %d: number of attempts left */
				'err_wrong'         => __( 'Incorrect code. %d attempt(s) left.', 'appress' ),
				'err_locked'        => __( 'Too many wrong attempts. Please request a new code.', 'appress' ),
				'err_expired'       => __( 'Code expired or unused. Please request a new code.', 'appress' ),
			],
		];
		return $payload;
	}

	/**
	 * Supported $atts:
	 *   - title       (string) Optional heading shown on the request screen.
	 *   - button_text (string) Override the default "Delete my account" label.
	 *   - description (string) Short explainer rendered below the title.
	 *   - class       (string) Extra wrapper class(es).
	 *   - demo        (yes/no) Editor-canvas demo mode — skip the
	 *                 `is_user_logged_in()` gate so builder previews
	 *                 show the full layout on every state.
	 *   - preview_state (logged_in/logged_out) Editor-only override.
	 *   - button_icon_html (string) Icon HTML from the Elementor / Bricks
	 *                 icon picker. Empty falls back to the built-in
	 *                 trash glyph.
	 *   - button_inline_style (string) Avada path — applied as
	 *                 `style="..."` on the button element.
	 */
	public static function render( $atts = [] ) {
		$atts = shortcode_atts( [
			'title'                => '',
			'description'          => '',
			'button_text'          => '',
			'class'                => '',
			'demo'                 => 'no',
			'preview_state'        => '',
			'button_icon_html'     => '',
			'button_inline_style'  => '',
		], (array) $atts, 'appress_account_deletion' );

		$is_demo  = $atts['demo'] === 'yes';
		if ( $is_demo && in_array( $atts['preview_state'], [ 'logged_in', 'logged_out' ], true ) ) {
			$logged_in = $atts['preview_state'] === 'logged_in' ? '1' : '0';
		} else {
			$logged_in = is_user_logged_in() ? '1' : '0';
		}

		if ( $logged_in !== '1' && ! $is_demo ) {
			return '';
		}

		$title       = $atts['title'];
		$description = $atts['description'];
		$button_text = $atts['button_text'] !== '' ? $atts['button_text'] : __( 'Delete my account', 'appress' );

		$extra_class = ! empty( $atts['class'] ) ? ' ' . sanitize_html_class( $atts['class'] ) : '';
		$root_class  = self::ROOT_CLASS . $extra_class;
		$demo_attr   = $is_demo ? ' data-demo="1"' : '';

		$btn_style_attr = $atts['button_inline_style'] !== ''
			? ' style="' . esc_attr( $atts['button_inline_style'] ) . '"'
			: '';

		wp_enqueue_style( 'appress:frontend-commons.css' );
		wp_enqueue_style( self::CSS_HANDLE );
		wp_enqueue_script( self::JS_HANDLE );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $root_class ); ?>" data-appress-account-deletion data-logged-in="<?php echo esc_attr( $logged_in ); ?>"<?php echo $demo_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php /* ── REQUEST screen ─────────────────────────────────── */ ?>
			<div class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__screen" data-screen="request">
				<?php if ( $title !== '' ) : ?>
					<h3 class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__title"><?php echo esc_html( $title ); ?></h3>
				<?php endif; ?>
				<?php if ( $description !== '' ) : ?>
					<p class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
				<button type="button" class="appress-btn appress-btn-account-deletion" data-action="request-code"<?php echo $btn_style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<span class="appress-btn__icon-container">
						<?php
						if ( $atts['button_icon_html'] !== '' ) {
							echo $atts['button_icon_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							?>
							<svg class="appress-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<polyline points="3 6 5 6 21 6"/>
								<path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/>
								<path d="M10 11v6M14 11v6"/>
								<path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>
							</svg>
							<?php
						}
						?>
					</span>
					<span class="appress-btn__label"><?php echo esc_html( $button_text ); ?></span>
				</button>
				<p class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__message" data-message="request" role="status" aria-live="polite" style="display:none;"></p>
			</div>

			<?php /* ── VERIFY screen (hidden until code requested) ─────── */ ?>
			<div class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__screen" data-screen="verify" hidden>
				<h3 class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__title" data-verify-heading></h3>
				<p class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__description" data-verify-hint></p>
				<input
					type="text"
					class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__code-input"
					data-code-input
					inputmode="numeric"
					pattern="[0-9]*"
					autocomplete="one-time-code"
					maxlength="6"
					placeholder="<?php echo esc_attr__( '------', 'appress' ); ?>"
					aria-label="<?php echo esc_attr__( '6-digit verification code', 'appress' ); ?>"
				/>
				<button type="button" class="appress-btn appress-btn-account-deletion" data-action="verify-code" disabled>
					<span class="appress-btn__label" data-confirm-label></span>
				</button>
				<div class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__verify-actions">
					<button type="button" class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__link" data-action="resend-code" data-resend-label></button>
					<button type="button" class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__link" data-action="cancel-verify" data-cancel-label></button>
				</div>
				<p class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__message" data-message="verify" role="status" aria-live="polite" style="display:none;"></p>
			</div>

			<?php /* ── DONE screen (post-deletion confirmation) ────────── */ ?>
			<div class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__screen" data-screen="done" hidden>
				<div class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__done-icon" aria-hidden="true">✓</div>
				<p class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__done-message" data-done-message></p>
				<p class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__redirecting" data-redirecting aria-live="polite">
					<span class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__spinner" aria-hidden="true"></span>
					<span data-redirecting-label></span>
				</p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
