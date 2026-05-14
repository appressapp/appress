<?php
/**
 * Bricks element: Appress status-bar-height spacer.
 *
 * Registered via `\Bricks\Elements::register_element(__FILE__)` from
 * Bricks_Controller. Render delegates to
 * `Status_Bar_Height_Shortcode_Controller::render()` so shortcode +
 * Elementor widget + Bricks element produce identical markup.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Bricks\Element' ) ) {
	return;
}

class Appress_Status_Bar_Height_Element extends \Bricks\Element {

	public $category = 'appress';
	public $name     = 'appress-status-bar-height';
	public $icon     = 'appress-icon';

	public function get_label() {
		return esc_html__( 'Status bar height', 'appress' );
	}

	public function get_keywords() {
		return [ 'appress', 'status bar', 'safe area', 'notch', 'spacer' ];
	}

	public function enqueue_scripts() {
		wp_enqueue_style( \Appress\Controllers\Components\Status_Bar_Height_Shortcode_Controller::CSS_HANDLE );
	}

	public function set_control_groups() {
		$this->control_groups['style'] = [
			'title' => esc_html__( 'Style', 'appress' ),
			'tab'   => 'style',
		];
	}

	public function set_controls() {
		$root = '.appress-status-bar-height';

		$this->controls['background'] = [
			'tab'   => 'style',
			'group' => 'style',
			'label' => esc_html__( 'Background', 'appress' ),
			'type'  => 'color',
			'css'   => [ [ 'selector' => $root, 'property' => 'background-color' ] ],
		];
	}

	public function render() {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \Appress\Controllers\Components\Status_Bar_Height_Shortcode_Controller::render( [] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
