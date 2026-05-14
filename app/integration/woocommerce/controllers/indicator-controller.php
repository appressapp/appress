<?php

namespace Appress\Integration\Woocommerce\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce cart indicator with real-time updates.
 * Supports both AJAX add-to-cart (shop pages) and redirect add-to-cart (single product).
 */
class Indicator_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->filter( 'appress/indicator/types', '@register_types' );
		$this->filter( 'appress/indicator/woo_cart_count', '@get_cart_count' );
		$this->filter( 'appress/indicator/realtime_js', '@register_realtime_handlers' );
	}

	public function register_types( $types ) {
		$types['woo_cart'] = [ 'label' => __( 'WooCommerce Cart', 'appress' ) ];
		return $types;
	}

	public function get_cart_count( $count ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $count;
		}
		try {
			return WC()->cart->get_cart_contents_count();
		} catch ( \Exception $e ) {
			return $count;
		}
	}

	public function register_realtime_handlers( $js_snippets ) {

		$js_snippets[] = <<<'JS'
(function() {
	// ═══ REAL-TIME CART COUNT ═══
	// Three detection layers: jQuery events (AJAX), cart fragments (sessionStorage), click fallback (redirect)

	// 1. jQuery events — WooCommerce fires these on AJAX add/remove
	if (window.jQuery) {
		jQuery(document.body).on('added_to_cart', function(e, fragments) {
			// Extract count from fragments HTML (most reliable)
			var count = extractCountFromFragments(fragments);
			if (count !== null) {
				Appress.indicator.set('woo_cart', count);
			} else {
				Appress.indicator.refresh('woo_cart');
			}
		});

		jQuery(document.body).on('removed_from_cart', function(e, fragments) {
			var count = extractCountFromFragments(fragments);
			if (count !== null) {
				Appress.indicator.set('woo_cart', count);
			} else {
				Appress.indicator.refresh('woo_cart');
			}
		});

		jQuery(document.body).on('updated_cart_totals wc_fragments_refreshed', function() {
			readCountFromDom();
		});
	}

	// 2. WooCommerce Blocks Store API — /wc/store/ endpoints (including batch)
	Appress.indicator.realtime.onAction('wc/store', function(action, text) {
		try {
			var r = JSON.parse(text);
			// Direct cart response
			if (r && r.items_count !== undefined) {
				Appress.indicator.set('woo_cart', r.items_count);
				return;
			}
			// Batch response: {"responses":[{"body":{"items_count":N,...}}]}
			if (r && Array.isArray(r.responses)) {
				for (var i = 0; i < r.responses.length; i++) {
					var body = r.responses[i].body;
					if (body && body.items_count !== undefined) {
						Appress.indicator.set('woo_cart', body.items_count);
						return;
					}
				}
			}
		} catch(e) {}
	});

	// 3. Classic WC AJAX intercept — catches wc-ajax=add_to_cart and the
	//    remove/update endpoints. WC's `removed_from_cart` jQuery event
	//    fires only when the theme's mini-cart wires fragments; on themes
	//    that swap in their own remove handler (or POST to a custom endpoint)
	//    the jQuery event never fires, so we also tap the underlying wc-ajax
	//    response to keep the badge accurate.
	function handleWcAjaxResponse(action, text) {
		try {
			var r = JSON.parse(text);
			if (!r || r.error) return;
			var count = extractCountFromFragments(r.fragments);
			if (count !== null) {
				Appress.indicator.set('woo_cart', count);
			} else {
				Appress.indicator.refresh('woo_cart');
			}
		} catch(e) {
			// Non-JSON response (some themes echo raw HTML on remove) —
			// authoritative server fetch is the only reliable fallback.
			Appress.indicator.refresh('woo_cart');
		}
	}
	Appress.indicator.realtime.onAction('wc-ajax=add_to_cart',     handleWcAjaxResponse);
	Appress.indicator.realtime.onAction('wc-ajax=remove_from_cart', handleWcAjaxResponse);
	Appress.indicator.realtime.onAction('wc-ajax=update_cart',      handleWcAjaxResponse);
	Appress.indicator.realtime.onAction('wc-ajax=apply_coupon',     handleWcAjaxResponse);
	Appress.indicator.realtime.onAction('wc-ajax=remove_coupon',    handleWcAjaxResponse);

	// 3. Redirect fallback — after page load on cart/checkout/single-product pages
	//    (handles redirect add-to-cart where no AJAX fires)
	readCountFromDom();

	// ═══ HELPERS ═══

	// Extract cart count from WooCommerce fragments HTML
	function extractCountFromFragments(fragments) {
		if (!fragments) return null;
		// Fragments contain HTML snippets keyed by CSS selectors
		// Common: '.cart-contents .count', 'a.cart-contents', '.widget_shopping_cart_content'
		for (var selector in fragments) {
			var html = fragments[selector];
			if (typeof html !== 'string') continue;
			// Look for cart-count patterns in fragment HTML
			var match = html.match(/class=["'][^"']*cart[_-]?count[^"']*["'][^>]*>\s*(\d+)/i)
				|| html.match(/(\d+)\s*<\/span>\s*items?/i)
				|| html.match(/cart-contents[^"']*["'][^>]*>[^<]*?(\d+)/i);
			if (match) return parseInt(match[1]) || 0;
		}
		return null;
	}

	// Read count directly from page DOM (reliable after page load or fragment refresh)
	function readCountFromDom() {
		// Common WooCommerce cart count selectors across themes
		var selectors = [
			'.cart-contents .count',
			'.cart-count',
			'.mini-cart-count',
			'.cart-contents-count',
			'.woocommerce-cart-count',
			'.header-cart .count',
			'a.cart-contents'
		];
		for (var i = 0; i < selectors.length; i++) {
			var el = document.querySelector(selectors[i]);
			if (el) {
				var text = el.textContent.replace(/[^0-9]/g, '');
				if (text !== '') {
					Appress.indicator.set('woo_cart', parseInt(text) || 0);
					return;
				}
			}
		}
		// No count exposed in DOM — typical when cart went empty (theme
		// hides the count widget) or theme uses non-standard markup.
		// Fall through to the authoritative server count so the badge
		// can drop to 0 instead of staying on the stale pre-removal value.
		Appress.indicator.refresh('woo_cart');
	}
})();
JS;

		// ═══ CROSS-WEBVIEW SYNC ═══
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
			if ((cur !== _myVer || _stale) && document.querySelector('.woocommerce-cart-form, .cart_totals, .woocommerce-mini-cart, .widget_shopping_cart, .wc-block-cart, .wc-block-mini-cart, .wc-block-checkout, .wp-block-woocommerce-cart, .wp-block-woocommerce-checkout')) {
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

		// Background reload via native broadcast
		$js_snippets[] = <<<'JS'
Appress.indicator.afterUpdate(function(key) {
	if (key === 'woo_cart' && document.querySelector('.woocommerce-cart-form, .cart_totals, .woocommerce-mini-cart, .widget_shopping_cart, .wc-block-cart, .wc-block-mini-cart, .wc-block-checkout, .wp-block-woocommerce-cart, .wp-block-woocommerce-checkout')) {
		Appress.reloadScreen();
	}
});
JS;

		return $js_snippets;
	}
}
