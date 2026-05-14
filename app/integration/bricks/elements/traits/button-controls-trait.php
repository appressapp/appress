<?php
namespace Appress\Integration\Bricks\Elements\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared Bricks controls for every Appress button element — mirror of
 * the Elementor `Button_Controls_Trait`.
 *
 * Bricks element files live at the global namespace (Bricks's
 * `register_element` requires top-level classes), but each one can
 * `use \Appress\Integration\Bricks\Elements\Traits\Button_Controls_Trait;`
 * to pick this up.
 *
 * Two registration helpers, both called from a Bricks element's
 * `set_controls()`:
 *   - `register_button_content_controls($opts)` — Tab Content
 *   - `register_button_style_controls($opts)`   — Tab Style
 *
 * Plus one helper for `set_control_groups()`:
 *   - `register_button_control_groups($opts)`   — registers the group(s)
 *
 * Multi-button elements (e.g. Biometric) call all three helpers more
 * than once with a different `prefix` per button — that prefix
 * namespaces every control + group ID so Bricks doesn't collide.
 *
 * Required element contract:
 *   - Markup uses `appress-btn` + `appress-btn-{slug}` classes.
 *   - Inner elements: `.appress-btn__icon-container > .appress-btn__icon`
 *     and (optional) `.appress-btn__label`.
 *   - Element passes `selector` (e.g. `'.appress-btn-{variant}'`) so
 *     the trait writes its values at the right scope.
 */
trait Button_Controls_Trait {

	/**
	 * @param array $opts {
	 *     @type string $prefix         Control + group ID namespace. Default ''.
	 *     @type string $content_group  Content tab group ID. Default `{prefix}btn_content`.
	 *     @type string $content_label  Content group title. Default "Content".
	 *     @type string $style_group    Style tab group ID. Default `{prefix}btn_style`.
	 *     @type string $style_label    Style group title. Default "Style".
	 *     @type bool   $register_groups Whether to register groups (set false if
	 *                                   the element registers its own with custom labels).
	 *                                   Default true.
	 * }
	 */
	protected function register_button_control_groups( $opts = [] ) {
		$opts = array_merge( [
			'prefix'          => '',
			'content_group'   => null,
			'content_label'   => esc_html__( 'Content', 'appress' ),
			'style_group'     => null,
			'style_label'     => esc_html__( 'Style', 'appress' ),
			'register_content' => true,
			'register_style'  => true,
		], $opts );

		$p   = (string) $opts['prefix'];
		$gc  = $opts['content_group'] ?? ( $p . 'btn_content' );
		$gs  = $opts['style_group']   ?? ( $p . 'btn_style' );

		if ( $opts['register_content'] ) {
			$this->control_groups[ $gc ] = [
				'title' => $opts['content_label'],
				'tab'   => 'content',
			];
		}
		if ( $opts['register_style'] ) {
			$this->control_groups[ $gs ] = [
				'title' => $opts['style_label'],
				'tab'   => 'style',
			];
		}
	}

	/**
	 * @param array $opts {
	 *     @type string $prefix         Control + group ID namespace.
	 *     @type string $group          Override the auto group ID. Default `{prefix}btn_content`.
	 *     @type bool   $has_label      Default true.
	 *     @type bool   $has_icon       Default true.
	 *     @type string $default_label  Placeholder.
	 * }
	 */
	protected function register_button_content_controls( $opts = [] ) {
		$opts = array_merge( [
			'prefix'        => '',
			'group'         => null,
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => '',
		], $opts );

		if ( ! $opts['has_label'] && ! $opts['has_icon'] ) {
			return;
		}

		$p = (string) $opts['prefix'];
		$g = $opts['group'] ?? ( $p . 'btn_content' );

		if ( $opts['has_label'] ) {
			$this->controls[ $p . 'label' ] = [
				'tab'         => 'content',
				'group'       => $g,
				'label'       => esc_html__( 'Button label', 'appress' ),
				'type'        => 'text',
				'placeholder' => $opts['default_label'],
			];
		}

		if ( $opts['has_icon'] ) {
			$this->controls[ $p . 'selected_icon' ] = [
				'tab'   => 'content',
				'group' => $g,
				'label' => esc_html__( 'Icon', 'appress' ),
				'type'  => 'icon',
			];
		}
	}

