/**
 * Menu-toggle widget — `[appress_menu_toggle]` (shortcode / Elementor / Bricks).
 *
 * Wires every `[data-appress-menu-toggle]` root rendered on the page.
 *
 * Click behaviour:
 *   - `window.Appress.backButtonContext === 'menu'` (button placed inside
 *     the side menu's own WebView) → post `close_side_menu` to native.
 *   - Anywhere else (tab / subscreen / unknown ctx) → post `open_side_menu`.
 *
 * The wrapper element also carries the `.appress-open-menu` class so the
 * slave JS link-interceptor opens the menu even when this widget JS
 * hasn't bound yet (covers tap-during-load race). The two paths are
 * idempotent on the native side — one wins, the other no-ops.
 */
(function () {
	function postNative(type) {
		var msg = JSON.stringify({ type: type });
		try {
			if (window.AppressNativeBridge && typeof window.AppressNativeBridge.postMessage === 'function') {
				window.AppressNativeBridge.postMessage(msg);
				return true;
			}
			if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.AppressNativeBridge) {
				window.webkit.messageHandlers.AppressNativeBridge.postMessage(msg);
				return true;
			}
			if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.AppressLinkIntercept) {
				window.webkit.messageHandlers.AppressLinkIntercept.postMessage({ type: type });
				return true;
			}
		} catch (e) {}
		return false;
	}

	function getContext() {
		return (window.Appress && typeof window.Appress.backButtonContext === 'string')
			? window.Appress.backButtonContext
			: null;
	}

	function onClick(evt) {
		evt.preventDefault();
		evt.stopPropagation();
		var ctx = getContext();
		if (ctx === 'menu') {
			postNative('close_side_menu');
		} else {
			postNative('open_side_menu');
		}
	}

	function wire(root) {
		if (root.__appressMenuToggleBound) return;
		root.__appressMenuToggleBound = true;
		root.addEventListener('click', onClick);
	}

	function scan() {
		var nodes = document.querySelectorAll('[data-appress-menu-toggle]');
		for (var i = 0; i < nodes.length; i++) wire(nodes[i]);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scan);
	} else {
		scan();
	}

	// Builder previews + dynamically inserted instances — re-scan when
	// DOM mutates. `wire` is idempotent via the __appressMenuToggleBound flag.
	if (typeof MutationObserver === 'function') {
		try {
			new MutationObserver(scan).observe(document.documentElement, { childList: true, subtree: true });
		} catch (e) {}
	}
})();
