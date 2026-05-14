/**
 * Sign in with Apple visibility gate.
 *
 * Apple Guideline 4.8 mandates SIWA on iOS only — Android and the
 * desktop web have no Apple Sign-In SDK, so the button would render
 * but tap into a dead end. Server-side UA sniffing won't survive page
 * caches (Varnish, LSCache, Cloudflare), so we hide the button by
 * default and unhide it client-side once we know we're inside the
 * iOS Capacitor WebView.
 *
 * Both the master Capacitor WebView and the slave WebViews expose
 * `Capacitor.getPlatform()` (the slave bundle's `coreSlaveJS` mock
 * returns `'ios'` on iOS devices), so the same check works in every
 * Appress surface — auth gate, tabs, side menu, screen detail.
 *
 * Editor preview surfaces (Elementor canvas, Bricks builder) opt
 * out by setting `data-demo="1"` on the button — that branch leaves
 * the button visible regardless of platform so the admin can style
 * it without having to load the page on a real device.
 */
(function () {
	function isIOSAppContext() {
		try {
			var Cap = window.Capacitor;
			if (!Cap || typeof Cap.getPlatform !== 'function') return false;
			if (Cap.getPlatform() !== 'ios') return false;
			// Reject the rare case where Capacitor SDK loads on a desktop
			// browser via a misconfigured CDN — `isNativePlatform` returns
			// false there and we want the button hidden.
			if (typeof Cap.isNativePlatform === 'function' && !Cap.isNativePlatform()) return false;
			return true;
		} catch (_) {
			return false;
		}
	}

	function reveal() {
		var inIOS = isIOSAppContext();
		// Class matches `Apple_Shortcode_Controller::TRIGGER_CLS` — the same
		// class the click bridge in `Apple_Controller::inject_js_bridge`
		// listens to. Don't drift from `appress-apple-login` here: a
		// mismatch leaves every Apple button stuck at `display:none` (the
		// shortcode renders hidden, this gate is the only thing that ever
		// reveals it).
		var nodes = document.querySelectorAll('.appress-apple-login');
		for (var i = 0; i < nodes.length; i++) {
			var el = nodes[i];
			if (el.dataset.appressAppleResolved === '1') continue;
			el.dataset.appressAppleResolved = '1';
			if (inIOS || el.dataset.demo === '1') {
				el.style.display = '';
			} else {
				// Remove from DOM so screen readers + tab order skip it.
				el.parentNode && el.parentNode.removeChild(el);
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', reveal);
	} else {
		reveal();
	}

	// Re-run on dynamic mounts — Voxel, Elementor popups, and other
	// AJAX flows may inject the button after the initial paint. The
	// observer is cheap (subtree mutation count, no per-node read)
	// and self-stops once the gate has resolved every node it sees.
	try {
		if (typeof MutationObserver === 'function' && document.body) {
			var mo = new MutationObserver(function () { reveal(); });
			mo.observe(document.body, { childList: true, subtree: true });
		}
	} catch (_) {}
})();
