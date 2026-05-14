<?php
/**
 * Bricks element: Sign in with Apple button.
 *
 * Controls (label, icon, button/icon/label style sections) come from
 * the shared `Button_Controls_Trait`. Render delegates to the
 * shortcode controller so all four surfaces (shortcode + Elementor +
 * Bricks + Avada) emit identical markup.
 *
 * Apple HIG note: the trait exposes full control over background,
 * border, padding, etc. Apple permits only black or white variants
 * (no brand colours, no gradients) and a 44pt min-height. Designers
 * who go outside those limits risk App Store review feedback —
 * controls are intentionally permissive so the widget stays
 * consistent with the rest of the Appress button line-up.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Bricks\Element' ) ) {
	return;
}

class Appress_Apple_Login_Element extends \Bricks\Element {

	use \Appress\Integration\Bricks\Elements\Traits\Button_Controls_Trait;

	public $category = 'appress';
	public $name     = 'appress-apple-login';
	public $icon     = 'appress-icon';

	public function get_label()    { return esc_html__( 'Sign in with Apple', 'appress' ); }
	public function get_keywords() { return [ 'appress', 'apple', 'sign in', 'login', 'siwa', 'oauth' ]; }

	public function enqueue_scripts() {
		wp_enqueue_style( 'appress:frontend-commons.css' );
		wp_enqueue_script( \Appress\Controllers\Login\Apple_Shortcode_Controller::JS_HANDLE );
	}

	public function set_control_groups() {
		$this->register_button_control_groups();
	}

	public function set_controls() {
		$this->register_button_content_controls( [
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => esc_html__( 'Sign in with Apple', 'appress' ),
		] );
		$this->register_button_style_controls( [
			'selector'  => '.appress-btn-apple-login',
			'has_label' => true,
			'has_icon'  => true,
		] );
	}

	public function render() {
		$s    = $this->settings;
		$demo = $this->is_bricks_builder_context() ? 'yes' : 'no';
		$html = \Appress\Controllers\Login\Apple_Shortcode_Controller::render( [
			'label'     => isset( $s['label'] ) ? (string) $s['label'] : '',
			'icon_html' => $this->render_selected_icon_html(),
			'demo'      => $demo,
		] );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->render_button_wrapper( $html );
	}

	private function is_bricks_builder_context() {
		if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() ) return true;
		if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) return true;
		if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) return true;
		if ( class_exists( '\Bricks\Api' ) ) {
			if ( method_exists( '\Bricks\Api', 'is_current_endpoint_render_element' ) && \Bricks\Api::is_current_endpoint_render_element() ) return true;
			if ( method_exists( '\Bricks\Api', 'is_current_endpoint' ) ) {
				try { if ( \Bricks\Api::is_current_endpoint( 'render_element' ) ) return true; } catch ( \Throwable $e ) {}
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && strpos( sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ), 'bricks_' ) === 0 ) return true;
		return false;
	}
}
