<?php

namespace Appress\Controllers\Preview;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preview config endpoint — feeds the Appress host app's
 * `AppressPreviewController` everything it needs to bootstrap a nested
 * RoutingController instance for one customer app.
 *
 * Cross-origin auth model
 * -----------------------
 * The host app's master WebView lives on Central (`my.appress.app`)
 * and POSTs here from a different origin, so admin cookies don't ride
 * along. Authentication is the same `connection_token` Central already
 * issued the customer site at pair time:
 *
 *   1. Host app reads `data-token` off the Elementor preview button
 *      (decrypted server-side by Central from `connection_token` post
 *      meta on the matching `app` Voxel post).
 *   2. Host app POSTs `{ token: "..." }` to this endpoint.
 *   3. We hash it via the shared `lookup_hash` and match against
 *      `wp_appress_apps.connection_token_lookup`. Match → return
 *      config. No match → 401.
 *
 * Both `appress_ajax_*` AND `appress_ajax_nopriv_*` hooks register
 * because the cross-origin call lands without a WP login session even
 * if the requester happens to be admin somewhere else (mirrors the
 * `feedback_ajax_hooks` rule).
 *
 * Output shape
 * ------------
 * Same hydrated layout the regular `app.get` admin endpoint emits via
 * `parse_with_schema()` — so the native side can reuse the existing
 * config consumers — minus anything secret. Stripped fields:
 *   - connection_token / lookup hash
 *   - signing_secret
 *   - credentials block (encrypted at rest)
 *   - keystore raw / passwords / aliases
 *   - p8 / p12 / service-account JSON blobs (any *_raw or path field)
 *
 * Plus a `screens` array enumerating every published `appress_screen`
 * CPT post (id + title + permalink + slug). The native router uses
 * `permalink` as the WebView URL and `slug` for deep-link matching.
 *
 * Throttling
 * ----------
 * One transient lock per token (5-second window) prevents hammering
 * the endpoint while still allowing the host app to retry on transient
 * failures. Token is hashed before use as the cache key so the raw
 * value never lands in object cache.
 */
class Preview_Controller extends Base_Controller {

	const THROTTLE_SECONDS = 5;

	/**
	 * Postmeta keys / config sub-keys to strip from the response. Belt
	 * + suspenders: the schema-driven hydration in `parse_with_schema`
	 * already excludes most secrets, but we re-strip here so a future
	 * field addition that forgets to mark itself sensitive doesn't leak.
	 */
	const SENSITIVE_KEYS = [
		'connection_token',
		'connection_token_lookup',
		'signing_secret',
		'credentials',
		'firebase_service_account_json',
		'apple_p8_key',
		'apple_p8_path',
		'android_keystore_raw',
		'android_keystore_password',
		'android_keystore_alias',
		'play_service_account_json',
	];

	protected function hooks() {
		$this->on( 'appress_ajax_app.preview_config',        '@handle_preview_config' );
		$this->on( 'appress_ajax_nopriv_app.preview_config', '@handle_preview_config' );
	}

