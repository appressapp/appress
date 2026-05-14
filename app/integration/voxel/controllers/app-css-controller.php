<?php

namespace Appress\Integration\Voxel\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Voxel-specific CSS rules appended to the Appress mobile-app CSS
 * payload via the `appress/app/css` filter (see {@see \Appress\get_app_css}).
 *
 * Pipeline:
 *   admin textareas (live_config.css_*) → this filter → boot payload →
 *   native (Android `appCssJS` / iOS WKUserScript) → injected at
 *   documentStart inside every WebView.
 *
 * Edit {@see ::rules_for_platform} below to add new rules. Use the
 * 'all' branch for cross-platform tweaks; 'android' / 'ios' for
 * platform-specific quirks.
 */
class App_Css_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Filter signature is ($css, $platform, $app_id) — our
		// implementation appends a static rule block per platform and
		// returns the merged string back into the boot payload.
		$this->filter( 'appress/app/css', '@append_rules', 10, 3 );
	}

	public function append_rules( $css, $platform, $app_id ) {
		$rules = $this->rules_for_platform( (string) $platform );
		if ( $rules === '' ) {
			return $css;
		}
		return $css === '' ? $rules : ( $css . "\n\n" . $rules );
	}

	/**
	 * Return the CSS body for a given platform scope, or empty string
	 * when nothing applies. Edit the heredocs below to add rules.
	 *
	 * Cascading order matches the native injector — `all` is emitted
	 * first as the platform-agnostic baseline, then `android` / `ios`
	 * is appended on the matching device. Use `!important` sparingly;
	 * the user's admin-entered CSS already runs after this so they can
	 * still override these defaults.
	 */
	private function rules_for_platform( $platform ) {
		switch ( $platform ) {
			case 'all':
				return <<<'CSS'
/* Voxel — cross-platform tweaks for Appress mobile app */
.ts-popup-root .ts-popup-content-wrapper, .ts-popup-root .ts-field-popup-container > .ts-field-popup{
    max-height: calc(100vh - var(--appress-status-bar-height, 0px)) !important;
}
CSS;

			case 'android':
				return <<<'CSS'
/* Voxel — Android-specific tweaks */
CSS;

			case 'ios':
				return <<<'CSS'
/* Voxel — iOS-specific tweaks */
CSS;
		}
		return '';
	}
}