	/**
	 * Style controls live in ONE Bricks group, divided by `separator`
	 * controls into Button / Icon / Label headings — same flow the
	 * Elementor trait uses with `Controls_Manager::HEADING`.
	 *
	 * @param array $opts {
	 *     @type string $prefix    Control + group ID namespace.
	 *     @type string $group     Override auto group ID. Default `{prefix}btn_style`.
	 *     @type string $selector  CSS scope. Required.
	 *     @type bool   $has_label Default true.
	 *     @type bool   $has_icon  Default true.
	 * }
	 */
	protected function register_button_style_controls( $opts = [] ) {
		$opts = array_merge( [
			'prefix'    => '',
			'group'     => null,
			'selector'  => '.appress-btn',
			'has_label' => true,
			'has_icon'  => true,
		], $opts );

		$p   = (string) $opts['prefix'];
		$sel = $opts['selector'];
		$g   = $opts['group'] ?? ( $p . 'btn_style' );

		// ─── Button heading ───────────────────────────────────────────
		$this->controls[ $p . 'btn_heading_button' ] = [
			'tab'   => 'style',
			'group' => $g,
			'type'  => 'separator',
			'label' => esc_html__( 'Button', 'appress' ),
		];

		$this->controls[ $p . 'btn_align' ] = [
			'tab'     => 'style',
			'group'   => $g,
			'label'   => esc_html__( 'Content alignment', 'appress' ),
			'type'    => 'select',
			'options' => [
				'flex-start' => esc_html__( 'Left', 'appress' ),
				'center'     => esc_html__( 'Center', 'appress' ),
				'flex-end'   => esc_html__( 'Right', 'appress' ),
			],
			'css'     => [ [ 'selector' => $sel, 'property' => 'justify-content' ] ],
		];

		$this->controls[ $p . 'btn_width' ] = [
			'tab'   => 'style',
			'group' => $g,
			'label' => esc_html__( 'Width', 'appress' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-width' ] ],
		];

		$this->controls[ $p . 'btn_min_height' ] = [
			'tab'   => 'style',
			'group' => $g,
			'label' => esc_html__( 'Height', 'appress' ),
			'type'  => 'number',
			'units' => true,
			'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-min-height' ] ],
		];

