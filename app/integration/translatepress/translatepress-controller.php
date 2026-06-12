<?php

namespace Appress\Integration\Translatepress;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TranslatePress integration entry point.
 *
 * Gating model:
 *   - Hard requirement: the TRP plugin must be active (`\TRP_Translate_Press`
 *     class exists). Without it the integration stays dormant and the
 *     per-app sub-tab + Native Features toggle hide themselves in the
 *     admin Vue layer (driven by `Admin_Controller::collect_integration_status`).
 *   - Per-app opt-in: `build_config.translatepress.enabled` — surfaced
 *     as a toggle in the per-app Native Features grid + as the
 *     conditionally-shown "TranslatePress" sub-tab under Settings
 *     where admins edit per-language bottom-nav label overrides.
 *
 * What it does (when active + per-app enabled):
 *   - Hooks `appress/app/live_config` (via Boot_Config_Controller) to
 *     inject a `translatepress` block with pre-resolved URL + label
 *     translation maps. Native consumes the maps so navigation +
 *     bottom-nav labels render in the active language without round-
 *     trips or 302 redirects.
 *   - Registers the `[appress_trp_switcher]` shortcode for in-page
 *     language pickers (admin places via page builder).
 *   - Exposes mobile-side AJAX endpoints (`translatepress.{save,get}_user_language`)
 *     so the in-app language switcher can persist a logged-in user's
 *     choice across sessions via WP user meta.
 *
 * Note: the legacy `/appress-integrations` detail page and the
 * site-level `appress_settings.modules.translatepress` master toggle
 * were removed when the per-app sub-tab landed — every gate now lives
 * on the per-app `build_config.translatepress.enabled` flag, which is
 * the single source of truth Boot_Config_Controller consults.
 */
class Translatepress_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		// TRP plugin must be active for anything to wire up. Without it
		// we skip entirely — the per-app sub-tab + Features toggle hide
		// themselves automatically because
		// `Admin_Controller::collect_integration_status` reports
		// `translatepress.installed = false`.
		if ( ! class_exists( '\TRP_Translate_Press' ) ) {
			return;
		}

		// (Removed Settings_Controller — its sole job was the legacy
		// "Clear boot cache" AJAX endpoint. Per the build-time-bake
		// refactor, every TP value, including translations, ships
		// baked into AppressBakedConfig; nothing reads from the boot
		// cache that the controller used to invalidate.)

		// Boot enrichment + switcher shortcode + user-language store.
		// All three are inert at runtime when no app has the per-app
		// toggle on — `Boot_Config_Controller::build_context` short-
		// circuits on `$live_config['translatepress']['enabled'] !== true`,
		// the shortcode returns an empty string when TRP itself has no
		// extra languages published, and the user-language endpoints
		// validate the lang code against TRP's published list before
		// writing. Bootstrapping unconditionally keeps the wiring
		// simple — the gates live downstream where they belong.
		new Controllers\Boot_Config_Controller();
		new Controllers\Shortcode_Controller();
		new Controllers\User_Language_Controller();
	}
}
