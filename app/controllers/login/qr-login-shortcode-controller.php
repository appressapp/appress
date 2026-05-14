<?php
namespace Appress\Controllers\Login;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sign in with QR Code button — `[appress_qr_login]`.
 *
 * Renders a button that, when tapped on the web, opens a modal showing
 * a freshly-minted QR session for the user to scan with the Appress
 * mobile app. JS owned by `qr-login-widget.js`.
 *
 * Same pattern as `Apple_Shortcode_Controller`: shortcode + Elementor
 * widget + Bricks element + Avada element all call `render()` so the
 * markup is identical across surfaces.
 *
 * Inside the Appress app's WebView, the same `.appress-login-by-qr-code-trigger`
 * class triggers the NATIVE scanner — the app intercepts the click,
 * opens the camera, processes the result, and calls `qr_login.approve`
 * directly. So one button == two roles depending on context.
 *
 * Visibility: the SITE-LEVEL toggle `site_settings.qr_login.enabled`
 * (Settings page) gates whether the button renders at all. Disabled =
 * `[appress_qr_login]` is a no-op so admins can drop it into a template
 * safely while deciding whether to ship the integration. The toggle is
 * site-wide because the QR encodes only `host:token` — any Appress app
 * built for this host can scan it; per-app gating added no real
 * protection (host check inside the native scanner already rejects
 * QR codes minted for other sites).
 */
class Qr_Login_Shortcode_Controller extends Base_Controller {

	const VARIANT_CLASS = 'appress-btn-qr-login';
	const TRIGGER_CLS   = 'appress-login-by-qr-code-trigger';
	const CSS_HANDLE    = 'appress:qr-login-widget.css';
	const JS_HANDLE     = 'appress:qr-login-widget.js';

	protected function hooks() {
		$this->on( 'init', '@register_shortcode' );
	}

	protected function register_shortcode() {
		add_shortcode( 'appress_qr_login', [ __CLASS__, 'render' ] );
	}

	/**
	 * Supported $atts:
	 *   - label  (string)         Button label.
	 *   - style  (dark|light)     Color variant.
	 *   - shape  (round|square)   Corner radius.
	 *   - class  (string)         Extra wrapper class(es).
	 *   - demo   (yes/no)         Editor-canvas mode — render visible
	 *                             regardless of session state.
	 */
	public static function render( $atts = [] ) {
		$atts = shortcode_atts( [
			'label'     => '',
			'style'     => 'dark',
			'shape'     => 'round',
			'class'     => '',
			'demo'      => 'no',
			// Allow callers (Elementor widget with the icon picker) to
			// inject a custom icon HTML snippet. Empty falls back to the
			// shortcode's built-in QR glyph below.
			'icon_html'    => '',
			// Per-instance inline style — used by the Avada element to
			// stamp CSS-variable overrides directly on the button
			// (Avada lacks Bricks/Elementor's selector-scoped CSS
			// pipeline, so inline-style with the highest specificity
			// is the cleanest way to surface per-instance customisation).
			'inline_style' => '',
		], (array) $atts, 'appress_qr_login' );

		// Site-level kill switch (Settings → Sign in with QR Code).
		// Demo mode (Elementor / Bricks / Avada editor canvas) bypasses
		// the gate so admins can always preview the markup.
		if ( $atts['demo'] !== 'yes' ) {
			if ( ! self::is_enabled() ) {
				return '';
			}
			// Hide for already-signed-in visitors — the button is for
			// pre-auth flows.
			if ( is_user_logged_in() ) {
				return '';
			}
		}

		$label   = $atts['label'] !== '' ? $atts['label'] : __( 'Sign in with QR Code', 'appress' );
		$variant = in_array( $atts['style'], [ 'dark', 'light' ], true ) ? $atts['style'] : 'dark';
		$shape   = in_array( $atts['shape'], [ 'round', 'square' ], true ) ? $atts['shape'] : 'round';
		$is_demo = $atts['demo'] === 'yes';

		$extra_class = ! empty( $atts['class'] ) ? ' ' . sanitize_html_class( $atts['class'] ) : '';
		// Shared `appress-btn` + variant + style/shape modifiers + the
		// native intercept trigger class. The `Button_Controls_Trait`
		// (Elementor) writes its CSS vars at this scope.
		$root_class  = 'appress-btn'
			. ' ' . self::VARIANT_CLASS
			. ' ' . self::VARIANT_CLASS . '--' . $variant
			. ' ' . self::VARIANT_CLASS . '--' . $shape
			. ' ' . self::TRIGGER_CLS
			. $extra_class;

		$demo_attr  = $is_demo ? ' data-demo="1"' : '';
		$style_attr = $atts['inline_style'] !== '' ? ' style="' . esc_attr( $atts['inline_style'] ) . '"' : '';

		// Custom icon HTML from the Elementor icon picker, else the
		// built-in QR glyph. The trait's render helper already stamps
		// `appress-btn__icon` on Elementor's icon output so the shared
		// CSS sizes it correctly.
		$icon_html = $atts['icon_html'] !== '' ? $atts['icon_html'] : self::default_icon_svg();

		// Shared button base + every variant lives in `frontend-commons.css`;
		// this widget's CSS owns only the modal + scanner-only gate.
		wp_enqueue_style( 'appress:frontend-commons.css' );
		wp_enqueue_style( self::CSS_HANDLE );
		// Vendored Kazuhiko Arase qrcode-generator must load BEFORE the
		// widget script because the widget calls the `qrcode` global at
		// session-start time. Same footer bucket → WP prints them in
		// enqueue order, no explicit dep wiring needed.
		wp_enqueue_script( 'appress:qrcode-generator.min.js' );
		wp_enqueue_script( self::JS_HANDLE );

		ob_start();
		?>
		<button type="button" class="<?php echo esc_attr( $root_class ); ?>" data-appress-qr-login<?php echo $demo_attr . $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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
			. '<rect x="3" y="3" width="7" height="7" rx="1"/>'
			. '<rect x="14" y="3" width="7" height="7" rx="1"/>'
			. '<rect x="3" y="14" width="7" height="7" rx="1"/>'
			. '<line x1="14" y1="14" x2="14" y2="17"/>'
			. '<line x1="14" y1="20" x2="14" y2="21"/>'
			. '<line x1="17" y1="14" x2="21" y2="14"/>'
			. '<line x1="17" y1="17" x2="17" y2="21"/>'
			. '<line x1="20" y1="17" x2="20" y2="20"/>'
			. '<line x1="21" y1="20" x2="21" y2="21"/>'
			. '</svg>';
	}

	/**
	 * Read the site-level QR Login toggle from
	 * `appress_settings.site_settings.qr_login.enabled`. One source of
	 * truth used by the shortcode + Elementor / Bricks / Avada wrappers.
	 */
	private static function is_enabled(): bool {
		$site = \Appress\get( 'site_settings', [] );
		if ( ! is_array( $site ) ) {
			return false;
		}
		$qr = $site['qr_login'] ?? [];
		return is_array( $qr ) && ! empty( $qr['enabled'] );
	}
}
