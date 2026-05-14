<?php
namespace Appress\Integration\Elementor\Widgets;

use Appress\Integration\Elementor\Widgets\Traits\Button_Controls_Trait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget: Sign in with QR Code button.
 *
 * Controls (label, icon, button/icon/label style sections) come from
 * the shared `Button_Controls_Trait` so every Appress button widget
 * exposes the same UI surface. Render still delegates to the shortcode
 * controller for byte-identical markup across shortcode + Elementor +
 * Bricks + Avada surfaces.
 */
class Qr_Login_Widget extends \Elementor\Widget_Base {

	use Button_Controls_Trait;

	public function get_name()    { return 'appress-qr-login'; }
	public function get_title()   { return __( 'Sign in with QR Code', 'appress' ); }
	public function get_icon()    { return 'appress-icon'; }
	public function get_categories() { return [ 'appress' ]; }
	public function get_keywords()   { return [ 'appress', 'qr', 'sign in', 'login' ]; }

	public function get_style_depends() {
		return [ \Appress\Controllers\Login\Qr_Login_Shortcode_Controller::CSS_HANDLE ];
	}
	public function get_script_depends() {
		return [ \Appress\Controllers\Login\Qr_Login_Shortcode_Controller::JS_HANDLE ];
	}

	protected function register_controls() {
		$this->register_button_content_section( [
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => __( 'Sign in with QR Code', 'appress' ),
		] );
		$this->register_button_style_section( [
			// Trait writes vars at the variant scope so this widget's
			// style settings can't bleed into other Appress buttons that
			// happen to share the page (e.g. an `.appress-btn-apple-login`
			// rendered above it).
			'selector'  => '{{WRAPPER}} .appress-btn-qr-login',
			'has_label' => true,
			'has_icon'  => true,
		] );
	}

	protected function render() {
		$s = $this->get_settings_for_display();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \Appress\Controllers\Login\Qr_Login_Shortcode_Controller::render( [
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
