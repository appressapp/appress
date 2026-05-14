<?php
namespace Appress\Integration\Woocommerce\Events\Orders;

use Appress\Integration\Woocommerce\Events\Base_WC_Event;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared prepare/tokens for every order-lifecycle event.
 * Subclasses only declare key/label/defaults — no repeated WC API plumbing.
 */
abstract class Base_Order_Event extends Base_WC_Event {

	public function get_category(): string {
		return 'orders';
	}

	public function get_category_label(): string {
		return 'Orders';
	}

	/** @param int $order_id Injected by the WC hook. */
	public function prepare( ...$args ): void {
		$order_id = (int) ( $args[0] ?? 0 );
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			throw new \Exception( 'wc_get_order unavailable or missing order id' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new \Exception( 'order not found' );
		}
		$this->order = $order;
		$this->extra = (array) ( $args[1] ?? [] );
	}

	public function dynamic_tags(): array {
		$order = $this->order;
		if ( ! $order ) {
			return $this->extra;
		}
		return array_merge( [
			'order_id'       => $order->get_id(),
			'order_number'   => (string) $order->get_order_number(),
			'order_total'    => (string) $order->get_total(),
			'order_currency' => (string) $order->get_currency(),
			'order_status'   => (string) $order->get_status(),
			'order_url'      => (string) $order->get_view_order_url(),
			'billing_name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'item_count'     => (int) $order->get_item_count(),
		], $this->extra );
	}

	public function token_hints(): array {
		return [
			'order_id'       => 'Order ID',
			'order_number'   => 'Order number (display)',
			'order_total'    => 'Order total',
			'order_currency' => 'Currency code',
			'order_status'   => 'Current status',
			'order_url'      => 'View-order URL',
			'billing_name'   => 'Customer name',
			'item_count'     => 'Number of items',
		];
	}

	/** Recipient callables share identical logic across order events. */
	protected function customer_destination( array $overrides = [] ): array {
		return array_merge( [
			'label'           => 'Notify customer',
			'default_enabled' => true,
			'recipient'       => function ( Base_Order_Event $event ) {
				$customer_id = (int) $event->order->get_customer_id();
				return $customer_id > 0 ? [ $customer_id ] : [];
			},
			'default_subject' => '',
			'default_message' => '',
			'default_url'     => '{{order_url}}',
		], $overrides );
	}

	protected function admin_destination( array $overrides = [] ): array {
		return array_merge( [
			'label'           => 'Notify admin',
			'default_enabled' => false,
			'recipient'       => function ( Base_Order_Event $event ) {
				return $event->get_admin_recipient_ids();
			},
			'default_subject' => '',
			'default_message' => '',
			'default_url'     => '{{order_url}}',
		], $overrides );
	}

	public function get_admin_recipient_ids(): array {
		return parent::get_admin_recipient_ids();
	}
}
