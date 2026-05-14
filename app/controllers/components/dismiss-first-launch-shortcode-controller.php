<?php
namespace Appress\Controllers\Components;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dismiss-first-launch button — `[appress_dismiss_first_launch]`.
 *
 * Renders a button carrying the magic class
 * `.appress-dismiss-first-launch-screen`. No JavaScript ships with the
 * shortcode: native already wires a global capture-phase click listener
 * (iOS `AppressSlaveJSService.masterDismissCaptureJS` +
 *  `appLinkInterceptorJS`; Android `AppressAppController.injectFirstLaunchClickHandler`)
 * that catches every click on `.appress-dismiss-first-launch-screen` and
 * forwards a `pop_standalone` message to the routing controller, which
 * fires `AppressFirstLaunchService.onDismissFromWeb()` to advance the boot
 * pipeline to the next phase.
 *
 * Outside the Appress app the button does nothing — the listener only
 * exists when the page is loaded inside the master WebView during the
 * First Launch phase. That's intentional: the button is meant for the
 * First Launch URL (a WordPress page admins set in the Appress dashboard).
 *
 * Visual styles come from shared `frontend-commons.css` (base +
 * `.appress-btn-dismiss-first-launch` variant — chromeless defaults).
 * Builders style via the shared `Button_Controls_Trait`.
 */
class Dismiss_First_Launch_Shortcode_Controller extends Base_Controller {

	const VARIANT_CLASS = 'appress-btn-dismiss-first-launch';
	const TRIGGER_CLASS = 'appress-dismiss-first-launch-screen';

	protected function hooks() {
		$this->on( 'init', '@register_shortcode' );
	}

	protected function register_shortcode() {
		add_shortcode( 'appress_dismiss_first_launch', [ __CLASS__, 'render' ] );
	}

	/**
	 * Supported $atts:
	 *   - label     (string) Button text. Defaults to "Get started".
	 *   - class     (string) Extra wrapper class(es).
	 *   - icon_html (string) Custom icon HTML pass-through (Elementor
	 *                        icon picker). Empty = no icon (this widget
	 *                        is text-first by default).
	 */
	public static function render( $atts = [] ) {
		$atts = shortcode_atts( [
			'label'     => '',
			'class'     => '',
			'icon_html'    => '',
			// Per-instance inline style — Avada writes CSS-var overrides here.
			'inline_style' => '',
		], (array) $atts, 'appress_dismiss_first_launch' );

		$label       = $atts['label'] !== '' ? (string) $atts['label'] : __( 'Get started', 'appress' );
		$extra_class = ! empty( $atts['class'] ) ? ' ' . sanitize_html_class( $atts['class'] ) : '';
		// `TRIGGER_CLASS` is the contract native listens for — keep present.
		$root_class  = 'appress-btn'
			. ' ' . self::VARIANT_CLASS
			. ' ' . self::TRIGGER_CLASS
			. $extra_class;

		wp_enqueue_style( 'appress:frontend-commons.css' );

		$style_attr = $atts['inline_style'] !== '' ? ' style="' . esc_attr( $atts['inline_style'] ) . '"' : '';

		ob_start();
		?>
		<button type="button" class="<?php echo esc_attr( $root_class ); ?>"<?php echo $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( $atts['icon_html'] !== '' ) : ?>
				<span class="appress-btn__icon-container">
					<?php echo $atts['icon_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
			<?php endif; ?>
			<?php if ( $label !== '' ) : ?>
				<span class="appress-btn__label"><?php echo esc_html( $label ); ?></span>
			<?php endif; ?>
		</button>
		<?php
		return ob_get_clean();
	}
}
