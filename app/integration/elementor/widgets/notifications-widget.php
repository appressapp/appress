<?php
namespace Appress\Integration\Elementor\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget: drops the Appress notifications feed onto any page/section/
 * template without touching the `[appress_notifications]` shortcode. Controls
 * mirror the shortcode atts 1:1 so both surfaces stay behaviorally identical.
 *
 * Render delegates to `\Appress\Controllers\Notifications\Controller::render()`
 * — the same skeleton + data-attrs the shortcode emits — which means the
 * CSS/JS enqueue + feed mount logic is shared (no duplicate asset wiring).
 */
class Notifications_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'appress-notifications';
	}

	public function get_title() {
		return __( 'Notifications', 'appress' );
	}

	public function get_icon() {
		return 'appress-icon';
	}

	public function get_categories() {
		return [ 'appress' ];
	}

	public function get_keywords() {
		return [ 'appress', 'notifications', 'feed', 'bell', 'push' ];
	}

	/**
	 * Declaring the handles here is the canonical Elementor way — it loads
	 * them in the editor preview iframe AND on the frontend automatically,
	 * and Elementor handles the "only enqueue when widget is on the page"
	 * optimization for us. No manual `wp_enqueue_*` in render(), no
	 * `elementor/preview/enqueue_scripts` hack.
	 */
	public function get_style_depends() {
		return [ \Appress\Controllers\Notifications\Controller::CSS_HANDLE ];
	}

	public function get_script_depends() {
		return [ \Appress\Controllers\Notifications\Controller::JS_HANDLE ];
	}

	protected function register_controls() {
		// ── Content ────────────────────────────────────────────────────────
		// Sections organised BY COMPONENT — each Style section maps 1:1 to
		// a DOM element the feed renders. Panel-level chrome (background,
		// border, radius, padding) stays in notifications-feed.css as sensible
		// defaults and is intentionally NOT exposed as controls.

		// ── Content ──────────────────────────────────────────────────────
		$this->start_controls_section( 'section_content', [
			'label' => __( 'Content', 'appress' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'limit', [
			'label'       => __( 'Items per page', 'appress' ),
			'type'        => \Elementor\Controls_Manager::NUMBER,
			'default'     => 10,
			'min'         => 1,
			'max'         => 50,
			'step'        => 1,
			'description' => __( 'How many items to load per page. "Load more" fetches the next batch.', 'appress' ),
		] );

		$this->add_control( 'empty', [
			'label'       => __( 'Empty state text', 'appress' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'placeholder' => __( 'No notifications yet.', 'appress' ),
		] );

		$this->add_control( 'mark_all', [
			'label'        => __( 'Show "Mark all read"', 'appress' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => 'yes',
		] );

		$this->add_control( 'clear_all', [
			'label'        => __( 'Show "Clear all"', 'appress' ),
			'type'         => \Elementor\Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => 'yes',
		] );

		$this->add_control( 'max_height', [
			'label'       => __( 'Max height', 'appress' ),
			'type'        => \Elementor\Controls_Manager::TEXT,
			'placeholder' => __( 'e.g. 480px, 70vh', 'appress' ),
			'description' => __( 'CSS length (scrolls beyond this). Empty = no scroll.', 'appress' ),
			'selectors'   => [
				'{{WRAPPER}} .appress-notifications' => '--appress-notifications-max-height: {{VALUE}};',
			],
		] );

		$this->end_controls_section();

		// ── Header ───────────────────────────────────────────────────────
		// Title (unread count) + actions (Mark all / Clear all) — all
		// top-bar styling lives here. Actions share typography; per-label
		// color. Not styled as buttons (product choice: text-link look).
		$this->start_controls_section( 'section_style_header', [
			'label' => __( 'Header', 'appress' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		] );

		$this->add_control( 'heading_header_title', [
			'label' => __( 'Title (unread count)', 'appress' ),
			'type'  => \Elementor\Controls_Manager::HEADING,
		] );
		$this->add_control( 'title_color', [
			'label'     => __( 'Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__title' => 'color: {{VALUE}};' ],
		] );
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[ 'name' => 'title_typography', 'selector' => '{{WRAPPER}} .appress-notifications__title' ]
		);

		$this->add_control( 'heading_header_actions', [
			'label'     => __( 'Actions', 'appress' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		] );
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[ 'name' => 'actions_typography', 'selector' => '{{WRAPPER}} .appress-notifications__btn' ]
		);
		$this->add_control( 'mark_all_color', [
			'label'     => __( 'Mark all — Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__mark-all' => 'color: {{VALUE}};' ],
		] );
		$this->add_control( 'clear_all_color', [
			'label'     => __( 'Clear all — Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__clear-all' => 'color: {{VALUE}};' ],
		] );

		$this->end_controls_section();

		// ── List item ────────────────────────────────────────────────────
		// Everything a single row owns: padding, state backgrounds,
		// divider, icon, three text layers, and the delete (×) button.
		$this->start_controls_section( 'section_style_item', [
			'label' => __( 'List item', 'appress' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		] );

		$this->add_control( 'item_padding', [
			'label'      => __( 'Padding', 'appress' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', 'em', 'rem' ],
			'selectors'  => [
				'{{WRAPPER}} .appress-notifications__link' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
			],
		] );
		$this->add_control( 'item_hover_bg', [
			'label'     => __( 'Hover background', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__item:hover' => 'background-color: {{VALUE}};' ],
		] );
		$this->add_control( 'item_unread_bg', [
			'label'     => __( 'Unread background', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__item--unread' => 'background-color: {{VALUE}};' ],
		] );
		$this->add_control( 'item_divider', [
			'label'     => __( 'Divider', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__item' => 'border-bottom-color: {{VALUE}};' ],
		] );

		$this->add_control( 'heading_icon', [
			'label'     => __( 'Icon', 'appress' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		] );
		$this->add_control( 'icon_size', [
			'label'      => __( 'Size', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 24, 'max' => 80 ] ],
			'selectors'  => [
				'{{WRAPPER}} .appress-notifications__media' => 'flex-basis: {{SIZE}}{{UNIT}}; width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
			],
		] );
		$this->add_control( 'icon_radius', [
			'label'      => __( 'Corner radius', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px', '%' ],
			'range'      => [ 'px' => [ 'min' => 0, 'max' => 50 ], '%' => [ 'min' => 0, 'max' => 50 ] ],
			'selectors'  => [ '{{WRAPPER}} .appress-notifications__media' => 'border-radius: {{SIZE}}{{UNIT}};' ],
		] );

		$this->add_control( 'heading_subject', [
			'label'     => __( 'Subject', 'appress' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		] );
		$this->add_control( 'subject_color', [
			'label'     => __( 'Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__subject' => 'color: {{VALUE}};' ],
		] );
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[ 'name' => 'subject_typography', 'selector' => '{{WRAPPER}} .appress-notifications__subject' ]
		);

		$this->add_control( 'heading_body', [
			'label'     => __( 'Body preview', 'appress' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		] );
		$this->add_control( 'body_color', [
			'label'     => __( 'Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__body' => 'color: {{VALUE}};' ],
		] );
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[ 'name' => 'body_typography', 'selector' => '{{WRAPPER}} .appress-notifications__body' ]
		);

		$this->add_control( 'heading_time', [
			'label'     => __( 'Timestamp', 'appress' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		] );
		$this->add_control( 'time_color', [
			'label'     => __( 'Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__time' => 'color: {{VALUE}};' ],
		] );
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[ 'name' => 'time_typography', 'selector' => '{{WRAPPER}} .appress-notifications__time' ]
		);

		$this->add_control( 'heading_delete', [
			'label'     => __( 'Delete button (×)', 'appress' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		] );
		$this->add_control( 'delete_icon_size', [
			'label'      => __( 'Icon size', 'appress' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 10, 'max' => 40 ] ],
			'selectors'  => [ '{{WRAPPER}} .appress-notifications__delete' => 'font-size: {{SIZE}}{{UNIT}};' ],
		] );
		$this->start_controls_tabs( 'tabs_delete' );
		$this->start_controls_tab( 'tab_delete_normal', [ 'label' => __( 'Normal', 'appress' ) ] );
		$this->add_control( 'delete_color', [
			'label'     => __( 'Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__delete' => 'color: {{VALUE}};' ],
		] );
		$this->end_controls_tab();
		$this->start_controls_tab( 'tab_delete_hover', [ 'label' => __( 'Hover', 'appress' ) ] );
		$this->add_control( 'delete_color_hover', [
			'label'     => __( 'Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__delete:hover' => 'color: {{VALUE}};' ],
		] );
		$this->end_controls_tab();
		$this->end_controls_tabs();

		$this->end_controls_section();

		// ── Load more button ─────────────────────────────────────────────
		$this->start_controls_section( 'section_style_more', [
			'label' => __( 'Load more button', 'appress' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		] );

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[ 'name' => 'more_typography', 'selector' => '{{WRAPPER}} .appress-notifications__more' ]
		);
		$this->add_control( 'more_radius', [
			'label'      => __( 'Border radius', 'appress' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', '%', 'rem' ],
			'selectors'  => [ '{{WRAPPER}} .appress-notifications__more' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
		] );
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[ 'name' => 'more_border', 'selector' => '{{WRAPPER}} .appress-notifications__more' ]
		);

		$this->start_controls_tabs( 'tabs_more' );

		$this->start_controls_tab( 'tab_more_normal', [ 'label' => __( 'Normal', 'appress' ) ] );
		$this->add_control( 'more_bg', [
			'label'     => __( 'Background', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__more' => 'background-color: {{VALUE}};' ],
		] );
		$this->add_control( 'more_text', [
			'label'     => __( 'Text', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__more' => 'color: {{VALUE}};' ],
		] );
		$this->end_controls_tab();

		$this->start_controls_tab( 'tab_more_hover', [ 'label' => __( 'Hover', 'appress' ) ] );
		$this->add_control( 'more_bg_hover', [
			'label'     => __( 'Background', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__more:hover' => 'background-color: {{VALUE}};' ],
		] );
		$this->add_control( 'more_text_hover', [
			'label'     => __( 'Text', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__more:hover' => 'color: {{VALUE}};' ],
		] );
		$this->add_control( 'more_border_color_hover', [
			'label'     => __( 'Border', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__more:hover' => 'border-color: {{VALUE}};' ],
		] );
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		// ── Empty state ──────────────────────────────────────────────────
		$this->start_controls_section( 'section_style_empty', [
			'label' => __( 'Empty state', 'appress' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		] );

		$this->add_control( 'empty_color', [
			'label'     => __( 'Color', 'appress' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .appress-notifications__empty' => 'color: {{VALUE}};' ],
		] );
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			[ 'name' => 'empty_typography', 'selector' => '{{WRAPPER}} .appress-notifications__empty' ]
		);

		$this->end_controls_section();
	}

	protected function render() {
		$s = $this->get_settings_for_display();
		// Controller::render() esc_attr-encodes these at output time —
		// pre-escaping here would double-encode user-entered text.
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \Appress\Controllers\Notifications\Controller::render( [
			'limit'      => isset( $s['limit'] ) ? (int) $s['limit'] : 10,
			'empty'      => isset( $s['empty'] ) ? (string) $s['empty'] : '',
			'mark_all'   => isset( $s['mark_all'] )  && $s['mark_all']  === 'yes' ? 'yes' : 'no',
			'clear_all'  => isset( $s['clear_all'] ) && $s['clear_all'] === 'yes' ? 'yes' : 'no',
			'max_height' => isset( $s['max_height'] ) ? (string) $s['max_height'] : '',
			// Editor canvas previews look broken when the feed is empty (admin
			// usually has zero notifications on a dev site). Demo mode skips
			// the AJAX fetch and renders sample items so spacing / typography
			// / colors are visible while editing. Frontend & "Save & view"
			// preview use the real list.
			'demo'       => $this->is_elementor_editor() ? 'yes' : 'no',
		] );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function is_elementor_editor() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['elementor-preview'] ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'elementor' ) {
			return true;
		}
		$plugin = \Elementor\Plugin::$instance;
		if ( isset( $plugin->editor ) && method_exists( $plugin->editor, 'is_edit_mode' ) && $plugin->editor->is_edit_mode() ) {
			return true;
		}
		if ( isset( $plugin->preview ) && method_exists( $plugin->preview, 'is_preview_mode' ) && $plugin->preview->is_preview_mode() ) {
			return true;
		}
		return false;
	}
}
