<?php

/**
 * Controller configuration for Appress plugins.
 * Modeled after Voxel Theme architecture.
 */

namespace Appress;

if ( ! defined('ABSPATH') ) {
	exit;
}

return [
	// Plugin-level scaffolding — each controller owns ONE concern.
	// Listed first because they wire foundational hooks (deactivation
	// cleanup, plugin row links, WP notice filters) that downstream
	// feature controllers can rely on being in place.
	\Appress\Controllers\Plugin\Lifecycle_Controller::class,
	\Appress\Controllers\Plugin\Action_Links_Controller::class,
	\Appress\Controllers\Plugin\Textdomain_Notice_Controller::class,

	// Schema migration MUST run before any controller that selects from
	// `wp_appress_apps` (Firebase router's on_mobile() → get_apps_class(),
	// the AJAX ajax-controller's resolve_request_prefix(), etc.). Listing
	// it here — before Assets / Updater / Firebase / Ajax — means the
	// migration completes at controller boot and downstream hooks() calls
	// see the post-1.1.0 schema.
	\Appress\Controllers\App\Database_Controller::class,

	\Appress\Controllers\Assets_Controller::class,

	// Auto-update from GitHub Releases. Wires
	// `yahnis-elsts/plugin-update-checker` so WP's native plugin-update
	// flow pulls new versions straight off the public GitHub repo —
	// admins see the standard "Update available" badge + one-click
	// update on the Plugins page, identical to a wp.org-hosted plugin.
	\Appress\Controllers\Updater\Github_Updater_Controller::class,
	// AJAX surface for the Updates tab on the Settings page —
	// `updater.list_versions` + `updater.rollback`. Admin picks a
	// past release from the dropdown + clicks Rollback; the
	// controller hands the zip URL to WP's Plugin_Upgrader.
	\Appress\Controllers\Updater\Ajax_Controller::class,
	// Top-of-page "Appress X.Y.Z is available" banner on every
	// admin screen, matching the visibility pattern Yoast /
	// Wordfence use. Pure consumer of the `update_plugins`
	// transient that Github_Updater_Controller's library
	// populates — no extra HTTP from this controller.
	\Appress\Controllers\Updater\Admin_Notice_Controller::class,

	\Appress\Controllers\Firebase\Database_Controller::class,
	// Firebase Module
	\Appress\Controllers\Firebase\Template_Controller::class,
	\Appress\Controllers\Firebase\Ajax_Controller::class,
	\Appress\Controllers\Firebase\Admin_Controller::class,

	\Appress\Controllers\Ajax_Controller::class,
	\Appress\Controllers\Integrations\Admin_Controller::class,
	\Appress\Controllers\Integrations\Ajax_Controller::class,
	\Appress\Controllers\Integrations\Integrations_Manager::class,

	// Site-wide Settings — Smart Banner today, more sections to come.
	\Appress\Controllers\Settings\Admin_Controller::class,
	\Appress\Controllers\Settings\Ajax_Controller::class,
	\Appress\Controllers\Settings\Smart_Banner_Controller::class,
	// Appress Screens
	\Appress\Controllers\Screens\Post_Type_Controller::class,
	\Appress\Controllers\Screens\Template_Controller::class,
	\Appress\Controllers\Screens\Seo_Controller::class,
	\Appress\Controllers\Screens\Ajax_Controller::class,

	// Native Login (Google x Appress Core)
	\Appress\Controllers\Login\Google_Controller::class,

	// Native Login (Apple Sign-In — Apple Guideline 4.8 mandates SIWA
	// when third-party social login is offered).
	\Appress\Controllers\Login\Apple_Controller::class,
	\Appress\Controllers\Login\Apple_Shortcode_Controller::class,

	// QR-code device-linked sign-in
	\Appress\Controllers\Login\Qr_Login_Controller::class,
	\Appress\Controllers\Login\Qr_Login_Shortcode_Controller::class,

	// Client API: Get Mobile App UI config

	// App Management (Database_Controller is registered earlier so its
	// schema migration beats every consumer's hooks() call.)
	\Appress\Controllers\App\Frontend_Controller::class,
	\Appress\Controllers\App\Heartbeat_Controller::class,
	// Cache invalidation — bumps every app's `live_config.update_time_hash`
	// (the `app.boot` "up_to_date" gate) when the plugin version changes
	// OR an integration is toggled in the Integrations admin page. Native
	// apps re-pull fresh config on their next cold-start.
	\Appress\Controllers\App\Cache_Invalidation_Controller::class,

	\Appress\Controllers\App\Admin_Controller::class,
	\Appress\Controllers\App\Apps_Controller::class,
	\Appress\Controllers\Preview\Preview_Controller::class,

	// Theme UI (CSS injection, Shortcodes)
	\Appress\Controllers\Theme\Shortcode_Controller::class,
	\Appress\Controllers\Theme\Injection_Controller::class,

	// Bottom Nav Badge Indicators
	\Appress\Controllers\App\Indicator_Controller::class,

	// Native Login (Google x Voxel Integration - Moved to Voxel_Controller)

	// Notification Management (Bootstrapped via Events Init)
	
	// Broadcast (Push Campaigns)
	\Appress\Controllers\Broadcast\Database_Controller::class,
	\Appress\Controllers\Broadcast\Admin_Controller::class,
	\Appress\Controllers\Broadcast\Frontend_Controller::class,
	\Appress\Controllers\Broadcast\Cron_Controller::class,

	// Automations & App Events
	\Appress\Controllers\Events\Admin_Controller::class,
	
	// Notification Management (Powered by App Events)
	\Appress\Controllers\Notifications\Database_Controller::class,
	\Appress\Controllers\Notifications\Controller::class,
	\Appress\Controllers\Notifications\Ajax_Controller::class,

	// Biometric Login (Face ID / Touch ID) — token issue + exchange + revoke
	\Appress\Controllers\Biometric\Database_Controller::class,
	\Appress\Controllers\Biometric\Ajax_Controller::class,
	// Shortcode + shared renderer: `[appress_biometric]` + the
	// `Shortcode_Controller::render()` that Elementor / Bricks widgets
	// also call into so every surface stays visually identical.
	\Appress\Controllers\Biometric\Shortcode_Controller::class,

	// Account deletion — email-confirmed self-delete flow required by
	// Apple Guideline 5.1.1(v) and useful for GDPR Art. 17. Token-gated
	// confirmation link expires in 30 minutes; admin role blocked from
	// self-delete; content reassigned to the first administrator.
	\Appress\Controllers\Account_Deletion\Ajax_Controller::class,
	\Appress\Controllers\Account_Deletion\Shortcode_Controller::class,

	// Generic UI components — back button + status-bar-height spacer +
	// dismiss-first-launch button. Each registers a shortcode + shared
	// renderer the Elementor widget and Bricks element delegate to so
	// all three surfaces match.
	\Appress\Controllers\Components\Back_Button_Shortcode_Controller::class,
	\Appress\Controllers\Components\Menu_Toggle_Shortcode_Controller::class,
	\Appress\Controllers\Components\Status_Bar_Height_Shortcode_Controller::class,
	\Appress\Controllers\Components\Dismiss_First_Launch_Shortcode_Controller::class,
	\Appress\Controllers\Components\Qr_Scanner_Shortcode_Controller::class,

	// Integrations Module: Global System Configuration
	// Appress\Controllers\Integrations\... run automatically from above
	
	// Built-in Appress events (security / account / social) — always on,
	// powered by WP core hooks (wp_login, profile_update, after_password_reset,
	// set_user_role, comment_post). Event classes live under `app/events/`.
	\Appress\Controllers\Events\Builtin_Controller::class,

	// External Integrations (Voxel, WooCommerce, etc)
	\Appress\Integration\Voxel\Voxel_Controller::class,
	\Appress\Integration\Woocommerce\Woocommerce_Controller::class,
	// Auto-enabled integrations: not user-toggleable, no card in appress-integrations.
	\Appress\Integration\Elementor\Elementor_Controller::class,
	\Appress\Integration\Bricks\Bricks_Controller::class,
	\Appress\Integration\Avada\Avada_Controller::class,
	// Removed: Woo_Abandoned_Cart — superseded by Uncanny Automator integration
	// (admins use Automator + a free abandoned-cart plugin to build their own recipe).
	\Appress\Integration\Uncanny_Automator\Uncanny_Automator_Controller::class,
	\Appress\Integration\FluentCRM\FluentCRM_Controller::class,
	\Appress\Integration\Translatepress\Translatepress_Controller::class,
];

