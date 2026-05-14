/**
 * QR Login button — opens modal, renders QR, polls server.
 *
 * Two modes from the SAME `.appress-login-by-qr-code-trigger` class:
 *
 *   - WEB CONTEXT (browser): clicking the button opens our modal with
 *     a freshly-minted QR session and polls `qr_login.poll` until the
 *     mobile app approves or the token expires.
 *
 *   - APPRESS APP CONTEXT (in-app WebView): the native layer detects
 *     the same class via a click listener injected at WebView creation
 *     time, opens the camera scanner, calls `qr_login.approve` after
 *     user confirmation, and CANCELS the click before our JS runs.
 *     Detection here: `window.AppressNativeBridge` (Android) or
 *     `window.webkit.messageHandlers.AppressNativeBridge` (iOS) → bail
 *     out so the modal doesn't fight the native flow.
 */
(function () {
	'use strict';

	var AJAX_URL = (window.Apppress_Config && window.Apppress_Config.ajaxUrl) || '/?appress=1';

	function isInAppressApp() {
		if (window.AppressNativeBridge && typeof window.AppressNativeBridge.postMessage === 'function') return true;
		if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.AppressNativeBridge) return true;
		return false;
	}


	// ─── QR encoder (Kazuhiko Arase qrcode-generator UMD, MIT) ───
	// `qrcode` is a global injected by `qrcode-generator.min.js` which
	// MUST be enqueued before this file (see Qr_Login_Shortcode_Controller).
	// Replaces an earlier hand-trimmed inline port whose output Apple
	// Camera (Vision NN-decoder) accepted but iOS AVCaptureMetadataOutput's
	// classical decoder rejected — shipping the full untrimmed library
	// closes that gap. ErrorCorrectionLevel 'M' + cellSize 8px + margin 4
	// modules = robust scan even on glossy laptop screens.
	var QR = {
		encode: function (payload) {
			var qr = qrcode(0, 'M');
			qr.addData(payload);
			qr.make();
			return qr.createImgTag(8, 4);
		}
	};

	// ─── Modal logic ─────────────────────────────────────────────────────
	var pollTimer = null, currentToken = null;

	function form(data) {
		var p = [];
		for (var k in data) if (Object.prototype.hasOwnProperty.call(data, k)) {
			p.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
		}
		return p.join('&');
	}
	function ajax(action, data) {
		return fetch(AJAX_URL + '&action=' + action, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: form(data || {})
		}).then(function (r) { return r.json(); });
	}

	function buildModal() {
		var overlay = document.createElement('div');
		overlay.className = 'appress-qr-login-modal';
		overlay.innerHTML =
			'<div class="appress-qr-login-modal__card" style="position:relative;">' +
				'<button type="button" class="appress-qr-login-modal__close" aria-label="Close">✕</button>' +
				'<h3 class="appress-qr-login-modal__title">Sign in with QR Code</h3>' +
				'<p class="appress-qr-login-modal__subtitle">Open the Appress mobile app and scan this code to sign in.</p>' +
				'<div class="appress-qr-login-modal__qr appress-qr-login-modal__qr--loading">Generating…</div>' +
				'<div class="appress-qr-login-modal__status">Waiting for scan…</div>' +
			'</div>';
		overlay.querySelector('.appress-qr-login-modal__close').addEventListener('click', closeModal);
		overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
		document.body.appendChild(overlay);
		return overlay;
	}

	function setStatus(card, text, kind) {
		var s = card.querySelector('.appress-qr-login-modal__status');
		if (!s) return;
		s.textContent = text;
		s.className = 'appress-qr-login-modal__status' + (kind ? ' appress-qr-login-modal__status--' + kind : '');
	}
	function setRetryButton(card, onClick) {
		var s = card.querySelector('.appress-qr-login-modal__status');
		if (!s) return;
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'appress-qr-login-modal__retry';
		btn.textContent = 'Try again';
		btn.addEventListener('click', onClick);
		s.appendChild(document.createElement('br'));
		s.appendChild(btn);
	}

	function closeModal() {
		if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
		currentToken = null;
		var existing = document.querySelector('.appress-qr-login-modal');
		if (existing) existing.remove();
	}

	function startSession(modal) {
		var qrBox = modal.querySelector('.appress-qr-login-modal__qr');
		qrBox.classList.add('appress-qr-login-modal__qr--loading');
		qrBox.textContent = 'Generating…';
		setStatus(modal, 'Waiting for scan…');
		ajax('qr_login.start').then(function (res) {
			if (!res || !res.success) {
				setStatus(modal, (res && res.message) || 'Unable to generate QR code.', 'error');
				setRetryButton(modal, function () { startSession(modal); });
				return;
			}
			currentToken = res.data.token;
			qrBox.classList.remove('appress-qr-login-modal__qr--loading');
			qrBox.innerHTML = QR.encode(res.data.qr_payload);
			pollLoop(modal);
		}).catch(function () {
			setStatus(modal, 'Network error.', 'error');
			setRetryButton(modal, function () { startSession(modal); });
		});
	}

	function pollLoop(modal) {
		if (!currentToken) return;
		var token = currentToken;
		ajax('qr_login.poll', { token: token }).then(function (res) {
			if (!currentToken || token !== currentToken) return;
			if (!res || !res.success) {
				setStatus(modal, (res && res.message) || 'Polling failed.', 'error');
				return;
			}
			var status = res.data && res.data.status;
			if (status === 'approved') {
				setStatus(modal, 'Signed in. Reloading…', 'success');
				setTimeout(function () {
					if (res.data.redirect) window.location.href = res.data.redirect;
					else window.location.reload();
				}, 600);
				return;
			}
			if (status === 'denied') {
				setStatus(modal, 'Sign-in cancelled from the app.', 'error');
				setRetryButton(modal, function () { startSession(modal); });
				return;
			}
			if (status === 'expired') {
				setStatus(modal, 'QR code expired.', 'error');
				setRetryButton(modal, function () { startSession(modal); });
				return;
			}
			pollTimer = setTimeout(function () { pollLoop(modal); }, 1500);
		}).catch(function () {
			pollTimer = setTimeout(function () { pollLoop(modal); }, 3000);
		});
	}

	function openModal() {
		if (document.querySelector('.appress-qr-login-modal')) return;
		var modal = buildModal();
		startSession(modal);
	}

	// Stamp `<html>` with `appress-in-app` so the matching CSS rule can
	// reveal `[data-appress-scanner-only]` buttons (the
	// [appress_qr_scanner] component, which is meaningless outside the
	// app — no camera bridge in browsers). One DOM mutation, then the
	// CSS gate handles the rest forever, no per-button handler needed.
	if (isInAppressApp() && document.documentElement
		&& !document.documentElement.classList.contains('appress-in-app')) {
		document.documentElement.classList.add('appress-in-app');
	}

	// Click delegation: every `.appress-login-by-qr-code-trigger` opens
	// the web modal that DISPLAYS a QR — even inside the Appress app.
	// Use case: a logged-in phone displays the QR, a sibling device
	// (tablet, second phone, browser) scans it to sign in. Native code
	// has its own `.appress-qr-scanner-trigger` listener for the scanner
	// button — separate widget, separate class, no double-handling.
	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.appress-login-by-qr-code-trigger');
		if (!btn) return;
		e.preventDefault();
		openModal();
	}, false);
})();
