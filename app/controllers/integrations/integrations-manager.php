<?php

namespace Appress\Controllers\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrations_Manager — activates enabled integration modules on boot.
 *
 * For every integration id marked `true` in `appress_settings.modules`,
 * this controller fires `appress/integration/{id}/execute` once per
 * request on `plugins_loaded`. Each integration controller listens to
 * that action and bootstraps its own sub-controllers (WC indicator +
 * events, Voxel visibility rules + Google login, FluentCRM lists, …).
 *
 * Decouples the on/off UI from the integration internals — the admin
 * toggles a card on the Integrations page, Ajax_Controller persists the
 * map, Integrations_Manager reads it on the next request, and each
 * integration boots itself. No controller references another by name.
 */
class Integrations_Manager extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// `plugins_loaded` priority 20 runs AFTER WooCommerce + Voxel +
		// other 3rd-party plugins finish loading on priority 10, so the
		// integration's bootstrap can safely call class_exists( 'WooCommerce' )
		// and find it.
		$this->on( 'plugins_loaded', '@activate_enabled_integrations', 20 );
	}

	protected function activate_enabled_integrations() {
		$modules = \Appress\get( 'modules', [] );
		if ( ! is_array( $modules ) ) {
			return;
		}
		foreach ( $modules as $id => $enabled ) {
			if ( ! $enabled ) {
				continue;
			}
			do_action( 'appress/integration/' . $id . '/execute' );
		}
	}
}
