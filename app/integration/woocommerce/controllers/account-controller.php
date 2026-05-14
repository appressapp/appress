<?php
namespace Appress\Integration\Woocommerce\Controllers;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Biometric sign-in section on the WooCommerce
 * `/my-account/edit-account/` endpoint. The shell HTML is rendered
 * always but stays `display:none` until JS detects `window.Appress`
 * — keeps the markup theme-agnostic + page-cache-safe (Luật Thép:
 * never gate visible markup by server-side User-Agent).
 *
 * When the native app's `biometric.status()` bridge reports the
 * device has a usable sensor, the section reveals itself with
 * Enable/Disable controls that call `biometric.enable()` /
 * `biometric.disable()`. Those bridges talk to
 * `AppressBiometricService` on the native side and to the plugin's
 * own `auth.biometric.issue_token` / `auth.biometric.revoke`
 * endpoints for token lifecycle.
 */
class Account_Controller extends Base_Controller {

	protected function hooks() {
		// `woocommerce_edit_account_form` fires inside the <form>
		// after the password block, before the submit button — the
		// right visual slot for a secondary security control.
		$this->on( 'woocommerce_edit_account_form', '@render_section' );

		// `woocommerce_login_form_start` fires just after the opening
		// <form> of the WC login form on /my-account/. We render the
		// "Use Face ID" button here so logged-out users can biometric-
		// unlock without typing the password.
		$this->on( 'woocommerce_login_form_start', '@render_login_button' );
	}

