<?php
namespace Appress\Integration\Elementor\Widgets;

use Appress\Integration\Elementor\Widgets\Traits\Button_Controls_Trait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget: Appress dismiss-first-launch button.
 *
 * Controls (label, icon, button/icon/label style sections) come from
 * the shared `Button_Controls_Trait`. Render delegates to the
 * shortcode controller for byte-identical markup across surfaces.
 *
 * Click is caught natively by the global
 * `.appress-dismiss-first-launch-screen` listener — no JS ships with
 * the widget. Default look is chromeless (see
 * `.appress-btn-dismiss-first-launch` in `frontend-commons.css`); builders
 * style up via the trait when they want a panel-style button.
 */
class Dismiss_First_Launch_Widget extends \Elementor\Widget_Base {

	use Button_Controls_Trait;

	public function get_name()    { return 'appress-dismiss-first-launch'; }
	public function get_title()   { return __( 'Dismiss first launch', 'appress' ); }
	public function get_icon()    { return 'appress-icon'; }
	public function get_categories() { return [ 'appress' ]; }
	public function get_keywords()   { return [ 'appress', 'first launch', 'dismiss', 'onboarding', 'get started' ]; }

	public function get_style_depends() {
		return [ 'appress:frontend-commons.css' ];
	}

	protected function register_controls() {
		$this->register_button_content_section( [
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => __( 'Get started', 'appress' ),
		] );
		$this->register_button_style_section( [
			'selector'  => '{{WRAPPER}} .appress-btn-dismiss-first-launch',
			'has_label' => true,
			'has_icon'  => true,
		] );
	}

	protected function render() {
		$s = $this->get_settings_for_display();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \Appress\Controllers\Components\Dismiss_First_Launch_Shortcode_Controller::render( [
			'label'     => isset( $s['label'] ) ? (string) $s['label'] : '',
			'icon_html' => $this->render_selected_icon_html(),
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
