<?php
namespace Appress\Integration\Woocommerce\Controllers;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default "load in same screen, NOT a new subscreen" CSS selectors for
 * WooCommerce templates. Admin can always override / disable these via the
 * Live App Builder "Inline Link Selectors" field (or a custom filter).
 *
 * Each selector here corresponds to a navigation surface where WooCommerce
 * reuses the SAME page template across links. Pushing a fresh subscreen on
 * tap breaks the flow (loses cart context, re-runs Woo's expensive template
 * bootstrap, strips URL params). Staying in-screen keeps the session + SPA
 * feel users expect from a mobile app.
 */
class Inline_Links_Controller extends Base_Controller {

	protected function hooks() {
		$this->filter( 'appress/app/inline_link_selectors', '@contribute_selectors', 10, 2 );
	}

	/**
	 * @param string[] $selectors Upstream list (admin-entered + other integrations).
	 * @param int      $app_id
	 * @return string[]
	 */
	protected function contribute_selectors( $selectors, $app_id ) {
		$woo = [
			// My Account dashboard sidebar — Orders / Downloads / Addresses /
			// Payment methods / Account details / Logout. All render on the
			// same `/my-account/` template with different endpoint content;
			// subscreen pushing loses the active-tab highlight + breaks the
			// logout nonce because the new view doesn't inherit the previous
			// page's referer.
			'.woocommerce-MyAccount-navigation a',

			// Single-product tabs (Description / Additional information /
			// Reviews). These are in-page anchor navigation — switching tabs
			// should NEVER pop a new screen.
			'.wc-tabs a',
			'.woocommerce-tabs .tabs a',

			// Variable-product swatches that use `<a>` for "pick a variant".
			// Same product page, different variation — must stay inline.
			'.variations_form .variations a',
		];

		return array_merge( (array) $selectors, $woo );
	}
}
