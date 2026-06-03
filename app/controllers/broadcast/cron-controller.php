<?php
namespace Appress\Controllers\Broadcast;

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
 * Broadcast cron. Two-phase pipeline so campaigns with thousands of
 * recipients don't block a single cron run:
 *
 *   Phase 1 — populate:
 *     status='queued' → expand targeting (apps × users × tokens) into rows
 *     on `wp_appress_broadcast_queue`, one row per recipient (or per guest
 *     bucket). Flip status='sending', reschedule self.
 *
 *   Phase 2 — drain:
 *     status='sending' → pick the next CHUNK_SIZE queue rows for this
 *     campaign, create the Notification DB row + send one FCM multicast
 *     per recipient, DELETE processed rows. If any rows remain,
 *     reschedule self; otherwise finalize stats + mark status='sent'.
 *
 * Each cron tick stays bounded, so WP cron can't time out on big
 * campaigns and a failed tick just retries on the next scheduled run.
 */
class Cron_Controller extends \Appress\Controllers\Base_Controller {

	/** Max queue rows processed per cron tick. Tuned for a single FCM
	 *  multicast per row (typically 1–3 tokens) so even on a slow FCM
	 *  network we stay well under the default PHP max_execution_time. */
	const CHUNK_SIZE = 20;

	protected function hooks() {
		$this->on( 'appress_broadcast_send_campaign_cron', '@cron_send_campaign' );
	}

