<?php
/**
 * Fusion Builder element: Appress status-bar-height spacer.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Fusion_Element' ) ) {
	return;
}

if ( ! class_exists( 'FusionSC_Appress_Status_Bar_Height' ) ) {

	class FusionSC_Appress_Status_Bar_Height extends Fusion_Element {

		public function __construct() {
			parent::__construct();
			add_shortcode( 'fusion_appress_status_bar_height', [ $this, 'render' ] );
			add_action( 'wp_ajax_get_fusion_appress_status_bar_height', [ $this, 'ajax_render' ] );
		}

		public static function get_element_defaults() {
			return [];
		}

		public function render( $args, $content = '' ) {
			return \Appress\Controllers\Components\Status_Bar_Height_Shortcode_Controller::render( [] );
		}

		public function ajax_render() {
			check_ajax_referer( 'fusion_load_nonce', 'fusion_load_nonce' );
			echo wp_json_encode( [
				'html' => \Appress\Controllers\Components\Status_Bar_Height_Shortcode_Controller::render( [] ),
			] );
			wp_die();
		}

		public function add_css_files() {
			FusionBuilder()->add_element_css( APPRESS_PLUGIN_DIR . 'assets/css/status-bar-height-widget.css' );
		}
	}

	new FusionSC_Appress_Status_Bar_Height();
}

if ( ! function_exists( 'fusion_element_appress_status_bar_height' ) ) {
	function fusion_element_appress_status_bar_height() {
		fusion_builder_map(
			[
				'name'      => esc_attr__( 'Appress Status Bar Height', 'appress' ),
				'shortcode' => 'fusion_appress_status_bar_height',
				'icon'      => 'fusiona-mobile',
				'front-end' => __DIR__ . '/front-end/status-bar-height.php',
				'callback'  => [
					'function' => 'fusion_ajax',
					'action'   => 'get_fusion_appress_status_bar_height',
					'ajax'     => true,
				],
				'params'    => [],
			]
		);
	}
	add_action( 'fusion_builder_before_init', 'fusion_element_appress_status_bar_height' );
}
