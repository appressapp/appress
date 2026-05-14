<?php
namespace Appress\Integration\Woocommerce\Events\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Low_Stock_Event extends Base_Product_Event {

	public function get_key(): string {
		return 'woocommerce/product/low_stock';
	}

	public function get_label(): string {
		return 'Low stock';
	}

	public function get_description(): string {
		return 'Fires when a product drops below its low-stock threshold.';
	}

	protected function default_destinations(): array {
		return [
			'admin' => $this->admin_destination( [
				'default_subject' => 'Low stock: {{product_title}}',
				'default_message' => '{{stock}} units remain for {{product_title}}.',
			] ),
		];
	}
}
