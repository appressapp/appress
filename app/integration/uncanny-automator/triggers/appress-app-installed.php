<?php

namespace Appress\Integration\Uncanny_Automator;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trigger: user installs + opens the Appress app for the first time.
 * Single pill: app selector.
 */
class APPRESS_APP_INSTALLED extends \Uncanny_Automator\Recipe\Trigger {

	protected function setup_trigger() {
		$this->set_integration( 'APPRESS' );
		$this->set_trigger_code( 'APPRESS_APP_INSTALLED' );
		$this->set_trigger_meta( 'APPRESS_APP_INSTALLED_APP' );
		$this->set_is_login_required( true );
		$this->set_sentence(
			sprintf(
				/* translators: %1$s: app selector */
				esc_attr_x( 'A user installs {{an Appress app:%1$s}} for the first time', 'Appress', 'appress' ),
				$this->get_trigger_meta()
			)
		);
		$this->set_readable_sentence( esc_attr_x( 'A user installs {{an Appress app}} for the first time', 'Appress', 'appress' ) );

		$this->add_action( 'appress/user/installed_app', 10, 3 );
	}

	public function options() {
		return [
			\Automator()->helpers->recipe->field->select( [
				'option_code' => $this->get_trigger_meta(),
				'label'       => esc_attr_x( 'Appress app', 'Appress', 'appress' ),
				'required'    => true,
				'options'     => $this->get_app_options(),
			] ),
		];
	}

	public function validate( $trigger, $hook_args ) {
		if ( ! isset( $trigger['meta'][ $this->get_trigger_meta() ] ) ) {
			return false;
		}
		$configured = (int) $trigger['meta'][ $this->get_trigger_meta() ];
		$fired_app  = isset( $hook_args[1] ) ? (int) $hook_args[1] : 0;
		return $configured === 0 || $configured === $fired_app;
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
