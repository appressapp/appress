<?php
namespace Appress\Controllers\Components;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status-bar-height spacer — `[appress_status_bar_height]`.
 *
 * Renders an empty `<div>` whose height equals `--appress-status-bar-height`
 * (the CSS variable native injects at `atDocumentStart` — see
 * `screens-controller.php` for the 0px fallback that runs on web). Use
 * this to push site chrome below the iOS notch / Android status bar
 * inside the Appress WebView. Outside the app the variable resolves to
 * 0px, so the spacer collapses on desktop browsers.
 *
 * Single render entry point so shortcode + Elementor widget + Bricks
 * element produce identical markup.
 */
class Status_Bar_Height_Shortcode_Controller extends Base_Controller {

	const ROOT_CLASS = 'appress-status-bar-height';
	const CSS_HANDLE = 'appress:status-bar-height-widget.css';

	protected function hooks() {
		$this->on( 'init', '@register_shortcode' );
	}

	protected function register_shortcode() {
		add_shortcode( 'appress_status_bar_height', [ __CLASS__, 'render' ] );
	}

	/**
	 * Supported $atts:
	 *   - class (string) Extra wrapper class(es).
	 */
	public static function render( $atts = [] ) {
		$atts = shortcode_atts( [
			'class' => '',
		], (array) $atts, 'appress_status_bar_height' );

		$extra_class = ! empty( $atts['class'] ) ? ' ' . sanitize_html_class( $atts['class'] ) : '';
		$root_class  = self::ROOT_CLASS . $extra_class;

		wp_enqueue_style( self::CSS_HANDLE );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $root_class ); ?>" aria-hidden="true"></div>
		<?php
		return ob_get_clean();
	}
}
