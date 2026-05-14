<?php

namespace Appress\Controllers\Login;

// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.Security.NonceVerification.Missing
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
if ( ! defined('ABSPATH') ) {
	exit;
}

class Google_Controller extends \Appress\Controllers\Base_Controller {
	// protected function authorize() {
	// 	return (bool) \Appress\get( 'modules.google_login', false );
	// }

	protected function hooks() {
		// Use Appress's custom fast-router endpoint instead of admin-ajax.php.
		$this->on( 'appress_ajax_auth.google.login', '@handle_login' );
		$this->on( 'appress_ajax_nopriv_auth.google.login', '@handle_login' );

		// `wp_enqueue_scripts` runs before `wp_print_footer_scripts` (priority
		// 20 on `wp_footer`), so the bridge handle gets flushed into the
		// page along with everything else. Hooking on `wp_footer` priority
		// 100 silently drops the script because the print pass already
		// happened — the click handler never binds and the button looks
		// dead.
		$this->on( 'wp_enqueue_scripts', '@inject_js_bridge' );
	}

	protected function handle_login() {
		try {
			$id_token = sanitize_text_field( wp_unslash( $_POST['id_token'] ?? '' ) );
			if ( empty( $id_token ) ) {
				throw new \Exception( esc_html__( 'Missing ID Token.', 'appress' ) );
			}

			// Validate token directly against Google's tokeninfo endpoint — no external JWT library needed.
			$response = wp_remote_get( 'https://oauth2.googleapis.com/tokeninfo?id_token=' . $id_token );
			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
				throw new \Exception( esc_html__( 'Invalid or expired Google ID Token.', 'appress' ) );
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $data['email'] ) || empty( $data['aud'] ) ) {
				throw new \Exception( esc_html__( 'Malformed token data.', 'appress' ) );
			} 

			$app_id = \Appress\get_current_app_id();
			
			if ( ! $app_id ) {
				throw new \Exception( esc_html__( 'Missing App ID in User-Agent. Cannot determine Google Client ID.', 'appress' ) );
			}

			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT build_information FROM $table WHERE id = %d LIMIT 1", $app_id ) );

			if ( ! $row ) {
				/* translators: %s: app id sent in the request */
				throw new \Exception( esc_html( sprintf( __( 'App not found. ID sent: %s', 'appress' ), $app_id ) ) );
			}
 
			$build_info = json_decode( $row->build_information ?? '{}', true ) ?: [];
			$allowed_client_ids = [];

			// Extract OAuth client IDs from the Android google-services.json.
			$android_json = ! empty( $build_info['firebase_android'] ) ? $build_info['firebase_android'] : '';
			if ( $android_json ) {
				$json_data = json_decode( $android_json, true );
				if ( ! empty( $json_data['client'][0]['oauth_client'] ) ) {
					foreach ( $json_data['client'][0]['oauth_client'] as $oauth_client ) {
						if ( ! empty( $oauth_client['client_id'] ) ) {
							$allowed_client_ids[] = $oauth_client['client_id'];
						}
					}
				}
			}

			// Extract CLIENT_ID from the iOS GoogleService-Info.plist.
			$ios_plist = ! empty( $build_info['firebase_ios'] ) ? $build_info['firebase_ios'] : '';
			if ( $ios_plist ) {
				if ( preg_match( '/<key>CLIENT_ID<\/key>\s*<string>([^<]+)<\/string>/', $ios_plist, $matches ) ) {
					$allowed_client_ids[] = $matches[1];
				}
			}

			if ( empty( $allowed_client_ids ) ) {
				throw new \Exception( esc_html__( 'Google Client IDs not found. Please upload the Firebase Android JSON and iOS plist files in App Builder.', 'appress' ) );
			}

			$allowed_client_ids = array_unique( $allowed_client_ids );

			// Only accept an exact match against one of the native client IDs (Android or iOS).
			$is_valid = in_array( $data['aud'], $allowed_client_ids, true );

			if ( ! $is_valid ) {
				/* translators: 1: client id aud value, 2: comma-separated list of allowed client ids */
				throw new \Exception( esc_html( sprintf( __( 'Invalid request (aud: %1$s, allowed: %2$s)', 'appress' ), $data['aud'], implode( ', ', $allowed_client_ids ) ) ) );
			}

			$email = sanitize_email( $data['email'] );

			// Look up an existing user by email.
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				wp_clear_auth_cookie();
				wp_set_auth_cookie( $user->ID, true );
				
				do_action( 'appress/auth/google/user_logged_in', $user->ID, $email );

