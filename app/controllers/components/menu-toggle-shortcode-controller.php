<?php
namespace Appress\Controllers\Components;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * App-menu toggle widget — `[appress_menu_toggle]`.
 *
 * Renders a button that opens or closes the native side menu drawer
 * depending on where it's placed:
 *   - Inside the menu's own WebView (`window.Appress.backButtonContext === 'menu'`)
 *     → post `close_side_menu` to native.
 *   - Anywhere else (tab content, subscreen)
 *     → post `open_side_menu` to native.
 *
 * The native bridge already accepts both message types — see
 * `AppressSideMenuView` (iOS/Android) `open_side_menu` / `close_side_menu`
 * handlers. The button also carries `.appress-open-menu` so the slave JS
 * link-interceptor opens the menu even if the per-button widget JS
 * hasn't bound yet (race-safe).
 *
 * Renders on web too — outside the app the native bridge calls no-op,
 * so a menu-toggle inside a plain browser preview does nothing instead
 * of crashing. Visual styles live in `frontend-commons.css` under
 * `.appress-btn-menu-toggle` (chromeless defaults; builders apply
 * colour / size via the shared `Button_Controls_Trait`).
 */
class Menu_Toggle_Shortcode_Controller extends Base_Controller {

	const VARIANT_CLASS = 'appress-btn-menu-toggle';
	const TRIGGER_CLASS = 'appress-menu-toggle'; // listened to by menu-toggle-widget.js
	const JS_HANDLE     = 'appress:menu-toggle-widget.js';

	protected function hooks() {
		$this->on( 'init', '@register_shortcode' );
	}

	protected function register_shortcode() {
		add_shortcode( 'appress_menu_toggle', [ __CLASS__, 'render' ] );
	}

	/**
	 * Supported $atts:
	 *   - target       (left|right) Which drawer to toggle. Default 'left' so
	 *                  shortcodes embedded before the 2026-05-15 two-drawer
	 *                  feature keep their original single-drawer behavior.
	 *   - label        (string) Optional text next to the icon. Empty = icon only.
	 *   - class        (string) Extra wrapper class(es).
	 *   - icon_html    (string) Custom icon HTML pass-through. Empty falls back to hamburger.
	 *   - inline_style (string) Per-instance inline style — Avada writes CSS-var overrides here.
	 *   - demo         (yes/no) Editor-canvas mode; reserved.
	 */
	public static function render( $atts = [] ) {
		$atts = shortcode_atts( [
			'target'       => 'left',
			'label'        => '',
			'class'        => '',
			'icon_html'    => '',
			'demo'         => 'no',
			'inline_style' => '',
		], (array) $atts, 'appress_menu_toggle' );

		$target      = strtolower( (string) $atts['target'] ) === 'right' ? 'right' : 'left';
		$label       = (string) $atts['label'];
		$extra_class = ! empty( $atts['class'] ) ? ' ' . sanitize_html_class( $atts['class'] ) : '';
		// `appress-open-menu` / `appress-open-right-menu` is the native-side
		// click interceptor selector (slave JS bundle) — keeping it on the
		// wrapper means clicks fire even if `menu-toggle-widget.js` hasn't
		// run yet (e.g. early-tap race during page load).
		$intercept_class = $target === 'right' ? 'appress-open-right-menu' : 'appress-open-menu';
		$root_class  = 'appress-btn'
			. ' ' . self::VARIANT_CLASS
			. ' ' . self::TRIGGER_CLASS
			. ' ' . $intercept_class
			. $extra_class;
		$aria_label  = $label !== '' ? $label : ( $target === 'right' ? __( 'Open right menu', 'appress' ) : __( 'Menu', 'appress' ) );
		$icon_html   = $atts['icon_html'] !== '' ? $atts['icon_html'] : self::default_icon_svg();
		$style_attr  = $atts['inline_style'] !== '' ? ' style="' . esc_attr( $atts['inline_style'] ) . '"' : '';

		wp_enqueue_style( 'appress:frontend-commons.css' );
		wp_enqueue_script( self::JS_HANDLE );

		ob_start();
		?>
		<button type="button" class="<?php echo esc_attr( $root_class ); ?>" data-appress-menu-toggle data-appress-menu-target="<?php echo esc_attr( $target ); ?>" aria-label="<?php echo esc_attr( $aria_label ); ?>"<?php echo $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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
			. '<line x1="3" y1="6" x2="21" y2="6"/>'
			. '<line x1="3" y1="12" x2="21" y2="12"/>'
			. '<line x1="3" y1="18" x2="21" y2="18"/>'
			. '</svg>';
	}
}
