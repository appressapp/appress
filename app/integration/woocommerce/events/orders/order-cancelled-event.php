<?php
namespace Appress\Integration\Woocommerce\Events\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Order_Cancelled_Event extends Base_Order_Event {

	public function get_key(): string {
		return 'woocommerce/order/cancelled';
	}

	public function get_label(): string {
		return 'Order cancelled';
	}

	public function get_description(): string {
		return 'Fires when an order is cancelled.';
	}

	protected function default_destinations(): array {
		return [
			'customer' => $this->customer_destination( [
				'default_subject' => 'Order cancelled',
				'default_message' => 'Order #{{order_number}} has been cancelled.',
			] ),
			'admin'    => $this->admin_destination( [
				'default_enabled' => true,
				'default_subject' => 'Order #{{order_number}} cancelled',
				'default_message' => '{{billing_name}} cancelled an order worth {{order_total}} {{order_currency}}.',
			] ),
		];
	}
}
