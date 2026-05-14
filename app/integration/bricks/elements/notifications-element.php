<?php
/**
 * Bricks element: Appress Notifications feed.
 *
 * Loaded via `\Bricks\Elements::register_element( __FILE__ )` from
 * Bricks_Controller::register_bricks_element(). Controls mirror the
 * `[appress_notifications]` shortcode atts so both surfaces behave the same.
 *
 * Bricks registers elements by file path (not class name) and reads the
 * `$name` property off the class — there's no autoloader shortcut, so this
 * file is required directly.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bricks may require-once this file before its base element class is available
// on very early boots. Skip silently in that case — Bricks will retry later.
if ( ! class_exists( '\Bricks\Element' ) ) {
	return;
}

class Appress_Notifications_Element extends \Bricks\Element {

	public $category = 'appress';
	public $name     = 'appress-notifications';
	public $icon     = 'appress-icon';

	public function get_label() {
		return esc_html__( 'Notifications', 'appress' );
	}

	public function get_keywords() {
		return [ 'appress', 'notifications', 'feed', 'bell', 'push' ];
	}

	/**
	 * Bricks-native asset declaration. Runs when the element is on the page
	 * (frontend AND in the builder iframe preview), so the feed bundle ships
	 * exactly when it's needed — no `wp_enqueue_script` inside render(), no
	 * elementor-style `elementor/preview/enqueue_scripts` hack.
	 *
	 * Handles are registered centrally by Assets_Controller; we just opt in
	 * per element the same way Bricks' own elements do.
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( \Appress\Controllers\Notifications\Controller::CSS_HANDLE );
		wp_enqueue_script( \Appress\Controllers\Notifications\Controller::JS_HANDLE );
	}

	public function set_control_groups() {
		// Groups mirror the Elementor widget's component-based sections,
		// so admins switching builders see the same logical layout.
		$this->control_groups['content'] = [ 'title' => esc_html__( 'Content',          'appress' ), 'tab' => 'content' ];
		$this->control_groups['header']  = [ 'title' => esc_html__( 'Header',           'appress' ), 'tab' => 'style'   ];
		$this->control_groups['item']    = [ 'title' => esc_html__( 'List item',        'appress' ), 'tab' => 'style'   ];
		$this->control_groups['more']    = [ 'title' => esc_html__( 'Load more button', 'appress' ), 'tab' => 'style'   ];
		$this->control_groups['empty']   = [ 'title' => esc_html__( 'Empty state',      'appress' ), 'tab' => 'style'   ];
	}

	public function set_controls() {
		// ── Content ──────────────────────────────────────────────────────
		$this->controls['limit'] = [
			'tab'         => 'content',
			'group'       => 'content',
			'label'       => esc_html__( 'Items per page', 'appress' ),
			'type'        => 'number',
			'default'     => 10,
			'min'         => 1,
			'max'         => 50,
			'description' => esc_html__( 'How many items to load per page. "Load more" fetches the next batch.', 'appress' ),
		];
		$this->controls['empty'] = [
			'tab'         => 'content',
			'group'       => 'content',
			'label'       => esc_html__( 'Empty state text', 'appress' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'No notifications yet.', 'appress' ),
		];
		$this->controls['mark_all'] = [
			'tab'     => 'content',
			'group'   => 'content',
			'label'   => esc_html__( 'Show "Mark all read"', 'appress' ),
			'type'    => 'checkbox',
			'default' => true,
		];
		$this->controls['clear_all'] = [
			'tab'     => 'content',
			'group'   => 'content',
			'label'   => esc_html__( 'Show "Clear all"', 'appress' ),
			'type'    => 'checkbox',
			'default' => true,
		];
		$this->controls['max_height'] = [
			'tab'         => 'content',
			'group'       => 'content',
			'label'       => esc_html__( 'Max height', 'appress' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'e.g. 480px, 70vh', 'appress' ),
			'description' => esc_html__( 'CSS length (scrolls beyond this). Empty = no scroll.', 'appress' ),
			'css'         => [[ 'property' => '--appress-notifications-max-height', 'selector' => '.appress-notifications' ]],
		];

		// ── Header (title + actions) ─────────────────────────────────────
		$this->controls['title_sep'] = [
			'tab' => 'style', 'group' => 'header',
			'type' => 'separator', 'label' => esc_html__( 'Title (unread count)', 'appress' ),
		];
		$this->controls['title_color'] = [
			'tab'   => 'style', 'group' => 'header',
			'label' => esc_html__( 'Color', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'color', 'selector' => '.appress-notifications__title' ]],
		];
		$this->controls['title_typography'] = [
			'tab'   => 'style', 'group' => 'header',
			'label' => esc_html__( 'Typography', 'appress' ),
			'type'  => 'typography',
			'css'   => [[ 'property' => 'font', 'selector' => '.appress-notifications__title' ]],
		];

		$this->controls['actions_sep'] = [
			'tab' => 'style', 'group' => 'header',
			'type' => 'separator', 'label' => esc_html__( 'Actions', 'appress' ),
		];
		$this->controls['actions_typography'] = [
			'tab'   => 'style', 'group' => 'header',
			'label' => esc_html__( 'Typography (shared)', 'appress' ),
			'type'  => 'typography',
			'css'   => [[ 'property' => 'font', 'selector' => '.appress-notifications__btn' ]],
		];
		$this->controls['mark_all_color'] = [
			'tab'   => 'style', 'group' => 'header',
			'label' => esc_html__( 'Mark all — Color', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'color', 'selector' => '.appress-notifications__mark-all' ]],
		];
		$this->controls['clear_all_color'] = [
			'tab'   => 'style', 'group' => 'header',
			'label' => esc_html__( 'Clear all — Color', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'color', 'selector' => '.appress-notifications__clear-all' ]],
		];

		// ── List item (padding + state bg + icon + text layers + delete) ──
		$this->controls['item_padding'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Padding', 'appress' ),
			'type'  => 'dimensions',
			'css'   => [[ 'property' => 'padding', 'selector' => '.appress-notifications__link' ]],
		];
		$this->controls['item_hover_bg'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Hover background', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'background-color', 'selector' => '.appress-notifications__item:hover' ]],
		];
		$this->controls['item_unread_bg'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Unread background', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'background-color', 'selector' => '.appress-notifications__item--unread' ]],
		];
		$this->controls['item_divider'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Divider', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'border-bottom-color', 'selector' => '.appress-notifications__item' ]],
		];

		$this->controls['icon_sep'] = [
			'tab' => 'style', 'group' => 'item',
			'type' => 'separator', 'label' => esc_html__( 'Icon', 'appress' ),
		];
		$this->controls['icon_size'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Size', 'appress' ),
			'type'  => 'number', 'units' => true, 'min' => 24, 'max' => 80,
			// Width+height+flex-basis on the same selector keeps the 1:1
			// ratio as the admin drags the slider.
			'css'   => [
				[ 'property' => 'flex-basis', 'selector' => '.appress-notifications__media' ],
				[ 'property' => 'width',      'selector' => '.appress-notifications__media' ],
				[ 'property' => 'height',     'selector' => '.appress-notifications__media' ],
			],
		];
		$this->controls['icon_radius'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Corner radius', 'appress' ),
			'type'  => 'number', 'units' => true, 'min' => 0, 'max' => 50,
			'css'   => [[ 'property' => 'border-radius', 'selector' => '.appress-notifications__media' ]],
		];

		$this->controls['subject_sep'] = [
			'tab' => 'style', 'group' => 'item',
			'type' => 'separator', 'label' => esc_html__( 'Subject', 'appress' ),
		];
		$this->controls['subject_color'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Color', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'color', 'selector' => '.appress-notifications__subject' ]],
		];
		$this->controls['subject_typography'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Typography', 'appress' ),
			'type'  => 'typography',
			'css'   => [[ 'property' => 'font', 'selector' => '.appress-notifications__subject' ]],
		];

		$this->controls['body_sep'] = [
			'tab' => 'style', 'group' => 'item',
			'type' => 'separator', 'label' => esc_html__( 'Body preview', 'appress' ),
		];
		$this->controls['body_color'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Color', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'color', 'selector' => '.appress-notifications__body' ]],
		];
		$this->controls['body_typography'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Typography', 'appress' ),
			'type'  => 'typography',
			'css'   => [[ 'property' => 'font', 'selector' => '.appress-notifications__body' ]],
		];

		$this->controls['time_sep'] = [
			'tab' => 'style', 'group' => 'item',
			'type' => 'separator', 'label' => esc_html__( 'Timestamp', 'appress' ),
		];
		$this->controls['time_color'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Color', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'color', 'selector' => '.appress-notifications__time' ]],
		];
		$this->controls['time_typography'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Typography', 'appress' ),
			'type'  => 'typography',
			'css'   => [[ 'property' => 'font', 'selector' => '.appress-notifications__time' ]],
		];

		$this->controls['delete_sep'] = [
			'tab' => 'style', 'group' => 'item',
			'type' => 'separator', 'label' => esc_html__( 'Delete button (×)', 'appress' ),
		];
		$this->controls['delete_icon_size'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Icon size', 'appress' ),
			'type'  => 'number', 'units' => true, 'min' => 10, 'max' => 40,
			'css'   => [[ 'property' => 'font-size', 'selector' => '.appress-notifications__delete' ]],
		];
		$this->controls['delete_color'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Color', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'color', 'selector' => '.appress-notifications__delete' ]],
		];
		$this->controls['delete_color_hover'] = [
			'tab'   => 'style', 'group' => 'item',
			'label' => esc_html__( 'Color (hover)', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'color', 'selector' => '.appress-notifications__delete:hover' ]],
		];

		// ── Load more button ─────────────────────────────────────────────
		$this->controls['more_typography'] = [
			'tab'   => 'style', 'group' => 'more',
			'label' => esc_html__( 'Typography', 'appress' ),
			'type'  => 'typography',
			'css'   => [[ 'property' => 'font', 'selector' => '.appress-notifications__more' ]],
		];
		$this->controls['more_radius'] = [
			'tab'   => 'style', 'group' => 'more',
			'label' => esc_html__( 'Border radius', 'appress' ),
			'type'  => 'dimensions',
			'css'   => [[ 'property' => 'border-radius', 'selector' => '.appress-notifications__more' ]],
		];
		$this->controls['more_border'] = [
			'tab'   => 'style', 'group' => 'more',
			'label' => esc_html__( 'Border', 'appress' ),
			'type'  => 'border',
			'css'   => [[ 'property' => 'border', 'selector' => '.appress-notifications__more' ]],
		];
		$this->controls['more_normal_sep'] = [
			'tab' => 'style', 'group' => 'more',
			'type' => 'separator', 'label' => esc_html__( 'Normal', 'appress' ),
		];
		$this->controls['more_bg'] = [
			'tab'   => 'style', 'group' => 'more',
			'label' => esc_html__( 'Background', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'background-color', 'selector' => '.appress-notifications__more' ]],
		];
		$this->controls['more_text'] = [
			'tab'   => 'style', 'group' => 'more',
			'label' => esc_html__( 'Text', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'color', 'selector' => '.appress-notifications__more' ]],
		];
		$this->controls['more_hover_sep'] = [
			'tab' => 'style', 'group' => 'more',
			'type' => 'separator', 'label' => esc_html__( 'Hover', 'appress' ),
		];
		$this->controls['more_bg_hover'] = [
			'tab'   => 'style', 'group' => 'more',
			'label' => esc_html__( 'Background', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'background-color', 'selector' => '.appress-notifications__more:hover' ]],
		];
		$this->controls['more_text_hover'] = [
			'tab'   => 'style', 'group' => 'more',
			'label' => esc_html__( 'Text', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'color', 'selector' => '.appress-notifications__more:hover' ]],
		];
		$this->controls['more_border_color_hover'] = [
			'tab'   => 'style', 'group' => 'more',
			'label' => esc_html__( 'Border color', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'border-color', 'selector' => '.appress-notifications__more:hover' ]],
		];

		// ── Empty state ──────────────────────────────────────────────────
		$this->controls['empty_color'] = [
			'tab'   => 'style', 'group' => 'empty',
			'label' => esc_html__( 'Color', 'appress' ),
			'type'  => 'color',
			'css'   => [[ 'property' => 'color', 'selector' => '.appress-notifications__empty' ]],
		];
		$this->controls['empty_typography'] = [
			'tab'   => 'style', 'group' => 'empty',
			'label' => esc_html__( 'Typography', 'appress' ),
			'type'  => 'typography',
			'css'   => [[ 'property' => 'font', 'selector' => '.appress-notifications__empty' ]],
		];
	}

	public function render() {
		$s = $this->settings;
		// Builder canvas ⇒ demo=yes (sample items). Frontend + "Save & view"
		// preview ⇒ demo=no (real list). Bricks ships several overlapping
		// detection helpers depending on version — cover all of them so the
		// check works on 1.5+, 1.8+, and REST-based builds without guessing.
		$is_builder = $this->is_bricks_builder_context();
		$html = \Appress\Controllers\Notifications\Controller::render( [
			'limit'      => isset( $s['limit'] ) ? (int) $s['limit'] : 10,
			'empty'      => isset( $s['empty'] ) ? (string) $s['empty'] : '',
			'mark_all'   => ! empty( $s['mark_all'] )  ? 'yes' : 'no',
			'clear_all'  => ! empty( $s['clear_all'] ) ? 'yes' : 'no',
			'max_height' => isset( $s['max_height'] ) ? (string) $s['max_height'] : '',
			'demo'       => $is_builder ? 'yes' : 'no',
		] );
		// Wrap in the Bricks root div so `brxe-<id>` scoping exists for the
		// dynamic CSS Bricks generates from every control's `css` key. Without
		// the wrapper, rules like `.brxe-<id> .appress-notifications { … }`
		// would have no element to anchor onto — styling would silently fail.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "<div {$this->render_attributes( '_root' )}>{$html}</div>";
	}

	/**
	 * True when this render call is happening inside the Bricks builder — the
	 * outer builder window, the canvas preview iframe, OR the AJAX/REST call
	 * Bricks makes to rehydrate a dropped element. Returns false on the public
	 * frontend and on the "Save & view" preview so live visitors see the real
	 * feed, not the sample data.
	 */
	private function is_bricks_builder_context() {
		// Legacy helper (Bricks ≤ 1.7) — iframe-only flag, true while the
		// canvas <iframe> is loading its preview page.
		if ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() ) {
			return true;
		}
		// Outer builder window (the host page that wraps the canvas iframe).
		if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) {
			return true;
		}
		// Umbrella helper in newer builds — true for EITHER iframe or main.
		if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
			return true;
		}
		// REST API render (Bricks 1.8+): the builder fetches per-element HTML
		// through `/bricks/v1/render_element`, not admin-ajax. The REST route
		// sets BRICKS_RENDER_ELEMENT_CONTEXT on current endpoint.
		if ( class_exists( '\Bricks\Api' ) ) {
			if ( method_exists( '\Bricks\Api', 'is_current_endpoint_render_element' ) && \Bricks\Api::is_current_endpoint_render_element() ) {
				return true;
			}
			if ( method_exists( '\Bricks\Api', 'is_current_endpoint' ) ) {
				try {
					if ( \Bricks\Api::is_current_endpoint( 'render_element' ) ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					// Signature changed across versions — swallow + fall through.
				}
			}
		}
		// Admin-ajax fallback — legacy Bricks (< 1.5) renders elements via
		// admin-ajax.php with action prefixed `bricks_`.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && strpos( sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ), 'bricks_' ) === 0 ) {
			return true;
		}
		return false;
	}
}
