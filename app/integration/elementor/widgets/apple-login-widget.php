<?php
namespace Appress\Integration\Elementor\Widgets;

use Appress\Integration\Elementor\Widgets\Traits\Button_Controls_Trait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget: Sign in with Apple button.
 *
 * Controls (label, icon, button/icon/label style sections) come from
 * the shared `Button_Controls_Trait`. Render delegates to the
 * shortcode controller so all four surfaces (shortcode + Elementor +
 * Bricks + Avada) emit identical markup.
 *
 * Apple HIG note: the trait exposes full control over background,
 * border, padding, etc. Apple permits only black or white variants
 * (no brand colours, no gradients) and a 44pt min-height. Designers
 * who go outside those limits risk App Store review feedback — but
 * the controls are intentionally permissive so the widget stays
 * consistent with the rest of the Appress button line-up. The
 * shortcode's `style` att still defaults to `black` on first render.
 */
class Apple_Login_Widget extends \Elementor\Widget_Base {

	use Button_Controls_Trait;

	public function get_name()    { return 'appress-apple-login'; }
	public function get_title()   { return __( 'Sign in with Apple', 'appress' ); }
	public function get_icon()    { return 'appress-icon'; }
	public function get_categories() { return [ 'appress' ]; }
	public function get_keywords()   { return [ 'appress', 'apple', 'sign in', 'login', 'siwa', 'oauth' ]; }

	public function get_style_depends() {
		return [ 'appress:frontend-commons.css' ];
	}
	public function get_script_depends() {
		return [ \Appress\Controllers\Login\Apple_Shortcode_Controller::JS_HANDLE ];
	}

	protected function register_controls() {
		$this->register_button_content_section( [
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => __( 'Sign in with Apple', 'appress' ),
		] );
		$this->register_button_style_section( [
			'selector'  => '{{WRAPPER}} .appress-btn-apple-login',
			'has_label' => true,
			'has_icon'  => true,
		] );
	}

	protected function render() {
		$s = $this->get_settings_for_display();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \Appress\Controllers\Login\Apple_Shortcode_Controller::render( [
			'label'     => isset( $s['label'] ) ? (string) $s['label'] : '',
			'icon_html' => $this->render_selected_icon_html(),
			// Editor canvas: skip the iOS-only visibility gate so admins
			// styling the widget always see the button.
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
