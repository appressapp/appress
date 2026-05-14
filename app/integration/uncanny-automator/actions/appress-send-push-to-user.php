<?php

namespace Appress\Integration\Uncanny_Automator;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action: send a Firebase push notification to a specific user (by ID).
 * Recipe author supplies target app + title/body + optional URL/image.
 */
class APPRESS_SEND_PUSH_TO_USER extends \Uncanny_Automator\Recipe\Action {

	protected function setup_action() {
		$this->set_integration( 'APPRESS' );
		$this->set_action_code( 'APPRESS_SEND_PUSH_TO_USER' );
		$this->set_action_meta( 'APPRESSPUSHTOUSER' );
		$this->set_is_pro( false );
		$this->set_requires_user( true );

		$this->set_sentence(
			sprintf(
				/* translators: %s: action meta */
				esc_attr_x( 'Send a push notification {{to the user:%s}}', 'Appress', 'appress' ),
				$this->get_action_meta()
			)
		);
		$this->set_readable_sentence( esc_attr_x( 'Send a push notification to the user', 'Appress', 'appress' ) );
	}

	public function options() {
		return [
			\Automator()->helpers->recipe->field->select_field_args( [
				'option_code' => 'APP_ID',
				'label'       => esc_attr_x( 'Target App', 'Appress', 'appress' ),
				'required'    => true,
				'options'     => $this->get_app_options(),
			] ),
			\Automator()->helpers->recipe->field->text( [
				'option_code' => 'TITLE',
				'label'       => esc_attr_x( 'Notification title', 'Appress', 'appress' ),
				'input_type'  => 'text',
				'required'    => true,
			] ),
			\Automator()->helpers->recipe->field->text( [
				'option_code'  => 'BODY',
				'label'        => esc_attr_x( 'Notification body', 'Appress', 'appress' ),
				'input_type'   => 'textarea',
				'supports_tokens' => true,
				'required'     => true,
			] ),
			\Automator()->helpers->recipe->field->text( [
				'option_code' => 'URL',
				'label'       => esc_attr_x( 'Click URL', 'Appress', 'appress' ),
				'input_type'  => 'url',
				'required'    => false,
			] ),
			\Automator()->helpers->recipe->field->text( [
				'option_code' => 'IMAGE',
				'label'       => esc_attr_x( 'Image URL', 'Appress', 'appress' ),
				'input_type'  => 'url',
				'required'    => false,
			] ),
		];
	}

	private function get_app_options() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, app_name FROM {$wpdb->prefix}appress_apps ORDER BY id ASC", ARRAY_A );
		$opts = [];
		foreach ( (array) $rows as $row ) {
			$opts[] = [ 'value' => (string) $row['id'], 'text' => $row['app_name'] ];
		}
		return $opts;
	}

	protected function process_action( $user_id, $action_data, $recipe_id, $args, $parsed ) {
		$app_id = isset( $parsed[ 'APP_ID' ] ) ? (int) $parsed[ 'APP_ID' ] : 0;
		$title  = isset( $parsed[ 'TITLE' ] ) ? (string) $parsed[ 'TITLE' ] : '';
		$body   = isset( $parsed[ 'BODY' ] ) ? (string) $parsed[ 'BODY' ] : '';
		$url    = isset( $parsed[ 'URL' ] ) ? (string) $parsed[ 'URL' ] : '';
		$image  = isset( $parsed[ 'IMAGE' ] ) ? (string) $parsed[ 'IMAGE' ] : '';

		if ( $app_id <= 0 || $user_id <= 0 || ( $title === '' && $body === '' ) ) {
			$this->add_log_error( 'Missing app, user, or content.' );
			return false;
		}

		try {
			$payload = [ 'title' => $title, 'body' => $body, 'image' => $image, 'url' => $url ];
			// One call runs the full pipeline: persist feed row →
			// resolve FCM tokens → multicast → fire dispatched hook.
			// Matches the path every other integration (WooCommerce,
			// Voxel, Broadcast) funnels through.
			$res = \Appress\Notification::send_to( $user_id, $app_id, $title, $body, $payload );
			if ( $res['tokens_sent'] === 0 ) {
				$this->add_log_error( 'No FCM tokens for this user/app.' );
				return false;
			}
		} catch ( \Exception $e ) {
			$this->add_log_error( $e->getMessage() );
			return false;
		}

		return true;
	}
}
