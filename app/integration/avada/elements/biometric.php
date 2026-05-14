<?php
/**
 * Fusion Builder element: Appress biometric panel / sign-in button.
 *
 * The widget surfaces three buttons (Login, Enable/Disable, Clear all);
 * each gets its own pair of helper-supplied param sets (Content +
 * Style) with a different `prefix`. Frontend renders the real
 * `is_user_logged_in()` state; builder canvas honours `preview_state`
 * so admins can design both surfaces without flipping their auth.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Fusion_Element' ) ) {
	return;
}

if ( ! class_exists( 'FusionSC_Appress_Biometric' ) ) {

	class FusionSC_Appress_Biometric extends Fusion_Element {

		public function __construct() {
			parent::__construct();
			add_shortcode( 'fusion_appress_biometric', [ $this, 'render' ] );
			add_action( 'wp_ajax_get_fusion_appress_biometric', [ $this, 'ajax_render' ] );
		}

		public static function get_element_defaults() {
			$defaults = [
				'preview_state' => 'logged_in',
			];
			// Fold in 3× per-button defaults (login_/toggle_/clear_).
			foreach ( [ 'login_', 'toggle_', 'clear_' ] as $p ) {
				$defaults[ $p . 'label' ]            = '';
				$defaults[ $p . 'selected_icon' ]    = '';
				$defaults[ $p . 'btn_align' ]        = '';
				$defaults[ $p . 'btn_width' ]        = '';
				$defaults[ $p . 'btn_min_height' ]   = '';
				$defaults[ $p . 'btn_padding' ]      = [];
				$defaults[ $p . 'btn_radius' ]       = [];
				$defaults[ $p . 'btn_border_width' ] = '';
				$defaults[ $p . 'btn_border_color' ] = '';
				$defaults[ $p . 'btn_bg' ]           = '';
				$defaults[ $p . 'btn_icon_gap' ]     = '';
				$defaults[ $p . 'btn_icon_size' ]    = '';
				$defaults[ $p . 'btn_icon_color' ]   = '';
				$defaults[ $p . 'btn_label_color' ]  = '';
			}
			return $defaults;
		}

		public function render( $args, $content = '' ) {
			$atts      = shortcode_atts( self::get_element_defaults(), $args, 'fusion_appress_biometric' );
			$is_editor = self::is_builder_context();
			wp_enqueue_style( 'appress:frontend-commons.css' );
			wp_enqueue_style( \Appress\Controllers\Biometric\Shortcode_Controller::CSS_HANDLE );
			wp_enqueue_script( \Appress\Controllers\Biometric\Shortcode_Controller::JS_HANDLE );
			return \Appress\Controllers\Biometric\Shortcode_Controller::render( [
				'login_label'         => $atts['login_label'],
				'login_icon_html'     => \Appress\Integration\Avada\Helpers\Button_Params::render_icon_html( $atts, 'login_' ),
				'login_inline_style'  => \Appress\Integration\Avada\Helpers\Button_Params::render_inline_style( $atts, 'login_' ),
				'toggle_label'        => $atts['toggle_label'],
				'toggle_icon_html'    => \Appress\Integration\Avada\Helpers\Button_Params::render_icon_html( $atts, 'toggle_' ),
				'toggle_inline_style' => \Appress\Integration\Avada\Helpers\Button_Params::render_inline_style( $atts, 'toggle_' ),
				'clear_label'         => $atts['clear_label'],
				'clear_icon_html'     => \Appress\Integration\Avada\Helpers\Button_Params::render_icon_html( $atts, 'clear_' ),
				'clear_inline_style'  => \Appress\Integration\Avada\Helpers\Button_Params::render_inline_style( $atts, 'clear_' ),
				'demo'                => $is_editor ? 'yes' : 'no',
				'preview_state'       => $is_editor ? (string) $atts['preview_state'] : '',
			] );
		}

		public function ajax_render() {
			check_ajax_referer( 'fusion_load_nonce', 'fusion_load_nonce' );
			$args = isset( $_POST['model']['params'] ) ? wp_unslash( $_POST['model']['params'] ) : []; // phpcs:ignore WordPress.Security
			$atts = shortcode_atts( self::get_element_defaults(), is_array( $args ) ? $args : [], 'fusion_appress_biometric' );
			echo wp_json_encode( [
				'html' => \Appress\Controllers\Biometric\Shortcode_Controller::render( [
					'login_label'         => $atts['login_label'],
					'login_icon_html'     => \Appress\Integration\Avada\Helpers\Button_Params::render_icon_html( $atts, 'login_' ),
					'login_inline_style'  => \Appress\Integration\Avada\Helpers\Button_Params::render_inline_style( $atts, 'login_' ),
					'toggle_label'        => $atts['toggle_label'],
					'toggle_icon_html'    => \Appress\Integration\Avada\Helpers\Button_Params::render_icon_html( $atts, 'toggle_' ),
					'toggle_inline_style' => \Appress\Integration\Avada\Helpers\Button_Params::render_inline_style( $atts, 'toggle_' ),
					'clear_label'         => $atts['clear_label'],
					'clear_icon_html'     => \Appress\Integration\Avada\Helpers\Button_Params::render_icon_html( $atts, 'clear_' ),
					'clear_inline_style'  => \Appress\Integration\Avada\Helpers\Button_Params::render_inline_style( $atts, 'clear_' ),
					'demo'                => 'yes',
					'preview_state'       => (string) $atts['preview_state'],
				] ),
			] );
			wp_die();
		}

		public function add_css_files() {
			FusionBuilder()->add_element_css( APPRESS_PLUGIN_DIR . 'assets/css/frontend-commons.css' );
			FusionBuilder()->add_element_css( APPRESS_PLUGIN_DIR . 'assets/css/biometric-widget.css' );
		}

		private static function is_builder_context() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['fb-edit'] ) || isset( $_GET['builder'] ) ) {
				return true;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			return function_exists( 'fusion_is_builder_frame' ) && fusion_is_builder_frame();
		}
	}

	new FusionSC_Appress_Biometric();
}

if ( ! function_exists( 'fusion_element_appress_biometric' ) ) {
	function fusion_element_appress_biometric() {
		// 1× General (preview state) + 3× per-button content + 3× per-button style.
		$general = [
			[
				'type'        => 'select',
				'heading'     => esc_attr__( 'Preview', 'appress' ),
				'param_name'  => 'preview_state',
				'value'       => [
					'logged_in'  => esc_attr__( 'Logged in', 'appress' ),
					'logged_out' => esc_attr__( 'Logged out', 'appress' ),
				],
				'default'     => 'logged_in',
				'group'       => esc_attr__( 'General', 'appress' ),
				'description' => esc_attr__( 'Editor-only — frontend renders the real auth state.', 'appress' ),
			],
		];

		$buttons = [
			'login'  => [ 'group' => esc_attr__( 'Login button', 'appress' ),            'default' => esc_attr__( 'Sign in with Face ID / Touch ID', 'appress' ) ],
			'toggle' => [ 'group' => esc_attr__( 'Enable / Disable button', 'appress' ), 'default' => esc_attr__( 'Enable', 'appress' ) ],
			'clear'  => [ 'group' => esc_attr__( 'Clear all button', 'appress' ),        'default' => esc_attr__( 'Clear all devices', 'appress' ) ],
		];

		$btn_params = [];
		foreach ( $buttons as $slug => $info ) {
			$btn_params = array_merge(
				$btn_params,
				\Appress\Integration\Avada\Helpers\Button_Params::content_params( [
					'prefix'        => $slug . '_',
					'group'         => $info['group'],
					'has_label'     => true,
					'has_icon'      => true,
					'default_label' => $info['default'],
				] ),
				\Appress\Integration\Avada\Helpers\Button_Params::style_params( [
					'prefix'    => $slug . '_',
					'group'     => $info['group'],
					'has_label' => true,
					'has_icon'  => true,
				] )
			);
		}

		fusion_builder_map(
			[
				'name'      => esc_attr__( 'Appress Biometric', 'appress' ),
				'shortcode' => 'fusion_appress_biometric',
				'icon'      => 'fusiona-key',
				'front-end' => __DIR__ . '/front-end/biometric.php',
				'callback'  => [
					'function' => 'fusion_ajax',
					'action'   => 'get_fusion_appress_biometric',
					'ajax'     => true,
				],
				'params'    => array_merge( $general, $btn_params ),
			]
		);
	}
	add_action( 'fusion_builder_before_init', 'fusion_element_appress_biometric' );
}
