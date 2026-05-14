<?php
namespace Appress\Controllers\Notifications;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// $table comes from $this->table() which returns $wpdb->prefix . 'appress_notifications'
// — a fixed, hardcoded table name with no user input.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX surface for the notifications feed.
 *
 * List + mark-read + delete all flow through filters so each integration
 * (WooCommerce, Voxel, future add-ons) can own its own notification source
 * without bolting onto the core controller. The default built-in source is
 * the `wp_appress_notifications` table — persisted by `Event::send()` which
 * integrations already use for push notifications.
 *
 * Filter contracts:
 *
 *   `appress/notifications/items` (array $items, array $ctx) → array
 *     Each integration appends (or transforms) items. `$ctx` carries
 *     `user_id`, `cursor`, `limit`. Built-in DB source hooks at priority 5
 *     so integrations at the default 10 can react to DB items if needed.
 *
 *   `appress/notifications/mark_read` (bool $handled, string $id, int $user_id) → bool
 *     Return true when the integration owns the ID and has marked it read.
 *     If no integration handled + the id is numeric, the default DB path fires.
 *
 *   `appress/notifications/delete` (bool $handled, string $id, int $user_id) → bool
 *     Same contract as mark_read.
 *
 *   `appress/notifications/delete_all` (action, int $user_id)
 *   `appress/notifications/mark_all_read` (action, int $user_id)
 *     Fire-and-forget actions. Integrations purge / mark their own store;
 *     the default DB path always also runs to handle built-in rows.
 *
 * Item shape (returned to JS):
 *   id          string   — stable per source (integer for DB rows, prefixed for integrations)
 *   source      string   — 'appress' | 'woocommerce' | 'voxel' | custom
 *   subject     string
 *   body        string
 *   url         string   — click target (can be deep-link)
 *   image       string   — URL or '' (JS falls back to bell SVG when empty)
 *   is_read     bool
 *   created_at  string   — UTC `YYYY-MM-DD HH:MM:SS`
 */
