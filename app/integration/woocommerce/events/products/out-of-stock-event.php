<?php
namespace Appress\Integration\Woocommerce\Events\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Out_Of_Stock_Event extends Base_Product_Event {

	public function get_key(): string {
		return 'woocommerce/product/out_of_stock';
	}

	public function get_label(): string {
		return 'Out of stock';
	}

	public function get_description(): string {
		return 'Fires when a product is fully depleted.';
	}

	protected function default_destinations(): array {
		return [
			'admin' => $this->admin_destination( [
				'default_subject' => 'Out of stock: {{product_title}}',
				'default_message' => '{{product_title}} is sold out.',
			] ),
		];
	}
}
