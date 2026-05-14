<?php

namespace Appress\Controllers\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom links rendered on the wp-admin → Plugins row for Appress.
 *
 * The Plugins admin page lists every installed plugin with a default
 * `Deactivate | Edit` link group. WP's `plugin_action_links_{basename}`
 * filter lets us prepend / append our own entries. Today we prepend:
 *
 *   1. "Onboard"   → admin.php?page=appress
 *      Jumps to the Appress dashboard so a first-run admin doesn't
 *      have to hunt for the side-menu entry — the moment they
 *      activate the plugin, the affordance is right next to the
 *      activation toggle they just clicked.
 *
 *   2. "Updates"   → admin.php?page=appress-settings&tab=updates
 *      Shortcut to the in-dashboard update / rollback panel.
 *      Visible at all times (not gated on "update available") so
 *      admins recovering from a bad update can find the rollback
 *      flow even when the new version is already considered
 *      current by WP.
 *
 * Future additions live here: premium-upsell link, Documentation
 * link, etc.
 */
class Action_Links_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->filter( 'plugin_action_links_' . plugin_basename( APPRESS_PLUGIN_FILE ), '@add_links' );
	}

	protected function add_links( $links ) {
		$onboard = '<a href="' . esc_url( admin_url( 'admin.php?page=appress' ) ) . '">'
			. esc_html__( 'Onboard', 'appress' ) . '</a>';
		$updates = '<a href="' . esc_url( admin_url( 'admin.php?page=appress-settings&tab=updates' ) ) . '">'
			. esc_html__( 'Updates', 'appress' ) . '</a>';
		// Onboard first, Updates second — keep the higher-frequency
		// affordance leftmost. Both prepend before WP's stock
		// Deactivate / Edit group.
		array_unshift( $links, $updates );
		array_unshift( $links, $onboard );
		return $links;
	}
}
