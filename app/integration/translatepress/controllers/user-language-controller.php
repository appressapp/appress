<?php

namespace Appress\Integration\Translatepress\Controllers;

use Appress\Controllers\Base_Controller;
use Appress\Integration\Translatepress\Services\Url_Translator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-user language preference, persisted in WordPress's native
 * `user_meta.locale` key (NOT TRP's `trp_language`):
 *   - Set by WP's built-in "Language" field on the user profile (Users → Edit).
 *   - Read by {@see get_user_locale()}, which WP core consults for admin
 *     screens, transactional emails, dashboard, etc. Also picked up by
 *     TRP automatically (TRP's user-language resolution chain includes
 *     `get_user_locale()` as a fallback).
 *
 * Why WP-native instead of `trp_language`:
 *   - One source of truth across the entire WP ecosystem (admin UI, emails,
 *     WC emails, plugins) — TRP's `trp_language` only matters to TRP itself.
 *   - Survives if TRP is deactivated; `trp_language` would orphan.
 *   - Matches user expectation: "the language I set in my profile is THE
 *     language I want to see across all touchpoints, including the app."
 *
 * Format alignment: WP locale uses POSIX-style `vi_VN`, `en_US`, `pt_BR` —
 * same shape as TRP variant keys, so the lang code we read from
 * `user_meta.locale` can be matched directly against TRP variant keys
 * in the app's boot payload with no remapping.
 *
 * Two endpoints, both logged-in only:
 *
 *   - `translatepress.save_user_language`
 *       POST { lang } — called fire-and-forget by the JS switcher
 *       (`translatepress-switcher.js`) right before it fires the native
 *       `translatepress.changeLanguage` bridge. Validates `lang` against
 *       the active TRP language list, then writes `user_meta.locale`.
 *
 *   - `translatepress.get_user_language`
 *       GET — called by native on the login transition (see
 *       `AppressTranslatepressController.handleLoginTransition` on
 *       iOS/Android). Returns the saved `user_meta.locale`, empty
 *       when unset. Native applies it + cold-restarts when it differs
 *       from the device's current TRP selection.
 */
class User_Language_Controller extends Base_Controller {

	/** WordPress native per-user language meta key — set by WP's built-in
	 *  user profile "Language" field, read by {@see get_user_locale()}. */
	const META_KEY = 'locale';

	/** @var Url_Translator */
	private $translator;

	public function __construct() {
		$this->translator = new Url_Translator();
		parent::__construct();
	}

	protected function hooks() {
		// Mobile-only — native TranslatePress integration in the app calls
		// these when the user picks a language in the in-app switcher and
		// when re-reading the persisted choice. Register on each app's
		// `<class_id>_ajax_*` + legacy `appress_ajax_*` for backward compat.
		$this->on_mobile( 'translatepress.save_user_language', '@handle_save_user_language' );
		$this->on_mobile( 'translatepress.get_user_language',  '@handle_get_user_language' );
	}

	protected function handle_save_user_language() {
		try {
			if ( ! is_user_logged_in() ) {
				throw new \Exception( 'Unauthorized.' );
			}
			$lang = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
			if ( $lang === '' ) {
				throw new \Exception( 'Missing lang.' );
			}

			$settings = $this->translator->get_settings();
			$languages = isset( $settings['languages'] ) ? (array) $settings['languages'] : [];
			if ( ! in_array( $lang, $languages, true ) ) {
				throw new \Exception( 'Invalid lang.' );
			}

			update_user_meta( get_current_user_id(), self::META_KEY, $lang );

			return wp_send_json( [
				'success' => true,
				'message' => 'Saved.',
				'data'    => [ 'lang' => $lang ],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}

	protected function handle_get_user_language() {
		try {
			if ( ! is_user_logged_in() ) {
				throw new \Exception( 'Unauthorized.' );
			}
			$lang = (string) get_user_meta( get_current_user_id(), self::META_KEY, true );
			return wp_send_json( [
				'success' => true,
				'data'    => [ 'lang' => $lang ],
			] );
		} catch ( \Exception $e ) {
			return wp_send_json( [
				'success' => false,
				'message' => $e->getMessage(),
			] );
		}
	}
}
