/**
 * Biometric widget behaviour — shortcode / Elementor / Bricks.
 *
 * Wires every `[data-appress-biometric]` root rendered on the page:
 *   - Logged-OUT state: "Sign in with Face ID / Touch ID" button that
 *     calls `window.Appress.biometric.signIn()`; reloads on success.
 *     Only reveals itself when the device has a paired token.
 *   - Logged-IN state: live pairing status + Enable/Disable toggle +
 *     "Clear all devices" button (server-side revoke + local purge).
 *
 * The whole widget stays `display:none` until the JS bridge exists —
 * browsers and non-biometric builds render nothing.
 *
 * Builder previews set `data-demo="1"` on the wrapper so Elementor /
 * Bricks editor canvases skip the bridge gate and show the full
 * rendered layout while styling.
 */
(function () {
	// Server-provided config — translations, AJAX URL, and CSRF nonce. Emitted
	// via `appress/assets/localize/{handle}` filter in
	// Biometric\Shortcode_Controller::localize_strings(). Defensive defaults
	// so the widget never blows up if the global is missing.
	var CFG  = (window.AppressBiometric && typeof window.AppressBiometric === 'object') ? window.AppressBiometric : {};
	var I18N = (CFG.i18n && typeof CFG.i18n === 'object') ? CFG.i18n : {};
	function t(key, fallback) { return typeof I18N[key] === 'string' ? I18N[key] : fallback; }

	function postPill(type) {
		try {
			if (window.AppressNativeBridge && typeof window.AppressNativeBridge.postMessage === 'function') {
				window.AppressNativeBridge.postMessage(JSON.stringify({ type: type }));
			} else if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.AppressLinkIntercept) {
				window.webkit.messageHandlers.AppressLinkIntercept.postMessage({ type: type });
			}
		} catch (e) {}
	}

	function wire(root) {
		// Idempotent: drag-add in builders re-runs init() on every DOM
		// mutation, but each root must be wired exactly once.
		if (root.__appressBiometricBound) return;
		root.__appressBiometricBound = true;

		var loggedIn = root.getAttribute('data-logged-in') === '1';
		var demo     = root.getAttribute('data-demo') === '1';
		var appId    = parseInt(root.getAttribute('data-app-id') || '0', 10);

		// Builder editor preview: skip the native bridge gate so
		// Elementor / Bricks admins see the rendered layout.
		if (demo) { root.style.display = ''; return; }

		// No native bridge = desktop browser. Entire widget stays
		// hidden — nothing to offer without biometric hardware.
		if (!window.Appress || !window.Appress.biometric) return;

		if (loggedIn) { wirePanel(root, appId); }
		else          { wireLogin(root); }
	}

	function wireLogin(root) {
		var btn = root.querySelector('[data-action="login"]');
		if (!btn) return;

		// Only reveal when the device has a paired token AND biometric
		// is usable right now.
		window.Appress.biometric.status().then(function (s) {
			if (!s || s.availability !== 'available' || !s.paired) return;
			root.style.display = '';
		}, function () {});

		btn.addEventListener('click', function () {
			btn.disabled = true;
			postPill('show_pill_spinner');
			window.Appress.biometric.signIn().then(function (r) {
				if (r && r.success) {
					// Cookies landed — reload so WP sees the user.
					location.reload();
					return;
				}
				postPill('hide_pill_spinner');
				btn.disabled = false;
			}, function () {
				postPill('hide_pill_spinner');
				btn.disabled = false;
			});
		});
	}

	function wirePanel(root, appId) {
		var statusEl = root.querySelector('[data-status]');
		var toggle   = root.querySelector('[data-action="toggle"]');
		var clearAll = root.querySelector('[data-action="clear-all"]');
		var errEl    = root.querySelector('[data-error]');

		function refresh() {
			window.Appress.biometric.status().then(function (s) {
				if (!s || s.availability === 'unavailable') {
					root.style.display = 'none';
					return;
				}
				root.style.display = '';
				if (s.availability === 'not_enrolled') {
					statusEl.textContent = t('status_not_enrolled', 'Set up Face ID / fingerprint in device settings first.');
					toggle.disabled = true;
					toggle.textContent = t('btn_unavailable', 'Unavailable');
					return;
				}
				toggle.disabled = false;
				if (s.paired) {
					statusEl.textContent = t('status_paired', 'Active on this device.');
					toggle.textContent = t('btn_disable', 'Disable');
					toggle.setAttribute('data-mode', 'disable');
					toggle.setAttribute('aria-pressed', 'true');
				} else {
					statusEl.textContent = t('status_not_paired', 'Not paired on this device.');
					toggle.textContent = t('btn_enable', 'Enable');
					toggle.setAttribute('data-mode', 'enable');
					toggle.setAttribute('aria-pressed', 'false');
				}
			}, function () { root.style.display = 'none'; });
		}

		toggle.addEventListener('click', function () {
			toggle.disabled = true;
			errEl.style.display = 'none';
			postPill('show_pill_spinner');
			var mode = toggle.getAttribute('data-mode');
			var call = mode === 'disable' ? window.Appress.biometric.disable() : window.Appress.biometric.enable();
			call.then(function (r) {
				postPill('hide_pill_spinner');
				if (r && !r.success && r.reason && r.reason !== 'cancelled') {
					errEl.textContent = mode === 'disable'
						? t('err_toggle_disable', 'Could not disable biometric. Please try again.')
						: t('err_toggle_enable', 'Could not enable biometric. Please try again.');
					errEl.style.display = '';
				}
				refresh();
			}, function () {
				postPill('hide_pill_spinner');
				refresh();
			});
		});

		clearAll.addEventListener('click', function () {
			if (!confirm(t('confirm_clear_devices', 'This will remove biometric sign-in from ALL your devices. You can re-enable later. Continue?'))) return;
			clearAll.disabled = true;
			errEl.style.display = 'none';
			var body = new FormData();
			body.append('app_id', String(appId || 0));
			body.append('nonce',  String(CFG.nonce || ''));
			// Fallback URL only fires if the localized config is somehow missing.
			fetch(CFG.ajaxUrl || '/?appress=1&action=auth.biometric.revoke', {
				method: 'POST', credentials: 'include', body: body
			}).then(function (r) { return r.json(); })
			  .then(function (res) {
				clearAll.disabled = false;
				if (!res || !res.success) {
					errEl.textContent = (res && res.message) ? res.message : t('err_clear_devices', 'Could not clear devices.');
					errEl.style.display = '';
					return;
				}
				// Server killed all tokens. Also clear this device's
				// local Keychain so status flips to "not paired"
				// instantly — otherwise the user sees a stale UI until
				// the next signIn attempt self-heals.
				if (typeof window.Appress.biometric.forgetLocal === 'function') {
					window.Appress.biometric.forgetLocal().then(refresh, refresh);
				} else {
					refresh();
				}
			  }, function () {
				clearAll.disabled = false;
				errEl.textContent = t('err_network', 'Network error. Please try again.');
				errEl.style.display = '';
			  });
		});

		refresh();
	}

	function init() {
		var roots = document.querySelectorAll('[data-appress-biometric]');
		for (var i = 0; i < roots.length; i++) wire(roots[i]);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Builder previews (Avada / Elementor / Bricks) inject the widget
	// DOM via AJAX after this script's initial mount() pass — re-scan
	// on every DOM mutation. `wire()` is idempotent so re-runs are
	// cheap. Pass `init` directly so the observer callback ignores its
	// MutationRecord arg and just re-scans.
	if (typeof MutationObserver === 'function') {
		try {
			new MutationObserver(init).observe(document.documentElement, { childList: true, subtree: true });
		} catch (e) {}
	}
})();
