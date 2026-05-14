<?php

namespace Appress\Integration\Translatepress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TranslatePress integration entry point.
 *
 * Two layers of gating, by design (not redundant):
 *   1. Site-level toggle on the Integrations admin page (Features Manager
 *      writes to `appress_settings.modules.translatepress`). OFF = the
 *      integration is dormant for the whole site.
 *   2. Per-app toggle inside the integration's detail page
 *      (`?page=appress-integrations&integration=translatepress`). Stored
 *      by Settings_Controller. OFF = boot endpoint for that specific app
 *      doesn't get a `translatepress` block, even though the site-level
 *      toggle is ON.
 *
 * What it does (when active):
 *   - Registers a card on the Integrations admin page so admins can
 *     toggle the integration site-wide and click through to configure
 *     per-app translations.
 *   - Hooks `appress/integrations/admin_template/translatepress` to
 *     render the detail page (per-app toggle + bottom-nav label
 *     translations + per-app clear-cache button).
 *   - Hooks `appress/app/live_config` (via Boot_Config_Controller) to
 *     inject a `translatepress` block with pre-resolved URL + label
 *     translation maps. Native consumes the maps so navigation +
 *     bottom-nav labels render in the active language without round-
 *     trips or 302 redirects.
 *
 * Hard gate: TRP plugin must be active. Without it, the card still
 * appears on the Integrations page but with a "install TranslatePress
 * first" CTA (via `requires_plugin`) — no boot-config enrichment runs.
 */
class Translatepress_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// Card registered unconditionally so the Integrations page can
		// render the "install TranslatePress first" CTA via the
		// `requires_plugin` gate when TRP isn't active yet.
		$this->filter( 'appress/integrations/registered', '@register_integration' );

		// TRP plugin must be active for any of the rest. Without it the
		// integration card still shows on the Integrations page but
		// every downstream controller would fail to read TRP settings.
		if ( ! class_exists( '\TRP_Translate_Press' ) ) {
			return;
		}

		// Always-on: per-app admin UI (toggle + per-app label strings +
		// cache clear button). Admin needs to configure these BEFORE
		// flipping the master toggle on, so the controller's render +
		// save handlers must be wired regardless of master state.
		// Mirrors the Voxel `Events_Controller` pattern.
		new Controllers\Settings_Controller();

		// Detail page hook — Integrations\Admin_Controller fires this when
		// admin visits `?integration=translatepress`. Asset enqueue is
		// handled separately by Settings_Controller on `admin_enqueue_scripts`.
		$this->on( 'appress/integrations/admin_template/translatepress', '@render_detail' );

		// Site-level toggle gate. Integrations_Manager fires
		// `appress/integration/translatepress/execute` only when
		// `appress_settings.modules.translatepress = true`. Without
		// this gate, the integration bootstraps as soon as the TRP
		// plugin class loads — bypassing the admin's master toggle on
		// the Integrations page. Customer surfaced this exact bug:
		// they switched the master off but the mobile app boot endpoint
		// kept emitting variant URLs, so device-locale matching pinned
		// English users on the en_GB variant instead of falling back
		// to the site's TRP default. Match the Voxel / WC / Avada
		// pattern: site-level toggle → bootstrap on execute action.
		$this->on( 'appress/integration/translatepress/execute', '@bootstrap_integration' );
	}

	/**
	 * Boot the integration's user-facing surfaces (boot-config enrichment,
	 * front-end switcher shortcode, server-side user-language store)
	 * only when the master toggle is on. Settings UI is always on
	 * (instantiated in hooks() above) so admin can prep config before
	 * flipping the master.
	 */
	protected function bootstrap_integration() {
		new Controllers\Boot_Config_Controller();
		new Controllers\Shortcode_Controller();
		new Controllers\User_Language_Controller();
	}

	protected function register_integration( $integrations ) {
		$integrations['translatepress'] = [
			'name'            => __( 'TranslatePress', 'appress' ),
			'description'     => __( 'Render every screen URL and bottom-nav label in the active language so the app matches your TranslatePress setup with no flicker or redirect.', 'appress' ),
			'color'           => 'orange',
			// Dashicon path — Vue Integrations list resolves `dashicons-*`
			// strings as CSS classes (see Avada_Controller for the full
			// icon-resolution note). Swap to a logo.svg URL later if a
			// brand glyph is preferred.
			'icon'            => APPRESS_PLUGIN_URL . 'app/integration/translatepress/logo.svg',
			'configurable'    => true,
			'requires_plugin' => [
				'name'  => 'TranslatePress – Multilingual',
				'class' => '\\TRP_Translate_Press',
			],
			'integrations'    => [
				'url_translation'   => __( 'Per-language URL resolution for screens & deeplinks', 'appress' ),
				'label_translation' => __( 'Per-language bottom-nav labels', 'appress' ),
				'per_app_toggle'    => __( 'Enable per app, with per-app cache control', 'appress' ),
			],
		];
		return $integrations;
	}

	public function render_detail() {
		Controllers\Settings_Controller::render();
	}
}
