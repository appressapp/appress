<?php

namespace Appress\Integration\Uncanny_Automator;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trigger: user returns after being away X+ days. Single editor
 * holding both App selector + Days number. >= match so a 40-day
 * return satisfies recipes at both X=7 and X=30 (escalating rewards stack).
 */
class APPRESS_USER_RETURNED extends \Uncanny_Automator\Recipe\Trigger {

	protected function setup_trigger() {
		$this->set_integration( 'APPRESS' );
		$this->set_trigger_code( 'APPRESS_USER_RETURNED' );
		$this->set_trigger_meta( 'APPRESS_USER_RETURNED_DAYS' );
		$this->set_is_login_required( true );
		$this->set_sentence(
			sprintf(
				/* translators: %s: days input */
				esc_attr_x( 'A user returns to an Appress app after {{X+ days:%s}} away', 'Appress', 'appress' ),
				$this->get_trigger_meta()
			)
		);
		$this->set_readable_sentence( esc_attr_x( 'A user returns to an Appress app after {{X+ days}} away', 'Appress', 'appress' ) );

		$this->add_action( 'appress/automator/user_returned', 10, 3 );
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
				'label'         => esc_attr_x( 'Minimum days away', 'Appress', 'appress' ),
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
		$configured_app  = isset( $trigger['meta']['APP_ID'] ) ? (int) $trigger['meta']['APP_ID'] : 0;
		$gap_days        = isset( $hook_args[1] ) ? (int) $hook_args[1] : 0;
		$fired_app       = isset( $hook_args[2] ) ? (int) $hook_args[2] : 0;

		if ( $configured_days <= 0 || $gap_days < $configured_days ) {
			return false;
		}
		return $configured_app === 0 || $configured_app === $fired_app;
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
