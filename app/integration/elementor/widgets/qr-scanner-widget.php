<?php
namespace Appress\Integration\Elementor\Widgets;

use Appress\Integration\Elementor\Widgets\Traits\Button_Controls_Trait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget: Scan QR Code button.
 *
 * Same shared `Button_Controls_Trait` as `Qr_Login_Widget` — same
 * controls, same look semantics, just a different default label /
 * default icon and a different variant CSS class so users can style
 * the two buttons independently when both sit on a page.
 *
 * Visibility gate (browser hides, in-app reveals) is owned by
 * `qr-login-widget.css` via the `data-appress-scanner-only` attribute
 * rendered by the shortcode controller — not the trait's concern.
 */
class Qr_Scanner_Widget extends \Elementor\Widget_Base {

	use Button_Controls_Trait;

	public function get_name()    { return 'appress-qr-scanner'; }
	public function get_title()   { return __( 'Scan QR Code', 'appress' ); }
	public function get_icon()    { return 'appress-icon'; }
	public function get_categories() { return [ 'appress' ]; }
	public function get_keywords()   { return [ 'appress', 'qr', 'scan', 'scanner', 'camera' ]; }

	public function get_style_depends() {
		return [ \Appress\Controllers\Components\Qr_Scanner_Shortcode_Controller::CSS_HANDLE ];
	}
	public function get_script_depends() {
		return [ \Appress\Controllers\Components\Qr_Scanner_Shortcode_Controller::JS_HANDLE ];
	}

	protected function register_controls() {
		$this->register_button_content_section( [
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => __( 'Scan QR Code', 'appress' ),
		] );
		$this->register_button_style_section( [
			'selector'  => '{{WRAPPER}} .appress-btn-qr-scanner',
			'has_label' => true,
			'has_icon'  => true,
		] );
	}

	protected function render() {
		$s = $this->get_settings_for_display();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \Appress\Controllers\Components\Qr_Scanner_Shortcode_Controller::render( [
			'label'     => isset( $s['label'] ) ? (string) $s['label'] : '',
			'icon_html' => $this->render_selected_icon_html(),
			'demo'      => $this->is_elementor_editor() ? 'yes' : 'no',
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function is_elementor_editor() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) return false;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['elementor-preview'] ) ) return true;
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'elementor' ) return true;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$plugin = \Elementor\Plugin::$instance;
		if ( isset( $plugin->editor ) && method_exists( $plugin->editor, 'is_edit_mode' ) && $plugin->editor->is_edit_mode() ) return true;
		if ( isset( $plugin->preview ) && method_exists( $plugin->preview, 'is_preview_mode' ) && $plugin->preview->is_preview_mode() ) return true;
		return false;
	}
}
