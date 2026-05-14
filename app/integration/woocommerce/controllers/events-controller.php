<?php
namespace Appress\Integration\Woocommerce\Controllers;

// phpcs:disable WordPress.Security.NonceVerification.Recommended

use Appress\Controllers\Base_Controller;
use Appress\Integration\Woocommerce\Events\Base_WC_Event;
use Appress\Integration\Woocommerce\Events\Orders;
use Appress\Integration\Woocommerce\Events\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce → Appress event bridge.
 *
 * Each concrete event class under events/ declares its own recipients
 * (customer / admin / future vendor) and default templates. This
 * controller only wires the WC hooks to the matching event's dispatch().
 *
 * Vendor-marketplace plugins (WCFM, Dokan, …) extend the system by
 * adding a `vendor` destination through the
 * `appress/wc/events/{key}/destinations` filter — no edits here needed.
 */
class Events_Controller extends Base_Controller {

	/** @var Base_WC_Event[] — keyed by event key for O(1) lookup. */
	private $events = [];

	protected function hooks() {
		$this->filter( 'appress/events', '@register_schema' );

		// Priority 20 — let WC core finish mutating order state before we read it.
		// `woocommerce_new_order` fires on EVERY order creation path (classic
		// checkout, block/Store API checkout, admin manual, REST API, HPOS) —
		// `woocommerce_checkout_order_processed` only covers classic checkout,
		// so Blocks-based stores silently skipped this event.
		$this->on( 'woocommerce_new_order',                         '@on_order_created',   20, 1 );
		$this->on( 'woocommerce_payment_complete',                  '@on_order_paid',      20, 1 );
		$this->on( 'woocommerce_order_status_completed',            '@on_order_completed', 20, 1 );
		$this->on( 'woocommerce_order_status_cancelled',            '@on_order_cancelled', 20, 1 );
		$this->on( 'woocommerce_order_refunded',                    '@on_order_refunded',  20, 2 );
		$this->on( 'woocommerce_new_customer_note',                 '@on_order_note',      20, 1 );

		$this->on( 'comment_post',                                  '@on_review_created',  20, 3 );
		$this->on( 'woocommerce_low_stock',                         '@on_low_stock',       20, 1 );
		$this->on( 'woocommerce_no_stock',                          '@on_out_of_stock',    20, 1 );
	}

	/** Lazy-build the event catalog once per request. Filterable so add-ons can register extras. */
	private function events(): array {
		if ( ! empty( $this->events ) ) {
			return $this->events;
		}
		$events = [
			new Orders\Order_Created_Event(),
			new Orders\Order_Paid_Event(),
			new Orders\Order_Completed_Event(),
			new Orders\Order_Cancelled_Event(),
			new Orders\Order_Refunded_Event(),
			new Orders\Order_Note_Event(),
			new Products\Review_Created_Event(),
			new Products\Low_Stock_Event(),
			new Products\Out_Of_Stock_Event(),
		];
		$events = (array) apply_filters( 'appress/wc/events/register', $events );
		foreach ( $events as $event ) {
			if ( $event instanceof Base_WC_Event ) {
				$this->events[ $event->get_key() ] = $event;
			}
		}
		return $this->events;
	}

	protected function register_schema( $integrations ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $integrations;
		}
		$events_schema = [];
		foreach ( $this->events() as $event_key => $event ) {
			$events_schema[ $event_key ] = $event->get_schema();
		}
		$integrations['woocommerce'] = [
			'name'        => 'WooCommerce App Events',
			'icon'        => 'dashicons-cart',
			'type'        => 'global',
			'description' => 'Push notifications for order lifecycle, reviews, and stock alerts — one configurable recipient group per event.',
			'events'      => $events_schema,
		];
		return $integrations;
	}

	private function dispatch( string $key, ...$args ): void {
		$events = $this->events();
		if ( ! isset( $events[ $key ] ) ) {
			return;
		}
		$events[ $key ]->dispatch( ...$args );
	}

	// ── Order hooks ───────────────────────────────────────────────────────

	public function on_order_created( $order_id )   { $this->dispatch( 'woocommerce/order/created',   $order_id ); }
	public function on_order_paid( $order_id )      { $this->dispatch( 'woocommerce/order/paid',      $order_id ); }
	public function on_order_completed( $order_id ) { $this->dispatch( 'woocommerce/order/completed', $order_id ); }
	public function on_order_cancelled( $order_id ) { $this->dispatch( 'woocommerce/order/cancelled', $order_id ); }

	public function on_order_refunded( $order_id, $refund_id ) {
		$refund = function_exists( 'wc_get_order' ) ? wc_get_order( $refund_id ) : null;
		$extra = [ 'refund_amount' => $refund ? (string) $refund->get_amount() : '' ];
		$this->dispatch( 'woocommerce/order/refunded', $order_id, $extra );
	}

	public function on_order_note( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}
		$order = $args['order'] ?? null;
		if ( ! $order || ! method_exists( $order, 'get_id' ) ) {
			return;
		}
		$note = wp_strip_all_tags( (string) ( $args['customer_note'] ?? '' ) );
		$this->dispatch( 'woocommerce/order/note_added', $order->get_id(), [ 'note' => $note ] );
	}

	// ── Product hooks ─────────────────────────────────────────────────────

	public function on_review_created( $comment_id, $comment_approved, $commentdata ) {
		if ( $comment_approved !== 1 || empty( $commentdata['comment_post_ID'] ) ) {
			return;
		}
		$post_id = (int) $commentdata['comment_post_ID'];
		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		if ( ! $product ) {
			return;
		}
		$extra = [
			'rating'         => (int) get_comment_meta( $comment_id, 'rating', true ),
			'review_author'  => (string) ( $commentdata['comment_author'] ?? '' ),
			'review_excerpt' => wp_trim_words( wp_strip_all_tags( (string) ( $commentdata['comment_content'] ?? '' ) ), 30 ),
		];
		$this->dispatch( 'woocommerce/review/created', $product, $extra );
	}

	public function on_low_stock( $product )    { $this->dispatch( 'woocommerce/product/low_stock',    $product ); }
	public function on_out_of_stock( $product ) { $this->dispatch( 'woocommerce/product/out_of_stock', $product ); }
}
