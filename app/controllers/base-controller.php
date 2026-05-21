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

	/**
	 * Register a handler for an AJAX action that's reachable from the MOBILE
	 * APP (one of the customer's connected apps on this site). Fires every
	 * registered app's per-class hook so each app's `?<class_id>=1&action=…`
	 * URL hits the same handler.
	 *
	 * Hooks registered:
	 *   - `{<class_id>}_ajax_nopriv_{$action}` and `{<class_id>}_ajax_{$action}`
	 *     for every app's `unique_class`.
	 *   - `appress_ajax_nopriv_{$action}` and `appress_ajax_{$action}` for
	 *     backward compat with mobile apps built before unique_class shipped
	 *     (they still hit the legacy `?appress=1` URL).
	 *
	 * NOTE: admin-only actions (Vue Builder, settings page, etc.) must keep
	 * using plain `$this->on( 'appress_ajax_<action>', … )` — they should NOT
	 * be reachable from mobile-app URL shapes, which is the whole point of
	 * segregating per-app endpoints from admin endpoints.
	 *
	 * Calls `\Appress\get_apps_class()` at registration time; the helper
	 * caches the list so multi-controller registration costs exactly one DB
	 * round-trip per request. When apps are added/removed AFTER controllers
	 * register (rare — admins normally save apps and the router picks them
	 * up next request), `clear_apps_class_cache()` is called on every
	 * relevant DB mutation so the next request resolves the fresh list.
	 *
	 * @param string $action            Action name (e.g. "auth.google.login").
	 * @param string $function_to_add   Callable or "@method_name" reference.
	 * @param int    $priority          add_action priority.
	 * @param int    $accepted_args     Args count passed to handler.
	 */
	protected function on_mobile( $action, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		foreach ( \Appress\get_apps_class() as $class_id ) {
			$this->on( "{$class_id}_ajax_nopriv_{$action}", $function_to_add, $priority, $accepted_args );
			$this->on( "{$class_id}_ajax_{$action}",        $function_to_add, $priority, $accepted_args );
		}
		// Backward compat: mobile apps built before unique_class still hit
		// the legacy `?appress=1` URL → same hook prefix as Vue admin. Keep
		// listening so those older installs continue working through the
		// transition window. Drop this pair once every customer has rebuilt.
		$this->on( "appress_ajax_nopriv_{$action}", $function_to_add, $priority, $accepted_args );
		$this->on( "appress_ajax_{$action}",        $function_to_add, $priority, $accepted_args );
	}
}
