<?php

namespace Appress\Controllers\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activation / deactivation / uninstall hooks.
 *
 * Owns long-lived side effects that must be wound down (or set up)
 * when the plugin is switched on/off. Today the only inhabitant is
 * deactivation cleanup — wipe scheduled cron events the plugin owns
 * so a turned-off plugin doesn't keep enqueueing `appress_*` hooks
 * into WP-Cron's queue forever. Activation + uninstall logic land
 * here next as the surface grows (DB schema migrations, default
 * options seeding, etc.).
 */
class Lifecycle_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// `register_deactivation_hook` MUST receive the plugin's main
		// file path, not this controller's. Without the constant the
		// hook would be registered against a path inside `app/` and
		// WP would silently never fire it.
		register_deactivation_hook( APPRESS_PLUGIN_FILE, [ $this, 'on_deactivate' ] );
	}

	/**
	 * Broadcast campaigns schedule per-campaign one-shot sends via
	 * `wp_schedule_single_event` keyed on
	 * `appress_broadcast_send_campaign_cron`. On deactivation we wipe
	 * the entire hook so paused campaigns don't fire after the plugin
	 * is reactivated later with a different (or missing) handler.
	 * Reactivating mid-cycle restarts campaigns from the admin UI.
	 *
	 * Public because `register_deactivation_hook` invokes the callback
	 * directly — the controller's `@method_name` filter wrapper isn't
	 * in play here.
	 */
	public function on_deactivate() {
		wp_clear_scheduled_hook( 'appress_broadcast_send_campaign_cron' );
	}
}
