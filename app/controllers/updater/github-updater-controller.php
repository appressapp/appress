<?php

namespace Appress\Controllers\Updater;

use Appress\Controllers\Base_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub-backed auto-update wiring.
 *
 * Customer sites pick up new versions of the Appress plugin straight
 * from the public GitHub release feed — no wp.org listing, no
 * self-hosted update server. Behind the scenes we delegate to
 * {@see https://github.com/YahnisElsts/plugin-update-checker} which
 * hooks WP's native plugin-update transients
 * (`pre_set_site_transient_update_plugins`, `plugins_api`, …) so the
 * "Update available" UX on the WP Plugins page is byte-identical to a
 * wp.org-distributed plugin: badge + one-click update + auto-update
 * toggle.
 *
 * Release contract — honoured by the GitHub Actions workflow at
 * `.github/workflows/release.yml`:
 *
 *   1. Tag format `v{MAJOR}.{MINOR}.{PATCH}.{HOTFIX}` triggers the
 *      workflow (matches the 4-segment APPRESS_VERSION scheme).
 *   2. Workflow attaches a release asset named `appress.zip` built
 *      with `composer install --no-dev`, excludes `dev/`,
 *      `node_modules/`, `CLAUDE.md`, `.git/`, and flips
 *      `APPRESS_IS_DEV` from 1 → 0.
 *   3. The asset is what customer sites unpack into
 *      `wp-content/plugins/appress/`.
 *
 * Why `enableReleaseAssets()` is load-bearing: without it the
 * library falls back to GitHub's auto-generated source-code zip,
 * which lacks `app/vendor/` (composer deps are NOT committed to git)
 * → every customer site fatal-errors on the first auto-update with
 * "Class Kreait\Firebase\… not found".
 *
 * Degrades gracefully when the library isn't on disk yet (e.g. a
 * fresh server pull that hasn't run `composer install`): the
 * dependency check in `authorize()` returns false, the plugin still
 * boots, but auto-update is silently disabled until vendor/ exists.
 */
class Github_Updater_Controller extends Base_Controller {

	/**
	 * Canonical public repo. Public by design — release zips are
	 * customer-facing artefacts; the source code is GPL-licensed and
	 * already lives on customer servers post-install.
	 */
	const REPO_URL = 'https://github.com/appressapp/appress/';

	/**
	 * Default branch tracked for version checks. Tags on this branch
	 * (`v*.*.*.*`) drive the release flow; the library compares the
	 * latest tag's version against `APPRESS_VERSION` to decide if an
	 * update is offered.
	 */
	const BRANCH = 'main';

	/** Relative path to the vendored library — pinned so the
	 *  dependency check + require stay in sync. */
	const LIB_PATH = 'app/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';

	/**
	 * Skip the whole controller when the vendored library is missing —
	 * happens on a fresh git pull before `composer install` runs.
	 * Returning false here means Base_Controller never calls hooks(),
	 * so we never reach a "Class not found" fatal during boot.
	 */
	protected function authorize() {
		return defined( 'APPRESS_PLUGIN_DIR' )
			&& file_exists( APPRESS_PLUGIN_DIR . self::LIB_PATH );
	}

	/**
	 * Eager-load the library. The `PucFactory` class lives outside our
	 * PSR-4 autoload root (it's a third-party vendor with its own
	 * namespace structure), so we require its bootstrap file directly.
	 */
	protected function dependencies() {
		require_once APPRESS_PLUGIN_DIR . self::LIB_PATH;
	}

	/**
	 * Build the update checker. The library wires its own chain of WP
	 * filters (`pre_set_site_transient_update_plugins`, `plugins_api`,
	 * `upgrader_pre_download`, …) inside `buildUpdateChecker`, so this
	 * one factory call is the equivalent of `add_filter(...)` for our
	 * purposes.
	 */
	protected function hooks() {
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO_URL,
			APPRESS_PLUGIN_FILE,
			'appress'
		);
		$checker->setBranch( self::BRANCH );
		// Pull the `appress.zip` release asset, NOT the auto-generated
		// source-code archive — see class docblock for the vendor/
		// rationale.
		$checker->getVcsApi()->enableReleaseAssets();
	}
}
