<?php

namespace Appress\Controllers\App;

// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
// phpcs:disable WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
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
use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Apps_Controller extends Base_Controller {

	protected function hooks() {
		$this->on( 'appress_ajax_app.list', '@list_apps' );
		$this->on( 'appress_ajax_app.get', '@get_config' );
		$this->on( 'appress_ajax_app.save', '@save_config' );
		$this->on( 'appress_ajax_app.update_token', '@update_token' );
		$this->on( 'appress_ajax_app.delete', '@delete_app' );
		$this->on( 'appress_ajax_app.pull', '@pull_config' );
		$this->on( 'appress_ajax_app.lookup_apple_store_id', '@lookup_apple_store_id' );

		$this->on( 'appress_ajax_app.connect', '@handle_onboard' ); // Now acts as Connect New App
		$this->on( 'appress_ajax_app.refresh_essentials', '@refresh_essentials' );
		$this->on( 'appress_ajax_app.request_build', '@request_build' );
		$this->on( 'appress_ajax_app.get_builds', '@get_builds' );
		$this->on( 'appress_ajax_app.get_build_config', '@get_build_config' );
		$this->on( 'appress_ajax_app.download_build', '@download_build' );
		$this->on( 'appress_ajax_app.get_plan', '@get_plan' );
		$this->on( 'appress_ajax_app.testflight_submit', '@submit_testflight' );
		$this->on( 'appress_ajax_app.playstore_publish', '@publish_playstore' );
		$this->on( 'appress_ajax_app.appstore_publish', '@publish_appstore' );
	} 

	protected function check_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( esc_html__( 'Unauthorized access.', 'appress' ) );
		}
		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'appress_admin_action' ) ) {
			throw new \Exception( esc_html__( 'Security token invalid or expired.', 'appress' ) );
		}
	}

	protected function delete_app() {
		try {
			$this->check_permissions();
			// Form-urlencoded body posted by Vue admin (`postForm` helper).
			// Mirrors Voxel's `jQuery.post` shape so strict hosting WAFs
			// (LiteSpeed / cPanel ModSecurity rule 920420) accept the
			// request — raw `application/json` POST to a non-REST endpoint
			// silently 406s on those hosts.
			$params = wp_unslash( $_POST );
			$app_id   = intval( $params['app_id'] ?? 0 );

			if ( ! $app_id ) {
				throw new \Exception( esc_html__( 'App ID is required.', 'appress' ) );
			}

			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			$wpdb->delete( $table, [ 'id' => $app_id ] );

			// Drop the unique_class lookup cache so the AJAX router stops
			// accepting `?<deleted_class>=1` immediately on the next request
			// (otherwise the stale entry lingers until process restart).
			\Appress\clear_apps_class_cache();

			return wp_send_json( [
				'success' => true,
				'message' => 'App deleted successfully.'
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	private function parse_with_schema( $db_row ) {
		$schema = \Appress\config('schema');

		$hydrated = [];

		// Read base DB columns
		foreach ( $db_row as $key => $value ) {
			if ( ! array_key_exists( $key, $schema ) ) {
				$hydrated[$key] = $value;
			}
		}

		// Hydrate dynamically from JSON Schema keys
		foreach ( $schema as $category => $category_config ) {
			if ( empty( $category_config['fields'] ) ) continue;

			// `credentials` column is encrypted at rest (save_config wraps it
			// with \Appress\encrypt). Decrypt before json_decode so the UI
			// receives plaintext values.
			$raw_column = $db_row[$category] ?? '';
			if ( $category === 'credentials' && $raw_column !== '' ) {
				$raw_column = \Appress\decrypt( (string) $raw_column );
			}
			$saved_data = ! empty( $raw_column ) ? json_decode( $raw_column, true ) : [];
			if ( ! is_array( $saved_data ) ) $saved_data = [];

			foreach ( $category_config['fields'] as $key => $field_config ) {
				$hydrated[$key] = $saved_data[$key] ?? $field_config['default'] ?? null;
			}
		}

		// Ensure native DB columns are populated and override any empty defaults from the schema loop
		if ( isset( $db_row['id'] ) ) {
			$hydrated['app_id'] = $db_row['id'];
			$hydrated['id'] = $db_row['id'];
		}
		if ( isset( $db_row['connection_token'] ) ) {
			// Decrypt at-rest envelope before exposing to the rest of the app.
			$hydrated['connection_token'] = \Appress\decrypt( (string) $db_row['connection_token'] );
		}
		if ( isset( $db_row['central_app_id'] ) ) {
			$hydrated['central_app_id'] = $db_row['central_app_id'];
		}
		// Central-derived per-app identifier — null until first app.connect /
		// refresh_essentials populates it. Vue read-only displays this for
		// debugging / support visibility.
		if ( isset( $db_row['unique_class'] ) ) {
			$hydrated['unique_class'] = $db_row['unique_class'];
		}

		return $hydrated;
	}

	protected function list_apps() {
		try {
			$this->check_permissions();
			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';

			// Return the leanest payload possible: id, name, and package_id (nested in build_info).
			$results = $wpdb->get_results( "SELECT id, app_name, build_config FROM $table ORDER BY id DESC", ARRAY_A );

			$apps = [];
			foreach ( (array) $results as $row ) {
				$hydrated = [
					'id'       => $row['id'], // Pass id through for Vue's selectApp call
					'app_name' => $row['app_name'],
				];
				
				$build_info = !empty( $row['build_config'] ) ? json_decode( $row['build_config'], true ) : [];
				if ( is_array( $build_info ) && !empty( $build_info['package_id'] ) ) {
					$hydrated['package_id'] = $build_info['package_id'];
				}
				
				$apps[] = $hydrated;
			}
			
			return wp_send_json( [
				'success' => true,
				'data' => $apps
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	protected function get_config() {
		try {
			$this->check_permissions();
			$app_id = intval( $_GET['app_id'] ?? 0 );
			
			if ( ! $app_id ) {
				throw new \Exception( esc_html__( 'App ID is required.', 'appress' ) );
			}

			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $app_id ), ARRAY_A );

			if ( ! $row ) {
				throw new \Exception( esc_html__( 'App not found.', 'appress' ) );
			}

			$data = $this->parse_with_schema( $row );

			return wp_send_json( [
				'success' => true,
				'data' => $data
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * SSRF guard — return true only when $url is safe to fetch server-side.
	 * Admin-supplied URLs could otherwise point at cloud metadata
	 * (169.254.169.254), loopback (127.0.0.0/8, ::1), private subnets
	 * (10/8, 172.16-31/12, 192.168/16, fc00::/7), or link-local (169.254/16).
	 * Rejects any host that resolves to a non-public IP.
	 */
	private function is_safe_external_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) return false;
		$scheme = strtolower( $parts['scheme'] ?? '' );
		$host   = $parts['host'] ?? '';
		if ( $scheme !== 'http' && $scheme !== 'https' ) return false;
		if ( $host === '' ) return false;

		// Resolve host → IP. Reject if host literally IS a private address
		// OR resolves to one. gethostbynamel returns all A records; reject
		// if any of them lands in a private range (DNS rebinding defense).
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? [ $host ] : ( gethostbynamel( $host ) ?: [] );
		if ( empty( $ips ) ) return false;
		foreach ( $ips as $ip ) {
			$public = filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
			if ( $public === false ) return false;
		}
		return true;
	}

	/**
	 * Validate that the given image URL points to a square image at least
	 * $min_size px wide. Prefers the attachment metadata path (fast, no HTTP)
	 * and falls back to getimagesize() for URLs outside the Media Library.
	 * Throws with a user-facing message on mismatch so the save flow can
	 * surface the error back to the Vue UI.
	 */
	private function assert_square_image( $url, $min_size, $field_key ) {
		$width = 0; $height = 0;

		// Fast path: if URL belongs to this site's Media Library we can read
		// the stored width/height from postmeta without a network round trip.
		$attachment_id = attachment_url_to_postid( $url );
		if ( $attachment_id > 0 ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $meta ) ) {
				$width  = (int) ( $meta['width']  ?? 0 );
				$height = (int) ( $meta['height'] ?? 0 );
			}
		}

		// Fallback: external URL or missing meta → fetch headers + decode.
		// SSRF guard: getimagesize() on user-supplied URL can reach into
		// cloud metadata services (169.254.169.254), localhost ports, or
		// private subnets. Refuse anything that doesn't resolve to a
		// public IP before we fetch.
		if ( ( $width === 0 || $height === 0 ) && $this->is_safe_external_url( $url ) ) {
			set_error_handler( static function () { /* swallow */ } );
			$info = getimagesize( $url );
			restore_error_handler();
			if ( is_array( $info ) ) {
				$width  = (int) $info[0];
				$height = (int) $info[1];
			}
		}

		if ( $width === 0 || $height === 0 ) {
   /* translators: dynamic value injected into the message */
			throw new \Exception( esc_html( sprintf( __( 'Could not read image dimensions for `%s`. Try re-uploading.', 'appress' ), $field_key ) ) );
		}
		if ( $width !== $height ) {
			/* translators: 1: field key, 2: actual width, 3: actual height */
			throw new \Exception( esc_html( sprintf( __( '`%1$s` must be a square image (1:1 ratio). Got %2$d×%3$d. Crop to square and save again.', 'appress' ), $field_key, $width, $height ) ) );
		}
		if ( $min_size > 0 && $width < $min_size ) {
   /* translators: 1: value, 2: value, 3: value, 4: value, 5: value */
			throw new \Exception( esc_html( sprintf( __( '`%1$s` is too small. Minimum %2$d×%2$d, got %3$d×%4$d.', 'appress' ), $field_key, $min_size, $width, $height ) ) );
		}
	}

	/**
	 * Filter an associative array down to entries with a "real" value.
	 * Used by handle_onboard's reconnect-merge to decide which local
	 * fields should override the Central backup. The merge intent is:
	 * "local wins where local has data; backup fills holes." A null /
	 * empty-string / empty-array entry is treated as a hole, NOT as
	 * an explicit user-set value. `0`, `'0'`, and `false` ARE preserved
	 * (they're meaningful — e.g. a numeric 0, a "no" toggle).
	 */
	private function non_empty( array $arr ) {
		$out = [];
		foreach ( $arr as $k => $v ) {
			if ( $v === null ) continue;
			if ( is_string( $v ) && $v === '' ) continue;
			if ( is_array( $v ) && empty( $v ) ) continue;
			$out[ $k ] = $v;
		}
		return $out;
	}

	/**
	 * Recursively sanitize a free-form assoc-array of string leaves —
	 * the workhorse behind the `dict` sanitize type. Keys collapse to
	 * a safe-character allowlist (letters, digits, underscore, dash,
	 * dot) so they round-trip back into JSON / JS object keys without
	 * needing per-call escaping. Scalar leaves pass through
	 * `sanitize_text_field` (strips control chars + tags); nested
	 * arrays recurse. Anything else (objects, resources) is dropped.
	 */
	private function sanitize_dict_recursive( array $value ): array {
		$out = [];
		foreach ( $value as $k => $v ) {
			$safe_key = preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $k );
			if ( $safe_key === '' ) continue;
			if ( is_array( $v ) ) {
				$out[ $safe_key ] = $this->sanitize_dict_recursive( $v );
			} elseif ( is_scalar( $v ) ) {
				$out[ $safe_key ] = sanitize_text_field( (string) $v );
			}
		}
		return $out;
	}

	private function sanitize_field( $value, $field_config, $field_key = 'unknown' ) {
		$valid_types = [ 'text', 'url', 'number', 'boolean', 'color', 'file', 'image', 'object', 'repeater', 'textarea', 'file_drag_drop', 'select', 'icon_picker', 'dict' ];

		if ( empty( $field_config['type'] ) || ! in_array( $field_config['type'], $valid_types, true ) ) {
			throw new \Exception( esc_html__( 'Invalid', 'appress' ) );
		}

		// `object` + `repeater` enumerate child fields explicitly so the
		// sanitizer can recurse into a known shape; `dict` is the escape
		// hatch for runtime-keyed maps (e.g. translation matrices keyed
		// by dynamic ids) where pre-declaring `fields` is impossible.
		if ( in_array( $field_config['type'], [ 'object', 'repeater' ] ) && empty( $field_config['fields'] ) ) {
			throw new \Exception( esc_html__( 'Invalid', 'appress' ) );
		}

		if ( empty( $field_config['sanitize'] ) ) {
			throw new \Exception( esc_html__( 'Invalid', 'appress' ) );
		}

		if ( $value === null ) return '';

		$sanitize_rule = $field_config['sanitize'];

		switch ( $sanitize_rule ) {
			case 'raw':
				return $value;
			case 'css':
				// CSS sanitizer for the per-app custom-CSS textareas. Strips
				// the patterns commonly used for XSS / external resource
				// injection while leaving legitimate styling intact:
				//   - <, > (script/HTML tag injection through CSS contexts)
				//   - `expression(...)` (legacy IE JavaScript evaluation)
				//   - `javascript:` / `vbscript:` URI schemes
				//   - `behavior:` (legacy IE script binding)
				//   - `@import` (cross-origin CSS pull)
				//   - `url(http*)` and `url('http*')` (cross-origin asset
				//     pull; same-origin / data: URLs are still allowed by
				//     not matching the `http` prefix)
				// `$value` is already once-unslashed by `save_config`'s entry
				// `wp_unslash($_POST)` — DO NOT re-apply, otherwise CSS
				// escape sequences like `content: "\A"` (newline) lose their
				// backslash and silently mangle.
				$css = is_string( $value ) ? (string) $value : '';
				$css = preg_replace( '/[<>]/', '', $css );
				$css = preg_replace( '/expression\s*\(/i', '', $css );
				$css = preg_replace( '/(?:javascript|vbscript)\s*:/i', '', $css );
				$css = preg_replace( '/behavior\s*:/i', '', $css );
				$css = preg_replace( '/@import[^;]*;?/i', '', $css );
				$css = preg_replace( '/url\s*\(\s*[\'"]?\s*https?:\/\/[^\)]+\)/i', '', $css );
				return (string) $css;
			case 'url':
				// `$value` already once-unslashed at entry — no second wp_unslash
				// here (would strip backslashes from URL-encoded contexts).
				$sanitized_url = esc_url_raw( (string) $value );
				// Enforce `ui.constraint: 'square'` for image URLs. Belt-and-
				// suspenders with Vue's client check — user could bypass JS
				// by pasting a URL directly, cached form, or direct POST.
				// Validates image dimensions server-side at save time.
				if ( $sanitized_url !== '' && ( $field_config['ui']['constraint'] ?? '' ) === 'square' ) {
					$this->assert_square_image(
						$sanitized_url,
						(int) ( $field_config['ui']['min_size'] ?? 0 ),
						$field_key
					);
				}
				return $sanitized_url;
			case 'boolean':
				return (bool) $value;
			case 'number':
				return is_numeric( $value ) ? (float) $value : 0;
			case 'object':
				if ( ! is_array( $value ) ) return [];

				$sanitized_object = [];
				foreach ( $field_config['fields'] as $sub_key => $sub_config ) {
					$raw_val = $value[$sub_key] ?? $sub_config['default'] ?? null;
					$sanitized_object[$sub_key] = $this->sanitize_field( $raw_val, $sub_config, $sub_key );
				}
				return $sanitized_object;

			case 'repeater':
				if ( ! is_array( $value ) ) return [];

				$sanitized_list = [];
				foreach ( $value as $item ) {
					if ( ! is_array( $item ) ) continue;

					$sanitized_item = [];
					foreach ( $field_config['fields'] as $sub_key => $sub_config ) {
						$raw_val = $item[$sub_key] ?? $sub_config['default'] ?? null;
						$sanitized_item[$sub_key] = $this->sanitize_field( $raw_val, $sub_config, $sub_key );
					}
					$sanitized_list[] = $sanitized_item;
				}
				return $sanitized_list;

			case 'dict':
				// Free-form assoc-array of arbitrarily-nested string
				// leaves. Used for runtime-keyed maps the admin builds
				// up at edit time (e.g. TranslatePress's
				// `translatepress.strings[tab_id][lang_code] = title`)
				// where `fields` can't be pre-declared because the keys
				// depend on user-created data (tab ids, language codes
				// added in the TRP plugin, etc.).
				//
				// Sanitization rules:
				//   - Non-array input → `[]` (drop garbage).
				//   - Keys: cast to string + `sanitize_key`-ish allowlist
				//     so they're safe to use as array keys + map ids.
				//   - Values: recurse into nested arrays; cast scalar
				//     leaves through `sanitize_text_field`.
				if ( ! is_array( $value ) ) return [];
				return $this->sanitize_dict_recursive( $value );

			case 'text':
				// `$value` arrives already-unslashed because `save_config`
				// runs `wp_unslash( $_POST )` once at the entry point.
				// Re-applying `wp_unslash` here strips a SECOND level —
				// `\n` becomes `n` — corrupting any field that legitimately
				// contains backslash-escape sequences (PEM / JSON content
				// pasted in via Vue). One unslash, never two.
				if ( is_array( $value ) ) {
					return map_deep( $value, function( $v ) {
						return is_bool( $v ) ? $v : sanitize_text_field( $v );
					} );
				}
				return sanitize_text_field( (string) $value );

			case 'textarea':
				// Multi-line free text (path lists, CSS selectors, hints).
				// `sanitize_textarea_field` preserves newlines but strips
				// tags + invalid UTF-8, exactly the shape we want for
				// admin-edited textareas exposed to the boot payload.
				return sanitize_textarea_field( (string) $value );

			case 'file_content':
				// Admin-only binary / PEM / JSON / plist file bodies.
				// `sanitize_text_field` cannot apply here — it strips
				// the newlines that PEM blocks and JSON pretty-print
				// rely on, and would corrupt binary keystores entirely.
				// The fields are gated by `manage_options` at the AJAX
				// layer, so the input is already trusted; this case
				// performs minimal safety hardening (null-byte strip
				// + length cap) and leaves the structural validation
				// to the per-format upload handler.
				if ( ! is_string( $value ) ) return '';
				$clean = str_replace( "\0", '', $value );
				if ( strlen( $clean ) > 5 * 1024 * 1024 ) {
					throw new \Exception( esc_html__( 'File too large.', 'appress' ) );
				}
				return $clean;

			default:
				throw new \Exception( esc_html__( 'Invalid field configuration', 'appress' ) );
		}
	}

	protected function save_config() {
		try {
			$this->check_permissions();

			// Form-urlencoded body posted by Vue admin (`postForm` helper).
			// Mirrors Voxel's `jQuery.post` shape so strict hosting WAFs
			// (LiteSpeed / cPanel ModSecurity rule 920420) accept the
			// request — raw `application/json` POST to a non-REST endpoint
			// silently 406s on those hosts.
			//
			// CONTRACT: this `wp_unslash` runs ONCE here at the entry.
			// `$params` (and every value passed downstream into
			// `sanitize_field`) is therefore already once-unslashed —
			// the per-type cases inside `sanitize_field` MUST NOT
			// re-apply `wp_unslash`, otherwise legitimate `\n` escape
			// sequences inside file bodies (Firebase service-account
			// PEM private keys, JSON, plist) get stripped down to
			// literal `n` letters and silently corrupt the saved data.
			// Symptoms surface much later as `OpenSSL unable to validate
			// key` errors at FCM dispatch time. See the saigonstays.com
			// 2026-05-06 incident for the full bug history.
			$params = wp_unslash( $_POST );

			$app_id = intval( $params['app_id'] ?? 0 );
			if ( ! $app_id ) {
				throw new \Exception( esc_html__( 'App ID is required.', 'appress' ) );
			}

			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $app_id ), ARRAY_A );

			if ( ! $row ) {
				throw new \Exception( esc_html__( 'App not found.', 'appress' ) );
			}

			$schema = \Appress\config('schema');

			$update_payload = [];

			foreach ( $schema as $category => $category_config ) {
				if ( empty( $category_config['fields'] ) ) continue;

				$sanitized_category = [];
				foreach ( $category_config['fields'] as $key => $field_config ) {
					$raw_value = $params[$key] ?? $field_config['default'] ?? null;
					$sanitized_category[$key] = $this->sanitize_field( $raw_value, $field_config, $key );
				}

				if ( $category === 'build_config' ) {
					// `url` (Website URL) is admin-hidden and auto-detected
					// — overwrite whatever the client sent with the canonical
					// `home_url()` so the build engine + native code never
					// receive a stale or hand-edited value. Single source of
					// truth = the WP install this admin runs on.
					$sanitized_category['url'] = home_url();
					// Bump time hash so the host preview app (my.appress.app)
					// can short-circuit on cold-start when nothing changed.
					$sanitized_category['update_time_hash'] = (string) time();
				}

				$update_payload[$category] = wp_json_encode( $sanitized_category );
			}

			// Determine app name from title
			$update_payload['app_name'] = !empty( $params['title'] ) ? sanitize_text_field( $params['title'] ) : 'Untitled App';

			// Persist connection_token if the client sent a new one (e.g. user pasted a
			// fresh token in the "Invalid connection token" recovery gate). The column
			// lives outside the schema-driven loop above, so without this branch the
			// token in DB would never update — auth would re-fail on next page reload.
			// Only update on non-empty payload to avoid wiping a valid token by accident.
			if ( isset( $params['connection_token'] ) && $params['connection_token'] !== '' ) {
				$new_token = sanitize_text_field( $params['connection_token'] );
				$current   = isset( $row['connection_token'] ) ? \Appress\decrypt( (string) $row['connection_token'] ) : '';
				if ( $new_token !== $current ) {
					$update_payload['connection_token']        = \Appress\encrypt( $new_token );
					$update_payload['connection_token_lookup'] = \Appress\lookup_hash( $new_token );
				}
			}

			// Ensure a signing_secret exists for this app — generated lazily on first save,
			// kept forever (rebuild reuses the same secret so installed apps keep working).
			$signing_secret = isset( $row['signing_secret'] ) ? \Appress\decrypt( (string) $row['signing_secret'] ) : '';
			if ( $signing_secret === '' ) {
				$signing_secret = bin2hex( random_bytes( 32 ) );
			}
			// Always (re-)write encrypted form so legacy plaintext rows get upgraded on next save.
			$update_payload['signing_secret'] = \Appress\encrypt( $signing_secret );

			// Encrypt FCM service-account JSON at-rest. credentials column was being written
			// via the schema loop above as plaintext JSON — wrap before insert.
			if ( isset( $update_payload['credentials'] ) && is_string( $update_payload['credentials'] ) && $update_payload['credentials'] !== '' ) {
				$update_payload['credentials'] = \Appress\encrypt( $update_payload['credentials'] );
			}

			$wpdb->update( $table, $update_payload, [ 'id' => $app_id ] );

			// Invalidate the cached home-screen path so the next
			// `Frontend_Controller::print_app_settings` request reads
			// the fresh value off DB (admin may have changed Home
			// Screen → URL during this save).
			\Appress\Controllers\App\Frontend_Controller::clear_home_path_cache( $app_id );

			// Push backup to Central SaaS (fire-and-forget, non-blocking).
			// Token in DB is encrypted at-rest → decrypt before sending plaintext over HTTPS.
			$backup_build   = json_decode( $update_payload['build_config'] ?? '{}', true ) ?: [];
			// Strip user CSS — printed live at request time via the
			// `wp_head` hook now. See `request_build` for the full
			// rationale.
			unset( $backup_build['css_all'], $backup_build['css_ios'], $backup_build['css_android'] );
			unset( $backup_build['disable_web_ads'], $backup_build['disable_web_ads_platforms'], $backup_build['disable_web_ads_custom_hosts'] );
			unset( $backup_build['google_analytics_id'], $backup_build['exclude_all_web_ga'], $backup_build['exclude_web_ga_ids'] );
			$plain_token    = \Appress\decrypt( (string) $row['connection_token'] );
			wp_remote_post( APPRESS_CENTRAL_URL . '/?my_appress=1&action=app.update_config', [
				'headers'   => [ 'Content-Type' => 'application/json' ],
				'body'      => wp_json_encode( [
					'connection_token'    => $plain_token,
					// Single source of truth post-1.3.0. Older Central
					// deployments (<= 1.1.x) read this under the legacy
					// `build_information` key — duplicated so the rolling
					// upgrade window is safe.
					'build_config'        => $backup_build,
					'build_information'   => $backup_build,
					// Legacy `config` key carried the old live_config slice
					// (now merged into build_config). Older Central reads
					// $params['config'] for its post_meta backup, so pass
					// the merged build_config through that key too — it
					// just means Central will mirror the same blob
					// twice on disk for one upgrade cycle.
					'config'              => $backup_build,
					'signing_secret'      => $signing_secret,
				], JSON_UNESCAPED_UNICODE ),
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => ! ( defined( 'APPRESS_IS_DEV' ) && \APPRESS_IS_DEV )
			] );

			return wp_send_json( [
				'success' => true,
				'message' => 'Settings saved successfully'
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Dedicated endpoint for the "Invalid connection token" recovery flow.
	 *
	 * The general `app.save` endpoint runs the full schema validator — which
	 * includes image-dimension checks on `logo`. If the stored logo is older
	 * than the current 1024×1024 minimum, the user gets locked out: they
	 * can't fix the token without first re-uploading a bigger logo, and
	 * they can't save a new logo because Central rejects the expired token.
	 *
	 * This endpoint touches exactly one column (connection_token) and skips
	 * the schema loop entirely, so users can paste a fresh token to unblock
	 * themselves without any collateral validation.
	 */
	protected function update_token() {
		try {
			$this->check_permissions();

			// Form-urlencoded body posted by Vue admin (`postForm` helper).
			// Mirrors Voxel's `jQuery.post` shape so strict hosting WAFs
			// (LiteSpeed / cPanel ModSecurity rule 920420) accept the
			// request — raw `application/json` POST to a non-REST endpoint
			// silently 406s on those hosts.
			$params = wp_unslash( $_POST );

			$app_id = intval( $params['app_id'] ?? 0 );
			if ( ! $app_id ) {
				throw new \Exception( esc_html__( 'App ID is required.', 'appress' ) );
			}

			$new_token = isset( $params['connection_token'] ) ? sanitize_text_field( $params['connection_token'] ) : '';
			if ( $new_token === '' ) {
				throw new \Exception( esc_html__( 'Connection token is required.', 'appress' ) );
			}

			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, connection_token FROM $table WHERE id = %d", $app_id ), ARRAY_A );
			if ( ! $row ) {
				throw new \Exception( esc_html__( 'App not found.', 'appress' ) );
			}

			$wpdb->update(
				$table,
				[
					'connection_token'        => \Appress\encrypt( $new_token ),
					'connection_token_lookup' => \Appress\lookup_hash( $new_token ),
				],
				[ 'id' => $app_id ]
			);

			return wp_send_json( [
				'success' => true,
				'message' => __( 'Connection token updated.', 'appress' )
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Resolve an iOS app's numeric App Store ID from its bundle ID via
	 * Apple's public iTunes Search lookup, optionally persist into the
	 * app row so callers can skip the usual save-config round-trip.
	 *
	 * Two call sites:
	 *   - SingleAppView's inline Get button — sends only `bundle_id`,
	 *     lets the form decide when to persist (current save flow).
	 *   - Settings page Smart Banner card — sends `app_id` too, so the
	 *     server writes the resolved id back into `wp_appress_apps`
	 *     immediately. The settings page has no save button context for
	 *     individual apps, so persisting here is the cleaner UX.
	 *
	 * Request:  POST { bundle_id: "com.x.y", app_id?: 6 }
	 * Response: { success, data: { apple_store_id: "1234567890", persisted: bool } }
	 */
	protected function lookup_apple_store_id() {
		try {
			$this->check_permissions();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$params = wp_unslash( $_POST );

			$bundle = isset( $params['bundle_id'] ) ? sanitize_text_field( $params['bundle_id'] ) : '';
			$app_id = isset( $params['app_id'] ) ? intval( $params['app_id'] ) : 0;

			// Caller didn't pass bundle_id but did pass app_id — read the
			// bundle from the row so the Settings page can fetch with just
			// the app id (no need to know the package).
			if ( $bundle === '' && $app_id > 0 ) {
				global $wpdb;
				$table = $wpdb->prefix . 'appress_apps';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$bi    = $wpdb->get_var( $wpdb->prepare( "SELECT build_config FROM {$table} WHERE id = %d", $app_id ) );
				$json  = json_decode( (string) $bi, true );
				if ( is_array( $json ) ) {
					$bundle = (string) ( $json['package_id'] ?? '' );
				}
			}

			if ( $bundle === '' ) {
				throw new \Exception( esc_html__( 'Bundle ID is required.', 'appress' ) );
			}

			$transient_key = 'appress_itunes_lookup_' . md5( $bundle );
			$cached        = get_transient( $transient_key );
			$store_id      = '';
			if ( is_array( $cached ) && isset( $cached['apple_store_id'] ) ) {
				$store_id = $cached['apple_store_id'];
			} else {
				$response = wp_remote_get(
					'https://itunes.apple.com/lookup?bundleId=' . rawurlencode( $bundle ),
					[ 'timeout' => 8, 'headers' => [ 'Accept' => 'application/json' ] ]
				);

				if ( is_wp_error( $response ) ) {
					throw new \Exception( esc_html( sprintf(
						/* translators: %s: error from Apple iTunes lookup */
						__( 'Apple lookup failed: %s', 'appress' ),
						$response->get_error_message()
					) ) );
				}

				$body = wp_remote_retrieve_body( $response );
				$data = json_decode( $body, true );
				if ( ! is_array( $data ) || empty( $data['results'] ) || ! isset( $data['results'][0]['trackId'] ) ) {
					set_transient( $transient_key, [ 'not_found' => true ], 5 * MINUTE_IN_SECONDS );
					throw new \Exception( esc_html__( 'App not on App Store yet.', 'appress' ) );
				}

				$store_id = (string) (int) $data['results'][0]['trackId'];
				set_transient( $transient_key, [ 'apple_store_id' => $store_id ], DAY_IN_SECONDS );
			}

			$persisted = false;
			if ( $app_id > 0 ) {
				$persisted = $this->persist_apple_store_id( $app_id, $store_id );
			}

			return wp_send_json([
				'success' => true,
				'data'    => [
					'apple_store_id' => $store_id,
					'persisted'      => $persisted,
				],
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}

	/**
	 * Write `apple_store_id` into a single app's `build_config`
	 * column without disturbing other keys. Called inline by the
	 * lookup endpoint when the caller wants the value persisted in
	 * one round-trip.
	 */
	private function persist_apple_store_id( int $app_id, string $store_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'appress_apps';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT build_config FROM {$table} WHERE id = %d", $app_id ) );
		if ( $raw === null ) {
			return false;
		}
		$bi = json_decode( (string) $raw, true );
		if ( ! is_array( $bi ) ) {
			$bi = [];
		}
		if ( ( $bi['apple_store_id'] ?? '' ) === $store_id ) {
			return true;
		}
		$bi['apple_store_id'] = $store_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, [ 'build_config' => wp_json_encode( $bi ) ], [ 'id' => $app_id ] );
		return true;
	}

	protected function handle_onboard() {
		try {
			$this->check_permissions();

			// Form-urlencoded body posted by Vue admin (`postForm` helper).
			// Mirrors Voxel's `jQuery.post` shape so strict hosting WAFs
			// (LiteSpeed / cPanel ModSecurity rule 920420) accept the
			// request — raw `application/json` POST to a non-REST endpoint
			// silently 406s on those hosts.
			$params = wp_unslash( $_POST );
			$token    = sanitize_text_field( $params['connection_token'] ?? '' );

			if ( empty( $token ) ) {
				throw new \Exception( esc_html__( 'Please properly enter the Connection Token.', 'appress' ) );
			}

			// Check duplicate: token already registered on this site?
			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			// Lookup uses HMAC of the plaintext token (not the encrypted column directly,
			// since the cipher is non-deterministic and won't match on equality).
			$lookup   = \Appress\lookup_hash( $token );
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE connection_token_lookup = %s LIMIT 1", $lookup ) );
			if ( $existing ) {
				throw new \Exception( esc_html__( 'This Connection Token is already linked to an existing App on this website.', 'appress' ) );
			}

			// Call Central SaaS to verify token and fetch package-id
			$response = wp_remote_post( APPRESS_CENTRAL_URL . '/?my_appress=1&action=app.connect', [
				'headers'   => [ 'Content-Type' => 'application/json' ],
				'body'      => wp_json_encode( [ 'connection_token' => $token ] ),
				'timeout'   => 15,
				'sslverify' => ! ( defined( 'APPRESS_IS_DEV' ) && \APPRESS_IS_DEV )
			] );

			if ( is_wp_error( $response ) ) {
    /* translators: dynamic value injected into the message */
				throw new \Exception( esc_html( sprintf( __( 'Central SaaS unreachable: %s', 'appress' ), $response->get_error_message() ) ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$code = wp_remote_retrieve_response_code( $response );

			if ( $code === 200 && isset( $body['success'] ) && $body['success'] ) {
				$central_app_id = intval( $body['data']['post_id'] ?? 0 );
				$app_name = !empty($body['data']['post_title']) ? sanitize_text_field($body['data']['post_title']) : 'SaaS App';

				// Pre-1.3.0 Central used to split the backup across two
				// post_meta blobs (`config` ↔ live_config_backup,
				// `build_information` ↔ build_information_backup). v1.3.0
				// collapsed those into a single `build_config` blob, so
				// every legacy key now feeds into the same merge target.
				// Order matters: newest schema's `build_config_backup`
				// wins, falling back to the legacy keys for older
				// Central deployments.
				$build_info_backup = $body['data']['build_config_backup']
					?? $body['data']['build_information_backup']
					?? [];
				$build_info_from_backup = is_array( $build_info_backup ) ? $build_info_backup : [];

				$legacy_live_backup = $body['data']['live_config_backup'] ?? [];
				if ( is_array( $legacy_live_backup ) && ! empty( $legacy_live_backup ) ) {
					// Build wins on collision — newer slot.
					$build_info_from_backup = array_replace( $legacy_live_backup, $build_info_from_backup );
				}

				// Always-fresh fields from Central regardless of merge path —
				// these are authoritative on Central (package_id is allocated
				// there, sha1 derives from the build keystore, app_name is
				// the post title customer renames in central UI).
				$essentials = [
					'package_id'       => sanitize_text_field( $body['data']['package_id'] ?? '' ),
					'title'            => $app_name,
					'sha1_fingerprint' => sanitize_text_field( $body['data']['sha1_fingerprint'] ?? '' ),
				];

				$signing_secret = isset( $body['data']['signing_secret'] ) ? sanitize_text_field( $body['data']['signing_secret'] ) : '';

				// unique_class is Central-derived (deterministic from package_id) and
				// gets persisted to its own DB column for fast lookup. Validate format
				// before accepting — must be "X" followed by hex chars (Swift/Java
				// identifier-safe). Empty string is acceptable: a Central running an
				// older version of the plugin won't include the field; the lazy
				// backfill on the Central side will populate it on the next
				// app.connect / app.update_config round-trip.
				$unique_class_raw = isset( $body['data']['unique_class'] ) ? sanitize_text_field( $body['data']['unique_class'] ) : '';
				$unique_class = preg_match( '/^X[a-f0-9]{8,32}$/', $unique_class_raw ) === 1 ? $unique_class_raw : '';

				// Existing-row lookup by central_app_id. The duplicate-token
				// guard above already rejected exact-token re-link, so any
				// row we find here was previously linked with a DIFFERENT
				// token (rotation: Central revoked → new token issued) or
				// the user is reconnecting an app whose connection_token
				// was cleared locally. Either way, preserve local
				// build_config edits — only overwrite when a field is
				// genuinely missing or empty locally and the backup has
				// a value. Never blow away user-edited fields with stale
				// Central backups.
				$existing_row = $central_app_id > 0
					? $wpdb->get_row( $wpdb->prepare( "SELECT id, build_config FROM $table WHERE central_app_id = %d", $central_app_id ), ARRAY_A )
					: null;

				if ( $existing_row ) {
					$local_build = json_decode( (string) $existing_row['build_config'], true );
					$local_build = is_array( $local_build ) ? $local_build : [];

					// Fill-only-missing: local wins where it has a non-empty
					// value; backup fills holes. `array_replace` order matters
					// — backup first, local overlays it.
					$merged_build = array_replace( $build_info_from_backup, $this->non_empty( $local_build ) );

					// Always-fresh essentials override either side.
					$merged_build = array_replace( $merged_build, $essentials );

					$update_payload = [
						'app_name'                => $app_name,
						'connection_token'        => \Appress\encrypt( $token ),
						'connection_token_lookup' => \Appress\lookup_hash( $token ),
						'build_config'            => wp_json_encode( $merged_build ),
					];
					if ( $signing_secret !== '' ) {
						$update_payload['signing_secret'] = \Appress\encrypt( $signing_secret );
					}
					if ( $unique_class !== '' ) {
						$update_payload['unique_class'] = $unique_class;
					}
					$wpdb->update( $table, $update_payload, [ 'id' => intval( $existing_row['id'] ) ] );

					if ( $unique_class !== '' ) {
						\Appress\clear_apps_class_cache();
					}

					return wp_send_json( [
						'success' => true,
						'message' => __( 'Reconnected — local settings preserved.', 'appress' ),
						'data'    => [ 'app_id' => intval( $existing_row['id'] ), 'reconnected' => true ],
					] );
				}

				// Fresh install for this central app on this site — no local
				// data to preserve, populate from Central backup as-is.
				$build_info = array_replace( $build_info_from_backup, $essentials );
				$wpdb->insert( $table, [
					'app_name'                 => $app_name,
					'connection_token'         => \Appress\encrypt( $token ),
					'connection_token_lookup'  => \Appress\lookup_hash( $token ),
					'central_app_id'           => $central_app_id,
					'unique_class'             => $unique_class !== '' ? $unique_class : null,
					'build_config'             => wp_json_encode( $build_info ),
					'signing_secret'           => $signing_secret !== '' ? \Appress\encrypt( $signing_secret ) : null,
				]);

				if ( $unique_class !== '' ) {
					\Appress\clear_apps_class_cache();
				}

				return wp_send_json( [
					'success' => true,
					'message' => 'App connected successfully!',
					'data'    => [ 'app_id' => intval( $wpdb->insert_id ), 'reconnected' => false ],
				] );
			} else {
				$msg = $body['message'] ?? 'Invalid token.';
    /* translators: dynamic value injected into the message */
				throw new \Exception( esc_html( sprintf( __( 'Central SaaS Rejected: %s', 'appress' ), $msg ) ) );
			}
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	protected function request_build() {
		try {
			$this->check_permissions();

			$central_url = defined( 'APPRESS_CENTRAL_URL' ) ? APPRESS_CENTRAL_URL : '';
			if ( empty( $central_url ) ) {
				throw new \Exception( esc_html__( 'Central URL not defined.', 'appress' ) );
			}

			$app_id = intval( $_POST['app_id'] ?? 0 );
			if ( ! $app_id ) {
				throw new \Exception( esc_html__( 'App ID is required.', 'appress' ) );
			}

			// Read config from the local DB — this is the source of truth
			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $app_id ), ARRAY_A );

			if ( ! $row ) {
				throw new \Exception( esc_html__( 'App not found in local database.', 'appress' ) );
			}

			$build_info = ! empty( $row['build_config'] ) ? json_decode( $row['build_config'], true ) : [];
			if ( ! is_array( $build_info ) ) $build_info = [];

			// Validate iOS permission descriptions — every plist usage
			// string the engine will need MUST be filled in. Build
			// engine's `03-post-config.js` ALSO throws on missing
			// strings (defence-in-depth), but failing fast here gives
			// the admin a clear message before the payload even
			// crosses to Central + the engine. Rule set mirrors the
			// engine's `requirePerm()` calls one-for-one.
			$ios_perms = isset( $build_info['ios_permissions'] ) && is_array( $build_info['ios_permissions'] ) ? $build_info['ios_permissions'] : [];
			$feature_on = function ( $key ) use ( $build_info ) {
				$f = $build_info[ $key ] ?? null;
				if ( ! is_array( $f ) ) return false;
				return ! empty( $f['enabled'] );
			};
			$missing_perms = [];
			$require_perm = function ( $key, $feature_label ) use ( $ios_perms, &$missing_perms ) {
				$v = isset( $ios_perms[ $key ] ) ? trim( (string) $ios_perms[ $key ] ) : '';
				if ( $v === '' ) {
					$missing_perms[] = sprintf( '%s (required by %s)', $key, $feature_label );
				}
			};
			if ( $feature_on( 'geolocation' ) )                                 $require_perm( 'location',          'Geolocation' );
			if ( $feature_on( 'photo_camera' ) || $feature_on( 'qr_scanner' ) ) $require_perm( 'camera',            'Photo & Camera / QR Scanner' );
			if ( $feature_on( 'microphone' ) )                                  $require_perm( 'microphone',        'Microphone' );
			if ( $feature_on( 'photo_camera' ) )                                $require_perm( 'photo_library',     'Photo & Camera' );
			if ( $feature_on( 'photo_camera' ) )                                $require_perm( 'photo_library_add', 'Photo & Camera' );
			if ( $feature_on( 'biometric' ) )                                   $require_perm( 'face_id',           'Biometric' );
			if ( ! empty( $missing_perms ) ) {
				throw new \Exception( sprintf(
					/* translators: %s = comma-separated list of missing permission keys */
					esc_html__( 'Fill in the iOS Permission Descriptions before requesting a build. Missing: %s. Open Build → Features → iOS Permission Descriptions.', 'appress' ),
					esc_html( implode( ', ', $missing_perms ) )
				) );
			}

			// Live-config fields (Custom CSS, Disable Web Ads, Analytics,
			// Smart Prefetch, Subscreen routing rules) live in the
			// dedicated `settings` DB column (1.4.0 schema split) and the
			// build engine never sees them. The `unset()` scrubs that
			// used to live here are gone for good — the storage layer
			// enforces the boundary now.

			// Signing credentials live in the encrypted `credentials` JSON
			// column. Decrypt here for transit to Central over HTTPS — Central
			// re-encrypts at rest on its side before forwarding to Build Engine.
			$credentials_raw = \Appress\decrypt( (string) ( $row['credentials'] ?? '' ) );
			$credentials     = $credentials_raw ? json_decode( $credentials_raw, true ) : [];
			if ( ! is_array( $credentials ) ) $credentials = [];

			// Firebase files are base64-encoded server-side to guarantee byte-exact transport.
			$firebase_android_raw = $build_info['firebase_android'] ?? '';
			$firebase_ios_raw     = $build_info['firebase_ios']     ?? '';
			$b64_android = ! empty( $firebase_android_raw ) ? base64_encode( $firebase_android_raw ) : '';
			$b64_ios     = ! empty( $firebase_ios_raw )     ? base64_encode( $firebase_ios_raw )     : '';

			// Binary credentials travel as base64 so bytes survive JSON transport.
			// Text fields (team_id, key_id, issuer_id, passwords) stay plain —
			// \Appress\decrypt above already converted them to plaintext.
			$keystore_raw    = $credentials['android_keystore_file']          ?? '';
			$apple_p8_raw    = $credentials['apple_appstore_key_p8']          ?? '';
			$play_sa_raw     = $credentials['google_play_service_account_json'] ?? '';
			$keystore_b64    = ! empty( $keystore_raw ) ? base64_encode( $keystore_raw ) : '';
			$apple_p8_b64    = ! empty( $apple_p8_raw ) ? base64_encode( $apple_p8_raw ) : '';
			$play_sa_b64     = ! empty( $play_sa_raw )  ? base64_encode( $play_sa_raw )  : '';

			// Parse platforms from the POST payload.
			$platforms_raw = sanitize_text_field( wp_unslash( $_POST['platforms'] ?? '["android","ios"]' ) );
			$platforms     = json_decode( $platforms_raw, true );
			if ( ! is_array( $platforms ) ) $platforms = [ 'android', 'ios' ];

			// Ship the FULL admin-configured `build_info` (app_screens,
			// bottom_navigation, side_menu, right_menu, css_*, first_launch,
			// auth_gate, subscreen, biometric, etc.) so the build engine
			// has every UI field baked into `AppressBakedConfig` at compile
			// time — no runtime `app.boot` refetch needed. The engine
			// previously had to re-call this site's `app.boot` from
			// `pre-config.js` to pull the missing UI config, which made
			// every build engine-side `app-config.json` edit useless
			// (next build overwrote it) and added a network round-trip.
			//
			// Order matters: spread `build_info` FIRST, then override with
			// Central/credential-derived fields (firebase base64 vs raw,
			// signing creds, request-time platforms, etc.) so the
			// overrides win on key collision.
			$app_config = array_merge( $build_info, [
				// CLIENT local DB id (wp_appress_apps.id) — NOT Central post_id.
				// Native code uses this to call /?appress=1&action=app.get_config&app_id=X
				// on the CLIENT site. Central post_id is a different number.
				'app_id'           => $app_id,

				// Subscreen routing rules + Smart Prefetch settings live
				// on the WP side now — printed into every page's wp_head
				// as `window.AppressAppSettings` (see
				// `frontend-controller::print_app_settings`) and consumed
				// by `AppressAppSettingsService` at runtime. Stripping
				// them from the build payload keeps the baked config
				// (and the `__cstring` segment Apple's 4.3 classifier
				// scans) clean: no more shared `subscreen_url_patterns`
				// JSON shape across customer binaries. Admin edits
				// apply on the next page load with no rebuild.
				// Base64-encoded so binary Firebase config bytes survive
				// JSON transport without escaping issues. Overrides the
				// raw `build_info` values which were stored verbatim.
				'firebase_android' => $b64_android,
				'firebase_ios'     => $b64_ios,
				// Request-time platform selection (admin may build only
				// android or only ios from the UI even if both are
				// configured in `build_info`).
				'platforms'        => $platforms,

				// iOS signing — Team ID (public, from build_config) +
				// App Store Connect API Key trio (encrypted at rest). Build
				// Engine uses the API key with xcodebuild -allowProvisioning-
				// Updates to fetch/create Development profile on the fly and
				// sign the .ipa. The same key is reused to upload to
				// TestFlight / App Store Connect post-build.
				'apple_appstore_key_id'    => $credentials['apple_appstore_key_id']       ?? '',
				'apple_appstore_issuer_id' => $credentials['apple_appstore_issuer_id']    ?? '',
				'apple_appstore_key_p8'    => $apple_p8_b64,

				// Android Play Store signing — customer override; empty tells
				// Central to fall back to the per-app auto-generated keystore.
				'android_keystore_b64'      => $keystore_b64,
				'android_keystore_password' => $credentials['android_keystore_password'] ?? '',
				'android_keystore_alias'    => $credentials['android_keystore_alias']    ?? '',

				// Play Console package-name anti-squatting verification.
				// Engine writes `assets/adi-registration.properties` before
				// gradle when present. Empty → engine skips, build unchanged.
				'play_console_verification_token' => $credentials['play_console_verification_token'] ?? '',

				// Google Play auto-publish (premium — consumed if present).
				'google_play_service_account_json' => $play_sa_b64,
			] );

			// Apply the `appress/app/live_config` filter to the build
			// payload — same hook `Frontend_Controller::handle_boot`
			// runs to enrich `app.boot` for the host preview app.
			// Integrations (TranslatePress) inject their per-language
			// variant map here (`translatepress.languages[code].{slug,
			// host, config}`) so the engine bakes it into
			// `AppressBakedConfig` and customer apps consume it
			// straight from the binary — same baking pattern as
			// `bottom_navigation`. Pre-fix, the filter only ran on
			// the live `app.boot` path → host preview app received
			// variants but customer apps shipped with
			// `translatepress: {enabled, strings: []}` and missing
			// `languages`, so the native variant apply guard
			// returned early and TP was a silent no-op on every
			// customer install.
			$app_config = (array) apply_filters( 'appress/app/live_config', $app_config, $app_id );

			// Default language for the mobile binary. Apple uses this
			// for `CFBundleDevelopmentRegion` (the "Primary Language"
			// in App Store Connect) and Android uses it as the
			// unqualified `res/values/strings.xml` fallback when the
			// device locale doesn't match any TP-translated `values-<lang>/`.
			// Source: TP plugin's `default_language` when TP is on
			// (so the mobile binary matches the WP site's own default
			// pick), else WP's `get_locale()` base. Engine reads
			// `payload.default_language` in `injectors/*.js` and
			// `03-post-config.js`.
			$tp_default = isset( $app_config['translatepress']['default_language'] )
				? trim( (string) $app_config['translatepress']['default_language'] )
				: '';
			if ( $tp_default === '' || empty( $app_config['translatepress']['enabled'] ) ) {
				$site_locale  = (string) get_locale();
				$lang_base    = substr( $site_locale, 0, 2 );
				$tp_default   = strtolower( $lang_base ?: 'en' );
			}
			$app_config['default_language'] = $tp_default;

			// POST the structured payload to Central — token is decrypted to plaintext for the HTTPS hop.
			$response = wp_remote_post( $central_url . '/?my_appress=1&action=build.request', [
				'body'      => [
					'connection_token' => \Appress\decrypt( (string) $row['connection_token'] ),
					'post_id'          => intval( $_POST['post_id'] ?? 0 ),
					// JSON_UNESCAPED_UNICODE ships Vietnamese / CJK / Cyrillic / etc.
					// as raw UTF-8 bytes instead of `á` escapes. Without
					// the flag, the body is URL-encoded by `wp_remote_post`,
					// reaches Central, and Central's `wp_unslash($_POST)`
					// strips the leading `\` of every Unicode escape — so
					// `Khám phá` arrives at the build engine as the
					// literal string `Khu00e1m phá` and ships into the
					// app's bottom-nav title verbatim. Customer build_1206
					// (bottom_navigation items: Khám phá / Đã lưu / Tài
					// khoản) surfaced this; all three titles arrived with
					// every `\u…` reduced to `u…`.
					'app_config'       => wp_json_encode( $app_config, JSON_UNESCAPED_UNICODE ),
				],
				'timeout'   => 15,
				'sslverify' => ! ( defined( 'APPRESS_IS_DEV' ) && \APPRESS_IS_DEV )
			] );

			if ( is_wp_error( $response ) ) {
    /* translators: dynamic value injected into the message */
				throw new \Exception( esc_html( sprintf( __( 'Central SaaS unreachable: %s', 'appress' ), $response->get_error_message() ) ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $body['success'] ) ) {
				throw new \Exception( esc_html__( 'Invalid response from Central SaaS.', 'appress' ) );
			}

			return wp_send_json( $body );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	protected function get_builds() {
		try {
			$this->check_permissions();

			$central_url = defined( 'APPRESS_CENTRAL_URL' ) ? APPRESS_CENTRAL_URL : '';
			if ( empty( $central_url ) ) {
				throw new \Exception( esc_html__( 'Central URL not defined.', 'appress' ) );
			}

			$proxy_body = [
				'connection_token' => sanitize_text_field( wp_unslash( $_POST['connection_token'] ?? '' ) ),
				'post_id'          => intval( $_POST['post_id'] ?? 0 ),
			];

			$response = wp_remote_post( $central_url . '/?my_appress=1&action=build.get', [
				'body'      => $proxy_body,
				'timeout'   => 10,
				'sslverify' => ! ( defined( 'APPRESS_IS_DEV' ) && \APPRESS_IS_DEV )
			] );

			if ( is_wp_error( $response ) ) {
    /* translators: dynamic value injected into the message */
				throw new \Exception( esc_html( sprintf( __( 'Central SaaS unreachable: %s', 'appress' ), $response->get_error_message() ) ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $body['success'] ) ) {
				throw new \Exception( esc_html__( 'Invalid response from Central SaaS.', 'appress' ) );
			}

			// Rewrite each Central URL to a local proxy URL — but ONLY for
			// platforms Central actually signalled as downloadable (non-empty
			// URL). Previously we unconditionally set both android_url and
			// ios_url on any 'completed' build, which produced a working-looking
			// link for a platform that hadn't finished yet (404 from engine).
			$post_id = intval( $_POST['post_id'] ?? 0 );

			if ( isset($body['success']) && $body['success'] && isset($body['data']['builds']) ) {
				$local_base = home_url( '/?appress=1&action=app.download_build' );
				$dl_nonce   = wp_create_nonce( 'appress_download_build' );
				foreach ($body['data']['builds'] as &$b) {
					$b['android_url'] = ! empty( $b['android_url'] )
						? $local_base . '&app_id=' . $post_id . '&build_id=' . $b['id'] . '&os=android&_wpnonce=' . $dl_nonce
						: '';
					$b['android_aab_url'] = ! empty( $b['android_aab_url'] )
						? $local_base . '&app_id=' . $post_id . '&build_id=' . $b['id'] . '&os=android_aab&_wpnonce=' . $dl_nonce
						: '';
					$b['ios_url'] = ! empty( $b['ios_url'] )
						? $local_base . '&app_id=' . $post_id . '&build_id=' . $b['id'] . '&os=ios&_wpnonce=' . $dl_nonce
						: '';
				}
			}

			return wp_send_json( $body );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Proxy to Central `build.get_config` — returns the request-time
	 * snapshot of the engine payload for a specific build_id, so the
	 * admin UI can show exactly what config produced a given binary.
	 * Sensitive credentials are redacted server-side on Central.
	 */
	protected function get_build_config() {
		try {
			$this->check_permissions();

			$central_url = defined( 'APPRESS_CENTRAL_URL' ) ? APPRESS_CENTRAL_URL : '';
			if ( empty( $central_url ) ) {
				throw new \Exception( esc_html__( 'Central URL not defined.', 'appress' ) );
			}

			$build_id = intval( $_POST['build_id'] ?? 0 );
			if ( $build_id <= 0 ) {
				throw new \Exception( esc_html__( 'build_id is required.', 'appress' ) );
			}

			$proxy_body = [
				'connection_token' => sanitize_text_field( wp_unslash( $_POST['connection_token'] ?? '' ) ),
				'post_id'          => intval( $_POST['post_id'] ?? 0 ),
				'build_id'         => $build_id,
			];

			$response = wp_remote_post( $central_url . '/?my_appress=1&action=build.get_config', [
				'body'      => $proxy_body,
				'timeout'   => 15,
				'sslverify' => ! ( defined( 'APPRESS_IS_DEV' ) && \APPRESS_IS_DEV )
			] );

			if ( is_wp_error( $response ) ) {
				/* translators: dynamic value injected into the message */
				throw new \Exception( esc_html( sprintf( __( 'Central SaaS unreachable: %s', 'appress' ), $response->get_error_message() ) ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $body['success'] ) ) {
				throw new \Exception( esc_html__( 'Invalid response from Central SaaS.', 'appress' ) );
			}

			return wp_send_json( $body );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	protected function get_plan() {
		try {
			$this->check_permissions();

			$central_url = defined( 'APPRESS_CENTRAL_URL' ) ? APPRESS_CENTRAL_URL : '';
			if ( empty( $central_url ) ) {
				throw new \Exception( esc_html__( 'Central URL not defined.', 'appress' ) );
			}

			$app_id = intval( $_POST['app_id'] ?? 0 );
			if ( ! $app_id ) throw new \Exception( esc_html__( 'Missing app_id.', 'appress' ) );

			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT connection_token, central_app_id FROM $table WHERE id = %d", $app_id ), ARRAY_A );
			if ( ! $row ) throw new \Exception( esc_html__( 'App not found.', 'appress' ) );

			$response = wp_remote_post( $central_url . '/?my_appress=1&action=build.get_plan', [
				'body'      => [
					'connection_token' => \Appress\decrypt( (string) $row['connection_token'] ),
					'post_id'          => intval( $row['central_app_id'] ),
				],
				'timeout'   => 10,
				'sslverify' => ! ( defined( 'APPRESS_IS_DEV' ) && \APPRESS_IS_DEV )
			] );

			if ( is_wp_error( $response ) ) {
    /* translators: dynamic value injected into the message */
				throw new \Exception( esc_html( sprintf( __( 'Central unreachable: %s', 'appress' ), $response->get_error_message() ) ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $body['success'] ) ) {
				throw new \Exception( esc_html__( 'Invalid response from Central.', 'appress' ) );
			}

			return wp_send_json( $body );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	protected function submit_testflight() {
		try {
			$this->check_permissions();

			$central_url = defined( 'APPRESS_CENTRAL_URL' ) ? APPRESS_CENTRAL_URL : '';
			if ( empty( $central_url ) ) {
				throw new \Exception( esc_html__( 'Central URL not defined.', 'appress' ) );
			}

			$app_id   = intval( $_POST['app_id'] ?? 0 );
			$build_id = intval( $_POST['build_id'] ?? 0 );
			if ( ! $app_id || ! $build_id ) throw new \Exception( esc_html__( 'Missing app_id or build_id.', 'appress' ) );

			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT connection_token, central_app_id FROM $table WHERE id = %d", $app_id ), ARRAY_A );
			if ( ! $row ) throw new \Exception( esc_html__( 'App not found.', 'appress' ) );

			$response = wp_remote_post( $central_url . '/?my_appress=1&action=build.testflight_submit', [
				'body'      => [
					'connection_token' => \Appress\decrypt( (string) $row['connection_token'] ),
					'post_id'          => intval( $row['central_app_id'] ),
					'build_id'         => $build_id,
				],
				'timeout'   => 600,
				'sslverify' => ! ( defined( 'APPRESS_IS_DEV' ) && \APPRESS_IS_DEV ),
			] );

			if ( is_wp_error( $response ) ) {
    /* translators: dynamic value injected into the message */
				throw new \Exception( esc_html( sprintf( __( 'Central unreachable: %s', 'appress' ), $response->get_error_message() ) ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) ) {
				throw new \Exception( esc_html__( 'Invalid Central response.', 'appress' ) );
			}

			return wp_send_json( $body );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	protected function publish_playstore() {
		return $this->proxy_to_central( 'build.playstore_publish', [
			'build_id' => intval( $_POST['build_id'] ?? 0 ),
			'track'    => sanitize_text_field( $_POST['track'] ?? 'production' ),
		], 600 );
	}

	protected function publish_appstore() {
		return $this->proxy_to_central( 'build.appstore_publish', [
			'build_id' => intval( $_POST['build_id'] ?? 0 ),
		], 2400 );
	}

	/**
	 * Generic proxy helper: Vue -> Client plugin -> Central. Resolves
	 * connection_token + central_app_id from local DB, forwards extra args
	 * to Central, returns Central's response verbatim. Used by all
	 * publish actions to avoid duplicating boilerplate.
	 */
	private function proxy_to_central( $action, $extra_args, $timeout = 600 ) {
		try {
			$this->check_permissions();

			$central_url = defined( 'APPRESS_CENTRAL_URL' ) ? APPRESS_CENTRAL_URL : '';
			if ( empty( $central_url ) ) throw new \Exception( esc_html__( 'Central URL not defined.', 'appress' ) );

			$app_id = intval( $_POST['app_id'] ?? 0 );
			if ( ! $app_id ) throw new \Exception( esc_html__( 'Missing app_id.', 'appress' ) );

			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT connection_token, central_app_id FROM $table WHERE id = %d", $app_id ), ARRAY_A );
			if ( ! $row ) throw new \Exception( esc_html__( 'App not found.', 'appress' ) );

			$body = array_merge( [
				'connection_token' => \Appress\decrypt( (string) $row['connection_token'] ),
				'post_id'          => intval( $row['central_app_id'] ),
			], $extra_args );

			$response = wp_remote_post( $central_url . '/?my_appress=1&action=' . $action, [
				'body'      => $body,
				'timeout'   => $timeout,
				'sslverify' => ! ( defined( 'APPRESS_IS_DEV' ) && \APPRESS_IS_DEV ),
			] );

			if ( is_wp_error( $response ) ) {
    /* translators: dynamic value injected into the message */
				throw new \Exception( esc_html( sprintf( __( 'Central unreachable: %s', 'appress' ), $response->get_error_message() ) ) );
			}

			$body_resp = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body_resp ) ) throw new \Exception( esc_html__( 'Invalid Central response.', 'appress' ) );
			return wp_send_json( $body_resp );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	protected function download_build() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Unauthorized Access.' );
			}

			// Verify nonce to prevent CSRF on download links
			$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
			if ( ! wp_verify_nonce( $nonce, 'appress_download_build' ) ) {
				wp_die( 'Security token invalid or expired. Please refresh the page and try again.' );
			}

			$app_id   = intval( $_GET['app_id'] ?? 0 ); // Central App ID
			$build_id = intval( $_GET['build_id'] ?? 0 );
			
			$os   = sanitize_text_field( wp_unslash( $_GET['os'] ?? '' ) );
			$file = sanitize_text_field( wp_unslash( $_GET['file'] ?? '' ) ); // Fallback cũ

			if ( $os === 'android' ) {
				$file = 'android.apk';
			} elseif ( $os === 'android_aab' ) {
				$file = 'android.aab';
			} elseif ( $os === 'ios' ) {
				$file = 'iphone.ipa';
			}

			// Compose a human-readable filename for the download (e.g. my-app-android.apk).
			$app_slug = sanitize_title( get_the_title( $app_id ) ?: 'app' );
			$display_name = $app_slug . '-' . $file;

			if ( ! $app_id || ! $build_id || ! $file ) {
				wp_die( 'Missing required parameters.' );
			}

			// 1. Recover the Central connection token stored locally for this app.
			// The column holds the encrypted envelope — decrypt before using.
			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			$encrypted_token = $wpdb->get_var( $wpdb->prepare( "SELECT connection_token FROM $table WHERE central_app_id = %d LIMIT 1", $app_id ) );
			$token = \Appress\decrypt( (string) $encrypted_token );

			if ( empty($token) ) {
				wp_die( 'App connection token not found or invalid.' );
			}

			$central_url = defined( 'APPRESS_CENTRAL_URL' ) ? APPRESS_CENTRAL_URL : '';
			if ( empty($central_url) ) {
				wp_die( 'Central config error.' );
			}

			// Hand off to Central with a 302 redirect — Central validates token
			// then 302s again to R2 (or streams from the engine for legacy builds).
			// No bytes pass through this plugin → zero hosting-bandwidth cost and
			// no PHP timeouts on large .ipa/.apk. Browser follows the chain and
			// starts the download once the R2 Content-Disposition lands.
			//
			// Token must go on the query string here — 302 cannot carry custom
			// headers. Using connection_token which Central validates with
			// hash_equals against encrypted postmeta. Not ideal to have it in
			// the URL, but the URL lives exactly one request before the browser
			// follows the redirect; short-lived signed tokens could replace this
			// later.
			$download_url = add_query_arg( [
				'my_appress' => 1,
				'action'     => 'build.download_file',
				'build_id'   => $build_id,
				'file'       => $file,
				'token'      => $token,
			], $central_url . '/' );

			wp_redirect( $download_url, 302 );
			exit;

		} catch ( \Exception $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	protected function pull_config() {
		// Deprecated / Refactored later depending on needs.
		return wp_send_json( [ 'success' => false, 'message' => 'Pull is currently unimplemented for Multi-App.' ] );
	}

	/**
	 * Re-fetch the "always-fresh" fields from Central using the app's
	 * stored connection token. Authoritative on Central:
	 *   - package_id     (allocated by Central, may rotate when admin
	 *                     changes the bundle id)
	 *   - app_name       (post title — customer renames in Central UI)
	 *   - sha1_fingerprint (derived from the build keystore)
	 *
	 * Used by the "refresh" icon next to Package ID in Build Config.
	 * Does NOT touch user-edited fields in `build_config` or
	 * `live_config` — only the three essentials above are merged in.
	 *
	 * Mirrors the merge step in handle_onboard()'s reconnect branch
	 * (line ~830) but skips the duplicate-token guard since we are
	 * intentionally hitting Central with a token we already own.
	 */
	protected function refresh_essentials() {
		try {
			$this->check_permissions();

			$app_id = isset( $_POST['app_id'] ) ? intval( $_POST['app_id'] ) : 0;
			if ( $app_id <= 0 ) {
				throw new \Exception( esc_html__( 'App ID is required.', 'appress' ) );
			}

			global $wpdb;
			$table = $wpdb->prefix . 'appress_apps';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, connection_token, build_config FROM {$table} WHERE id = %d", $app_id ), ARRAY_A );
			if ( ! $row ) {
				throw new \Exception( esc_html__( 'App not found.', 'appress' ) );
			}

			$token = \Appress\decrypt( (string) $row['connection_token'] );
			if ( $token === '' ) {
				throw new \Exception( esc_html__( 'Connection token is required.', 'appress' ) );
			}

			$central_url = defined( 'APPRESS_CENTRAL_URL' ) ? APPRESS_CENTRAL_URL : '';
			if ( $central_url === '' ) {
				throw new \Exception( esc_html__( 'Central URL not defined.', 'appress' ) );
			}

			$response = wp_remote_post( $central_url . '/?my_appress=1&action=app.connect', [
				'headers'   => [ 'Content-Type' => 'application/json' ],
				'body'      => wp_json_encode( [ 'connection_token' => $token ] ),
				'timeout'   => 15,
				'sslverify' => ! ( defined( 'APPRESS_IS_DEV' ) && \APPRESS_IS_DEV ),
			] );

			if ( is_wp_error( $response ) ) {
				/* translators: %s: error message returned from the HTTP transport. */
				throw new \Exception( esc_html( sprintf( __( 'Central SaaS unreachable: %s', 'appress' ), $response->get_error_message() ) ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code !== 200 || empty( $body['success'] ) ) {
				$msg = $body['message'] ?? __( 'Invalid response from Central SaaS.', 'appress' );
				/* translators: %s: error message returned by Central. */
				throw new \Exception( esc_html( sprintf( __( 'Central SaaS Rejected: %s', 'appress' ), $msg ) ) );
			}

			$data       = is_array( $body['data'] ?? null ) ? $body['data'] : [];
			$package_id = sanitize_text_field( $data['package_id'] ?? '' );
			$app_name   = ! empty( $data['post_title'] ) ? sanitize_text_field( $data['post_title'] ) : '';
			$sha1       = sanitize_text_field( $data['sha1_fingerprint'] ?? '' );

			// unique_class — Central-derived per-app identifier. Persist to its
			// own column for fast lookup (used to scope mobile-facing endpoints).
			// Validate to "X" + hex chars before accepting; empty / malformed
			// → skip the column update, leave whatever's currently there intact.
			$unique_class_raw = sanitize_text_field( $data['unique_class'] ?? '' );
			$unique_class = preg_match( '/^X[a-f0-9]{8,32}$/', $unique_class_raw ) === 1 ? $unique_class_raw : '';

			$build = json_decode( (string) $row['build_config'], true );
			$build = is_array( $build ) ? $build : [];

			$essentials = [];
			if ( $package_id !== '' ) {
				$essentials['package_id'] = $package_id;
			}
			if ( $app_name !== '' ) {
				$essentials['title'] = $app_name;
			}
			if ( $sha1 !== '' ) {
				$essentials['sha1_fingerprint'] = $sha1;
			}

			if ( empty( $essentials ) ) {
				throw new \Exception( esc_html__( 'Invalid response from Central SaaS.', 'appress' ) );
			}

			$merged_build = array_replace( $build, $essentials );

			$update_payload = [ 'build_config' => wp_json_encode( $merged_build ) ];
			if ( $app_name !== '' ) {
				$update_payload['app_name'] = $app_name;
			}
			if ( $unique_class !== '' ) {
				$update_payload['unique_class'] = $unique_class;
			}
			$wpdb->update( $table, $update_payload, [ 'id' => $app_id ] );

			if ( $unique_class !== '' ) {
				\Appress\clear_apps_class_cache();
			}

			// Expose unique_class to the Vue UI so the read-only field updates
			// in place without a full hydrate round-trip.
			$response_data = $essentials;
			if ( $unique_class !== '' ) {
				$response_data['unique_class'] = $unique_class;
			}

			return wp_send_json( [
				'success' => true,
				'message' => __( 'Settings saved.', 'appress' ),
				'data'    => $response_data,
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}
}
