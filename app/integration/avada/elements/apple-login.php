<?php
/**
 * Fusion Builder element: Appress "Sign in with Apple" button.
 *
 * Params come from the shared `Avada\Helpers\Button_Params` helper —
 * matching the surface every other Appress button element exposes.
 * Apple HIG defaults still ship via the shared CSS + variant class;
 * admins overriding to brand colours or sub-44pt heights risk App
 * Store review feedback.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Fusion_Element' ) ) {
	return;
}

if ( ! class_exists( 'FusionSC_Appress_Apple_Login' ) ) {

	class FusionSC_Appress_Apple_Login extends Fusion_Element {

		public function __construct() {
			parent::__construct();
			add_shortcode( 'fusion_appress_apple_login', [ $this, 'render' ] );
			add_action( 'wp_ajax_get_fusion_appress_apple_login', [ $this, 'ajax_render' ] );
		}

		public static function get_element_defaults() {
			return [
				'label'           => '',
				'selected_icon'   => '',
				'btn_align'       => '',
				'btn_width'       => '',
				'btn_min_height'  => '',
				'btn_padding'     => [],
				'btn_radius'      => [],
				'btn_border_width' => '',
				'btn_border_color' => '',
				'btn_bg'          => '',
				'btn_icon_gap'    => '',
				'btn_icon_size'   => '',
				'btn_icon_color'  => '',
				'btn_label_color' => '',
			];
		}

		public function render( $args, $content = '' ) {
			$atts = shortcode_atts( self::get_element_defaults(), $args, 'fusion_appress_apple_login' );
			wp_enqueue_style( 'appress:frontend-commons.css' );
			wp_enqueue_script( \Appress\Controllers\Login\Apple_Shortcode_Controller::JS_HANDLE );
			return \Appress\Controllers\Login\Apple_Shortcode_Controller::render( [
				'label'        => $atts['label'],
				'icon_html'    => \Appress\Integration\Avada\Helpers\Button_Params::render_icon_html( $atts ),
				'inline_style' => \Appress\Integration\Avada\Helpers\Button_Params::render_inline_style( $atts ),
				'demo'         => self::is_builder_context() ? 'yes' : 'no',
			] );
		}

		public function ajax_render() {
			check_ajax_referer( 'fusion_load_nonce', 'fusion_load_nonce' );
			$args = isset( $_POST['model']['params'] ) ? wp_unslash( $_POST['model']['params'] ) : []; // phpcs:ignore WordPress.Security
			$atts = shortcode_atts( self::get_element_defaults(), is_array( $args ) ? $args : [], 'fusion_appress_apple_login' );
			echo wp_json_encode( [
				'html' => \Appress\Controllers\Login\Apple_Shortcode_Controller::render( [
					'label'        => $atts['label'],
					'icon_html'    => \Appress\Integration\Avada\Helpers\Button_Params::render_icon_html( $atts ),
					'inline_style' => \Appress\Integration\Avada\Helpers\Button_Params::render_inline_style( $atts ),
					'demo'         => 'yes',
				] ),
			] );
			wp_die();
		}

		public function add_css_files() {
			FusionBuilder()->add_element_css( APPRESS_PLUGIN_DIR . 'assets/css/frontend-commons.css' );
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

	new FusionSC_Appress_Apple_Login();
}

if ( ! function_exists( 'fusion_element_appress_apple_login' ) ) {
	function fusion_element_appress_apple_login() {
		fusion_builder_map(
			[
				'name'      => esc_attr__( 'Appress Apple Login', 'appress' ),
				'shortcode' => 'fusion_appress_apple_login',
				'icon'      => 'fusiona-user',
				'front-end' => __DIR__ . '/front-end/apple-login.php',
				'callback'  => [
					'function' => 'fusion_ajax',
					'action'   => 'get_fusion_appress_apple_login',
					'ajax'     => true,
				],
				'params'    => array_merge(
					\Appress\Integration\Avada\Helpers\Button_Params::content_params( [
						'default_label' => esc_attr__( 'Sign in with Apple', 'appress' ),
					] ),
					\Appress\Integration\Avada\Helpers\Button_Params::style_params()
				),
			]
		);
	}
	add_action( 'fusion_builder_before_init', 'fusion_element_appress_apple_login' );
}
