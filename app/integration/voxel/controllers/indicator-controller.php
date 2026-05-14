<?php

namespace Appress\Integration\Voxel\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Voxel-specific indicator types (voxel_cart, voxel_message)
 * and hooks real-time AJAX interception for instant badge updates.
 *
 * Note on notifications: Voxel notifications are NOT exposed as a separate
 * indicator. They feed into the core `notification` indicator via the
 * `appress/notifications/unread_count` filter (see
 * integration/voxel/controllers/notifications-controller.php), so a single
 * Appress notification count covers both DB + Voxel rows — and updates
 * realtime when FCM delivers a push (no polling needed).
 */
class Indicator_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Register Voxel indicator types
		$this->filter( 'appress/indicator/types', '@register_types' );

		// Server-side count providers
		$this->filter( 'appress/indicator/voxel_cart_count', '@get_cart_count' );
		$this->filter( 'appress/indicator/voxel_message_count', '@get_message_count' );

		// Client-side: guest cart count at page load
		$this->filter( 'appress/indicator/client_js', '@register_guest_cart_js' );

		// Client-side: real-time handler registrations
		$this->filter( 'appress/indicator/realtime_js', '@register_realtime_handlers' );
	}

	// ─── Register indicator types ────────────────────────────────────────

	public function register_types( $types ) {
		$types['voxel_cart']    = [ 'label' => __( 'Voxel Cart', 'appress' ) ];
		$types['voxel_message'] = [ 'label' => __( 'Voxel Message', 'appress' ) ];
		return $types;
	}

	// ─── Server-side count providers ─────────────────────────────────────

	public function get_cart_count( $count ) {
		if ( ! is_user_logged_in() || ! class_exists( '\Voxel\User' ) ) {
			return $count;
		}
		try {
			$user = \Voxel\current_user();
			if ( ! $user ) return $count;
			return count( $user->get_cart()->get_items() );
		} catch ( \Exception $e ) {
			return $count;
		}
	}

	public function get_message_count( $count ) {
		if ( ! is_user_logged_in() || ! class_exists( '\Voxel\User' ) || ! class_exists( '\Voxel\Modules\Direct_Messages\Chat' ) ) {
			return $count;
		}
		try {
			$user_id = get_current_user_id();
			$chats = \Voxel\Modules\Direct_Messages\Chat::get_inbox( $user_id, 50, 0 );
			$unseen = 0;
			foreach ( $chats as $chat ) {
				if ( ! $chat->is_seen() ) {
					$unseen++;
				}
			}
			return $unseen;
		} catch ( \Exception $e ) {
			return $count;
		}
	}

	// ─── Client-side: guest cart at page load ────────────────────────────

	public function register_guest_cart_js( $js_snippets ) {
		if ( is_user_logged_in() ) return $js_snippets;

		$js_snippets[] = "try { var gc = JSON.parse(localStorage.getItem('voxel:guest_cart') || '{}'); var gcCount = Array.isArray(gc) ? gc.length : Object.keys(gc).length; indicators.voxel_cart = gcCount; } catch(e) {}";

		return $js_snippets;
	}

	// ─── Client-side: real-time handlers ─────────────────────────────────

	public function register_realtime_handlers( $js_snippets ) {

		// Guest cart: check localStorage after user interaction (ONLY for non-logged-in users)
		if ( ! is_user_logged_in() ) {
			$js_snippets[] = <<<'JS'
(function() {
	var _lastGuestCartCount = -1;
	var _checkTimer = null;
	function checkGuestCart() {
		try {
			var raw = localStorage.getItem('voxel:guest_cart');
			var count = 0;
			if (raw) {
				var gc = JSON.parse(raw);
				count = Array.isArray(gc) ? gc.length : Object.keys(gc).length;
			}
			if (count !== _lastGuestCartCount) {
				_lastGuestCartCount = count;
				Appress.indicator.set('voxel_cart', count);
			}
		} catch(e) {}
	}
	document.addEventListener('click', function() {
		clearTimeout(_checkTimer);
		_checkTimer = setTimeout(checkGuestCart, 500);
	}, true);
	document.addEventListener('touchend', function() {
		clearTimeout(_checkTimer);
		_checkTimer = setTimeout(checkGuestCart, 500);
	}, true);
})();
JS;
		}

		// Cart
		$js_snippets[] = <<<'JS'
Appress.indicator.realtime.onAction('add_to_cart', function(action, text) {
	try {
		var r = JSON.parse(text);
		if (r && r.success) {
			var current = (Appress.indicators || {}).voxel_cart || 0;
			Appress.indicator.set('voxel_cart', current + 1);
		}
	} catch(e) {}
});
Appress.indicator.realtime.onAction('remove_cart_item', function(action, text) {
	try {
		var r = JSON.parse(text);
		if (r && r.success && r.items !== undefined) {
			var c = Array.isArray(r.items) ? r.items.length : Object.keys(r.items).length;
			Appress.indicator.set('voxel_cart', c);
		} else { Appress.indicator.refresh('voxel_cart'); }
	} catch(e) {}
});
Appress.indicator.realtime.onAction('get_cart_items', function(action, text) {
	try {
		var r = JSON.parse(text);
		if (r && r.success && r.items !== undefined) {
			var c = Array.isArray(r.items) ? r.items.length : Object.keys(r.items).length;
			Appress.indicator.set('voxel_cart', c);
		}
	} catch(e) {}
});
Appress.indicator.realtime.onAction('update_cart_item_quantity', function(action, text) {
	try {
		var r = JSON.parse(text);
		if (r && r.success && r.items !== undefined) {
			var c = Array.isArray(r.items) ? r.items.length : Object.keys(r.items).length;
			Appress.indicator.set('voxel_cart', c);
		}
	} catch(e) {}
});
Appress.indicator.realtime.onAction('empty_cart', function(action, text) {
	try { var r = JSON.parse(text); if (r && r.success) Appress.indicator.set('voxel_cart', 0); } catch(e) {}
});
JS;

		// Messages
		$js_snippets[] = <<<'JS'
Appress.indicator.realtime.onAction('inbox.list_chats', function(action, text) {
	try {
		var r = JSON.parse(text);
		if (r && r.success && Array.isArray(r.list)) {
			var u = 0;
			for (var i = 0; i < r.list.length; i++) { if (!r.list[i].seen || r.list[i].is_new) u++; }
			Appress.indicator.set('voxel_message', u);
		}
	} catch(e) {}
});
Appress.indicator.realtime.onAction('inbox.search_chats', function(action, text) {
	try {
		var r = JSON.parse(text);
		if (r && r.success && Array.isArray(r.list)) {
			var u = 0;
			for (var i = 0; i < r.list.length; i++) { if (!r.list[i].seen || r.list[i].is_new) u++; }
			Appress.indicator.set('voxel_message', u);
		}
	} catch(e) {}
});
Appress.indicator.realtime.onAction('inbox.send_message', function() { Appress.indicator.refresh('voxel_message'); });
Appress.indicator.realtime.onAction('inbox.load_chat', function() { Appress.indicator.refresh('voxel_message'); });
JS;

		// Notifications: intentionally no polling. Voxel rows merge into the
		// Appress notification feed via the `appress/notifications/items`
		// filter (see notifications-controller.php), and FCM pushes bump
		// the core `notification` indicator in realtime — so Voxel's
		// `check-activity.php`-style poll for notifications would just be
		// duplicate work.

		// Voxel message polling endpoint
		$js_snippets[] = <<<'JS'
Appress.indicator.realtime.onXhr('check-activity.php', function(responseText) {
	if (responseText === '1') {
		setTimeout(function() { Appress.indicator.refresh('voxel_message'); }, 1500);
	}
});
JS;

		// Background reload via native broadcast: afterUpdate fires on OTHER screens
		$js_snippets[] = <<<'JS'
Appress.indicator.afterUpdate(function(key) {
	var dominated = false;
	if (key === 'voxel_cart') dominated = !!document.querySelector('.ts-order-cart, .ts-checkout, [data-voxel-cart], .ts-add-to-cart');
	else if (key === 'voxel_message') dominated = !!document.querySelector('.ts-messages, .ts-inbox, [data-voxel-messages]');
	if (dominated) Appress.reloadScreen();
});
JS;

		// Cross-WebView sync: localStorage signal + DOM check on tab switch
		$js_snippets[] = <<<'JS'
(function() {
	var SYNC_KEY = 'appress:indicator:last_updated';
	var _myVer = localStorage.getItem(SYNC_KEY) || '0';

	var _origSet = Appress.indicator.set;
	Appress.indicator.set = function(key, count) {
		var old = (Appress.indicators || {})[key];
		_origSet.call(this, key, count);
		if (old !== undefined && old !== count) {
			var ver = String(Date.now());
			try { localStorage.setItem(SYNC_KEY, ver); } catch(e) {}
			_myVer = ver;
		}
	};

	var _stale = false;
	window.addEventListener('storage', function(e) {
		if (e.key === SYNC_KEY) _stale = true;
	});

	function checkAndReload() {
		try {
			var cur = localStorage.getItem(SYNC_KEY) || '0';
			if ((cur !== _myVer || _stale) && document.querySelector('.ts-order-cart, .ts-checkout, [data-voxel-cart], .ts-add-to-cart, .ts-messages, .ts-inbox, [data-voxel-messages]')) {
				_myVer = cur;
				_stale = false;

				var s = document.createElement('div');
				s.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;display:flex;justify-content:center;padding-top:' + (parseInt(getComputedStyle(document.documentElement).getPropertyValue('--appress-status-bar-height')) + 12 || 60) + 'px;pointer-events:none;';
				s.innerHTML = '<div style="width:44px;height:44px;border-radius:22px;backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);background:rgba(255,255,255,0.72);box-shadow:0 2px 6px rgba(0,0,0,0.18);display:flex;align-items:center;justify-content:center;animation:_apr_in 0.2s ease-out;"><div style="width:20px;height:20px;border:2.5px solid rgba(0,0,0,0.08);border-top-color:rgba(0,0,0,0.45);border-radius:50%;animation:_apr_spin 0.7s linear infinite;"></div></div>';
				var st = document.createElement('style');
				st.textContent = '@keyframes _apr_spin{to{transform:rotate(360deg)}}@keyframes _apr_in{from{opacity:0;transform:scale(0.3)}to{opacity:1;transform:scale(1)}}';
				document.head.appendChild(st);
				document.body.appendChild(s);
				window.location.reload();
			} else {
				_myVer = cur;
				_stale = false;
			}
		} catch(e) {}
	}

	document.addEventListener('visibilitychange', function() { if (!document.hidden) checkAndReload(); });
	window.addEventListener('appress:screen_activated', checkAndReload);
})();
JS;

		// Mirror unified `notification` count (DB + Voxel rows merged via
		// `appress/notifications/unread_count` filter) onto Voxel user-bar
		// notification icon (.unread-indicator span). Voxel server-renders
		// the span only when unread>0 at page-load; native updates must
		// keep it in sync.
		$js_snippets[] = <<<'JS'
(function() {
	function syncNotificationBadge(count) {
		document.querySelectorAll('.ts-notifications-wrapper .ts-comp-icon').forEach(function(icon) {
			var span = icon.querySelector('.unread-indicator');
			if (count > 0) {
				if (!span) {
					span = document.createElement('span');
					span.className = 'unread-indicator';
					icon.appendChild(span);
				} else {
					span.classList.remove('hidden');
				}
			} else if (span) {
				span.classList.add('hidden');
			}
		});
	}
	Appress.indicator.afterUpdate(function(key, newVal) {
		if (key === 'notification') syncNotificationBadge(newVal);
	});
	var _origSetNotif = Appress.indicator.set;
	Appress.indicator.set = function(key, count) {
		_origSetNotif.call(this, key, count);
		if (key === 'notification') syncNotificationBadge(parseInt(count) || 0);
	};
	if (Appress.indicators && Appress.indicators.notification !== undefined) {
		syncNotificationBadge(Appress.indicators.notification);
	}
})();
JS;

		return $js_snippets;
	}
}
