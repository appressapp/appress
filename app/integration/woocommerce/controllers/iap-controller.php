<?php
namespace Appress\Integration\Woocommerce\Controllers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.Security.NonceVerification.Recommended

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce → In-App Purchase admin surface.
 *
 * Stores per-product IAP configuration in WC product meta — no new DB
 * table, no sync layer. The admin UI (Vue panel on the Integrations →
 * WooCommerce → In-App Purchase tab) reads + writes these via the
 * AJAX endpoints below; the IAP payment gateway + verify endpoint
 * (wired elsewhere when native StoreKit / Play Billing ships) read
 * the same meta at runtime to decide which products must route
 * through IAP.
 *
 * Meta schema:
 *   _appress_iap_required           bool   — "this product MUST use
 *                                             IAP when opened in-app"
 *   _appress_iap_ios_product_id     string — App Store Connect id
 *                                             (e.g. com.myapp.pro_month)
 *   _appress_iap_android_product_id string — Google Play Console id
 *   _appress_iap_kind               enum   — consumable | non_consumable
 *                                             | subscription
 *
 * `_appress_iap_required` is the single flag the rest of the system
 * reads; a product with `required = false` is shown in the list only
 * for audit purposes. Removing a product's mapping deletes all four
 * meta keys in one shot.
 */
class Iap_Controller extends \Appress\Controllers\Base_Controller {

	const META_REQUIRED    = '_appress_iap_required';
	const META_IOS_ID      = '_appress_iap_ios_product_id';
	const META_ANDROID_ID  = '_appress_iap_android_product_id';
	const META_KIND        = '_appress_iap_kind';

	protected function hooks() {
		$this->on( 'appress_ajax_woocommerce.iap.search_products', '@handle_search_products' );
		$this->on( 'appress_ajax_woocommerce.iap.list_mappings',   '@handle_list_mappings' );
		$this->on( 'appress_ajax_woocommerce.iap.save_mapping',    '@handle_save_mapping' );
		$this->on( 'appress_ajax_woocommerce.iap.remove_mapping',  '@handle_remove_mapping' );
	}

	// ── Auth ─────────────────────────────────────────────────────────────

