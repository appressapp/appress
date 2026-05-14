<?php
namespace Appress\Integration\Elementor\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget: Appress status-bar-height spacer.
 *
 * Delegates rendering to `Status_Bar_Height_Shortcode_Controller::render()`
 * so shortcode + widget + Bricks element produce identical markup.
 * Height is wired to `--appress-status-bar-height` via the widget CSS;
 * builders only paint background colour + optional override height.
 */
class Status_Bar_Height_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'appress-status-bar-height';
	}

	public function get_title() {
		return __( 'Status bar height', 'appress' );
	}

	public function get_icon() {
		return 'appress-icon';
	}

	public function get_categories() {
		return [ 'appress' ];
	}

	public function get_keywords() {
		return [ 'appress', 'status bar', 'safe area', 'notch', 'spacer' ];
	}

	public function get_style_depends() {
		return [ \Appress\Controllers\Components\Status_Bar_Height_Shortcode_Controller::CSS_HANDLE ];
	}

	protected function register_controls() {
		$sel = '{{WRAPPER}} .appress-status-bar-height';

		// ── Style ─────────────────────────────────────────────────────────
		$this->start_controls_section( 'section_style', [
			'label' => __( 'Style', 'appress' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		] );

		$this->add_control( 'background', [
			'label'     => __( 'Background', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ $sel => 'background-color: {{VALUE}};' ],
		] );

		$this->add_control( 'editor_preview_height', [
			'label'       => __( 'Editor preview height', 'appress' ),
			'type'        => \Elementor\Controls_Manager::SLIDER,
			'size_units'  => [ 'px' ],
			'range'       => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
			'description' => __( 'Visible height in the Elementor editor only — the live frontend always uses --appress-status-bar-height.', 'appress' ),
			'selectors'   => [ '.elementor-editor-active ' . $sel => 'min-height: {{SIZE}}{{UNIT}};' ],
		] );

		$this->end_controls_section();
	}

	protected function render() {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \Appress\Controllers\Components\Status_Bar_Height_Shortcode_Controller::render( [] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
