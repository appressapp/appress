<?php
namespace Appress\Integration\Voxel\Controllers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
use Appress\Controllers\Base_Controller;
use Appress\Event;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Events_Controller extends Base_Controller {

	protected function hooks() {
		$this->filter( 'appress/events', '@register_schema' );

		// Register listeners at init:999 — all plugins/themes have already called
		// add_filter('voxel/app-events/register', ...) by then (registration happens on
		// plugins_loaded / after_setup_theme), so Base_Event::get_all() returns the full
		// runtime catalog. Voxel's wp_options 'events' row is NOT a reliable source:
		// event-controller.php:96 only persists events whose admin config has items,
		// so unconfigured events would silently drop out of our listener set.
		$this->on( 'init', '@register_voxel_event_listeners', 999 );
	}

	protected function register_voxel_event_listeners() {
		// Voxel is optional — silent no-op on non-Voxel sites (was spamming debug.log every request).
		if ( ! class_exists( '\Voxel\Events\Base_Event' ) ) {
			return;
		}

		// Gate runtime dispatch on the admin-controllable Voxel module
		// toggle. Schema registration above (`register_schema`) stays
		// always-on so the events catalogue still renders on the
		// Integrations → Voxel detail page when the toggle is OFF — the
		// admin needs to see what's available before flipping the
		// switch. Without this gate, every Voxel event would fire
		// FCM pushes regardless of toggle state, defeating the whole
		// point of having a integration switch.
		$modules = (array) \Appress\get( 'modules', [] );
		if ( empty( $modules['voxel'] ) ) {
			return;
		}

		$event_keys = array_keys( (array) \Voxel\Events\Base_Event::get_all() );
		foreach ( $event_keys as $event_key ) {
			$this->on( "voxel/app-events/{$event_key}", '@handle_voxel_event', 10, 1 );
		}
	}

	protected function register_schema( $integrations ) {
		$events_schema = [];

		if ( class_exists( '\Voxel\Events\Base_Event' ) ) {
			$categories = [];
			if ( method_exists( '\Voxel\Events\Base_Event', 'get_categories' ) ) {
				foreach ( \Voxel\Events\Base_Event::get_categories() as $cat ) {
					if ( isset( $cat['key'] ) ) {
						$categories[ $cat['key'] ] = $cat['label'] ?? $cat['key'];
					}
				}
			}

			foreach ( \Voxel\Events\Base_Event::get_all() as $event_key => $event ) {
				$label        = method_exists( $event, 'get_label' ) ? (string) $event->get_label() : $event_key;
				$category_key = method_exists( $event, 'get_category' ) ? (string) $event->get_category() : '';
				$events_schema[ $event_key ] = [
					'label'         => $label,
					'description'   => '',
					'category'      => $category_key,
					'category_label' => $categories[ $category_key ] ?? ( $category_key !== '' ? $category_key : 'Other' ),
					'has_content'   => false,
					'always_on'     => true,
				];
			}
		}

		$integrations['voxel'] = [
			'name'        => 'Voxel In-App Events',
			'icon'        => 'dashicons-admin-site-alt3',
			'type'        => 'global',
			'description' => 'Forward Voxel In-App Notifications to selected apps — per event.',
			'events'      => $events_schema,
		];

		return $integrations;
	}

	protected function handle_voxel_event( $event ) {
		if ( ! $event instanceof \Voxel\Events\Base_Event ) {
			return;
		}

		$key = $event->get_key();

		// Read per-event mode: 'voxel' (follow Voxel in-app enabled state) or 'custom' (override).
		$settings         = \Appress\get( 'events', [] );
		$cfg              = $settings['voxel'][ $key ] ?? [];
		$mode             = ( isset( $cfg['mode'] ) && $cfg['mode'] === 'custom' ) ? 'custom' : 'voxel';
		$override_enabled = ! empty( $cfg['override_enabled'] );

		$this->debug( "event fired: {$key} (mode={$mode}, override_enabled=" . ( $override_enabled ? '1' : '0' ) . ')' );

		// Custom mode with the override switch OFF — never push regardless of Voxel.
		if ( $mode === 'custom' && ! $override_enabled ) {
			return;
		}

		// Voxel mode — push only when Voxel actually persisted in-app notifications in this cycle.
		if ( $mode === 'voxel' ) {
			if ( empty( $event->_inapp_sent_cache ) || ! is_array( $event->_inapp_sent_cache ) ) {
				$this->debug( "{$key}: voxel mode, no inapp cache — skipping" );
				return;
			}
			$sent = 0;
			foreach ( $event->_inapp_sent_cache as $destination => $notification ) {
				if ( $notification instanceof \Voxel\Notification ) {
					$this->push_from_notification( $event, $notification, $destination );
					$sent++;
				}
			}
			$this->debug( "{$key}: voxel mode dispatched x{$sent}" );
			return;
		}

		// Custom mode with override ON — push regardless of Voxel's in-app state.
		// Prefer cached notifications if Voxel already rendered them (best content);
		// otherwise synthesize from notification config (email subject / label fallback).
		if ( ! empty( $event->_inapp_sent_cache ) && is_array( $event->_inapp_sent_cache ) ) {
			$sent = 0;
			foreach ( $event->_inapp_sent_cache as $destination => $notification ) {
				if ( $notification instanceof \Voxel\Notification ) {
					$this->push_from_notification( $event, $notification, $destination );
					$sent++;
				}
			}
			$this->debug( "{$key}: custom mode (cache) dispatched x{$sent}" );
			return;
		}

		$notifications = $event->get_notifications();
		if ( empty( $notifications ) || ! is_array( $notifications ) ) {
			$this->debug( "{$key}: no notifications registered" );
			return;
		}
		$sent = 0;
		foreach ( $notifications as $destination => $config ) {
			if ( ! is_callable( $config['recipient'] ?? null ) ) {
				continue;
			}
			try {
				$recipient = $config['recipient']( $event );
			} catch ( \Exception $e ) {
				$this->debug( "{$key}/{$destination}: recipient callable threw: " . $e->getMessage() );
				continue;
			}
			if ( ! $recipient instanceof \Voxel\User ) {
				continue;
			}
			$event->recipient = $recipient;
			$this->push_from_config( $event, $config, $destination, $recipient->get_id() );
			$sent++;
		}
	}

	private function push_from_notification( \Voxel\Events\Base_Event $event, \Voxel\Notification $notification, $destination ) {
		$user_id = (int) $notification->get_user_id();
		$title   = $this->category_label_for( $event );
		$body    = wp_strip_all_tags( (string) $notification->get_subject() );
		$url     = (string) ( $notification->get_links_to() ?: '' );
		$image   = (string) ( $notification->get_image_url() ?: '' );

		// `voxel-<id>` is the feed's ID prefix for Voxel rows (see
		// Notifications_Controller::ID_PREFIX). Threading it through as the
		// FCM `appress_source_id` lets the native tap handler hit
		// `notifications.mark_read` with the exact Voxel row id — the
		// filter chain routes it back to Voxel::handle_mark_read which
		// marks `seen = 1` + bumps the user meta bell count. No URL
		// parameter pollution, no page-load hook required.
		$source_id = $notification->get_id() ? ( 'voxel-' . (int) $notification->get_id() ) : '';

		$this->dispatch_appress_event( $event->get_key(), $destination, $user_id, $title, $body, $url, $image, $source_id );
	}

	/** Resolve the category label for an event — used as the push notification title. */
	private function category_label_for( \Voxel\Events\Base_Event $event ) {
		$cat_key = method_exists( $event, 'get_category' ) ? (string) $event->get_category() : '';
		if ( $cat_key !== '' && method_exists( '\Voxel\Events\Base_Event', 'get_categories' ) ) {
			$cats = \Voxel\Events\Base_Event::get_categories();
			if ( isset( $cats[ $cat_key ]['label'] ) ) {
				return wp_strip_all_tags( (string) $cats[ $cat_key ]['label'] );
			}
		}
		// Fallback: site name if category cannot be resolved.
		return (string) get_bloginfo( 'name' );
	}

	private function push_from_config( \Voxel\Events\Base_Event $event, array $config, $destination, $user_id ) {
		$title = $this->category_label_for( $event );

		// Prefer in-app subject (parses dynamic tags with current event context); fallback to email subject.
		$body_template = '';
		if ( ! empty( $config['inapp']['subject'] ) ) {
			$body_template = $config['inapp']['subject'];
		} elseif ( ! empty( $config['inapp']['default_subject'] ) ) {
			$body_template = $config['inapp']['default_subject'];
		} elseif ( ! empty( $config['email']['subject'] ) ) {
			$body_template = $config['email']['subject'];
		} elseif ( ! empty( $config['email']['default_subject'] ) ) {
			$body_template = $config['email']['default_subject'];
		}

		$body = '';
		if ( $body_template !== '' ) {
			try {
				$body = wp_strip_all_tags( \Voxel\render( $body_template, $event->get_dynamic_tags() ) );
			} catch ( \Exception $e ) {
				$this->debug( "render subject failed: " . $e->getMessage() );
			}
		}

		$url = '';
		if ( is_callable( $config['inapp']['links_to'] ?? null ) ) {
			try {
				$url = (string) ( $config['inapp']['links_to']( $event ) ?: '' );
			} catch ( \Exception $e ) {}
		}

		$image = '';
		if ( is_callable( $config['inapp']['image_id'] ?? null ) ) {
			try {
				$image_id = $config['inapp']['image_id']( $event );
				if ( $image_id ) {
					$image = (string) ( wp_get_attachment_image_url( $image_id, 'medium' ) ?: '' );
				}
			} catch ( \Exception $e ) {}
		}

		$this->dispatch_appress_event( $event->get_key(), $destination, (int) $user_id, $title, $body, $url, $image, '' );
	}

	private function dispatch_appress_event( $event_key, $destination, $user_id, $title, $body, $url, $image, $source_id ) {
		if ( ! $user_id || ( empty( $title ) && empty( $body ) ) ) {
			$this->debug( "{$event_key}/{$destination}: skipped — empty user/title/body (user_id={$user_id})" );
			return;
		}

		$fallback_title = $title !== '' ? $title : (string) get_bloginfo( 'name' );

		$params = [
			'_override_title'    => $fallback_title,
			'_override_body'     => $body,
			'_override_url'      => $url,
			'_override_image'    => $image,
			// `_skip_db_persist` tells Event::send NOT to insert into
			// `wp_appress_notifications`. Voxel already persists the same event
			// in `wp_voxel_notifications`, and the notifications-feed controller
			// surfaces those via the `appress/notifications/items` filter — so
			// persisting here would show every Voxel event twice in the feed.
			// The FCM push is still sent.
			'_skip_db_persist'   => true,
		];
		if ( $source_id !== '' ) {
			$params['_override_source_id'] = $source_id;
		}

		$appress_event = new Event( $event_key, $user_id, $params );

		$appress_event->send();
	}

	private function debug( $message ) {
		\Appress\debug_log( '[Appress/Voxel Events] ' . $message );
	}
}
