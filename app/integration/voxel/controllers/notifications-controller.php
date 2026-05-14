<?php
namespace Appress\Integration\Voxel\Controllers;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges Voxel's native notification system (`wp_voxel_notifications`,
 * `\Voxel\Notification`) into the Appress notification feed via the
 * `appress/notifications/*` filter contract.
 *
 * Why this exists:
 *   - Voxel maintains its own notification store (the bell dropdown in the
 *     web UI reads from `\Voxel\Notification::query`). When the user marks a
 *     notification as seen inside the app, Voxel's store also has to see
 *     `seen = 1`, otherwise the web bell keeps showing the same dot.
 *   - Voxel renders per-event subjects via `get_subject()` (which runs the
 *     event's dynamic tags through Voxel's template engine). Flattening
 *     that into the feed matches what the user already sees on web.
 *
 * Gate: only loaded when the Voxel integration module is active AND the
 * `\Voxel\Notification` class is present (theme active / compatible version).
 * Safe no-op when either gate fails.
 */
class Notifications_Controller extends Base_Controller {

	/** Prefix applied to every Voxel-sourced notification ID so mark_read /
	 *  delete can cleanly route back to Voxel without colliding with the
	 *  numeric IDs used by the built-in DB source.
	 */
	const ID_PREFIX = 'voxel-';

	protected function hooks() {
		// Defer Voxel-class-gated wiring to `init`. This controller is
		// instantiated at `plugins_loaded:20` (via Integrations_Manager →
		// Voxel_Controller::bootstrap_integrations), and at that point
		// Voxel's theme files haven't loaded yet — `\Voxel\Notification`
		// resolves to false. Registering the filter here directly +
		// gating on class_exists would silently skip wiring on every
		// request and the bell feed would only ever show built-in DB
		// rows, never Voxel's. By init, Voxel is fully loaded.
		$this->on( 'init', '@register_voxel_filters', 999 );
	}

	protected function register_voxel_filters() {
		if ( ! class_exists( '\Voxel\Notification' ) ) {
			return;
		}
		$this->filter( 'appress/notifications/items',        '@provide_items',      10, 2 );
		$this->filter( 'appress/notifications/mark_read',    '@handle_mark_read',   10, 3 );
		$this->filter( 'appress/notifications/delete',       '@handle_delete',      10, 3 );
		$this->filter( 'appress/notifications/unread_count', '@add_unread_count',   10, 2 );
		// mark_all_read / delete_all are actions — not filters — so no return.
		$this->on( 'appress/notifications/mark_all_read', '@handle_mark_all_read' );
		$this->on( 'appress/notifications/delete_all',    '@handle_delete_all' );
	}

	/**
	 * Append Voxel notifications for the current user to the feed. Each entry
	 * normalises to the documented item shape so the JS renderer doesn't need
	 * to care about the source.
	 */
	protected function provide_items( $items, $ctx ) {
		$user_id = (int) ( $ctx['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return $items;
		}
		// Pull enough rows for the caller to do its own sort+slice. Merging
		// across sources happens in Ajax_Controller::handle_list.
		$take = (int) ( $ctx['limit'] ?? 10 ) + 1;

		$voxel_notifs = \Voxel\Notification::query( [
			'user_id' => $user_id,
			'limit'   => $take,
			'order'   => 'desc',
		] );

		foreach ( (array) $voxel_notifs as $n ) {
			if ( ! ( $n instanceof \Voxel\Notification ) ) {
				continue;
			}
			// `is_valid()` weeds out notifications whose event config was
			// removed / disabled since the row was inserted — subject would
			// be null and the item would render blank.
			if ( ! $n->is_valid() ) {
				continue;
			}
			$subject = (string) ( $n->get_subject() ?? '' );
			$url     = (string) ( $n->get_links_to() ?? '' );
			$image   = (string) ( $n->get_image_url() ?? '' );
			if ( $subject === '' ) {
				continue;
			}
			$items[] = [
				'id'         => self::ID_PREFIX . $n->get_id(),
				'source'     => 'voxel',
				'subject'    => wp_strip_all_tags( $subject ),
				'body'       => '',
				'url'        => $url,
				'image'      => $image,
				'is_read'    => (bool) $n->is_seen(),
				'created_at' => (string) $n->get_created_at(),
			];
		}
		return $items;
	}

	/**
	 * Add Voxel's own unread count to the feed total so the badge in the UI
	 * reflects both sources.
	 *
	 * NOTE: we intentionally do NOT use `\Voxel\Notification::get_unread_count`
	 * — despite the name, that method's query has no `seen = 0` filter, it
	 * just counts rows created since a given date. Calling it with `0`
	 * returns the total number of Voxel notifications ever created for the
	 * user, which left the badge stuck at a non-zero value forever even
	 * after the user marked everything read. Direct DB query is the only
	 * safe path.
	 */
	protected function add_unread_count( $count, $ctx ) {
		$user_id = (int) ( $ctx['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return $count;
		}
		global $wpdb;
		// Voxel's own table — no public API to query unread count, must hit DB.
		// Live count, can't cache (changes per page-view as user reads items).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$voxel_unread = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}voxel_notifications WHERE user_id = %d AND seen = 0",
			$user_id
		) );
		return (int) $count + $voxel_unread;
	}

