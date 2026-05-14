<?php
namespace Appress;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public entry for every notification the plugin emits.
 *
 * Model (`create`, `unread_count_db`) + Orchestrator (`send_to`) +
 * Facade (`firebase`) on one class. The FCM SDK wrapper lives in
 * `Notifications\Firebase` and is cached per app_id — Kreait binds one
 * service account per instance, so multi-app installs need one client
 * per Firebase project.
 */
class Notification {

	/** @var array<int, Notifications\Firebase> */
	private static array $firebase_cache = [];

	/** Cached Firebase client for raw FCM access. Most callers want {@see send_to()} instead. */
	public static function firebase( int $app_id ): Notifications\Firebase {
		return self::$firebase_cache[ $app_id ] ??= new Notifications\Firebase( $app_id );
	}

	/**
	 * Send a notification. `$user_id` is polymorphic:
	 *   - `int`   → one recipient (use `0` + `override_tokens` for guests).
	 *   - `int[]` → fan out; duplicates removed, negatives dropped,
	 *               length-1 behaves like the scalar form.
	 *
	 * @param int|int[] $user_id
	 * @param array     $payload  Ride-along data sent to the client: url, image, appress_campaign_id, extras.
	 * @param array     $opts     Server-side behaviour flags:
	 *                              - skip_persist (bool)       — skip DB row when caller has its own store.
	 *                              - override_tokens (string[]) — pre-resolved FCM tokens (broadcast + guest).
	 *                              - override_source_id (string) — integration-prefixed id for mark_read.
	 *                              - extra_data (array)        — scalar pass-through into FCM data map.
	 * @return array { recipients: int, notification_ids: int[], sent: int, failed: int, tokens_sent: int }
	 */
	public static function send_to( int|array $user_id, int $app_id, string $title, string $body, array $payload = [], array $opts = [] ): array {
		$user_ids = is_array( $user_id )
			? array_values( array_unique( array_map( 'intval', $user_id ) ) )
			: [ (int) $user_id ];
		// `0` is preserved (guest bucket); negatives dropped.
		$user_ids = array_values( array_filter( $user_ids, static fn( $u ) => $u >= 0 ) );

		$agg = [
			'recipients'       => 0,
			'notification_ids' => [],
			'sent'             => 0,
			'failed'           => 0,
			'tokens_sent'      => 0,
		];

		if ( $title === '' && $body === '' ) return $agg;
		if ( $app_id <= 0 )                  return $agg;
		if ( empty( $user_ids ) )            return $agg;

		foreach ( $user_ids as $uid ) {
			$one = [ 'notification_id' => null, 'sent' => 0, 'failed' => 0, 'tokens_sent' => 0 ];

			// 1. Persist feed row. Skipped when caller maintains its own
			//    store (broadcast queue, some integrations) or user_id=0.
			if ( empty( $opts['skip_persist'] ) && $uid > 0 ) {
				$nid = self::create( $uid, $title, $body, $payload );
				if ( $nid ) {
					$one['notification_id'] = (int) $nid;
				}
			}

			// 2. Resolve tokens. `override_tokens` bypasses the device
			//    lookup — required for the guest bucket (user_id=0 matches
			//    zero device rows) and for broadcast queue rows that
			//    pre-resolved tokens at fan-out time.
			$tokens = isset( $opts['override_tokens'] ) && is_array( $opts['override_tokens'] )
				? array_values( array_filter( array_unique( $opts['override_tokens'] ) ) )
				: self::lookup_tokens( $app_id, $uid );
			$one['tokens_sent'] = count( $tokens );

			// 3 + 4. Build FCM data map + multicast. Exceptions are
			//    swallowed so one bad app can't break a batch — the
			//    user's slot is booked as failed, loop continues.
			if ( ! empty( $tokens ) ) {
				$data_payload = [
					'title' => $title,
					'body'  => $body,
					'image' => (string) ( $payload['image'] ?? '' ),
					'url'   => (string) ( $payload['url'] ?? '' ),
				];
				if ( ! empty( $payload['appress_campaign_id'] ) ) {
					$data_payload['appress_campaign_id'] = (string) $payload['appress_campaign_id'];
				}
				// Explicit override_source_id wins over the just-persisted row id.
				if ( ! empty( $opts['override_source_id'] ) ) {
					$data_payload['appress_source_id'] = (string) $opts['override_source_id'];
				} elseif ( $one['notification_id'] ) {
					$data_payload['appress_source_id'] = (string) $one['notification_id'];
				}
				if ( ! empty( $opts['extra_data'] ) && is_array( $opts['extra_data'] ) ) {
					foreach ( $opts['extra_data'] as $k => $v ) {
						if ( is_scalar( $v ) && ! isset( $data_payload[ $k ] ) ) {
							$data_payload[ $k ] = (string) $v;
						}
					}
				}

				try {
					$res = self::firebase( $app_id )->send_multicast(
						$tokens, $title, $body, $data_payload, (string) ( $payload['image'] ?? '' )
					);
					$one['sent']   = (int) ( $res['successCount'] ?? 0 );
					$one['failed'] = (int) ( $res['failureCount'] ?? 0 );
				} catch ( \Exception $e ) {
					\Appress\debug_log( '[Appress/Notification] app=' . $app_id . ' user=' . $uid . ' EXCEPTION: ' . $e->getMessage() );
					$one['failed'] = count( $tokens );
				}
			}

			// 5. Observability hook fires unconditionally so analytics
			//    see the no-tokens case too.
			do_action( 'appress/notification/dispatched', [
				'user_id'         => $uid,
				'app_id'          => $app_id,
				'notification_id' => $one['notification_id'],
				'title'           => $title,
				'body'            => $body,
				'payload'         => $payload,
				'sent'            => $one['sent'],
				'failed'          => $one['failed'],
				'tokens_sent'     => $one['tokens_sent'],
			] );

			// Guest bucket contributes sent/failed but doesn't count as a recipient.
			if ( $uid > 0 ) $agg['recipients']++;
			if ( $one['notification_id'] !== null ) $agg['notification_ids'][] = $one['notification_id'];
			$agg['sent']        += $one['sent'];
			$agg['failed']      += $one['failed'];
			$agg['tokens_sent'] += $one['tokens_sent'];
		}
		return $agg;
	}

