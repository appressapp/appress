<?php
namespace Appress\Integration\Elementor\Widgets;

use Appress\Integration\Elementor\Widgets\Traits\Button_Controls_Trait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget: Appress app-menu toggle button.
 *
 * Controls come from the shared `Button_Controls_Trait`. Render delegates
 * to the shortcode controller for byte-identical markup across surfaces.
 *
 * Default look is chromeless — see `.appress-btn-menu-toggle` in
 * `frontend-commons.css`. The trait lets users style the button if they
 * want a panel-style menu button.
 */
class Menu_Toggle_Widget extends \Elementor\Widget_Base {

	use Button_Controls_Trait;

	public function get_name()    { return 'appress-menu-toggle'; }
	public function get_title()   { return __( 'Menu toggle', 'appress' ); }
	public function get_icon()    { return 'appress-icon'; }
	public function get_categories() { return [ 'appress' ]; }
	public function get_keywords()   { return [ 'appress', 'menu', 'hamburger', 'drawer', 'navigation' ]; }

	public function get_style_depends() {
		return [ 'appress:frontend-commons.css' ];
	}
	public function get_script_depends() {
		return [ \Appress\Controllers\Components\Menu_Toggle_Shortcode_Controller::JS_HANDLE ];
	}

	protected function register_controls() {
		$this->register_button_content_section( [
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => __( 'Menu', 'appress' ),
		] );
		$this->register_button_style_section( [
			'selector'  => '{{WRAPPER}} .appress-btn-menu-toggle',
			'has_label' => true,
			'has_icon'  => true,
		] );
	}

	protected function render() {
		$s = $this->get_settings_for_display();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \Appress\Controllers\Components\Menu_Toggle_Shortcode_Controller::render( [
			'label'     => isset( $s['label'] ) ? (string) $s['label'] : '',
			'icon_html' => $this->render_selected_icon_html(),
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
