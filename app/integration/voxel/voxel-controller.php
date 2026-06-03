<?php

namespace Appress\Integration\Voxel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Voxel_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Register integration card on the Appress Integrations page.
		$this->filter( 'appress/integrations/registered', '@register_integration' );

		// Run integrations ONLY when dynamically activated by the Integrations Manager.
		$this->on( 'appress/integration/voxel/execute', '@bootstrap_integrations' );

		// Detail-page renderer (Integrations → Voxel)
		$this->on( 'appress/integrations/admin_template/voxel', '@render_detail' );

		// Events_Controller is always on (regardless of module toggle)
		// so the Voxel event catalogue shows up on the Integrations → Voxel
		// detail page before the admin enables the module — they need
		// to see what's available in order to configure target apps +
		// push copy upfront. Runtime dispatchers inside the controller
		// gate themselves on `class_exists( \Voxel\Events\Base_Event )`
		// so this is a no-op on sites that don't run Voxel.
		new Controllers\Events_Controller();
	}

	protected function register_integration( $integrations ) {
		$integrations['voxel'] = [
			'name'         => __( 'Voxel Theme', 'appress' ),
			'description'  => __( 'Enable native integrations: Visibility Rules, App Events, and Google Login', 'appress' ),
			'color'        => 'purple',
			'icon'            => APPRESS_PLUGIN_URL . 'app/integration/voxel/logo.svg',

			'configurable' => true,
			'requires_plugin' => [
				'name'  => 'Voxel Theme',
				'class' => '\\Voxel\\Post',
			],
			'integrations'     => [
				'events'       => __( 'In-app Voxel event', 'appress' ),
				'google_login' => __( 'Google Login', 'appress' ),
				'indicators'   => __( 'Cart, Message & Notification indicators', 'appress' ),
			]
		];
		return $integrations;
	}

	protected function bootstrap_integrations() {
		// All integrations auto-enabled when main integration is on.
		// Events_Controller is intentionally NOT here — it's instantiated
		// in hooks() so the event schema shows up in the Integrations admin
		// UI even when Voxel module is toggled off.
		new \Appress\Integration\Voxel\Controllers\Visibility_Rules_Controller();
		new \Appress\Integration\Voxel\Controllers\Google_Login_Controller();
		new \Appress\Integration\Voxel\Controllers\Indicator_Controller();
		new \Appress\Integration\Voxel\Controllers\Notifications_Controller();
		new \Appress\Integration\Voxel\Controllers\App_Css_Controller();
		new \Appress\Integration\Voxel\Controllers\Subscreen_Patterns_Controller();
	}

	/**
	 * Voxel detail page — Events tab only.
	 */
	public function render_detail() {
		$tabs = [
			'events' => __( 'Events', 'appress' ),
		];
		$active = \Appress\current_integration_tab( $tabs, 'events' );
		\Appress\render_integration_tab_bar( 'voxel', $tabs, $active );
		\Appress\render_integration_events_panel( 'voxel' );
	}
}
