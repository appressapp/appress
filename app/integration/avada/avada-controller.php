<?php

namespace Appress\Integration\Avada;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Avada Builder (Fusion Builder) integration: adds Appress conditional
 * rendering + custom Fusion Builder elements (back button, status-bar
 * height spacer, dismiss-first-launch, biometric, apple-login,
 * notifications).
 *
 * Toggle-gated via the Integrations admin page. The card registers
 * unconditionally; the actual filter/action hooks only run when the
 * admin enables the module via Integrations → Avada Builder.
 *
 * Reference points (Fusion Builder 3.15+):
 *   - Custom conditions: `awb_custom_rendering_conditions` filter
 *     (handled by `Fusion_Builder_Conditional_Render_Helper::get_custom_options`).
 *   - Custom elements: `fusion_builder_shortcodes_init` action +
 *     `fusion_builder_map()` registration.
 */
class Avada_Controller extends \Appress\Controllers\Base_Controller {

	const GROUP    = 'appress';
	const CATEGORY = 'appress';

	protected function hooks() {
		// Register integration card on the Appress Integrations page.
		$this->filter( 'appress/integrations/registered', '@register_integration' );

		// Run integrations ONLY when dynamically activated by the Integrations Manager.
		$this->on( 'appress/integration/avada/execute', '@bootstrap_integrations' );
	}

	/**
	 * Adds the Avada Builder card to the Appress Integrations admin page.
	 * `requires_plugin.class = FusionBuilder` is the gate the Integrations
	 * UI uses to render the "install Avada Builder first" CTA when the
	 * plugin isn't active.
	 */
	protected function register_integration( $integrations ) {
		$integrations['avada'] = [
			'name'            => __( 'Avada Theme', 'appress' ),
			'description'     => __( 'Native Appress elements + In-App visibility conditions inside the Avada theme builder.', 'appress' ),
			// Vue Integrations list resolves `icon` via 3 paths:
			//   - dashicons-* → CSS class
			//   - URL ending in .svg/.png/etc. → <img>
			//   - inline SVG markup → v-html
			// Convention: each integration ships `logo.svg` in its own
			// folder and references it by URL, keeping the brand glyph
			// next to the controller that owns it.
			'icon'            => APPRESS_PLUGIN_URL . 'app/integration/avada/logo.svg',
			'color'           => 'purple',
			'configurable'    => false,
			// Gate on the Avada theme's main class (defined in
			// `themes/Avada/includes/class-avada.php`). The theme bundles
			// Fusion Builder, so when the theme is active the builder is
			// usually present too — `bootstrap_integrations` re-checks
			// `FusionBuilder` separately so a stale toggle doesn't crash
			// the site if only the theme is active.
			'requires_plugin' => [
				'name'  => 'Avada Theme',
				'class' => 'Avada',
			],
			'integrations'        => [
				'elements'   => __( 'Custom builder elements', 'appress' ),
				'visibility' => __( 'In-App conditional rendering', 'appress' ),
			],
		];
		return $integrations;
	}

	/**
	 * Fired by Integrations Manager when the Avada toggle is ON. Wires the
	 * filter + action hooks that drive conditional rendering and custom
	 * element registration. Constant + class checks defend against a
	 * disabled-but-installed Fusion Builder so a stale toggle doesn't
	 * crash the site.
	 */
	protected function bootstrap_integrations() {
		if ( ! defined( 'FUSION_BUILDER_VERSION' ) || ! class_exists( 'FusionBuilder' ) ) {
			return;
		}

		// Conditional rendering: lets editors hide/show elements based on
		// whether the visitor is browsing inside the Appress mobile app.
		add_filter( 'awb_custom_rendering_conditions', [ $this, 'register_conditions' ] );

		// Append our shortcodes to Fusion's element whitelist
		// (`fusion_builder_settings.fusion_elements`). Once an admin saves
		// the Avada Builder Options page, that option freezes whatever
		// element list existed at save time — any element registered later
		// by a plugin (us) gets silently filtered out of
		// `$fusion_builder_elements` at line 311 of fusion-builder/inc/shortcodes.php
		// and therefore never reaches the live-editor element panel.
		// Fresh installs work because the option is empty and the code
		// auto-enables every registered shortcode; sites that have ever
		// hit Save break without this filter. See bug history: avada.appress.app
		// 2026-05-06 — element files loaded, classes instantiated, shortcodes
		// registered, but the panel was still empty until this hook landed.
		add_filter( 'fusion_builder_enabled_elements', [ $this, 'whitelist_elements' ] );

		// Fusion Builder element registration. `init` priority 5 runs
		// after `after_setup_theme` (where `FusionBuilder::get_instance()`
		// loads `Fusion_Element` parent class), and BEFORE Avada Builder
		// fires its own `fusion_builder_before_init` action — which is
		// the actual hook every Fusion element wires its `fusion_builder_map`
		// into. The require_once needs to land between those two so the
		// element files can extend `Fusion_Element` AND have their
		// `add_action` listeners registered before the action fires.
		add_action( 'init', [ $this, 'register_elements' ], 5 );

		// Brand glyph for Avada Builder's element panel.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_builder_assets' ] );
	}

