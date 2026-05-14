<?php
namespace Appress\Integration\Woocommerce\Events\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Review_Created_Event extends Base_Product_Event {

	public function get_key(): string {
		return 'woocommerce/review/created';
	}

	public function get_label(): string {
		return 'New product review';
	}

	public function get_description(): string {
		return 'Fires when a customer posts a product review.';
	}

	public function token_hints(): array {
		return parent::token_hints() + [
			'rating'         => 'Star rating 1–5',
			'review_author'  => 'Reviewer display name',
			'review_excerpt' => 'First 30 words of review',
		];
	}

	protected function default_destinations(): array {
		return [
			'admin' => $this->admin_destination( [
				'default_subject' => 'New review on {{product_title}}',
				'default_message' => '{{review_author}} left {{rating}}★ — "{{review_excerpt}}"',
			] ),
		];
	}
}
