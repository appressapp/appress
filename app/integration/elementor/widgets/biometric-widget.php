<?php
namespace Appress\Integration\Elementor\Widgets;

use Appress\Integration\Elementor\Widgets\Traits\Button_Controls_Trait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget: Appress biometric panel / sign-in button.
 *
 * The widget surfaces three buttons (Login, Enable/Disable, Clear all);
 * each gets its own pair of trait sections (Content + Style) via the
 * shared `Button_Controls_Trait` with a different `prefix`. Panel-level
 * chrome (gap, header typography, status typography) lives in a single
 * General section above the per-button sections.
 *
 * Per-button content rules:
 *   - Login   → has_label=false (the "Sign in with…" text is i18n via
 *                the shortcode), has_icon=true (admin can swap glyph).
 *   - Toggle  → no Content section at all — its label is JS-driven
 *                (Enable / Disable swap) so admin overrides would just
 *                get clobbered. Style section still exposes typography.
 *   - Clear   → same as Toggle.
 *
 * Render delegates to the shortcode controller for byte-identical
 * markup across surfaces.
 */
class Biometric_Widget extends \Elementor\Widget_Base {

	use Button_Controls_Trait;

	public function get_name()    { return 'appress-biometric'; }
	public function get_title()   { return __( 'Biometric', 'appress' ); }
	public function get_icon()    { return 'appress-icon'; }
	public function get_categories() { return [ 'appress' ]; }
	public function get_keywords()   { return [ 'appress', 'biometric', 'face id', 'touch id', 'fingerprint', 'login' ]; }

	public function get_style_depends() {
		return [
			'appress:frontend-commons.css',
			\Appress\Controllers\Biometric\Shortcode_Controller::CSS_HANDLE,
		];
	}

	public function get_script_depends() {
		return [ \Appress\Controllers\Biometric\Shortcode_Controller::JS_HANDLE ];
	}

	protected function register_controls() {
		// ── General — editor-only preview toggle ──
		// Picks which state the editor canvas renders (logged-in panel
		// vs logged-out login button) so admins can design both
		// surfaces without flipping their own auth state. Frontend
		// renders the real `is_user_logged_in()` state regardless of
		// what's selected here — the value only flows through when
		// `demo=yes` (Elementor editor + preview iframe).
		$this->start_controls_section( 'section_general', [
			'label' => __( 'General', 'appress' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		] );
		$this->add_control( 'preview_state', [
			'label'       => __( 'Preview', 'appress' ),
			'type'        => \Elementor\Controls_Manager::SELECT,
			'default'     => 'logged_in',
			'options'     => [
				'logged_in'  => __( 'Logged in', 'appress' ),
				'logged_out' => __( 'Logged out', 'appress' ),
			],
			'description' => __( 'Editor-only — frontend renders the real auth state.', 'appress' ),
		] );
		$this->end_controls_section();

		// ── Per-button Content sections (3) ── each has label + icon picker.
		$this->register_button_content_section( [
			'prefix'        => 'login_',
			'section_label' => __( 'Login button', 'appress' ),
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => __( 'Sign in with Face ID / Touch ID', 'appress' ),
		] );

		$this->register_button_content_section( [
			'prefix'        => 'toggle_',
			'section_label' => __( 'Enable / Disable button', 'appress' ),
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => __( 'Enable', 'appress' ),
		] );

		$this->register_button_content_section( [
			'prefix'        => 'clear_',
			'section_label' => __( 'Clear all button', 'appress' ),
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => __( 'Clear all devices', 'appress' ),
		] );

		// ── General (panel chrome — gap + title typo + status typo) ──
		$this->start_controls_section( 'section_style_general', [
			'label' => __( 'General', 'appress' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		] );

		$this->add_control( 'gap', [
			'label'      => __( 'Internal gap', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px', 'rem' ],
			'range'      => [ 'px' => [ 'min' => 0, 'max' => 32 ], 'rem' => [ 'min' => 0, 'max' => 2, 'step' => 0.1 ] ],
			'selectors'  => [ '{{WRAPPER}} .appress-biometric' => '--appress-bio-gap: {{SIZE}}{{UNIT}};' ],
		] );

		$this->add_control( 'heading_title', [
			'label'     => __( 'Panel title', 'appress' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		] );
		$this->add_control( 'color_title', [
			'label'     => __( 'Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-biometric__title' => 'color: {{VALUE}};' ],
		] );
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[ 'name' => 'typography_title', 'selector' => '{{WRAPPER}} .appress-biometric__title' ]
		);

		$this->add_control( 'heading_status', [
			'label'     => __( 'Status line', 'appress' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		] );
		$this->add_control( 'color_status', [
			'label'     => __( 'Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-biometric__status' => 'color: {{VALUE}};' ],
		] );
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[ 'name' => 'typography_status', 'selector' => '{{WRAPPER}} .appress-biometric__status' ]
		);

		$this->end_controls_section();

		// ── Per-button Style sections (3) ──
		$this->register_button_style_section( [
			'prefix'        => 'login_',
			'section_label' => __( 'Login button', 'appress' ),
			'selector'      => '{{WRAPPER}} .appress-btn-biometric-login',
			'has_label'     => true,
			'has_icon'      => true,
		] );

		$this->register_button_style_section( [
			'prefix'        => 'toggle_',
			'section_label' => __( 'Enable / Disable button', 'appress' ),
			'selector'      => '{{WRAPPER}} .appress-btn-biometric-primary',
			'has_label'     => true,
			'has_icon'      => true,
		] );

		$this->register_button_style_section( [
			'prefix'        => 'clear_',
			'section_label' => __( 'Clear all button', 'appress' ),
			'selector'      => '{{WRAPPER}} .appress-btn-biometric-danger',
			'has_label'     => true,
			'has_icon'      => true,
		] );
	}

	protected function render() {
		$s         = $this->get_settings_for_display();
		$is_editor = $this->is_elementor_editor();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \Appress\Controllers\Biometric\Shortcode_Controller::render( [
			// Per-button overrides from the trait's Content sections.
			// Empty string falls through to the shortcode's i18n
			// defaults — admin can leave any field blank.
			'login_label'      => isset( $s['login_label'] )  ? (string) $s['login_label']  : '',
			'login_icon_html'  => $this->render_selected_icon_html( 'login_' ),
			'toggle_label'     => isset( $s['toggle_label'] ) ? (string) $s['toggle_label'] : '',
			'toggle_icon_html' => $this->render_selected_icon_html( 'toggle_' ),
			'clear_label'      => isset( $s['clear_label'] )  ? (string) $s['clear_label']  : '',
			'clear_icon_html'  => $this->render_selected_icon_html( 'clear_' ),
			// Editor canvas: skip the bridge gate so the full panel UI
			// is visible while styling. Frontend + "Save & view" preview
			// run the real native check. The `preview_state` only flows
			// through in editor — frontend keeps `is_user_logged_in()`.
			'demo'             => $is_editor ? 'yes' : 'no',
			'preview_state'    => $is_editor ? ( isset( $s['preview_state'] ) ? (string) $s['preview_state'] : 'logged_in' ) : '',
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
