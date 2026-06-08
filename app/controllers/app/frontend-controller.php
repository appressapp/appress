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
 *   - Returns the merged build_config + live_config payload.
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
		// Print the customer's App CSS (admin textarea + every
		// `appress/app/css` filter hook from integrations like Voxel,
		// Elementor, WooCommerce) into `<head>` whenever the page is
		// being rendered inside the native app. Priority 5 so this
		// lands after Rank Math / theme priority-0 SEO meta but before
		// the default `wp_print_styles`/`wp_print_head_scripts` at
		// priority 9/10 — guarantees the rules cascade-win over the
		// site's own theme CSS without needing `!important`. Replaces
		// the previous build-time bake (`css_all` / `css_ios` /
		// `css_android` fields shipped through Central into the
		// `AppressBakedConfig` blob then injected via
		// `AppressCssService`). PHP is always-live: admin save → next
		// page load in the app has the new CSS, no rebuild needed,
		// existing installed apps benefit immediately when an
		// integration adds rules.
		$this->on('wp_head', '@print_app_css', 5);

		// Block known web ad networks from loading inside the native app.
		// Priority 0 emits the script BEFORE any other `wp_enqueue_script`
		// (default priority 10) so the `document.createElement` override
		// is in place when the page's own scripts start running — same
		// timing guarantee `WKUserScript .atDocumentStart` used to give
		// when this logic lived in `AppressAdsExcludeService`. Moving
		// to PHP gets us live updates (admin save → next page applies,
		// no rebuild) and a `appress/app/ads_excluded_hosts` filter for
		// integration extensions.
		$this->on('wp_head', '@print_ads_blocker_js', 0);

		// App-owned Google Analytics + web GA blocking. Same priority-0
		// rationale as the ads blocker — `document.createElement` override
		// must be installed BEFORE the page's own analytics scripts run
		// or web-side gtag/GTM stubs initialize against the unfiltered
		// real `window.gtag`. Moved from native `AppressAnalyticsService`
		// (Android-only — iOS had no equivalent so iOS users never got
		// app-owned GA at all) to a single PHP path that covers both
		// platforms identically.
		$this->on('wp_head', '@print_analytics_js', 0);

		$this->on('wp_login', '@detect_first_install_on_login', 10, 2);

		// Async dispatcher: scheduled by the detector below; runs in cron so any
		// heavy Automator recipe doesn't block the page that triggered the login.
		$this->on('appress/user/installed_app_async', '@dispatch_install_event', 10, 3);

		// Async dispatcher for the boot signal — runs in cron so Automator
		// recipes, analytics hooks, and last-active writes don't block the
		// synchronous response the native app is waiting for.
		$this->on('appress/app/booted_async', '@dispatch_booted_event', 10, 3);
	}

	/**
	 * Emit the per-app stylesheet inside `<head>` when the page is
	 * being rendered for the native app. Combines:
	 *   - `css_all`     — admin textarea (always applied)
	 *   - `css_ios`     — iOS-only admin textarea (when UA is iOS)
	 *   - `css_android` — Android-only admin textarea (when UA is Android)
	 *   - every rule appended by integrations via the
	 *     `appress/app/css` filter (Voxel button overrides, Elementor
	 *     theme-builder rules, WooCommerce account-page tweaks, etc.).
	 *
	 * The helper {@see \Appress\get_app_css()} runs all three slots
	 * through the filter chain. Platform routing is then driven by the
	 * app's UA — `iPhone`/`iPad`/`Android` substring match — so the
	 * customer's css_ios block doesn't ship to Android users and
	 * vice versa.
	 *
	 * Output skipped when:
	 *   - request isn't coming from the native app (no UA marker),
	 *   - no app row matches the UA-embedded id, or
	 *   - every slot ends up empty after the filter chain (no admin
	 *     CSS + no integration hooks contributed).
	 */
	protected function print_app_css()
	{
		$app_id = \Appress\get_current_app_id();
		if ( $app_id <= 0 ) {
			return; // not an app request
		}

		$css = \Appress\get_app_css( $app_id );
		$rules = trim( (string) ( $css['css_all'] ?? '' ) );

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( stripos( $ua, 'iPhone' ) !== false || stripos( $ua, 'iPad' ) !== false ) {
			$platform_rules = trim( (string) ( $css['css_ios'] ?? '' ) );
			if ( $platform_rules !== '' ) {
				$rules .= "\n" . $platform_rules;
			}
		} elseif ( stripos( $ua, 'Android' ) !== false ) {
			$platform_rules = trim( (string) ( $css['css_android'] ?? '' ) );
			if ( $platform_rules !== '' ) {
				$rules .= "\n" . $platform_rules;
			}
		}

		$rules = trim( $rules );
		if ( $rules === '' ) {
			return; // nothing to emit
		}

		// Inline tag, no enqueue — `wp_add_inline_style` would need a
		// parent handle and we don't enqueue a stylesheet at all here.
		// `</style>` inside `$rules` would break the closing tag; the
		// admin sanitize callback strips it, but defense-in-depth.
		$safe = str_replace( '</style', '<\/style', $rules );

		echo "<style id=\"appress-app-css\">\n" . $safe . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS body sanitized by schema + admin only.
	}

	/**
	 * Emit the ad-blocker JS bundle inside `<head>` when the page is
	 * being rendered for the native app. Hooked at `wp_head` priority 0
	 * so the inline `<script>` executes before any other enqueued page
	 * script — the override of `document.createElement('script'|'iframe')`
	 * has to be installed FIRST or page-emitted ad code runs unimpeded.
	 *
	 * No-emit cases:
	 *   - request isn't from the native app (no UA marker → 0 app id),
	 *   - `disable_web_ads` toggle is off in admin (helper returns []),
	 *   - all platform checkboxes off + custom hosts empty (also []).
	 *
	 * The JS bundle mirrors the legacy native
	 * `AppressAdsExcludeService::buildInjectionJs` shape so behavior
	 * stays identical: createElement hook + ad-slot DOM nuker +
	 * MutationObserver for dynamically inserted slots.
	 */
	protected function print_ads_blocker_js()
	{
		$app_id = \Appress\get_current_app_id();
		if ( $app_id <= 0 ) {
			return;
		}

		$hosts = \Appress\get_disable_web_ads_hosts( $app_id );
		if ( empty( $hosts ) ) {
			return;
		}

		// Encode the hostname list as a JS array literal. `wp_json_encode`
		// gives us safe JSON ( `\u` for non-ASCII, escaped quotes) — a
		// direct JS literal in a string-evaluated `<script>` context.
		$hosts_json = wp_json_encode( array_values( $hosts ) );
		if ( ! is_string( $hosts_json ) || $hosts_json === '' ) {
			return;
		}

		// `nukeSlots` query catches every well-known ad slot wrapper
		// shipped by Google AdManager, GAM, AdSense, Header Bidding's
		// `<div id="div-gpt-ad-…">` pattern, and common data-attribute
		// based slot markers. Kept inline so changing the list ships
		// instantly with no JS-bundle build step.
		$js = <<<'JS'
(function(){
  if(window.__appressAdsExcludeInit)return;window.__appressAdsExcludeInit=true;
  var hosts=__HOSTS__;
  function matchHost(v){if(typeof v!=='string')return false;var lv=v.toLowerCase();for(var i=0;i<hosts.length;i++){if(lv.indexOf(hosts[i])!==-1)return true;}return false;}
  try{
    var d=Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype,'src')||Object.getOwnPropertyDescriptor(HTMLElement.prototype,'src');
    var di=Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype,'src')||Object.getOwnPropertyDescriptor(HTMLElement.prototype,'src');
    var oc=document.createElement.bind(document);
    document.createElement=function(t){
      var el=oc(t);
      var tag=(t||'').toLowerCase();
      if((tag==='script'||tag==='iframe')&&d&&d.set){
        Object.defineProperty(el,'src',{configurable:true,set:function(v){try{if(matchHost(v))return;}catch(e){}(tag==='iframe'?di:d).set.call(this,v);},get:function(){return (tag==='iframe'?di:d).get.call(this);}});
      }
      return el;
    };
  }catch(e){}
  function nukeSlots(root){try{
    var q='ins.adsbygoogle,[id^="google_ads_iframe"],[id^="div-gpt-ad-"],[data-ad-client],[data-ad-slot]';
    (root||document).querySelectorAll(q).forEach(function(n){n.remove();});
  }catch(e){}}
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',function(){nukeSlots(document);});}else{nukeSlots(document);}
  try{var mo=new MutationObserver(function(m){m.forEach(function(r){r.addedNodes&&r.addedNodes.forEach&&r.addedNodes.forEach(function(n){if(n.nodeType===1){nukeSlots(n);}});});});mo.observe(document.documentElement,{childList:true,subtree:true});}catch(e){}
})();
JS;
		$js = str_replace( '__HOSTS__', $hosts_json, $js );

		// Defensive: an admin pasting `</script>` into custom hosts
		// would otherwise break out of our inline tag. wp_json_encode
		// escapes the `/` only with JSON_HEX_TAG flag; cheaper to do a
		// targeted str_replace here on the already-encoded string.
		$js = str_replace( '</script', '<\/script', $js );

		echo "<script id=\"appress-ads-blocker\">\n" . $js . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hosts list JSON-encoded above; admin-only inputs.
	}

	/**
	 * Emit the analytics JS bundle inside `<head>` when the page is
	 * being rendered for the native app. Priority 0 so it lands before
	 * the page's own analytics scripts — same browser-timing guarantee
	 * the legacy native `WKUserScript .atDocumentStart` /
	 * `WebViewCompat.addDocumentStartJavaScript` injection used to
	 * provide. The override of `window.gtag` + `document.createElement`
	 * has to be installed BEFORE the customer's enqueued analytics
	 * scripts run.
	 *
	 * No-emit cases:
	 *   - request isn't from the native app,
	 *   - no app-owned GA ID, no `exclude_all_web_ga`, no `exclude_ids`
	 *     (admin set nothing — no override desired, web GA passes through).
	 */
	protected function print_analytics_js()
	{
		$app_id = \Appress\get_current_app_id();
		if ( $app_id <= 0 ) {
			return;
		}

		$cfg = \Appress\get_analytics_config( $app_id );
		// Short-circuit when nothing to do — no override, no overhead.
		if ( $cfg['ga_id'] === '' && ! $cfg['exclude_all'] && empty( $cfg['exclude_ids'] ) ) {
			return;
		}

		$ga_id_json    = wp_json_encode( $cfg['ga_id'] );
		$exclude_all   = $cfg['exclude_all'] ? 'true' : 'false';
		$exclude_json  = wp_json_encode( $cfg['exclude_ids'] );

		// `window.dataLayer` + `window.gtag` shim mirrors the legacy
		// `AppressAnalyticsService::buildInjectionJs` shape exactly so
		// any plugin / theme JS that depends on `gtag` being present
		// keeps working. createElement override blocks loading
		// `googletagmanager.com/gtag/js?id=...` for any ID that's
		// either in `excludeIds` or — when `excludeAll` is on — any ID
		// that isn't the app's own.
		$js = <<<'JS'
(function(){
  if(window.__appressGAInit)return;window.__appressGAInit=true;
  var appId=__GA_ID__;
  var excludeAll=__EXCLUDE_ALL__;
  var excludeIds=__EXCLUDE_IDS__;
  var allowed=appId?[appId]:[];
  function blocked(id){if(!id)return false;if(allowed.indexOf(id)!==-1)return false;if(excludeAll)return true;return excludeIds.indexOf(id)!==-1;}
  window.dataLayer=window.dataLayer||[];
  window.gtag=function(){var a=Array.prototype.slice.call(arguments);if(a[0]==='config'&&blocked(a[1]))return;window.dataLayer.push(a);};
  try{var d=Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype,'src')||Object.getOwnPropertyDescriptor(HTMLElement.prototype,'src');
  var oc=document.createElement.bind(document);
  document.createElement=function(t){var el=oc(t);if((t||'').toLowerCase()==='script'&&d&&d.set){Object.defineProperty(el,'src',{configurable:true,set:function(v){try{if(typeof v==='string'&&v.indexOf('googletagmanager.com/gtag/js')!==-1){var m=v.match(/[?&]id=([^&]+)/);if(m&&blocked(decodeURIComponent(m[1])))return;}}catch(e){}d.set.call(this,v);},get:function(){return d.get.call(this);}});}return el;};}catch(e){}
  if(appId){try{var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(appId);(document.head||document.documentElement).appendChild(s);window.gtag('js',new Date());window.gtag('config',appId);}catch(e){}}
})();
JS;
		$js = str_replace( [ '__GA_ID__', '__EXCLUDE_ALL__', '__EXCLUDE_IDS__' ], [ $ga_id_json, $exclude_all, $exclude_json ], $js );
		$js = str_replace( '</script', '<\/script', $js );

		echo "<script id=\"appress-analytics\">\n" . $js . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values JSON-encoded; admin-only input.
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
	 * separate `build_config` column that is NEVER returned by this
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
			$row = $wpdb->get_row( $wpdb->prepare("SELECT build_config FROM {$wpdb->prefix}appress_apps WHERE id = %d", $app_id), ARRAY_A );

			if ( ! $row ) {
				throw new \Exception( esc_html__( 'Application not found.', 'appress' ) );
			}

			// 1.3.0 collapsed live_config into build_config — customer apps
			// never re-fetch at runtime so the runtime-mutable split was
			// imaginary. Variable is still named `$live_config` because the
			// downstream filter hook contract (`appress/app/live_config`)
			// and the response key (`data`) are part of the public boot
			// payload shape; renaming them is a separate breaking change.
			$live_config = !empty($row['build_config']) ? (array) json_decode($row['build_config'], true) : [];
 
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

			// Synthetic home-screen fallback. When an admin creates an
			// app but doesn't add any screens (or adds screens with no
			// `role=home` / no usable URL), native bootstrap would
			// otherwise land on a blank master WebView with nothing to
			// load. Detect that situation here and inject a
			// home-flagged screen pointing at the WP site's `home_url()`
			// so the app boots into the customer's homepage. Plays
			// nicely with `single-screen mode`: when there are no
			// other tabs we force `bottom_navigation.enabled = false`
			// so the nav stays hidden and the synthetic tab fills
			// the screen edge-to-edge (Safari-style).
			$this->ensure_home_screen_fallback( $live_config );

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

	/**
	 * Guarantee at least ONE bootable screen exists in the boot payload.
	 *
	 * Walks `bottom_navigation.items`, resolves each item's effective URL
	 * + role (either directly or via the linked `app_screens` row), and
	 * looks for a screen flagged `role=home` with a non-empty URL. When
	 * none is found, prepends a synthetic home screen + matching tab
	 * pointing at the WP site's `home_url()`. Mutates `$live_config` in
	 * place — native bootstrap then sees a valid default screen and
	 * boots the app into the customer's homepage instead of a blank
	 * master WebView.
	 *
	 * Empty admin config (the trigger case) → also hides the bottom nav
	 * by forcing `bottom_navigation.enabled = false`, so the synthetic
	 * tab fills the screen edge-to-edge rather than rendering a 1-item
	 * nav bar nobody asked for.
	 *
	 * Idempotent + cache-safe. The `update_time_hash` is computed off
	 * the admin's saved config (before this fallback runs), so the
	 * "up_to_date" branch above never short-circuits this code; once
	 * native receives the synthesized config, subsequent boots match
	 * the same hash and reuse the cached fallback.
	 */
	private function ensure_home_screen_fallback( array &$live_config ): void {
		$footer       = (array) ( $live_config['bottom_navigation'] ?? [] );
		$footer_items = (array) ( $footer['items'] ?? [] );
		$app_screens  = (array) ( $live_config['app_screens'] ?? [] );

		// Build an `_id => screen` lookup for O(1) screen_id resolution.
		$screens_by_id = [];
		foreach ( $app_screens as $s ) {
			if ( is_array( $s ) ) {
				$sid = (string) ( $s['_id'] ?? '' );
				if ( $sid !== '' ) {
					$screens_by_id[ $sid ] = $s;
				}
			}
		}

		// A "viable default" = any item that resolves to a non-empty URL
		// AND carries role=home (either on the item itself or on the
		// linked app_screens row).
		$has_viable_default = false;
		foreach ( $footer_items as $item ) {
			if ( ! is_array( $item ) ) continue;
			$item_url = trim( (string) ( $item['url'] ?? '' ) );
			$item_role = (string) ( $item['role'] ?? '' );
			$screen_id = (string) ( $item['screen_id'] ?? '' );

			$effective_url  = $item_url;
			$effective_role = $item_role;
			if ( $screen_id !== '' && isset( $screens_by_id[ $screen_id ] ) ) {
				$linked = $screens_by_id[ $screen_id ];
				$linked_url = trim( (string) ( $linked['url'] ?? '' ) );
				if ( $linked_url !== '' ) $effective_url = $linked_url;
				$linked_role = (string) ( $linked['role'] ?? '' );
				if ( $linked_role !== '' ) $effective_role = $linked_role;
			}

			if ( $effective_url !== '' && $effective_role === 'home' ) {
				$has_viable_default = true;
				break;
			}
		}

		if ( $has_viable_default ) return;

		// Build the synthetic fallback. Mirror the schema-defined
		// repeater shape so downstream consumers (CssService,
		// integrations' filter implementations, TranslatePress URL
		// rewriter) see a normal-looking row.
		$home_url = home_url( '/' );
		$synthetic_screen_id = 'home_fallback';
		$synthetic_screen = [
			'_id'                => $synthetic_screen_id,
			'wp_id'              => '',
			'type'               => 'custom_url',
			'title'              => __( 'Home', 'appress' ),
			'url'                => $home_url,
			'icon'               => '',
			'role'               => 'home',
			'reload_on_click'    => true,
			'always_reload'      => false,
			'show_web_header'    => false,
			'show_web_footer'    => false,
			'use_general_config' => true,
			'pull_to_refresh'    => false,
			'offline'            => true,
			'preload'            => false,
		];
		$synthetic_tab = [
			'_id'         => 'home_fallback_tab',
			'title'       => '',
			'icon'        => '',
			'type'        => 'screen',
			'screen_id'   => $synthetic_screen_id,
			'url'         => $home_url,
			'menu_target' => 'left',
			'indicator'   => 'none',
		];

		$live_config['app_screens'] = array_merge( [ $synthetic_screen ], $app_screens );
		$footer['items']            = array_merge( [ $synthetic_tab ], $footer_items );

		// Admin had no tabs at all → hide the nav so a single-item bar
		// doesn't render. If admin already had some tabs but just no
		// role=home one, leave `enabled` alone (their nav stays).
		if ( empty( $footer_items ) ) {
			$footer['enabled'] = false;
		}

		$live_config['bottom_navigation'] = $footer;
	}
}
