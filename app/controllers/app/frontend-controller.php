<?php

namespace Appress\Controllers\App;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
use Appress\Controllers\Base_Controller;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Public-facing controller that serves the boot payload to the native app on
 * every app launch.
 *
 * Endpoint: /?appress=1&action=app.boot&app_id=15&version=<hash>
 *   - Returns the Live App Builder config.
 *   - Schedules an async `appress/app/booted` hook in WP-Cron for extensions
 *     (last-active tracking, Automator triggers, analytics) to piggyback on
 *     without delaying the native response.
 */
class Frontend_Controller extends Base_Controller
{

	protected function hooks()
	{
		// Mobile-only — first request a freshly-installed app fires to
		// pull its native config. Register on each app's `<class_id>_ajax_*`
		// + legacy `appress_ajax_*` for backward compat.
		$this->on_mobile( 'app.boot', '@handle_boot' );

		// First-install detection on login from inside the app. Covers BOTH paths:
		// (a) user opens app → logs in for the first time (UA = Appress/{appId}).
		// (b) user opens app while already logged-in → not detected here, but ANY
		//     authenticated request from the app surfaces the UA, so wp_login is
		//     the canonical entry that distinguishes "first install" reliably.
		$this->on('wp_login', '@detect_first_install_on_login', 10, 2);

		// Async dispatcher: scheduled by the detector below; runs in cron so any
		// heavy Automator recipe doesn't block the page that triggered the login.
		$this->on('appress/user/installed_app_async', '@dispatch_install_event', 10, 3);

		// Async dispatcher for the boot signal — runs in cron so Automator
		// recipes, analytics hooks, and last-active writes don't block the
		// synchronous response the native app is waiting for.
		$this->on('appress/app/booted_async', '@dispatch_booted_event', 10, 3);
	}

	protected function detect_first_install_on_login($user_login, $user)
	{
		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( ! preg_match('/Appress\\/(\\d+)/i', $ua, $m) ) {
			return;
		}

		$user_id = (int) ( $user instanceof \WP_User ? $user->ID : 0 );
		$app_id  = (int) $m[1];
		if ( $user_id <= 0 || $app_id <= 0 ) {
			return;
		}

		$installed = (array) get_user_meta($user_id, 'appress_installed_apps', true);
		if ( in_array($app_id, array_map('intval', $installed), true) ) {
			return; // Already counted — semantic is FIRST install per (user, app).
		}

		$installed[] = $app_id;
		update_user_meta($user_id, 'appress_installed_apps', $installed);

		$platform = stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false ? 'ios'
			: ( stripos($ua, 'Android') !== false ? 'android' : '' );

		// Fire-and-forget — Automator recipes may be heavy.
		if ( ! wp_next_scheduled('appress/user/installed_app_async', [$user_id, $app_id, $platform]) ) {
			wp_schedule_single_event(time(), 'appress/user/installed_app_async', [$user_id, $app_id, $platform]);
			spawn_cron();
		}
	}

	protected function dispatch_install_event($user_id, $app_id, $platform)
	{
		do_action('appress/user/installed_app', (int) $user_id, (int) $app_id, (string) $platform);
	}

	/**
	 * Cron handler for the boot signal. Fires the public `appress/app/booted`
	 * hook for extensions to listen to (last-active tracking, analytics,
	 * Automator triggers). Running in cron keeps Automator recipe cost off
	 * the critical path of the native app's boot request.
	 *
	 * @param int    $app_id   App ID from the boot request.
	 * @param int    $user_id  WP user ID (0 for guest / unauthenticated device).
	 * @param string $platform 'ios' | 'android' | '' (sniffed from UA).
	 */
	protected function dispatch_booted_event($app_id, $user_id, $platform)
	{
		do_action('appress/app/booted', (int) $app_id, (int) $user_id, (string) $platform);
	}

	/**
	 * Schedule the boot signal for async dispatch. Deduplicated per
	 * (app_id, user_id) with a short window so rapid foreground/background
	 * toggles don't queue a fresh cron event on every tap.
	 */
	private function schedule_booted_event($app_id, $user_id, $platform)
	{
		if ( $app_id <= 0 ) {
			return;
		}
		$args = [ $app_id, $user_id, $platform ];
		// wp_next_scheduled returns a timestamp if already queued with identical args.
		if ( wp_next_scheduled('appress/app/booted_async', $args) ) {
			return;
		}
		wp_schedule_single_event(time(), 'appress/app/booted_async', $args);
		spawn_cron();
	}