	/**
	 * Persist one row into `wp_appress_notifications`. Low-level —
	 * most callers want {@see send_to()} which wraps this plus FCM.
	 *
	 * @return int|false Inserted id, or false on failure.
	 */
	public static function create( $user_id, $title, $body, $payload = [] ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'appress_notifications';

		$user_id = intval( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}

		// Hoist campaign_id into its own column so `notifications.mark_read`
		// can target an entire campaign on FCM tap without unpacking JSON.
		$campaign_id = 0;
		if ( is_array( $payload ) && isset( $payload['appress_campaign_id'] ) ) {
			$campaign_id = (int) $payload['appress_campaign_id'];
		}

		$data = [
			'user_id'     => $user_id,
			'campaign_id' => $campaign_id > 0 ? $campaign_id : null,
			'title'       => trim( $title ),
			'body'        => trim( $body ),
			'payload'     => empty( $payload ) ? '' : wp_json_encode( $payload ),
			'is_read'     => 0,
			'created_at'  => current_time( 'mysql', 1 ),
		];

		// `wpdb->insert` emits `NULL` literal for PHP-null values regardless
		// of the format slot, so `%d` on the nullable campaign_id is fine.
		$format = [ '%d', '%d', '%s', '%s', '%s', '%d', '%s' ];

		return $wpdb->insert( $table_name, $data, $format ) ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Raw DB unread count. Indicator callers run this then apply
	 * `appress/notifications/unread_count` filter to aggregate with
	 * integrations that store rows elsewhere. The `_db` suffix marks
	 * it as the raw number, not the filtered total.
	 */
	public static function unread_count_db( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}appress_notifications WHERE user_id = %d AND is_read = 0",
			$user_id
		) );
	}

	private static function lookup_tokens( int $app_id, int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT token FROM {$wpdb->prefix}appress_fcm_devices WHERE app_id = %d AND user_id = %d",
			$app_id, $user_id
		) );
		return array_values( array_filter( array_unique( (array) $rows ) ) );
	}
}
