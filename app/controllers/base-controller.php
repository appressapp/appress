<?php

namespace Appress\Controllers;

if ( ! defined('ABSPATH') ) {
	exit;
}

abstract class Base_Controller {

	public function __construct() {
		if ( $this->authorize() ) {
			$this->dependencies();
			$this->hooks();
		}
	}

	/**
	 * Add controller hooks (actions, filters, etc.)
	 */
	abstract protected function hooks();

	/**
	 * Load controller dependencies (classes, files, etc.)
	 */
	protected function dependencies() {
		// Override in child class if needed
	}

	/**
	 * Determine whether the controller should be loaded.
	 */
	protected function authorize() {
		return true; // Default to always load, override if needed
	}

	/**
	 * Wrapper for `add_filter` which allows using protected
	 * methods as filter callbacks via @method_name syntax.
	 */
	protected function filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1, $run_once = false ) {
		if ( is_string( $function_to_add ) && substr( $function_to_add, 0, 1 ) === '@' ) {
			$method_name = substr( $function_to_add, 1 );
			add_filter( $tag, function() use ( $method_name, $run_once ) {
				static $ran = false;
				if ( $run_once && $ran === true ) {
					return;
				}

				$ran = true;
				// call_user_func_array([$this, ltrim($method_name, '@')], func_get_args())
				return $this->{$method_name}( ...func_get_args() );
			}, $priority, $accepted_args );
		} else {
			add_filter( $tag, $function_to_add, $priority, $accepted_args );
		}
	}

	/**
	 * Wrapper for `add_action` using the filter wrapper logic.
	 */
	protected function on( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		$this->filter( $tag, $function_to_add, $priority, $accepted_args );
	}

	/**
	 * Allows for adding an action hook that only runs once.
	 */
	protected function once( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		$this->filter( $tag, $function_to_add, $priority, $accepted_args, true );
	}
}
