<?php
namespace Appress\Integration\Avada\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared Fusion Builder param definitions + render helpers for every
 * Appress button element — Avada equivalent of the Elementor /
 * Bricks `Button_Controls_Trait`.
 *
 * Avada is data-driven: `fusion_builder_map(['params' => [...]])`
 * registers controls; each param produces a settings att read by the
 * element's `render($args)`. Style values flow through as data, then
 * `render_inline_style()` assembles them into a CSS-vars `style="..."`
 * attribute on the button. Inline style on the element has the
 * highest specificity, so per-instance customisation wins without
 * generating per-instance selectors / `<style>` blocks.
 *
 * Multi-button elements (e.g. Biometric) call the helpers more than
 * once with a different `prefix` per button — that prefix namespaces
 * every param name so Avada doesn't collide.
 *
 * Required element contract:
 *   - Markup uses `appress-btn` + `appress-btn-{slug}` classes.
 *   - Inner elements: `.appress-btn__icon-container > .appress-btn__icon`
 *     and (optional) `.appress-btn__label`.
 *   - Render passes `inline_style` att so the shortcode can stamp it
 *     on the button.
 */
class Button_Params {

	/**
	 * Content params — label + icon picker. Returns an array of
	 * Fusion param definitions ready to merge into a `params` array.
	 *
	 * @param array $opts {
	 *     @type string $prefix          Param name namespace. Default ''.
	 *     @type string $group           Avada group/section heading.
	 *                                   Default `Content`. Pass empty
	 *                                   string to leave ungrouped.
	 *     @type bool   $has_label       Default true.
	 *     @type bool   $has_icon        Default true.
	 *     @type string $default_label   Description hint for the textfield.
	 * }
	 */
	public static function content_params( $opts = [] ): array {
		$opts = array_merge( [
			'prefix'        => '',
			'group'         => esc_attr__( 'Content', 'appress' ),
			'has_label'     => true,
			'has_icon'      => true,
			'default_label' => '',
		], $opts );

		$p     = (string) $opts['prefix'];
		$group = (string) $opts['group'];
		$out   = [];

		if ( $opts['has_label'] ) {
			$out[] = [
				'type'        => 'textfield',
				'heading'     => esc_attr__( 'Button label', 'appress' ),
				'param_name'  => $p . 'label',
				'value'       => '',
				'group'       => $group,
				'description' => $opts['default_label'] !== ''
					/* translators: %s: the button's default label text shown when the field is left blank. */
					? sprintf( esc_attr__( 'Defaults to "%s".', 'appress' ), $opts['default_label'] )
					: esc_attr__( 'Leave blank to render the icon only.', 'appress' ),
			];
		}

		if ( $opts['has_icon'] ) {
			$out[] = [
				'type'        => 'iconpicker',
				'heading'     => esc_attr__( 'Icon', 'appress' ),
				'param_name'  => $p . 'selected_icon',
				'value'       => '',
				'group'       => $group,
				'description' => esc_attr__( 'Leave blank to keep the element\'s default icon.', 'appress' ),
			];
		}

		return $out;
	}

