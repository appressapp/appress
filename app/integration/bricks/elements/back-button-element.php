<?php
/**
 * Bricks element: Appress back button.
 *
 * Controls (label, icon, button/icon/label style sections) come from
 * the shared `Button_Controls_Trait`. Render delegates to the
 * shortcode controller for byte-identical markup across surfaces.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Bricks\Element' ) ) {
	return;
}

class Appress_Back_Button_Element extends \Bricks\Element {

	use \Appress\Integration\Bricks\Elements\Traits\Button_Controls_Trait;

	public $category = 'appress';
	public $name     = 'appress-back-button';
	public $icon     = 'appress-icon';

	public function get_label()    { return esc_html__( 'Back button', 'appress' ); }
	public function get_keywords() { return [ 'appress', 'back', 'history', 'subscreen', 'navigation' ]; }

	public function enqueue_scripts() {
		wp_enqueue_style( 'appress:frontend-commons.css' );
		wp_enqueue_script( \Appress\Controllers\Components\Back_Button_Shortcode_Controller::JS_HANDLE );
	}

	public function set_control_groups() {
		$this->register_button_control_groups();
	}

	public function set_controls() {
		$this->register_button_content_controls( [
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => esc_html__( 'Back', 'appress' ),
		] );
		$this->register_button_style_controls( [
			'selector'  => '.appress-btn-back',
			'has_label' => true,
			'has_icon'  => true,
		] );
	}

	public function render() {
		$s    = $this->settings;
		$html = \Appress\Controllers\Components\Back_Button_Shortcode_Controller::render( [
			'label'     => isset( $s['label'] ) ? (string) $s['label'] : '',
			'icon_html' => $this->render_selected_icon_html(),
		] );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->render_button_wrapper( $html );
	}
}
