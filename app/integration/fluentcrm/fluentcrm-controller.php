<?php

namespace Appress\Integration\FluentCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FluentCRM_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->filter( 'appress/integrations/registered', '@register_integration' );
		$this->on( 'appress/integration/fluentcrm/execute', '@bootstrap' );
	}

	protected function register_integration( $integrations ) {
		$integrations['fluentcrm'] = [
			'name'        => __( 'FluentCRM', 'appress' ),
			'description' => __( 'Target push notification campaigns by FluentCRM lists and tags', 'appress' ),
			'color'       => 'cyan',
			'icon'            => APPRESS_PLUGIN_URL . 'app/integration/fluentcrm/logo.svg',

			'integrations'    => [
				'broadcast_targeting' => __( 'Campaign targeting by list/tag', 'appress' ),
			]
		];
		return $integrations;
	}

	protected function bootstrap() {
		if ( defined( 'FLUENTCRM' ) ) {
			new Controllers\Broadcast_Controller();
		}
	}
}
