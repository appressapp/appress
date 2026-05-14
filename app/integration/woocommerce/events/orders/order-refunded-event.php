<?php
namespace Appress\Integration\Woocommerce\Events\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Order_Refunded_Event extends Base_Order_Event {

	public function get_key(): string {
		return 'woocommerce/order/refunded';
	}

	public function get_label(): string {
		return 'Order refunded';
	}

	public function get_description(): string {
		return 'Fires when a refund is issued on an order.';
	}

	public function token_hints(): array {
		return parent::token_hints() + [ 'refund_amount' => 'Refunded amount' ];
	}

	protected function default_destinations(): array {
		return [
			'customer' => $this->customer_destination( [
				'default_subject' => 'Refund issued',
				'default_message' => 'We have refunded {{refund_amount}} {{order_currency}} for order #{{order_number}}.',
			] ),
			'admin'    => $this->admin_destination( [
				'default_enabled' => true,
				'default_subject' => 'Refund on order #{{order_number}}',
				'default_message' => '{{refund_amount}} {{order_currency}} refunded.',
			] ),
		];
	}
}