	// ── Conditional rendering ────────────────────────────────────────────

	/**
	 * Each entry feeds two pipelines inside Fusion Builder:
	 *   1. `param` is appended to the conditions dropdown via
	 *      `Fusion_Builder_Conditional_Render_Helper::maybe_add_custom_options`
	 *      (each `param` becomes a `choices[]` entry).
	 *   2. `callback` runs at render-time inside `get_value()` to return
	 *      the current value to compare against the editor-supplied value.
	 *
	 * Comparison shape mirrors the built-in `user_state`: select with
	 * yes/no options + equal/not-equal comparisons. Editor flow:
	 *   field = "In Appress App" → comparison = "Equal To" → value = "Yes".
	 */
	public function register_conditions( $conditions ) {
		if ( ! is_array( $conditions ) ) {
			$conditions = [];
		}

		$yesno = [
			'true'  => esc_html__( 'Yes', 'appress' ),
			'false' => esc_html__( 'No', 'appress' ),
		];
		$compare = [
			'equal'     => esc_attr__( 'Equal To', 'appress' ),
			'not-equal' => esc_attr__( 'Not Equal To', 'appress' ),
		];

		$conditions['appress_in_app'] = [
			'param'    => [
				'id'          => 'appress_in_app',
				'title'       => esc_html__( 'In Appress App', 'appress' ),
				'group'       => esc_html__( 'Appress', 'appress' ),
				'type'        => 'select',
				'options'     => $yesno,
				'comparisons' => $compare,
			],
			'callback' => [ __CLASS__, 'eval_in_app' ],
		];
		$conditions['appress_in_android'] = [
			'param'    => [
				'id'          => 'appress_in_android',
				'title'       => esc_html__( 'In Appress App (Android)', 'appress' ),
				'group'       => esc_html__( 'Appress', 'appress' ),
				'type'        => 'select',
				'options'     => $yesno,
				'comparisons' => $compare,
			],
			'callback' => [ __CLASS__, 'eval_in_android' ],
		];
		$conditions['appress_in_ios'] = [
			'param'    => [
				'id'          => 'appress_in_ios',
				'title'       => esc_html__( 'In Appress App (iOS)', 'appress' ),
				'group'       => esc_html__( 'Appress', 'appress' ),
				'type'        => 'select',
				'options'     => $yesno,
				'comparisons' => $compare,
			],
			'callback' => [ __CLASS__, 'eval_in_ios' ],
		];
		$conditions['appress_app_id'] = [
			'param'    => [
				'id'          => 'appress_app_id',
				'title'       => esc_html__( 'Appress App ID', 'appress' ),
				'group'       => esc_html__( 'Appress', 'appress' ),
				'placeholder' => esc_attr__( 'e.g. 3', 'appress' ),
				'type'        => 'text',
				'comparisons' => [
					'equal'     => esc_attr__( 'Equal To', 'appress' ),
					'not-equal' => esc_attr__( 'Not Equal To', 'appress' ),
				],
			],
			'callback' => [ __CLASS__, 'eval_app_id' ],
		];

		return $conditions;
	}

	public static function eval_in_app( $value, $additionals ) {
		return \Appress\is_app() ? 'true' : 'false';
	}

	public static function eval_in_android( $value, $additionals ) {
		return \Appress\is_android() ? 'true' : 'false';
	}

	public static function eval_in_ios( $value, $additionals ) {
		return \Appress\is_ios() ? 'true' : 'false';
	}

	public static function eval_app_id( $value, $additionals ) {
		// Returns the current app id as a string so Fusion's standard
		// equal / not-equal comparison can match it against the value the
		// editor typed. `0` outside the Appress app — user picks
		// "Equal To" + their app id to gate on a specific build.
		return (string) (int) \Appress\get_current_app_id();
	}

