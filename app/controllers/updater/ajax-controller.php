<?php

namespace Appress\Controllers\Updater;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX surface for the Updates tab on the Settings page.
 *
 * Two endpoints, both admin-only + nonce-gated:
 *
 *   updater.list_versions
 *     Fetches the public release feed of `appressapp/appress` from
 *     GitHub's REST API, transformed into a compact `{version, tag,
 *     date, zip_url}` payload the Vue tab renders into a select
 *     dropdown. Cached in a 1-hour transient to keep us well under
 *     GitHub's 60-req-per-hour anonymous rate limit even when an
 *     admin hammers refresh.
 *
 *   updater.rollback
 *     Hands a target version's `appress.zip` URL to WP's built-in
 *     `Plugin_Upgrader` (the same machinery the Plugins page uses
 *     for stock updates). WP handles download → unpack → file
 *     replacement → plugin reactivation. We re-validate the picked
 *     version against the cached release list before passing the
 *     URL to the upgrader so a forged POST can't trick us into
 *     installing an arbitrary zip from outside our repo.
 */
class Ajax_Controller extends Base_Controller {

	const TRANSIENT_RELEASES = 'appress_updater_releases_v1';
	const TRANSIENT_TTL      = HOUR_IN_SECONDS;
	const GITHUB_REPO        = 'appressapp/appress';

	protected function hooks() {
		$this->on( 'appress_ajax_updater.list_versions', '@handle_list_versions' );
		$this->on( 'appress_ajax_updater.rollback',      '@handle_rollback' );
	}

	/**
	 * Return `{current, releases: [...]}`. Each release element:
	 *   - version    e.g. "1.0.0.7" (tag with leading `v` stripped)
	 *   - tag        e.g. "v1.0.0.7" (raw GitHub tag)
	 *   - date       ISO timestamp of the GitHub release publish event
	 *   - zip_url    direct download URL of the `appress.zip` asset
	 *   - is_current bool — matches APPRESS_VERSION
	 */
	protected function handle_list_versions() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				throw new \Exception( esc_html__( 'Unauthorized.', 'appress' ) );
			}
			if ( ! $this->verify_admin_nonce() ) {
				throw new \Exception( esc_html__( 'Security check failed.', 'appress' ) );
			}

			$force = ! empty( $_POST['force'] );
			if ( $force ) {
				delete_transient( self::TRANSIENT_RELEASES );
			}

			$releases = $this->fetch_releases();
			$current  = defined( 'APPRESS_VERSION' ) ? (string) APPRESS_VERSION : '';

			$out = [];
			foreach ( $releases as $r ) {
				$out[] = [
					'version'    => $r['version'],
					'tag'        => $r['tag'],
					'date'       => $r['date'],
					'zip_url'    => $r['zip_url'],
					'is_current' => $r['version'] === $current,
				];
			}

			return wp_send_json( [
				'success' => true,
				'data'    => [
					'current'  => $current,
					'releases' => $out,
				],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}

	/**
	 * Install the requested version's `appress.zip` via WP's
	 * `Plugin_Upgrader`. Same machinery used by the Plugins admin
	 * page — backups + activation are handled by core.
	 *
	 * Response on success: `{success: true, version, reload_required: true}`.
	 * Client should `location.reload()` after a brief delay so the
	 * admin UI re-bootstraps under the newly-installed code base.
	 */
	protected function handle_rollback() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				throw new \Exception( esc_html__( 'Unauthorized.', 'appress' ) );
			}
			if ( ! $this->verify_admin_nonce() ) {
				throw new \Exception( esc_html__( 'Security check failed.', 'appress' ) );
			}

			$requested = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
			if ( $requested === '' ) {
				throw new \Exception( esc_html__( 'Missing version.', 'appress' ) );
			}

			// Re-validate the requested version against the cached
			// release list. Without this guard a forged POST could pass
			// `version=anything-with-a-malicious-zip`.
			$releases = $this->fetch_releases();
			$match    = null;
			foreach ( $releases as $r ) {
				if ( $r['version'] === $requested ) {
					$match = $r;
					break;
				}
			}
			if ( ! $match ) {
				throw new \Exception( esc_html__( 'Version not found in the published release list.', 'appress' ) );
			}

			// `Plugin_Upgrader` lives in admin scope — load it lazily so
			// the AJAX endpoint (which doesn't include admin headers by
			// default) gets the class without a fatal.
			if ( ! class_exists( '\Plugin_Upgrader' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/misc.php';
			}

			// `Automatic_Upgrader_Skin` swallows the upgrader's HTML
			// progress output (which assumes a full admin page wrap).
			// We surface success/failure via JSON instead.
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $match['zip_url'], [ 'overwrite_package' => true ] );

			if ( is_wp_error( $result ) ) {
				throw new \Exception( esc_html( $result->get_error_message() ) );
			}
			if ( $result === false ) {
				// Upgrader returned a clean false without WP_Error —
				// usually a filesystem credentials issue (FTP mode).
				throw new \Exception( esc_html__( 'Install failed. Check filesystem permissions.', 'appress' ) );
			}

			// Re-activate the plugin if WP deactivated it during the
			// upgrade. `Plugin_Upgrader::install` with `overwrite_package`
			// preserves activation in modern WP, but we belt-and-suspender
			// in case a future WP rev changes the contract.
			$plugin_file = plugin_basename( APPRESS_PLUGIN_FILE );
			if ( ! is_plugin_active( $plugin_file ) ) {
				activate_plugin( $plugin_file );
			}

			// Bust the release cache so the next `list_versions` call
			// reflects the newly-installed version's `is_current` flag.
			delete_transient( self::TRANSIENT_RELEASES );

			return wp_send_json( [
				'success'         => true,
				'version'         => $match['version'],
				'reload_required' => true,
				'message'         => sprintf(
					/* translators: %s: target version, e.g. 1.0.0.5 */
					__( 'Installed version %s.', 'appress' ),
					$match['version']
				),
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}

	/**
	 * Pull (or read from cache) the GitHub release list.
	 *
	 * Returns an array of `{version, tag, date, zip_url}` entries
	 * sorted newest-first. Filters out:
	 *   - Drafts (`draft=true`)
	 *   - Pre-releases (`prerelease=true`) — admins picking from
	 *     production UI should not see beta builds.
	 *   - Releases without an `appress.zip` asset — those are likely
	 *     hand-tagged commits the CI workflow hasn't built yet, and
	 *     selecting them would 404 on rollback.
	 *
	 * Throws on any HTTP / parse error so the caller surfaces a
	 * coherent message to the admin instead of an empty dropdown.
	 */
	private function fetch_releases(): array {
		$cached = get_transient( self::TRANSIENT_RELEASES );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases?per_page=30',
			[
				'timeout'    => 10,
				'sslverify'  => true,
				'user-agent' => 'Appress-Updater/' . ( defined( 'APPRESS_VERSION' ) ? APPRESS_VERSION : '0' ),
				'headers'    => [
					'Accept' => 'application/vnd.github+json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( esc_html( $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			throw new \Exception(
				/* translators: %d: HTTP status code */
				sprintf( esc_html__( 'GitHub returned HTTP %d.', 'appress' ), $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			throw new \Exception( esc_html__( 'Malformed GitHub response.', 'appress' ) );
		}

		$out = [];
		foreach ( $json as $rel ) {
			if ( ! is_array( $rel ) ) {
				continue;
			}
			if ( ! empty( $rel['draft'] ) || ! empty( $rel['prerelease'] ) ) {
				continue;
			}
			$tag = isset( $rel['tag_name'] ) ? (string) $rel['tag_name'] : '';
			if ( $tag === '' ) {
				continue;
			}
			// Find a plugin-zip asset; skip releases missing one.
			// Matches both the legacy flat name (`appress.zip`) and
			// the versioned scheme (`appress-1.0.0.8.zip`) the CI
			// workflow ships going forward. Without the tolerant
			// matcher, all releases newer than the rename would
			// silently fall off the dropdown.
			$zip_url = '';
			foreach ( (array) ( $rel['assets'] ?? [] ) as $asset ) {
				if ( ! isset( $asset['name'], $asset['browser_download_url'] ) ) {
					continue;
				}
				$name = strtolower( (string) $asset['name'] );
				if ( strpos( $name, 'appress' ) === 0 && substr( $name, -4 ) === '.zip' ) {
					$zip_url = (string) $asset['browser_download_url'];
					break;
				}
			}
			if ( $zip_url === '' ) {
				continue;
			}
			$out[] = [
				'version' => ltrim( $tag, 'vV' ),
				'tag'     => $tag,
				'date'    => isset( $rel['published_at'] ) ? (string) $rel['published_at'] : '',
				'zip_url' => $zip_url,
			];
		}

		// GitHub's `/releases?per_page=N` endpoint sorts by tag name
		// LEXICALLY descending, NOT by date or version. That breaks for
		// double-digit segments: `v1.0.0.9` lexically > `v1.0.0.11`
		// because `'9' > '1'`, so the API returns `9, 8, 7, 11, 10`
		// and the Vue side picks `1.0.0.9` as "Latest available" on a
		// site running `1.0.0.11`. Sort with PHP's `version_compare`
		// which knows numeric-segment semantics — drops the entire
		// downstream chain into the expected newest-first order.
		usort( $out, function ( $a, $b ) {
			return version_compare( $b['version'], $a['version'] );
		} );

		set_transient( self::TRANSIENT_RELEASES, $out, self::TRANSIENT_TTL );
		return $out;
	}

	/**
	 * Both endpoints accept the shared `appress_admin_action` nonce —
	 * same one Settings\Admin_Controller bootstraps into
	 * `window.appressConfig.nonce`. The Vue admin's `postForm` helper
	 * always sends it as an `X-WP-Nonce` request header (NOT in the
	 * form body), matching the convention every other Appress admin
	 * AJAX endpoint already follows (see Settings\Ajax_Controller).
	 * Body-side `$_POST['nonce']` is kept as a fallback for callers
	 * that don't use the Vue helper.
	 */
	private function verify_admin_nonce(): bool {
		$nonce = '';
		if ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) );
		} elseif ( isset( $_POST['nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
		}
		return (bool) wp_verify_nonce( $nonce, 'appress_admin_action' );
	}
}
