<?php

namespace Appress\Integration\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woocommerce_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->filter( 'appress/integrations/registered', '@register_integration' );
		$this->on( 'appress/integration/woocommerce/execute', '@bootstrap_integrations' );
		$this->on( 'appress/integrations/admin_template/woocommerce', '@render_detail' );

		// Events_Controller is always on so the Integrations → WooCommerce
		// detail page (Events tab) can read + write its data regardless of
		// the module toggle — admins need to pre-configure events before
		// flipping the master switch. Runtime dispatchers inside gate
		// themselves on `class_exists( 'WooCommerce' )` so it's a no-op on
		// sites without WC installed.
		if ( class_exists( 'WooCommerce' ) ) {
			new Controllers\Events_Controller();
		}
	}

	protected function register_integration( $integrations ) {
		$integrations['woocommerce'] = [
			'name'         => __( 'WooCommerce', 'appress' ),
			'description'  => __( 'Enable native cart indicator with real-time badge updates and order lifecycle push notifications', 'appress' ),
			'color'        => 'purple',
			'icon'            => APPRESS_PLUGIN_URL . 'app/integration/woocommerce/logo.svg',

			'configurable' => true,
			'requires_plugin' => [
				'name'  => 'WooCommerce',
				'class' => 'WooCommerce',
			],
			'integrations'     => [
				'indicators' => __( 'Cart indicator', 'appress' ),
				'events'     => __( 'App events (orders, reviews, stock)', 'appress' ),
			]
		];
		return $integrations;
	}

	protected function bootstrap_integrations() {
		// Events_Controller is intentionally NOT here — it's instantiated
		// in hooks() so the event schema shows up in the Integrations admin UI
		// even when the WooCommerce module is toggled off.
		if ( class_exists( 'WooCommerce' ) ) {
			new \Appress\Integration\Woocommerce\Controllers\Indicator_Controller();
			new \Appress\Integration\Woocommerce\Controllers\Inline_Links_Controller();
			new \Appress\Integration\Woocommerce\Controllers\Account_Controller();
		}
	}

	/**
	 * WooCommerce detail page — Events tab only.
	 */
	public function render_detail() {
		$tabs = [
			'events' => __( 'Events', 'appress' ),
		];
		$active = \Appress\current_integration_tab( $tabs, 'events' );
		\Appress\render_integration_tab_bar( 'woocommerce', $tabs, $active );
		\Appress\render_integration_events_panel( 'woocommerce' );
	}
}