	public function cron_send_campaign( $campaign_id ) {
		try {
			$campaign_id = (int) $campaign_id;
			if ( $campaign_id <= 0 ) {
				return;
			}

			global $wpdb;
			$broadcast_table = $wpdb->prefix . 'appress_broadcast';
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$broadcast_table} WHERE id = %d", $campaign_id ), ARRAY_A );

			if ( ! $campaign ) {
				return;
			}
			if ( $campaign['status'] === 'sent' ) {
				return;
			}

			if ( $campaign['status'] === 'queued' ) {
				$this->populate_queue( $campaign );
				$this->reschedule( $campaign_id );
				return;
			}

			// status === 'sending' (or anything else that survived populate)
			$this->drain_chunk( $campaign );
		} catch ( \Exception $e ) {
			\Appress\debug_log( 'Appress Cron Campaign Error: ' . $e->getMessage() );
		}
	}

	/**
	 * Expand targeting into per-recipient queue rows. Runs once per campaign
	 * (when it transitions from 'queued' → 'sending'). After this, all heavy
	 * lifting is DB-only and the per-tick drain makes FCM calls.
	 */
	private function populate_queue( array $campaign ) {
		global $wpdb;
		$campaign_id     = (int) $campaign['id'];
		$broadcast_table = $wpdb->prefix . 'appress_broadcast';

		// Atomic `queued` → `sending` flip — if we don't win the race, another
		// tick is already populating. Skip to avoid duplicate queue rows (which
		// would cause double-send).
		$claimed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$broadcast_table} SET status = 'sending' WHERE id = %d AND status = 'queued'",
			$campaign_id
		) );
		if ( ! $claimed ) {
			return;
		}

		$payload = json_decode( stripslashes( $campaign['payload'] ?? '{}' ), true ) ?: [];
		$target_apps      = json_decode( stripslashes( $campaign['target_apps'] ?? '[]' ), true ) ?: [];
		$target_platforms = json_decode( stripslashes( $campaign['target_platforms'] ?? '[]' ), true ) ?: [];
		$target_countries = $payload['target_countries'] ?? [];

		$fcm_table   = $wpdb->prefix . 'appress_fcm_devices';
		$queue_table = $wpdb->prefix . 'appress_broadcast_queue';

		if ( ! empty( $target_apps ) ) {
			foreach ( $target_apps as $app_id ) {
				$app_id = (int) $app_id;
				if ( $app_id <= 0 ) continue;

				$args        = [ $app_id ];
				$platform_sql = '';
				if ( ! empty( $target_platforms ) ) {
					$placeholders = implode( ',', array_fill( 0, count( $target_platforms ), '%s' ) );
					$platform_sql = " AND platform IN ($placeholders)";
					$args = array_merge( $args, $target_platforms );
				}
				$country_sql = '';
				if ( ! empty( $target_countries ) && is_array( $target_countries ) ) {
					$placeholders = implode( ',', array_fill( 0, count( $target_countries ), '%s' ) );
					$country_sql = " AND country IN ($placeholders)";
					$args = array_merge( $args, $target_countries );
				}

				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT token, user_id FROM {$fcm_table} WHERE app_id = %d{$platform_sql}{$country_sql}",
					...$args
				), ARRAY_A );

				$all_tokens     = array_column( (array) $rows, 'token' );
				$allowed_tokens = apply_filters( 'appress/broadcast/filter_tokens', $all_tokens, $campaign, $app_id );
				if ( empty( $allowed_tokens ) ) continue;
				$allowed_set = array_flip( $allowed_tokens );

				// Group by user. Guests share one queue row per app — they
				// don't persist to the feed so there's no per-user fan-out
				// benefit + a single multicast is cheapest.
				$tokens_by_user = [];
				$guest_tokens   = [];
				foreach ( (array) $rows as $r ) {
					if ( ! isset( $allowed_set[ $r['token'] ] ) ) continue;
					$uid = (int) $r['user_id'];
					if ( $uid > 0 ) {
						$tokens_by_user[ $uid ][] = $r['token'];
					} else {
						$guest_tokens[] = $r['token'];
					}
				}

				foreach ( $tokens_by_user as $uid => $userTokens ) {
					$wpdb->insert( $queue_table, [
						'campaign_id' => $campaign_id,
						'app_id'      => $app_id,
						'user_id'     => (int) $uid,
						'tokens'      => wp_json_encode( $userTokens ),
					], [ '%d', '%d', '%d', '%s' ] );
				}
				if ( ! empty( $guest_tokens ) ) {
					$wpdb->insert( $queue_table, [
						'campaign_id' => $campaign_id,
						'app_id'      => $app_id,
						'user_id'     => 0,
						'tokens'      => wp_json_encode( $guest_tokens ),
					], [ '%d', '%d', '%d', '%s' ] );
				}
			}
		}

		// Status already flipped to 'sending' atomically above. If no queue
		// rows were inserted (empty audience / all filtered out), the next
		// drain tick will short-circuit to 'sent'.
	}

	/**
	 * Pull CHUNK_SIZE queue rows, process them, delete on success. If any
	 * rows remain the next tick is rescheduled; otherwise the campaign is
	 * finalized.
	 */
	private function drain_chunk( array $campaign ) {
		global $wpdb;
		$campaign_id = (int) $campaign['id'];

		$queue_table = $wpdb->prefix . 'appress_broadcast_queue';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, app_id, user_id, tokens FROM {$queue_table} WHERE campaign_id = %d ORDER BY id ASC LIMIT %d",
			$campaign_id, self::CHUNK_SIZE
		), ARRAY_A );

		if ( empty( $rows ) ) {
			$this->finalize( $campaign_id );
			return;
		}

		$title   = $campaign['title'];
		$body    = $campaign['body'];
		$payload = json_decode( stripslashes( $campaign['payload'] ?? '{}' ), true ) ?: [];
		$image   = $payload['image'] ?? '';
		$url     = $payload['url']   ?? '';

		$persist_payload_base = [
			'url'                 => $url,
			'image'               => $image,
			'campaign_id' => (string) $campaign_id,
		];

		$sent = 0;
		$failed = 0;
		$processed_ids = [];

		// Same unified service WC / Voxel events use — broadcast stops being a
		// parallel code path: persist (for logged-in users), FCM multicast,
		// `source_id` threading, and `appress/notification/dispatched`
		// all happen inside the service so campaign stats + feed items + mark-
		// read routing stay identical regardless of source.
		foreach ( $rows as $row ) {
			$row_id  = (int) $row['id'];
			$app_id  = (int) $row['app_id'];
			$user_id = (int) $row['user_id'];
			$tokens  = json_decode( $row['tokens'] ?? '[]', true );
			if ( ! is_array( $tokens ) || empty( $tokens ) ) {
				$processed_ids[] = $row_id;
				continue;
			}

			// Broadcast adds `action` so the native tap handler can branch
			// between "open the embedded URL" and "just foreground the app"
			// without inspecting the url field.
			$payload_for_user = $persist_payload_base + [
				'action' => ! empty( $url ) ? 'open_url' : 'open_app',
			];

			$res = \Appress\Notification::send_to(
				$user_id, $app_id, $title, $body, $payload_for_user,
				[
					// Tokens come pre-resolved in the queue row — bypass the
					// (app_id, user_id) lookup inside the service. Required so
					// guest queue rows (user_id=0) actually fire: the lookup
					// would otherwise return 0 rows for guests.
					'override_tokens' => $tokens,
				]
			);
			$sent         += (int) ( $res['sent'] ?? 0 );
			$failed       += (int) ( $res['failed'] ?? 0 );
			$processed_ids[] = $row_id;
		}

		// Delete processed queue rows.
		if ( ! empty( $processed_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $processed_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$queue_table} WHERE id IN ($placeholders)",
				...$processed_ids
			) );
		}

		// Accumulate stats without re-reading the whole campaign row.
		if ( $sent > 0 || $failed > 0 ) {
			$this->bump_stats( $campaign_id, $sent, $failed );
		}

		// More work left? Reschedule. Otherwise finalize.
		$remaining = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$queue_table} WHERE campaign_id = %d",
			$campaign_id
		) );
		if ( $remaining > 0 ) {
			$this->reschedule( $campaign_id );
		} else {
			$this->finalize( $campaign_id );
		}
	}

	private function bump_stats( $campaign_id, $sent_delta, $failed_delta ) {
		global $wpdb;
		$table = $wpdb->prefix . 'appress_broadcast';
		// NULLIF + IF guard the case where `stats` is '' (old rows) — JSON_SET
		// on an empty string throws "Missing a name for object member".
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET stats = JSON_SET(
				IF(stats IS NULL OR stats = '', '{}', stats),
				'$.sent',   COALESCE(JSON_EXTRACT(IF(stats IS NULL OR stats = '', '{}', stats), '$.sent'), 0) + %d,
				'$.failed', COALESCE(JSON_EXTRACT(IF(stats IS NULL OR stats = '', '{}', stats), '$.failed'), 0) + %d
			) WHERE id = %d",
			$sent_delta, $failed_delta, $campaign_id
		) );
	}

	private function finalize( $campaign_id ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'appress_broadcast',
			[ 'status' => 'sent' ],
			[ 'id' => $campaign_id ]
		);
	}

	private function reschedule( $campaign_id ) {
		// `time() + 1` = "next cron pass ASAP". WP cron fires on frontend
		// requests by default; admins with light traffic should enable a
		// real system cron to keep the drain snappy.
		if ( ! wp_next_scheduled( 'appress_broadcast_send_campaign_cron', [ $campaign_id ] ) ) {
			wp_schedule_single_event( time() + 1, 'appress_broadcast_send_campaign_cron', [ $campaign_id ] );
		}
	}
}
