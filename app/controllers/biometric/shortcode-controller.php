<?php
namespace Appress\Controllers\Biometric;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Biometric widget renderer — `[appress_biometric]`.
 *
 * Logged-OUT state renders a single "Sign in with Face ID / Touch ID"
 * button that calls `window.Appress.biometric.signIn()` and reloads on
 * success. Shown only when the device already has a paired token (no
 * point offering biometric to someone who has never enabled it).
 *
 * Logged-IN state renders a panel with:
 *   • Header + live pairing status
 *   • Enable / Disable toggle (this device)
 *   • Clear all devices (revoke every paired device server-side +
 *     purge this device's local token so future signIn prompts a
 *     fresh pairing)
 *
 * The whole widget is hidden until the biometric JS bridge exists —
 * web browsers or native builds without bio support render nothing.
 *
 * Styling is done via CSS custom properties on the wrapper so
 * Elementor + Bricks builders can recolour / resize via one-line
 * selector overrides (see Notifications Controller for the pattern).
 * Single render entry point so shortcode, Elementor widget, and
 * Bricks element stay visually identical.
 */
class Shortcode_Controller extends Base_Controller {

	const ROOT_CLASS = 'appress-biometric';
	const CSS_HANDLE = 'appress:biometric-widget.css';
	const JS_HANDLE  = 'appress:biometric-widget.js';

	protected function hooks() {
		$this->on( 'init', '@register_shortcode' );
		// Translations for every user-facing string that lives in the widget JS.
		// PHP translates via __(); JS reads from `AppressBiometricI18n` global.
		$this->filter( 'appress/assets/localize/' . self::JS_HANDLE, '@localize_strings' );
	}

	protected function register_shortcode() {
		add_shortcode( 'appress_biometric', [ __CLASS__, 'render' ] );
	}

	public function localize_strings( $payload ) {
		$payload['AppressBiometric'] = [
			// Server endpoint + CSRF nonce for the revoke action.
			'ajaxUrl' => home_url( '/?appress=1&action=auth.biometric.revoke' ),
			'nonce'   => wp_create_nonce( 'appress_biometric_revoke' ),
			// Translatable strings. Short ambiguous labels use `_x` with a
			// translator context so "Enable" / "Disable" aren't misinterpreted
			// as generic toggles by translators.
			'i18n'    => [
				'status_not_enrolled'   => __( 'Set up Face ID / fingerprint in device settings first.', 'appress' ),
				'status_paired'         => __( 'Active on this device.', 'appress' ),
				'status_not_paired'     => __( 'Not paired on this device.', 'appress' ),
				'btn_enable'            => _x( 'Enable', 'biometric toggle action', 'appress' ),
				'btn_disable'           => _x( 'Disable', 'biometric toggle action', 'appress' ),
				'btn_unavailable'       => _x( 'Unavailable', 'biometric toggle state', 'appress' ),
				'err_toggle_enable'     => __( 'Could not enable biometric. Please try again.', 'appress' ),
				'err_toggle_disable'    => __( 'Could not disable biometric. Please try again.', 'appress' ),
				'err_clear_devices'     => __( 'Could not clear devices.', 'appress' ),
				'err_network'           => __( 'Network error. Please try again.', 'appress' ),
				'confirm_clear_devices' => __( 'This will remove biometric sign-in from ALL your devices. You can re-enable later. Continue?', 'appress' ),
			],
		];
		return $payload;
	}

