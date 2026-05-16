<?php
/**
 * Bricks element: Appress app-menu toggle button.
 *
 * Controls come from the shared `Button_Controls_Trait`. Render delegates
 * to the shortcode controller for byte-identical markup across surfaces.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Bricks\Element' ) ) {
	return;
}

class Appress_Menu_Toggle_Element extends \Bricks\Element {

	use \Appress\Integration\Bricks\Elements\Traits\Button_Controls_Trait;

	public $category = 'appress';
	public $name     = 'appress-menu-toggle';
	public $icon     = 'appress-icon';

	public function get_label()    { return esc_html__( 'Menu toggle', 'appress' ); }
	public function get_keywords() { return [ 'appress', 'menu', 'hamburger', 'drawer', 'navigation' ]; }

	public function enqueue_scripts() {
		wp_enqueue_style( 'appress:frontend-commons.css' );
		wp_enqueue_script( \Appress\Controllers\Components\Menu_Toggle_Shortcode_Controller::JS_HANDLE );
	}

	public function set_control_groups() {
		$this->register_button_control_groups();
	}

	public function set_controls() {
		// Target select — which drawer to toggle. Default 'left' so
		// pre-2026-05-15 instances keep working unchanged.
		$this->controls['menu_target'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Target', 'appress' ),
			'type'        => 'select',
			'options'     => [
				'left'  => esc_html__( 'Left Side Menu',  'appress' ),
				'right' => esc_html__( 'Right Side Menu', 'appress' ),
			],
			'default'     => 'left',
		];
		$this->register_button_content_controls( [
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => esc_html__( 'Menu', 'appress' ),
		] );
		$this->register_button_style_controls( [
			'selector'  => '.appress-btn-menu-toggle',
			'has_label' => true,
			'has_icon'  => true,
		] );
	}

	public function render() {
		$s    = $this->settings;
		$html = \Appress\Controllers\Components\Menu_Toggle_Shortcode_Controller::render( [
			'target'    => isset( $s['menu_target'] ) ? (string) $s['menu_target'] : 'left',
			'label'     => isset( $s['label'] ) ? (string) $s['label'] : '',
			'icon_html' => $this->render_selected_icon_html(),
		] );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->render_button_wrapper( $html );
	}
}
