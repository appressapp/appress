<?php

/**
 * Assets configuration for Appress plugin.
 *
 * Two source folders, two bucket pairs:
 *   - `dist_*`  — Vite-built admin bundles under `assets/dist/{css,js}/`.
 *                 Auto-localized with `Apppress_Config`; JS shipped as
 *                 `type="module"` so Vite code-splitting works.
 *   - `styles` / `scripts` — plain (non-bundled) files under
 *                 `assets/css/` and `assets/js/`. Integrations can inject
 *                 their own localize payload via
 *                 `appress/assets/localize/{handle}` filter.
 *
 * Every entry registers with handle `appress:{filename}` — callers
 * `wp_enqueue_style` / `wp_enqueue_script` by handle from anywhere.
 */

namespace Appress;

if ( ! defined('ABSPATH') ) {
	exit;
}

return [
	// Vite dist — `assets/dist/css/`, `assets/dist/js/`.
	'dist_styles' => [
		'admin.css',
	],
	'dist_scripts' => [
		'apps.js',
		'integrations.js',
		'integration-events-panel.js',
		'integration-translatepress.js',
		'woocommerce-iap.js',
		'broadcast.js',
		'settings.js',
	],

	// Plain files — `assets/css/`, `assets/js/`.
	'styles' => [
		'builder-icon.css',             // Brand glyph for Bricks builder panel. Elementor prints its own tag directly (Editor V2 drops queued admin styles).
		'frontend-commons.css',              // Shared button base + every variant (qr-login, qr-scanner, apple-login, back, dismiss-first-launch). Driven by Button_Controls_Trait CSS vars.
		'notifications-feed.css',       // [appress_notifications] + builder widgets.
		'biometric-widget.css',         // [appress_biometric] + builder widgets.
		'account-deletion-widget.css',  // [appress_account_deletion] + builder widgets.
		'qr-login-widget.css',          // [appress_qr_login] + [appress_qr_scanner] (modal + visibility gate via [data-appress-scanner-only]).
		'status-bar-height-widget.css',     // [appress_status_bar_height] + builder widgets.
		'translatepress-switcher.css',  // [appress_translatepress_switcher] — language switcher (dropdown + inline list).
	],
	'scripts' => [
		'notifications-feed.js',
		'biometric-widget.js',
		'account-deletion-widget.js',
		'apple-login-widget.js',
		// Vendored Kazuhiko Arase qrcode-generator (MIT, untrimmed) — qr-login-widget
		// depends on its `qrcode` global. Replaces a hand-trimmed port that produced
		// QR codes Apple Camera (Vision NN-decoder) could read but iOS
		// AVCaptureMetadataOutput's classical decoder rejected.
		'qrcode-generator.min.js',
		'qr-login-widget.js',
		'back-button-widget.js',
		'menu-toggle-widget.js',
		'translatepress-switcher.js',
	],
];
