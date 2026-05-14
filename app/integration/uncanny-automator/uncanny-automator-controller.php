<?php

namespace Appress\Integration\Uncanny_Automator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps Appress's Uncanny Automator integration via the official
 * `automator_add_integration` action — same pattern as Automator's own sample
 * integration plugin (https://docs.automatorplugin.com/guides/create-integration/).
 *
 * Card visible in admin → Appress → Integrations. Toggle ON loads the
 * Automator-side integration class + triggers + actions.
 */
class Uncanny_Automator_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->filter( 'appress/integrations/registered', '@register_integration' );
		// Automator fires `automator_add_integration` exactly once during init,
		// AFTER its own framework is ready — perfect entry point for add-ons.
		$this->on( 'automator_add_integration', '@load_integration' );
	}

	protected function register_integration( $integrations ) {
		$integrations['uncanny_automator'] = [
			'name'        => __( 'Uncanny Automator', 'appress' ),
			'description' => __( 'Appress provides triggers and actions for Uncanny Automator, letting you take full advantage of your app’s commerce and engagement capabilities', 'appress' ),
			'color'       => 'blue',
			'icon'            => APPRESS_PLUGIN_URL . 'app/integration/uncanny-automator/logo.svg',

			'integrations'    => [
				'send_push_to_user' => __( 'Action: Send push to a user', 'appress' ),
				'app_install'       => __( 'Trigger: User installs the app for the first time', 'appress' ),
				'opened_today'      => __( 'Trigger: User opens the app for the first time today', 'appress' ),
				'user_inactive'     => __( 'Trigger: User is inactive for X days', 'appress' ),
				'user_returned'     => __( 'Trigger: User returns after being away X+ days', 'appress' ),
			],
		];
		return $integrations;
	}

	protected function load_integration() {
		// Gate: only load when admin has switched the integration ON.
		$active_modules = (array) \Appress\get( 'modules', [] );
		if ( empty( $active_modules['uncanny_automator'] ) ) {
			return;
		}

		// Gate: Automator base classes must exist (host plugin active).
		if ( ! class_exists( '\\Uncanny_Automator\\Integration' ) ) {
			return;
		}

		require_once __DIR__ . '/add-appress-integration.php';
		require_once __DIR__ . '/triggers/appress-app-installed.php';
		require_once __DIR__ . '/triggers/appress-app-opened-today.php';
		require_once __DIR__ . '/triggers/appress-user-inactive.php';
		require_once __DIR__ . '/triggers/appress-user-returned.php';
		require_once __DIR__ . '/actions/appress-send-push-to-user.php';

		new Add_Appress_Integration();
		new APPRESS_APP_INSTALLED();
		new APPRESS_APP_OPENED_TODAY();
		new APPRESS_USER_INACTIVE();
		new APPRESS_USER_RETURNED();
		new APPRESS_SEND_PUSH_TO_USER();
	}
}
