<?php
namespace Appress\Controllers\Login;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sign in with Apple button — `[appress_apple_login]`.
 *
 * Renders a HIG-compliant button (black/white variants, Apple logo
 * inline SVG, default text "Sign in with Apple"). The click handler is
 * already wired globally by {@see Apple_Controller::inject_js_bridge}
 * — when the user taps the button, that bridge JS catches the click,
 * calls `Capacitor.Plugins.SignInWithApple.authorize()`, and on
 * success POSTs the identity token to `auth.apple.login`.
 *
 * Visual styles come from the shared `frontend-commons.css` (base) +
 * `.appress-btn-apple-login` variant. Elementor / Bricks / Avada
 * builders write their style overrides via the shared
 * `Button_Controls_Trait` so every Appress button shares one control
 * surface.
 */
class Apple_Shortcode_Controller extends Base_Controller {

	const VARIANT_CLASS = 'appress-btn-apple-login';
	const TRIGGER_CLS   = 'appress-apple-login'; // listened to by the bridge JS in Apple_Controller
	const JS_HANDLE     = 'appress:apple-login-widget.js';

	protected function hooks() {
		$this->on( 'init', '@register_shortcode' );
	}

	protected function register_shortcode() {
		add_shortcode( 'appress_apple_login', [ __CLASS__, 'render' ] );
	}

	/**
	 * Supported $atts:
	 *   - label     (string)        Button label (Apple HIG allows
	 *                               "Sign in with Apple" / "Sign up
	 *                               with Apple" / "Continue with Apple").
	 *   - style     (black|white)   Button color variant — Apple HIG
	 *                               permits these two only. Default `black`.
	 *   - shape     (round|square)  `round` = 22pt radius, `square` = 6pt.
	 *   - class     (string)        Extra wrapper class(es).
	 *   - demo      (yes/no)        Editor-canvas mode — render visible
	 *                               regardless of platform.
	 *   - icon_html (string)        Pass-through HTML for a custom icon
	 *                               (Elementor icon picker). Empty falls
	 *                               back to the built-in Apple logo SVG.
	 */
	public static function render( $atts = [] ) {
		$atts = shortcode_atts( [
			'label'     => '',
			'style'     => 'black',
			'shape'     => 'round',
			'class'     => '',
			'demo'      => 'no',
			'icon_html'    => '',
			// Per-instance inline style — Avada writes CSS-var overrides here.
			'inline_style' => '',
		], (array) $atts, 'appress_apple_login' );

		// Hide when already authenticated. Demo mode (Elementor / Bricks
		// editor) keeps the button visible so admins can style it.
		if ( $atts['demo'] !== 'yes' && is_user_logged_in() ) {
			return '';
		}

		$label   = $atts['label'] !== '' ? $atts['label'] : __( 'Sign in with Apple', 'appress' );
		$variant = in_array( $atts['style'], [ 'black', 'white' ], true ) ? $atts['style'] : 'black';
		$shape   = in_array( $atts['shape'], [ 'round', 'square' ], true ) ? $atts['shape'] : 'round';
		$is_demo = $atts['demo'] === 'yes';

		$extra_class = ! empty( $atts['class'] ) ? ' ' . sanitize_html_class( $atts['class'] ) : '';
		$root_class  = 'appress-btn'
			. ' ' . self::VARIANT_CLASS
			. ' ' . self::VARIANT_CLASS . '--' . $variant
			. ' ' . self::VARIANT_CLASS . '--' . $shape
			. ' ' . self::TRIGGER_CLS
			. $extra_class;

		// Compose inline style: visibility gate (`display:none` outside
		// demo mode) + per-instance CSS-var overrides (Avada). Apple
		// Sign-In has no SDK on Android / desktop, so the gate hides
		// the button until the iOS Capacitor JS reveals it; demo mode
		// (builder canvas) skips that hide so admins can style.
		$style_parts = [];
		if ( ! $is_demo ) {
			$style_parts[] = 'display:none';
		}
		if ( $atts['inline_style'] !== '' ) {
			$style_parts[] = rtrim( $atts['inline_style'], '; ' );
		}
		$style_attr = $style_parts ? ' style="' . esc_attr( implode( '; ', $style_parts ) ) . '"' : '';
		$demo_attr  = $is_demo ? ' data-demo="1"' : '';
		$icon_html  = $atts['icon_html'] !== '' ? $atts['icon_html'] : self::default_icon_svg();

		wp_enqueue_style( 'appress:frontend-commons.css' );
		wp_enqueue_script( self::JS_HANDLE );

		ob_start();
		?>
		<button type="button" class="<?php echo esc_attr( $root_class ); ?>" data-appress-apple-login<?php echo $demo_attr . $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<span class="appress-btn__icon-container">
				<?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
			<?php if ( $label !== '' ) : ?>
				<span class="appress-btn__label"><?php echo esc_html( $label ); ?></span>
			<?php endif; ?>
		</button>
		<?php
		return ob_get_clean();
	}

	private static function default_icon_svg(): string {
		return '<svg class="appress-btn__icon" viewBox="0 0 17 20" fill="currentColor" aria-hidden="true">'
			. '<path d="M16.4 15.4c-.3.7-.7 1.4-1.1 2-.6.9-1.1 1.5-1.5 1.8-.6.5-1.3.8-2 .9-.5 0-1.1-.2-1.8-.5-.7-.3-1.4-.4-2-.4-.6 0-1.3.1-2.1.4-.7.3-1.3.5-1.8.5-.7 0-1.4-.3-2.1-.8-.4-.3-1-.9-1.6-1.9-.7-1-1.2-2.1-1.6-3.4C.3 12.7.1 11.5.1 10.3c0-1.4.3-2.6.9-3.6.5-.8 1.1-1.4 1.9-1.9.8-.5 1.7-.7 2.6-.7.5 0 1.2.2 2 .5.8.3 1.4.5 1.6.5.2 0 .8-.2 1.7-.6.9-.4 1.7-.5 2.4-.5 1.7.1 3 .8 3.9 2-1.5.9-2.3 2.2-2.3 3.9 0 1.3.5 2.4 1.5 3.3.4.4.9.7 1.5.9-.1.4-.3.7-.4 1.1zM12.5.4c0 1-.4 2-1.1 2.9-.9 1.1-2 1.7-3.2 1.6 0-.1 0-.3 0-.5C8.2 3.4 8.6 2.4 9.4 1.5c.4-.4.9-.8 1.5-1.1.6-.3 1.2-.4 1.7-.5 0 .2 0 .4 0 .5z"/>'
			. '</svg>';
	}
}
