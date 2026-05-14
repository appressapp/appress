<?php
namespace Appress\Events\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Login_New_Device_Event extends \Appress\Events\Base_Event {

	public function get_key(): string          { return 'auth/login_new_device'; }
	public function get_label(): string        { return 'Login from new device'; }
	public function get_category(): string     { return 'security'; }
	public function get_category_label(): string { return 'Security'; }
	public function get_description(): string {
		return 'Push the user when a sign-in is detected from a device (user-agent + IP) not seen before.';
	}

	protected function user_default_subject(): string {
		return 'New sign-in detected';
	}

	protected function user_default_message(): string {
		return "Hi {{first_name}}, a new sign-in on {{device_ua}} ({{device_ip}}) just happened. If this wasn't you, change your password immediately.";
	}

	public function token_hints(): array {
		return parent::token_hints() + [
			'device_ua' => 'Device user agent (friendly label)',
			'device_ip' => 'Device IP (last octet masked)',
		];
	}
}
