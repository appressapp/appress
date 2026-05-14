<?php
namespace Appress\Controllers\Components;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Back-button widget — `[appress_back_button]`.
 *
 * Click behaviour (history first, close last) — see
 * `assets/js/back-button-widget.js` for the full priority table:
 *   1. `window.location.href !== window.Appress.screenEntryHref` (the
 *      URL native loaded into this screen) → `window.history.back()`.
 *   2. Out of in-screen history → context-based close:
 *      - side menu → `close_side_menu`
 *      - subscreen → `pop_standalone`
 *      - tab root  → button hides itself (nothing to do)
 *
 * `screenEntryHref` is injected by the native `addBackContextUserScript`
 * (iOS) / onPageStarted JS (Android) at documentStart, so the widget
 * sees a stable entry-URL anchor before any page script runs.
 *
 * Renders on web too — `history.back()` works in any browser; the native
 * bridge fallback simply no-ops outside the app. Visual styles come from
 * the shared `frontend-commons.css` (base + `.appress-btn-back` variant —
 * defaults are chromeless: transparent background, no border, no
 * padding). Builders apply colour / size via the shared
 * `Button_Controls_Trait`.
 */
class Back_Button_Shortcode_Controller extends Base_Controller {

	const VARIANT_CLASS = 'appress-btn-back';
	const TRIGGER_CLASS = 'appress-back-button'; // listened to by back-button-widget.js
	const JS_HANDLE     = 'appress:back-button-widget.js';

	protected function hooks() {
		$this->on( 'init', '@register_shortcode' );
	}

	protected function register_shortcode() {
		add_shortcode( 'appress_back_button', [ __CLASS__, 'render' ] );
	}

	/**
	 * Supported $atts:
	 *   - label     (string) Optional text next to the chevron. Empty = icon only.
	 *   - class     (string) Extra wrapper class(es).
	 *   - icon_html (string) Custom icon HTML pass-through (Elementor
	 *                        icon picker). Empty falls back to chevron.
	 *   - demo      (yes/no) Editor-canvas mode (no-op here; reserved
	 *                        for future visibility gates).
	 */
	public static function render( $atts = [] ) {
		$atts = shortcode_atts( [
			'label'     => '',
			'class'     => '',
			'icon_html'    => '',
			'demo'         => 'no',
			// Per-instance inline style — Avada writes CSS-var overrides here.
			'inline_style' => '',
		], (array) $atts, 'appress_back_button' );

		$label       = (string) $atts['label'];
		$extra_class = ! empty( $atts['class'] ) ? ' ' . sanitize_html_class( $atts['class'] ) : '';
		$root_class  = 'appress-btn'
			. ' ' . self::VARIANT_CLASS
			. ' ' . self::TRIGGER_CLASS
			. $extra_class;
		$aria_label  = $label !== '' ? $label : __( 'Back', 'appress' );
		$icon_html   = $atts['icon_html'] !== '' ? $atts['icon_html'] : self::default_icon_svg();
		$style_attr  = $atts['inline_style'] !== '' ? ' style="' . esc_attr( $atts['inline_style'] ) . '"' : '';

		wp_enqueue_style( 'appress:frontend-commons.css' );
		wp_enqueue_script( self::JS_HANDLE );

		ob_start();
		?>
		<button type="button" class="<?php echo esc_attr( $root_class ); ?>" data-appress-back-button aria-label="<?php echo esc_attr( $aria_label ); ?>"<?php echo $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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
			. '<polyline points="15 18 9 12 15 6"/>'
			. '</svg>';
	}
}