	/**
	 * Endpoint: return live config payload (or `up_to_date` when the client
	 * already has the latest version hash) and schedule the async boot hook
	 * for extensions. This runs on EVERY app launch.
	 *
	 * Auth model: PUBLIC by design. The endpoint is also registered as
	 * `nopriv` because compiled mobile apps query it on cold start without
	 * a WordPress session. The returned `live_config` is the same payload
	 * that gets compiled into the public APK / IPA — UI tokens, screen
	 * URLs, public Firebase project IDs, ad unit IDs. There is no
	 * user-specific data and no secret material; admin-only credentials
	 * (signing keys, service account JSON, keystore passwords) live in a
	 * separate `build_information` column that is NEVER returned by this
	 * handler. A standard WordPress nonce isn't applicable to a stateless
	 * mobile client; the response is rate-limited at the platform layer
	 * (cache headers + WP-cron throttling on the async hook below).
	 */
	protected function handle_boot()
	{
		try {
			$app_id = isset($_GET['app_id']) ? intval($_GET['app_id']) : 0;
			if ( empty($app_id) ) {
				throw new \Exception( esc_html__( 'App ID is required.', 'appress' ) );
			}

			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare("SELECT live_config FROM {$wpdb->prefix}appress_apps WHERE id = %d", $app_id), ARRAY_A );

			if ( ! $row ) {
				throw new \Exception( esc_html__( 'Application not found.', 'appress' ) );
			}

			$live_config = !empty($row['live_config']) ? json_decode($row['live_config'], true) : [];
 
			$client_version = isset($_GET['version']) ? sanitize_text_field(wp_unslash($_GET['version'])) : '0';
 
			$server_version = (string) ($live_config['update_time_hash'] ?? '0');

			// Queue the async boot hook BEFORE returning. wp_schedule_single_event
			// is a cheap DB insert (<1ms) and spawn_cron() is non-blocking, so this
			// does not meaningfully delay the response.
			$ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			$platform = ( stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false ) ? 'ios'
				: ( stripos($ua, 'Android') !== false ? 'android' : '' );
			$this->schedule_booted_event( $app_id, (int) get_current_user_id(), $platform );

			// Return early if version matches
			if ($client_version === $server_version && $server_version !== '0') {
				return wp_send_json([
					'success' => true,
					'status'  => 'up_to_date',
					'message' => 'App configuration is up-to-date.'
				]);
			}

			$live_config['update_hash'] = $server_version;

			// Normalized + filter-merged list, same helper the in-page
			// `wp_head` script uses — single source of truth so native
			// cache + fresh page render can't drift.
			$live_config['inline_link_selectors'] = \Appress\get_inline_link_selectors( $app_id );

			// URL patterns that force subscreen routing (admin opt-in +
			// integration defaults). Native interceptors read this list
			// from the boot config — no JS bridge needed since the
			// match happens at navigation time, not click time.
			$live_config['subscreen_url_patterns'] = \Appress\get_subscreen_url_patterns( $app_id );

			// App CSS (css_all / css_android / css_ios) routed through
			// the `appress/app/css` filter so integrations can append
			// rules. Spread overwrites the raw admin values that
			// `live_config` already holds with the filtered versions.
			$live_config = array_merge( $live_config, \Appress\get_app_css( $app_id ) );

			/**
			 * Filter: `appress/app/live_config`
			 *
			 * Last-mile mutation point for the boot payload. Runs after CSS +
			 * inline-link-selectors merge so integrations see the final shape.
			 * Used by integrations like TranslatePress to inject a
			 * `translatepress` block (url_translations map, language list,
			 * slug map) so the native app can resolve every screen URL to
			 * the active language without round-trips.
			 *
			 * @param array $live_config Boot payload returned to native.
			 * @param int   $app_id      App ID being booted.
			 */
			$live_config = (array) apply_filters( 'appress/app/live_config', $live_config, $app_id );

			return wp_send_json([
				'success' => true,
				'status'  => 'updated',
				'message' => 'Live configuration payload loaded.',
				'data'    => $live_config
			]);
		} catch (\Exception $e) {
			return wp_send_json([
				'success' => false,
				'message' => $e->getMessage()
			]);
		}
	}
}
