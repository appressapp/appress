<?php
/**
 * Bricks element: Appress Biometric panel / sign-in button.
 *
 * The widget surfaces three buttons (Login, Enable/Disable, Clear all);
 * each gets its own pair of trait sections (Content + Style) via the
 * shared `Button_Controls_Trait` with a different `prefix`. Panel-level
 * chrome (gap, header typography, status typography) lives in a single
 * "Panel" group.
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

class Appress_Biometric_Element extends \Bricks\Element {

	use \Appress\Integration\Bricks\Elements\Traits\Button_Controls_Trait;

	public $category = 'appress';
	public $name     = 'appress-biometric';
	public $icon     = 'appress-icon';

	public function get_label()    { return esc_html__( 'Biometric', 'appress' ); }
	public function get_keywords() { return [ 'appress', 'biometric', 'face id', 'touch id', 'fingerprint', 'login' ]; }

	public function enqueue_scripts() {
		wp_enqueue_style( 'appress:frontend-commons.css' );
		wp_enqueue_style( \Appress\Controllers\Biometric\Shortcode_Controller::CSS_HANDLE );
		wp_enqueue_script( \Appress\Controllers\Biometric\Shortcode_Controller::JS_HANDLE );
	}

	public function set_control_groups() {
		// Editor-only "General" — preview-state picker.
		$this->control_groups['general'] = [
			'title' => esc_html__( 'General', 'appress' ),
			'tab'   => 'content',
		];

		// One Content group per button (label + icon).
		$this->control_groups['login_btn_content'] = [
			'title' => esc_html__( 'Login button', 'appress' ),
			'tab'   => 'content',
		];
		$this->control_groups['toggle_btn_content'] = [
			'title' => esc_html__( 'Enable / Disable button', 'appress' ),
			'tab'   => 'content',
		];
		$this->control_groups['clear_btn_content'] = [
			'title' => esc_html__( 'Clear all button', 'appress' ),
			'tab'   => 'content',
		];

		// Panel-level style group (gap + title typo + status typo).
		$this->control_groups['panel'] = [
			'title' => esc_html__( 'Panel', 'appress' ),
			'tab'   => 'style',
		];

		// One Style group per button.
		$this->control_groups['login_btn_style'] = [
			'title' => esc_html__( 'Login button', 'appress' ),
			'tab'   => 'style',
		];
		$this->control_groups['toggle_btn_style'] = [
			'title' => esc_html__( 'Enable / Disable button', 'appress' ),
			'tab'   => 'style',
		];
		$this->control_groups['clear_btn_style'] = [
			'title' => esc_html__( 'Clear all button', 'appress' ),
			'tab'   => 'style',
		];
	}

	public function set_controls() {
		// ── General — editor-only preview-state picker ──
		// Picks which surface the editor canvas renders so admins can
		// design both states without flipping their own auth state.
		// Frontend renders the real `is_user_logged_in()` regardless.
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

		// ── Per-button Content sections (3) ──
		$this->register_button_content_controls( [
			'prefix'        => 'login_',
			'group'         => 'login_btn_content',
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => esc_html__( 'Sign in with Face ID / Touch ID', 'appress' ),
		] );
		$this->register_button_content_controls( [
			'prefix'        => 'toggle_',
			'group'         => 'toggle_btn_content',
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => esc_html__( 'Enable', 'appress' ),
		] );
		$this->register_button_content_controls( [
			'prefix'        => 'clear_',
			'group'         => 'clear_btn_content',
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => esc_html__( 'Clear all devices', 'appress' ),
		] );

		// ── Panel chrome ──
		$this->controls['gap'] = [
			'tab'   => 'style',
			'group' => 'panel',
			'label' => esc_html__( 'Internal gap', 'appress' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [ [ 'selector' => '.appress-biometric', 'property' => '--appress-bio-gap' ] ],
		];
		$this->controls['title_sep'] = [
			'tab'   => 'style',
			'group' => 'panel',
			'type'  => 'separator',
			'label' => esc_html__( 'Panel title', 'appress' ),
		];
		$this->controls['color_title'] = [
			'tab'   => 'style',
			'group' => 'panel',
			'label' => esc_html__( 'Color', 'appress' ),
			'type'  => 'color',
			'css'   => [ [ 'selector' => '.appress-biometric__title', 'property' => 'color' ] ],
		];
		$this->controls['typography_title'] = [
			'tab'   => 'style',
			'group' => 'panel',
			'label' => esc_html__( 'Typography', 'appress' ),
			'type'  => 'typography',
			'css'   => [ [ 'selector' => '.appress-biometric__title' ] ],
		];
		$this->controls['status_sep'] = [
			'tab'   => 'style',
			'group' => 'panel',
			'type'  => 'separator',
			'label' => esc_html__( 'Status line', 'appress' ),
		];
		$this->controls['color_status'] = [
			'tab'   => 'style',
			'group' => 'panel',
			'label' => esc_html__( 'Color', 'appress' ),
			'type'  => 'color',
			'css'   => [ [ 'selector' => '.appress-biometric__status', 'property' => 'color' ] ],
		];
		$this->controls['typography_status'] = [
			'tab'   => 'style',
			'group' => 'panel',
			'label' => esc_html__( 'Typography', 'appress' ),
			'type'  => 'typography',
			'css'   => [ [ 'selector' => '.appress-biometric__status' ] ],
		];

		// ── Per-button Style sections (3) ──
		$this->register_button_style_controls( [
			'prefix'    => 'login_',
			'group'     => 'login_btn_style',
			'selector'  => '.appress-btn-biometric-login',
			'has_label' => true,
			'has_icon'  => true,
		] );
		$this->register_button_style_controls( [
			'prefix'    => 'toggle_',
			'group'     => 'toggle_btn_style',
			'selector'  => '.appress-btn-biometric-primary',
			'has_label' => true,
			'has_icon'  => true,
		] );
		$this->register_button_style_controls( [
			'prefix'    => 'clear_',
			'group'     => 'clear_btn_style',
			'selector'  => '.appress-btn-biometric-danger',
			'has_label' => true,
			'has_icon'  => true,
		] );
	}

	public function render() {
		$s         = $this->settings;
		$is_editor = $this->is_bricks_builder_context();
		$html      = \Appress\Controllers\Biometric\Shortcode_Controller::render( [
			'login_label'      => isset( $s['login_label'] )  ? (string) $s['login_label']  : '',
			'login_icon_html'  => $this->render_selected_icon_html( 'login_' ),
			'toggle_label'     => isset( $s['toggle_label'] ) ? (string) $s['toggle_label'] : '',
			'toggle_icon_html' => $this->render_selected_icon_html( 'toggle_' ),
			'clear_label'      => isset( $s['clear_label'] )  ? (string) $s['clear_label']  : '',
			'clear_icon_html'  => $this->render_selected_icon_html( 'clear_' ),
			'demo'             => $is_editor ? 'yes' : 'no',
			'preview_state'    => $is_editor ? ( isset( $s['preview_state'] ) ? (string) $s['preview_state'] : 'logged_in' ) : '',
		] );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->render_button_wrapper( $html );
	}

	/**
	 * True when rendering inside the Bricks builder — outer builder
	 * window, canvas preview iframe, REST `render_element` endpoint,
	 * or legacy admin-ajax. Probes overlapping helpers across versions
	 * and returns on first match.
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
