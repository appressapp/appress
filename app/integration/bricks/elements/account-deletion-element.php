<?php
/**
 * Bricks element: Appress account-deletion button.
 *
 * Surfaces a single button (Delete my account). Trait sections
 * (Content + Style) reuse the shared `Button_Controls_Trait` with the
 * `button_` prefix. Wrapper chrome (title, description) lives in a
 * separate "Wrapper" group above the button groups.
 *
 * Render delegates to the shortcode controller for byte-identical
 * markup across surfaces.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Bricks\Element' ) ) {
	return;
}

class Appress_Account_Deletion_Element extends \Bricks\Element {

	use \Appress\Integration\Bricks\Elements\Traits\Button_Controls_Trait;

	public $category = 'appress';
	public $name     = 'appress-account-deletion';
	public $icon     = 'appress-icon';

	public function get_label()    { return esc_html__( 'Account deletion', 'appress' ); }
	public function get_keywords() { return [ 'appress', 'delete', 'account', 'erase', 'gdpr', 'apple', 'guideline' ]; }

	public function enqueue_scripts() {
		wp_enqueue_style( 'appress:frontend-commons.css' );
		wp_enqueue_style( \Appress\Controllers\Account_Deletion\Shortcode_Controller::CSS_HANDLE );
		wp_enqueue_script( \Appress\Controllers\Account_Deletion\Shortcode_Controller::JS_HANDLE );
	}

	public function set_control_groups() {
		// General — wrapper text + editor-only preview-state.
		$this->control_groups['general'] = [
			'title' => esc_html__( 'General', 'appress' ),
			'tab'   => 'content',
		];
		// Button content (label + icon).
		$this->control_groups['button_content'] = [
			'title' => esc_html__( 'Button', 'appress' ),
			'tab'   => 'content',
		];
		// Wrapper style (gap + title + description typography).
		$this->control_groups['wrapper'] = [
			'title' => esc_html__( 'Wrapper', 'appress' ),
			'tab'   => 'style',
		];
		// Button style.
		$this->control_groups['button_style'] = [
			'title' => esc_html__( 'Button', 'appress' ),
			'tab'   => 'style',
		];
	}

	public function set_controls() {
		// ── General ──
		$this->controls['title'] = [
			'tab'         => 'content',
			'group'       => 'general',
			'label'       => esc_html__( 'Title', 'appress' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'Delete your account', 'appress' ),
		];
		$this->controls['description'] = [
			'tab'         => 'content',
			'group'       => 'general',
			'label'       => esc_html__( 'Description', 'appress' ),
			'type'        => 'textarea',
			'placeholder' => esc_html__( 'This action is permanent. Your data will be removed within 30 minutes of confirming the email link.', 'appress' ),
		];
		$this->controls['preview_state'] = [
			'tab'         => 'content',
			'group'       => 'general',
			'label'       => esc_html__( 'Preview', 'appress' ),
			'type'        => 'select',
			'default'     => 'logged_in',
			'options'     => [
				'logged_in'  => esc_html__( 'Logged in', 'appress' ),
				'logged_out' => esc_html__( 'Logged out', 'appress' ),
			],
			'description' => esc_html__( 'Editor-only — frontend renders the real auth state.', 'appress' ),
		];

		// ── Button content ──
		$this->register_button_content_controls( [
			'prefix'        => 'button_',
			'group'         => 'button_content',
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => esc_html__( 'Delete my account', 'appress' ),
		] );

		// ── Wrapper style ──
		$this->controls['gap'] = [
			'tab'   => 'style',
			'group' => 'wrapper',
			'label' => esc_html__( 'Internal gap', 'appress' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [ [ 'selector' => '.appress-account-deletion', 'property' => '--appress-acd-gap' ] ],
		];
		$this->controls['title_sep'] = [
			'tab'   => 'style',
			'group' => 'wrapper',
			'type'  => 'separator',
			'label' => esc_html__( 'Title', 'appress' ),
		];
		$this->controls['color_title'] = [
			'tab'   => 'style',
			'group' => 'wrapper',
			'label' => esc_html__( 'Color', 'appress' ),
			'type'  => 'color',
			'css'   => [ [ 'selector' => '.appress-account-deletion__title', 'property' => 'color' ] ],
		];
		$this->controls['typography_title'] = [
			'tab'   => 'style',
			'group' => 'wrapper',
			'label' => esc_html__( 'Typography', 'appress' ),
			'type'  => 'typography',
			'css'   => [ [ 'selector' => '.appress-account-deletion__title' ] ],
		];
		$this->controls['description_sep'] = [
			'tab'   => 'style',
			'group' => 'wrapper',
			'type'  => 'separator',
			'label' => esc_html__( 'Description', 'appress' ),
		];
		$this->controls['color_description'] = [
			'tab'   => 'style',
			'group' => 'wrapper',
			'label' => esc_html__( 'Color', 'appress' ),
			'type'  => 'color',
			'css'   => [ [ 'selector' => '.appress-account-deletion__description', 'property' => 'color' ] ],
		];
		$this->controls['typography_description'] = [
			'tab'   => 'style',
			'group' => 'wrapper',
			'label' => esc_html__( 'Typography', 'appress' ),
			'type'  => 'typography',
			'css'   => [ [ 'selector' => '.appress-account-deletion__description' ] ],
		];

		// ── Button style ──
		$this->register_button_style_controls( [
			'prefix'    => 'button_',
			'group'     => 'button_style',
			'selector'  => '.appress-btn-account-deletion',
			'has_label' => true,
			'has_icon'  => true,
		] );
	}

	public function render() {
		$s         = $this->settings;
		$is_editor = $this->is_bricks_builder_context();
		$html      = \Appress\Controllers\Account_Deletion\Shortcode_Controller::render( [
			'title'            => isset( $s['title'] )        ? (string) $s['title']        : '',
			'description'      => isset( $s['description'] )  ? (string) $s['description']  : '',
			'button_text'      => isset( $s['button_label'] ) ? (string) $s['button_label'] : '',
			'button_icon_html' => $this->render_selected_icon_html( 'button_' ),
			'demo'             => $is_editor ? 'yes' : 'no',
			'preview_state'    => $is_editor ? ( isset( $s['preview_state'] ) ? (string) $s['preview_state'] : 'logged_in' ) : '',
		] );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->render_button_wrapper( $html );
	}

	/**
	 * True when rendering inside the Bricks builder — see biometric
	 * element for the full version-probing rationale.
	 */
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
