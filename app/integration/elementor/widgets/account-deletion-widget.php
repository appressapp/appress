<?php
namespace Appress\Integration\Elementor\Widgets;

use Appress\Integration\Elementor\Widgets\Traits\Button_Controls_Trait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget: Appress account-deletion button.
 *
 * Surfaces a single button (Delete my account). Trait sections
 * (Content + Style) reuse the shared `Button_Controls_Trait` with the
 * `button_` prefix. Wrapper-level chrome (title, description) lives in
 * the General Content section above.
 *
 * Render delegates to the shortcode controller for byte-identical
 * markup across surfaces.
 */
class Account_Deletion_Widget extends \Elementor\Widget_Base {

	use Button_Controls_Trait;

	public function get_name()       { return 'appress-account-deletion'; }
	public function get_title()      { return __( 'Account deletion', 'appress' ); }
	public function get_icon()       { return 'appress-icon'; }
	public function get_categories() { return [ 'appress' ]; }
	public function get_keywords()   { return [ 'appress', 'delete', 'account', 'erase', 'gdpr', 'apple', 'guideline' ]; }

	public function get_style_depends() {
		return [
			'appress:frontend-commons.css',
			\Appress\Controllers\Account_Deletion\Shortcode_Controller::CSS_HANDLE,
		];
	}

	public function get_script_depends() {
		return [ \Appress\Controllers\Account_Deletion\Shortcode_Controller::JS_HANDLE ];
	}

	protected function register_controls() {
		// ── General — wrapper text + editor-only preview-state ──
		$this->start_controls_section( 'section_general', [
			'label' => __( 'General', 'appress' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		] );
		$this->add_control( 'title', [
			'label'       => __( 'Title', 'appress' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'default'     => '',
			'placeholder' => __( 'Delete your account', 'appress' ),
		] );
		$this->add_control( 'description', [
			'label'       => __( 'Description', 'appress' ),
			'type'        => \Elementor\Controls_Manager::TEXTAREA,
			'default'     => '',
			'rows'        => 3,
			'placeholder' => __( 'This action is permanent. Your data will be removed within 30 minutes of confirming the email link.', 'appress' ),
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

		// ── Button Content section (label + icon) ──
		$this->register_button_content_section( [
			'prefix'        => 'button_',
			'section_label' => __( 'Button', 'appress' ),
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => __( 'Delete my account', 'appress' ),
		] );

		// ── Wrapper Style (gap + title + description typography) ──
		$this->start_controls_section( 'section_style_general', [
			'label' => __( 'General', 'appress' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		] );

		$this->add_control( 'gap', [
			'label'      => __( 'Internal gap', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px', 'rem' ],
			'range'      => [ 'px' => [ 'min' => 0, 'max' => 32 ], 'rem' => [ 'min' => 0, 'max' => 2, 'step' => 0.1 ] ],
			'selectors'  => [ '{{WRAPPER}} .appress-account-deletion' => '--appress-acd-gap: {{SIZE}}{{UNIT}};' ],
		] );

		$this->add_control( 'heading_title', [
			'label'     => __( 'Title', 'appress' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		] );
		$this->add_control( 'color_title', [
			'label'     => __( 'Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-account-deletion__title' => 'color: {{VALUE}};' ],
		] );
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[ 'name' => 'typography_title', 'selector' => '{{WRAPPER}} .appress-account-deletion__title' ]
		);

		$this->add_control( 'heading_description', [
			'label'     => __( 'Description', 'appress' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		] );
		$this->add_control( 'color_description', [
			'label'     => __( 'Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-account-deletion__description' => 'color: {{VALUE}};' ],
		] );
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[ 'name' => 'typography_description', 'selector' => '{{WRAPPER}} .appress-account-deletion__description' ]
		);

		$this->end_controls_section();

		// ── Button Style section ──
		$this->register_button_style_section( [
			'prefix'        => 'button_',
			'section_label' => __( 'Button', 'appress' ),
			'selector'      => '{{WRAPPER}} .appress-btn-account-deletion',
			'has_label'     => true,
			'has_icon'      => true,
		] );
	}

	protected function render() {
		$s         = $this->get_settings_for_display();
		$is_editor = $this->is_elementor_editor();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \Appress\Controllers\Account_Deletion\Shortcode_Controller::render( [
			'title'            => isset( $s['title'] )       ? (string) $s['title']       : '',
			'description'      => isset( $s['description'] ) ? (string) $s['description'] : '',
			'button_text'      => isset( $s['button_label'] ) ? (string) $s['button_label'] : '',
			'button_icon_html' => $this->render_selected_icon_html( 'button_' ),
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
