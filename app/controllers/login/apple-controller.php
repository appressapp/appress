<?php

namespace Appress\Controllers\Login;

// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.Security.NonceVerification.Missing
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sign in with Apple — Apple Guideline 4.8 mandates SIWA whenever an
 * app offers third-party social login (Google, Facebook, …) as an
 * equivalent option. The bridge JS calls
 * `Capacitor.Plugins.SignInWithApple.authorize()` (provided by
 * `@capacitor-community/apple-sign-in`), Apple's native sheet returns
 * an `identity_token` JWT, the JS POSTs it here, and this handler
 * verifies the token signature against Apple's published JWKs before
 * issuing a WP login cookie.
 *
 * Mirrors {@see Google_Controller} 1:1 — same hook surface, same
 * JS-bridge / button-selector filter pattern, so integrations
 * (Voxel, Bricks, …) only need a one-line override per service.
 */
class Apple_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Mobile-only endpoint — register on each app's `<class_id>_ajax_*`
		// prefix for new builds + the legacy `appress_ajax_*` prefix as
		// backward compat for mobile apps shipped before unique_class.
		$this->on_mobile( 'auth.apple.login', '@handle_login' );

		// `wp_enqueue_scripts` runs before `wp_print_footer_scripts`
		// (priority 20 on `wp_footer`), so the bridge handle gets
		// flushed into the page along with everything else.
		$this->on( 'wp_enqueue_scripts', '@inject_js_bridge' );
	}

	protected function handle_login() {
		try {
			$id_token = sanitize_text_field( wp_unslash( $_POST['id_token'] ?? '' ) );
			if ( empty( $id_token ) ) {
				throw new \Exception( esc_html__( 'Missing identity token.', 'appress' ) );
			}

			// `user` (name) is optional — Apple sends it ONLY on the very
			// first authorization for a given Apple ID. Subsequent logins
			// have null/empty. We pass it through to user_registered so
			// integrations can persist display name on first sign-up.
			$user_payload_raw = wp_unslash( $_POST['user'] ?? '' );
			$user_payload     = $user_payload_raw ? json_decode( $user_payload_raw, true ) : [];
			$first_name       = isset( $user_payload['givenName'] ) ? sanitize_text_field( $user_payload['givenName'] ) : '';
			$last_name        = isset( $user_payload['familyName'] ) ? sanitize_text_field( $user_payload['familyName'] ) : '';

			$claims = $this->verify_apple_id_token( $id_token );

			$email = isset( $claims['email'] ) ? sanitize_email( $claims['email'] ) : '';
			if ( empty( $email ) ) {
				throw new \Exception( esc_html__( 'Apple did not return an email. Enable email sharing in the Apple ID prompt.', 'appress' ) );
			}

			$app_id = \Appress\get_current_app_id();
			if ( ! $app_id ) {
				throw new \Exception( esc_html__( 'Missing App ID in User-Agent. Cannot determine Apple Service ID.', 'appress' ) );
			}

			// Validate `aud` against the bundle id of the customer's
			// app, which we read from `wp_appress_apps.build_information`.
			// Apple sets `aud` to the bundle id for native sign-in
			// (ASAuthorizationAppleIDProvider) and to the configured
			// Service ID for web sign-in. Native is the only path the
			// app uses, so bundle id is the only valid audience.
			global $wpdb;
			// Inline `{$wpdb->prefix}` inside the prepared SQL — prefix
			// is core-set, never user input, safe in interpolation. The
			// previous `$table = $wpdb->prefix . '…'` indirection
			// triggered Plugin Check's `DirectDB.UnescapedDBParameter`
			// false positive (it tracks the variable assignment but
			// can't see the prefix's provenance).
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT build_information FROM {$wpdb->prefix}appress_apps WHERE id = %d LIMIT 1", $app_id ) );
			if ( ! $row ) {
				/* translators: %s: app id sent in the request */
				throw new \Exception( esc_html( sprintf( __( 'App not found. ID sent: %s', 'appress' ), $app_id ) ) );
			}
			$build_info = json_decode( $row->build_information ?? '{}', true ) ?: [];

			$allowed_aud = [];
			if ( ! empty( $build_info['package_id'] ) ) {
				$allowed_aud[] = (string) $build_info['package_id'];
			}
			$allowed_aud = (array) apply_filters( 'appress/auth/apple/allowed_aud', $allowed_aud, $app_id, $build_info );
			$allowed_aud = array_filter( array_unique( $allowed_aud ) );

			if ( empty( $allowed_aud ) ) {
				throw new \Exception( esc_html__( 'Apple bundle id (package_id) not configured for this app.', 'appress' ) );
			}
			if ( ! in_array( $claims['aud'] ?? '', $allowed_aud, true ) ) {
				/* translators: 1: aud claim, 2: comma-separated list of allowed audiences */
				throw new \Exception( esc_html( sprintf( __( 'Invalid request (aud: %1$s, allowed: %2$s)', 'appress' ), (string) ( $claims['aud'] ?? '' ), implode( ', ', $allowed_aud ) ) ) );
			}

			// Look up an existing user by email.
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				wp_clear_auth_cookie();
				wp_set_auth_cookie( $user->ID, true );

				do_action( 'appress/auth/apple/user_logged_in', $user->ID, $email );

				wp_send_json([
					'success'     => true,
					'message'     => 'Login successful',
					'redirect_to' => home_url()
				]);
				exit;
			}

			// Username derived from the email's local part. Apple may hand
			// back a per-app relay address (`xxx@privaterelay.appleid.com`)
			// when the user picked "Hide My Email" — the local part is
			// still a stable identifier for THIS app, so it works fine
			// here. Collisions between two Apple IDs that happen to share
			// a local part get a numeric suffix.
			$base_username = sanitize_user( current( explode( '@', $email ) ), true );
			if ( empty( $base_username ) ) {
				$base_username = 'user';
			}
			$username = $base_username;
			$suffix   = 1;
			while ( username_exists( $username ) ) {
				$username = $base_username . '_' . $suffix;
				$suffix++;
			}
			$role_key = apply_filters( 'appress/auth/apple/default_role', 'subscriber' );

			$args = [
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 16 ),
				'role'       => $role_key,
				'first_name' => $first_name,
				'last_name'  => $last_name,
			];
			// Display name from Apple's first-auth payload. Apple only
			// sends givenName/familyName on the FIRST authorization for
			// an Apple ID + bundle id pair — subsequent sign-ins arrive
			// with both null, so we can only set this once at signup.
			if ( $first_name || $last_name ) {
				$args['display_name'] = trim( $first_name . ' ' . $last_name );
			}

			$user_id = wp_insert_user( $args );
			if ( is_wp_error( $user_id ) ) {
				throw new \Exception( esc_html( $user_id->get_error_message() ) );
			}

			do_action( 'appress/auth/apple/user_registered', $user_id, $email );

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

	/**
	 * Verify Apple's identity_token JWT (RS256) against the public JWKs
	 * Apple publishes at https://appleid.apple.com/auth/keys.
	 *
	 * Returns the decoded payload (claims) on success; throws on any
	 * verification failure. Apple's keys are cached per-process for 1
	 * hour via `wp_cache_*` so repeat sign-ins don't re-fetch the JWKs
	 * every time.
	 *
	 * @return array claims
	 */
	private function verify_apple_id_token( string $jwt ): array {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) !== 3 ) {
			throw new \Exception( esc_html__( 'Malformed identity token.', 'appress' ) );
		}
		[ $headerB64, $payloadB64, $signatureB64 ] = $parts;

		$header  = json_decode( $this->b64url_decode( $headerB64 ), true );
		$payload = json_decode( $this->b64url_decode( $payloadB64 ), true );
		$signature = $this->b64url_decode( $signatureB64 );

		if ( ! is_array( $header ) || ! is_array( $payload ) ) {
			throw new \Exception( esc_html__( 'Cannot decode identity token.', 'appress' ) );
		}
		if ( ( $header['alg'] ?? '' ) !== 'RS256' ) {
			throw new \Exception( esc_html__( 'Unexpected token algorithm.', 'appress' ) );
		}
		if ( empty( $header['kid'] ) ) {
			throw new \Exception( esc_html__( 'Token missing key id (kid).', 'appress' ) );
		}

		// Apple's `iss` is fixed.
		if ( ( $payload['iss'] ?? '' ) !== 'https://appleid.apple.com' ) {
			throw new \Exception( esc_html__( 'Token issuer is not Apple.', 'appress' ) );
		}
		// Reject expired tokens. 60s leeway absorbs minor clock skew.
		$now = time();
		if ( ! empty( $payload['exp'] ) && (int) $payload['exp'] + 60 < $now ) {
			throw new \Exception( esc_html__( 'Identity token has expired.', 'appress' ) );
		}

		$jwk = $this->fetch_apple_jwk( (string) $header['kid'] );
		$pem = $this->jwk_to_pem( $jwk );
		$ok  = openssl_verify( "{$headerB64}.{$payloadB64}", $signature, $pem, OPENSSL_ALGO_SHA256 );
		if ( $ok !== 1 ) {
			throw new \Exception( esc_html__( 'Identity token signature mismatch.', 'appress' ) );
		}

		return $payload;
	}

	/**
	 * Fetch Apple's published JWK matching the given key id. Cached
	 * for 1 hour to avoid hitting Apple's keys endpoint on every
	 * login.
	 *
	 * @return array{kty:string,kid:string,n:string,e:string}
	 */
	private function fetch_apple_jwk( string $kid ): array {
		$cache_key = 'appress_apple_jwk_' . $kid;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( 'https://appleid.apple.com/auth/keys', [ 'timeout' => 5 ] );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			throw new \Exception( esc_html__( 'Cannot fetch Apple public keys.', 'appress' ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['keys'] ) || ! is_array( $body['keys'] ) ) {
			throw new \Exception( esc_html__( 'Apple keys endpoint returned malformed data.', 'appress' ) );
		}

		foreach ( $body['keys'] as $k ) {
			if ( isset( $k['kid'] ) && $k['kid'] === $kid ) {
				set_transient( $cache_key, $k, HOUR_IN_SECONDS );
				return $k;
			}
		}
		throw new \Exception( esc_html__( 'No matching Apple public key for the token.', 'appress' ) );
	}

	/**
	 * Convert an RSA JWK (n, e in base64url) to a PEM string suitable
	 * for `openssl_verify`. Implementation builds the SubjectPublicKeyInfo
	 * DER manually so we don't pull in a JWT/JWK library just for this.
	 */
	private function jwk_to_pem( array $jwk ): string {
		if ( empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
			throw new \Exception( esc_html__( 'JWK is missing modulus or exponent.', 'appress' ) );
		}
		$modulus  = $this->b64url_decode( $jwk['n'] );
		$exponent = $this->b64url_decode( $jwk['e'] );

		// Pad with 0x00 if leading byte has high bit set, so DER reads
		// it as a positive integer.
		$pad = function ( string $bytes ): string {
			return ( ord( $bytes[0] ) & 0x80 ) ? "\x00" . $bytes : $bytes;
		};
		$encodeLen = function ( int $len ): string {
			if ( $len < 0x80 ) return chr( $len );
			$str = '';
			while ( $len > 0 ) {
				$str = chr( $len & 0xFF ) . $str;
				$len >>= 8;
			}
			return chr( 0x80 | strlen( $str ) ) . $str;
		};
		$encodeInteger = function ( string $bytes ) use ( $pad, $encodeLen ): string {
			$bytes = $pad( $bytes );
			return "\x02" . $encodeLen( strlen( $bytes ) ) . $bytes;
		};

		$rsaKey = $encodeInteger( $modulus ) . $encodeInteger( $exponent );
		$rsaKey = "\x30" . $encodeLen( strlen( $rsaKey ) ) . $rsaKey;

		// rsaEncryption OID: 1.2.840.113549.1.1.1
		$rsaOid = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
		$bitstring = "\x03" . $encodeLen( strlen( $rsaKey ) + 1 ) . "\x00" . $rsaKey;
		$spki = $rsaOid . $bitstring;
		$spki = "\x30" . $encodeLen( strlen( $spki ) ) . $spki;

		return "-----BEGIN PUBLIC KEY-----\n" .
			chunk_split( base64_encode( $spki ), 64, "\n" ) .
			"-----END PUBLIC KEY-----\n";
	}

	private function b64url_decode( string $input ): string {
		$remainder = strlen( $input ) % 4;
		if ( $remainder ) {
			$input .= str_repeat( '=', 4 - $remainder );
		}
		return base64_decode( strtr( $input, '-_', '+/' ) );
	}

	protected function inject_js_bridge() {
		// Integrations override the CSS selector that triggers the
		// SIWA flow. Default `.appress-apple-login` matches the
		// vanilla button class (e.g. shortcode emit).
		$selector = apply_filters( 'appress/auth/apple/button_selector', '.appress-apple-login' );
		if ( empty( $selector ) ) {
			return;
		}

		wp_register_script( 'appress-apple-auth-bridge', false, [], \Appress\get_assets_version(), true );
		wp_enqueue_script( 'appress-apple-auth-bridge' );
		wp_localize_script( 'appress-apple-auth-bridge', 'appressAppleAuth', [
			'selector' => (string) $selector,
			'loginUrl' => home_url( '/?appress=1&action=auth.apple.login' ),
		] );
		wp_add_inline_script( 'appress-apple-auth-bridge', $this->apple_auth_bridge_js() );
	}

	/**
	 * Static JS body for the SIWA click bridge. Matches the
	 * `Google_Controller` shape so a single mental model covers both
	 * services.
	 */
	private function apple_auth_bridge_js(): string {
		return <<<'JS'
document.addEventListener("DOMContentLoaded", function () {
	if (!window.appressAppleAuth || !window.appressAppleAuth.selector) return;
	var SELECTOR = window.appressAppleAuth.selector;
	var LOGIN_URL = window.appressAppleAuth.loginUrl;

	document.body.addEventListener('click', async function(e) {
		var link = e.target.closest(SELECTOR);
		if (!link) return;

		e.preventDefault();

		try {
			if (!window.Capacitor) {
				window.location.href = link.href || link.getAttribute('data-href');
				return;
			}

			// Preview-mode gate. The Appress host app sets
			// `window.Appress.isPreviewMode = true` on every slave
			// WebView while a customer's app is mounted via the preview
			// pipeline. Apple Sign-In's clientId comes from the host's
			// bundle id (`com.appress.app`), so the customer's WP server
			// would reject the resulting identity token even if the
			// flow completed. Refuse upfront with the standard alert.
			if (window.Appress && window.Appress.isPreviewMode) {
				alert('Apple Sign-In is not available in Preview mode.');
				return;
			}

			if (!window.Capacitor.Plugins.SignInWithApple && window.Capacitor.registerPlugin) {
				window.Capacitor.Plugins.SignInWithApple = window.Capacitor.registerPlugin('SignInWithApple');
			}

			if (!window.Capacitor.Plugins.SignInWithApple) {
				window.location.href = link.href || link.getAttribute('data-href');
				return;
			}

			// Apple ASAuthorizationAppleIDProvider request — `clientId` is
			// the bundle id (native flow). `scopes` request email + name.
			var result = await window.Capacitor.Plugins.SignInWithApple.authorize({
				clientId: '',          // native: ignored; plugin reads bundle id from the running app
				redirectURI: '',       // native: ignored; only needed for the JS web SDK
				scopes: 'email name',
				state: '',
			});

			var resp = (result && result.response) ? result.response : null;
			if (!resp || !resp.identityToken) {
				return;
			}

			var formData = new FormData();
			formData.append('id_token', resp.identityToken);
			// First-login user payload — only present once per Apple ID.
			if (resp.givenName || resp.familyName) {
				formData.append('user', JSON.stringify({
					givenName: resp.givenName || '',
					familyName: resp.familyName || ''
				}));
			}

			var response = await fetch(LOGIN_URL, { method: 'POST', body: formData, credentials: 'same-origin' });
			var body = await response.json();

			if (body.success) {
				// Server confirmed login. Drop the native overlay NOW
				// (before reload) so the in-flight reload + post-auth
				// screen rebuild is blanketed under it. Auto-hides when
				// the post-login default screen paints.
				if (window.Appress && window.Appress.authOverlay) {
					window.Appress.authOverlay.show();
				}
				window.location.reload();
			} else {
				alert('Login Failed: ' + (body.message || 'Unknown error'));
			}
		} catch (error) {
			// User canceled the SIWA sheet — silent. iOS rejects with
			// `error.code === 1001` (ASAuthorizationErrorCanceled);
			// older Capacitor builds bubble a string with `cancel` in
			// the message.
			var errorMsg = (typeof error === 'object') ? (error.message || JSON.stringify(error)) : String(error);
			var lower    = String(errorMsg).toLowerCase();
			var code     = (typeof error === 'object' && error) ? (error.code !== undefined ? error.code : '') : '';
			if (lower.indexOf('cancel') !== -1 ||
				code === 1001 || code === '1001' ||
				code === '1000') {
				return;
			}
			alert('Apple Authentication Failed:\n' + errorMsg);
		}
	});
});
JS;
	}
}