	/**
	 * Supported $atts:
	 *   - title      (string) Header label when logged in.
	 *   - login_text (string) Logged-out button label.
	 *   - class      (string) Extra wrapper class(es).
	 *   - demo       (yes/no) Editor-canvas demo mode — skip the
	 *                `window.Appress.biometric` gate so builder
	 *                previews show the full layout even on desktop.
	 */
	public static function render( $atts = [] ) {
		$atts = shortcode_atts( [
			'title'            => '',
			'class'            => '',
			'demo'             => 'no',
			// Per-button label + icon overrides. Empty falls back to
			// the built-in i18n string / SVG glyph. Toggle's `label`
			// is the INITIAL state text only — the JS swap between
			// "Enable" / "Disable" still uses i18n strings under
			// `AppressBiometric.i18n.btn_{enable,disable}`.
			'login_label'      => '',
			'login_icon_html'  => '',
			'toggle_label'     => '',
			'toggle_icon_html' => '',
			'clear_label'      => '',
			'clear_icon_html'  => '',
			// Editor-only state override. When demo=yes AND this is set
			// (`logged_in` | `logged_out`), the renderer uses it
			// instead of `is_user_logged_in()` so admins can design
			// both surfaces from the same page without flipping their
			// own auth state. Ignored on the frontend.
			'preview_state'    => '',
			// Per-button inline styles — Avada writes CSS-var overrides
			// here (one string per button, applied as `style="..."` on
			// the matching `.appress-btn-biometric-{slug}` element).
			'login_inline_style'  => '',
			'toggle_inline_style' => '',
			'clear_inline_style'  => '',
		], (array) $atts, 'appress_biometric' );

		$title        = $atts['title'] !== '' ? $atts['title'] : __( 'Biometric authentication', 'appress' );
		$login_label  = $atts['login_label']  !== '' ? $atts['login_label']  : __( 'Sign in with Face ID / Touch ID', 'appress' );
		$toggle_label = $atts['toggle_label'] !== '' ? $atts['toggle_label'] : _x( 'Enable', 'biometric toggle action', 'appress' );
		$clear_label  = $atts['clear_label']  !== '' ? $atts['clear_label']  : __( 'Clear all devices', 'appress' );
		$is_demo      = $atts['demo'] === 'yes';
		// In editor (demo=yes) the widget honours `preview_state` so an
		// already-logged-in admin can design the logged-out surface (or
		// vice versa). On the frontend (demo=no) the override is
		// ignored — only the actual auth state decides what renders.
		if ( $is_demo && in_array( $atts['preview_state'], [ 'logged_in', 'logged_out' ], true ) ) {
			$logged_in = $atts['preview_state'] === 'logged_in' ? '1' : '0';
		} else {
			$logged_in = is_user_logged_in() ? '1' : '0';
		}
		// app_id is the row id in wp_appress_apps. Native injects it as
		// `Appress/{app_id}` in the UA; biometric only ever runs inside
		// the native WebView, so this resolver is authoritative for
		// "which app is this widget paired against". The `data-app-id`
		// attribute it produces is what the Clear-all-devices revoke
		// posts back to the server.
		$app_id      = \Appress\get_current_app_id();
		$demo_attr   = $is_demo ? ' data-demo="1"' : '';
		// Inline `display:none` hides the widget until JS confirms the native
		// bridge is present. Skip it in demo mode — Elementor's canvas appends
		// widget DOM AFTER DOMContentLoaded so our init() never wires the root,
		// leaving the panel hidden forever. In demo mode the panel is always
		// visible (no bridge check needed in builder previews).
		$style_attr  = $is_demo ? '' : ' style="display:none;"';
		$extra_class = ! empty( $atts['class'] ) ? ' ' . sanitize_html_class( $atts['class'] ) : '';
		$root_class  = self::ROOT_CLASS . ' ' . self::ROOT_CLASS . '--' . ( $logged_in === '1' ? 'panel' : 'login' ) . $extra_class;

		// Per-button inline-style attributes (Avada path).
		$login_style_attr  = $atts['login_inline_style']  !== '' ? ' style="' . esc_attr( $atts['login_inline_style'] )  . '"' : '';
		$toggle_style_attr = $atts['toggle_inline_style'] !== '' ? ' style="' . esc_attr( $atts['toggle_inline_style'] ) . '"' : '';
		$clear_style_attr  = $atts['clear_inline_style']  !== '' ? ' style="' . esc_attr( $atts['clear_inline_style'] )  . '"' : '';

		// Inner buttons share `.appress-btn` markup + variants from
		// `frontend-commons.css`. Panel chrome (header/status/error)
		// stays in `biometric-widget.css`. Both required.
		wp_enqueue_style( 'appress:frontend-commons.css' );
		wp_enqueue_style( self::CSS_HANDLE );
		wp_enqueue_script( self::JS_HANDLE );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $root_class ); ?>" data-appress-biometric data-logged-in="<?php echo esc_attr( $logged_in ); ?>" data-app-id="<?php echo esc_attr( (string) $app_id ); ?>"<?php echo $demo_attr . $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( $logged_in === '1' ) : ?>
				<div class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__header">
					<h3 class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__title"><?php echo esc_html( $title ); ?></h3>
					<span class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__status" data-status aria-live="polite" aria-atomic="true"><?php echo esc_html_x( 'Checking…', 'biometric pairing status', 'appress' ); ?></span>
				</div>
				<div class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__actions">
					<button type="button" class="appress-btn appress-btn-biometric-primary" data-action="toggle" aria-pressed="false" disabled<?php echo $toggle_style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php if ( $atts['toggle_icon_html'] !== '' ) : ?>
							<span class="appress-btn__icon-container">
								<?php echo $atts['toggle_icon_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</span>
						<?php endif; ?>
						<span class="appress-btn__label"><?php echo esc_html( $toggle_label ); ?></span>
					</button>
					<button type="button" class="appress-btn appress-btn-biometric-danger" data-action="clear-all"<?php echo $clear_style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php if ( $atts['clear_icon_html'] !== '' ) : ?>
							<span class="appress-btn__icon-container">
								<?php echo $atts['clear_icon_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</span>
						<?php endif; ?>
						<span class="appress-btn__label"><?php echo esc_html( $clear_label ); ?></span>
					</button>
				</div>
				<p class="<?php echo esc_attr( self::ROOT_CLASS ); ?>__error" data-error role="alert" style="display:none;"></p>
			<?php else : ?>
				<button type="button" class="appress-btn appress-btn-biometric-login" data-action="login"<?php echo $login_style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<span class="appress-btn__icon-container">
						<?php
						// Custom icon HTML from the Elementor icon picker;
						// fall back to the built-in lock glyph.
						if ( $atts['login_icon_html'] !== '' ) {
							echo $atts['login_icon_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							?>
							<svg class="appress-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
								<path d="M7 11V7a5 5 0 0 1 10 0v4"/>
							</svg>
							<?php
						}
						?>
					</span>
					<span class="appress-btn__label"><?php echo esc_html( $login_label ); ?></span>
				</button>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
