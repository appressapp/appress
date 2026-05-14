/**
 * Account-deletion widget — OTP code flow.
 *
 * State machine on each `[data-appress-account-deletion]` root:
 *
 *   REQUEST → (tap "Delete my account")
 *   confirm() dialog → POST request_code → if success: show VERIFY screen
 *                                          if error:   inline message on REQUEST
 *
 *   VERIFY  → (paste / autofill 6 digits, tap "Confirm deletion")
 *   POST verify_code → if success: show DONE screen, optional auto-redirect
 *                      if wrong:   inline message, attempts countdown
 *                      if locked / expired: bounce to REQUEST + message
 *
 *   Auxiliary on VERIFY:
 *   - "Resend code" (60s cooldown, server caps at 3/hour)
 *   - "Cancel" → back to REQUEST screen
 *
 * Builder previews set `data-demo="1"` on the wrapper so Elementor /
 * Bricks editor canvases skip the live behaviour and just paint the
 * static markup for styling.
 */
(function () {
	var CFG  = (window.AppressAccountDeletion && typeof window.AppressAccountDeletion === 'object') ? window.AppressAccountDeletion : {};
	var I18N = (CFG.i18n && typeof CFG.i18n === 'object') ? CFG.i18n : {};
	function t(key, fallback) { return typeof I18N[key] === 'string' ? I18N[key] : fallback; }
	function fmt(tpl, val) { return String(tpl).replace(/%s/, val).replace(/%d/, val); }

	function showScreen(root, name) {
		var screens = root.querySelectorAll('[data-screen]');
		for (var i = 0; i < screens.length; i++) {
			var s = screens[i];
			if (s.getAttribute('data-screen') === name) {
				s.hidden = false;
			} else {
				s.hidden = true;
			}
		}
	}

	function setMessage(root, screen, text, isError) {
		var el = root.querySelector('[data-message="' + screen + '"]');
		if (!el) return;
		el.textContent = text || '';
		el.style.display = text ? '' : 'none';
		el.classList.toggle('is-error', !!isError);
	}

	function clearMessage(root, screen) { setMessage(root, screen, '', false); }

	function postForm(url, data) {
		var form = new FormData();
		form.append('nonce', CFG.nonce || '');
		for (var k in data) {
			if (Object.prototype.hasOwnProperty.call(data, k)) form.append(k, data[k]);
		}
		return fetch(url, { method: 'POST', body: form, credentials: 'same-origin' })
			.then(function (r) { return r.json(); });
	}

	/**
	 * Surface the server's structured error code with the right i18n
	 * key. `data.message` is the localized fallback when we don't have
	 * a per-code translation — handles future server-side codes
	 * without a client release.
	 */
	function renderError(root, screen, data) {
		var code = data && data.code;
		var msg;
		if (code === 'admin')              msg = t('err_admin', 'Administrators cannot delete account');
		else if (code === 'invalid_input') msg = t('err_invalid_input', 'Enter the 6-digit code from the email.');
		else if (code === 'wrong')         msg = fmt(t('err_wrong', 'Incorrect code. %d attempt(s) left.'), (data && typeof data.attempts_left === 'number') ? data.attempts_left : '?');
		else if (code === 'locked')        msg = t('err_locked', 'Too many wrong attempts. Please request a new code.');
		else if (code === 'expired')       msg = t('err_expired', 'Code expired or unused. Please request a new code.');
		else if (data && data.message)     msg = data.message;
		else                                msg = t('err_generic', 'Something went wrong. Please try again later.');
		setMessage(root, screen, msg, true);
	}

	function wire(root) {
		if (root.__appressAccountDeletionBound) return;
		root.__appressAccountDeletionBound = true;

		var demo = root.getAttribute('data-demo') === '1';
		if (demo) return;

		var requestBtn = root.querySelector('[data-action="request-code"]');
		var verifyBtn  = root.querySelector('[data-action="verify-code"]');
		var resendBtn  = root.querySelector('[data-action="resend-code"]');
		var cancelBtn  = root.querySelector('[data-action="cancel-verify"]');
		var codeInput  = root.querySelector('[data-code-input]');
		if (!requestBtn || !verifyBtn || !resendBtn || !cancelBtn || !codeInput) return;

		// Static text inside the verify screen — set once, never
		// re-rendered. Heading is filled with the masked email when we
		// transition to verify.
		root.querySelector('[data-verify-hint]').textContent       = t('verify_hint', 'The code expires in 30 minutes.');
		root.querySelector('[data-confirm-label]').textContent     = t('btn_confirm', 'Confirm deletion');
		root.querySelector('[data-cancel-label]').textContent      = t('btn_cancel', 'Cancel');

		var resendTimer = null;
		function setResendCooldown(seconds) {
			if (resendTimer) { clearInterval(resendTimer); resendTimer = null; }
			var remaining = seconds;
			function tick() {
				if (remaining <= 0) {
					resendBtn.disabled = false;
					resendBtn.textContent = t('btn_resend', 'Resend code');
					if (resendTimer) { clearInterval(resendTimer); resendTimer = null; }
					return;
				}
				resendBtn.disabled = true;
				resendBtn.textContent = fmt(t('btn_resend_wait', 'Resend in %ds'), remaining);
				remaining -= 1;
			}
			tick();
			resendTimer = setInterval(tick, 1000);
		}

		function transitionToVerify() {
			var heading = root.querySelector('[data-verify-heading]');
			heading.textContent = t('verify_heading', 'Please enter the code sent to your account email');
			showScreen(root, 'verify');
			clearMessage(root, 'verify');
			codeInput.value = '';
			verifyBtn.disabled = true;
			setResendCooldown(CFG.resendCooldown || 60);
			// Focus the input so the keyboard pops on mobile + the iOS
			// OTP autofill suggestion has somewhere to land.
			setTimeout(function () { codeInput.focus(); }, 80);
		}

		function transitionToRequest() {
			showScreen(root, 'request');
			clearMessage(root, 'request');
			requestBtn.disabled = false;
			// Restore the original label in case a "Sending…" swap left
			// it stuck on a transient state.
			var lbl = requestBtn.querySelector('.appress-btn__label');
			if (lbl && requestBtn.__originalLabel) lbl.textContent = requestBtn.__originalLabel;
			if (resendTimer) { clearInterval(resendTimer); resendTimer = null; }
		}

		function transitionToDone(message) {
			var el = root.querySelector('[data-done-message]');
			if (el) el.textContent = message;
			// Surface the "Redirecting…" affordance so the 4s pause
			// reads as "the app is doing something" instead of "the
			// app froze after deleting my account". Spinner + text
			// gives both visual + textual feedback; the polite
			// aria-live announces it to screen readers once.
			var lblEl = root.querySelector('[data-redirecting-label]');
			if (lblEl) lblEl.textContent = t('redirecting', 'Redirecting…');
			showScreen(root, 'done');
			if (resendTimer) { clearInterval(resendTimer); resendTimer = null; }
			// Hard reload after 4 seconds so the now-deleted user lands
			// on the public homepage with cookies cleared (server-side
			// `wp_logout` cleared the cookie on the response, but only
			// a navigation makes the browser stop sending it). 4s gives
			// the success message time to read.
			setTimeout(function () { location.href = '/'; }, 4000);
		}

		// ── REQUEST: tap delete button ─────────────────────────────
		// TEMPORARY: confirm-only flow while iCloud deliverability is
		// being sorted out. Posts directly to the no-OTP `deleteUrl`
		// after the user accepts `window.confirm()`. To revert to the
		// email-OTP flow, swap `CFG.deleteUrl` → `CFG.requestUrl` and
		// `transitionToDone(message)` → `transitionToVerify()` below.
		// The OTP branch + verify screen + email endpoints remain in
		// the codebase, just unused by this entry path.
		requestBtn.addEventListener('click', function () {
			if (!window.confirm(t('confirm_delete', 'This will permanently delete your account. Are you sure you want to continue?'))) return;
			clearMessage(root, 'request');
			requestBtn.disabled = true;
			var lbl = requestBtn.querySelector('.appress-btn__label');
			if (lbl) {
				requestBtn.__originalLabel = requestBtn.__originalLabel || lbl.textContent;
				lbl.textContent = t('sending', 'Sending…');
			}
			postForm(CFG.deleteUrl || '', {})
				.then(function (data) {
					if (lbl && requestBtn.__originalLabel) lbl.textContent = requestBtn.__originalLabel;
					if (data && data.success) {
						transitionToDone((data && data.message) || t('success_deleted', 'Your account has been deleted. Goodbye!'));
						return;
					}
					requestBtn.disabled = false;
					renderError(root, 'request', data);
				})
				.catch(function () {
					if (lbl && requestBtn.__originalLabel) lbl.textContent = requestBtn.__originalLabel;
					requestBtn.disabled = false;
					setMessage(root, 'request', t('err_network', 'Network error. Please try again.'), true);
				});
		});

		// ── VERIFY: input enables button only on 6 digits ──────────
		codeInput.addEventListener('input', function () {
			// Strip everything except digits as the user types. Lets
			// paste-from-email work even when the mail client wraps the
			// code in surrounding text (e.g. "Your code is 123456.").
			var cleaned = codeInput.value.replace(/\D+/g, '').slice(0, 6);
			if (codeInput.value !== cleaned) codeInput.value = cleaned;
			verifyBtn.disabled = cleaned.length !== 6;
			clearMessage(root, 'verify');
		});

		// Submit-on-Enter for keyboard users
		codeInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !verifyBtn.disabled) verifyBtn.click();
		});

		// ── VERIFY: confirm deletion ──────────────────────────────
		verifyBtn.addEventListener('click', function () {
			var code = codeInput.value;
			clearMessage(root, 'verify');
			verifyBtn.disabled = true;
			var lbl = verifyBtn.querySelector('.appress-btn__label');
			var original = lbl ? lbl.textContent : '';
			if (lbl) lbl.textContent = t('verifying', 'Verifying…');
			postForm(CFG.verifyUrl || '', { code: code })
				.then(function (data) {
					if (lbl) lbl.textContent = original;
					if (data && data.success) {
						transitionToDone((data && data.message) || t('success_deleted', 'Your account has been deleted. Goodbye!'));
						return;
					}
					verifyBtn.disabled = false;
					renderError(root, 'verify', data);
					// Server invalidated the code — bounce back to
					// request so the user has the correct affordance
					// (a "Delete my account" button to start over).
					if (data && (data.code === 'locked' || data.code === 'expired' || data.code === 'admin')) {
						setTimeout(function () {
							transitionToRequest();
							setMessage(root, 'request', (data && data.message) || '', true);
						}, 1500);
					}
				})
				.catch(function () {
					if (lbl) lbl.textContent = original;
					verifyBtn.disabled = false;
					setMessage(root, 'verify', t('err_network', 'Network error. Please try again.'), true);
				});
		});

		// ── VERIFY: resend code (60s cooldown) ────────────────────
		resendBtn.addEventListener('click', function () {
			if (resendBtn.disabled) return;
			clearMessage(root, 'verify');
			resendBtn.disabled = true;
			postForm(CFG.requestUrl || '', {})
				.then(function (data) {
					if (data && data.success) {
						setResendCooldown(CFG.resendCooldown || 60);
						return;
					}
					resendBtn.disabled = false;
					resendBtn.textContent = t('btn_resend', 'Resend code');
					renderError(root, 'verify', data);
				})
				.catch(function () {
					resendBtn.disabled = false;
					resendBtn.textContent = t('btn_resend', 'Resend code');
					setMessage(root, 'verify', t('err_network', 'Network error. Please try again.'), true);
				});
		});

		// ── VERIFY: cancel → back to request ──────────────────────
		cancelBtn.addEventListener('click', function () {
			transitionToRequest();
		});
	}

	function init() {
		var roots = document.querySelectorAll('[data-appress-account-deletion]');
		for (var i = 0; i < roots.length; i++) wire(roots[i]);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	if (window.MutationObserver) {
		var mo = new MutationObserver(init);
		mo.observe(document.documentElement, { childList: true, subtree: true });
	}
})();
