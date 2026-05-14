<?php
namespace Appress\Integration\Woocommerce\Events\Products;

use Appress\Integration\Woocommerce\Events\Base_WC_Event;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared prepare/tokens for product-side events (stock alerts, reviews).
 * These events are admin-facing by default — customer never receives them.
 */
abstract class Base_Product_Event extends Base_WC_Event {

	public function get_category(): string {
		return 'products';
	}

	public function get_category_label(): string {
		return 'Products';
	}

	public function prepare( ...$args ): void {
		$product = $args[0] ?? null;
		if ( is_numeric( $product ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $product );
		}
		if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
			throw new \Exception( 'product missing or invalid' );
		}
		$this->product = $product;
		$this->extra   = (array) ( $args[1] ?? [] );
	}

	public function dynamic_tags(): array {
		$product = $this->product;
		if ( ! $product ) {
			return $this->extra;
		}
		$stock = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : '';
		return array_merge( [
			'product_id'    => $product->get_id(),
			'product_title' => wp_strip_all_tags( $product->get_name() ),
			'stock'         => (string) $stock,
		], $this->extra );
	}

	public function token_hints(): array {
		return [
			'product_id'    => 'Product ID',
			'product_title' => 'Product title',
			'stock'         => 'Stock quantity',
		];
	}

	protected function admin_destination( array $overrides = [] ): array {
		return array_merge( [
			'label'           => 'Notify admin',
			'default_enabled' => true,
			'recipient'       => function ( Base_Product_Event $event ) {
				return $event->get_admin_recipient_ids();
			},
			'default_subject' => '',
			'default_message' => '',
			'default_url'     => '',
		], $overrides );
	}

	public function get_admin_recipient_ids(): array {
		return parent::get_admin_recipient_ids();
	}
}
