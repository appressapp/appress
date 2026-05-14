<?php
namespace Appress\Integration\Elementor\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget: TranslatePress language switcher.
 *
 * Render delegates to {@see Shortcode_Controller::render} so the
 * shortcode, this widget, and the Bricks element all emit identical
 * markup. Content controls cover the 4 shortcode attributes (style,
 * show_flag, show_label, label); Style controls expose border /
 * background / padding / typography for the wrapper so designers can
 * match the theme without writing custom CSS.
 */
class Translatepress_Switcher_Widget extends \Elementor\Widget_Base {

	public function get_name()    { return 'appress-translatepress-switcher'; }
	public function get_title()   { return __( 'Translatepress language', 'appress' ); }
	public function get_icon()    { return 'appress-icon'; }
	public function get_categories() { return [ 'appress' ]; }
	public function get_keywords()   { return [ 'appress', 'translatepress', 'trp', 'language', 'switcher', 'flag', 'locale' ]; }

	public function get_style_depends() {
		return [ \Appress\Integration\Translatepress\Controllers\Shortcode_Controller::CSS_HANDLE ];
	}
	public function get_script_depends() {
		return [ \Appress\Integration\Translatepress\Controllers\Shortcode_Controller::JS_HANDLE ];
	}

	protected function register_controls() {
		// ── Content ───────────────────────────────────────────────────
		$this->start_controls_section( 'content_section', [
			'label' => __( 'Content', 'appress' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'style', [
			'label'   => __( 'Style', 'appress' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'dropdown',
			'options' => [
				'dropdown' => __( 'Dropdown', 'appress' ),
				'inline'   => __( 'Inline pills', 'appress' ),
			],
		] );

		$this->add_control( 'show_flag', [
			'label'        => __( 'Show flag', 'appress' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => __( 'Yes', 'appress' ),
			'label_off'    => __( 'No', 'appress' ),
			'return_value' => 'yes',
			'default'      => 'yes',
		] );

		$this->add_control( 'show_label', [
			'label'        => __( 'Show language name', 'appress' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => __( 'Yes', 'appress' ),
			'label_off'    => __( 'No', 'appress' ),
			'return_value' => 'yes',
			'default'      => 'yes',
		] );

		$this->add_control( 'label', [
			'label'       => __( 'Prefix label', 'appress' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'default'     => '',
			'placeholder' => __( 'e.g. Language:', 'appress' ),
		] );

		$this->end_controls_section();

		// ── Style: Switcher ───────────────────────────────────────────
		$this->start_controls_section( 'style_switcher', [
			'label' => __( 'Switcher', 'appress' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		] );

		$this->add_control( 'text_color', [
			'label'     => __( 'Text color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [
				'{{WRAPPER}} .appress-trp-switcher' => 'color: {{VALUE}};',
			],
		] );

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .appress-trp-switcher',
			]
		);

		$this->add_control( 'border_color', [
			'label'     => __( 'Border color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [
				'{{WRAPPER}} .appress-trp-switcher__dropdown' => 'border-color: {{VALUE}};',
				'{{WRAPPER}} .appress-trp-switcher__item'     => 'border-color: {{VALUE}};',
			],
		] );

		$this->add_control( 'border_width', [
			'label'      => __( 'Border width', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 0, 'max' => 4, 'step' => 1 ] ],
			'selectors'  => [
				'{{WRAPPER}} .appress-trp-switcher__dropdown' => 'border-width: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .appress-trp-switcher__item'     => 'border-width: {{SIZE}}{{UNIT}};',
			],
		] );

		$this->add_control( 'border_radius', [
			'label'      => __( 'Border radius', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 0, 'max' => 999, 'step' => 1 ] ],
			'selectors'  => [
				'{{WRAPPER}} .appress-trp-switcher__dropdown' => 'border-radius: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .appress-trp-switcher__item'     => 'border-radius: {{SIZE}}{{UNIT}};',
			],
		] );

		$this->add_control( 'bg_color', [
			'label'     => __( 'Background color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [
				'{{WRAPPER}} .appress-trp-switcher__dropdown' => 'background-color: {{VALUE}};',
				'{{WRAPPER}} .appress-trp-switcher__item'     => 'background-color: {{VALUE}};',
			],
		] );

		$this->add_responsive_control( 'padding', [
			'label'      => __( 'Padding', 'appress' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', 'em' ],
			'selectors'  => [
				'{{WRAPPER}} .appress-trp-switcher__dropdown' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				'{{WRAPPER}} .appress-trp-switcher__item'     => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			],
		] );

		$this->add_responsive_control( 'gap', [
			'label'      => __( 'Gap', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 0, 'max' => 24, 'step' => 1 ] ],
			'selectors'  => [
				'{{WRAPPER}} .appress-trp-switcher__list' => 'gap: {{SIZE}}{{UNIT}};',
				'{{WRAPPER}} .appress-trp-switcher'       => 'gap: {{SIZE}}{{UNIT}};',
			],
		] );

		$this->end_controls_section();

		// ── Style: Flag ───────────────────────────────────────────────
		$this->start_controls_section( 'style_flag', [
			'label'     => __( 'Flag', 'appress' ),
			'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
			'condition' => [ 'show_flag' => 'yes' ],
		] );

		$this->add_control( 'flag_width', [
			'label'      => __( 'Width', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 12, 'max' => 48, 'step' => 1 ] ],
			'selectors'  => [
				'{{WRAPPER}} .appress-trp-switcher__flag' => 'width: {{SIZE}}{{UNIT}};',
			],
		] );

		$this->add_control( 'flag_height', [
			'label'      => __( 'Height', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 8, 'max' => 36, 'step' => 1 ] ],
			'selectors'  => [
				'{{WRAPPER}} .appress-trp-switcher__flag' => 'height: {{SIZE}}{{UNIT}};',
			],
		] );

		$this->add_control( 'flag_radius', [
			'label'      => __( 'Border radius', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 0, 'max' => 24, 'step' => 1 ] ],
			'selectors'  => [
				'{{WRAPPER}} .appress-trp-switcher__flag' => 'border-radius: {{SIZE}}{{UNIT}};',
			],
		] );

		$this->end_controls_section();
	}

	protected function render() {
		$s = $this->get_settings_for_display();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \Appress\Integration\Translatepress\Controllers\Shortcode_Controller::render( [
			'style'      => isset( $s['style'] ) ? (string) $s['style'] : 'dropdown',
			'show_flag'  => ( isset( $s['show_flag'] ) && $s['show_flag'] === 'yes' ) ? '1' : '0',
			'show_label' => ( isset( $s['show_label'] ) && $s['show_label'] === 'yes' ) ? '1' : '0',
			'label'      => isset( $s['label'] ) ? (string) $s['label'] : '',
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
