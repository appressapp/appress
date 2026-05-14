<?php
namespace Appress\Integration\Woocommerce\Events\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Order_Paid_Event extends Base_Order_Event {

	public function get_key(): string {
		return 'woocommerce/order/paid';
	}

	public function get_label(): string {
		return 'Order paid';
	}

	public function get_description(): string {
		return 'Fires when payment is completed for an order.';
	}

	protected function default_destinations(): array {
		return [
			'customer' => $this->customer_destination( [
				'default_subject' => 'Payment received',
				'default_message' => 'We received payment for order #{{order_number}}.',
			] ),
			'admin'    => $this->admin_destination( [
				'default_enabled' => true,
				'default_subject' => 'Order #{{order_number}} paid',
				'default_message' => 'Payment confirmed — {{order_total}} {{order_currency}}.',
			] ),
		];
	}
}
