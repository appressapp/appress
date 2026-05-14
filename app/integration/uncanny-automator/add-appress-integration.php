<?php

namespace Appress\Integration\Uncanny_Automator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appress integration card in the Automator recipe builder.
 * Mirrors the official Automator sample integration plugin.
 *
 * Reference: https://docs.automatorplugin.com/guides/create-integration/
 */
class Add_Appress_Integration extends \Uncanny_Automator\Integration {

	protected function setup() {
		$this->set_integration( 'APPRESS' );
		$this->set_name( 'Appress' );
		$this->set_icon_url( APPRESS_PLUGIN_URL . 'assets/images/logo.svg' );
	}
}
