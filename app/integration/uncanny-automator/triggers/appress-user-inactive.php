<?php

namespace Appress\Integration\Uncanny_Automator;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trigger: user inactive for exactly X days.
 * Single editor holding both App selector + Days number.
 */
class APPRESS_USER_INACTIVE extends \Uncanny_Automator\Recipe\Trigger {

	protected function setup_trigger() {
		$this->set_integration( 'APPRESS' );
		$this->set_trigger_code( 'APPRESS_USER_INACTIVE' );
		$this->set_trigger_meta( 'APPRESS_USER_INACTIVE_DAYS' );
		$this->set_is_login_required( true );
		$this->set_sentence(
			sprintf(
				/* translators: %s: days input */
				esc_attr_x( 'A user has not opened an Appress app for {{X days:%s}}', 'Appress', 'appress' ),
				$this->get_trigger_meta()
			)
		);
		$this->set_readable_sentence( esc_attr_x( 'A user has not opened an Appress app for {{X days}}', 'Appress', 'appress' ) );

		$this->add_action( 'appress/automator/user_inactive', 10, 2 );
	}

	public function options() {
		return [
			\Automator()->helpers->recipe->field->select( [
				'option_code' => 'APP_ID',
				'label'       => esc_attr_x( 'Appress app', 'Appress', 'appress' ),
				'required'    => true,
				'options'     => $this->get_app_options(),
			] ),
			\Automator()->helpers->recipe->field->text( [
				'option_code'   => $this->get_trigger_meta(),
				'label'         => esc_attr_x( 'Days inactive', 'Appress', 'appress' ),
				'input_type'    => 'int',
				'required'      => true,
				'default_value' => 7,
			] ),
		];
	}

	public function validate( $trigger, $hook_args ) {
		if ( ! isset( $trigger['meta'][ $this->get_trigger_meta() ] ) ) {
			return false;
		}
		$configured_days = (int) $trigger['meta'][ $this->get_trigger_meta() ];
		$fired_days      = isset( $hook_args[1] ) ? (int) $hook_args[1] : 0;
		return $configured_days > 0 && $fired_days === $configured_days;
	}

	private function get_app_options() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, app_name FROM {$wpdb->prefix}appress_apps ORDER BY id ASC", ARRAY_A );
		$opts = [ [ 'value' => '0', 'text' => esc_attr_x( 'Any app', 'Appress', 'appress' ) ] ];
		foreach ( (array) $rows as $row ) {
			$opts[] = [ 'value' => (string) $row['id'], 'text' => $row['app_name'] ];
		}
		return $opts;
	}
}