	// ── Element registration ─────────────────────────────────────────────

	/**
	 * Shortcode names registered by `register_elements()`. Single source
	 * of truth so the whitelist filter and the require list stay in sync.
	 */
	private const SHORTCODES = [
		'fusion_appress_back_button',
		'fusion_appress_status_bar_height',
		'fusion_appress_dismiss_first_launch',
		'fusion_appress_biometric',
		'fusion_appress_apple_login',
		'fusion_appress_qr_login',
		'fusion_appress_qr_scanner',
		'fusion_appress_notifications',
	];

	public function register_elements() {
		require_once __DIR__ . '/elements/back-button.php';
		require_once __DIR__ . '/elements/status-bar-height.php';
		require_once __DIR__ . '/elements/dismiss-first-launch.php';
		require_once __DIR__ . '/elements/biometric.php';
		require_once __DIR__ . '/elements/apple-login.php';
		require_once __DIR__ . '/elements/qr-login.php';
		require_once __DIR__ . '/elements/qr-scanner.php';
		require_once __DIR__ . '/elements/notifications.php';
	}

	/**
	 * Append our shortcodes to Fusion's enabled-elements whitelist. The
	 * filter is called by `fusion-builder/inc/shortcodes.php` (line 19)
	 * BEFORE `fusion_builder_map()` decides whether to register a module,
	 * so values added here become visible to the live-editor element panel.
	 *
	 * Empty string = "no whitelist saved yet" (fresh install) — Fusion
	 * itself substitutes the full element list later in
	 * `fusion_builder_filter_available_elements()`. Don't convert it to
	 * an array here, otherwise we trip a different branch and end up with
	 * ONLY our shortcodes enabled (fusion-builder-row / column / etc.
	 * silently disappear from the panel). Just pass through.
	 */
	public function whitelist_elements( $enabled ) {
		if ( ! is_array( $enabled ) ) {
			return $enabled;
		}
		foreach ( self::SHORTCODES as $sc ) {
			if ( ! in_array( $sc, $enabled, true ) ) {
				$enabled[] = $sc;
			}
		}
		return $enabled;
	}

	// ── Builder iframe assets ────────────────────────────────────────────

	public function enqueue_builder_assets() {
		// Two builder contexts:
		//   - `?fb-edit` = parent live-editor page (sidebar + element panel).
		//   - `?builder=true` = preview iframe inside it (renders shortcodes).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$is_fb_edit = isset( $_GET['fb-edit'] );
		$is_preview = isset( $_GET['builder'] ) && 'true' === $_GET['builder'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $is_fb_edit && ! $is_preview ) {
			return;
		}

		// Brand glyph for the element panel — only the parent page renders the panel.
		if ( $is_fb_edit ) {
			wp_enqueue_style( 'appress:builder-icon.css' );
		}

		// Iframe: pre-load every element's CSS *and* JS at iframe-load
		// time. Drag-add goes through Fusion's AJAX render endpoint
		// which only returns the shortcode HTML — neither `wp_enqueue_*`
		// inside `render()` nor Fusion's dynamic-CSS pipeline reaches
		// that response. Without this safety net, every freshly-dropped
		// element would need a save+reload to gain styling and behavior.
		// Each element ALSO registers via `add_css_files()` (covers the
		// published frontend) and `wp_enqueue_script()` inside `render()`
		// (covers the same), so the only context we duplicate for is the
		// builder iframe.
		if ( $is_preview ) {
			$css_handles = [
				// Shared button base — covers every Appress button widget
				// (qr-login / qr-scanner / apple-login / back /
				// dismiss-first-launch) via `.appress-btn` + variant
				// classes. Single stylesheet, single source of truth.
				'appress:frontend-commons.css',
				'appress:status-bar-height-widget.css',
				'appress:biometric-widget.css',
				// QR Login modal + scanner-only visibility gate.
				'appress:qr-login-widget.css',
				'appress:notifications-feed.css',
			];
			foreach ( $css_handles as $h ) {
				wp_enqueue_style( $h );
			}
			$js_handles = [
				'appress:back-button-widget.js',
				'appress:biometric-widget.js',
				'appress:apple-login-widget.js',
				'appress:qr-login-widget.js',
				'appress:notifications-feed.js',
			];
			foreach ( $js_handles as $h ) {
				wp_enqueue_script( $h );
			}
		}
	}

}
