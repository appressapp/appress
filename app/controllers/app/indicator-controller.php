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
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides badge indicator counts for the mobile app bottom navigation.
 *
 * Three indicator types: cart, message, notification.
 * Each is powered by a WordPress filter — any theme or plugin can hook in:
 *
 *   add_filter('appress/indicator/cart_count', function($count) {
 *       return WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
 *   });
 *
 * Real-time updates: core provides fetch/jQuery/XHR interception engine.
 * Integrations register handlers via filter 'appress/indicator/realtime_js'.
 */
class Indicator_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Mobile-only — bottom-nav indicator counts the app polls + on
		// realtime events. Register on each app's `<class_id>_ajax_*` +
		// legacy `appress_ajax_*` for backward compat.
		$this->on_mobile( 'app.get_indicators', '@handle_get_indicators' );

		// Admin-only — Vue Builder lists registered indicator types so the
		// admin can map them onto bottom-nav slots. Stays on
		// `appress_ajax_*` (not reachable from mobile-app URL).
		$this->on( 'appress_ajax_app.get_indicator_types', '@handle_get_indicator_types' );

		// Built-in `notification` indicator — counts unread rows in the Appress
		// notification feed across all sources (DB + every integration that
		// hooks `appress/notifications/unread_count`). Registered in core so
		// it's available even when no integration is active.
		$this->filter( 'appress/indicator/types',              '@register_core_types' );
		$this->filter( 'appress/indicator/notification_count', '@get_notification_count' );
		$this->filter( 'appress/indicator/realtime_js',        '@register_realtime_handlers' );

		if ( \Appress\is_app() ) {
			// Priority 5 — must be < 20 because WordPress's
			// `wp_print_footer_scripts` fires at `wp_footer:20`. If we
			// hooked at 50 (or any > 20), `wp_enqueue_script` inside
			// `inject_indicators_script` runs AFTER the print pass and
			// silently no-ops — script tags never reach the HTML even
			// though hooks() registered the callback correctly. The
			// other Appress footer scripts (apple-auth-bridge, etc.)
			// don't hit this trap because they enqueue at the standard
			// `wp_enqueue_scripts` hook before `wp_footer` ever fires.
			$this->on( 'wp_footer', '@inject_indicators_script', 5 );
		}
	}

	/** Register the built-in `notification` type. Integrations can add more. */
	public function register_core_types( $types ) {
		$types['notification'] = [ 'label' => __( 'Notification', 'appress' ) ];
		return $types;
	}

	/**
	 * Count unread entries in the shared Appress notification feed. Routes
	 * through the same `appress/notifications/unread_count` filter that the
	 * feed itself reads — integrations (Voxel etc.) that hook the filter
	 * automatically contribute their count here too, no duplicate bookkeeping.
	 */
	public function get_notification_count( $count ) {
		if ( ! is_user_logged_in() ) {
			return $count;
		}
		$user_id = (int) get_current_user_id();
		$ctx = [ 'user_id' => $user_id ];
		return (int) apply_filters( 'appress/notifications/unread_count', \Appress\Notification::unread_count_db( $user_id ), $ctx );
	}

	/**
	 * Real-time refresh hooks — the notification AJAX endpoints all return
	 * the authoritative `data.unread_count` in their response body, so we
	 * just read it and set() directly. No secondary `app.get_indicators`
	 * fetch, no `refresh()` debounce, no full indicators fan-out (which
	 * would re-run every integration's count filter — heavy).
	 */
	public function register_realtime_handlers( $js_snippets ) {
		$js_snippets[] = <<<'JS'
(function() {
	function setFromResponse(action, text) {
		try {
			var r = JSON.parse(text);
			if (r && r.success && r.data && typeof r.data.unread_count !== 'undefined') {
				Appress.indicator.set('notification', parseInt(r.data.unread_count, 10) || 0);
			}
		} catch (e) {}
	}
	Appress.indicator.realtime.onAction('notifications.mark_read',     setFromResponse);
	Appress.indicator.realtime.onAction('notifications.mark_all_read', setFromResponse);
	Appress.indicator.realtime.onAction('notifications.delete',        setFromResponse);
	Appress.indicator.realtime.onAction('notifications.delete_all',    setFromResponse);
	Appress.indicator.realtime.onAction('notifications.list',          setFromResponse);
})();
JS;
		return $js_snippets;
	}

	/**
	 * Get registered indicator types.
	 * Core registers: cart, message, notification.
	 * 3rd party devs add their own via filter:
	 *
	 *   add_filter('appress/indicator/types', function($types) {
	 *       $types['wishlist'] = [ 'label' => 'Wishlist' ];
	 *       return $types;
	 *   });
	 *
	 * Then hook the count:
	 *   add_filter('appress/indicator/wishlist_count', function($count) {
	 *       return get_user_wishlist_count();
	 *   });
	 */
	public static function get_types() {
		return apply_filters( 'appress/indicator/types', [] );
	}

	private function get_counts( $force_fresh = false ) {
		$uid = get_current_user_id();
		$cache_key = 'appress_ind_' . $uid;

		if ( ! $force_fresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) return $cached;
		}

		$types = self::get_types();
		$counts = [];
		foreach ( $types as $key => $type ) {
			$counts[ $key ] = (int) apply_filters( "appress/indicator/{$key}_count", 0 );
		}
		set_transient( $cache_key, $counts, 30 );
		return $counts;
	}

	protected function handle_get_indicator_types() {
		try {
			$types = self::get_types();
			$options = [ [ 'value' => 'none', 'label' => __( 'None', 'appress' ) ] ];
			foreach ( $types as $key => $type ) {
				$options[] = [ 'value' => $key, 'label' => $type['label'] ];
			}
			return wp_send_json( [ 'success' => true, 'data' => $options ] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Endpoint: return badge counters (cart, notifications, messages…)
	 * for the WebView's bottom-nav indicators.
	 *
	 * Auth model: identity is taken from the standard WordPress
	 * `wordpress_logged_in_*` cookie that the mobile WebView already
	 * carries (the app shares a cookie store with the master Capacitor
	 * WebView). The `nopriv` registration exists so guests get a
	 * structurally-valid `0`-counts response instead of a 401, which
	 * would force the client to special-case anonymous browsing.
	 * `get_counts()` resolves the counts per the current user — never
	 * leaks one user's counts to another. No nonce because the response
	 * is read-only and contains nothing more sensitive than what the
	 * logged-in user already sees in their own cart / inbox UI.
	 */
	protected function handle_get_indicators() {
		try {
			return wp_send_json( [
				'success' => true,
				'data'    => $this->get_counts( true )
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	public function inject_indicators_script() {
		// Register a virtual handle and stream the indicator JS through WP's
		// enqueue pipeline (wp_add_inline_script) instead of a raw <script>
		// tag. Filter-injected snippets are appended in order between the
		// header / main / footer chunks so the final emitted body is one
		// continuous IIFE.
		wp_register_script( 'appress-indicators', false, [], \Appress\get_assets_version(), false );
		wp_enqueue_script( 'appress-indicators' );
		wp_localize_script( 'appress-indicators', 'appressIndicatorsConfig', [
			'counts'  => $this->get_counts(),
			'ajaxUrl' => home_url( '/?appress=1&action=app.get_indicators' ),
		] );

		wp_add_inline_script( 'appress-indicators', $this->indicators_header_js() );

		// Filter contract: callbacks emit pre-validated JavaScript. We
		// append each snippet via the same enqueue pipeline; reviewer-friendly,
		// no raw echo, no inline escape gymnastics.
		foreach ( apply_filters( 'appress/indicator/client_js', [] ) as $snippet ) {
			wp_add_inline_script( 'appress-indicators', (string) $snippet );
		}

		wp_add_inline_script( 'appress-indicators', $this->indicators_main_js() );

		foreach ( apply_filters( 'appress/indicator/realtime_js', [] ) as $snippet ) {
			wp_add_inline_script( 'appress-indicators', (string) $snippet );
		}

		wp_add_inline_script( 'appress-indicators', $this->indicators_footer_js() );
	}

	private function indicators_header_js(): string {
		return <<<'JS'
(function() {
	window.Appress = window.Appress || {};
	var indicators = (window.appressIndicatorsConfig && window.appressIndicatorsConfig.counts) || {};
	var APPRESS_INDICATORS_URL = (window.appressIndicatorsConfig && window.appressIndicatorsConfig.ajaxUrl) || '';
JS;
	}

	private function indicators_main_js(): string {
		return <<<'JS'
			window.Appress.indicators = indicators;

			// --- Native bridge sync (debounced 300ms to batch rapid updates) ---
			var _syncTimer = null;
			function syncBadgeToNative() {
				clearTimeout(_syncTimer);
				_syncTimer = setTimeout(function() {
					var msg = JSON.stringify({ type: 'indicators', data: window.Appress.indicators });
					if (window.AppressNativeBridge && typeof window.AppressNativeBridge.postMessage === 'function') {
						window.AppressNativeBridge.postMessage(msg);
					} else if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.AppressNativeBridge) {
						window.webkit.messageHandlers.AppressNativeBridge.postMessage(msg);
					}
				}, 300);
			}

			/**
			 * Public API: Update a single indicator.
			 * Usage: Appress.setIndicator('cart', 3);
			 *    or: Appress.indicator.set('cart', 3);
			 */
			window.Appress.setIndicator = function(key, count) {
				window.Appress.indicators[key] = parseInt(count) || 0;
				syncBadgeToNative();
			};

			syncBadgeToNative();

			var _rt = {};

			// ===================================================================
			// REAL-TIME INTERCEPTION ENGINE
			// Intercepts fetch(), jQuery AJAX, and XHR to detect AJAX actions.
			// Integrations register handlers — core handles all plumbing.
			// ===================================================================

			var _ajaxHandlers = [];
			var _xhrHandlers = [];

			/**
			 * Appress.indicator.realtime — Real-time indicator update API.
			 *
			 * Integrations use this to register handlers that react to AJAX/fetch responses
			 * and update badge indicators in real-time without page reload.
			 *
			 * Methods:
			 *   .onAction(pattern, callback)  — Watch AJAX actions (URL ?action=xxx or POST body)
			 *   .onXhr(urlPattern, callback)   — Watch raw XHR URLs (polling endpoints, etc.)
			 *
			 * Examples:
			 *   // WooCommerce cart
			 *   Appress.indicator.realtime.onAction('wc-ajax=add_to_cart', function(action, text) {
			 *       Appress.indicator.refresh('cart');
			 *   });
			 *
			 *   // Voxel messages polling
			 *   Appress.indicator.realtime.onXhr('check-activity.php', function(responseText) {
			 *       if (responseText === '1') Appress.indicator.refresh('message');
			 *   });
			 */
			window.Appress.indicator = window.Appress.indicator || {};
			var _afterUpdateCallbacks = [];
			var _freshKeys = {}; // Tracks when each key was last set by realtime handler

			window.Appress.indicator.set = function(key, count) {
				window.Appress.indicators[key] = parseInt(count) || 0;
				_freshKeys[key] = Date.now();
				syncBadgeToNative();
			};

			/**
			 * Delta bump — increment by `delta` (default +1). Used by the
			 * realtime FCM bridge to bump the `notification` indicator as
			 * soon as a push arrives, without waiting for the 60s poll or
			 * a server round-trip to recount.
			 */
			window.Appress.indicator.bump = function(key, delta) {
				var current = parseInt(window.Appress.indicators[key] || 0, 10) || 0;
				var next = current + (parseInt(delta, 10) || 1);
				if (next < 0) next = 0;
				window.Appress.indicator.set(key, next);
			};

			// Called by native broadcast ONLY — updates state WITHOUT syncing back to native (breaks loop)
			window.Appress.indicator._applyBroadcast = function(counts) {
				var changed = [];
				for (var k in counts) {
					var newVal = parseInt(counts[k]) || 0;
					var oldVal = window.Appress.indicators[k];
					if (oldVal !== newVal) changed.push({ key: k, newVal: newVal, oldVal: oldVal });
					window.Appress.indicators[k] = newVal;
				}
				for (var i = 0; i < changed.length; i++) {
					for (var j = 0; j < _afterUpdateCallbacks.length; j++) {
						try { _afterUpdateCallbacks[j](changed[i].key, changed[i].newVal, changed[i].oldVal); } catch(e) {}
					}
				}
			};

			/**
			 * Fires when indicator changes on ANOTHER screen (via native broadcast).
			 * Does NOT fire on the screen that originated the change.
			 */
			window.Appress.indicator.afterUpdate = function(callback) {
				if (typeof callback === 'function') _afterUpdateCallbacks.push(callback);
			};

			/**
			 * Request native to safely reload this screen (bypasses URL routing).
			 * Debounced to prevent double-reload from duplicate intercepts.
			 */
			var _reloadTimer = null;
			window.Appress.reloadScreen = function() {
				if (_reloadTimer) return;
				_reloadTimer = setTimeout(function() { _reloadTimer = null; }, 2000);
				var msg = JSON.stringify({ type: 'reloadScreen' });
				if (window.AppressNativeBridge && typeof window.AppressNativeBridge.postMessage === 'function') {
					window.AppressNativeBridge.postMessage(msg);
				} else if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.AppressNativeBridge) {
					window.webkit.messageHandlers.AppressNativeBridge.postMessage(msg);
				}
			};

			window.Appress.indicator.refresh = function(key) {
				clearTimeout(_rt[key]);
				_rt[key] = setTimeout(function() {
					window._appressOrigFetch.call(window, APPRESS_INDICATORS_URL)
						.then(function(r) { return r.json(); })
						.then(function(d) {
							if (d.success && d.data && d.data[key] !== undefined) {
								window.Appress.indicator.set(key, d.data[key]);
							}
						}).catch(function() {});
				}, 500);
			};

			window.Appress.indicator.realtime = {
				onAction: function(pattern, callback) {
					_ajaxHandlers.push({ pattern: pattern, callback: callback });
				},
				onXhr: function(urlPattern, callback) {
					_xhrHandlers.push({ pattern: urlPattern, callback: callback });
				}
			};

			// Backwards compat aliases
			window.Appress.refreshIndicator = window.Appress.indicator.refresh;

			// --- Extract action identifier from URL query, POST body, or URL path ---
			function extractAction(url, body) {
				var a = '';
				// 1. Query param: ?action=xxx or ?wc-ajax=xxx
				if (url) {
					var m = url.match(/[?&]action=([^&]+)/);
					if (m) a = decodeURIComponent(m[1]);
					if (!a) { var m2 = url.match(/[?&]wc-ajax=([^&]+)/); if (m2) a = 'wc-ajax=' + decodeURIComponent(m2[1]); }
				}
				// 2. POST body
				if (!a && body && typeof body === 'string') { var m3 = body.match(/action=([^&]+)/); if (m3) a = decodeURIComponent(m3[1]); }
				if (!a && body && typeof body === 'object' && body.get) { a = body.get('action') || ''; }
				// 3. URL path fallback (REST API style: /wc/store/v1/cart/add-item)
				if (!a && url) {
					try { a = new URL(url, location.origin).pathname; } catch(e) { a = url.split('?')[0]; }
				}
				return a;
			}

			// --- Match action against pattern (string or regex) ---
			function matchesPattern(action, pattern) {
				if (pattern instanceof RegExp) return pattern.test(action);
				return action.indexOf(pattern) !== -1;
			}

			// --- Dispatch to registered handlers ---
			function dispatchHandlers(action, responseText) {
				for (var i = 0; i < _ajaxHandlers.length; i++) {
					if (matchesPattern(action, _ajaxHandlers[i].pattern)) {
						try { _ajaxHandlers[i].callback(action, responseText); } catch(e) {}
					}
				}
			}

			// --- 1. Intercept fetch() ---
			window._appressOrigFetch = window.fetch;
			window.fetch = function(input, init) {
				var url = (typeof input === 'string') ? input : (input && input.url ? input.url : '');
				var body = (init && init.body) ? init.body : null;
				var action = extractAction(url, body);

				return window._appressOrigFetch.apply(this, arguments).then(function(response) {
					if (action) {
						response.clone().text().then(function(t) { dispatchHandlers(action, t); }).catch(function() {});
					}
					return response;
				});
			};

			// --- 2. Intercept jQuery AJAX ---
			if (window.jQuery) {
				jQuery(document).ajaxComplete(function(ev, xhr, settings) {
					if (!settings) return;
					var action = extractAction(settings.url, settings.data);
					if (action && xhr.responseText) dispatchHandlers(action, xhr.responseText);
				});
			}

			// --- 3. Intercept XHR for BOTH action handlers and URL-based handlers ---
			var _origXhrOpen = XMLHttpRequest.prototype.open;
			var _origXhrSend = XMLHttpRequest.prototype.send;
			XMLHttpRequest.prototype.open = function(method, url) {
				this._appressUrl = url || '';
				// URL-based handlers (polling endpoints etc.)
				var self = this;
				if (url && typeof url === 'string') {
					for (var i = 0; i < _xhrHandlers.length; i++) {
						(function(handler) {
							if (matchesPattern(url, handler.pattern)) {
								self.addEventListener('load', function() {
									try { handler.callback(self.responseText, url); } catch(e) {}
								});
							}
						})(_xhrHandlers[i]);
					}
				}
				return _origXhrOpen.apply(this, arguments);
			};
			// Action dispatch via XHR only when jQuery is NOT available (jQuery ajaxComplete already covers it)
			if (!window.jQuery) {
				XMLHttpRequest.prototype.send = function(body) {
					var self = this;
					var action = extractAction(self._appressUrl, body);
					if (action) {
						self.addEventListener('load', function() {
							try { dispatchHandlers(action, self.responseText); } catch(e) {}
						});
					}
					return _origXhrSend.apply(this, arguments);
				};
			}

JS;
	}

	private function indicators_footer_js(): string {
		return <<<'JS'
	// --- Poll every 60s while page is visible ---
	var pollTimer = null;
	var FRESH_COOLDOWN = 15000; // Don't overwrite keys set by realtime handlers within 15s
	function startPoll() {
		if (pollTimer) return;
		pollTimer = setInterval(function() {
			window._appressOrigFetch.call(window, APPRESS_INDICATORS_URL)
				.then(function(r) { return r.json(); })
				.then(function(res) {
					if (res.success && res.data) {
						var now = Date.now();
						for (var k in res.data) {
							if (_freshKeys[k] && (now - _freshKeys[k]) < FRESH_COOLDOWN) continue;
							window.Appress.indicators[k] = parseInt(res.data[k]) || 0;
						}
						syncBadgeToNative();
					}
				}).catch(function() {});
		}, 60000);
	}
	function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
	document.addEventListener('visibilitychange', function() { document.hidden ? stopPoll() : startPoll(); });
	if (!document.hidden) startPoll();

	// Native bridge fan-out: when push tap server marks a notification row read
	// (`broadcast.track_read`) or any other path mutates server-side state, the
	// native side fires `appress:notification:changed`. Re-poll the unread count
	// immediately instead of waiting for the 60s tick so the bell badge syncs live.
	window.addEventListener('app:notification:changed', function() {
		window.Appress.indicator.refresh('notification');
	});
})();
JS;
	}
}
