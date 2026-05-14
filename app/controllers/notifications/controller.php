<?php
namespace Appress\Controllers\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public notifications feed renderer.
 *
 * Single render entry point — `Controller::render( $atts )` — is called by
 * the `[appress_notifications]` shortcode and (future) Elementor/Bricks
 * widgets. Output is a lean skeleton `<div>` + data-attrs; the client-side
 * bundle `notifications-feed.js` mounts + fetches + renders the list.
 *
 * Asset registration lives in Assets_Controller — this class only provides
 * the per-handle localize payload via `appress/assets/localize/{handle}` and
 * enqueues by handle inside render().
 */
class Controller extends \Appress\Controllers\Base_Controller {

	const CSS_HANDLE = 'appress:notifications-feed.css';
	const JS_HANDLE  = 'appress:notifications-feed.js';

	protected function hooks() {
		$this->on( 'init', '@register_shortcode' );
		// Localize data for `notifications-feed.js` — Assets_Controller calls
		// this filter after `wp_register_script` to drop `AppressNotificationsConfig`
		// onto the window at script load time.
		$this->filter( 'appress/assets/localize/' . self::JS_HANDLE, '@localize_js', 10, 1 );
	}

	protected function register_shortcode() {
		add_shortcode( 'appress_notifications', [ __CLASS__, 'render' ] );
	}

	protected function localize_js( $data ) {
		$data['AppressNotificationsConfig'] = [
			'ajaxUrl' => home_url( '/?appress=1' ),
			// CSRF nonce — verified server-side in the 4 mutating handlers
			// (mark_read, mark_all_read, delete, delete_all).
			'nonce'   => wp_create_nonce( 'appress_notifications' ),
			// Short actionable labels use `_x` so translators know the UI
			// context ("Load more" isn't ambiguous when marked as a
			// pagination button, for instance).
			'i18n'    => [
				'loading'         => __( 'Loading…', 'appress' ),
				'empty'           => __( 'No notifications yet.', 'appress' ),
				'loadMore'        => _x( 'Load more', 'notifications pagination button', 'appress' ),
				'markAllRead'     => _x( 'Mark all read', 'notifications batch action', 'appress' ),
				'clearAll'        => _x( 'Clear all', 'notifications batch action', 'appress' ),
				'clearAllConfirm' => __( 'Delete all notifications? This cannot be undone.', 'appress' ),
				'deleteItem'      => _x( 'Delete notification', 'notification item aria-label', 'appress' ),
				/* translators: %d: number of unread notifications */
				'unreadCount'     => __( '%d unread', 'appress' ),
				'justNow'         => _x( 'just now', 'notification relative time', 'appress' ),
			],
		];
		return $data;
	}

	/**
	 * Render the skeleton for one feed instance. Called by shortcode AND builder
	 * widgets — $atts shape is identical across surfaces so behavior stays
	 * consistent whether the admin pasted a shortcode or dropped an Elementor
	 * widget. Returns '' for logged-out visitors (nothing to show).
	 *
	 * Supported $atts:
	 *   - limit       (int)    per-page batch size [default 10, max 50]
	 *   - empty       (string) empty-state copy
	 *   - mark_all    (yes/no) show the "Mark all read" header button
	 *   - clear_all   (yes/no) show the "Clear all" header button
	 *   - max_height  (string) CSS length — scroll container beyond this
	 *   - class       (string) extra root class(es)
	 *   - demo        (yes/no) skip the AJAX fetch and render hardcoded sample
	 *                          items — used by Elementor / Bricks builders so
	 *                          the editor canvas previews real-looking content
	 *                          instead of an empty/loading state. Mutations
	 *                          (mark/delete) update the UI but skip the POST.
	 */
	public static function render( $atts = [] ) {
		$atts = shortcode_atts( [
			'limit'      => 10,
			'empty'      => '',
			'mark_all'   => 'yes',
			'clear_all'  => 'yes',
			'max_height' => '',
			'class'      => '',
			'demo'       => 'no',
		], (array) $atts, 'appress_notifications' );

		// Guests still render the component: the list endpoint returns an
		// empty success for them (see Ajax_Controller::handle_list), so the
		// JS feed draws its "No notifications yet" empty state cleanly.
		// Just suppress the mutation buttons — mark_all_read / delete_all
		// require login and clicking them as a guest is a dead end.
		// Demo mode skips this — builders preview the FULL UI regardless of
		// who's editing.
		if ( $atts['demo'] !== 'yes' && ! is_user_logged_in() ) {
			$atts['mark_all']  = 'no';
			$atts['clear_all'] = 'no';
		}

		// Assets are registered centrally by Assets_Controller; enqueue by
		// handle on demand so pages without the shortcode pay zero cost.
		wp_enqueue_style( self::CSS_HANDLE );
		wp_enqueue_script( self::JS_HANDLE );

		$limit   = max( 1, min( 50, (int) $atts['limit'] ) );
		$empty   = $atts['empty'] !== '' ? $atts['empty'] : __( 'No notifications yet.', 'appress' );
		$classes = 'appress-notifications' . ( ! empty( $atts['class'] ) ? ' ' . sanitize_html_class( $atts['class'] ) : '' );
		$style   = $atts['max_height'] !== '' ? ' style="--appress-notifications-max-height:' . esc_attr( $atts['max_height'] ) . '"' : '';
		$demo    = $atts['demo'] === 'yes' ? '1' : '0';

		ob_start();
		?>
		<div class="<?php echo esc_attr( $classes ); ?>"<?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>
		     data-limit="<?php echo esc_attr( $limit ); ?>"
		     data-empty="<?php echo esc_attr( $empty ); ?>"
		     data-mark-all="<?php echo esc_attr( $atts['mark_all'] === 'yes' ? '1' : '0' ); ?>"
		     data-clear-all="<?php echo esc_attr( $atts['clear_all'] === 'yes' ? '1' : '0' ); ?>"
		     data-demo="<?php echo esc_attr( $demo ); ?>"></div>
		<?php
		return ob_get_clean();
	}
}
