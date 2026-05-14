<?php
namespace Appress\Integration\Woocommerce\Events\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Order_Created_Event extends Base_Order_Event {

	public function get_key(): string {
		return 'woocommerce/order/created';
	}

	public function get_label(): string {
		return 'Order created';
	}

	public function get_description(): string {
		return 'Fires when a new order is placed at checkout.';
	}

	protected function default_destinations(): array {
		return [
			'customer' => $this->customer_destination( [
				'default_subject' => 'Order confirmation',
				'default_message' => 'Thanks! Your order #{{order_number}} has been placed.',
			] ),
			'admin'    => $this->admin_destination( [
				'default_enabled' => true,
				'default_subject' => 'New order #{{order_number}}',
				'default_message' => '{{billing_name}} placed an order for {{order_total}} {{order_currency}}.',
			] ),
		];
	}
}
