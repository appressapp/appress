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
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->on( 'admin_menu', '@register_menus' );
		$this->on( 'admin_enqueue_scripts', '@enqueue_scripts' );

		// Endpoints for rendering admin UI via Vue
		$this->on( 'appress_ajax_admin.broadcast.get_campaigns', '@handle_get_campaigns' );
		$this->on( 'appress_ajax_admin.broadcast.get_single', '@handle_get_single_campaign' );
		$this->on( 'appress_ajax_admin.broadcast.create', '@handle_create_campaign' );
		$this->on( 'appress_ajax_admin.broadcast.update', '@handle_update_campaign' );
		$this->on( 'appress_ajax_admin.broadcast.send', '@handle_send_campaign' );
		$this->on( 'appress_ajax_admin.broadcast.apps', '@handle_get_apps' );
		$this->on( 'appress_ajax_admin.broadcast.countries', '@handle_get_countries' );
		$this->on( 'appress_ajax_admin.broadcast.bulk_action', '@handle_bulk_action' );
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

	protected function register_menus() {
		add_submenu_page(
			'appress',
			__( 'Broadcast', 'appress' ),
			__( 'Broadcast', 'appress' ),
			'manage_options',
			'appress-broadcast',
			[ $this, 'render_page' ]
		);
	}

	protected function enqueue_scripts() {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( $page !== 'appress-broadcast' ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'appress:admin.css' );
		wp_enqueue_script( 'appress:broadcast.js' );
		// Bootstrap config attached via the proper inline-script API.
		// 'before' position guarantees the JSON literal is in scope when
		// broadcast.js evaluates the Vue mount.
		$payload = wp_json_encode( $this->build_localize() );
		wp_add_inline_script(
			'appress:broadcast.js',
			'window.appressConfig=' . $payload . ';window.appConfig=window.appressConfig;',
			'before'
		);
	}

	private function build_localize(): array {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'appress-broadcast';

		// Shared Vue UI dict + page-specific overrides. Page entries
		// win on collision; see `App\Admin_Controller::build_localize`
		// for the rationale.
		$shared_i18n = file_exists( APPRESS_PLUGIN_DIR . 'app/i18n/vue-strings.php' )
			? require APPRESS_PLUGIN_DIR . 'app/i18n/vue-strings.php'
			: [];

		$page_i18n = [
			'Broadcast' => __( 'Broadcast', 'appress' ),
			'Broadcast messages to audience segments.' => __( 'Broadcast messages to audience segments.', 'appress' ),
			'Push Notifications Panel' => __( 'Push Notifications Panel', 'appress' ),
			'Connect to SaaS Central to broadcast push campaigns.' => __( 'Connect to SaaS Central to broadcast push campaigns.', 'appress' ),
			'Central SaaS Integration Required' => __( 'Central SaaS Integration Required', 'appress' ),
			'Connect Now' => __( 'Connect Now', 'appress' ),
			'Active' => __( 'Active', 'appress' ),
			'Error loading settings' => __( 'Error loading settings', 'appress' ),
			'Request failed' => __( 'Request failed', 'appress' ),
		];

		return [
			'nonce'        => wp_create_nonce( 'appress_admin_action' ),
			'page'         => $page,
			'isOnboarded'  => (bool) \Appress\get( 'package-id' ),
			'i18n'         => array_merge( $shared_i18n, $page_i18n ),
		];
	}

	public function render_page() {
		// Bootstrap config is attached via `wp_add_inline_script` in
		// `enqueue_admin_assets` above. The render only emits the
		// Vue mount template.
		?>
		<div class="wrap" style="margin: 0; padding: 0;">
			<div id="appress-broadcast-app"></div>
		</div>
		<?php
	}

	protected function handle_get_apps() {
		try {
			$this->check_permissions();
			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			$apps = $wpdb->get_results( "SELECT id, app_name, build_information FROM {$table} ORDER BY created_at DESC", ARRAY_A );
			
			$formatted_apps = [];
			if ($apps) {
				foreach ($apps as $app) {
					$build_info = json_decode($app['build_information'] ?? '{}', true) ?: [];
					$formatted_apps[] = [
						'id' => $app['id'],
						'app_name' => $app['app_name'],
						'package_id' => $build_info['package_id'] ?? ''
					];
				}
			}
			
			return wp_send_json([
				'success' => true,
				'data'    => $formatted_apps
			]);
		} catch (\Exception $e) {
			return wp_send_json(['success' => false, 'message' => $e->getMessage()]);
		}
	}

	protected function handle_get_campaigns() {
		try {
			$this->check_permissions();
			global $wpdb;

			$table_name = $wpdb->prefix . 'appress_broadcast';

			$page     = isset( $_GET['page'] ) ? max( 1, intval( $_GET['page'] ) ) : 1;
			$per_page = isset( $_GET['per_page'] ) ? min( 100, max( 5, intval( $_GET['per_page'] ) ) ) : 20;
			$offset   = ( $page - 1 ) * $per_page;
			$app_id   = isset( $_GET['app_id'] ) ? intval( $_GET['app_id'] ) : 0;

			$where_sql = '1=1';
			$args = [];

			if ( $app_id > 0 ) {
				// Search within JSON target_apps array
				$where_sql = "JSON_CONTAINS(target_apps, %s) = 1";
				$args[] = json_encode( $app_id );
			}

			$args[] = $per_page;
			$args[] = $offset;

			$query = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
			$results = $wpdb->get_results( $wpdb->prepare( $query, ...$args ), ARRAY_A );

			if ( ! is_array( $results ) ) {
				$results = [];
			}

			if ( $app_id > 0 ) {
				$total_count_query = $wpdb->prepare( "SELECT COUNT(id) FROM {$table_name} WHERE JSON_CONTAINS(target_apps, %s) = 1", json_encode( $app_id ) );
			} else {
				$total_count_query = "SELECT COUNT(id) FROM {$table_name}";
			}
			$total_count = $wpdb->get_var( $total_count_query );

			return wp_send_json([
				'success' => true,
				'data'    => [
					'items'       => $results,
					'total'       => (int) $total_count,
					'total_pages' => ceil( $total_count / $per_page ),
					'page'        => $page
				]
			]);

		} catch ( \Exception $e ) {
			return wp_send_json([
				'success' => false,
				'message' => $e->getMessage()
			]);
		}
	}

	protected function handle_get_single_campaign() {
		try {
			$this->check_permissions();
			global $wpdb;
			
			$id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
			if ( $id <= 0 ) {
				throw new \Exception( esc_html__( 'Invalid Campaign ID', 'appress' ) );
			}

			$table_name = $wpdb->prefix . 'appress_broadcast';
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ), ARRAY_A );

			if ( ! $campaign ) {
				throw new \Exception( esc_html__( 'Campaign not found', 'appress' ) );
			}

			// Enrich with device count breakdown
			$fcm_table = $wpdb->prefix . 'appress_fcm_devices';
			$target_apps = json_decode( $campaign['target_apps'] ?? '[]', true ) ?: [];
			$target_platforms = json_decode( $campaign['target_platforms'] ?? '[]', true ) ?: [];
			$payload_data = json_decode( stripslashes( $campaign['payload'] ?? '{}' ), true ) ?: [];
			$target_countries = $payload_data['target_countries'] ?? [];

			$device_stats = [ 'total' => 0, 'targeted' => 0, 'android' => 0, 'ios' => 0 ];
			if ( ! empty( $target_apps ) ) {
				$app_placeholders = implode( ',', array_fill( 0, count( $target_apps ), '%d' ) );

				// Total: all devices for target apps (no filters)
				$device_stats['total'] = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$fcm_table} WHERE app_id IN ($app_placeholders)", ...$target_apps
				) );

				// Targeted: filtered by platform + country
				$filter_sql = '';
				$filter_args = $target_apps;

				if ( ! empty( $target_platforms ) ) {
					$plat_placeholders = implode( ',', array_fill( 0, count( $target_platforms ), '%s' ) );
					$filter_sql .= " AND platform IN ($plat_placeholders)";
					$filter_args = array_merge( $filter_args, $target_platforms );
				}

				if ( ! empty( $target_countries ) && is_array( $target_countries ) ) {
					$country_placeholders = implode( ',', array_fill( 0, count( $target_countries ), '%s' ) );
					$filter_sql .= " AND country IN ($country_placeholders)";
					$filter_args = array_merge( $filter_args, $target_countries );
				}

				$device_stats['targeted'] = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$fcm_table} WHERE app_id IN ($app_placeholders){$filter_sql}", ...$filter_args
				) );

				$device_stats['android'] = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$fcm_table} WHERE app_id IN ($app_placeholders) AND platform = 'android'", ...$target_apps
				) );
				$device_stats['ios'] = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$fcm_table} WHERE app_id IN ($app_placeholders) AND platform = 'ios'", ...$target_apps
				) );
			}
			$campaign['device_stats'] = $device_stats;

			return wp_send_json([
				'success' => true,
				'data'    => $campaign
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}

	protected function handle_get_countries() {
		try {
			$this->check_permissions();
			global $wpdb;
			$fcm_table = $wpdb->prefix . 'appress_fcm_devices';
			$rows = $wpdb->get_results(
				"SELECT country, COUNT(*) as device_count FROM {$fcm_table} WHERE country != '' GROUP BY country ORDER BY device_count DESC"
			);
			$countries = [];
			$names = $this->get_country_names();
			foreach ( $rows as $row ) {
				$code = strtoupper( $row->country );
				$countries[] = [
					'value' => $code,
					'label' => ( $names[ $code ] ?? $code ) . ' (' . $row->device_count . ')',
				];
			}
			return wp_send_json( [ 'success' => true, 'data' => $countries ] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	private function get_country_names() {
		return [
			'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada',
			'AU' => 'Australia', 'DE' => 'Germany', 'FR' => 'France', 'JP' => 'Japan',
			'KR' => 'South Korea', 'VN' => 'Vietnam', 'SG' => 'Singapore', 'IN' => 'India',
			'BR' => 'Brazil', 'MX' => 'Mexico', 'IT' => 'Italy', 'ES' => 'Spain',
			'NL' => 'Netherlands', 'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark',
			'FI' => 'Finland', 'PL' => 'Poland', 'TH' => 'Thailand', 'ID' => 'Indonesia',
			'MY' => 'Malaysia', 'PH' => 'Philippines', 'TW' => 'Taiwan', 'HK' => 'Hong Kong',
			'NZ' => 'New Zealand', 'IE' => 'Ireland', 'CH' => 'Switzerland', 'AT' => 'Austria',
			'BE' => 'Belgium', 'PT' => 'Portugal', 'CZ' => 'Czech Republic', 'RO' => 'Romania',
			'HU' => 'Hungary', 'GR' => 'Greece', 'IL' => 'Israel', 'AE' => 'UAE',
			'SA' => 'Saudi Arabia', 'ZA' => 'South Africa', 'AR' => 'Argentina', 'CL' => 'Chile',
			'CO' => 'Colombia', 'PE' => 'Peru', 'EG' => 'Egypt', 'NG' => 'Nigeria',
			'KE' => 'Kenya', 'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'LK' => 'Sri Lanka',
			'RU' => 'Russia', 'UA' => 'Ukraine', 'TR' => 'Turkey', 'CN' => 'China',
		];
	}

	protected function handle_create_campaign() {
		try {
			$this->check_permissions();
			global $wpdb;

			// Form-urlencoded body (`postForm` from Vue admin) — see
			// `apps-controller@delete_app` for the full WAF-compat note.
			// Nonce verified inside check_permissions() above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$data             = wp_unslash( $_POST );
			$target_apps      = isset( $data['target_apps'] ) && is_array( $data['target_apps'] ) ? array_map('intval', $data['target_apps']) : [];
			$target_platforms = isset( $data['target_platforms'] ) && is_array( $data['target_platforms'] ) ? array_map('sanitize_text_field', $data['target_platforms']) : [];
			
			$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
			$body  = isset( $data['body'] ) ? sanitize_textarea_field( $data['body'] ) : '';
			$image = isset( $data['image'] ) ? sanitize_text_field( $data['image'] ) : '';
			$url   = isset( $data['url'] ) ? sanitize_text_field( $data['url'] ) : '';

			if ( empty( $title ) ) {
				throw new \Exception( esc_html__( 'Title is required.', 'appress' ) );
			}
			if ( empty( $target_apps ) ) {
				throw new \Exception( esc_html__( 'At least one target application is required.', 'appress' ) );
			}

			$broadcast_table = $wpdb->prefix . 'appress_broadcast';
			$target_countries = isset( $data['target_countries'] ) && is_array( $data['target_countries'] ) ? array_map('sanitize_text_field', $data['target_countries']) : [];
			$payload_data = [ 'image' => $image, 'url' => $url, 'target_countries' => $target_countries ];
			$filter_context = [
				'title'            => $title,
				'body'             => $body,
				'image'            => $image,
				'url'              => $url,
				'target_apps'      => $target_apps,
				'target_platforms' => $target_platforms,
				'target_countries' => $target_countries,
			];
			$payload_data = apply_filters( 'appress/broadcast/campaign_payload', $payload_data, $filter_context );
			$payload = json_encode( $payload_data );

			$stats = json_encode([ 'sent' => 0, 'read' => 0 ]);

			$wpdb->insert(
				$broadcast_table,
				[
					'title'            => wp_slash($title),
					'body'             => wp_slash($body),
					'payload'          => wp_slash($payload),
					'target_apps'      => json_encode(array_values($target_apps)),
					'target_platforms' => json_encode(array_values($target_platforms)),
					'stats'            => wp_slash($stats),
					'status'           => 'draft',
				]
			);
			$campaign_id = $wpdb->insert_id;

			return wp_send_json([
				'success' => true,
				'data'    => [ 'id' => $campaign_id ],
				'message' => 'Campaign drafted successfully.'
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([
				'success' => false,
				'message' => $e->getMessage()
			]);
		}
	}

	protected function handle_update_campaign() {
		try {
			$this->check_permissions();
			global $wpdb;

			// Form-urlencoded body (`postForm` from Vue admin) — see
			// `apps-controller@delete_app` for the full WAF-compat note.
			// Nonce verified inside check_permissions() above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$data             = wp_unslash( $_POST );
			$id               = isset( $data['id'] ) ? intval( $data['id'] ) : 0;
			$target_apps      = isset( $data['target_apps'] ) && is_array( $data['target_apps'] ) ? array_map('intval', $data['target_apps']) : [];
			$target_platforms = isset( $data['target_platforms'] ) && is_array( $data['target_platforms'] ) ? array_map('sanitize_text_field', $data['target_platforms']) : [];
			
			$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
			$body  = isset( $data['body'] ) ? sanitize_textarea_field( $data['body'] ) : '';
			$image = isset( $data['image'] ) ? sanitize_text_field( $data['image'] ) : '';
			$url   = isset( $data['url'] ) ? sanitize_text_field( $data['url'] ) : '';

			if ( $id <= 0 ) {
				throw new \Exception( esc_html__( 'Invalid campaign ID.', 'appress' ) );
			}
			if ( empty( $title ) ) {
				throw new \Exception( esc_html__( 'Title is required.', 'appress' ) );
			}
			if ( empty( $target_apps ) ) {
				throw new \Exception( esc_html__( 'At least one target application is required.', 'appress' ) );
			}

			$broadcast_table = $wpdb->prefix . 'appress_broadcast';
			
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$broadcast_table} WHERE id = %d", $id ) );
			if ( ! $campaign || $campaign->status !== 'draft' ) {
				throw new \Exception( esc_html__( 'Only draft campaigns can be edited.', 'appress' ) );
			}

			$target_countries = isset( $data['target_countries'] ) && is_array( $data['target_countries'] ) ? array_map('sanitize_text_field', $data['target_countries']) : [];
			$payload_data = [ 'image' => $image, 'url' => $url, 'target_countries' => $target_countries ];
			$filter_context = [
				'title'            => $title,
				'body'             => $body,
				'image'            => $image,
				'url'              => $url,
				'target_apps'      => $target_apps,
				'target_platforms' => $target_platforms,
				'target_countries' => $target_countries,
			];
			$payload_data = apply_filters( 'appress/broadcast/campaign_payload', $payload_data, $filter_context );
			$payload = json_encode( $payload_data );

			$wpdb->update(
				$broadcast_table,
				[
					'title'            => wp_slash($title),
					'body'             => wp_slash($body),
					'payload'          => wp_slash($payload),
					'target_apps'      => json_encode(array_values($target_apps)),
					'target_platforms' => json_encode(array_values($target_platforms))
				],
				[ 'id' => $id ]
			);

			return wp_send_json([
				'success' => true,
				'data'    => [ 'id' => $id ],
				'message' => 'Campaign updated successfully.'
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([
				'success' => false,
				'message' => $e->getMessage()
			]);
		}
	}

	protected function handle_send_campaign() {
		try {
			$this->check_permissions();
			global $wpdb;

			// Form-urlencoded body (`postForm` from Vue admin) — see
			// `apps-controller@delete_app` for the full WAF-compat note.
			// Nonce verified inside check_permissions() above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$data = wp_unslash( $_POST );
			$id   = isset( $data['campaign_id'] ) ? intval( $data['campaign_id'] ) : 0;

			if ( $id <= 0 ) {
				throw new \Exception( esc_html__( 'Campaign ID is required.', 'appress' ) );
			}

			$broadcast_table = $wpdb->prefix . 'appress_broadcast';
			$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$broadcast_table} WHERE id = %d", $id ), ARRAY_A );

			if ( ! $campaign ) {
				throw new \Exception( esc_html__( 'Campaign not found.', 'appress' ) );
			}
			if ( $campaign['status'] === 'sent' ) {
				throw new \Exception( esc_html__( 'Campaign has already been sent.', 'appress' ) );
			}

			// Update campaign status to queued
			$wpdb->update(
				$broadcast_table,
				[ 'status' => 'queued' ],
				[ 'id' => $id ]
			);

			// Fire the first cron tick INLINE — `populate_queue` runs in
			// this request, so the campaign immediately transitions
			// queued → sending and queue rows land in DB before the
			// admin sees the response. Without this, sites running
			// `DISABLE_WP_CRON=true` + system cron at 1-min interval
			// would have admins click Send Now and see "queued" for up
			// to 60s before the first tick. Subsequent drain ticks
			// still fire via system cron — only the populate is moved
			// inline. Idempotent: cron_send_campaign short-circuits if
			// status !== 'queued', so any later WP-Cron-scheduled run
			// is harmless.
			do_action( 'appress_broadcast_send_campaign_cron', $id );

			// Schedule wp-cron task as fallback for subsequent drain ticks
			// — system cron should fire it, but the WP scheduling keeps
			// behavior compatible with sites that re-enable WP-Cron.
			if ( ! wp_next_scheduled( 'appress_broadcast_send_campaign_cron', [ $id ] ) ) {
				wp_schedule_single_event( time(), 'appress_broadcast_send_campaign_cron', [ $id ] );
			}

			return wp_send_json([
				'success' => true,
				'message' => 'Campaign queued for sending.'
			]);

		} catch ( \Exception $e ) {
			return wp_send_json([
				'success' => false,
				'message' => $e->getMessage()
			]);
		}
	}

	protected function handle_bulk_action() {
		try {
			$this->check_permissions();
			global $wpdb;

			// Form-urlencoded body (`postForm` from Vue admin) — see
			// `apps-controller@delete_app` for the full WAF-compat note.
			// Nonce verified inside check_permissions() above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$data   = wp_unslash( $_POST );
			$action = isset( $data['bulk_action'] ) ? sanitize_text_field( $data['bulk_action'] ) : '';
			$ids    = isset( $data['campaign_ids'] ) && is_array( $data['campaign_ids'] ) ? array_map('intval', $data['campaign_ids']) : [];

			if ( empty( $action ) || empty( $ids ) ) {
				throw new \Exception( esc_html__( 'Action and Campaign IDs are required.', 'appress' ) );
			}

			$table = $wpdb->prefix . 'appress_broadcast';
			$placeholders = implode(',', array_fill(0, count($ids), '%d'));

			if ( $action === 'delete' ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ($placeholders)", ...$ids ) );
				return wp_send_json([ 'success' => true, 'message' => 'Campaigns deleted.' ]);
			}

			if ( $action === 'send' ) {
				// We update all 'draft' campaigns to 'queued', skip 'sent'
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'queued' WHERE status = 'draft' AND id IN ($placeholders)", ...$ids ) );

				foreach ( $ids as $id ) {
					// Inline first tick — same rationale as single-send
					// `handle_send` above: ensures queued → sending +
					// populate_queue runs immediately, not on the next
					// 1-min system cron tick.
					do_action( 'appress_broadcast_send_campaign_cron', $id );
					if ( ! wp_next_scheduled( 'appress_broadcast_send_campaign_cron', [ $id ] ) ) {
						wp_schedule_single_event( time(), 'appress_broadcast_send_campaign_cron', [ $id ] );
					}
				}

				return wp_send_json([ 'success' => true, 'message' => 'Campaigns queued for sending.' ]);
			}

			throw new \Exception( esc_html__( 'Invalid action.', 'appress' ) );
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}
}