class Ajax_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$endpoints = [ 'list', 'mark_read', 'mark_all_read', 'delete', 'delete_all' ];
		foreach ( $endpoints as $ep ) {
			$method = '@handle_' . $ep;
			$this->on( "appress_ajax_notifications.{$ep}",        $method );
			$this->on( "appress_ajax_nopriv_notifications.{$ep}", $method );
		}

		// Built-in DB source registered at priority 5 so integrations that
		// transform the list (at default 10) see the DB rows in their $items
		// param. If they want to REPLACE the DB items, they can filter them
		// out at their own priority.
		$this->filter( 'appress/notifications/items', '@provide_db_notifications', 5, 2 );
	}

	private function require_user(): int {
		if ( ! is_user_logged_in() ) {
			throw new \Exception( 'Login required.' );
		}
		return (int) get_current_user_id();
	}

	/**
	 * Require login + a valid CSRF nonce. Nonce is minted on shortcode
	 * render (see Notifications\Controller::localize_js) and threaded
	 * through the localized `AppressNotificationsConfig.nonce`. Used by
	 * every mutating endpoint (mark_read / mark_all_read / delete / delete_all)
	 * — the read-only list endpoint skips this since it's safe to hit anonymously.
	 */
	private function require_user_with_nonce(): int {
		$user_id = $this->require_user();
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'appress_notifications' ) ) {
			throw new \Exception( 'Invalid security token.' );
		}
		return $user_id;
	}

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'appress_notifications';
	}

	// ── List ──────────────────────────────────────────────────────────────

	protected function handle_list() {
		try {
			// Guests see an empty feed instead of an auth error — the component
			// renders its "no notifications yet" state cleanly and the bell
			// indicator stays at zero. Mutating endpoints (mark_read, delete,
			// mark_all_read, delete_all) still enforce login via require_user().
			if ( ! is_user_logged_in() ) {
				return wp_send_json( [
					'success' => true,
					'data'    => [
						'items'        => [],
						'has_more'     => false,
						'next_cursor'  => null,
						'unread_count' => 0,
					],
				] );
			}

			$user_id = (int) get_current_user_id();
			$cursor  = isset( $_GET['cursor'] ) ? (int) $_GET['cursor'] : 0;
			$limit   = isset( $_GET['limit'] ) ? max( 1, min( 50, (int) $_GET['limit'] ) ) : 10;

			$ctx = [
				'user_id' => $user_id,
				'cursor'  => $cursor,
				'limit'   => $limit,
			];

			$items = (array) apply_filters( 'appress/notifications/items', [], $ctx );

			// Sort all items by created_at DESC (newest first) regardless of source.
			usort( $items, function ( $a, $b ) {
				return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
			} );

			// Cursor pagination: for DB rows we already filter by id < cursor in
			// the built-in source; for integration rows we trust their ordering
			// and slice by limit. This is good enough when cursor is monotonic
			// w/r/t created_at (true for DB-backed sources).
			$page_items = array_slice( $items, 0, $limit + 1 );
			$has_more   = count( $page_items ) > $limit;
			if ( $has_more ) {
				array_pop( $page_items );
			}

			$next_cursor = null;
			if ( $has_more && ! empty( $page_items ) ) {
				$last = end( $page_items );
				$next_cursor = isset( $last['id'] ) ? $last['id'] : null;
			}

			$unread_count = (int) apply_filters(
				'appress/notifications/unread_count',
				\Appress\Notification::unread_count_db( $user_id ),
				$ctx
			);

			return wp_send_json( [
				'success' => true,
				'data'    => [
					'items'        => array_values( $page_items ),
					'has_more'     => $has_more,
					'next_cursor'  => $next_cursor,
					'unread_count' => $unread_count,
				],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Built-in DB source. Registered at priority 5 on `appress/notifications/items`.
	 * Queries `wp_appress_notifications` (what `Event::send()` writes to) and
	 * normalizes each row into the documented item shape.
	 */
	protected function provide_db_notifications( array $items, array $ctx ): array {
		global $wpdb;
		$user_id = (int) ( $ctx['user_id'] ?? 0 );
		$cursor  = (int) ( $ctx['cursor'] ?? 0 );
		$limit   = (int) ( $ctx['limit'] ?? 10 );
		if ( $user_id <= 0 ) {
			return $items;
		}

		$table = $this->table();
		// Limit+1 so we can tell has_more when the DB is the only source. When
		// integrations add more items, they'll be merged + sliced by handle_list.
		$take = $limit + 1;

		if ( $cursor > 0 ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, title, body, payload, is_read, created_at FROM {$table} WHERE user_id = %d AND id < %d ORDER BY id DESC LIMIT %d",
				$user_id, $cursor, $take
			), ARRAY_A );
		} else {
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, title, body, payload, is_read, created_at FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d",
				$user_id, $take
			), ARRAY_A );
		}
		foreach ( $rows as $row ) {
			$payload = $row['payload'] ? json_decode( $row['payload'], true ) : [];
			if ( ! is_array( $payload ) ) {
				$payload = [];
			}
			$items[] = [
				'id'         => (string) $row['id'],
				'source'     => 'appress',
				'subject'    => (string) $row['title'],
				'body'       => (string) $row['body'],
				'url'        => (string) ( $payload['url'] ?? '' ),
				'image'      => (string) ( $payload['image'] ?? '' ),
				'is_read'    => (int) $row['is_read'] === 1,
				'created_at' => (string) $row['created_at'],
			];
		}
		return $items;
	}

	/**
	 * Authoritative post-mutation unread count — runs the same filter chain as
	 * the feed's list endpoint (DB + every integration that hooks
	 * `appress/notifications/unread_count`). Caller returns this in the
	 * response body so the client can update the indicator without a
	 * secondary `app.get_indicators` round-trip.
	 */
	private function fresh_unread_count( int $user_id ): int {
		$ctx = [ 'user_id' => $user_id ];
		return (int) apply_filters( 'appress/notifications/unread_count', \Appress\Notification::unread_count_db( $user_id ), $ctx );
	}

	/**
	 * Drop the indicator count cache written by `Indicator_Controller::get_counts`
	 * so the next `app.get_indicators` poll (or wp_footer re-inject on the next
	 * page load) reads fresh values. Without this, the cache held stale
	 * unread counts for up to 30s after a mark/delete action.
	 */
	private function invalidate_indicator_cache( int $user_id ): void {
		delete_transient( 'appress_ind_' . $user_id );
	}

	// ── Mark as read ─────────────────────────────────────────────────────

	protected function handle_mark_read() {
		try {
			$user_id = $this->require_user_with_nonce();
			// Nonce verified by require_user_with_nonce() above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
			if ( $id === '' ) {
				throw new \Exception( 'Invalid notification id.' );
			}

			$handled = (bool) apply_filters( 'appress/notifications/mark_read', false, $id, $user_id );
			if ( ! $handled && ctype_digit( $id ) ) {
				global $wpdb;
				$wpdb->update(
					$this->table(),
					[ 'is_read' => 1 ],
					[ 'id' => (int) $id, 'user_id' => $user_id ],
					[ '%d' ],
					[ '%d', '%d' ]
				);
			}

			$this->invalidate_indicator_cache( $user_id );
			return wp_send_json( [
				'success' => true,
				'data'    => [ 'unread_count' => $this->fresh_unread_count( $user_id ) ],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	protected function handle_mark_all_read() {
		try {
			$user_id = $this->require_user_with_nonce();
			do_action( 'appress/notifications/mark_all_read', $user_id );
			global $wpdb;
			$wpdb->update(
				$this->table(),
				[ 'is_read' => 1 ],
				[ 'user_id' => $user_id, 'is_read' => 0 ],
				[ '%d' ],
				[ '%d', '%d' ]
			);
			$this->invalidate_indicator_cache( $user_id );
			return wp_send_json( [
				'success' => true,
				'data'    => [ 'unread_count' => $this->fresh_unread_count( $user_id ) ],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	// ── Delete ───────────────────────────────────────────────────────────

	protected function handle_delete() {
		try {
			$user_id = $this->require_user_with_nonce();
			// Nonce verified by require_user_with_nonce() above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
			if ( $id === '' ) {
				throw new \Exception( 'Invalid notification id.' );
			}

			$handled = (bool) apply_filters( 'appress/notifications/delete', false, $id, $user_id );
			if ( ! $handled && ctype_digit( $id ) ) {
				global $wpdb;
				$wpdb->delete(
					$this->table(),
					[ 'id' => (int) $id, 'user_id' => $user_id ],
					[ '%d', '%d' ]
				);
			}
			$this->invalidate_indicator_cache( $user_id );
			return wp_send_json( [
				'success' => true,
				'data'    => [ 'unread_count' => $this->fresh_unread_count( $user_id ) ],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	protected function handle_delete_all() {
		try {
			$user_id = $this->require_user_with_nonce();
			do_action( 'appress/notifications/delete_all', $user_id );
			global $wpdb;
			$wpdb->delete(
				$this->table(),
				[ 'user_id' => $user_id ],
				[ '%d' ]
			);
			$this->invalidate_indicator_cache( $user_id );
			// Run the filter so integration stores (Voxel) that may still hold
			// unread rows contribute their counts — hard-coding 0 here
			// desynced the bell the moment Voxel started tracking alongside.
			return wp_send_json( [
				'success' => true,
				'data'    => [ 'unread_count' => $this->fresh_unread_count( $user_id ) ],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}
}
