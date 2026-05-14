<?php

namespace Appress\Controllers\App;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bump every app's boot-config cache key whenever something happens
 * that would shift the shape of the payload `app.boot` returns.
 *
 * The native mobile app sends its last-seen `update_time_hash` on
 * every cold-start (see `Frontend_Controller::handle_boot` →
 * "up_to_date" short-circuit). When the hash matches, native keeps
 * its cached config; when it differs, native re-downloads. We mint a
 * fresh `time()` value into every `wp_appress_apps.live_config
 * .update_time_hash` on the two events that can silently change what
 * the boot payload returns without an admin-touched per-app save:
 *
 *   1. **Plugin version change** (any direction — upgrade or
 *      downgrade). New controllers / filters may inject extra
 *      `live_config` fields; old apps still on the previous version's
 *      cached payload would miss those until the next per-app admin
 *      save. Detected by comparing `APPRESS_VERSION` against a
 *      stored `appress_last_known_version` option on every request —
 *      cheap (single autoloaded option read + string compare) and
 *      catches every deployment path including rsync / git pull
 *      where the standard `upgrader_process_complete` hook does NOT
 *      fire.
 *
 *   2. **Integration toggle on/off** (Appress → Integrations admin
 *      page). Each integration's bootstrap hooks `appress/app/live_
 *      config` (and CSS / link selector filters) — flipping a module
 *      changes the boot payload's shape immediately. Hooked on
 *      `update_option_appress_settings` so we only bump on actual
 *      changes to `appress_settings.modules`, not unrelated section
 *      writes (smart_banner, qr_login, etc).
 *
 * Performance: bump is one DB UPDATE per app row, rare event. The
 * version check is one in-memory option read per request — negligible.
 */
class Cache_Invalidation_Controller extends Base_Controller {

	/** Autoloaded so `get_option` resolves from the WP options cache,
	 *  no DB query on the typical request. */
	const VERSION_OPTION = 'appress_last_known_version';

	protected function hooks() {
		// Version-change check — runs on every request, but the
		// in-memory option read + string compare is effectively free
		// until the version actually shifts.
		$this->on( 'init', '@maybe_bump_on_version_change', 1 );

		// Integration toggle — fires only when `appress_settings`
		// option actually changes, and our handler only acts when
		// the `modules` subkey is what changed.
		$this->on( 'update_option_appress_settings', '@on_settings_change', 10, 3 );
	}

	/**
	 * Detect a plugin version mismatch between the source code
	 * (`APPRESS_VERSION` constant) and the last persisted record on
	 * the site. Triggers on:
	 *   - First load after upgrade (auto + manual + WP.org + GitHub)
	 *   - First load after rsync / git pull deploys to a customer site
	 *   - First load after downgrade (rollback flow)
	 *   - Fresh install (option absent → treated as version-change)
	 *
	 * We bump caches in BOTH directions because a downgrade can also
	 * change the boot-payload shape (removed integration → stale
	 * cache still has the removed block until next per-app save).
	 */
	protected function maybe_bump_on_version_change() {
		if ( ! defined( 'APPRESS_VERSION' ) ) {
			return;
		}
		$current = (string) APPRESS_VERSION;
		$known   = (string) get_option( self::VERSION_OPTION, '' );
		if ( $known === $current ) {
			return;
		}
		// Persist FIRST so a concurrent request on the same boot
		// doesn't double-bump (idempotent but wastes DB writes).
		update_option( self::VERSION_OPTION, $current, true );
		$this->bump_all_apps();
	}

	/**
	 * `update_option_appress_settings` fires AFTER WP has written the
	 * new value. We only bump when the `modules` subkey changed —
	 * unrelated writes (smart_banner toggle, qr_login toggle) don't
	 * shift boot-payload shape so they shouldn't invalidate every
	 * app's cache.
	 */
	protected function on_settings_change( $old_value, $new_value, $option ) {
		$old_modules = ( is_array( $old_value ) && isset( $old_value['modules'] ) && is_array( $old_value['modules'] ) ) ? $old_value['modules'] : [];
		$new_modules = ( is_array( $new_value ) && isset( $new_value['modules'] ) && is_array( $new_value['modules'] ) ) ? $new_value['modules'] : [];
		if ( $old_modules === $new_modules ) {
			return;
		}
		$this->bump_all_apps();
	}

	/**
	 * Mint a fresh timestamp into `live_config.update_time_hash` for
	 * every row in `wp_appress_apps`. Encoded back as JSON so the
	 * column shape stays identical to what `Apps_Controller@save`
	 * writes.
	 *
	 * Done as a per-row read-modify-write rather than a single SQL
	 * statement because `live_config` is JSON-in-TEXT (not the native
	 * JSON column on every customer's MySQL version), so a portable
	 * `JSON_SET()` isn't available. Plugin sites typically have a
	 * handful of apps — sequential UPDATE cost is negligible.
	 */
	public function bump_all_apps() {
		global $wpdb;
		$table = $wpdb->prefix . 'appress_apps';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( "SELECT id, live_config FROM {$table}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return;
		}
		$stamp = (string) time();
		foreach ( $rows as $row ) {
			$cfg = ! empty( $row['live_config'] ) ? json_decode( (string) $row['live_config'], true ) : [];
			if ( ! is_array( $cfg ) ) {
				$cfg = [];
			}
			$cfg['update_time_hash'] = $stamp;
			$wpdb->update(
				$table,
				[ 'live_config' => wp_json_encode( $cfg ) ],
				[ 'id' => (int) $row['id'] ]
			);
		}
	}
}
