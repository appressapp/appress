/**
 * Menu-toggle widget — `[appress_menu_toggle]` (shortcode / Elementor / Bricks).
 *
 * Wires every `[data-appress-menu-toggle]` root rendered on the page.
 *
 * Target menu (added 2026-05-15 for two-drawer apps):
 *   - `data-appress-menu-target="left"`  (default) → controls the LEFT drawer
 *   - `data-appress-menu-target="right"`           → controls the RIGHT drawer
 *
 * Click behaviour:
 *   - If the button is INSIDE the matching drawer's own WebView
 *     (`window.Appress.backButtonContext === 'menu'` for left,
 *     `=== 'right_menu'` for right) → post the close event for that drawer.
 *   - Anywhere else (tab / subscreen / unknown ctx) → post the open event
 *     for the target drawer.
 *
 * The wrapper element also carries `.appress-open-menu` or
 * `.appress-open-right-menu` class so the slave JS link-interceptor still
 * opens the correct drawer even when this widget JS hasn't bound yet
 * (covers tap-during-load race). The two paths are idempotent on the
 * native side — one wins, the other no-ops.
 */
(function () {
	// Per-app native-class-ID indirection — see back-button-widget.js
	// for the full explanation. Same pattern reused.
	var IDS = window.AppressClassIds || {};
	var NB_KEY = IDS.native        || 'AppressNativeBridge';
	var MB_KEY = IDS.master        || 'AppressMasterBridge';
	var LI_KEY = IDS.linkIntercept || 'AppressLinkIntercept';
	var NS_KEY = IDS.namespace     || 'Appress';

	function postNative(type) {
		var msg = JSON.stringify({ type: type });
		try {
			var nb = window[NB_KEY];
			if (nb && typeof nb.postMessage === 'function') {
				nb.postMessage(msg);
				return true;
			}
			var mh = window.webkit && window.webkit.messageHandlers;
			if (mh && mh[NB_KEY]) {
				mh[NB_KEY].postMessage(msg);
				return true;
			}
			if (mh && mh[LI_KEY]) {
				mh[LI_KEY].postMessage({ type: type });
				return true;
			}
			// Android Capacitor master (home-screen) WebView has NO native
			// slave bridge — it exposes the master bridge with direct
			// open methods instead. Without this fallback the open-menu
			// button on a master-rendered page (the home screen) does
			// nothing: `window[NB_KEY]` is undefined there, and onClick
			// already stopPropagation'd the event so the native master
			// link-interceptor never sees it. Mirrors the NB→MB fallback
			// in translatepress-switcher.js. (Close events only fire when
			// the button sits INSIDE a drawer, which is a slave WebView
			// where NB exists — so only the open methods are needed here.)
			var mb = window[MB_KEY];
			if (mb) {
				if (type === 'open_side_menu'  && typeof mb.openSideMenu  === 'function') { mb.openSideMenu();  return true; }
				if (type === 'open_right_menu' && typeof mb.openRightMenu === 'function') { mb.openRightMenu(); return true; }
			}
		} catch (e) {}
		return false;
	}

	function getContext() {
		var ns = window[NS_KEY];
		return (ns && typeof ns.backButtonContext === 'string')
			? ns.backButtonContext
			: null;
	}

	function targetOf(root) {
		// 'left' (default) | 'right'. Anything else falls back to 'left' so
		// pre-2026-05-15 markup without the attribute keeps working.
		var raw = (root.getAttribute('data-appress-menu-target') || '').toLowerCase();
		return raw === 'right' ? 'right' : 'left';
	}

	function onClick(evt) {
		var root = evt.currentTarget;
		evt.preventDefault();
		evt.stopPropagation();

		var target = targetOf(root);
		var ctx    = getContext();

		// Same-drawer toggle: button placed INSIDE the drawer's WebView
		// acts as a close button. Cross-drawer (left button inside right
		// menu, or vice-versa) still opens — useful for "switch drawer"
		// flows where the user taps a button in the right menu that
		// opens the left, etc.
		if (target === 'left' && ctx === 'menu') {
			postNative('close_side_menu');
			return;
		}
		if (target === 'right' && ctx === 'right_menu') {
			postNative('close_right_menu');
			return;
		}
		postNative(target === 'right' ? 'open_right_menu' : 'open_side_menu');
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
