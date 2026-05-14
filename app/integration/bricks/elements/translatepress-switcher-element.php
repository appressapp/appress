<?php
/**
 * Bricks element: TranslatePress language switcher.
 *
 * Render delegates to {@see Shortcode_Controller::render} so the
 * shortcode, the Elementor widget, and this element all emit identical
 * markup. Content controls cover the 4 shortcode attributes (style,
 * show_flag, show_label, label); Style controls expose border /
 * background / padding / flag size so designers can match the theme
 * without writing custom CSS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Bricks\Element' ) ) {
	return;
}

class Appress_Translatepress_Switcher_Element extends \Bricks\Element {

	public $category = 'appress';
	public $name     = 'appress-translatepress-switcher';
	public $icon     = 'appress-icon';

	public function get_label()    { return esc_html__( 'Translatepress language', 'appress' ); }
	public function get_keywords() { return [ 'appress', 'translatepress', 'trp', 'language', 'switcher', 'flag', 'locale' ]; }

	public function enqueue_scripts() {
		wp_enqueue_style( \Appress\Integration\Translatepress\Controllers\Shortcode_Controller::CSS_HANDLE );
		wp_enqueue_script( \Appress\Integration\Translatepress\Controllers\Shortcode_Controller::JS_HANDLE );
	}

	public function set_control_groups() {
		$this->control_groups['content'] = [
			'title' => esc_html__( 'Content', 'appress' ),
			'tab'   => 'content',
		];
		$this->control_groups['switcher'] = [
			'title' => esc_html__( 'Switcher style', 'appress' ),
			'tab'   => 'content',
		];
		$this->control_groups['flag'] = [
			'title'    => esc_html__( 'Flag style', 'appress' ),
			'tab'      => 'content',
			'required' => [ 'show_flag', '=', true ],
		];
	}

	public function set_controls() {
		// ── Content ──
		$this->controls['style'] = [
			'group'     => 'content',
			'label'     => esc_html__( 'Style', 'appress' ),
			'type'      => 'select',
			'options'   => [
				'dropdown' => esc_html__( 'Dropdown', 'appress' ),
				'inline'   => esc_html__( 'Inline pills', 'appress' ),
			],
			'default'   => 'dropdown',
			'inline'    => true,
			'clearable' => false,
		];
		$this->controls['show_flag'] = [
			'group'   => 'content',
			'label'   => esc_html__( 'Show flag', 'appress' ),
			'type'    => 'checkbox',
			'default' => true,
		];
		$this->controls['show_label'] = [
			'group'   => 'content',
			'label'   => esc_html__( 'Show language name', 'appress' ),
			'type'    => 'checkbox',
			'default' => true,
		];
		$this->controls['label'] = [
			'group'       => 'content',
			'label'       => esc_html__( 'Prefix label', 'appress' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'e.g. Language:', 'appress' ),
		];

		// ── Switcher style ──
		$this->controls['text_color'] = [
			'group' => 'switcher',
			'label' => esc_html__( 'Text color', 'appress' ),
			'type'  => 'color',
			'css'   => [
				[ 'selector' => '.appress-trp-switcher', 'property' => 'color' ],
			],
		];
		$this->controls['typography'] = [
			'group' => 'switcher',
			'label' => esc_html__( 'Typography', 'appress' ),
			'type'  => 'typography',
			'css'   => [
				[ 'selector' => '.appress-trp-switcher' ],
			],
		];
		$this->controls['border_color'] = [
			'group' => 'switcher',
			'label' => esc_html__( 'Border color', 'appress' ),
			'type'  => 'color',
			'css'   => [
				[ 'selector' => '.appress-trp-switcher__dropdown, .appress-trp-switcher__item', 'property' => 'border-color' ],
			],
		];
		$this->controls['border_width'] = [
			'group' => 'switcher',
			'label' => esc_html__( 'Border width', 'appress' ),
			'type'  => 'number',
			'min'   => 0,
			'max'   => 4,
			'units' => true,
			'css'   => [
				[ 'selector' => '.appress-trp-switcher__dropdown, .appress-trp-switcher__item', 'property' => 'border-width' ],
			],
		];
		$this->controls['border_radius'] = [
			'group' => 'switcher',
			'label' => esc_html__( 'Border radius', 'appress' ),
			'type'  => 'number',
			'min'   => 0,
			'max'   => 999,
			'units' => true,
			'css'   => [
				[ 'selector' => '.appress-trp-switcher__dropdown, .appress-trp-switcher__item', 'property' => 'border-radius' ],
			],
		];
		$this->controls['bg_color'] = [
			'group' => 'switcher',
			'label' => esc_html__( 'Background color', 'appress' ),
			'type'  => 'color',
			'css'   => [
				[ 'selector' => '.appress-trp-switcher__dropdown, .appress-trp-switcher__item', 'property' => 'background-color' ],
			],
		];
		$this->controls['padding'] = [
			'group' => 'switcher',
			'label' => esc_html__( 'Padding', 'appress' ),
			'type'  => 'dimensions',
			'css'   => [
				[ 'selector' => '.appress-trp-switcher__dropdown, .appress-trp-switcher__item', 'property' => 'padding' ],
			],
		];
		$this->controls['gap'] = [
			'group' => 'switcher',
			'label' => esc_html__( 'Gap', 'appress' ),
			'type'  => 'number',
			'min'   => 0,
			'max'   => 24,
			'units' => true,
			'css'   => [
				[ 'selector' => '.appress-trp-switcher__list, .appress-trp-switcher', 'property' => 'gap' ],
			],
		];

		// ── Flag style ──
		$this->controls['flag_width'] = [
			'group' => 'flag',
			'label' => esc_html__( 'Width', 'appress' ),
			'type'  => 'number',
			'min'   => 12,
			'max'   => 48,
			'units' => true,
			'css'   => [
				[ 'selector' => '.appress-trp-switcher__flag', 'property' => 'width' ],
			],
		];
		$this->controls['flag_height'] = [
			'group' => 'flag',
			'label' => esc_html__( 'Height', 'appress' ),
			'type'  => 'number',
			'min'   => 8,
			'max'   => 36,
			'units' => true,
			'css'   => [
				[ 'selector' => '.appress-trp-switcher__flag', 'property' => 'height' ],
			],
		];
		$this->controls['flag_radius'] = [
			'group' => 'flag',
			'label' => esc_html__( 'Border radius', 'appress' ),
			'type'  => 'number',
			'min'   => 0,
			'max'   => 24,
			'units' => true,
			'css'   => [
				[ 'selector' => '.appress-trp-switcher__flag', 'property' => 'border-radius' ],
			],
		];
	}

	public function render() {
		$s = $this->settings;
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \Appress\Integration\Translatepress\Controllers\Shortcode_Controller::render( [
			'style'      => isset( $s['style'] ) ? (string) $s['style'] : 'dropdown',
			'show_flag'  => ( ! isset( $s['show_flag'] ) || $s['show_flag'] ) ? '1' : '0',
			'show_label' => ( ! isset( $s['show_label'] ) || $s['show_label'] ) ? '1' : '0',
			'label'      => isset( $s['label'] ) ? (string) $s['label'] : '',
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
