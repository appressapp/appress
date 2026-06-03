<?php
namespace Appress\Notifications;

// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\AndroidConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Firebase Cloud Messaging client wrapper.
 *
 * Instance-per-app_id (Firebase Factory is configured with ONE service
 * account per instance — multi-app support requires separate instances).
 * Instantiation + caching is owned by the `\Appress\Notification` facade
 * — callers resolve via `\Appress\Notification::firebase( $app_id )` so
 * the cache stays process-wide and nobody instantiates this directly.
 */
class Firebase {

	private $messaging;
	private $app_id;

	public function __construct( $app_id ) {
		$this->app_id = $app_id;
		$this->load_sdk();
		$this->messaging = $this->init_messaging_for_app( $app_id );
	}

	private function load_sdk() {
		if ( ! class_exists( 'Kreait\Firebase\Factory' ) ) {
			$autoload_path = APPRESS_PLUGIN_DIR . 'app/vendor/autoload.php';
			if ( file_exists( $autoload_path ) ) {
				require_once $autoload_path;
			} else {
				throw new \Exception( esc_html__( 'Firebase SDK not found. Please run composer install.', 'appress' ) );
			}
		}
	}

	private function init_messaging_for_app( $app_id ) {
		global $wpdb;
		$app_table = $wpdb->prefix . 'appress_apps';
		$build_info_json = $wpdb->get_var( $wpdb->prepare( "SELECT build_config FROM {$app_table} WHERE id = %d", $app_id ) );

		if ( ! $build_info_json ) {
   /* translators: dynamic value injected into the message */
			throw new \Exception( esc_html( sprintf( __( 'App ID %d not found.', 'appress' ), $app_id ) ) );
		}

		$build_info = json_decode( $build_info_json, true ) ?: [];
		// Service Account JSON: check credentials column first, fallback to build_config
		$credentials = $wpdb->get_var( $wpdb->prepare( "SELECT credentials FROM {$app_table} WHERE id = %d", $app_id ) );
		// Decrypt at-rest envelope (legacy plaintext rows pass through unchanged).
		$credentials = \Appress\decrypt( (string) $credentials );
		$creds = $credentials ? json_decode( $credentials, true ) : [];
		$service_account_json = $creds['firebase_service_account'] ?? '';

		// Fallback: try build_config (legacy)
		if ( empty( $service_account_json ) ) {
			$service_account_json = $build_info['firebase_android'] ?? $build_info['firebase-android'] ?? '';
		}

		if ( empty( $service_account_json ) ) {
   /* translators: dynamic value injected into the message */
			throw new \Exception( esc_html( sprintf( __( 'App ID %d does not have a Firebase configuration.', 'appress' ), $app_id ) ) );
		}

		// Handle double/triple-encoded JSON (string within JSON column)
		$max_decode = 5;
		while ( is_string( $service_account_json ) && $max_decode-- > 0 ) {
			$decoded = json_decode( $service_account_json, true );
			if ( is_array( $decoded ) ) {
				$service_account_json = $decoded;
				break;
			} elseif ( is_string( $decoded ) ) {
				$service_account_json = $decoded; // Still a string, decode again
			} else {
				\Appress\debug_log( "Appress Firebase: Failed to decode Service Account JSON for App ID {$app_id}." );
    /* translators: dynamic value injected into the message */
				throw new \Exception( esc_html( sprintf( __( 'Invalid Service Account JSON for App ID %d.', 'appress' ), $app_id ) ) );
			}
		}

		// Fix private_key PEM format (newlines stripped during JSON encode/decode chain)
		if ( ! empty( $service_account_json['private_key'] ) ) {
			$pk = $service_account_json['private_key'];
			// Strip all whitespace/newlines, extract raw base64 content
			$pk = preg_replace( '/-----BEGIN PRIVATE KEY-----/', '', $pk );
			$pk = preg_replace( '/-----END PRIVATE KEY-----/', '', $pk );
			$pk = preg_replace( '/[\s\n\r]/', '', $pk );
			// Rebuild proper PEM: header + base64 wrapped at 64 chars + footer
			$pk = "-----BEGIN PRIVATE KEY-----\n" . wordwrap( $pk, 64, "\n", true ) . "\n-----END PRIVATE KEY-----\n";
			$service_account_json['private_key'] = $pk;
		}

		if ( ! $service_account_json || empty( $service_account_json['project_id'] ) ) {
			\Appress\debug_log( "Appress Firebase: Missing project_id in Service Account for App ID {$app_id}. Keys: " . implode(',', array_keys( $service_account_json ?: [] )) );
   /* translators: dynamic value injected into the message */
			throw new \Exception( esc_html( sprintf( __( 'Invalid Service Account JSON for App ID %d.', 'appress' ), $app_id ) ) );
		}

		$factory = (new Factory())->withServiceAccount( $service_account_json );
		return $factory->createMessaging();
	}

