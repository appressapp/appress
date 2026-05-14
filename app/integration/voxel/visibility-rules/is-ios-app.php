<?php

namespace Appress\Integration\Voxel\Visibility_Rules;

if ( ! defined('ABSPATH') ) {
	exit;
}

class Is_IOS_App extends \Voxel\Dynamic_Data\Visibility_Rules\Base_Visibility_Rule {

	public function get_type(): string {
		return 'appress:is_ios';
	}

	public function get_label(): string {
		return _x( 'Is inside iOS App', 'visibility rules', 'appress' );
	}

	protected function define_args(): void {
		$this->define_arg( 'app_id', [
			'type' => 'text',
			'label' => _x( 'App ID (leave empty for any app)', 'visibility rules', 'appress' ),
			'value' => '',
		] );
	}

	public function evaluate(): bool {
		$app_id = intval( $this->get_arg( 'app_id' ) ?: 0 );
		return \Appress\is_ios( $app_id );
	}
}