				wp_send_json([
					'success'     => true,
					'message'     => 'Login successful',
					'redirect_to' => home_url()
				]);
				exit;
			}

			// Register a new user.
			$username = sanitize_user( current( explode( '@', $email ) ) . '_' . wp_generate_password( 4, false ) );
			$role_key = apply_filters( 'appress/auth/google/default_role', 'subscriber' );

			$args = [
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 16 ),
				'role'       => $role_key,
			];

			$user_id = wp_insert_user( $args );
			if ( is_wp_error( $user_id ) ) {
				throw new \Exception( esc_html( $user_id->get_error_message() ) );
			}

			do_action( 'appress/auth/google/user_registered', $user_id, $email );

			wp_clear_auth_cookie();
			wp_set_auth_cookie( $user_id, true );

			wp_send_json([
				'success'     => true,
				'message'     => 'Registration successful',
				'redirect_to' => home_url()
			]);
			exit;

		} catch ( \Exception $e ) {
			wp_send_json([
				'success' => false,
				'message' => $e->getMessage()
			]);
			exit;
		}
	}

	protected function inject_js_bridge() {

		// Integrations can override the CSS selector that triggers the Google login flow.
		$selector = apply_filters( 'appress/auth/google/button_selector', '.appress-google-login' );
		if ( empty( $selector ) ) {
			return;
		}

		// Per-request config for the static JS body — selector + login
		// endpoint URL. wp_localize_script emits a JSON object that the
		// inline JS reads from `window.appressGoogleAuth`.
		wp_register_script( 'appress-google-auth-bridge', false, [], \Appress\get_assets_version(), true );
		wp_enqueue_script( 'appress-google-auth-bridge' );
		wp_localize_script( 'appress-google-auth-bridge', 'appressGoogleAuth', [
			'selector'  => (string) $selector,
			'loginUrl'  => home_url( '/?appress=1&action=auth.google.login' ),
		] );
		wp_add_inline_script( 'appress-google-auth-bridge', $this->google_auth_bridge_js() );
	}

	/**
	 * Static JS body for the Google sign-in click bridge. All per-request
	 * values (selector, login URL) come from `window.appressGoogleAuth`
	 * which is populated via wp_localize_script — keeps the JS string
	 * literal so heredoc handling stays simple and the wp.org reviewer
	 * sees no PHP interpolation inside a <script> tag.
	 */
	private function google_auth_bridge_js(): string {
		return <<<'JS'
document.addEventListener("DOMContentLoaded", function () {
	if (!window.appressGoogleAuth || !window.appressGoogleAuth.selector) return;
	var SELECTOR = window.appressGoogleAuth.selector;
	var LOGIN_URL = window.appressGoogleAuth.loginUrl;

	document.body.addEventListener('click', async function(e) {
		var link = e.target.closest(SELECTOR);
		if (!link) return;

		e.preventDefault();

		try {
			if (!window.Capacitor) {
				window.location.href = link.href || link.getAttribute('data-href');
				return;
			}

			// Preview-mode gate — match the Apple Sign-In bridge pattern.
			// Native side already alerts "Preview only" when it sees
			// signIn() under preview-mode, but bailing here avoids the
			// round-trip + the second "Google Authentication Failed:
			// preview_mode" generic alert that catches the rejection.
			if (window.Appress && window.Appress.isPreviewMode) {
				alert('Google Sign-In is not available in Preview mode.');
				return;
			}

			if (!window.Capacitor.Plugins.GoogleAuth && window.Capacitor.registerPlugin) {
				window.Capacitor.Plugins.GoogleAuth = window.Capacitor.registerPlugin('GoogleAuth');
			}

			if (!window.Capacitor.Plugins.GoogleAuth) {
				console.warn("Capacitor GoogleAuth Plugin not found! Falling back to web browser.");
				window.location.href = link.href || link.getAttribute('data-href');
				return;
			}

			if (typeof window.Capacitor.Plugins.GoogleAuth.initialize === 'function') {
				await window.Capacitor.Plugins.GoogleAuth.initialize();
			}

			// Force-clear any cached GMS account before sign-in so Android
			// always shows the account chooser instead of silently
			// auto-resolving with the previously-used Google account.
			// GMS native chooser persists last-used account at the system
			// level — `getSignInIntent()` returns immediately with that
			// account when only one exists on device. iOS uses a web flow
			// that always presents the picker, so `signOut()` is a no-op
			// for UX there (just clears in-memory `currentUser`). Wrapped
			// in try/catch because signOut() rejects when nothing is
			// cached, which is a perfectly normal first-login state.
			try { await window.Capacitor.Plugins.GoogleAuth.signOut(); } catch (e) {}

			var googleUser = await window.Capacitor.Plugins.GoogleAuth.signIn();

			if (!googleUser || !googleUser.authentication || !googleUser.authentication.idToken) {
				alert('Cannot fetch ID Token from Google.');
				return;
			}

			var formData = new FormData();
			formData.append('id_token', googleUser.authentication.idToken);

			var response = await fetch(LOGIN_URL, { method: 'POST', body: formData });
			var result = await response.json();

			if (result.success) {
				// Server confirmed login. Drop the native overlay NOW
				// (before reload) so the in-flight reload + post-auth
				// screen rebuild is blanketed under it. Auto-hides when
				// the post-login default screen paints.
				if (window.Appress && window.Appress.authOverlay) {
					window.Appress.authOverlay.show();
				}
				window.location.reload();
			} else {
				alert('Login Failed: ' + (result.message ? result.message : 'Unknown error'));
			}
		} catch (error) {
			// User-canceled flow: native Google sheet was dismissed by the
			// user. iOS rejects with code -5 / "the user canceled the
			// sign-in flow"; Android rejects with code 12501 or 16.
			// Silently swallow — no point showing a "failed" alert when
			// the user intentionally backed out.
			var errorMsg = (typeof error === 'object') ? (error.message || JSON.stringify(error)) : String(error);
			var lower    = String(errorMsg).toLowerCase();
			var code     = (typeof error === 'object' && error) ? (error.code !== undefined ? error.code : '') : '';
			if (lower.indexOf('cancel') !== -1 ||
				lower.indexOf('12501') !== -1 ||
				code === 16 || code === '16' ||
				code === -5 || code === '-5') {
				return;
			}
			// Preview-mode short-circuit: native side already showed
			// the "Preview only" alert before rejecting with this
			// reason. Don't pile a second generic "Google Authentication
			// Failed: preview_mode" popup on top.
			if (lower.indexOf('preview_mode') !== -1 || lower.indexOf('preview mode') !== -1) {
				return;
			}
			console.error("Google Auth Error:", error);
			alert("Google Authentication Failed:\n" + errorMsg);
		}
	});
});
JS;
	}
}