	protected function render_section() {
		// Inline JS goes through wp_add_inline_script on a virtual handle so
		// the script ships through WP's enqueue pipeline (no raw <script>
		// tag emitted directly from the template).
		wp_register_script( 'appress-wc-biometric-account', false, [], \Appress\get_assets_version(), true );
		wp_enqueue_script( 'appress-wc-biometric-account' );
		wp_add_inline_script( 'appress-wc-biometric-account', $this->biometric_account_js() );
		?>
		<fieldset class="appress-biometric-section" data-appress-biometric style="display:none;margin-top:1.5rem;">
			<legend><?php esc_html_e( 'Biometric sign-in', 'appress' ); ?></legend>
			<p class="form-row form-row-wide appress-biometric-row">
				<span class="appress-biometric-status" data-appress-biometric-status style="display:block;margin-bottom:0.75rem;color:#555;"></span>
				<button type="button" class="button" data-appress-biometric-toggle disabled>
					<?php esc_html_e( 'Enable', 'appress' ); ?>
				</button>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * WC login form "Use Face ID / Touch ID" button. Visible only
	 * when the page is loaded inside the native app AND the device
	 * has a paired biometric token. Button calls `biometric.signIn()`
	 * → native prompts, exchanges token with the server, injects
	 * cookies → JS reloads so WC's logged-in view takes over.
	 */
	protected function render_login_button() {
		wp_register_script( 'appress-wc-biometric-login', false, [], \Appress\get_assets_version(), true );
		wp_enqueue_script( 'appress-wc-biometric-login' );
		wp_add_inline_script( 'appress-wc-biometric-login', $this->biometric_login_js() );
		?>
		<div class="appress-biometric-login" data-appress-biometric-login style="display:none;margin-bottom:1.25rem;">
			<button type="button" class="button button-primary" data-appress-biometric-signin style="width:100%;padding:0.75rem;">
				<?php esc_html_e( 'Use Face ID / Touch ID to sign in', 'appress' ); ?>
			</button>
			<p class="appress-biometric-login-error" data-appress-biometric-login-error style="display:none;color:#a00;font-size:0.875rem;margin:0.5rem 0 0;"></p>
			<div class="appress-biometric-login-divider" style="display:flex;align-items:center;gap:0.75rem;margin-top:1rem;color:#888;font-size:0.8125rem;">
				<span style="flex:1;height:1px;background:#e0e0e0;"></span>
				<span><?php esc_html_e( 'or sign in with password', 'appress' ); ?></span>
				<span style="flex:1;height:1px;background:#e0e0e0;"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * JS body for the account-edit biometric toggle. Heredoc keeps it
	 * literal — no PHP interpolation — so wp_add_inline_script can pass
	 * it straight through without further escaping.
	 */
	private function biometric_account_js(): string {
		return <<<'JS'
(function() {
	if (!window.Appress || !window.Appress.biometric) return;
	var section = document.querySelector('[data-appress-biometric]');
	if (!section) return;
	var statusEl = section.querySelector('[data-appress-biometric-status]');
	var btn = section.querySelector('[data-appress-biometric-toggle]');

	function apply(status) {
		if (!status) { section.style.display = 'none'; return; }
		if (status.availability === 'unavailable') {
			section.style.display = 'none';
			return;
		}
		section.style.display = '';
		if (status.availability === 'not_enrolled') {
			statusEl.textContent = 'Set up Face ID or fingerprint in your device settings first, then come back to enable here.';
			btn.style.display = 'none';
			return;
		}
		btn.style.display = '';
		btn.disabled = false;
		if (status.paired) {
			statusEl.textContent = 'Biometric sign-in is active on this device.';
			btn.textContent = 'Disable';
			btn.setAttribute('data-action', 'disable');
		} else {
			statusEl.textContent = 'Turn on biometric sign-in to skip typing your password next time.';
			btn.textContent = 'Enable';
			btn.setAttribute('data-action', 'enable');
		}
	}

	function refresh() {
		try {
			window.Appress.biometric.status().then(apply, function() { section.style.display = 'none'; });
		} catch (e) { section.style.display = 'none'; }
	}

	btn.addEventListener('click', function(e) {
		e.preventDefault();
		btn.disabled = true;
		var action = btn.getAttribute('data-action');
		var call = action === 'disable' ? window.Appress.biometric.disable() : window.Appress.biometric.enable();
		call.then(refresh, refresh);
	});

	refresh();
})();
JS;
	}

	private function biometric_login_js(): string {
		return <<<'JS'
(function() {
	if (!window.Appress || !window.Appress.biometric) return;
	var wrap = document.querySelector('[data-appress-biometric-login]');
	if (!wrap) return;
	var btn = wrap.querySelector('[data-appress-biometric-signin]');
	var errEl = wrap.querySelector('[data-appress-biometric-login-error]');

	try {
		window.Appress.biometric.status().then(function(s) {
			if (!s || s.availability !== 'available' || !s.paired) return;
			wrap.style.display = '';
		}, function() {});
	} catch (e) {}

	function postPill(type) {
		try {
			if (window.AppressNativeBridge && typeof window.AppressNativeBridge.postMessage === 'function') {
				window.AppressNativeBridge.postMessage(JSON.stringify({ type: type }));
			} else if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.AppressLinkIntercept) {
				window.webkit.messageHandlers.AppressLinkIntercept.postMessage({ type: type });
			}
		} catch (e) {}
	}

	btn.addEventListener('click', function(e) {
		e.preventDefault();
		errEl.style.display = 'none';
		btn.disabled = true;
		postPill('show_pill_spinner');
		window.Appress.biometric.signIn().then(function(r) {
			if (r && r.success) {
				location.reload();
				return;
			}
			postPill('hide_pill_spinner');
			btn.disabled = false;
			var reason = r && r.reason;
			if (reason && reason !== 'cancelled') {
				var msg;
				if (reason === 'no_token')        msg = 'No device paired. Enable Face ID in your account settings first.';
				else if (reason === 'server_rejected') msg = 'Biometric session expired. Please sign in with your password.';
				else if (reason === 'network')    msg = 'Network error. Check your connection and try again.';
				else                              msg = 'Biometric sign-in failed. Try again or use your password.';
				errEl.textContent = msg;
				errEl.style.display = '';
			}
		}, function() {
			postPill('hide_pill_spinner');
			btn.disabled = false;
		});
	});
})();
JS;
	}
}
