<?php

namespace Appress\Controllers\Posts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-only post search endpoint that powers the Content Source picker
 * in `PostSearchPicker.vue`. The picker lives in every "screen URL"
 * field across the app config (Home Screen, First Launch, Auth Gate,
 * Side Menus, Bottom Nav items) and lets admins resolve a permalink
 * by typing a post title instead of pasting URLs.
 *
 * Endpoint: `?appress=1&action=posts.search`
 *
 * Body (POST):
 *   - `post_type` — `any` (search every public CPT) or a specific slug
 *                   (`page`, `post`, `product`, `appress_screen`, …).
 *   - `q`         — search query. Empty string returns the most-recent
 *                   posts of the requested type so the picker can show
 *                   a useful default list before the admin types.
 *   - `per_page`  — optional cap (default 20, hard ceiling 50).
 *
 * Response:
 *   { success: true, data: [{ id, title, url, post_type }] }
 *
 * Auth: admin-only (`manage_options` + nonce). Returns trimmed result
 * shape only — no post body, no meta, no taxonomy data — so the endpoint
 * stays cheap even on stores with tens of thousands of products.
 */
class Ajax_Controller extends \Appress\Controllers\Base_Controller {

	const HARD_CEILING = 50;
	const DEFAULT_LIMIT = 20;

	protected function hooks() {
		$this->on( 'appress_ajax_posts.search', '@handle_search' );
	}

	protected function handle_search() {
		try {
			$this->check_permissions();

			$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'any';
			$query     = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
			$requested = isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : self::DEFAULT_LIMIT;
			$per_page  = max( 1, min( self::HARD_CEILING, $requested ) );

			$resolved_types = $this->resolve_post_types( $post_type );
			if ( empty( $resolved_types ) ) {
				return wp_send_json( [ 'success' => true, 'data' => [] ] );
			}

			// IDs-only fetch keeps memory + SQL payload small. Stores with
			// thousands of products would otherwise blow memory loading
			// every column on each picker keystroke.
			$args = [
				'post_type'      => $resolved_types,
				'posts_per_page' => $per_page,
				'post_status'    => [ 'publish' ],
				'orderby'        => $query !== '' ? 'relevance' : 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'suppress_filters' => false,
			];
			if ( $query !== '' ) {
				$args['s'] = $query;
			}

			$ids = get_posts( $args );

			$data = [];
			foreach ( (array) $ids as $id ) {
				$permalink = get_permalink( $id );
				if ( ! $permalink ) continue;
				$data[] = [
					'id'        => (int) $id,
					'title'     => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
					'url'       => (string) $permalink,
					'post_type' => (string) get_post_type( $id ),
				];
			}

			return wp_send_json( [ 'success' => true, 'data' => $data ] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}

	/**
	 * Resolve a picker-supplied post type request into the exact post
	 * type slugs `get_posts` should query. Source of truth =
	 * `Assets_Controller::collect_pickable_post_types()` — the same
	 * filtered list the admin's dropdown was built from (drops
	 * Elementor templates, WP block library, navigation menu items,
	 * theme templates, etc.). Without this, `any` would fall back to
	 * the raw `public => true` set and bleed template / utility CPTs
	 * into the result list.
	 *
	 *   - `any` → every entry in the pickable list
	 *   - specific slug → validated against the pickable list, returns
	 *     `[$slug]` when valid, `[]` (no results) when not (defends
	 *     against picker UI passing a stale slug for a deleted CPT or
	 *     a slug an integration filter just stripped).
	 */
	private function resolve_post_types( string $requested ): array {
		$pickable = array_column( \Appress\Controllers\Assets_Controller::collect_pickable_post_types(), 'slug' );
		if ( $requested === 'any' || $requested === '' ) {
			return $pickable;
		}
		return in_array( $requested, $pickable, true ) ? [ $requested ] : [];
	}

	private function check_permissions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( esc_html__( 'Permission denied.', 'appress' ) );
		}
		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'appress_admin_action' ) ) {
			throw new \Exception( esc_html__( 'Security verification failed.', 'appress' ) );
		}
	}
}