	/**
	 * Send push notifications to multiple tokens in chunks of 500
	 * 
	 * @return array [ 'successCount' => int, 'failureCount' => int ]
	 */
	public function send_multicast( $tokens, $title, $body, $data_payload = [], $image_url = '' ) {
		if ( ! $this->messaging ) {
			return [ 'successCount' => 0, 'failureCount' => 0 ];
		}

		\Appress\debug_log( '[Appress Broadcast] send_multicast image_url=' . ( $image_url ?: '(empty)' ) . ' tokens=' . count( $tokens ) );

		$notification = Notification::create( $title, $body );
		if ( ! empty( $image_url ) ) {
			$notification = $notification->withImageUrl( $image_url );
		}

		// Platform-specific defaults: default sound on both iOS (APNs) and
		// Android. Without these, FCM delivers a silent data-only push on iOS
		// even when `notification` block is set.
		// mutable-content: 1 tells iOS to run the Notification Service Extension
		// (which downloads the image URL and attaches it as a rich notification).
		$apnsConfig = ApnsConfig::fromArray( [
			'payload' => [ 'aps' => [ 'sound' => 'default', 'mutable-content' => 1 ] ],
		] );
		// Android: image in AndroidConfig.notification ensures the system tray
		// renders the image even when app is background (onMessageReceived not
		// called for notification+data messages in background). channel_id ties
		// to our IMPORTANCE_HIGH channel for heads-up display.
		$androidNotif = [ 'sound' => 'default', 'channel_id' => 'appress_broadcast' ];
		if ( ! empty( $image_url ) ) {
			$androidNotif['image'] = $image_url;
		}
		$androidConfig = AndroidConfig::fromArray( [
			'priority' => 'high',
			'notification' => $androidNotif,
		] );

		$message_customizer = CloudMessage::new()
			->withNotification( $notification )
			->withApnsConfig( $apnsConfig )
			->withAndroidConfig( $androidConfig );

		if ( ! empty( $data_payload ) ) {
			// Firebase requires all data dictionary values to be strings
			$string_data = [];
			foreach ( $data_payload as $k => $v ) {
				$string_data[ $k ] = (string) $v;
			}
			$message_customizer = $message_customizer->withData( $string_data );
		}

		$total_success = 0;
		$total_failure = 0;
		
		$chunks = array_chunk( $tokens, 500 );
		foreach ( $chunks as $chunk ) {
			$max_retries = 3;
			$retry_delays = [ 2, 10, 30 ]; // seconds

			for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
				try {
					$multicast_send_report = $this->messaging->sendMulticast( $message_customizer, $chunk );

					$total_success += $multicast_send_report->successes()->count();
					$total_failure += $multicast_send_report->failures()->count();

					// Clean up stale tokens
					if ( $multicast_send_report->hasFailures() ) {
						$invalid_tokens = [];
						foreach ( $multicast_send_report->failures()->getItems() as $failure ) {
							$error = $failure->error()->getMessage();
							\Appress\debug_log( "Appress Firebase failure: " . $error . " | token: " . substr($failure->target()->value(), 0, 20) . "..." );
							if ( stripos( $error, 'unregistered' ) !== false || stripos( $error, 'invalid' ) !== false || stripos( $error, 'not found' ) !== false ) {
								$invalid_tokens[] = $failure->target()->value();
							}
						}

						if ( ! empty( $invalid_tokens ) ) {
							$this->delete_stale_tokens( $this->app_id, $invalid_tokens );
						}
					}

					break; // Success — exit retry loop

				} catch ( \Exception $e ) {
					\Appress\debug_log( "Appress Firebase Multicast Error (attempt " . ($attempt + 1) . "): " . $e->getMessage() );

					if ( $attempt < $max_retries ) {
						sleep( $retry_delays[ $attempt ] ?? 30 );
					} else {
						$total_failure += count( $chunk );
					}
				}
			}
		}

		return [
			'successCount' => $total_success,
			'failureCount' => $total_failure
		];
	}

	/**
	 * Send an individual app event notification
	 */
	public function send_event( $token, $title, $body, $data_payload = [], $image_url = '' ) {
		if ( ! $this->messaging ) {
			return false;
		}
		
		try {
			
			$notification = Notification::create( $title, $body );
			if ( ! empty( $image_url ) ) {
				$notification = $notification->withImageUrl( $image_url );
			}

			$apnsConfig = ApnsConfig::fromArray( [
				'payload' => [ 'aps' => [ 'sound' => 'default', 'mutable-content' => 1 ] ],
			] );
			$androidNotif = [ 'sound' => 'default', 'channel_id' => 'appress_broadcast' ];
			if ( ! empty( $image_url ) ) {
				$androidNotif['image'] = $image_url;
			}
			$androidConfig = AndroidConfig::fromArray( [
				'priority' => 'high',
				'notification' => $androidNotif,
			] );

			$message = CloudMessage::new()
				->withToken( $token )
				->withNotification( $notification )
				->withApnsConfig( $apnsConfig )
				->withAndroidConfig( $androidConfig );

			if ( ! empty( $data_payload ) ) {
				$string_data = [];
				foreach ( $data_payload as $k => $v ) {
					$string_data[ $k ] = (string) $v;
				}
				$message = $message->withData( $string_data );
			}

			$this->messaging->send( $message );
			return true;
		} catch ( \Exception $e ) {
			\Appress\debug_log( 'Appress Firebase Event Send Error: ' . $e->getMessage() );
			
			$error = $e->getMessage();
			if ( stripos( $error, 'unregistered' ) !== false || stripos( $error, 'invalid' ) !== false ) {
				$this->delete_stale_tokens( $this->app_id, [ $token ] );
			}
			
			return false;
		}
	}

	private function delete_stale_tokens( $app_id, $invalid_tokens ) {
		global $wpdb;
		$fcm_table = $wpdb->prefix . 'appress_fcm_devices';
		
		if ( empty( $invalid_tokens ) ) {
			return;
		}
		
		$placeholders = implode( ',', array_fill( 0, count( $invalid_tokens ), '%s' ) );
		$args = array_merge( [ $app_id ], $invalid_tokens );
		
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$fcm_table} WHERE app_id = %d AND token IN ($placeholders)", ...$args ) );
	}
}
