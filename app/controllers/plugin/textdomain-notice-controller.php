<?php

namespace Appress\Controllers\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workaround for the WP 6.7+ `_load_textdomain_just_in_time` notice
 * that fires whenever the `appress` text domain is queried before
 * the `init` hook.
 *
 * Root cause: `app/config/schema.config.php` declares hundreds of
 * field labels via `__(..., 'appress')` at FILE SCOPE. The config
 * helper eager-requires that file on the first `\Appress\config()`
 * call, which happens during `plugins_loaded` (controller bootstrap)
 * — i.e. before `init`. WP 6.7 defers `load_plugin_textdomain` to
 * `init` internally, so there's no public seam to pre-load the
 * domain in time.
 *
 * Impact: zero — WP's just-in-time loader still resolves
 * translations correctly. The notice is purely informational. We
 * suppress it to keep `debug.log` readable; a proper fix
 * (lazy schema evaluation) is tracked separately.
 *
 * Scope: ONLY the `appress` domain. Any other plugin's textdomain
 * notice is left untouched so debug.log still surfaces THEIR JIT
 * loading bugs.
 */
class Textdomain_Notice_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->filter( 'doing_it_wrong_trigger_error', '@suppress', 10, 3 );
	}

	/**
	 * WP wraps the offending domain name in `<code>…</code>` inside
	 * the message string, NOT single quotes. The exact substring
	 * match avoids accidentally swallowing notices for similarly-
	 * named domains (e.g. `appress-pro`).
	 */
	protected function suppress( $trigger, $function_name, $message ) {
		if ( $function_name === '_load_textdomain_just_in_time'
			&& strpos( (string) $message, '<code>appress</code>' ) !== false ) {
			return false;
		}
		return $trigger;
	}
}
