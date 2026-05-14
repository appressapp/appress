<?php
namespace Appress\Controllers\App;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.Security.NonceVerification.Missing
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Controller extends \Appress\Controllers\Base_Controller {
 
	protected function hooks() {
		if ( is_admin() ) {
			// Priority 9 — must beat WP core's `_add_post_type_submenus`
			// (admin_menu:10) so our explicit Apps submenu lands at
			// `$submenu['appress'][0]` BEFORE the screens CPT row gets
			// appended. WP routes a parent click to whatever URL sits
			// at submenu index 0; without this priority shift the CPT's
			// `edit.php?post_type=appress_screen` jumps in first and
			// hijacks the "Appress" sidebar entry to open the Screens
			// list page instead of the Apps dashboard.
			$this->on( 'admin_menu', '@register_menus', 9 );
			$this->on( 'admin_enqueue_scripts', '@enqueue_scripts' );
		}
	}
 	protected function register_menus() {
		add_menu_page(
			__( 'Appress', 'appress' ),
			__( 'Appress', 'appress' ),
			'manage_options',
			'appress',
			[ $this, 'render_admin_page' ],
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGhlaWdodD0iMjRweCIgdmlld0JveD0iMCAtOTYwIDk2MCA5NjAiIHdpZHRoPSIyNHB4IiBmaWxsPSIjRkZGRkZGIj48cGF0aCBkPSJNMTIwLTEyMHEtMzMgMC01Ni41LTIzLjVUNDAtMjAwdi04MHEwLTMzIDIzLjUtNTYuNVQxMjAtMzYwaDI0MHEzMyAwIDU2LjUgMjMuNVQ0NDAtMjgwdjgwcTAgMzMtMjMuNSA1Ni41VDM2MC0xMjBIMTIwWm00ODAgMHEtMzMgMC01Ni41LTIzLjVUNTIwLTIwMHYtNTYwcTAtMzMgMjMuNS01Ni41VDYwMC04NDBoMjQwcTMzIDAgNTYuNSAyMy41VDkyMC03NjB2NTYwcTAgMzMtMjMuNSA1Ni41VDg0MC0xMjBINjAwWm0xMjAtMTIwcTE3IDAgMjguNS0xMS41VDc2MC0yODBxMC0xNy0xMS41LTI4LjVUNzIwLTMyMHEtMTcgMC0yOC41IDExLjVUNjgwLTI4MHEwIDE3IDExLjUgMjguNVQ3MjAtMjQwWk0xMjAtNDQwcS0zMyAwLTU2LjUtMjMuNVQ0MC01MjB2LTI0MHEwLTMzIDIzLjUtNTYuNVQxMjAtODQwaDI0MHEzMyAwIDU2LjUgMjMuNVQ0NDAtNzYwdjI0MHEwIDMzLTIzLjUgNTYuNVQzNjAtNDQwSDEyMFptMTYwLTIwMHExNyAwIDI4LjUtMTEuNVQzMjAtNjgwcTAtMTctMTEuNS0yOC41VDI4MC03MjBxLTE3IDAtMjguNSAxMS41VDI0MC02ODBxMCAxNyAxMS41IDI4LjVUMjgwLTY0MFpNMTEwLTUyMGgxODBsLTkwLTEyMC05MCAxMjBaIi8+PC9zdmc+',
			58
		);

		// Pin the Apps dashboard at submenu index 0. `add_menu_page`
		// only writes to the `$menu` global; `$submenu['appress']` stays
		// empty until somebody calls `add_submenu_page`. By doing so
		// here at admin_menu:9, our entry is guaranteed to occupy
		// position 0 — every later submenu (Integrations, Broadcast, the
		// Screens CPT row, future integrations) appends after, and the
		// parent "Appress" sidebar link keeps routing to `?page=appress`.
		add_submenu_page(
			'appress',
			__( 'Apps', 'appress' ),
			__( 'Apps', 'appress' ),
			'manage_options',
			'appress',
			[ $this, 'render_admin_page' ]
		);

		// Pin the Screens CPT row at the BOTTOM of the sub-menu. The
		// CPT itself sets `show_in_menu => false` so WP's automatic
		// `_add_post_type_submenus` skips it; we register the row here
		// with an explicit `$position = 99` so it lands after every
		// other submenu (Integrations, Broadcast, integration cards) no
		// matter what order they're registered in. Empty callback
		// `null` makes WP route the click straight to the post-type
		// edit list (`edit.php?post_type=appress_screen`).
		add_submenu_page(
			'appress',
			__( 'Screens', 'appress' ),
			__( 'Screens', 'appress' ),
			'edit_posts',
			'edit.php?post_type=appress_screen',
			null,
			99
		);
	}

	protected function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'appress' ) === false ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'appress';

		if ( $page !== 'appress' ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'appress:admin.css' );
		wp_enqueue_script( 'appress:apps.js' );
		// Bootstrap config attached via the proper inline-script API so
		// `window.appConfig` (and the `appressConfig` alias) is parsed
		// before apps.js evaluates. 'before' position guarantees the
		// JSON literal is in scope when the Vue mount initialises —
		// works for both classic and ESM script handles via core's
		// `WP_Scripts::print_inline_script`.
		$payload = wp_json_encode( $this->build_localize() );
		wp_add_inline_script(
			'appress:apps.js',
			'window.appConfig=' . $payload . ';window.appressConfig=window.appConfig;',
			'before'
		);
	}

	private function build_localize(): array {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'appress';

		// Merge the shared Vue UI translation dict (sourced from
		// `app/i18n/vue-strings.php`, picked up by xgettext / Loco)
		// with the page-specific overrides below. Per-page entries win
		// when keys collide, so a page-specific phrasing keeps
		// precedence over the auto-extracted default. Without this
		// merge `t('My Apps')` etc. fall through to the source string
		// because `useI18n` finds `appConfig.i18n` first and `Apppress_Config`
		// is only consulted as a fallback.
		$shared_i18n = file_exists( APPRESS_PLUGIN_DIR . 'app/i18n/vue-strings.php' )
			? require APPRESS_PLUGIN_DIR . 'app/i18n/vue-strings.php'
			: [];

		$page_i18n = [
				'Appress' => __( 'Appress', 'appress' ),
				'Configure your native app settings and integrations.' => __( 'Configure your native app settings and integrations.', 'appress' ),
				'Event Automation' => __( 'Event Automation', 'appress' ),
				'Trigger mechanical pushes based on user actions.' => __( 'Trigger mechanical pushes based on user actions.', 'appress' ),
				'Broadcast' => __( 'Broadcast', 'appress' ),
				'Broadcast messages to audience segments.' => __( 'Broadcast messages to audience segments.', 'appress' ),
				'License Status' => __( 'License Status', 'appress' ),
				'Active' => __( 'Active', 'appress' ),
				'Core Configuration' => __( 'Core Configuration', 'appress' ),
				'Saving...' => __( 'Saving...', 'appress' ),
				'Save & Sync' => __( 'Save & Sync', 'appress' ),
				'Connection Token' => __( 'Connection Token', 'appress' ),
				'Paste your Central SaaS Token...' => __( 'Paste your Central SaaS Token...', 'appress' ),
				'This token safely links your client app to the central SaaS build engine.' => __( 'This token safely links your client app to the central SaaS build engine.', 'appress' ),

				'Select Application Logo' => __( 'Select Application Logo', 'appress' ),
				'Select Logo' => __( 'Select Logo', 'appress' ),
				'WordPress Media Uploader API not found in the background!' => __( 'WordPress Media Uploader API not found in the background!', 'appress' ),
				'Coming Soon' => __( 'Coming Soon', 'appress' ),
				'The event automation system is currently under development.' => __( 'The event automation system is currently under development.', 'appress' ),
				'Push Notifications Panel' => __( 'Push Notifications Panel', 'appress' ),
				'Connect to SaaS Central to broadcast push campaigns.' => __( 'Connect to SaaS Central to broadcast push campaigns.', 'appress' ),
				'Central SaaS Integration Required' => __( 'Central SaaS Integration Required', 'appress' ),
				'Connect Now' => __( 'Connect Now', 'appress' ),
				'Error loading settings' => __( 'Error loading settings', 'appress' ),
				'Error saving settings: ' => __( 'Error saving settings: ', 'appress' ),
				'Check console.' => __( 'Check console.', 'appress' ),
				'Request failed' => __( 'Request failed', 'appress' ),
				'Please enter and save a Connection Token first.' => __( 'Please enter and save a Connection Token first.', 'appress' ),
				'Successfully pulled configurations from Central SaaS!' => __( 'Successfully pulled configurations from Central SaaS!', 'appress' ),
				'Error pulling settings: ' => __( 'Error pulling settings: ', 'appress' ),
				'Pull request failed. Check console.' => __( 'Pull request failed. Check console.', 'appress' ),
		];

		return [
			'nonce' => wp_create_nonce( 'appress_admin_action' ),
			'page'  => $page,
			// Page overrides + shared dict; page entries win on collision.
			'i18n'  => array_merge( $shared_i18n, $page_i18n ),
		];
	}

	public function render_admin_page() {
		// Bootstrap config is attached via `wp_add_inline_script` in the
		// `enqueue_admin_assets` step above. The render path only emits
		// the Vue mount template.
		require_once APPRESS_PLUGIN_DIR . 'templates/admin-app.php';
	}
}
