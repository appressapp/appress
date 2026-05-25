<?php

namespace Appress;

if ( ! defined('ABSPATH') ) {
	exit;
}

return [
	'build_information' => [
		'fields' => [
			'title' => [
				'type' => 'text',
				'label' => __( 'App Title', 'appress' ),
				'sanitize' => 'text',
				'default' => 'Untitled App',
				'required' => true,
				'ui' => [ 'group' => 'app_info', 'placeholder' => __( 'E.g. My Awesome App', 'appress' ) ]
			],
			'url' => [
				'type' => 'url',
				'label' => __( 'Website URL', 'appress' ),
				'sanitize' => 'url',
				'default' => '',
				'required' => true,
				'ui' => [
					'group' => 'app_info',
					'placeholder' => 'https://example.com',
					'hint' => __( 'Root URL of your website.', 'appress' )
				]
			],
			'logo' => [
				'type' => 'file',
				'label' => __( 'Application Logo', 'appress' ),
				'sanitize' => 'url',
				'default' => '',
				'required' => true,
				'ui' => [
					'group' => 'app_info',
					'constraint' => 'square',
					'min_size' => 1024,
					'hint' => __( 'Square image (1:1). Minimum 1024×1024', 'appress' )
				]
			],

			// ── Splash Screen ─────────────────────────────────────────────
			// `splash_type` switches rendering between the centered-logo
			// boot screen (current default) and a full-bleed image. The
			// Build Engine bakes the chosen mode into the binary — only
			// the matching `APPRESS-SPLASH-*` block survives the
			// injector strip — so each app ships exactly one splash
			// implementation. This increases per-build binary diversity
			// (Apple 4.3a) on top of the user-facing customisation.
			'splash_type' => [
				'type' => 'select',
				'label' => __( 'Splash Type', 'appress' ),
				'sanitize' => 'text',
				'default' => 'default',
				'required' => true,
				'ui' => [
					'group' => 'splash_screen',
					'options' => [
						[ 'value' => 'default', 'label' => 'Default' ],
						[ 'value' => 'image',   'label' => 'Custom Image' ],
					],
					'hint' => __( 'Choose how the app boot screen looks. Default shows the logo on a background color; Custom Image shows a full-screen image.', 'appress' ),
				]
			],
			'splash_bg_color' => [
				'type' => 'color',
				'label' => __( 'Background Color', 'appress' ),
				'sanitize' => 'text',
				'default' => '#ffffff',
				'required' => true,
				'ui' => [
					'group' => 'splash_screen',
					'hint' => __( 'Behind the logo in Default mode. In Custom Image mode, match this to your image\'s edge color to avoid a color flash while the app launches.', 'appress' ),
				]
			],
			'splash_show_loading_bar' => [
				'type' => 'boolean',
				'label' => __( 'Show Loading Bar', 'appress' ),
				'sanitize' => 'boolean',
				'default' => true,
				'ui' => [
					'group' => 'splash_screen',
					'hint' => __( 'Animated progress pill displayed under the logo while the app boots.', 'appress' ),
				]
			],
			'splash_image' => [
				'type' => 'file',
				'label' => __( 'Splash Image', 'appress' ),
				'sanitize' => 'url',
				'default' => '',
				'ui' => [
					'group' => 'splash_screen',
					'hint' => __( 'Portrait image recommended. Use a high resolution (at least 1290×2796) so it stays crisp on large phones. The image is center-cropped to fit each device screen.', 'appress' )
				]
			],
			'sha1_fingerprint' => [
				'type' => 'text',
				'label' => __( 'SHA-1 Certificate Fingerprint', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [ 'group' => 'firebase', 'readonly' => true, 'hint' => __( 'Auto-generated. Copy this into your Firebase Android app settings.', 'appress' ) ]
			],
			'firebase_android' => [
				'type' => 'file_drag_drop',
				'label' => __( 'Android Firebase Config', 'appress' ),
				'sanitize' => 'file_content',
				'default' => '',
				'required' => true,
				'ui' => [ 'group' => 'firebase', 'accept' => '.json', 'filename' => 'google-services.json' ]
			],
			'firebase_ios' => [
				'type' => 'file_drag_drop',
				'label' => __( 'iOS Firebase Config', 'appress' ),
				'sanitize' => 'file_content',
				'default' => '',
				'required' => true,
				'ui' => [ 'group' => 'firebase', 'accept' => '.plist', 'filename' => 'GoogleService-Info.plist' ]
			],

			// iOS signing — Apple Team ID. Required at build time to sign .ipa
			// under the customer's Apple Developer account. Kept in
			// build_information (plaintext) rather than credentials because the
			// value is public-identifier grade (it appears in every signed
			// .ipa anyway) and the Build Engine needs it in the main build
			// payload, not the encrypted credentials column.
			'apple_team_id' => [
				'type' => 'text',
				'label' => __( 'Apple Team ID', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'required_for' => [ 'ios' ],
				'ui' => [
					'group' => 'ios_signing',
					'placeholder' => 'ABC1234DEF',
				]
			],


		
			// package_id stays inside build_information JSON — the Build Engine
			// reads it from the payload as part of the app config. It's a
			// public identifier (bundle ID baked into every signed binary),
			// so storing plaintext here is fine.
			'package_id' => [
				'type' => 'text',
				'label' => __( 'Package ID', 'appress' ),
				'sanitize' => 'text',
				'default' => ''
			],
			// Numeric App Store ID assigned by Apple after the iOS app is
			// published. Rendered manually inside the App Information card
			// (with an inline "Get" button that hits the iTunes Search API
			// to auto-fill from `package_id`) — `group: 'app_info'` puts
			// it in the same column as Title / URL / etc., and the Vue
			// view skips it from the auto v-for so the manual block can
			// take over rendering. Used downstream by the iOS Smart App
			// Banner and (future) Universal Links generator; consumers
			// gate on a non-empty value before emitting markup.
			'apple_store_id' => [
				'type' => 'text',
				'label' => __( 'Apple App Store ID', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [
					'group' => 'app_info',
					'placeholder' => '1234567890',
					'hint' => __( 'Numeric ID from App Store Connect. Click Get to auto-fetch once the app is live on the App Store, or paste it manually.', 'appress' ),
				],
			],
			// app_version + build_number are collected in the Build modal
			// (not in the main form) but must persist in build_information
			// so request_build can ship them. Without these schema entries,
			// save_config drops them silently — user types 1.2.5 build 10,
			// Build Engine receives 1.0.0 build 1.
			'app_version' => [
				'type' => 'text',
				'label' => __( 'App Version', 'appress' ),
				'sanitize' => 'text',
				'default' => '1.0.0'
			],
			'build_number' => [
				'type' => 'number',
				'label' => __( 'Build Number', 'appress' ),
				'sanitize' => 'number',
				'default' => 1
			],
			// ── Native Features ─────────────────────────────────────────────
			// Per-feature build-time toggles. Each one decides whether the
			// related Capacitor plugin + native framework gets bundled into
			// the .ipa / .apk. Disabling a feature shrinks binary size AND
			// makes each customer's linked-frameworks signature different —
			// helps with Apple's 4.3(a) similarity scanner that flags apps
			// sharing the same Capacitor + framework fingerprint across
			// developer accounts.
			//
			// Default ON for every feature so existing apps keep current
			// behavior on plugin upgrade. Customer opts OFF per app, per
			// build. Engine reads `<feature>.enabled` flag from build_payload
			// and conditionally includes the plugin in `npm install` + the
			// native code injection step.
			//
			// Each feature is its own object with an `enabled` field today,
			// with room to add per-feature configuration (provider tokens,
			// permission strings, etc.) later.

			'push_notifications' => [
				'type' => 'object',
				'label' => __( 'Push Notifications', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => true ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Enable Push Notifications', 'appress' ),
						'sanitize' => 'boolean',
						'default' => true,
						'ui' => [
							'group' => 'native_features',
							'doc_url' => 'https://docs.appress.app/native-features/push-notifications'
						]
					],
				]
			],

			'biometric' => [
				'type' => 'object',
				'label' => __( 'Biometric Authentication', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => true ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Enable Biometric (Face ID / Touch ID)', 'appress' ),
						'sanitize' => 'boolean',
						'default' => true,
						'ui' => [
							'group' => 'native_features',
							'doc_url' => 'https://docs.appress.app/native-features/biometric'
						]
					],
				]
			],

			'google_auth' => [
				'type' => 'object',
				'label' => __( 'Google Sign-In', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => true ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Enable Google Sign-In', 'appress' ),
						'sanitize' => 'boolean',
						'default' => true,
						'ui' => [
							'group' => 'native_features',
							'doc_url' => 'https://docs.appress.app/native-features/google-sign-in'
						]
					],
				]
			],

			'apple_auth' => [
				'type' => 'object',
				'label' => __( 'Sign in with Apple', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => true ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Enable Sign in with Apple', 'appress' ),
						'sanitize' => 'boolean',
						'default' => true,
						'ui' => [
							'group' => 'native_features',
							'doc_url' => 'https://docs.appress.app/native-features/sign-in-with-apple'
						]
					],
				]
			],

			'qr_scanner' => [
				'type' => 'object',
				'label' => __( 'QR Code Scanner', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => true ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Enable QR Scanner', 'appress' ),
						'sanitize' => 'boolean',
						'default' => true,
						'ui' => [
							'group' => 'native_features',
							'doc_url' => 'https://docs.appress.app/native-features/qr-scanner'
						]
					],
				]
			],

			'geolocation' => [
				'type' => 'object',
				'label' => __( 'Geolocation', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => true ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Enable Geolocation', 'appress' ),
						'sanitize' => 'boolean',
						'default' => true,
						'ui' => [
							'group' => 'native_features',
							'doc_url' => 'https://docs.appress.app/native-features/geolocation'
						]
					],
				]
			],

			// connection_token + central_app_id intentionally NOT declared
			// here. They live in their own DB columns (connection_token is
			// encrypted). Declaring them would also duplicate the values
			// into this plaintext JSON, leaking the token at rest.
		]
	],
	'live_config' => [
		'fields' => [
			'css_all' => [
				'type' => 'textarea',
				'label' => __( 'Custom CSS (Global)', 'appress' ),
				'sanitize' => 'css',
				'default' => '',
				'ui' => [ 'group' => 'custom_css', 'placeholder' => '.site-header { display: none; }', 'col_span' => 2 ]
			],
			'css_android' => [
				'type' => 'textarea',
				'label' => __( 'Custom CSS (Android)', 'appress' ),
				'sanitize' => 'css',
				'default' => '',
				'ui' => [ 'group' => 'custom_css', 'placeholder' => '/* Android specific */' ]
			],
			'css_ios' => [
				'type' => 'textarea',
				'label' => __( 'Custom CSS (iOS)', 'appress' ),
				'sanitize' => 'css',
				'default' => '',
				'ui' => [ 'group' => 'custom_css', 'placeholder' => '/* iOS specific */' ]
			],
			'bottom_navigation' => [
				'type' => 'object',
				'label' => __( 'Bottom Navigation', 'appress' ),
				'sanitize' => 'object',
				'default' => [],
				'fields' => [
					'enabled' => [ 'type' => 'boolean', 'sanitize' => 'boolean', 'default' => true ],
					// Toggles
					'hide_on_scroll' => [ 'type' => 'boolean', 'label' => __( 'Hide on Scroll', 'appress' ), 'sanitize' => 'boolean', 'default' => true, 'ui' => [ 'group' => 'nav_toggles' ] ],
					'active_item_top_border' => [ 'type' => 'boolean', 'label' => __( 'Active item top border', 'appress' ), 'sanitize' => 'boolean', 'default' => true, 'ui' => [ 'group' => 'nav_toggles' ] ],
					'show_on_subscreen' => [ 'type' => 'boolean', 'label' => __( 'Show on subscreen', 'appress' ), 'sanitize' => 'boolean', 'default' => false, 'ui' => [ 'group' => 'nav_toggles', 'hint' => __( 'Keep the bottom nav visible when a subscreen is pushed on top of a tab.', 'appress' ) ] ],
					// Colors
					'background_color' => [ 'type' => 'color', 'label' => __( 'Background Color', 'appress' ), 'sanitize' => 'text', 'default' => '#ffffff', 'ui' => [ 'group' => 'nav_colors' ] ],
					'active_color' => [ 'type' => 'color', 'label' => __( 'Active Color', 'appress' ), 'sanitize' => 'text', 'default' => '#000000', 'ui' => [ 'group' => 'nav_colors' ] ],
					'normal_color' => [ 'type' => 'color', 'label' => __( 'Normal Color', 'appress' ), 'sanitize' => 'text', 'default' => '#9ca3af', 'ui' => [ 'group' => 'nav_colors' ] ],
					// Typography
					'font_family' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => 'Inter, sans-serif' ],
					'font_size' => [ 'type' => 'number', 'label' => __( 'Font Size', 'appress' ), 'sanitize' => 'number', 'default' => 12, 'ui' => [ 'group' => 'nav_typography' ] ],
					'font_weight' => [
						'type' => 'select',
						'label' => __( 'Font Weight', 'appress' ),
						'sanitize' => 'text',
						'default' => '500',
						'ui' => [
							'group' => 'nav_typography',
							'options' => [
								[ 'value' => 'normal', 'label' => 'Normal' ],
								[ 'value' => '500', 'label' => 'Medium' ],
								[ 'value' => '600', 'label' => 'Semi Bold' ],
								[ 'value' => 'bold', 'label' => 'Bold' ],
							]
						]
					],
					'icon_size' => [ 'type' => 'number', 'label' => __( 'Icon Size (px)', 'appress' ), 'sanitize' => 'number', 'default' => 24, 'ui' => [ 'group' => 'nav_typography' ] ],
					// Shadow
					'shadow_color' => [ 'type' => 'color', 'label' => __( 'Shadow Color', 'appress' ), 'sanitize' => 'text', 'default' => '#ffffff', 'ui' => [ 'group' => 'nav_shadow' ] ],
					'shadow_opacity' => [ 'type' => 'number', 'label' => __( 'Shadow Opacity', 'appress' ), 'sanitize' => 'number', 'default' => 0.1, 'ui' => [ 'group' => 'nav_shadow', 'placeholder' => '0.1' ] ],
					'shadow_radius' => [ 'type' => 'number', 'label' => __( 'Shadow Blur Radius (px)', 'appress' ), 'sanitize' => 'number', 'default' => 4, 'ui' => [ 'group' => 'nav_shadow', 'placeholder' => '4' ] ],
					'shadow_offset_y' => [ 'type' => 'number', 'label' => __( 'Shadow Offset Y (px)', 'appress' ), 'sanitize' => 'number', 'default' => -2, 'ui' => [ 'group' => 'nav_shadow', 'placeholder' => '-2' ] ],
					// Border
					'border_top_color' => [ 'type' => 'color', 'label' => __( 'Top Border Color', 'appress' ), 'sanitize' => 'text', 'default' => '#ffffff', 'ui' => [ 'group' => 'nav_border' ] ],
					'border_top_size' => [ 'type' => 'number', 'label' => __( 'Top Border Size (px)', 'appress' ), 'sanitize' => 'number', 'default' => 1, 'ui' => [ 'group' => 'nav_border', 'placeholder' => '1' ] ],
					// Indicator (default badge colors — per-item override in items[])
					'indicator_background_color' => [ 'type' => 'color', 'label' => __( 'Indicator Background Color', 'appress' ), 'sanitize' => 'text', 'default' => '#ef4444', 'ui' => [ 'group' => 'nav_indicator' ] ],
					'indicator_color' => [ 'type' => 'color', 'label' => __( 'Indicator Color', 'appress' ), 'sanitize' => 'text', 'default' => '#ffffff', 'ui' => [ 'group' => 'nav_indicator' ] ],
					'items' => [
						'type' => 'repeater',
						'sanitize' => 'repeater',
						'default' => [],
						'fields' => [
							'_id' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => '' ],
							'title' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => '' ],
							'icon' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => '' ],
							'type' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => 'screen' ],
							'screen_id' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => '' ],
							'url' => [ 'type' => 'url', 'sanitize' => 'url', 'default' => '' ],
							// Which drawer this item opens when type === 'menu_toggle'.
							// 'left'  → opens the Left Side Menu  (action: open_side_menu)
							// 'right' → opens the Right Side Menu (action: open_right_menu)
							// Default 'left' so items configured before 2026-05-15 keep
							// their original single-drawer behavior.
							'menu_target' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => 'left' ],
							'indicator' => [ 'type' => 'select', 'sanitize' => 'text', 'default' => 'none' ],
							// Per-item indicator override — lets customer tone
							// down less-critical badges (e.g. cart = subtle
							// gray) while keeping important ones loud (e.g.
							// notifications = red). Empty values fall back to
							// the global default from default_config.
							'custom_indicator_style'     => [ 'type' => 'boolean', 'sanitize' => 'boolean', 'default' => false ],
							'indicator_background_color' => [ 'type' => 'color', 'sanitize' => 'text', 'default' => '' ],
							'indicator_color'            => [ 'type' => 'color', 'sanitize' => 'text', 'default' => '' ],
						]
					]
				]
			],
			// ── Default Configuration (toggles first, then colors) ──
			'pull_to_refresh' => [
				'type' => 'boolean',
				'label' => __( 'Pull to Refresh', 'appress' ),
				'sanitize' => 'boolean',
				'default' => true,
				'ui' => [ 'group' => 'default_config' ]
			],
			'allow_rotation' => [
				'type' => 'boolean',
				'label' => __( 'Allow Rotation', 'appress' ),
				'sanitize' => 'boolean',
				'default' => false,
				'ui' => [ 'group' => 'default_config' ]
			],
			'background_color' => [
				'type' => 'color',
				'label' => __( 'App Background Color', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [ 'group' => 'default_config' ]
			],
			'splash_footer_text' => [
				'type' => 'text',
				'label' => __( 'Splash Screen Footer', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [
					'group' => 'default_config',
					'placeholder' => __( 'e.g. © 2026 Brand Name', 'appress' ),
					'maxlength' => 80,
				]
			],
			'google_analytics_id' => [
				'type' => 'text',
				'label' => __( 'Google Analytics Measurement ID', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [ 'group' => 'analytics', 'placeholder' => 'G-XXXXXXXXXX' ]
			],
			'exclude_all_web_ga' => [
				'type' => 'boolean',
				'label' => __( 'Exclude ALL web Google Analytics inside this app', 'appress' ),
				'sanitize' => 'boolean',
				'default' => true,
				'ui' => [ 'group' => 'analytics', 'hint' => __( 'Block every gtag / GA4 script the website loads so traffic is not double-counted.', 'appress' ) ]
			],
			'exclude_web_ga_ids' => [
				'type' => 'textarea',
				'label' => __( 'Specific GA4 / gtag IDs to exclude', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [ 'group' => 'analytics', 'placeholder' => "G-WEB_ONE\nG-WEB_TWO", 'hint' => __( 'One ID per line. Only used when the switch above is OFF.', 'appress' ), 'show_if' => '!exclude_all_web_ga' ]
			],
			// ────────────────────────────────────────────────────────────────
			//   LEFT SIDE MENU
			//   Internal field name kept as `side_menu` for backward compat
			//   with apps shipped before 2026-05-15 when this was the only
			//   drawer. Always anchored to the LEFT edge.
			// ────────────────────────────────────────────────────────────────
			// Slide-in drawer loading the App Screen marked with role="menu".
			// WebView approach: customer designs the menu on their site (user
			// profile, avatar, account links, conditional content based on
			// login state) on a screen, marks it with the "Menu Screen" role,
			// and the drawer reuses that screen's URL + reload behavior. Drawer
			// pushes the whole app layout sideways (bottom nav included) so it
			// reveals cleanly. Triggered by: edge swipe, bottom nav item with
			// type=menu_toggle, or any element with class `.appress-open-menu`
			// inside the web content (intercepted by the native bridge JS).
			'side_menu' => [
				'type' => 'object',
				'label' => __( 'Left Side Menu', 'appress' ),
				'sanitize' => 'object',
				'default' => [],
				'fields' => [
					'enabled' => [ 'type' => 'boolean', 'label' => __( 'Enable Left Side Menu', 'appress' ), 'sanitize' => 'boolean', 'default' => false, 'ui' => [ 'group' => 'side_menu_main', 'col_span' => 2, 'hint' => __( 'Drawer content comes from the App Screen marked with the "Left Menu Screen" role. Set the role on that screen below.', 'appress' ) ] ],
					'width_percent' => [ 'type' => 'number', 'label' => __( 'Width (% of screen)', 'appress' ), 'sanitize' => 'number', 'default' => 90, 'ui' => [ 'group' => 'side_menu_main', 'col_span' => 2, 'placeholder' => '90', 'show_if' => 'enabled' ] ],
					'background_color' => [ 'type' => 'color', 'label' => __( 'Background color', 'appress' ), 'sanitize' => 'text', 'default' => '#ffffff', 'ui' => [ 'group' => 'side_menu_main', 'col_span' => 2, 'show_if' => 'enabled' ] ],
				]
			],

			// ────────────────────────────────────────────────────────────────
			//   RIGHT SIDE MENU (added 2026-05-15)
			// ────────────────────────────────────────────────────────────────
			// Optional second drawer anchored on the right edge. Use case:
			// separate user-account context (profile, messages, notifications,
			// logout) from site navigation (categories, pages) — common pattern
			// in messaging / social apps. Mirrors `side_menu` config minus the
			// `position` field (always anchored right).
			//
			// Default disabled — apps shipped before 2026-05-15 will keep their
			// single-menu behavior. Schema is additive, so payloads from older
			// engines (no `right_menu` key) flow through harmlessly and the
			// mobile-app code feature-detects this field's presence.
			'right_menu' => [
				'type' => 'object',
				'label' => __( 'Right Side Menu', 'appress' ),
				'sanitize' => 'object',
				'default' => [],
				'fields' => [
					'enabled' => [ 'type' => 'boolean', 'label' => __( 'Enable Right Side Menu', 'appress' ), 'sanitize' => 'boolean', 'default' => false, 'ui' => [ 'group' => 'right_menu_main', 'col_span' => 2, 'hint' => __( 'Drawer content comes from the App Screen marked with the "Right Menu Screen" role. Set the role on that screen below.', 'appress' ) ] ],
					'width_percent' => [ 'type' => 'number', 'label' => __( 'Width (% of screen)', 'appress' ), 'sanitize' => 'number', 'default' => 90, 'ui' => [ 'group' => 'right_menu_main', 'col_span' => 2, 'placeholder' => '90', 'show_if' => 'enabled' ] ],
					'background_color' => [ 'type' => 'color', 'label' => __( 'Background color', 'appress' ), 'sanitize' => 'text', 'default' => '#ffffff', 'ui' => [ 'group' => 'right_menu_main', 'col_span' => 2, 'show_if' => 'enabled' ] ],
				]
			],

			// First-launch screen — fires once, on the very first cold-start
			// after install. Mirrors Side Menu config shape (URL | App Screen).
			// Typical use: onboarding slider, TOS accept, permission priming
			// before the OS push-permission modal. Admin designs the page in
			// WordPress with any builder; native just opens it as a full-screen
			// modal and flips `has_launched=true` in local storage so it never
			// shows again on the device. Reinstall clears the flag.
			'first_launch' => [
				'type' => 'object',
				'label' => __( 'First Launch Screen', 'appress' ),
				'sanitize' => 'object',
				'default' => [],
				'fields' => [
					'enabled' => [ 'type' => 'boolean', 'label' => __( 'Enable First Launch Screen', 'appress' ), 'sanitize' => 'boolean', 'default' => false, 'ui' => [ 'group' => 'first_launch_main', 'col_span' => 2 ] ],
					'type' => [
						'type' => 'select',
						'label' => __( 'Content Source', 'appress' ),
						'sanitize' => 'text',
						'default' => 'screen',
						'ui' => [
							'group' => 'first_launch_main',
							'show_if' => 'enabled',
							'hint' => __( 'Load an external URL, or point to an existing App Screen so the launch page reuses its URL.', 'appress' ),
							'options' => [
								[ 'value' => 'url',    'label' => 'Custom URL' ],
								[ 'value' => 'screen', 'label' => 'App Screen' ],
							]
						]
					],
					'url' => [ 'type' => 'url', 'label' => __( 'URL', 'appress' ), 'sanitize' => 'url', 'default' => '', 'ui' => [ 'group' => 'first_launch_main', 'placeholder' => 'https://example.com/welcome', 'show_if' => 'enabled && type=url' ] ],
					'screen_id' => [ 'type' => 'text', 'label' => __( 'Linked App Screen', 'appress' ), 'sanitize' => 'text', 'default' => '', 'ui' => [ 'group' => 'first_launch_main', 'show_if' => 'enabled && type=screen' ] ],
				]
			],

			// Auth gate — when enabled, the native runtime refuses to render
			// tabs / bottom nav / side menu on cold start until the user is
			// logged in. Only the First Launch Screen (if configured) and the
			// screen with `role=auth` are visible. Once the login cookie
			// (`wordpress_logged_in_*`) appears, the gate tears down and the
			// normal layout bootstraps. Designed for apps that are login-only
			// (members-only communities, internal tools).
			'require_auth_to_open' => [
				'type' => 'boolean',
				'label' => __( 'Require authentication to open the app', 'appress' ),
				'sanitize' => 'boolean',
				'default' => false,
				'ui' => [
					'group' => 'require_auth',
					'col_span' => 2,
					'hint' => ''
				]
			],

			// ── In-App Purchase (StoreKit / Google Play Billing) ─────────
			// Platform-level master switches. When off, the Appress IAP
			// gateway + native purchase flow are bypassed — carts with
			// IAP-flagged products fall through to regular web checkout
			// (Stripe / COD / etc.). Apple / Google REQUIRE IAP for digital
			// content; flipping these on is how the admin signals "yes, I
			// have ticked all the Apple/Google prerequisites — ship it".
			//
			// Prerequisites that the admin has to handle OUTSIDE Appress:
			//   iOS      — Paid Applications Agreement signed, each product
			//              created in App Store Connect with matching product id
			//   Android  — Merchant account linked, each product created in
			//              Play Console, service account granted "View
			//              financial data" + "Manage orders" (on top of
			//              the existing Release Manager role used for
			//              publishing).
			'iap_enabled_ios' => [
				'type' => 'boolean',
				'label' => __( 'Enable In-App Purchase (iOS)', 'appress' ),
				'sanitize' => 'boolean',
				'default' => false,
				'ui' => [
					'group' => 'iap',
					'col_span' => 1,
					'hint' => __( 'Route digital-content purchases through StoreKit. Required by Apple for non-physical goods. Needs App Store Connect products + Paid Applications Agreement signed.', 'appress' )
				]
			],
			'iap_enabled_android' => [
				'type' => 'boolean',
				'label' => __( 'Enable In-App Purchase (Android)', 'appress' ),
				'sanitize' => 'boolean',
				'default' => false,
				'ui' => [
					'group' => 'iap',
					'col_span' => 1,
					'hint' => __( 'Route digital-content purchases through Google Play Billing. Required by Google for non-physical goods. Needs Play Console products + service account granted financial roles.', 'appress' )
				]
			],
			'iap_sandbox' => [
				'type' => 'boolean',
				'label' => __( 'Sandbox mode', 'appress' ),
				'sanitize' => 'boolean',
				'default' => true,
				'ui' => [
					'group' => 'iap',
					'col_span' => 2,
					'hint' => __( 'Verify purchases against Apple / Google sandbox endpoints (tester accounts, no real money). Turn OFF for production builds AFTER confirming the full purchase → verify → order-complete flow works end-to-end.', 'appress' )
				]
			],
			// Admin-UX niceties: let the per-product mapping panel deep-
			// link into the exact IAP section of each store's console.
			// Bundle ID / package name isn't enough — Apple + Google use
			// internal numeric ids in their URLs, which the admin has to
			// grab once (visible in the browser URL bar when they're in
			// the console for that app). Optional — empty values fall
			// back to the generic landing page.
			'iap_apple_app_id' => [
				'type' => 'text',
				'label' => __( 'App Store Connect numeric App ID', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [
					'group' => 'iap',
					'col_span' => 1,
					'placeholder' => '1234567890',
					'hint' => __( 'Optional. Numeric ID Apple assigns when you create the app in App Store Connect — visible in the URL: `appstoreconnect.apple.com/apps/<b>1234567890</b>/…`. When set, the IAP mapping UI deep-links straight into the In-App Purchases section.', 'appress' )
				]
			],
			'iap_google_play_app_id' => [
				'type' => 'text',
				'label' => __( 'Play Console internal App ID', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [
					'group' => 'iap',
					'col_span' => 1,
					'placeholder' => '4976237481231234567',
					'hint' => __( 'Optional. Long numeric ID visible in the Play Console URL: `play.google.com/console/u/0/developers/NNN/app/<b>MMM</b>/app-dashboard`. When set, the IAP mapping UI deep-links straight into Managed products / Subscriptions.', 'appress' )
				]
			],
			'iap_google_play_developer_id' => [
				'type' => 'text',
				'label' => __( 'Play Console Developer ID', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [
					'group' => 'iap',
					'col_span' => 2,
					'placeholder' => '8147632009876543210',
					'hint' => __( 'Optional. Long numeric ID from `play.google.com/console/u/0/developers/<b>NNN</b>/app/…`. Required alongside the app id for Play Console deep-links to work.', 'appress' )
				]
			],

			// ── Smart prefetch (server-side Cache-Control relax) ────────
			// The native slave WebViews inject a JS snippet that silently
			// fetches anchor targets entering the viewport (see
			// `AppressSlaveJSService.prefetchOnVisibleJS`). That warm-up
			// only speeds up subsequent taps if the server allows the
			// response to be cached — WordPress' default `no-store,
			// no-cache` for logged-in users would throw the prefetched
			// body away. These two fields let the admin relax Cache-
			// Control per-app, scoped to requests coming from the app
			// (UA check via `\Appress\is_app()`) and to paths that are
			// safe to briefly cache. Cart / checkout / account paths
			// keep `no-store` so live state stays fresh.
			'prefetch_cache_duration' => [
				'type' => 'number',
				'label' => __( 'Prefetch cache duration (seconds)', 'appress' ),
				'sanitize' => 'number',
				'default' => 300,
				'ui' => [
					'group' => 'prefetch',
					'col_span' => 1,
					'placeholder' => '300',
					'hint' => __( 'How long the WebView keeps a prefetched page in its HTTP cache', 'appress' )
				]
			],
			'prefetch_cache_exclude_paths' => [
				'type' => 'textarea',
				'label' => __( 'Paths excluded from prefetch caching', 'appress' ),
				'sanitize' => 'textarea',
				'default' => "/cart\n/checkout\n/my-account\n/wp-admin\n/wp-login.php\n?add-to-cart=\n?logout=",
				'ui' => [
					'group' => 'prefetch',
					'col_span' => 1,
					'placeholder' => "/cart\n/checkout\n/my-account",
					'hint' => __( 'One path fragment per line', 'appress' )
				]
			],

			// Links whose targets should load INSIDE the current screen instead
			// of pushing a new subscreen modal. Admins enter one CSS selector
			// per line (e.g. `.woocommerce-MyAccount-navigation a`). PHP
			// integrations contribute additional selectors at runtime via the
			// `appress/app/inline_link_selectors` filter — see
			// `integration/woocommerce/controllers/inline-links-controller.php`
			// for the default Woo set. Stored as textarea (raw newline-
			// separated) so admins can paste freely; the boot endpoint
			// normalizes to an array and merges with the filter.
			'inline_link_selectors' => [
				'type' => 'textarea',
				'label' => __( 'Stay-on-page link selectors', 'appress' ),
				'sanitize' => 'textarea',
				'default' => '',
				'ui' => [
					'group' => 'inline_links',
					'col_span' => 2,
					'placeholder' => ".my-tabs a\n#sub-nav a",
					'hint' => __( 'One CSS selector per line.', 'appress' ),
				],
			],

			// URL patterns whose targets should ALWAYS open in a new
			// subscreen, overriding the default in-place behaviour for
			// JS-driven navigations (search forms, archive filters, …).
			// Mirrors `inline_link_selectors` but operates on the
			// destination URL instead of the click element — catches
			// keyboard Enter on search inputs, programmatic
			// `window.location.href` calls, and notification deep-links
			// uniformly (click-based selectors would miss those paths).
			//
			// Glob syntax: `*` = any chars, `?` = one char. Tested
			// against pathname + querystring. Examples:
			//   `/listings/*`
			//   `/?s=*`
			//   `*?orderby=*`
			//
			// Integrations contribute defaults via the
			// `appress/app/subscreen_url_patterns` filter — see
			// `integration/voxel/controllers/subscreen-patterns-controller.php`
			// for the Voxel archive/page submit auto-detection.
			//
			// Conflict resolution: if a click on an element matching
			// `inline_link_selectors` targets a URL also matching
			// `subscreen_url_patterns`, the element selector WINS (in-place).
			// Reasoning: admin's element-specific intent is more precise than
			// a URL-pattern blanket. The native interceptor checks element
			// match first; URL pattern only fires when no inline-link match.
			'subscreen_url_patterns' => [
				'type' => 'textarea',
				'label' => __( 'Open in subscreen (URL patterns)', 'appress' ),
				'sanitize' => 'textarea',
				'default' => '',
				'ui' => [
					'group' => 'inline_links',
					'col_span' => 2,
					'placeholder' => "/?s=*\n/listings/*\n*?orderby=*",
					'hint' => __( 'One URL pattern per line. * matches any characters. Auto-applied to Voxel post-type archives.', 'appress' ),
				],
			],

			'app_screens' => [
				'type' => 'repeater',
				'label' => __( 'App Screens', 'appress' ),
				'sanitize' => 'repeater',
				'default' => [],
				'fields' => [
					// Identity / routing keys — never rendered as form controls,
					// populated by the repeater's header (add-screen dropdown,
					// WP post picker, URL field). Kept in schema so sanitize +
					// whitelist accept them on save.
					'_id' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => '' ],
					'wp_id' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => '' ],
					'type' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => 'custom_url' ],
					'title' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => '' ],
					'url' => [ 'type' => 'text', 'sanitize' => 'url', 'default' => '' ],
					'icon' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => '' ],
					// Screen role — a single enum tag for "named" surfaces in
					// the app (home / auth / notifications / menu). Cross-item
					// exclusivity (only one screen per non-none role) is enforced
					// by the Vue repeater on save — schema-level sanitize doesn't
					// see the sibling rows. Native consumers resolve a role to a
					// URL by scanning `app_screens` for the row with the matching
					// role, which frees the admin from pasting the same URL into
					// multiple settings (default screen, auth gate, push deep
					// links, etc.).
					'role' => [
						'type' => 'select', 'sanitize' => 'text', 'default' => 'none',
						'label' => __( 'Screen Role', 'appress' ),
						'ui' => [
							'group' => 'identity',
							'options' => [
								[ 'value' => 'none',          'label' => __( 'None',                'appress' ) ],
								[ 'value' => 'home',          'label' => __( 'Homescreen',          'appress' ) ],
								[ 'value' => 'auth',          'label' => __( 'Auth Screen',         'appress' ) ],
								[ 'value' => 'notifications', 'label' => __( 'Notification Screen', 'appress' ) ],
								// Backward compat: "Menu Screen" is the LEFT side menu's
								// content source. Pre-2026-05-15 this was simply "the menu".
								[ 'value' => 'menu',          'label' => __( 'Left Menu Screen',    'appress' ) ],
								// Added 2026-05-15 for the optional right-side drawer.
								// Apps that never enable `right_menu` ignore this role
								// → backward compat preserved.
								[ 'value' => 'right_menu',    'label' => __( 'Right Menu Screen',   'appress' ) ],
							]
						]
					],
					'reload_on_click' => [
						'type' => 'boolean', 'sanitize' => 'boolean', 'default' => true,
						'label' => __( 'Reload on reclick', 'appress' ),
						'ui' => [ 'group' => 'behavior' ]
					],
					'always_reload' => [
						'type' => 'boolean', 'sanitize' => 'boolean', 'default' => false,
						'label' => __( 'Always reload', 'appress' ),
						'ui' => [ 'group' => 'behavior' ]
					],
					'show_web_header' => [
						'type' => 'boolean', 'sanitize' => 'boolean', 'default' => false,
						'label' => __( 'Show Web Header', 'appress' ),
						'ui' => [ 'group' => 'behavior', 'show_if' => 'type=appress_screen' ]
					],
					'show_web_footer' => [
						'type' => 'boolean', 'sanitize' => 'boolean', 'default' => false,
						'label' => __( 'Show Web Footer', 'appress' ),
						'ui' => [ 'group' => 'behavior', 'show_if' => 'type=appress_screen' ]
					],
					'use_general_config' => [
						'type' => 'boolean', 'sanitize' => 'boolean', 'default' => true,
						'label' => __( 'Use Default Configuration', 'appress' ),
						'ui' => [ 'group' => 'behavior' ]
					],
					'pull_to_refresh' => [
						'type' => 'boolean', 'sanitize' => 'boolean', 'default' => false,
						'label' => __( 'Pull to refresh', 'appress' ),
						'ui' => [ 'group' => 'behavior', 'show_if' => '!use_general_config' ]
					],
					'offline' => [
						'type' => 'boolean', 'sanitize' => 'boolean', 'default' => true,
						'label' => __( 'Offline', 'appress' ),
						'ui' => [ 'group' => 'behavior' ]
					],
					'preload' => [
						'type' => 'boolean', 'sanitize' => 'boolean', 'default' => false,
						'label' => __( 'Preload', 'appress' ),
						'ui' => [
							'group' => 'preload',
							'col_span' => 2,
							'hint' => ''
						]
					],
					'background_color' => [
						'type' => 'color', 'sanitize' => 'text', 'default' => '',
						'label' => __( 'Background Color', 'appress' ),
						'ui' => [ 'group' => 'appearance', 'show_if' => '!use_general_config' ]
					],
				]
			],

			// ── Disable Web Ads (hide existing site ads when viewed in-app) ──
			'disable_web_ads' => [
				'type' => 'boolean',
				'label' => __( 'Disable web ads inside app', 'appress' ),
				'sanitize' => 'boolean',
				'default' => false,
				'ui' => [
					'group' => 'disable_web_ads',
					'hint' => __( 'Prevent your website\'s existing ad platforms from loading inside the app — cleaner in-app experience.', 'appress' )
				]
			],
			'disable_web_ads_platforms' => [
				'type' => 'object',
				'label' => __( 'Select your current web ad platforms', 'appress' ),
				'sanitize' => 'object',
				'default' => [],
				'ui' => [
					'group' => 'disable_web_ads',
					'show_if' => 'disable_web_ads',
					'hint' => __( 'Tick every platform your website currently uses. We\'ll block those networks\' scripts from loading when users view your site in the app.', 'appress' )
				],
				'fields' => [
					'adsense' => [
						'type' => 'boolean',
						'label' => __( 'Google AdSense / Ad Manager', 'appress' ),
						'sanitize' => 'boolean',
						'default' => true
					],
					'header_bidding' => [
						'type' => 'boolean',
						'label' => __( 'Header Bidding (Prebid, AppNexus, PubMatic, OpenX, Rubicon)', 'appress' ),
						'sanitize' => 'boolean',
						'default' => true
					],
					'ezoic' => [
						'type' => 'boolean',
						'label' => __( 'Ezoic', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false
					],
					'mediavine' => [
						'type' => 'boolean',
						'label' => __( 'Mediavine', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false
					],
					'raptive' => [
						'type' => 'boolean',
						'label' => __( 'AdThrive / Raptive', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false
					],
					'media_net' => [
						'type' => 'boolean',
						'label' => __( 'Media.net', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false
					],
					'taboola_outbrain' => [
						'type' => 'boolean',
						'label' => __( 'Taboola / Outbrain (content recommendation)', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false
					],
					'amazon_ads' => [
						'type' => 'boolean',
						'label' => __( 'Amazon Publisher Services', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false
					],
					'criteo' => [
						'type' => 'boolean',
						'label' => __( 'Criteo (retargeting)', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false
					]
				]
			],
			'disable_web_ads_custom_hosts' => [
				'type' => 'textarea',
				'label' => __( 'Advanced: custom hosts (one per line)', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [
					'group' => 'disable_web_ads',
					'show_if' => 'disable_web_ads',
					'placeholder' => "adserver.example.com\nads.my-network.io",
					'hint' => __( 'Add any extra ad-serving hostnames not covered by the presets above. Paths are not supported — hostnames only.', 'appress' )
				]
			],
		]
	],
	'credentials' => [
		'fields' => [
			'firebase_service_account' => [
				'type' => 'file_drag_drop',
				'label' => __( 'Firebase Service Account JSON', 'appress' ),
				'sanitize' => 'file_content',
				'default' => '',
				'ui' => [
					'group' => 'firebase_credentials',
					'accept' => '.json',
					'filename' => 'service-account.json',
					'hint' => __( 'Download from Firebase Console → Project Settings → Service Accounts → Generate new private key.', 'appress' )
				]
			],

			// ── Android Signing (Customer keystore override — optional) ──
			// If omitted, Central auto-generates a keystore per app. Provide
			// your own to keep ownership portable across SaaS providers or to
			// match an existing Play Console app's upload certificate.
			'android_keystore_file' => [
				'type' => 'file_drag_drop',
				'label' => __( 'Android Keystore (.jks / .p12)', 'appress' ),
				'sanitize' => 'file_content',
				'default' => '',
				'ui' => [
					'group' => 'android_signing',
					'col_span' => 2,
					'accept' => '.jks,.p12,.keystore',
					'filename' => 'release.jks',
					'hint' => __( 'Optional override. Leave empty to use the keystore auto-generated by Appress for this app.', 'appress' )
				]
			],
			'android_keystore_password' => [
				'type' => 'text',
				'label' => __( 'Keystore Password', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [
					'group' => 'android_signing',
					'show_if' => 'android_keystore_file'
				]
			],
			'android_keystore_alias' => [
				'type' => 'text',
				'label' => __( 'Keystore Alias', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [
					'group' => 'android_signing',
					'placeholder' => 'release',
					'show_if' => 'android_keystore_file'
				]
			],
			// Play Console anti-squatting verification — Google requires this
			// when a package name was previously registered, when its domain
			// is recognised, or when the account is newly flagged. The token
			// is baked into the APK as `assets/adi-registration.properties`
			// so a fresh build proves private-key ownership in one upload.
			// One-time per package; safe to keep filled across rebuilds.
			'play_console_verification_token' => [
				'type' => 'text',
				'label' => __( 'Play Console Verification Token', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'ui' => [
					'group' => 'android_signing',
					'col_span' => 2,
					'placeholder' => 'DZCDFK3SVAH4G...',
					'hint' => __( 'Paste the token from Play Console\'s "Sign and upload an APK" screen. Leave empty unless Google asked you to verify the package name.', 'appress' )
				]
			],

			// ── iOS — App Store Connect API Key (required for build signing) ──
			// Xcode uses this key to fetch/create Development profiles and sign
			// the .ipa on-the-fly. Also used for TestFlight / App Store upload
			// in the same build pipeline. Without it Build Engine cannot ship
			// an installable .ipa for the customer's iPhone.
			'apple_appstore_key_id' => [
				'type' => 'text',
				'label' => __( 'App Store Connect Key ID', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'required_for' => [ 'ios' ],
				'ui' => [
					'group' => 'ios_signing',
					'col_span' => 1,
					'placeholder' => '1A2B3C4D5E',
					'hint' => __( 'App Store Connect → Users and Access → Integrations → App Store Connect API → Generate. Role: Admin.', 'appress' )
				]
			],
			'apple_appstore_issuer_id' => [
				'type' => 'text',
				'label' => __( 'App Store Connect Issuer ID', 'appress' ),
				'sanitize' => 'text',
				'default' => '',
				'required_for' => [ 'ios' ],
				'ui' => [
					'group' => 'ios_signing',
					'col_span' => 1,
					'placeholder' => '69a6de70-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
					'hint' => __( 'UUID shown above the API Keys table in App Store Connect.', 'appress' )
				]
			],
			'apple_appstore_key_p8' => [
				'type' => 'file_drag_drop',
				'label' => __( 'App Store Connect API Key (.p8)', 'appress' ),
				'sanitize' => 'file_content',
				'default' => '',
				'required_for' => [ 'ios' ],
				'ui' => [
					'group' => 'ios_signing',
					'col_span' => 2,
					'accept' => '.p8',
					'filename' => 'AuthKey.p8',
					'hint' => __( 'Private key file downloaded once from App Store Connect. Keep safe — Apple never shows it again.', 'appress' )
				]
			],

			// ── Android — Google Play Developer API service account ──
			// One service account, two downstream uses:
			//   1. Auto-publish AAB to Play Store tracks (needs Release Manager role).
			//   2. Verify IAP receipts via Android Publisher API (needs View
			//      Financial Data + Manage Orders on top of Release Manager).
			// Grant all three roles up-front so enabling IAP later doesn't
			// require the admin to re-generate or re-link the key.
			'google_play_service_account_json' => [
				'type' => 'file_drag_drop',
				'label' => __( 'Google Play Service Account JSON', 'appress' ),
				'sanitize' => 'file_content',
				'default' => '',
				'ui' => [
					'group' => 'store_publishing',
					'col_span' => 2,
					'accept' => '.json',
					'filename' => 'play-service-account.json',
					'hint' => __( 'Play Console → Setup → API access → Service accounts. Grant roles: Release Manager (auto-publish AAB) + View Financial Data + Manage Orders (required for In-App Purchase receipt verification).', 'appress' )
				]
			],
		]
	],
	'i18n' => [
		'errors' => [
			'invalid_request' => __( 'Invalid request.', 'appress' ),
			'save_failed'     => __( 'Could not save settings.', 'appress' ),
		],
		'messages' => [
			'saved_success' => __( 'Settings saved successfully.', 'appress' ),
		],
		'ui' => [
			'save_btn'   => __( 'Save Changes', 'appress' ),
			'cancel_btn' => __( 'Cancel', 'appress' ),
		]
	]
];
