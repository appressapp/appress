<?php

namespace Appress\Controllers\Screens;

// phpcs:disable WordPress.Security.NonceVerification.Missing
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
if (! defined('ABSPATH')) {
	exit;
}

class Ajax_Controller extends \Appress\Controllers\Base_Controller
{
	protected function hooks()
	{
		$this->on('appress_ajax_screens.get_list', '@get_list');
		$this->on('appress_ajax_screens.create', '@create_screen');
		$this->on('appress_ajax_screens.update', '@update_screen');
		$this->on('appress_ajax_screens.delete', '@delete_screen');
	}

	/**
	 * Shared cap + CSRF gate. Mirrors the pattern in broadcast/events
	 * admin controllers — every mutating endpoint must call this first.
	 * get_list is read-only but still admin-only so it calls the cap
	 * check directly (skips nonce since no state change).
	 */
	protected function check_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( esc_html__( 'Unauthorized access.', 'appress' ) );
		}
		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'appress_admin_action' ) ) {
			throw new \Exception( esc_html__( 'Security verification failed. Please refresh and try again.', 'appress' ) );
		}
	}

	public function get_list()
	{
		try {
			if (! current_user_can('manage_options')) {
				throw new \Exception( esc_html__( 'Unauthorized access.', 'appress' ) );
			}

			// Pull IDs only — get_posts with default fields loads every post
			// column into memory. On installs with hundreds of screens this
			// blows memory + SQL payload for no benefit (we only need id
			// + title + permalink).
			$ids = get_posts([
				'post_type'      => 'appress_screen',
				'posts_per_page' => -1,
				'post_status'    => ['publish', 'draft'],
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			]);

			$screens = [];
			foreach ($ids as $id) {
				$screens[] = [
					'id'    => $id,
					'title' => get_the_title($id),
					'url'   => get_permalink($id),
				];
			}

			return wp_send_json([
				'success' => true,
				'data'    => $screens,
			]);
		} catch (\Exception $e) {
			return wp_send_json([
				'success' => false,
				'message' => $e->getMessage()
			]);
		}
	}

	/**
	 * Create-or-resolve an `appress_screen` post.
	 *
	 * Behaves as "ensure exists" so the live-config's Edit UI button
	 * can be unconditional — the client doesn't know whether `wp_id` on
	 * the screen item points at a live post or a stale id (deleted /
	 * trashed). Pass the row's `wp_id` when present:
	 *
	 *   - Valid live `appress_screen` post → return its id + permalink
	 *     (no insert, `created=false`).
	 *   - Missing / wrong post-type / trashed → fall through and create
	 *     a fresh row with the provided title.
	 *
	 * Caller can rely on `data.id` always pointing at a usable edit
	 * target after this call.
	 */
	public function create_screen()
	{
		try {
			$this->check_permissions();

			$title       = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : 'New App Screen';
			$existing_id = isset($_POST['wp_id']) ? intval($_POST['wp_id']) : 0;

			if ($existing_id > 0) {
				$existing = get_post($existing_id);
				if ($existing && $existing->post_type === 'appress_screen' && $existing->post_status !== 'trash') {
					return wp_send_json([
						'success' => true,
						'message' => 'Screen already exists.',
						'data'    => [
							'id'       => $existing_id,
							'title'    => $existing->post_title,
							'url'      => get_permalink($existing_id),
							'edit_url' => admin_url('post.php?post=' . $existing_id . '&action=edit'),
							'created'  => false,
						],
					]);
				}
			}

			$post_id = wp_insert_post([
				'post_title'  => $title,
				'post_status' => 'publish', // Auto Publish
				'post_type'   => 'appress_screen',
			]);

			if (is_wp_error($post_id)) {
				throw new \Exception($post_id->get_error_message());
			}

			return wp_send_json([
				'success' => true,
				'message' => 'Screen created successfully.',
				'data'    => [
					'id'       => $post_id,
					'title'    => $title,
					'url'      => get_permalink($post_id),
					'edit_url' => admin_url('post.php?post=' . $post_id . '&action=edit'),
					'created'  => true,
				]
			]);
		} catch (\Exception $e) {
			return wp_send_json([
				'success' => false,
				'message' => $e->getMessage()
			]);
		}
	}

	public function update_screen()
	{
		try {
			$this->check_permissions();
			// Form-urlencoded body (`postForm` from Vue admin) — see
			// `apps-controller@delete_app` for the full WAF-compat note.
			$params = wp_unslash( $_POST );

			$post_id = intval($params['id'] ?? 0);
			$title   = isset($params['title']) ? sanitize_text_field($params['title']) : (isset($params['name']) ? sanitize_text_field($params['name']) : '');

			if (! $post_id || ! $title) {
				throw new \Exception( esc_html__( 'Screen ID and Title are required.', 'appress' ) );
			}

			$post = get_post( $post_id );
			if ( ! $post || $post->post_type !== 'appress_screen' ) {
				throw new \Exception( esc_html__( 'Screen post not found.', 'appress' ) );
			}

			// Regenerate the slug from the new title so the public URL of the
			// screen tracks its label. `wp_update_post` does NOT auto-rebuild
			// `post_name` when only `post_title` changes — without this, an
			// admin renaming "Home" → "Dashboard" would leave the slug stuck
			// at `home`, leading to mismatched URLs in app_screens config and
			// confused deep links. `wp_unique_post_slug` resolves any clash
			// with existing screens by appending `-2`, `-3`, etc.
			$base_slug   = sanitize_title( $title );
			$unique_slug = wp_unique_post_slug( $base_slug, $post_id, $post->post_status, 'appress_screen', 0 );

			wp_update_post([
				'ID'         => $post_id,
				'post_title' => $title,
				'post_name'  => $unique_slug,
			]);

			return wp_send_json([
				'success' => true,
				'message' => 'Screen renamed successfully.',
				'data'    => [
					'id'   => $post_id,
					'slug' => $unique_slug,
					'url'  => get_permalink( $post_id ),
				],
			]);
		} catch (\Exception $e) {
			return wp_send_json([
				'success' => false,
				'message' => $e->getMessage()
			]);
		}
	}

	public function delete_screen()
	{
		try {
			$this->check_permissions();
			// Form-urlencoded body (`postForm` from Vue admin) — see
			// `apps-controller@delete_app` for the full WAF-compat note.
			$params = wp_unslash( $_POST );

			$post_id = intval($params['id'] ?? 0);

			if (! $post_id) {
				throw new \Exception( esc_html__( 'Screen ID is required.', 'appress' ) );
			}

			wp_delete_post($post_id, true);

			return wp_send_json([
				'success' => true,
				'message' => 'Screen deleted successfully.',
			]);
		} catch (\Exception $e) {
			return wp_send_json([
				'success' => false,
				'message' => $e->getMessage()
			]);
		}
	}
}
