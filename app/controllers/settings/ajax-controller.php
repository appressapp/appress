<?php
namespace Appress\Controllers\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX surface for the Settings page. Site-wide config persists to
 * `appress_settings.site_settings` — flat shape with one sub-array
 * per section (`smart_banner`, future: `universal_links`, `debug`).
 *
 * Save handler whitelists known sections to keep the option blob
 * structured even when a stale Vue bundle posts removed keys.
 */
class Ajax_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->on( 'appress_ajax_settings.get',  '@handle_get' );
		$this->on( 'appress_ajax_settings.save', '@handle_save' );
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

	protected function handle_get() {
		try {
			$this->require_admin();
			$saved = \Appress\get( 'site_settings', [] );
			if ( ! is_array( $saved ) ) {
				$saved = [];
			}
			return wp_send_json([
				'success' => true,
				'data'    => (object) $saved,
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}

	/**
	 * Save handler. Single `wp_unslash($_POST)` at the entry point
	 * (see CLAUDE.md feedback memory: never re-apply downstream).
	 * Each section runs through its own `sanitize_*_section` so
	 * future sections add a method, not a branch in a giant switch.
	 */
	protected function handle_save() {
		try {
			$this->require_admin();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$body = wp_unslash( $_POST );
			if ( ! is_array( $body ) ) {
				throw new \Exception( esc_html__( 'Invalid payload.', 'appress' ) );
			}

			$saved = \Appress\get( 'site_settings', [] );
			if ( ! is_array( $saved ) ) {
				$saved = [];
			}

			// Smart Banner section ----------------------------------------
			if ( isset( $body['smart_banner'] ) && is_array( $body['smart_banner'] ) ) {
				$saved['smart_banner'] = $this->sanitize_smart_banner( $body['smart_banner'] );
			}

			// QR Login section --------------------------------------------
			if ( isset( $body['qr_login'] ) && is_array( $body['qr_login'] ) ) {
				$saved['qr_login'] = $this->sanitize_qr_login( $body['qr_login'] );
			}

			\Appress\set( 'site_settings', $saved );

			return wp_send_json([
				'success' => true,
				'message' => esc_html__( 'Settings saved.', 'appress' ),
				'data'    => (object) $saved,
			]);
		} catch ( \Exception $e ) {
			return wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
		}
	}

	private function sanitize_smart_banner( array $raw ): array {
		return [
			'enabled' => ! empty( $raw['enabled'] ),
			'app_id'  => isset( $raw['app_id'] ) ? max( 0, (int) $raw['app_id'] ) : 0,
		];
	}

	private function sanitize_qr_login( array $raw ): array {
		return [
			'enabled' => ! empty( $raw['enabled'] ),
		];
	}
}
