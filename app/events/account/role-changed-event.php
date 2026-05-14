<?php
namespace Appress\Events\Account;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Role_Changed_Event extends \Appress\Events\Base_Event {

	public function get_key(): string          { return 'account/role_changed'; }
	public function get_label(): string        { return 'Account role changed'; }
	public function get_category(): string     { return 'account'; }
	public function get_category_label(): string { return 'Account'; }
	public function get_description(): string {
		return 'Push the user when their account role changes — membership upgrades, admin-granted access, role revocation. Skips the default role assignment on first registration.';
	}

	protected function user_default_subject(): string {
		return 'Your account access updated';
	}

	protected function user_default_message(): string {
		return "Hi {{first_name}}, your role just changed from {{old_role}} to {{new_role}}.";
	}

	public function token_hints(): array {
		return parent::token_hints() + [
			'new_role' => 'New role label',
			'old_role' => 'Previous role label',
		];
	}
}
