<?php
namespace Appress\Events\Account;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Changed_Event extends \Appress\Events\Base_Event {

	public function get_key(): string          { return 'account/email_changed'; }
	public function get_label(): string        { return 'Email changed'; }
	public function get_category(): string     { return 'security'; }
	public function get_category_label(): string { return 'Security'; }
	public function get_description(): string {
		return 'Push the user when the account email is updated — hijack detection + compliance.';
	}

	protected function user_default_subject(): string {
		return 'Your email address was updated';
	}

	protected function user_default_message(): string {
		return "Hi {{first_name}}, your account email just changed to {{new_email}}. If this wasn't you, contact support.";
	}

	public function token_hints(): array {
		return parent::token_hints() + [
			'old_email' => 'Previous email (masked)',
			'new_email' => 'New email (masked)',
		];
	}
}