	/**
	 * Style params — align, width/height, padding, radius, border, bg,
	 * icon size/color/gap, label color. Skips the icon-container +
	 * full typography sub-controls (covered by Avada Global Styles
	 * and per-element classes) to keep the param surface manageable.
	 *
	 * @param array $opts {
	 *     @type string $prefix      Param name namespace.
	 *     @type string $group       Avada section heading. Default `Style`.
	 *     @type bool   $has_label   Default true.
	 *     @type bool   $has_icon    Default true.
	 * }
	 */
	public static function style_params( $opts = [] ): array {
		$opts = array_merge( [
			'prefix'    => '',
			'group'     => esc_attr__( 'Style', 'appress' ),
			'has_label' => true,
			'has_icon'  => true,
		], $opts );

		$p     = (string) $opts['prefix'];
		$group = (string) $opts['group'];
		$out   = [];

		// ─── Button ───
		$out[] = [
			'type'       => 'radio_button_set',
			'heading'    => esc_attr__( 'Content alignment', 'appress' ),
			'param_name' => $p . 'btn_align',
			'value'      => [
				''            => esc_attr__( 'Default', 'appress' ),
				'flex-start'  => esc_attr__( 'Left', 'appress' ),
				'center'      => esc_attr__( 'Center', 'appress' ),
				'flex-end'    => esc_attr__( 'Right', 'appress' ),
			],
			'default'    => '',
			'group'      => $group,
		];
		$out[] = [
			'type'       => 'range',
			'heading'    => esc_attr__( 'Width', 'appress' ),
			'param_name' => $p . 'btn_width',
			'value'      => '',
			'min'        => '0',
			'max'        => '800',
			'step'       => '1',
			'group'      => $group,
			'description' => esc_attr__( 'Pixels. Blank = use shared default.', 'appress' ),
		];
		$out[] = [
			'type'       => 'range',
			'heading'    => esc_attr__( 'Min height', 'appress' ),
			'param_name' => $p . 'btn_min_height',
			'value'      => '',
			'min'        => '0',
			'max'        => '120',
			'step'       => '1',
			'group'      => $group,
		];
		$out[] = [
			'type'       => 'dimension',
			'heading'    => esc_attr__( 'Padding', 'appress' ),
			'param_name' => $p . 'btn_padding',
			'value'      => [ 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ],
			'group'      => $group,
		];
		$out[] = [
			'type'       => 'dimension',
			'heading'    => esc_attr__( 'Border radius', 'appress' ),
			'param_name' => $p . 'btn_radius',
			'value'      => [ 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ],
			'group'      => $group,
			'description' => esc_attr__( 'Top-left, top-right, bottom-right, bottom-left.', 'appress' ),
		];
		$out[] = [
			'type'       => 'range',
			'heading'    => esc_attr__( 'Border width', 'appress' ),
			'param_name' => $p . 'btn_border_width',
			'value'      => '',
			'min'        => '0',
			'max'        => '8',
			'step'       => '1',
			'group'      => $group,
		];
		$out[] = [
			'type'       => 'colorpickeralpha',
			'heading'    => esc_attr__( 'Border color', 'appress' ),
			'param_name' => $p . 'btn_border_color',
			'value'      => '',
			'group'      => $group,
		];
		$out[] = [
			'type'       => 'colorpickeralpha',
			'heading'    => esc_attr__( 'Background', 'appress' ),
			'param_name' => $p . 'btn_bg',
			'value'      => '',
			'group'      => $group,
		];

		// ─── Icon ───
		if ( $opts['has_icon'] ) {
			if ( $opts['has_label'] ) {
				$out[] = [
					'type'       => 'range',
					'heading'    => esc_attr__( 'Icon ↔ Label gap', 'appress' ),
					'param_name' => $p . 'btn_icon_gap',
					'value'      => '',
					'min'        => '0',
					'max'        => '32',
					'step'       => '1',
					'group'      => $group,
				];
			}
			$out[] = [
				'type'       => 'range',
				'heading'    => esc_attr__( 'Icon size', 'appress' ),
				'param_name' => $p . 'btn_icon_size',
				'value'      => '',
				'min'        => '8',
				'max'        => '64',
				'step'       => '1',
				'group'      => $group,
			];
			$out[] = [
				'type'       => 'colorpickeralpha',
				'heading'    => esc_attr__( 'Icon color', 'appress' ),
				'param_name' => $p . 'btn_icon_color',
				'value'      => '',
				'group'      => $group,
			];
		}

		// ─── Label ───
		if ( $opts['has_label'] ) {
			$out[] = [
				'type'       => 'colorpickeralpha',
				'heading'    => esc_attr__( 'Label color', 'appress' ),
				'param_name' => $p . 'btn_label_color',
				'value'      => '',
				'group'      => $group,
			];
		}

		return $out;
	}

