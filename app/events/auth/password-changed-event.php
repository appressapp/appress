<?php
namespace Appress\Events\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Password_Changed_Event extends \Appress\Events\Base_Event {

	public function get_key(): string          { return 'auth/password_changed'; }
	public function get_label(): string        { return 'Password changed'; }
	public function get_category(): string     { return 'security'; }
	public function get_category_label(): string { return 'Security'; }
	public function get_description(): string {
		return 'Push the user every time the account password changes — lost-session warning + audit trail.';
	}

	protected function user_default_subject(): string {
		return 'Your password was changed';
	}

	protected function user_default_message(): string {
		return "Hi {{first_name}}, your account password just changed. If this wasn't you, contact support immediately.";
	}
}
