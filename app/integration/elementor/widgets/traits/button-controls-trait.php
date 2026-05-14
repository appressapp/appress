<?php
namespace Appress\Integration\Elementor\Widgets\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared Elementor controls for every Appress button widget.
 *
 * Two registration helpers, both called from a widget's
 * `register_controls()`:
 *   - `register_button_content_section($opts)` — Tab Content
 *       Button label  (optional; gated by $opts['has_label'])
 *       Choose icon   (optional; gated by $opts['has_icon'])
 *   - `register_button_style_section($opts)` — Tab Style (single section)
 *       ── Button heading ──  align, width, height, padding, radius,
 *                              border, background, box-shadow, icon↔label gap
 *       ── Icon heading ──    size, color, container size/padding/bg/border/radius
 *       ── Label heading ──   typography, color
 *
 * Every value lands as a CSS custom property on the widget's selector
 * (`--appress-btn-*`). Buttons render against a shared `.appress-btn`
 * base style that reads those vars, so one trait drives every Appress
 * button's look — no per-widget CSS rules.
 *
 * Multi-button widgets (e.g. Biometric) call the helpers more than once
 * with a different `prefix` per button — that prefix namespaces every
 * control + section ID so Elementor doesn't collide. Single-button
 * widgets pass no prefix (default empty string).
 *
 * Required widget contract:
 *   - Markup uses `appress-btn` + `appress-btn-{slug}` classes.
 *   - Inner elements: `.appress-btn__icon-container > .appress-btn__icon`
 *     and (optional) `.appress-btn__label`.
 *   - Widget passes `selector` (e.g. `'{{WRAPPER}} .appress-btn-{variant}'`)
 *     so trait writes vars at the right scope.
 */
trait Button_Controls_Trait {

