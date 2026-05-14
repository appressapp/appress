<?php
namespace Appress\Integration\Woocommerce\Events\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Order_Note_Event extends Base_Order_Event {

	public function get_key(): string {
		return 'woocommerce/order/note_added';
	}

	public function get_label(): string {
		return 'Customer note added';
	}

	public function get_description(): string {
		return 'Fires when the shop sends a customer-visible note on an order.';
	}

	public function token_hints(): array {
		return parent::token_hints() + [ 'note' => 'Note text' ];
	}

	protected function default_destinations(): array {
		return [
			'customer' => $this->customer_destination( [
				'default_subject' => 'New note on your order',
				'default_message' => '{{note}}',
			] ),
			// Admins authored the note — no need to notify themselves by default.
			'admin' => $this->admin_destination(),
		];
	}
}
