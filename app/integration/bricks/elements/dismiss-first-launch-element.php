<?php
/**
 * Bricks element: Appress dismiss-first-launch button.
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

class Appress_Dismiss_First_Launch_Element extends \Bricks\Element {

	use \Appress\Integration\Bricks\Elements\Traits\Button_Controls_Trait;

	public $category = 'appress';
	public $name     = 'appress-dismiss-first-launch';
	public $icon     = 'appress-icon';

	public function get_label()    { return esc_html__( 'Dismiss first launch', 'appress' ); }
	public function get_keywords() { return [ 'appress', 'first launch', 'dismiss', 'onboarding', 'get started' ]; }

	public function enqueue_scripts() {
		wp_enqueue_style( 'appress:frontend-commons.css' );
	}

	public function set_control_groups() {
		$this->register_button_control_groups();
	}

	public function set_controls() {
		$this->register_button_content_controls( [
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => esc_html__( 'Get started', 'appress' ),
		] );
		$this->register_button_style_controls( [
			'selector'  => '.appress-btn-dismiss-first-launch',
			'has_label' => true,
			'has_icon'  => true,
		] );
	}

	public function render() {
		$s    = $this->settings;
		$html = \Appress\Controllers\Components\Dismiss_First_Launch_Shortcode_Controller::render( [
			'label'     => isset( $s['label'] ) ? (string) $s['label'] : '',
			'icon_html' => $this->render_selected_icon_html(),
		] );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->render_button_wrapper( $html );
	}
}
