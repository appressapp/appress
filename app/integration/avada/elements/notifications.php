<?php
/**
 * Fusion Builder element: Appress notifications feed.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Fusion_Element' ) ) {
	return;
}

if ( ! class_exists( 'FusionSC_Appress_Notifications' ) ) {

	class FusionSC_Appress_Notifications extends Fusion_Element {

		public function __construct() {
			parent::__construct();
			add_shortcode( 'fusion_appress_notifications', [ $this, 'render' ] );
			add_action( 'wp_ajax_get_fusion_appress_notifications', [ $this, 'ajax_render' ] );
		}

		public static function get_element_defaults() {
			return [
				'limit'      => 10,
				'empty'      => '',
				'mark_all'   => 'yes',
				'clear_all'  => 'yes',
				'max_height' => '',
			];
		}

		public function render( $args, $content = '' ) {
			$defaults = self::get_element_defaults();
			$atts     = shortcode_atts( $defaults, $args, 'fusion_appress_notifications' );
			// Builder iframe → render sample data so editors see a real-looking
			// feed instead of the live "No notifications yet" empty state.
			$atts['demo'] = self::is_builder_context() ? 'yes' : 'no';
			wp_enqueue_script( \Appress\Controllers\Notifications\Controller::JS_HANDLE );
			return \Appress\Controllers\Notifications\Controller::render( $atts );
		}

		public function ajax_render() {
			check_ajax_referer( 'fusion_load_nonce', 'fusion_load_nonce' );
			$args = isset( $_POST['model']['params'] ) ? wp_unslash( $_POST['model']['params'] ) : []; // phpcs:ignore WordPress.Security
			$atts = shortcode_atts( self::get_element_defaults(), is_array( $args ) ? $args : [], 'fusion_appress_notifications' );
			$atts['demo'] = 'yes'; // AJAX render only fires from the builder.
			// Skeleton + a synchronous mount kick. Notifications render an
			// EMPTY `<div>` and rely on `notifications-feed.js` to populate
			// it. On first drop, Fusion applies the response via diffdom
			// which doesn't reliably trigger MutationObserver mounts inside
			// the preview iframe — without this script tag the user sees a
			// blank gap until they save+reload. Append after the div so
			// `mount()` finds the freshly-inserted root.
			$html  = \Appress\Controllers\Notifications\Controller::render( $atts );
			$html .= '<script>(function(){var f=window.AppressNotificationsFeed;if(f&&typeof f.mount==="function"){f.mount();}})();</script>';
			echo wp_json_encode( [ 'html' => $html ] );
			wp_die();
		}

		public function add_css_files() {
			FusionBuilder()->add_element_css( APPRESS_PLUGIN_DIR . 'assets/css/notifications-feed.css' );
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

	new FusionSC_Appress_Notifications();
}

if ( ! function_exists( 'fusion_element_appress_notifications' ) ) {
	function fusion_element_appress_notifications() {
		fusion_builder_map(
			[
				'name'      => esc_attr__( 'Appress Notifications', 'appress' ),
				'shortcode' => 'fusion_appress_notifications',
				'icon'      => 'fusiona-envelope',
				'front-end' => __DIR__ . '/front-end/notifications.php',
				'callback'  => [
					'function' => 'fusion_ajax',
					'action'   => 'get_fusion_appress_notifications',
					'ajax'     => true,
				],
				'params'    => [
					[
						'type'        => 'textfield',
						'heading'     => esc_attr__( 'Limit', 'appress' ),
						'param_name'  => 'limit',
						'value'       => '10',
						'description' => esc_attr__( 'Max number of notifications to display.', 'appress' ),
					],
					[
						'type'        => 'textfield',
						'heading'     => esc_attr__( 'Empty state text', 'appress' ),
						'param_name'  => 'empty',
						'value'       => '',
						'description' => esc_attr__( 'Defaults to "No notifications yet.".', 'appress' ),
					],
					[
						'type'       => 'radio_button_set',
						'heading'    => esc_attr__( 'Show "Mark all" button', 'appress' ),
						'param_name' => 'mark_all',
						'default'    => 'yes',
						'value'      => [
							'yes' => esc_attr__( 'Yes', 'appress' ),
							'no'  => esc_attr__( 'No', 'appress' ),
						],
					],
					[
						'type'       => 'radio_button_set',
						'heading'    => esc_attr__( 'Show "Clear all" button', 'appress' ),
						'param_name' => 'clear_all',
						'default'    => 'yes',
						'value'      => [
							'yes' => esc_attr__( 'Yes', 'appress' ),
							'no'  => esc_attr__( 'No', 'appress' ),
						],
					],
					[
						'type'        => 'textfield',
						'heading'     => esc_attr__( 'Max height', 'appress' ),
						'param_name'  => 'max_height',
						'value'       => '',
						'description' => esc_attr__( 'CSS length, e.g. 480px. Leave blank for no scroll cap.', 'appress' ),
					],
				],
			]
		);
	}
	add_action( 'fusion_builder_before_init', 'fusion_element_appress_notifications' );
}
