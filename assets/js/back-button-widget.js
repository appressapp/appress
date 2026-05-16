/**
 * Back-button widget behaviour — shortcode / Elementor / Bricks / Avada.
 *
 * Wires every `[data-appress-back-button]` root rendered on the page.
 *
 * Click behaviour (history first, close fallback):
 *   1. If `window.history.length > 1`, optimistically call
 *      `history.back()` and listen for `popstate` / `pagehide` to
 *      detect a real navigation. If neither fires within 120ms the
 *      browser silently no-op'd the call (sitting at the very first
 *      entry while forward history still exists) — fall through to
 *      the context close.
 *   2. Context close:
 *      - `'menu'`      → `close_side_menu`
 *      - `'subscreen'` → `pop_standalone`
 *      - `'tab'`       → no-op (the button is hidden via shouldShow)
 *      - null (legacy) → `pop_standalone`
 *
 * Why the popstate-listen dance: `window.history.length` counts BOTH
 * back and forward entries, so it stays > 1 after the user has already
 * walked all the way back. `history.back()` is a silent no-op in that
 * case — without the listener+fallback the button does nothing on the
 * second click, which user-reported as "lúc làm lúc không / không
 * làm gì luôn".
 *
 * Auto-hide re-eval points: init, `popstate`, `pageshow` (bfcache).
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

	function normalize(u) {
		try {
			var url = new URL(u);
			return url.origin + url.pathname.replace(/\/$/, '');
		} catch (e) {
			return (u || '').replace(/\/$/, '');
		}
	}

	function canGoBackInHistory() {
		// `history.length > 1` IS the reliable signal:
		// - HTTP 302 redirect chains DON'T add history entries (the
		//   browser silently follows them), so a fresh subscreen that
		//   landed via redirect still has length === 1.
		// - User-clicked links / form submits / JS pushState all add
		//   entries — exactly the navigations we want to undo.
		// URL-compare against `screenEntryHref` was tried earlier but
		// produced too many false negatives: stripping query/hash hid
		// pagination, hash-anchor nav, and pushState tab switchers —
		// the user reported "back button just closes the subscreen"
		// instead of unwinding those in-page navs. Drop the URL gate.
		return !!window.history && window.history.length > 1;
	}

	function shouldShow() {
		// `master` context is checked BEFORE the history-first short-
		// circuit because master pages (auth gate, first launch) can
		// legitimately have history.length > 1 from a Capacitor
		// scaffold → auth URL redirect chain, but `history.back()`
		// there walks into the Capacitor stub (404) or a previous
		// auth-step page the user can't legally return to. Native
		// injects this context at documentStart on every master
		// navigation so the button never appears on pages where back-
		// nav has no valid destination.
		var ctx = getContext();
		if (ctx === 'master') return false;
		// History-first: button is meaningful whenever back-nav has a
		// real target. The context-based close is the fallback only.
		if (canGoBackInHistory()) return true;
		if (ctx === 'menu' || ctx === 'right_menu' || ctx === 'subscreen') return true;
		if (ctx === 'tab') return false;
		// Legacy/browser fallback (no native context marker) — keep
		// visible so old subscreen-only flows still work.
		return true;
	}

	function fallbackClose(ctx) {
		if (ctx === 'menu')       { postNative('close_side_menu');  return; }
		if (ctx === 'right_menu') { postNative('close_right_menu'); return; }
		if (ctx === 'subscreen')  { postNative('pop_standalone');   return; }
		if (ctx === 'tab') { return; }
		// Legacy null context — pop_standalone, native no-ops if stack
		// is empty.
		postNative('pop_standalone');
	}

	function onClick(evt) {
		evt.preventDefault();
		evt.stopPropagation();
		var ctx = getContext();

		// `window.history.length > 1` is necessary BUT NOT sufficient —
		// it counts forward entries too. After back-navigating to the
		// first entry, length is still > 1 (forwards exist) but
		// `history.back()` is a silent no-op. Trying it blindly leaves
		// the button feeling dead.
		//
		// Strategy: optimistically try `history.back()` + listen for
		// `popstate` (same-doc state pop) or `pagehide` (cross-doc
		// navigation away). If neither fires within ~120ms the call
		// was a no-op, so fall through to the context close action.
		if (canGoBackInHistory()) {
			var beforeHref = window.location.href;
			var navigated = false;
			var onPop = function () { navigated = true; };
			var onHide = function () { navigated = true; };
			window.addEventListener('popstate', onPop, true);
			window.addEventListener('pagehide', onHide, true);

			// Tell native this navigation is back/forward so the
			// subscreen-pattern router skips it (Android needs the
			// explicit flag; iOS gets it from .backForward natively).
			postNative('mark_back_forward_nav');
			try { window.history.back(); } catch (e) {}

			setTimeout(function () {
				window.removeEventListener('popstate', onPop, true);
				window.removeEventListener('pagehide', onHide, true);
				if (!navigated && window.location.href === beforeHref) {
					// `history.back()` was silently no-op → at the first
					// entry of this WebView session. Honour the original
					// "close screen" intent.
					fallbackClose(ctx);
				}
			}, 120);
			return;
		}
		// No history at all → direct close.
		fallbackClose(ctx);
	}

	function applyVisibility(root) {
		var visible = shouldShow();
		// `display: none` over `hidden` so the button takes no layout space
		// when hidden — page builders may put it in a flex/grid row where
		// keeping the slot would shift adjacent widgets.
		if (visible) {
			if (root.style.display === 'none') root.style.display = '';
		} else {
			root.style.display = 'none';
		}
	}

	function wire(root) {
		if (root.__appressBackBound) {
			applyVisibility(root);
			return;
		}
		root.__appressBackBound = true;
		root.addEventListener('click', onClick);
		applyVisibility(root);
	}

	function scan() {
		var nodes = document.querySelectorAll('[data-appress-back-button]');
		for (var i = 0; i < nodes.length; i++) wire(nodes[i]);
	}

	function rescanVisibility() {
		var nodes = document.querySelectorAll('[data-appress-back-button]');
		for (var i = 0; i < nodes.length; i++) applyVisibility(nodes[i]);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scan);
	} else {
		scan();
	}

	// History grows/shrinks → re-evaluate visibility. `popstate` fires on
	// history.back / forward; `pageshow` covers bfcache restore where the
	// previous page is shown again without a fresh DOMContentLoaded.
	window.addEventListener('popstate', rescanVisibility);
	window.addEventListener('pageshow', rescanVisibility);

	// Builder previews + dynamically inserted instances — re-scan when DOM
	// mutates. Wire wraps duplicates safely via the __appressBackBound flag.
	if (typeof MutationObserver === 'function') {
		try {
			new MutationObserver(scan).observe(document.documentElement, { childList: true, subtree: true });
		} catch (e) {}
	}
})();