	/**
	 * @param array $opts {
	 *     @type string $prefix         Namespace for control + section IDs.
	 *                                  Default ''. Used by multi-button widgets.
	 *     @type string $section_id     Override the auto-generated section ID.
	 *                                  Default `{prefix}appress_btn_content`.
	 *     @type string $section_label  Section heading. Default "Content".
	 *     @type bool   $has_label      Render the "Button label" control. Default true.
	 *     @type bool   $has_icon       Render the "Choose icon" control. Default true.
	 *     @type string $default_label  Placeholder for the label input.
	 * }
	 */
	protected function register_button_content_section( $opts = [] ) {
		$opts = array_merge( [
			'prefix'        => '',
			'section_id'    => null,
			'section_label' => __( 'Content', 'appress' ),
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => '',
		], $opts );

		// Nothing to register — caller asked for an empty content section.
		if ( ! $opts['has_label'] && ! $opts['has_icon'] ) {
			return;
		}

		$p   = (string) $opts['prefix'];
		$sid = $opts['section_id'] ?? ( $p . 'appress_btn_content' );

		$this->start_controls_section( $sid, [
			'label' => $opts['section_label'],
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		] );

		if ( $opts['has_label'] ) {
			$this->add_control( $p . 'label', [
				'label'       => __( 'Button label', 'appress' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => $opts['default_label'],
				'default'     => '',
				'description' => __( 'Leave blank to render the icon only.', 'appress' ),
			] );
		}

		if ( $opts['has_icon'] ) {
			$this->add_control( $p . 'selected_icon', [
				'label'            => __( 'Icon', 'appress' ),
				'type'             => \Elementor\Controls_Manager::ICONS,
				'fa4compatibility' => 'icon',
				'description'      => __( 'Leave blank to keep the widget\'s default icon.', 'appress' ),
			] );
		}

		$this->end_controls_section();
	}

	/**
	 * Style section for one button. All controls live under a single
	 * Elementor section, divided by HEADING controls into Button / Icon
	 * / Label groups so the panel stays compact (4-section sprawl was
	 * unmanageable for multi-button widgets like Biometric).
	 *
	 * @param array $opts {
	 *     @type string $prefix         Control + section ID namespace. Default ''.
	 *     @type string $section_id     Override auto-generated section ID.
	 *                                  Default `{prefix}appress_btn_style`.
	 *     @type string $section_label  Section heading. Default "Style".
	 *     @type string $selector       CSS scope the trait writes its vars at.
	 *                                  Required. Typical: `'{{WRAPPER}} .appress-btn-{variant}'`.
	 *     @type bool   $has_label      Show Label heading + typography/color. Default true.
	 *     @type bool   $has_icon       Show Icon heading + icon controls. Default true.
	 * }
	 */
	protected function register_button_style_section( $opts = [] ) {
		$opts = array_merge( [
			'prefix'        => '',
			'section_id'    => null,
			'section_label' => __( 'Style', 'appress' ),
			'selector'      => '{{WRAPPER}} .appress-btn',
			'has_label'     => true,
			'has_icon'      => true,
		], $opts );

		$p   = (string) $opts['prefix'];
		$sel = $opts['selector'];
		$sid = $opts['section_id'] ?? ( $p . 'appress_btn_style' );

		$this->start_controls_section( $sid, [
			'label' => $opts['section_label'],
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		] );

		// ─── Button heading ───────────────────────────────────────────
		$this->add_control( $p . 'heading_button', [
			'label' => __( 'Button', 'appress' ),
			'type'  => \Elementor\Controls_Manager::HEADING,
		] );

		// Content alignment INSIDE the button (icon-container ↔ label).
		// Maps left/center/right → flex-start/center/flex-end on the
		// button's `inline-flex` axis. Writes at the variant selector
		// so a single instance only affects its own inner layout.
		$this->add_responsive_control( $p . 'btn_align', [
			'label'   => __( 'Content alignment', 'appress' ),
			'type'    => \Elementor\Controls_Manager::CHOOSE,
			'options' => [
				'flex-start' => [ 'title' => __( 'Left', 'appress' ),   'icon' => 'eicon-text-align-left' ],
				'center'     => [ 'title' => __( 'Center', 'appress' ), 'icon' => 'eicon-text-align-center' ],
				'flex-end'   => [ 'title' => __( 'Right', 'appress' ),  'icon' => 'eicon-text-align-right' ],
			],
			'default'   => 'center',
			'toggle'    => true,
			'selectors' => [ $sel => 'justify-content: {{VALUE}};' ],
		] );

		$this->add_responsive_control( $p . 'btn_width', [
			'label'      => __( 'Width', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px', '%' ],
			'range'      => [
				'px' => [ 'min' => 100, 'max' => 800 ],
				'%'  => [ 'min' => 10,  'max' => 100 ],
			],
			'selectors'  => [ $sel => '--appress-btn-width: {{SIZE}}{{UNIT}};' ],
		] );

		$this->add_responsive_control( $p . 'btn_min_height', [
			'label'      => __( 'Height', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 28, 'max' => 100 ] ],
			'selectors'  => [ $sel => '--appress-btn-min-height: {{SIZE}}{{UNIT}};' ],
		] );

		$this->add_responsive_control( $p . 'btn_padding', [
			'label'      => __( 'Padding', 'appress' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', 'em', 'rem', '%' ],
			'selectors'  => [ $sel => '--appress-btn-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
		] );

		$this->add_responsive_control( $p . 'btn_radius', [
			'label'      => __( 'Border radius', 'appress' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', '%', 'rem' ],
			'selectors'  => [ $sel => '--appress-btn-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
		] );

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
			'name'     => $p . 'btn_border',
			'selector' => $sel,
		] );

		$this->add_control( $p . 'btn_bg', [
			'label'     => __( 'Background', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ $sel => '--appress-btn-bg: {{VALUE}};' ],
		] );

		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
			'name'     => $p . 'btn_box_shadow',
			'selector' => $sel,
		] );

		if ( $opts['has_label'] && $opts['has_icon'] ) {
			$this->add_control( $p . 'btn_icon_gap', [
				'label'      => __( 'Icon ↔ Label gap', 'appress' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 0, 'max' => 32 ] ],
				'selectors'  => [ $sel => '--appress-btn-icon-gap: {{SIZE}}{{UNIT}};' ],
			] );
		}

		// ─── Icon heading ─────────────────────────────────────────────
		if ( $opts['has_icon'] ) {
			$this->add_control( $p . 'heading_icon', [
				'label'     => __( 'Icon', 'appress' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			] );

			$this->add_responsive_control( $p . 'btn_icon_size', [
				'label'      => __( 'Size', 'appress' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [ 'px' => [ 'min' => 8, 'max' => 64 ] ],
				'selectors'  => [ $sel => '--appress-btn-icon-size: {{SIZE}}{{UNIT}};' ],
			] );

			$this->add_control( $p . 'btn_icon_color', [
				'label'     => __( 'Color', 'appress' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [ $sel => '--appress-btn-icon-color: {{VALUE}};' ],
			] );

			$this->add_responsive_control( $p . 'btn_icon_container_size', [
				'label'       => __( 'Container size', 'appress' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => [ 'px' ],
				'range'       => [ 'px' => [ 'min' => 0, 'max' => 80 ] ],
				'description' => __( 'Width = height. Set to 0 for no fixed size.', 'appress' ),
				'selectors'   => [ $sel => '--appress-btn-icon-container-size: {{SIZE}}{{UNIT}};' ],
			] );

			$this->add_responsive_control( $p . 'btn_icon_container_padding', [
				'label'      => __( 'Container padding', 'appress' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem' ],
				'selectors'  => [ $sel => '--appress-btn-icon-container-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
			] );

			$this->add_control( $p . 'btn_icon_container_bg', [
				'label'     => __( 'Container background', 'appress' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [ $sel => '--appress-btn-icon-container-bg: {{VALUE}};' ],
			] );

			$this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
				'name'     => $p . 'btn_icon_container_border',
				'selector' => $sel . ' .appress-btn__icon-container',
			] );

			$this->add_responsive_control( $p . 'btn_icon_container_radius', [
				'label'      => __( 'Container radius', 'appress' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'rem' ],
				'selectors'  => [ $sel => '--appress-btn-icon-container-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
			] );
		}

		// ─── Label heading ────────────────────────────────────────────
		if ( $opts['has_label'] ) {
			$this->add_control( $p . 'heading_label', [
				'label'     => __( 'Label', 'appress' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			] );

			$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), [
				'name'     => $p . 'btn_label_typography',
				'selector' => $sel . ' .appress-btn__label',
			] );

			$this->add_control( $p . 'btn_label_color', [
				'label'     => __( 'Color', 'appress' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [ $sel => '--appress-btn-label-color: {{VALUE}};' ],
			] );
		}

		$this->end_controls_section();
	}

	/**
	 * Resolve a widget's `{prefix}selected_icon` setting into an HTML
	 * snippet the shortcode can pass through verbatim. Returns empty
	 * string when the user hasn't picked one — caller falls back to
	 * the widget's default icon.
	 *
	 * @param string $prefix Same prefix that was passed to
	 *                       `register_button_content_section`. Default ''.
	 */
	protected function render_selected_icon_html( $prefix = '' ) {
		$s        = $this->get_settings_for_display();
		$selected = $s[ $prefix . 'selected_icon' ] ?? null;
		if ( empty( $selected ) || empty( $selected['value'] ) ) {
			return '';
		}
		ob_start();
		\Elementor\Icons_Manager::render_icon( $selected, [
			'aria-hidden' => 'true',
			'class'       => 'appress-btn__icon',
		] );
		return ob_get_clean();
	}
}