	/**
	 * Claim the mark_read filter for any ID that looks like ours, then route
	 * to Voxel. Return true signals the Ajax controller that the default DB
	 * path should NOT also run for this id.
	 */
	protected function handle_mark_read( $handled, $id, $user_id ) {
		$raw = $this->extract_id( $id );
		if ( $raw === null ) {
			return $handled;
		}
		$n = \Voxel\Notification::get( $raw );
		if ( ! $n || (int) $n->get_user_id() !== (int) $user_id ) {
			return $handled; // not ours, or not owned by caller — let default handle
		}
		$n->update( 'seen', 1 );
		// Voxel's bell count is cached on the user meta — poke it so the web
		// UI reflects the fresh seen state on next page load.
		if ( class_exists( '\Voxel\User' ) ) {
			$user = \Voxel\User::get( $user_id );
			if ( $user && method_exists( $user, 'update_notification_count' ) ) {
				$user->update_notification_count();
			}
		}
		return true;
	}

	protected function handle_delete( $handled, $id, $user_id ) {
		$raw = $this->extract_id( $id );
		if ( $raw === null ) {
			return $handled;
		}
		$n = \Voxel\Notification::get( $raw );
		if ( ! $n || (int) $n->get_user_id() !== (int) $user_id ) {
			return $handled;
		}
		$n->delete();
		if ( class_exists( '\Voxel\User' ) ) {
			$user = \Voxel\User::get( $user_id );
			if ( $user && method_exists( $user, 'update_notification_count' ) ) {
				$user->update_notification_count();
			}
		}
		return true;
	}

	protected function handle_mark_all_read( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		// Voxel's own table — no public API for bulk mark-all-read.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}voxel_notifications SET seen = 1 WHERE user_id = %d AND seen = 0",
			$user_id
		) );
		if ( class_exists( '\Voxel\User' ) ) {
			$user = \Voxel\User::get( $user_id );
			if ( $user && method_exists( $user, 'update_notification_count' ) ) {
				$user->update_notification_count();
			}
		}
	}

	protected function handle_delete_all( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		// Voxel's own table — no public API for bulk delete-all.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}voxel_notifications WHERE user_id = %d",
			$user_id
		) );
		if ( class_exists( '\Voxel\User' ) ) {
			$user = \Voxel\User::get( $user_id );
			if ( $user && method_exists( $user, 'update_notification_count' ) ) {
				$user->update_notification_count();
			}
		}
	}

	/**
	 * Peel the `voxel-` prefix off an incoming ID. Returns the raw integer or
	 * null when the ID isn't ours.
	 */
	private function extract_id( $id ) {
		$id = (string) $id;
		if ( strpos( $id, self::ID_PREFIX ) !== 0 ) {
			return null;
		}
		$raw = substr( $id, strlen( self::ID_PREFIX ) );
		if ( ! ctype_digit( $raw ) ) {
			return null;
		}
		return (int) $raw;
	}
}
