<?php

namespace Appress\Integration\FluentCRM\Controllers;

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
 * Hooks into Appress broadcast system to enable FluentCRM-based
 * audience targeting. Stores selected list/tag IDs in the campaign
 * payload and filters FCM tokens at send-time to only include
 * devices belonging to matching FluentCRM contacts.
 */
class Broadcast_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Store FluentCRM targeting in campaign payload on create/update.
		$this->filter( 'appress/broadcast/campaign_payload', '@save_targeting', 10, 2 );

		// Filter FCM tokens at send-time to match FluentCRM audience.
		$this->filter( 'appress/broadcast/filter_tokens', '@filter_tokens', 10, 3 );

		// Expose FluentCRM lists + tags to the broadcast Vue UI.
		$this->on( 'appress_ajax_broadcast.fluentcrm_options', '@get_options' );
	}

	/**
	 * Persist FluentCRM list/tag IDs into the campaign payload JSON.
	 * Called via `appress/broadcast/campaign_payload` filter.
	 */
	public function save_targeting( $payload, $data ) {
		if ( isset( $data['fluentcrm_lists'] ) && is_array( $data['fluentcrm_lists'] ) ) {
			$payload['fluentcrm_lists'] = array_map( 'intval', $data['fluentcrm_lists'] );
		}
		if ( isset( $data['fluentcrm_tags'] ) && is_array( $data['fluentcrm_tags'] ) ) {
			$payload['fluentcrm_tags'] = array_map( 'intval', $data['fluentcrm_tags'] );
		}
		return $payload;
	}

	/**
	 * Narrow the FCM token list to only devices whose `user_id`
	 * belongs to a FluentCRM contact matching the campaign's
	 * selected lists/tags. If no FluentCRM targeting is set on the
	 * campaign, returns tokens unchanged (broadcast to all).
	 */
	public function filter_tokens( $tokens, $campaign, $app_id ) {
		$payload = json_decode( stripslashes( $campaign['payload'] ?? '{}' ), true ) ?: [];

		$list_ids = $payload['fluentcrm_lists'] ?? [];
		$tag_ids  = $payload['fluentcrm_tags'] ?? [];

		// No FluentCRM targeting → don't filter.
		if ( empty( $list_ids ) && empty( $tag_ids ) ) {
			return $tokens;
		}

		if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
			return $tokens;
		}

		$query = \FluentCrm\App\Models\Subscriber::where( 'status', 'subscribed' )
			->where( 'user_id', '>', 0 );

		if ( ! empty( $tag_ids ) ) {
			$query->filterByTags( $tag_ids );
		}
		if ( ! empty( $list_ids ) ) {
			$query->filterByLists( $list_ids );
		}

		$user_ids = $query->pluck( 'user_id' )->toArray();

		if ( empty( $user_ids ) ) {
			return []; // No matching contacts → send to nobody.
		}

		// Cross-reference: keep only tokens whose user_id is in the
		// FluentCRM result set.
		global $wpdb;
		$fcm_table = $wpdb->prefix . 'appress_fcm_devices';
		$user_placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		$matching_tokens = $wpdb->get_col( $wpdb->prepare(
			"SELECT token FROM {$fcm_table} WHERE app_id = %d AND user_id IN ({$user_placeholders})",
			$app_id, ...$user_ids
		) );

		// Intersect with the already-filtered tokens (respects app_id + platform filters).
		return array_values( array_intersect( $tokens, $matching_tokens ) );
	}

	/**
	 * AJAX: return FluentCRM lists + tags for the campaign UI selectors.
	 */
	protected function get_options() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				throw new \Exception( esc_html__( 'Unauthorized.', 'appress' ) );
			}

			$lists = [];
			$tags  = [];

			if ( class_exists( '\FluentCrm\App\Models\Lists' ) ) {
				$lists = \FluentCrm\App\Models\Lists::select( 'id', 'title' )
					->orderBy( 'title' )
					->get()
					->map( fn( $l ) => [ 'value' => $l->id, 'label' => $l->title ] )
					->toArray();
			}

			if ( class_exists( '\FluentCrm\App\Models\Tag' ) ) {
				$tags = \FluentCrm\App\Models\Tag::select( 'id', 'title' )
					->orderBy( 'title' )
					->get()
					->map( fn( $t ) => [ 'value' => $t->id, 'label' => $t->title ] )
					->toArray();
			}

			return wp_send_json( [
				'success' => true,
				'data'    => [ 'lists' => $lists, 'tags' => $tags ]
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}
}
