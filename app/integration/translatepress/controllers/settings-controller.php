<?php

namespace Appress\Integration\Translatepress\Controllers;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TranslatePress integration — admin-side cache control.
 *
 * The per-app enable flag + bottom-nav label translation strings
 * live in the app's `build_config.translatepress` block now (managed
 * by the per-app TranslatePress sub-tab inside `SingleAppView.vue`).
 * The normal `app.save` flow persists them alongside the rest of the
 * app config and bumps `live_config.update_time_hash` automatically,
 * so the boot config cache invalidates on every settings save with
 * no extra wiring.
 *
 * This controller exists for ONE side-action: a "Clear boot cache"
 * button in the TranslatePress sub-tab that admins press when they
 * edit TRP's own dictionary / slug overrides OUTSIDE Appress and
 * want the host preview app to pick up the change immediately
 * (instead of waiting for the next regular save).
 */
class Settings_Controller extends Base_Controller {

	protected function hooks() {
		$this->on( 'appress_ajax_integrations.translatepress.clear_cache', '@handle_clear_cache' );
	}

	protected function handle_clear_cache() {
		try {
			$this->require_admin();
			$app_id = isset( $_POST['app_id'] ) ? (int) $_POST['app_id'] : 0;
			if ( $app_id <= 0 ) {
				throw new \Exception( esc_html__( 'Invalid app id.', 'appress' ) );
			}
			// "Clear cache" = bump version hash. Next boot fetch from any
			// client sees the mismatch, Boot_Config_Controller rebuilds
			// its per-(app, lang) compiled mutation set inline, and the
			// stale data is overwritten in place. No need to manually
			// delete option rows.
			self::bump_app_version_hash( $app_id );
			return wp_send_json( [
				'success' => true,
				'message' => esc_html__( 'Cache cleared.', 'appress' ),
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [ 'success' => false, 'message' => $e->getMessage() ] );
		}
	}

	private function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new \Exception( esc_html__( 'Permission denied.', 'appress' ) );
		}
		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'appress_admin_action' ) ) {
			throw new \Exception( esc_html__( 'Security verification failed.', 'appress' ) );
		}
	}

	/**
	 * Rotate the app row's `live_config.update_time_hash`. Matches the
	 * pattern in Apps_Controller::handle_save_config — single source
	 * of truth for the "config has changed" signal that the
	 * Frontend_Controller boot endpoint reads.
	 */
	private static function bump_app_version_hash( int $app_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'appress_apps';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT build_config FROM {$table} WHERE id = %d", $app_id ),
			ARRAY_A
		);
		if ( empty( $row ) ) {
			return;
		}
		$live_config = ! empty( $row['build_config'] ) ? json_decode( $row['build_config'], true ) : [];
		if ( ! is_array( $live_config ) ) {
			$live_config = [];
		}
		$live_config['update_time_hash'] = (string) time();
		$wpdb->update(
			$table,
			[ 'build_config' => wp_json_encode( $live_config ) ],
			[ 'id' => $app_id ]
		);
	}
}
