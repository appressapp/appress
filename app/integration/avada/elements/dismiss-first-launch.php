<?php
/**
 * Fusion Builder element: Appress dismiss-first-launch button.
 *
 * Params come from the shared `Avada\Helpers\Button_Params` helper.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Fusion_Element' ) ) {
	return;
}

if ( ! class_exists( 'FusionSC_Appress_Dismiss_First_Launch' ) ) {

	class FusionSC_Appress_Dismiss_First_Launch extends Fusion_Element {

		public function __construct() {
			parent::__construct();
			add_shortcode( 'fusion_appress_dismiss_first_launch', [ $this, 'render' ] );
			add_action( 'wp_ajax_get_fusion_appress_dismiss_first_launch', [ $this, 'ajax_render' ] );
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
			$atts = shortcode_atts( self::get_element_defaults(), $args, 'fusion_appress_dismiss_first_launch' );
			wp_enqueue_style( 'appress:frontend-commons.css' );
			return \Appress\Controllers\Components\Dismiss_First_Launch_Shortcode_Controller::render( [
				'label'        => $atts['label'],
				'icon_html'    => \Appress\Integration\Avada\Helpers\Button_Params::render_icon_html( $atts ),
				'inline_style' => \Appress\Integration\Avada\Helpers\Button_Params::render_inline_style( $atts ),
			] );
		}

		public function ajax_render() {
			check_ajax_referer( 'fusion_load_nonce', 'fusion_load_nonce' );
			$args = isset( $_POST['model']['params'] ) ? wp_unslash( $_POST['model']['params'] ) : []; // phpcs:ignore WordPress.Security
			$atts = shortcode_atts( self::get_element_defaults(), is_array( $args ) ? $args : [], 'fusion_appress_dismiss_first_launch' );
			echo wp_json_encode( [
				'html' => \Appress\Controllers\Components\Dismiss_First_Launch_Shortcode_Controller::render( [
					'label'        => $atts['label'],
					'icon_html'    => \Appress\Integration\Avada\Helpers\Button_Params::render_icon_html( $atts ),
					'inline_style' => \Appress\Integration\Avada\Helpers\Button_Params::render_inline_style( $atts ),
				] ),
			] );
			wp_die();
		}

		public function add_css_files() {
			FusionBuilder()->add_element_css( APPRESS_PLUGIN_DIR . 'assets/css/frontend-commons.css' );
		}
	}

	new FusionSC_Appress_Dismiss_First_Launch();
}

if ( ! function_exists( 'fusion_element_appress_dismiss_first_launch' ) ) {
	function fusion_element_appress_dismiss_first_launch() {
		fusion_builder_map(
			[
				'name'      => esc_attr__( 'Appress Dismiss First Launch', 'appress' ),
				'shortcode' => 'fusion_appress_dismiss_first_launch',
				'icon'      => 'fusiona-check',
				'front-end' => __DIR__ . '/front-end/dismiss-first-launch.php',
				'callback'  => [
					'function' => 'fusion_ajax',
					'action'   => 'get_fusion_appress_dismiss_first_launch',
					'ajax'     => true,
				],
				'params'    => array_merge(
					\Appress\Integration\Avada\Helpers\Button_Params::content_params( [
						'default_label' => esc_attr__( 'Get started', 'appress' ),
					] ),
					\Appress\Integration\Avada\Helpers\Button_Params::style_params()
				),
			]
		);
	}
	add_action( 'fusion_builder_before_init', 'fusion_element_appress_dismiss_first_launch' );
}
