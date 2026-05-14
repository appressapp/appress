<?php
namespace Appress\Controllers\Components;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scan QR Code button — `[appress_qr_scanner]`.
 *
 * Distinct trigger class from `[appress_qr_login]` — they are TWO
 * different actions and must not collide:
 *
 *   - `[appress_qr_login]` (`.appress-login-by-qr-code-trigger`) →
 *     always opens the WEB modal that DISPLAYS a QR for another
 *     already-signed-in device to scan. Works on browser AND inside
 *     the app (so a phone can authorise a tablet, etc).
 *   - `[appress_qr_scanner]` (`.appress-qr-scanner-trigger`) →
 *     opens the NATIVE camera so the current device can scan QRs
 *     produced elsewhere. Only useful in-app + logged in.
 *
 * Gates layered on the scanner button:
 *   - `data-appress-scanner-only="1"` attribute → CSS rule in
 *     `qr-login-widget.css` hides the button on browser (no camera
 *     bridge) and `qr-login-widget.js` reveals it once it stamps
 *     `<html>.appress-in-app`.
 *   - PHP-side `is_user_logged_in()` gate — scanner authenticates a
 *     SECOND device, which only makes sense for an originator that is
 *     already signed in. Guests get nothing rendered.
 *
 * Native scanner result handling (URL → router push, login token →
 * existing flow, other → "Unsupported" alert) lives in the iOS/Android
 * `AppressQrLoginController`.
 *
 * No new CSS / JS handles, no new marker class — everything piggybacks
 * on the QR Login bundle so we don't pay the bandwidth twice and there's
 * one source of truth for the visibility gate.
 */
class Qr_Scanner_Shortcode_Controller extends Base_Controller {

	const VARIANT_CLASS = 'appress-btn-qr-scanner';
	const TRIGGER_CLS   = 'appress-qr-scanner-trigger';
	// Re-export the QR Login asset handles so widget wrappers (Elementor /
	// Bricks / Avada) can declare a single dependency without reaching
	// into the Login namespace.
	const CSS_HANDLE    = 'appress:qr-login-widget.css';
	const JS_HANDLE     = 'appress:qr-login-widget.js';

	protected function hooks() {
		$this->on( 'init', '@register_shortcode' );
	}

	protected function register_shortcode() {
		add_shortcode( 'appress_qr_scanner', [ __CLASS__, 'render' ] );
	}

	/**
	 * Supported $atts:
	 *   - label  (string)         Button label.
	 *   - style  (dark|light)     Color variant.
	 *   - shape  (round|square)   Corner radius.
	 *   - class  (string)         Extra wrapper class(es).
	 *   - demo   (yes/no)         Editor-canvas mode — render visible
	 *                             regardless of in-app state.
	 */
	public static function render( $atts = [] ) {
		$atts = shortcode_atts( [
			'label'     => '',
			'style'     => 'dark',
			'shape'     => 'round',
			'class'     => '',
			'demo'      => 'no',
			// Allow callers (Elementor widget icon picker) to inject a
			// custom icon HTML snippet. Empty falls back to the built-in
			// scanner glyph below.
			'icon_html'    => '',
			// Per-instance inline style — Avada writes CSS-var overrides here.
			'inline_style' => '',
		], (array) $atts, 'appress_qr_scanner' );

		$label   = $atts['label'] !== '' ? $atts['label'] : __( 'Scan QR Code', 'appress' );
		$variant = in_array( $atts['style'], [ 'dark', 'light' ], true ) ? $atts['style'] : 'dark';
		$shape   = in_array( $atts['shape'], [ 'round', 'square' ], true ) ? $atts['shape'] : 'round';
		$is_demo = $atts['demo'] === 'yes';

		// Gate: scanner is only useful inside the app (CSS hides outside via
		// `data-appress-scanner-only`) AND only for logged-in users — the
		// scan result authenticates a SECOND device, which requires a
		// logged-in originator. Suppress render entirely when logged out so
		// guests don't see a button that does nothing for them. `demo` mode
		// (Elementor/Bricks editor canvas) bypasses both gates so designers
		// can lay out the button while logged out of the front-end.
		if ( ! $is_demo && ! is_user_logged_in() ) {
			return '';
		}

		$extra_class = ! empty( $atts['class'] ) ? ' ' . sanitize_html_class( $atts['class'] ) : '';
		$root_class  = 'appress-btn'
			. ' ' . self::VARIANT_CLASS
			. ' ' . self::VARIANT_CLASS . '--' . $variant
			. ' ' . self::VARIANT_CLASS . '--' . $shape
			. ' ' . self::TRIGGER_CLS
			. $extra_class;

		$demo_attr  = $is_demo ? ' data-demo="1"' : '';
		$style_attr = $atts['inline_style'] !== '' ? ' style="' . esc_attr( $atts['inline_style'] ) . '"' : '';
		$icon_html  = $atts['icon_html'] !== '' ? $atts['icon_html'] : self::default_icon_svg();

		wp_enqueue_style( 'appress:frontend-commons.css' );
		wp_enqueue_style( self::CSS_HANDLE );
		wp_enqueue_script( self::JS_HANDLE );

		ob_start();
		?>
		<button type="button" class="<?php echo esc_attr( $root_class ); ?>" data-appress-qr-scanner data-appress-scanner-only="1"<?php echo $demo_attr . $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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
		return '<svg class="appress-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M3 7V5a2 2 0 0 1 2-2h2"/>'
			. '<path d="M17 3h2a2 2 0 0 1 2 2v2"/>'
			. '<path d="M21 17v2a2 2 0 0 1-2 2h-2"/>'
			. '<path d="M7 21H5a2 2 0 0 1-2-2v-2"/>'
			. '<line x1="7" y1="12" x2="17" y2="12"/>'
			. '</svg>';
	}
}