		$this->controls[ $p . 'btn_padding' ] = [
			'tab'   => 'style',
			'group' => $g,
			'label' => esc_html__( 'Padding', 'appress' ),
			'type'  => 'dimensions',
			'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-padding' ] ],
		];

		$this->controls[ $p . 'btn_radius' ] = [
			'tab'   => 'style',
			'group' => $g,
			'label' => esc_html__( 'Border radius', 'appress' ),
			'type'  => 'dimensions',
			'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-radius' ] ],
		];

		$this->controls[ $p . 'btn_border' ] = [
			'tab'   => 'style',
			'group' => $g,
			'label' => esc_html__( 'Border', 'appress' ),
			'type'  => 'border',
			'css'   => [ [ 'selector' => $sel, 'property' => 'border' ] ],
		];

		$this->controls[ $p . 'btn_bg' ] = [
			'tab'   => 'style',
			'group' => $g,
			'label' => esc_html__( 'Background', 'appress' ),
			'type'  => 'color',
			'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-bg' ] ],
		];

		$this->controls[ $p . 'btn_box_shadow' ] = [
			'tab'   => 'style',
			'group' => $g,
			'label' => esc_html__( 'Box shadow', 'appress' ),
			'type'  => 'box-shadow',
			'css'   => [ [ 'selector' => $sel, 'property' => 'box-shadow' ] ],
		];

		if ( $opts['has_label'] && $opts['has_icon'] ) {
			$this->controls[ $p . 'btn_icon_gap' ] = [
				'tab'   => 'style',
				'group' => $g,
				'label' => esc_html__( 'Icon ↔ Label gap', 'appress' ),
				'type'  => 'number',
				'units' => true,
				'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-icon-gap' ] ],
			];
		}

		// ─── Icon heading ─────────────────────────────────────────────
		if ( $opts['has_icon'] ) {
			$this->controls[ $p . 'btn_heading_icon' ] = [
				'tab'   => 'style',
				'group' => $g,
				'type'  => 'separator',
				'label' => esc_html__( 'Icon', 'appress' ),
			];

			$this->controls[ $p . 'btn_icon_size' ] = [
				'tab'   => 'style',
				'group' => $g,
				'label' => esc_html__( 'Size', 'appress' ),
				'type'  => 'number',
				'units' => true,
				'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-icon-size' ] ],
			];

			$this->controls[ $p . 'btn_icon_color' ] = [
				'tab'   => 'style',
				'group' => $g,
				'label' => esc_html__( 'Color', 'appress' ),
				'type'  => 'color',
				'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-icon-color' ] ],
			];

			$this->controls[ $p . 'btn_icon_container_size' ] = [
				'tab'   => 'style',
				'group' => $g,
				'label' => esc_html__( 'Container size', 'appress' ),
				'type'  => 'number',
				'units' => true,
				'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-icon-container-size' ] ],
			];

			$this->controls[ $p . 'btn_icon_container_padding' ] = [
				'tab'   => 'style',
				'group' => $g,
				'label' => esc_html__( 'Container padding', 'appress' ),
				'type'  => 'dimensions',
				'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-icon-container-padding' ] ],
			];

			$this->controls[ $p . 'btn_icon_container_bg' ] = [
				'tab'   => 'style',
				'group' => $g,
				'label' => esc_html__( 'Container background', 'appress' ),
				'type'  => 'color',
				'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-icon-container-bg' ] ],
			];

			$this->controls[ $p . 'btn_icon_container_border' ] = [
				'tab'   => 'style',
				'group' => $g,
				'label' => esc_html__( 'Container border', 'appress' ),
				'type'  => 'border',
				'css'   => [ [ 'selector' => $sel . ' .appress-btn__icon-container', 'property' => 'border' ] ],
			];

			$this->controls[ $p . 'btn_icon_container_radius' ] = [
				'tab'   => 'style',
				'group' => $g,
				'label' => esc_html__( 'Container radius', 'appress' ),
				'type'  => 'dimensions',
				'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-icon-container-radius' ] ],
			];
		}

		// ─── Label heading ────────────────────────────────────────────
		if ( $opts['has_label'] ) {
			$this->controls[ $p . 'btn_heading_label' ] = [
				'tab'   => 'style',
				'group' => $g,
				'type'  => 'separator',
				'label' => esc_html__( 'Label', 'appress' ),
			];

			$this->controls[ $p . 'btn_label_typography' ] = [
				'tab'   => 'style',
				'group' => $g,
				'label' => esc_html__( 'Typography', 'appress' ),
				'type'  => 'typography',
				'css'   => [ [ 'selector' => $sel . ' .appress-btn__label' ] ],
			];

			$this->controls[ $p . 'btn_label_color' ] = [
				'tab'   => 'style',
				'group' => $g,
				'label' => esc_html__( 'Color', 'appress' ),
				'type'  => 'color',
				'css'   => [ [ 'selector' => $sel, 'property' => '--appress-btn-label-color' ] ],
			];
		}
	}

	/**
	 * Wrap shortcode output with Bricks's root attributes so the
	 * `.brxe-{element-id}` scoping class lands in the DOM. Without
	 * this, every CSS rule the trait registers (which Bricks scopes
	 * via that class) silently fails to match. Wrapper is a plain
	 * `<div>` — `width: 100%` on the inner button keeps layout
	 * unchanged for full-width buttons (every Appress variant ships
	 * `--appress-btn-width: 100%` by default).
	 */
	protected function render_button_wrapper( string $inner_html ): string {
		return '<div ' . $this->render_attributes( '_root' ) . '>' . $inner_html . '</div>';
	}

	/**
	 * Resolve the `{prefix}selected_icon` setting into HTML the
	 * shortcode can pass through verbatim. Returns empty string when
	 * the user hasn't picked one — caller falls back to the element's
	 * default icon.
	 *
	 * @param string $prefix Same prefix passed to the content registrar.
	 */
	protected function render_selected_icon_html( $prefix = '' ) {
		$key  = $prefix . 'selected_icon';
		$icon = $this->settings[ $key ] ?? null;
		if ( empty( $icon ) ) {
			return '';
		}
		// Bricks ships a global render_icon helper across versions —
		// returns the right wrapper element for FA / image / SVG icons.
		if ( function_exists( 'bricks_render_icon' ) ) {
			return bricks_render_icon( $icon, [
				'class'       => 'appress-btn__icon',
				'aria-hidden' => 'true',
			] );
		}
		// Fallback: render whichever shape the picker produced.
		if ( ! empty( $icon['icon'] ) ) {
			return '<i class="' . esc_attr( $icon['icon'] ) . ' appress-btn__icon" aria-hidden="true"></i>';
		}
		if ( ! empty( $icon['iconUpload']['url'] ) ) {
			return '<img class="appress-btn__icon" src="' . esc_url( $icon['iconUpload']['url'] ) . '" alt="" />';
		}
		return '';
	}
}
