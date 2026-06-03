<?php
namespace Appress\Controllers\Theme;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shortcode_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		add_shortcode( 'appress_open_app', [ $this, 'render_open_app_button' ] );

		// Visibility shortcodes
		add_shortcode( 'appress_if_app', [ $this, 'shortcode_if_app' ] );
		add_shortcode( 'appress_if_not_app', [ $this, 'shortcode_if_not_app' ] );
		add_shortcode( 'appress_if_ios', [ $this, 'shortcode_if_ios' ] );
		add_shortcode( 'appress_if_android', [ $this, 'shortcode_if_android' ] );
	}

	/**
	 * [appress_if_app]Content only in app[/appress_if_app]
	 * [appress_if_app app_id="3"]Content only in app #3[/appress_if_app]
	 */
	public function shortcode_if_app( $atts, $content = null ) {
		$atts = shortcode_atts( [ 'app_id' => 0 ], $atts, 'appress_if_app' );
		if ( \Appress\is_app( intval( $atts['app_id'] ) ) ) {
			return wp_kses_post( do_shortcode( $content ) );
		}
		return '';
	}

	/**
	 * [appress_if_not_app]Content only on web (hidden in app)[/appress_if_not_app]
	 * [appress_if_not_app app_id="3"]Hidden only in app #3[/appress_if_not_app]
	 */
	public function shortcode_if_not_app( $atts, $content = null ) {
		$atts = shortcode_atts( [ 'app_id' => 0 ], $atts, 'appress_if_not_app' );
		$app_id = intval( $atts['app_id'] );
		if ( $app_id > 0 ) {
			// Specific app: hide only in that app, show everywhere else
			if ( \Appress\is_app( $app_id ) ) return '';
			return wp_kses_post( do_shortcode( $content ) );
		}
		// No app_id: hide in all apps
		if ( ! \Appress\is_app() ) {
			return wp_kses_post( do_shortcode( $content ) );
		}
		return '';
	}

	/**
	 * [appress_if_ios]iOS only content[/appress_if_ios]
	 * [appress_if_ios app_id="3"]iOS of app #3 only[/appress_if_ios]
	 */
	public function shortcode_if_ios( $atts, $content = null ) {
		$atts = shortcode_atts( [ 'app_id' => 0 ], $atts, 'appress_if_ios' );
		if ( \Appress\is_ios( intval( $atts['app_id'] ) ) ) {
			return wp_kses_post( do_shortcode( $content ) );
		}
		return '';
	}

	/**
	 * [appress_if_android]Android only content[/appress_if_android]
	 * [appress_if_android app_id="3"]Android of app #3 only[/appress_if_android]
	 */
	public function shortcode_if_android( $atts, $content = null ) {
		$atts = shortcode_atts( [ 'app_id' => 0 ], $atts, 'appress_if_android' );
		if ( \Appress\is_android( intval( $atts['app_id'] ) ) ) {
			return wp_kses_post( do_shortcode( $content ) );
		}
		return '';
	}

	public function render_open_app_button( $atts, $content = null ) {
		// Skip rendering the button entirely inside the native app or on desktop — avoids a UI flash.
		if ( \Appress\is_app() || ! wp_is_mobile() ) {
			return '';
		}

		$atts = shortcode_atts( [
			'app_id'  => '',
			'scheme'  => '',
			'ios'     => '',
			'android' => '',
			'text'    => __( 'Open App', 'appress' ),
			'class'   => 'appress-open-app-btn',
		], $atts, 'appress_open_app' );

		$scheme      = $atts['scheme'];
		$ios_url     = $atts['ios'];
		$android_url = $atts['android'];

		// Auto-pull from database if variables are empty and app_id is passed
		if ( ! empty( $atts['app_id'] ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT build_config FROM {$table} WHERE id = %d", intval( $atts['app_id'] ) ), ARRAY_A );
			
			if ( $row ) {
				$build_info = json_decode( $row['build_config'] ?? '{}', true ) ?: [];
				$package_id = $build_info['package-id'] ?? '';
				
				if ( ! empty( $package_id ) ) {
					if ( empty( $android_url ) ) $android_url = 'https://play.google.com/store/apps/details?id=' . urlencode( $package_id );
					if ( empty( $scheme ) )      $scheme      = $package_id . '://'; 
				}
				
				if ( ! empty( $build_info['apple_app_id'] ) ) {
					if ( empty( $ios_url ) ) $ios_url = 'https://apps.apple.com/app/id' . urlencode( $build_info['apple_app_id'] );
				}
			}
		}

		$text        = $content ? do_shortcode( $content ) : esc_html( $atts['text'] );
		$btn_class   = esc_attr( $atts['class'] );

		// Register the per-button style + click-handler once per request via
		// virtual handles. wp_add_inline_* keeps the assets inside WP's enqueue
		// pipeline (no raw <style>/<script> blocks emitted from the template).
		// Per-instance data (scheme, iosUrl, androidUrl) ride on the <a> tag
		// dataset so the JS body stays static and content-safe.
		static $assets_registered = false;
		if ( ! $assets_registered ) {
			$assets_registered = true;
			wp_register_style( 'appress-open-app-btn', false, [], \Appress\get_assets_version() );
			wp_enqueue_style( 'appress-open-app-btn' );
			wp_add_inline_style( 'appress-open-app-btn', $this->open_app_btn_css() );

			wp_register_script( 'appress-open-app-btn', false, [], \Appress\get_assets_version(), true );
			wp_enqueue_script( 'appress-open-app-btn' );
			wp_add_inline_script( 'appress-open-app-btn', $this->open_app_btn_js() );
		}

		ob_start();
		$btn_id = 'appress-btn-' . uniqid();
		?>
		<a href="#"
			id="<?php echo esc_attr( $btn_id ); ?>"
			class="<?php echo $btn_class; ?>"
			data-appress-open-app
			data-scheme="<?php echo esc_attr( $scheme ); ?>"
			data-ios-url="<?php echo esc_url( $ios_url ); ?>"
			data-android-url="<?php echo esc_url( $android_url ); ?>">
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
			</svg>
			<?php echo $text; ?>
		</a>
		<?php
		return ob_get_clean();
	}

	private function open_app_btn_css(): string {
		return <<<'CSS'
.appress-open-app-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background-color: #000;
	color: #fff !important;
	padding: 12px 24px;
	border-radius: 8px;
	font-weight: 600;
	font-size: 15px;
	font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	text-decoration: none !important;
	transition: all 0.2s ease-in-out;
	box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
	border: none;
	cursor: pointer;
}
.appress-open-app-btn:hover {
	background-color: #333;
	transform: translateY(-2px);
	box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
}
.appress-open-app-btn svg {
	margin-right: 8px;
	width: 20px;
	height: 20px;
}
CSS;
	}

	private function open_app_btn_js(): string {
		return <<<'JS'
document.addEventListener('DOMContentLoaded', function() {
	document.body.addEventListener('click', function(e) {
		var btn = e.target.closest('[data-appress-open-app]');
		if (!btn) return;
		e.preventDefault();

		var isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
		var isAndroid = /Android/.test(navigator.userAgent);
		var scheme = btn.getAttribute('data-scheme') || '';
		var iosUrl = btn.getAttribute('data-ios-url') || '';
		var androidUrl = btn.getAttribute('data-android-url') || '';

		if (scheme) {
			window.location.href = scheme;
		}

		setTimeout(function() {
			if (isIos && iosUrl) {
				window.location.href = iosUrl;
			} else if (isAndroid && androidUrl) {
				window.location.href = androidUrl;
			}
		}, 1500);
	});
});
JS;
	}
}
