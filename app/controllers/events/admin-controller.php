<?php
namespace Appress\Controllers\Events;

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

class Admin_Controller extends Base_Controller {

	protected function hooks() {
		// Events UI lives inside the Integrations page (tabs: Overview /
		// Events / IAP). The AJAX endpoints stay here because they're
		// the data source the unified view reads — integration plugins
		// register their schema against the same `appress/events`
		// filter the `admin.events.schema` handler resolves below.
		$this->on( 'appress_ajax_admin.events.schema', '@handle_get_schema' );
		$this->on( 'appress_ajax_admin.events.get', '@handle_get_settings' );
		$this->on( 'appress_ajax_admin.events.save', '@handle_save_settings' );
		$this->on( 'appress_ajax_admin.events.apps', '@handle_get_apps' );
	}

	protected function check_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( esc_html__( 'Permission denied.', 'appress' ) );
		}

		// Verify CSRF nonce from request header (consistent with other Appress controllers)
		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'appress_admin_action' ) ) {
			throw new \Exception( esc_html__( 'Security verification failed. Please refresh and try again.', 'appress' ) );
		}
	}

	protected function handle_get_apps() {
		try {
			$this->check_permissions();
			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			// package_id lives inside build_information JSON — mirror broadcast admin-controller pattern
			$apps  = $wpdb->get_results( "SELECT id, app_name, build_information FROM {$table} ORDER BY created_at DESC", ARRAY_A );

			$formatted = [];
			if ( $apps ) {
				foreach ( $apps as $app ) {
					$build_info = json_decode( $app['build_information'] ?? '{}', true ) ?: [];
					$formatted[] = [
						'id'         => (int) $app['id'],
						'app_name'   => $app['app_name'],
						'package_id' => $build_info['package_id'] ?? '',
					];
				}
			}

			return wp_send_json([
				'success' => true,
				'data'    => $formatted,
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}

	protected function handle_get_schema() {
		try {
			$this->check_permissions();
			$schema = Event::get_all();

			// Per-integration plugin-availability metadata so the
			// admin events panel can distinguish:
			//   - "WooCommerce / Voxel not installed" → install CTA
			//   - "Plugin installed but publishes no events"
			//   - "Plugin installed + has events"
			// Without this, both empty cases render the same generic
			// "no events" message and the admin can't tell the
			// integration needs WP plugin install.
			$registered = (array) apply_filters( 'appress/integrations/registered', \Appress\config( 'integrations' ) ?: [] );
			$integrations_meta = [];
			foreach ( $registered as $id => $entry ) {
				$req = isset( $entry['requires_plugin'] ) && is_array( $entry['requires_plugin'] )
					? $entry['requires_plugin']
					: [];
				$class_name = isset( $req['class'] ) ? (string) $req['class'] : '';
				$integrations_meta[ $id ] = [
					'name'      => isset( $entry['name'] ) ? (string) $entry['name'] : (string) $id,
					'requires'  => $class_name !== '' ? [
						'name' => isset( $req['name'] ) ? (string) $req['name'] : '',
					] : null,
					'installed' => $class_name === '' ? true : class_exists( $class_name ),
				];
			}

			return wp_send_json([
				'success' => true,
				'data'    => $schema,
				'meta'    => [ 'integrations' => $integrations_meta ],
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}

	protected function handle_get_settings() {
		try {
			$this->check_permissions();
			$settings = \Appress\get( 'events', [] );

			return wp_send_json([
				'success' => true,
				'data'    => $settings
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}

	protected function handle_save_settings() {
		try {
			$this->check_permissions();
			// Form-urlencoded body (`postForm` from Vue admin) — see
			// `apps-controller@delete_app` for the full WAF-compat note.
			// Nonce verified inside check_permissions() above.
			//
			// CONTRACT: this `wp_unslash` runs ONCE at the entry point.
			// Every value extracted from `$data` below is already
			// once-unslashed; the per-field `sanitize_*` calls MUST NOT
			// re-apply `wp_unslash`. Double-unslashing strips the
			// backslash from `\n` escape sequences (becomes literal `n`)
			// and silently corrupts any field with embedded backslashes
			// (template tokens, JSON, PEM bodies). See the production
			// incident on saigonstays.com 2026-05-06 where this exact
			// pattern in apps-controller's sanitize_field destroyed every
			// Firebase service-account private key — pushes silently
			// failed for days with `OpenSSL unable to validate key`.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$data = wp_unslash( $_POST );

		if ( ! is_array( $data ) ) {
			return wp_send_json([ 'success' => false, 'message' => 'Invalid data payload.' ]);
		}

		// Save securely mapping only standard formats
		$settings = \Appress\get( 'events', [] );

		// Expecting $data to be: [ 'integration_id' => 'voxel', 'event_id' => '...', 'config' => [] ]
		$integration_id = isset( $data['integration_id'] ) ? sanitize_text_field( $data['integration_id'] ) : '';
		$event_id       = isset( $data['event_id'] ) ? sanitize_text_field( $data['event_id'] ) : '';
		$config         = isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : [];

		if ( empty( $integration_id ) || empty( $event_id ) ) {
			return wp_send_json([ 'success' => false, 'message' => 'Missing identifiers.' ]);
		}

		if ( ! isset( $settings[ $integration_id ] ) ) {
			$settings[ $integration_id ] = [];
		}

		// Look up schema to decide which shape to persist:
		//   • Destination-aware events (WooCommerce Base_WC_Event, future vendor plugins)
		//     expose `destinations` in their schema — we fan target_apps + templates out
		//     PER destination bucket (customer / admin / vendor / …).
		//   • Legacy events (Voxel forwards, custom integrations) stay on the flat shape.
		$schema       = Event::get_all();
		$event_schema = $schema[ $integration_id ]['events'][ $event_id ] ?? [];
		$destinations = isset( $event_schema['destinations'] ) && is_array( $event_schema['destinations'] ) ? $event_schema['destinations'] : [];
		$has_content  = ! isset( $event_schema['has_content'] ) || $event_schema['has_content'] !== false;

		if ( ! empty( $destinations ) ) {
			$dest_config = isset( $config['destinations'] ) && is_array( $config['destinations'] ) ? $config['destinations'] : [];
			$normalized  = [];
			$any_on      = false;
			foreach ( array_keys( $destinations ) as $dest_key ) {
				$src = isset( $dest_config[ $dest_key ] ) && is_array( $dest_config[ $dest_key ] ) ? $dest_config[ $dest_key ] : [];
				$on  = isset( $src['enabled'] ) ? rest_sanitize_boolean( $src['enabled'] ) : false;
				$any_on = $any_on || $on;
				// $src already once-unslashed at L134 — no second wp_unslash here.
				$normalized[ $dest_key ] = [
					'enabled'     => $on,
					'target_apps' => isset( $src['target_apps'] ) ? array_map( 'intval', (array) $src['target_apps'] ) : [],
					'subject'     => isset( $src['subject'] ) ? sanitize_text_field( (string) $src['subject'] ) : '',
					'body'        => isset( $src['body'] ) ? sanitize_textarea_field( (string) $src['body'] ) : '',
					'url'         => isset( $src['url'] ) ? sanitize_text_field( (string) $src['url'] ) : '',
					'image'       => isset( $src['image'] ) ? esc_url_raw( (string) $src['image'] ) : '',
				];
			}
			$row = [
				'enabled'      => $any_on,
				'destinations' => $normalized,
			];
		} else {
			$row = [
				'enabled'          => isset( $config['enabled'] ) ? rest_sanitize_boolean( $config['enabled'] ) : false,
				'target_apps'      => isset( $config['target_apps'] ) ? array_map( 'intval', $config['target_apps'] ) : [],
				'mode'             => ( isset( $config['mode'] ) && in_array( $config['mode'], [ 'voxel', 'custom' ], true ) ) ? $config['mode'] : 'voxel',
				'override_enabled' => isset( $config['override_enabled'] ) ? rest_sanitize_boolean( $config['override_enabled'] ) : false,
			];

			// Only persist notification content for events that actually author their own copy.
			// Forwarding events (has_content=false) pull title/body/image/url from the source at dispatch time.
			// $config already once-unslashed at L134 — no second wp_unslash here.
			if ( $has_content ) {
				$row['title'] = isset( $config['title'] ) ? sanitize_text_field( (string) $config['title'] ) : '';
				$row['body']  = isset( $config['body'] ) ? sanitize_textarea_field( (string) $config['body'] ) : '';
				$row['image'] = isset( $config['image'] ) ? esc_url_raw( $config['image'] ) : '';
				$row['url']   = isset( $config['url'] ) ? sanitize_text_field( $config['url'] ) : '';
			}
		}

		$settings[ $integration_id ][ $event_id ] = $row;

		\Appress\set( 'events', $settings );

			return wp_send_json([
				'success' => true,
				'message' => 'Settings saved successfully.',
				'data'    => $settings
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}
}
