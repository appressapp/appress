<?php

namespace Appress\Integration\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds an "Appress" panel to the Advanced tab of every Elementor element.
 *
 * Auto-enabled (no admin toggle, no entry in appress-integrations) — only loads
 * when Elementor is active. Exposes a switcher + show/hide select + a repeater
 * of conditions; each condition matches the current request's User-Agent against
 * "in app (any)", "in app (Android)", or "in app (iOS)" with an optional app_id
 * filter. PHP-side: when the switcher is ON the widget is suppressed via the
 * `elementor/frontend/{element}/should_render` filter — zero JS, zero CSS hacks.
 */
class Elementor_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Bail entirely if Elementor isn't loaded — no UI, no filters, no overhead.
		if ( ! did_action( 'elementor/loaded' ) ) {
			$this->on( 'plugins_loaded', '@maybe_boot', 20 );
			return;
		}
		$this->boot();
	}  

	protected function maybe_boot() {
		if ( did_action( 'elementor/loaded' ) ) {
			$this->boot();
		}
	} 

	protected function boot() {
		// Hook AFTER the Layout (first Advanced) section closes for each element type
		// so our "Appress" panel registers right below it — keeps the native Layout
		// controls at the top of Advanced where Elementor users expect them, and
		// places Appress as the second Advanced section.
		// First Advanced section per type: widget=_section_style, section=section_advanced,
		// column=section_advanced, container=section_layout.
		$this->on( 'elementor/element/common/_section_style/after_section_end',    '@register_controls', 10, 2 );
		$this->on( 'elementor/element/section/section_advanced/after_section_end', '@register_controls', 10, 2 );
		$this->on( 'elementor/element/column/section_advanced/after_section_end',  '@register_controls', 10, 2 );
		$this->on( 'elementor/element/container/section_layout/after_section_end', '@register_controls', 10, 2 );

		// One render-filter per element type Elementor exposes — each filter receives
		// the parent element instance so we read the same settings regardless of type.
		$this->filter( 'elementor/frontend/widget/should_render',    '@check_visibility', 10, 2 );
		$this->filter( 'elementor/frontend/section/should_render',   '@check_visibility', 10, 2 );
		$this->filter( 'elementor/frontend/column/should_render',    '@check_visibility', 10, 2 );
		$this->filter( 'elementor/frontend/container/should_render', '@check_visibility', 10, 2 );

		// Register Appress-owned widgets. `elementor/widgets/register` is the
		// post-3.5 API; `register()` takes a widget instance and Elementor
		// handles lifecycle + editor preview for us. Asset loading is declared
		// per-widget via `get_script_depends()` / `get_style_depends()` —
		// Elementor auto-enqueues them in both frontend AND the preview iframe,
		// no manual hook needed.
		$this->on( 'elementor/widgets/register', '@register_widgets', 10, 1 );

		// Dedicated "Appress" panel category so our widgets cluster together
		// instead of polluting the generic bucket. Must register before widgets
		// are built — `elementor/elements/categories_registered` fires exactly
		// at the right point.
		$this->on( 'elementor/elements/categories_registered', '@register_category', 10, 1 );

		// Brand-icon CSS for the Elementor editor PANEL (the left widget
		// list).
		//
		// Elementor's editor request rebuilds the head pipeline from
		// scratch: `Editor::init()` calls `remove_all_actions('wp_head')`
		// + `remove_all_actions('wp_enqueue_scripts')` (see
		// `core/editor/editor.php` ~line 123-141), then re-adds only
		// Elementor's own `enqueue_styles()` callback at priority
		// 999999. So the only hook that reliably runs INSIDE the panel
		// admin window's enqueue pass is one that Elementor itself
		// fires from inside that callback:
		// `do_action('elementor/editor/before_enqueue_styles')` /
		// `after_enqueue_styles` (`editor.php:385` + `:409`).
		// The output is then printed through Elementor's surviving
		// `wp_head → wp_print_styles` chain (`editor.php:130`).
		// `admin_enqueue_scripts` does NOT fire here because the editor
		// short-circuits the standard admin enqueue flow.
		// `elementor/preview/enqueue_styles` is for the canvas iframe,
		// not the panel — wrong target for widget-list icons.
		$this->on( 'elementor/editor/after_enqueue_styles', '@enqueue_editor_icon_style' );

		// Native-app CSS rules specific to Elementor — hooks into the
		// `appress/app/css` filter so the rules ship via boot payload
		// and get injected at documentStart in every WebView.
		new Controllers\App_Css_Controller();
	}

	public function register_widgets( $widgets_manager ) {
		if ( ! $widgets_manager || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}
		$widgets_manager->register( new \Appress\Integration\Elementor\Widgets\Notifications_Widget() );
		$widgets_manager->register( new \Appress\Integration\Elementor\Widgets\Biometric_Widget() );
		$widgets_manager->register( new \Appress\Integration\Elementor\Widgets\Account_Deletion_Widget() );
		$widgets_manager->register( new \Appress\Integration\Elementor\Widgets\Apple_Login_Widget() );
		$widgets_manager->register( new \Appress\Integration\Elementor\Widgets\Qr_Login_Widget() );
		$widgets_manager->register( new \Appress\Integration\Elementor\Widgets\Qr_Scanner_Widget() );
		$widgets_manager->register( new \Appress\Integration\Elementor\Widgets\Back_Button_Widget() );
		$widgets_manager->register( new \Appress\Integration\Elementor\Widgets\Menu_Toggle_Widget() );
		$widgets_manager->register( new \Appress\Integration\Elementor\Widgets\Status_Bar_Height_Widget() );
		$widgets_manager->register( new \Appress\Integration\Elementor\Widgets\Dismiss_First_Launch_Widget() );

		// TranslatePress switcher — only register when the TRP plugin is
		// active. Hides the widget from the Elementor panel on sites that
		// don't use TRP, instead of registering a stub that would render
		// nothing in preview.
		if ( class_exists( '\TRP_Translate_Press' ) ) {
			$widgets_manager->register( new \Appress\Integration\Elementor\Widgets\Translatepress_Switcher_Widget() );
		}
	}

	public function register_category( $elements_manager ) {
		$elements_manager->add_category(
			'appress',
			[
				'title' => esc_html__( 'Appress', 'appress' ),
				'icon'  => 'eicon-apps',
			]
		);
	}

	public function enqueue_editor_icon_style() {
		// Hook is `elementor/editor/after_enqueue_styles` — only fires
		// inside the editor request, no extra gate needed. URL is passed
		// directly to `wp_enqueue_style` (no separate `wp_register_style`
		// step) so we don't depend on registration timing.
		wp_enqueue_style(
			'appress:elementor-editor-icon',
			APPRESS_PLUGIN_URL . 'assets/css/builder-icon.css',
			[],
			\Appress\get_assets_version()
		);
	}
 
	public function register_controls( $element, $args = null ) {
		$element->start_controls_section(
			'appress_visibility',
			[
				'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
				'label' => __( 'Appress', 'appress' ),
			]
		);

		$element->add_control( 'appress_enable', [
			'label'        => __( 'Visibility condition', 'appress' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'label_on'     => __( 'On', 'appress' ),
			'label_off'    => __( 'Off', 'appress' ),
			'return_value' => 'yes',
			'default'      => '',
			'description'  => __( 'Show or hide this element based on whether the visitor is inside the Appress mobile app.', 'appress' ),
		] );

		$element->add_control( 'appress_action', [
			'label'     => __( 'Action when conditions match', 'appress' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'options'   => [
				'show' => __( 'Show', 'appress' ),
				'hide' => __( 'Hide', 'appress' ),
			],
			'default'   => 'show',
			'condition' => [ 'appress_enable' => 'yes' ],
		] );

		$repeater = new \Elementor\Repeater();
		$repeater->add_control( 'match', [
			'label'   => __( 'Match', 'appress' ),
			'type'    => \Elementor\Controls_Manager::SELECT,
			'default' => 'in_appress_app',
			'options' => [
				'in_appress_app'     => __( 'In Appress app (any platform)', 'appress' ),
				'in_appress_android' => __( 'In Appress app (Android)', 'appress' ),
				'in_appress_ios'     => __( 'In Appress app (iOS)', 'appress' ),
			],
		] );
		$repeater->add_control( 'app_id', [
			'label'       => __( 'App ID (optional)', 'appress' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'placeholder' => __( 'Leave empty for any app', 'appress' ),
			'description' => __( 'Restrict to one specific Appress app id; leave blank to match every app.', 'appress' ),
		] );

		$element->add_control( 'appress_conditions', [
			'label'       => __( 'Conditions (any match passes)', 'appress' ),
			'type'        => \Elementor\Controls_Manager::REPEATER,
			'fields'      => $repeater->get_controls(),
			'default'     => [
				[ 'match' => 'in_appress_app', 'app_id' => '' ],
			],
			'title_field' => '{{{ match }}}{{ app_id ? " · " + app_id : "" }}',
			'condition'   => [ 'appress_enable' => 'yes' ],
		] );

		$element->end_controls_section();
	}

	/**
	 * Filter: returning false hides the element entirely — Elementor short-circuits
	 * before any markup is produced, so it is server-side and cache-friendly.
	 */
	public function check_visibility( $should_render, $element ) {
		if ( ! $should_render ) {
			return false; // Don't override an upstream hide.
		}

		$settings = $element->get_settings_for_display();
		if ( empty( $settings['appress_enable'] ) || $settings['appress_enable'] !== 'yes' ) {
			return $should_render;
		}

		$action     = isset( $settings['appress_action'] ) && $settings['appress_action'] === 'hide' ? 'hide' : 'show';
		$conditions = isset( $settings['appress_conditions'] ) && is_array( $settings['appress_conditions'] ) ? $settings['appress_conditions'] : [];

		$matched = $this->any_condition_matches( $conditions );

		// show + match → render; show + no match → hide.
		// hide + match → hide;   hide + no match → render.
		if ( $action === 'show' ) {
			return $matched;
		}
		return ! $matched;
	}

	private function any_condition_matches( array $conditions ) {
		if ( empty( $conditions ) ) {
			return false;
		}
		// Bail fast if not an Appress request at all — saves looping conditions.
		if ( ! \Appress\is_app() ) {
			return false;
		}
		foreach ( $conditions as $cond ) {
			if ( $this->condition_matches( (array) $cond ) ) {
				return true;
			}
		}
		return false;
	}

	private function condition_matches( array $cond ) {
		$match  = isset( $cond['match'] ) ? (string) $cond['match'] : 'in_appress_app';
		$app_id = isset( $cond['app_id'] ) ? (int) trim( (string) $cond['app_id'] ) : 0;

		switch ( $match ) {
			case 'in_appress_android':
				return \Appress\is_android( $app_id );
			case 'in_appress_ios':
				return \Appress\is_ios( $app_id );
			case 'in_appress_app':
			default:
				return \Appress\is_app( $app_id );
		}
	}
}
