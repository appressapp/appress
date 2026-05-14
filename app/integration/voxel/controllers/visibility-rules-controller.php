<?php

namespace Appress\Integration\Voxel\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visibility_Rules_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->on( 'voxel/dynamic-data/visibility-rules', '@register_visibility_rules' );
	}

	public function register_visibility_rules( $rules ) {
		$rules['appress:is_app']     = \Appress\Integration\Voxel\Visibility_Rules\Is_Appress_App::class;
		$rules['appress:is_ios']     = \Appress\Integration\Voxel\Visibility_Rules\Is_IOS_App::class;
		$rules['appress:is_android'] = \Appress\Integration\Voxel\Visibility_Rules\Is_Android_App::class;

		return $rules;
	}
}