	private function require_admin() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( 'Permission denied.' );
		}
		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'appress_admin_action' ) ) {
			throw new \Exception( 'Security verification failed.' );
		}
	}

	// ── Endpoints ────────────────────────────────────────────────────────

	/**
	 * Live search over the WooCommerce product catalogue for the add-
	 * product autocomplete. Returns shallow records (id + title + sku +
	 * price) — enough for the dropdown without loading a full WC_Product
	 * per row. Excludes products that already have a mapping so the
	 * dropdown never offers duplicates.
	 */
	protected function handle_search_products() {
		try {
			$this->require_admin();

			$q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
			$limit = isset( $_GET['limit'] ) ? min( 50, max( 1, intval( $_GET['limit'] ) ) ) : 15;

			$args = [
				'post_type'      => [ 'product', 'product_variation' ],
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => $limit,
				's'              => $q,
				'orderby'        => 'relevance',
				'fields'         => 'ids',
				// Skip products that already have a mapping so the add
				// dropdown doesn't re-list what the admin already picked.
				// Admin-only operation, bounded by `$limit` (50 max),
				// runs once per admin search keystroke — slow-query risk
				// is acceptable for the low call frequency.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [
					[
						'key'     => self::META_REQUIRED,
						'compare' => 'NOT EXISTS',
					],
				],
			];

			// `relevance` only kicks in when `s` is set; empty query
			// falls back to date DESC via WP default so the admin
			// sees the most-recent unmapped products first.
			if ( $q === '' ) {
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
			}

			$query = new \WP_Query( $args );
			$results = [];
			foreach ( $query->posts as $product_id ) {
				$results[] = $this->product_summary( (int) $product_id );
			}

			return wp_send_json([
				'success' => true,
				'data'    => array_filter( $results ),
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}

	/**
	 * All products currently flagged with `_appress_iap_required`. This
	 * is the authoritative list the Vue panel binds to — the admin
	 * sees their existing mappings here, edits inline, and the save
	 * endpoint rewrites one at a time.
	 */
	protected function handle_list_mappings() {
		try {
			$this->require_admin();

			$query = new \WP_Query([
				'post_type'      => [ 'product', 'product_variation' ],
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				// Admin-only mappings list, max 200 results, called once per
				// page load of the IAP settings panel.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [
					[
						'key'     => self::META_REQUIRED,
						'compare' => 'EXISTS',
					],
				],
			]);

			$rows = [];
			foreach ( $query->posts as $product_id ) {
				$pid = (int) $product_id;
				$summary = $this->product_summary( $pid );
				if ( ! $summary ) {
					continue;
				}
				$rows[] = array_merge( $summary, [
					'required'          => (bool) get_post_meta( $pid, self::META_REQUIRED, true ),
					'ios_product_id'    => (string) get_post_meta( $pid, self::META_IOS_ID, true ),
					'android_product_id'=> (string) get_post_meta( $pid, self::META_ANDROID_ID, true ),
					'kind'              => (string) ( get_post_meta( $pid, self::META_KIND, true ) ?: 'non_consumable' ),
				] );
			}

			return wp_send_json([
				'success' => true,
				'data'    => $rows,
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}

	/**
	 * Upsert the four meta values for a product. `required = false` is
	 * allowed + persisted (admins sometimes keep a mapping around with
	 * the switch off for audit). Call `remove_mapping` to wipe the row
	 * entirely.
	 */
	protected function handle_save_mapping() {
		try {
			$this->require_admin();

			// Form-urlencoded body (`postForm` from Vue admin) — see
			// `apps-controller@delete_app` for the full WAF-compat note.
			// Nonce verified inside require_admin() above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$body = wp_unslash( $_POST );
			if ( ! is_array( $body ) ) {
				throw new \Exception( 'Invalid payload.' );
			}

			$product_id = isset( $body['product_id'] ) ? intval( $body['product_id'] ) : 0;
			if ( $product_id <= 0 ) {
				throw new \Exception( 'Missing product_id.' );
			}
			if ( get_post_type( $product_id ) !== 'product' && get_post_type( $product_id ) !== 'product_variation' ) {
				throw new \Exception( 'Product not found.' );
			}

			$allowed_kinds = [ 'consumable', 'non_consumable', 'subscription' ];
			$kind = isset( $body['kind'] ) ? sanitize_text_field( $body['kind'] ) : 'non_consumable';
			if ( ! in_array( $kind, $allowed_kinds, true ) ) {
				$kind = 'non_consumable';
			}

			update_post_meta( $product_id, self::META_REQUIRED,    ! empty( $body['required'] ) ? 1 : 0 );
			update_post_meta( $product_id, self::META_IOS_ID,      isset( $body['ios_product_id'] ) ? sanitize_text_field( $body['ios_product_id'] ) : '' );
			update_post_meta( $product_id, self::META_ANDROID_ID,  isset( $body['android_product_id'] ) ? sanitize_text_field( $body['android_product_id'] ) : '' );
			update_post_meta( $product_id, self::META_KIND,        $kind );

			return wp_send_json([
				'success' => true,
				'message' => 'Mapping saved.',
				'data'    => [ 'product_id' => $product_id ],
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}

	protected function handle_remove_mapping() {
		try {
			$this->require_admin();

			// Form-urlencoded body (`postForm` from Vue admin) — see
			// `apps-controller@delete_app` for the full WAF-compat note.
			// Nonce verified inside require_admin() above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$body = wp_unslash( $_POST );
			$product_id = isset( $body['product_id'] ) ? intval( $body['product_id'] ) : 0;
			if ( $product_id <= 0 ) {
				throw new \Exception( 'Missing product_id.' );
			}

			delete_post_meta( $product_id, self::META_REQUIRED );
			delete_post_meta( $product_id, self::META_IOS_ID );
			delete_post_meta( $product_id, self::META_ANDROID_ID );
			delete_post_meta( $product_id, self::META_KIND );

			return wp_send_json([ 'success' => true, 'message' => 'Mapping removed.' ]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Build a shallow product record from an id — safe without WC being
	 * active (falls back to `get_post` + post meta). Returns null for
	 * unknown ids so list endpoints can filter them out of the response.
	 */
	private function product_summary( int $product_id ): ?array {
		$post = get_post( $product_id );
		if ( ! $post || ( $post->post_type !== 'product' && $post->post_type !== 'product_variation' ) ) {
			return null;
		}

		$sku = '';
		$price = '';
		if ( function_exists( 'wc_get_product' ) ) {
			$wc_product = wc_get_product( $product_id );
			if ( $wc_product ) {
				$sku = (string) $wc_product->get_sku();
				$price = (string) $wc_product->get_price_html();
			}
		}
		if ( $sku === '' ) {
			$sku = (string) get_post_meta( $product_id, '_sku', true );
		}

		$thumb_id = get_post_thumbnail_id( $product_id );
		$thumb    = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';

		return [
			'id'    => $product_id,
			'title' => (string) get_the_title( $product_id ),
			'sku'   => $sku,
			'price' => wp_strip_all_tags( $price ),
			'thumb' => $thumb,
			'type'  => $post->post_type,
		];
	}
}
