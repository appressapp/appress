<?php

namespace Appress\Integration\Translatepress\Controllers;

use Appress\Controllers\Base_Controller;
use Appress\Integration\Translatepress\Services\Url_Translator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[appress_translatepress_switcher]` — language switcher web component.
 *
 * Surface-aware behaviour (see `assets/js/translatepress-switcher.js`):
 *   - Inside the Appress mobile app — fires the
 *     `translatepress.changeLanguage` JS bridge so native can cold-
 *     restart with the new variant applied from the cached config.
 *   - Plain web — falls back to TRP-style URL redirect via the
 *     anchor's `href` or the `<select>`'s `data-url`.
 *
 * Shortcode attributes:
 *   - style       `dropdown` (default) | `inline`
 *   - show_flag   `1` (default) | `0` — render TRP's bundled flag PNG
 *   - show_label  `1` (default) | `0` — render the language name
 *   - label       Optional prefix label rendered before the picker
 *                 (e.g. "Language:")
 *
 * Elementor widget + Bricks element delegate to {@see render} so all
 * three surfaces emit identical markup.
 */
class Shortcode_Controller extends Base_Controller {

	const SHORTCODE  = 'appress_translatepress_switcher';
	const JS_HANDLE  = 'appress:translatepress-switcher.js';
	const CSS_HANDLE = 'appress:translatepress-switcher.css';

	/** @var Url_Translator */ 
	private $translator;

	public function __construct() {
		$this->translator = new Url_Translator();
		parent::__construct();
	}

	protected function hooks() {
		$this->on( 'init', '@register' );
	}

	protected function register() {
		add_shortcode( self::SHORTCODE, [ $this, 'shortcode_render' ] );
	}

	/**
	 * Shortcode entry — thin wrapper around {@see render} that gates
	 * on TRP active and normalises the att array.
	 */
	public function shortcode_render( $atts = [] ) {
		$atts = shortcode_atts( [
			'style'      => 'dropdown',
			'show_flag'  => '1',
			'show_label' => '1',
			'label'      => '',
		], (array) $atts, self::SHORTCODE );

		return self::render( $atts );
	}

	/**
	 * Render the switcher markup. Public + static so the Elementor
	 * widget + Bricks element can call it directly with the same atts.
	 * Returns an empty string when TRP is off or no languages are
	 * published — caller doesn't need to gate.
	 */
	public static function render( array $atts ): string {
		$translator = new Url_Translator();
		if ( ! $translator->is_active() ) {
			return '';
		}

		$settings = $translator->get_settings();
		if ( empty( $settings['languages'] ) ) {
			return '';
		}

		$atts = array_merge( [
			'style'      => 'dropdown',
			'show_flag'  => '1',
			'show_label' => '1',
			'label'      => '',
		], $atts );

		$style      = in_array( $atts['style'], [ 'dropdown', 'inline' ], true ) ? $atts['style'] : 'dropdown';
		$show_flag  = ! empty( $atts['show_flag'] ) && $atts['show_flag'] !== '0';
		$show_label = ! empty( $atts['show_label'] ) && $atts['show_label'] !== '0';
		// At least one of the two must render — otherwise the switcher
		// would be a row of empty buttons. Force label back on.
		if ( ! $show_flag && ! $show_label ) {
			$show_label = true;
		}

		$languages      = (array) $settings['languages'];
		$language_names = $translator->get_language_names( $languages );
		$current        = self::resolve_current_language( $settings );

		// Per-language data baked once.
		$rows = [];
		foreach ( $languages as $code ) {
			$code              = (string) $code;
			$rows[ $code ] = [
				'name' => $language_names[ $code ] ?? $code,
				'url'  => $translator->translate_url( home_url( $_SERVER['REQUEST_URI'] ?? '/' ), $code ),
				'flag' => $show_flag ? $translator->get_flag_url( $code ) : '',
			];
		}

		// Asset enqueue is lazy — only on pages that actually use the
		// shortcode, no global bloat on every page load.
		wp_enqueue_script( self::JS_HANDLE );
		wp_enqueue_style( self::CSS_HANDLE );

		ob_start();
		?>
		<div class="appress-trp-switcher"
		     data-style="<?php echo esc_attr( $style ); ?>"
		     data-current="<?php echo esc_attr( $current ); ?>"
		     data-show-flag="<?php echo $show_flag ? '1' : '0'; ?>"
		     data-show-label="<?php echo $show_label ? '1' : '0'; ?>">

			<?php if ( $atts['label'] !== '' ) : ?>
				<span class="appress-trp-switcher__prefix"><?php echo esc_html( $atts['label'] ); ?></span>
			<?php endif; ?>

			<?php if ( $style === 'inline' ) : ?>
				<ul class="appress-trp-switcher__list">
					<?php foreach ( $rows as $code => $row ) : ?>
						<li>
							<a href="<?php echo esc_url( $row['url'] ); ?>"
							   class="appress-trp-switcher__item <?php echo $code === $current ? 'is-active' : ''; ?>"
							   data-lang="<?php echo esc_attr( $code ); ?>"
							   aria-current="<?php echo $code === $current ? 'true' : 'false'; ?>">
								<?php if ( $show_flag && $row['flag'] ) : ?>
									<img class="appress-trp-switcher__flag" src="<?php echo esc_url( $row['flag'] ); ?>" alt="" width="20" height="14" loading="lazy" />
								<?php endif; ?>
								<?php if ( $show_label ) : ?>
									<span class="appress-trp-switcher__name"><?php echo esc_html( $row['name'] ); ?></span>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<div class="appress-trp-switcher__dropdown">
					<?php if ( $show_flag && ! empty( $rows[ $current ]['flag'] ) ) : ?>
						<img class="appress-trp-switcher__flag appress-trp-switcher__flag--current"
						     src="<?php echo esc_url( $rows[ $current ]['flag'] ); ?>"
						     alt="" width="20" height="14" loading="lazy" aria-hidden="true" />
					<?php endif; ?>
					<select class="appress-trp-switcher__select" aria-label="<?php echo esc_attr__( 'Language', 'appress' ); ?>">
						<?php foreach ( $rows as $code => $row ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"
									data-url="<?php echo esc_url( $row['url'] ); ?>"
									<?php selected( $code, $current ); ?>>
								<?php
								// Always render the language NAME inside the
								// option — the dropdown's popup list needs
								// human-readable labels even when the
								// trigger is flag-only (`show_label=0`),
								// otherwise users couldn't tell options
								// apart when picking. The flag-only
								// trigger collapses the rendered text via
								// CSS (`[data-show-label="0"]
								// .appress-trp-switcher__select`).
								echo esc_html( $row['name'] );
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Current language: TRP's `$TRP_LANGUAGE` global if available
	 * (frontend context), otherwise the default. Used to mark the active
	 * option/link in the rendered switcher.
	 */
	private static function resolve_current_language( array $settings ): string {
		global $TRP_LANGUAGE;
		if ( ! empty( $TRP_LANGUAGE ) && in_array( $TRP_LANGUAGE, (array) $settings['languages'], true ) ) {
			return (string) $TRP_LANGUAGE;
		}
		return (string) $settings['default_language'];
	}
}
