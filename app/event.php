<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
namespace Appress;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.InvalidPrefixPassed
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Event {

	public $event_id;
	public $user_id; // Single user ID or Array of User IDs
	public $params;

	public function __construct( $event_id, $user_id, $params = [] ) {
		$this->event_id = $event_id;
		$this->user_id  = $user_id;
		$this->params   = $params;
	}

	public static function get_all() {
		return apply_filters( 'appress/events', [] );
	}

	public function send() {
		$settings = \Appress\get( 'events', [] );

		// 1. Locate the integration ID for this event
		$integrations = self::get_all();
		$integration_id = null;
		$event_schema = null;

		foreach ( $integrations as $i_id => $integration ) {
			if ( isset( $integration['events'][ $this->event_id ] ) ) {
				$integration_id = $i_id;
				$event_schema = $integration['events'][ $this->event_id ];
				break;
			}
		}

		if ( ! $integration_id || ! $event_schema ) {
			return; // Invalid or unregistered event
		}

		// 2. Load the specific settings for this integration -> event
		$event_config = isset( $settings[ $integration_id ][ $this->event_id ] ) ? $settings[ $integration_id ][ $this->event_id ] : null;

		// Destination-scoped dispatch (WooCommerce Base_WC_Event, future vendor plugins):
		// the caller identifies WHICH destination bucket to read target_apps from via
		// `_destination` on params. All gating/content flows through that subtree, not
		// the top-level event config.
		$destination = isset( $this->params['_destination'] ) ? (string) $this->params['_destination'] : '';
		if ( $destination !== '' ) {
			$dest_cfg = $event_config['destinations'][ $destination ] ?? null;
			if ( empty( $dest_cfg['enabled'] ) ) {
				return;
			}
			$target_apps = isset( $dest_cfg['target_apps'] ) && is_array( $dest_cfg['target_apps'] ) ? $dest_cfg['target_apps'] : [];
			if ( empty( $target_apps ) ) {
				return;
			}
		} else {
			// `always_on` events (gated by their parent Integration toggle) skip the per-event enabled check.
			$always_on = ! empty( $event_schema['always_on'] );
			if ( ! $always_on && ( ! $event_config || empty( $event_config['enabled'] ) ) ) {
				return; // Disabled globally
			}

			$target_apps = isset( $event_config['target_apps'] ) && is_array( $event_config['target_apps'] ) ? $event_config['target_apps'] : [];
			if ( empty( $target_apps ) ) {
				return;
			}
		}

		// 3. Process the notification content
		$title = '';
		$body  = '';
		$image = '';
		$url   = '';

		// If this is a forwarding event (has_content === false), it expects title and body to be injected in params
		if ( isset( $event_schema['has_content'] ) && $event_schema['has_content'] === false ) {
			$title = isset( $this->params['_override_title'] ) ? $this->params['_override_title'] : '';
			$body  = isset( $this->params['_override_body'] ) ? $this->params['_override_body'] : '';
			$image = isset( $this->params['_override_image'] ) ? $this->params['_override_image'] : '';
			$url   = isset( $this->params['_override_url'] ) ? $this->params['_override_url'] : '';
		} else {
			$title = $this->parse_template( isset( $event_config['title'] ) ? $event_config['title'] : '' );
			$body  = $this->parse_template( isset( $event_config['body'] ) ? $event_config['body'] : '' );
			$image = $this->parse_template( isset( $event_config['image'] ) ? $event_config['image'] : '' );
			$url   = $this->parse_template( isset( $event_config['url'] ) ? $event_config['url'] : '' );
		}

		if ( empty( $title ) && empty( $body ) ) {
			return; // Empty notification
		}

		// Key names MUST match what native AppressFirebaseMessagingService.onMessageReceived reads
		// (and what broadcast/cron-controller sends): 'url'. Using 'click_action' here would silently
		// drop the link on the client side.
		$payload = [
			'title' => $title,
			'body'  => $body,
			'image' => $image,
			'url'   => $url,
		];

		// Normalize user_id to array
		$user_ids = is_array( $this->user_id ) ? $this->user_id : [ $this->user_id ];
		$user_ids = array_filter( array_map( 'intval', $user_ids ) );

		if ( empty( $user_ids ) ) {
			return;
		}

		// Delegate to the unified service so persistence + FCM + source_id +
		// dispatched-hook stay in one place. Integrations opting out of the
		// built-in DB store (Voxel owns `wp_voxel_notifications` separately)
		// pass `_skip_db_persist`; callers with their own feed rows can thread
		// the prefixed id through `_override_source_id` so FCM-tap mark_read
		// lands on their store rather than ours.
		$skip_persist = ! empty( $this->params['_skip_db_persist'] );
		$payload['image'] = $image;
		$payload['url']   = $url;
		$opts = [
			'skip_persist'       => $skip_persist,
			'override_source_id' => $this->params['_override_source_id'] ?? null,
		];

		foreach ( $user_ids as $uid ) {
			foreach ( $target_apps as $app_id ) {
				\Appress\Notification::send_to(
					(int) $uid, (int) $app_id, $title, $body, $payload, $opts
				);
			}
		}
	}

	protected function parse_template( $string ) {
		if ( empty( $string ) || ! is_string( $string ) ) {
			return '';
		}

		// Parse custom event params (e.g. {{order_id}})
		foreach ( $this->params as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$string = str_replace( '{{' . $key . '}}', $value, $string );
			}
		}

		// We assume the first user in the array or single user for native tags if target is multiple
		$primary_user_id = is_array( $this->user_id ) ? ( $this->user_id[0] ?? 0 ) : $this->user_id;

		if ( $primary_user_id > 0 ) {
			$user = get_userdata( $primary_user_id );
			if ( $user ) {
				$string = str_replace( '{{display_name}}', $user->display_name, $string );
				$string = str_replace( '{{first_name}}', $user->first_name ?: $user->display_name, $string );
				$string = str_replace( '{{user_email}}', $user->user_email, $string );
			}
		}

		return $string;
	}
}