	protected function handle_preview_config() {
		try {
			$params = wp_unslash( $_POST );
			$token  = isset( $params['token'] ) ? sanitize_text_field( (string) $params['token'] ) : '';
			if ( $token === '' ) {
				throw new \Exception( esc_html__( 'Missing token.', 'appress' ) );
			}

			$throttle_key = 'appress_preview_throttle_' . md5( $token );
			if ( get_transient( $throttle_key ) ) {
				throw new \Exception( esc_html__( 'Too many preview requests. Please wait and try again.', 'appress' ) );
			}
			set_transient( $throttle_key, 1, self::THROTTLE_SECONDS );

			global $wpdb;
			$table  = $wpdb->prefix . 'appress_apps';
			$lookup = \Appress\lookup_hash( $token );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE connection_token_lookup = %s LIMIT 1", $lookup ), ARRAY_A );
			if ( ! $row ) {
				throw new \Exception( esc_html__( 'Invalid or revoked token.', 'appress' ) );
			}

			$config = $this->build_preview_config( $row );

			return wp_send_json( [
				'success' => true,
				'data'    => $config,
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}

	/**
	 * Hydrate the row into the same shape as `Apps_Controller::get_config`
	 * but without secrets, plus screen metadata. We deliberately re-implement
	 * the schema walk locally instead of calling `parse_with_schema()` because
	 * that method is `private` to `Apps_Controller` and exposing it just for
	 * preview would couple the two controllers more than necessary; the
	 * schema is the same input either way.
	 */
	private function build_preview_config( array $row ): array {
		$schema   = \Appress\config( 'schema' );
		$hydrated = [];

		foreach ( $schema as $category => $category_config ) {
			if ( empty( $category_config['fields'] ) ) {
				continue;
			}

			$raw_column = $row[ $category ] ?? '';
			// `credentials` is encrypted at rest in the DB column. Skip
			// entirely for preview — secrets never leave the customer site.
			if ( $category === 'credentials' ) {
				continue;
			}
			$saved = $raw_column !== '' ? json_decode( (string) $raw_column, true ) : [];
			if ( ! is_array( $saved ) ) {
				$saved = [];
			}

			foreach ( $category_config['fields'] as $key => $field_config ) {
				if ( in_array( $key, self::SENSITIVE_KEYS, true ) ) {
					continue;
				}
				$hydrated[ $key ] = $saved[ $key ] ?? $field_config['default'] ?? null;
			}
		}

		// Defensive: drop any sensitive top-level keys the schema walk
		// might have surfaced from an unexpected source.
		foreach ( self::SENSITIVE_KEYS as $sk ) {
			unset( $hydrated[ $sk ] );
		}

		// Public, non-sensitive identifiers the native side needs to
		// namespace state and address the WebView.
		$hydrated['app_id']   = (int) $row['id'];
		$hydrated['app_name'] = (string) ( $row['app_name'] ?? '' );
		$hydrated['screens']  = $this->collect_screens();

		// Mirror the same enrichment chain `Frontend_Controller::handle_boot`
		// runs for the regular `app.boot` payload. Without these, the
		// preview app would see RAW admin-saved values and miss every
		// integration-contributed override:
		//   - Voxel's `App_Css_Controller` adds `.vx-full-popup` /
		//     `.ts-popup-root` tweaks via the `appress/app/css` filter
		//   - Voxel's `Subscreen_Patterns_Controller` injects
		//     `/job-archive/*` etc. via `appress/app/subscreen_url_patterns`
		//   - inline link selectors come from
		//     `appress/app/inline_link_selectors` (admin textarea +
		//     integration defaults)
		// Without the merge, the customer's host-app preview render
		// drifts from a real production app build — bug surfaced by
		// the missing Voxel popup-top spacing rule on keden.pro.
		$app_id = (int) $row['id'];
		$hydrated['inline_link_selectors']  = \Appress\get_inline_link_selectors( $app_id );
		$hydrated['subscreen_url_patterns'] = \Appress\get_subscreen_url_patterns( $app_id );
		$hydrated = array_merge( $hydrated, \Appress\get_app_css( $app_id ) );

		// Last-mile filter — same hook the live boot endpoint runs in
		// `Frontend_Controller::handle_boot()`. Required so integrations
		// that enrich the boot payload (TranslatePress's `translatepress`
		// block of per-language variants, future filter-based blocks)
		// also reach the host app during a preview session. Without this,
		// the customer's TRP languages never arrive → `handleChangeLanguage`
		// in the native host bails at the variant lookup and language
		// switching from inside Preview is a silent no-op.
		$hydrated = (array) apply_filters( 'appress/app/live_config', $hydrated, $app_id );

		return $hydrated;
	}

	/**
	 * Enumerate every published `appress_screen` CPT post for this site.
	 * The native router needs `id` (post ID), `title`, `slug`, and
	 * `permalink` (the URL that lands in the WebView). Fields kept tight
	 * so we don't ship arbitrary postmeta over the wire — only what the
	 * router actually consumes.
	 */
	private function collect_screens(): array {
		$ids = get_posts( [
			'post_type'      => 'appress_screen',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'fields'         => 'ids',
		] );

		$screens = [];
		foreach ( (array) $ids as $id ) {
			$screens[] = [
				'id'        => (int) $id,
				'title'     => (string) get_the_title( $id ),
				'slug'      => (string) get_post_field( 'post_name', $id ),
				'permalink' => (string) get_permalink( $id ),
			];
		}
		return $screens;
	}
}
