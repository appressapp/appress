<?php
namespace Appress\Integration\Woocommerce\Events\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Order_Completed_Event extends Base_Order_Event {

	public function get_key(): string {
		return 'woocommerce/order/completed';
	}

	public function get_label(): string {
		return 'Order completed';
	}

	public function get_description(): string {
		return 'Fires when an order is marked completed (delivered / fulfilled).';
	}

	protected function default_destinations(): array {
		return [
			'customer' => $this->customer_destination( [
				'default_subject' => 'Your order is complete',
				'default_message' => 'Order #{{order_number}} has been marked complete.',
			] ),
			'admin'    => $this->admin_destination( [
				'default_subject' => 'Order #{{order_number}} completed',
				'default_message' => 'Fulfilment closed for {{billing_name}}\'s order.',
			] ),
		];
	}
}
