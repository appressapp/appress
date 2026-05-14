<?php

namespace Appress\Controllers\Firebase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Template_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Inject JS to fetch token from Capacitor App
		$this->on( 'wp_footer', '@inject_token_sync_script', 999 );
	} 

	protected function inject_token_sync_script() {
		// FCM token registration is handled natively by:
		// - Android: AppressAppController.syncFcmToken() (Java FirebaseMessaging API)
		// - iOS: masterHubJS in AppressSlaveJSService.swift (Capacitor PushNotifications on master WebView)
		// No JS injection needed on Slave WebViews.
	}
}
