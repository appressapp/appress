<?php

namespace Appress\Controllers\Integrations;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX surface for the Integrations page's user-facing on/off state.
 *
 *   integrations.get_modules  → { success, data: { woocommerce: true, voxel: false, … } }
 *   integrations.save_modules → POST JSON body of the same shape; persists
 *                           to `appress_settings.modules` and Integrations_Manager
 *                           picks it up on the next request to fire
 *                           `appress/integration/{id}/execute` for enabled ids.
 *
 * Vue reloads the page after save because activating an integration
 * typically registers new admin menus / assets that only appear after
 * a fresh boot.
 */
class Ajax_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->on( 'appress_ajax_integrations.get_modules',  '@handle_get_modules' );
		$this->on( 'appress_ajax_integrations.save_modules', '@handle_save_modules' );
	}

	private function require_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( esc_html__( 'Permission denied.', 'appress' ) );
		}
		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'appress_admin_action' ) ) {
			throw new \Exception( esc_html__( 'Security verification failed.', 'appress' ) );
		}
	}

	protected function handle_get_modules() {
		try {
			$this->require_admin();
			$modules = \Appress\get( 'modules', [] );
			if ( ! is_array( $modules ) ) {
				$modules = [];
			}
			return wp_send_json([
				'success' => true,
				'data'    => $modules,
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}

	protected function handle_save_modules() {
		try {
			$this->require_admin();
			// Form-urlencoded body (`postForm` from Vue admin) — see
			// `apps-controller@delete_app` for the full WAF-compat note.
			// Nonce verified inside require_admin() above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$body = wp_unslash( $_POST );
			if ( ! is_array( $body ) ) {
				throw new \Exception( esc_html__( 'Invalid payload.', 'appress' ) );
			}

			// Whitelist against the currently registered integration ids so a
			// stale or malicious payload can't persist arbitrary keys into
			// the options table.
			$registered = \Appress\config( 'integrations' );
			if ( ! is_array( $registered ) ) {
				$registered = [];
			}
			$registered = apply_filters( 'appress/integrations/registered', $registered );

			$normalized = [];
			foreach ( $registered as $id => $entry ) {
				$normalized[ (string) $id ] = ! empty( $body[ $id ] );
			}

			\Appress\set( 'modules', $normalized );

			return wp_send_json([
				'success' => true,
				'message' => esc_html__( 'Integrations saved.', 'appress' ),
				'data'    => $normalized,
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}
}