	/**
	 * Build the inline `style="--appress-btn-*: ..."` string from the
	 * att array. Empty values are skipped so the shared CSS defaults
	 * stay in effect for fields the admin didn't customise.
	 *
	 * @param array  $atts   Avada-resolved param values.
	 * @param string $prefix Prefix matching the param namespace.
	 */
	public static function render_inline_style( array $atts, string $prefix = '' ): string {
		$rules = [];

		// Map each param to its CSS var. Skip empty + zero-string values
		// (Avada returns '' for blank slider). Direct properties
		// (justify-content) live alongside vars in the same `style` attr.
		$bag = [
			'btn_width'       => '--appress-btn-width',
			'btn_min_height'  => '--appress-btn-min-height',
			'btn_bg'          => '--appress-btn-bg',
			'btn_border_color' => '--appress-btn-border-color',
			'btn_icon_gap'    => '--appress-btn-icon-gap',
			'btn_icon_size'   => '--appress-btn-icon-size',
			'btn_icon_color'  => '--appress-btn-icon-color',
			'btn_label_color' => '--appress-btn-label-color',
		];
		foreach ( $bag as $key => $var ) {
			$val = $atts[ $prefix . $key ] ?? '';
			if ( $val === '' || $val === null ) {
				continue;
			}
			// Numeric range values get `px` appended; colours pass through.
			if ( is_numeric( $val ) ) {
				$val .= 'px';
			}
			$rules[] = $var . ': ' . $val;
		}

		// Border width — special-case so we know whether to also set
		// border-style. Without an explicit width the user's border
		// colour pick is invisible against the shared default
		// (1px solid #d1d5db) so we only override when the admin set
		// width >= 0.
		$bw = $atts[ $prefix . 'btn_border_width' ] ?? '';
		if ( $bw !== '' && $bw !== null ) {
			if ( is_numeric( $bw ) ) {
				$bw .= 'px';
			}
			$rules[] = '--appress-btn-border-width: ' . $bw;
		}

		// Padding (dimension) — array of 4 sides.
		$pad = $atts[ $prefix . 'btn_padding' ] ?? null;
		if ( is_array( $pad ) ) {
			$top    = self::dim_val( $pad, 'top' );
			$right  = self::dim_val( $pad, 'right' );
			$bottom = self::dim_val( $pad, 'bottom' );
			$left   = self::dim_val( $pad, 'left' );
			if ( $top !== '' || $right !== '' || $bottom !== '' || $left !== '' ) {
				$top    = $top    !== '' ? $top    : '0';
				$right  = $right  !== '' ? $right  : '0';
				$bottom = $bottom !== '' ? $bottom : '0';
				$left   = $left   !== '' ? $left   : '0';
				$rules[] = "--appress-btn-padding: $top $right $bottom $left";
			}
		}

		// Border radius (dimension).
		$rad = $atts[ $prefix . 'btn_radius' ] ?? null;
		if ( is_array( $rad ) ) {
			$top    = self::dim_val( $rad, 'top' );
			$right  = self::dim_val( $rad, 'right' );
			$bottom = self::dim_val( $rad, 'bottom' );
			$left   = self::dim_val( $rad, 'left' );
			if ( $top !== '' || $right !== '' || $bottom !== '' || $left !== '' ) {
				$top    = $top    !== '' ? $top    : '0';
				$right  = $right  !== '' ? $right  : '0';
				$bottom = $bottom !== '' ? $bottom : '0';
				$left   = $left   !== '' ? $left   : '0';
				$rules[] = "--appress-btn-radius: $top $right $bottom $left";
			}
		}

		// Direct property: justify-content (alignment).
		$align = $atts[ $prefix . 'btn_align' ] ?? '';
		if ( in_array( $align, [ 'flex-start', 'center', 'flex-end' ], true ) ) {
			$rules[] = 'justify-content: ' . $align;
		}

		return $rules ? implode( '; ', $rules ) . ';' : '';
	}

	/**
	 * Convert a Fusion icon-picker setting into an HTML snippet that
	 * matches the shared `<svg class="appress-btn__icon">` contract.
	 * Returns empty string when the user hasn't picked one — caller
	 * falls back to the element's default icon.
	 */
	public static function render_icon_html( array $atts, string $prefix = '' ): string {
		$icon = $atts[ $prefix . 'selected_icon' ] ?? '';
		if ( ! is_string( $icon ) || $icon === '' ) {
			return '';
		}
		// Fusion stores icons as strings like `awb-icon-user` or full
		// FontAwesome class names. Wrap as `<i>` with our base class
		// so the shared CSS sizes it.
		return '<i class="appress-btn__icon ' . esc_attr( $icon ) . '" aria-hidden="true"></i>';
	}

	/**
	 * Pull a single side off Avada's `dimension` value, handling both
	 * the plain string form ("12px") and the array form
	 * (['top' => '12px', ...]). Bare numbers get `px` appended.
	 */
	private static function dim_val( array $dim, string $side ): string {
		$v = $dim[ $side ] ?? '';
		if ( $v === '' || $v === null ) {
			return '';
		}
		if ( is_numeric( $v ) ) {
			return $v . 'px';
		}
		return (string) $v;
	}
}
