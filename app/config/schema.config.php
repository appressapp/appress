<?php

namespace Appress;

if ( ! defined('ABSPATH') ) {
	exit;
}

return [
	'build_config' => [
		'fields' => [
			'title' => [
				'type' => 'text',
				'label' => __( 'App Title', 'appress' ),
				'sanitize' => 'text',
				'default' => 'Untitled App',
				'required' => true,
				'ui' => [ 'group' => 'app_info', 'placeholder' => __( 'E.g. My Awesome App', 'appress' ) ]
			],
			// Website URL lives on the Central app post's `website` field now,
			// not in client build_config. Central injects it into the build
			// payload directly (`Build_Controller::trigger_build_engine`), so
			// the client neither stores nor forwards a root URL any more.
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
					// Only Default mode renders the logo on a bg color. In
					// Custom Image mode the engine strips
					// `APPRESS-SPLASH-DEFAULT` blocks entirely and the
					// remaining IMAGE block hardcodes a black backdrop the
					// image's `CENTER_CROP` immediately covers — admin's
					// chosen color would never paint a single visible
					// pixel.
					'show_if' => 'splash_type=default',
					'hint' => __( 'Behind the logo while the app boots.', 'appress' ),
					// Native splash code reads this value at boot, before any
					// page paints — there's no DOM / `:root` to resolve a CSS
					// variable against. Force a plain hex string by hiding the
					// CSS-var toggle in `ColorInput`.
					'enable_css_var' => false,
				]
			],
			'splash_show_loading_bar' => [
				'type' => 'boolean',
				'label' => __( 'Show Loading Bar', 'appress' ),
				'sanitize' => 'boolean',
				'default' => true,
				'ui' => [
					'group' => 'splash_screen',
					// Loading pill is part of the Default block; the IMAGE
					// block renders nothing but the full-bleed image and
					// has no progress affordance to toggle.
					'show_if' => 'splash_type=default',
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
					// Only used by Custom Image mode — Default mode never
					// downloads or references this asset (engine
					// short-circuits `02-logo-processor` when
					// `splash_type !== 'image'`).
					'show_if' => 'splash_type=image',
					'hint' => __( 'Portrait image recommended. Use a high resolution (at least 1290×2796) so it stays crisp on large phones. The image is center-cropped to fit each device screen.', 'appress' )
				]
			],
			// ── Home Screen ──────────────────────────────────────────────
			// The URL native loads as the default screen when the app
			// opens. `type` is a UX switch — `url` for free-text input,
			// any other value (post-type slug like `page`, `product`,
			// `appress_screen`, or `any`) for the Content Source picker
			// that fetches matching posts from `posts.search`. Native
			// consumes only `url`, so the picker choice doesn't change
			// the stored shape.
			'home_screen' => [
				'type' => 'object',
				'label' => __( 'Home Screen', 'appress' ),
				'sanitize' => 'object',
				'default' => [],
				'fields' => [
					'type' => [
						// `select` with `sanitize: text` — Vue builds the
						// dropdown options dynamically from
						// `Apppress_Config.post_types` (every public CPT)
						// plus the `url` static entry. Schema `options`
						// stays empty since the list is runtime-determined.
						'type' => 'select',
						'label' => __( 'Content Source', 'appress' ),
						'sanitize' => 'text',
						'default' => 'page',
						'ui' => [
							'group' => 'home_screen',
							'hint' => __( 'Pick a Page / Post / Appress Screen / any post type — or enter a custom URL.', 'appress' ),
						]
					],
					'url' => [
						'type' => 'url',
						'label' => __( 'URL', 'appress' ),
						'sanitize' => 'url',
						'default' => '',
						'ui' => [
							'group' => 'home_screen',
							'placeholder' => 'https://example.com/'
						]
					],
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
			// build_config (plaintext) rather than credentials because the
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

			// Supported Devices — drives the iOS `TARGETED_DEVICE_FAMILY`
			// build setting (1=iPhone, 2=iPad, "1,2"=Universal). The Build
			// Engine reads these from the build_config payload and rewrites
			// the App target's device family. Both default true (Universal).
			// iOS-only: Android has no equivalent — every APK runs on all
			// touchscreen form factors by design.
			'ios_support_iphone' => [
				'type' => 'boolean',
				'label' => __( 'iPhone', 'appress' ),
				'sanitize' => 'boolean',
				'default' => true,
				'ui' => [
					'group' => 'ios_devices',
					'hint' => __( 'Make the app available on iPhone.', 'appress' ),
				]
			],
			'ios_support_ipad' => [
				'type' => 'boolean',
				'label' => __( 'iPad', 'appress' ),
				'sanitize' => 'boolean',
				'default' => true,
				'ui' => [
					'group' => 'ios_devices',
					'hint' => __( 'Make the app available on iPad.', 'appress' ),
				]
			],


		
			// package_id stays inside build_config JSON — the Build Engine
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
			// (not in the main form) but must persist in build_config
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
						'label' => __( 'Push Notifications', 'appress' ),
						'sanitize' => 'boolean',
						'default' => true,
						'ui' => [
							'group' => 'native_features',
							'tier'  => 'basic',
							'doc_url' => 'https://docs.appress.app/native-features/push-notifications'
						]
					],
				]
			],

			'biometric' => [
				'type' => 'object',
				'label' => __( 'Biometric Authentication', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => false ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Biometric (Face ID / Touch ID)', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false,
						'ui' => [
							'group' => 'native_features',
							'tier'  => 'advanced',
							'doc_url' => 'https://docs.appress.app/native-features/biometric'
						]
					],
				]
			],

			'google_auth' => [
				'type' => 'object',
				'label' => __( 'Google Sign-In', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => false ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Google Sign-In', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false,
						'ui' => [
							'group' => 'native_features',
							'tier'  => 'advanced',
							'doc_url' => 'https://docs.appress.app/native-features/google-sign-in'
						]
					],
				]
			],

			'apple_auth' => [
				'type' => 'object',
				'label' => __( 'Sign in with Apple', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => false ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Sign in with Apple', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false,
						'ui' => [
							'group' => 'native_features',
							'tier'  => 'advanced',
							'doc_url' => 'https://docs.appress.app/native-features/sign-in-with-apple'
						]
					],
				]
			],

			'qr_scanner' => [
				'type' => 'object',
				'label' => __( 'Login by QR', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => false ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Login by QR', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false,
						'ui' => [
							'group' => 'native_features',
							'tier'  => 'advanced',
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
						'label' => __( 'Geolocation', 'appress' ),
						'sanitize' => 'boolean',
						'default' => true,
						'ui' => [
							'group' => 'native_features',
							'tier'  => 'basic',
							'doc_url' => 'https://docs.appress.app/native-features/geolocation'
						]
					],
				]
			],

			// Photo & Camera Access — owns the camera + photo library
			// permission surface used by `<input type="file">` capture
			// flow + avatar uploaders. Default ON because the most
			// common customer surface (profile photo upload, WC product
			// review) triggers one of these prompts.
			'photo_camera' => [
				'type' => 'object',
				'label' => __( 'Photo & Camera Access', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => true ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Photo & Camera Access', 'appress' ),
						'sanitize' => 'boolean',
						'default' => true,
						'ui' => [
							'group' => 'native_features',
							'tier'  => 'basic',
							'doc_url' => 'https://docs.appress.app/native-features/photo-camera'
						]
					],
				],
			],

			// Microphone Access — separate from Photo & Camera because
			// most WordPress sites never trigger a microphone prompt
			// (rare: WebRTC voice/video chat plugins, voice-note
			// messengers, audio recorders). Default OFF so a typical
			// listing / e-commerce / blog ships without
			// `NSMicrophoneUsageDescription`, keeping the plist
			// fingerprint clean + avoiding the unused-permission
			// flag Apple sometimes raises during review.
			'microphone' => [
				'type' => 'object',
				'label' => __( 'Microphone Access', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => false ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Microphone Access', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false,
						'ui' => [
							'group' => 'native_features',
							'tier'  => 'advanced',
							'doc_url' => 'https://docs.appress.app/native-features/microphone'
						]
					],
				],
			],

			// ── iOS Permission Descriptions ────────────────────────────────
			// Shared Info.plist usage strings — each entry lives ONCE here
			// and declares which native features require it via
			// `ui.requires`. The Vue admin renders a single block in
			// build-features (separate card from Basic / Advanced toggles)
			// and shows each field when ANY listed feature is enabled
			// (`requires: []` or missing = always visible). The Build
			// Engine reads each string from `configData.ios_permissions
			// .<key>`, falls back to the default, and injects it into the
			// matching plist key set — `location` fills all three
			// NSLocation* variants, `camera` is shared by web_media + qr,
			// etc.
			//
			// Two reasons for the shared block instead of per-feature
			// children:
			//   1. Same string CAN be required by multiple features
			//      (Camera → web_media OR qr_scanner). Putting it on one
			//      feature would leave the other branch without input;
			//      duplicating it would give admin two boxes for the same
			//      Info.plist key.
			//   2. Apple's 4.3(a) cross-account similarity ML reads
			//      plist `__cstring` literals straight out of the binary.
			//      Hardcoded boilerplate strings shipped identical across
			//      every Appress build — admin typing their own wording
			//      varies the fingerprint per customer.
			'ios_permissions' => [
				'type' => 'object',
				'label' => __( 'iOS Permission Descriptions', 'appress' ),
				'sanitize' => 'object',
				'default' => [
					'location'          => '',
					'camera'            => '',
					'microphone'        => '',
					'photo_library'     => '',
					'photo_library_add' => '',
					'face_id'           => '',
				],
				'fields' => [
					'location' => [
						'type' => 'textarea',
						'label' => __( 'Location', 'appress' ),
						'sanitize' => 'textarea',
						'default' => '',
						'required_if' => [ 'geolocation.enabled' => true ],
						'ui' => [
							'group' => 'ios_permissions',
							'requires' => [ 'geolocation' ],
							'rows' => 2,
							'placeholder' => __( 'Why your app needs location.', 'appress' ),
						],
					],
					'camera' => [
						'type' => 'textarea',
						'label' => __( 'Camera', 'appress' ),
						'sanitize' => 'textarea',
						'default' => '',
						'required_if_any' => [ 'photo_camera.enabled' => true, 'qr_scanner.enabled' => true ],
						'ui' => [
							'group' => 'ios_permissions',
							'requires' => [ 'photo_camera', 'qr_scanner' ],
							'rows' => 2,
							'placeholder' => __( 'Why your app uses the camera.', 'appress' ),
						],
					],
					'microphone' => [
						'type' => 'textarea',
						'label' => __( 'Microphone', 'appress' ),
						'sanitize' => 'textarea',
						'default' => '',
						'required_if' => [ 'microphone.enabled' => true ],
						'ui' => [
							'group' => 'ios_permissions',
							'requires' => [ 'microphone' ],
							'rows' => 2,
							'placeholder' => __( 'Why your app uses the microphone.', 'appress' ),
						],
					],
					'photo_library' => [
						'type' => 'textarea',
						'label' => __( 'Photo Library (Read)', 'appress' ),
						'sanitize' => 'textarea',
						'default' => '',
						'required_if' => [ 'photo_camera.enabled' => true ],
						'ui' => [
							'group' => 'ios_permissions',
							'requires' => [ 'photo_camera' ],
							'rows' => 2,
							'placeholder' => __( 'Why your app reads photos.', 'appress' ),
						],
					],
					'photo_library_add' => [
						'type' => 'textarea',
						'label' => __( 'Photo Library (Save)', 'appress' ),
						'sanitize' => 'textarea',
						'default' => '',
						'required_if' => [ 'photo_camera.enabled' => true ],
						'ui' => [
							'group' => 'ios_permissions',
							'requires' => [ 'photo_camera' ],
							'rows' => 2,
							'placeholder' => __( 'Why your app saves photos.', 'appress' ),
						],
					],
					'face_id' => [
						'type' => 'textarea',
						'label' => __( 'Face ID', 'appress' ),
						'sanitize' => 'textarea',
						'default' => '',
						'required_if' => [ 'biometric.enabled' => true ],
						'ui' => [
							'group' => 'ios_permissions',
							'requires' => [ 'biometric' ],
							'rows' => 2,
							'placeholder' => __( 'Why your app uses Face ID.', 'appress' ),
						],
					],
				],
			],

			// Subscreen — when enabled, link taps open the destination in a
			// pushed modal screen (slide-from-right) instead of replacing
			// the current WebView's URL. Disabling collapses that whole
			// secondary stack: clicks always replace the active WebView's
			// URL, no slide animation, no per-screen back stack, no
			// standalone container in the native layout. Disabled apps
			// also ship without the push/pop machinery in the binary —
			// reduces code surface for apps that want a simpler in-place
			// nav model (single-screen storefronts, in-app browsers).
			'subscreen' => [
				'type' => 'object',
				'label' => __( 'Subscreen', 'appress' ),
				'sanitize' => 'object',
				'default' => [ 'enabled' => true ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Subscreen', 'appress' ),
						'sanitize' => 'boolean',
						'default' => true,
						'ui' => [
							'group' => 'native_features',
							'tier'  => 'basic',
							'doc_url' => 'https://docs.appress.app/native-features/subscreen'
						]
					],
				]
			],

			'translatepress' => [
				'type' => 'object',
				'label' => __( 'TranslatePress', 'appress' ),
				'sanitize' => 'object',
				// `strings`                  → bottom-nav title translations
				// `app_string_translations`  → In-App Strings per-language
				//                              overrides; the build engine
				//                              emits a locale slot when at
				//                              least one key is filled, so
				//                              empty maps don't bloat the
				//                              binary's i18n dictionary.
				'default' => [ 'enabled' => false, 'strings' => [], 'app_string_translations' => [] ],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'TranslatePress', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false,
						'ui' => [
							// Native Features toggle. Visibility further
							// gated client-side on `hasTranslatePress` (the
							// plugin is installed + active) — see the Vue
							// `getNativeFeatureToggles` filter. Hide rather
							// than disable to keep the Features grid clean
							// for sites that haven't installed TRP yet.
							'group'              => 'native_features',
							'tier'               => 'advanced',
							'requires_plugin'    => 'translatepress',
							'doc_url'            => 'https://docs.appress.app/integrations/translatepress'
						]
					],
					// Per-app bottom-nav label translations keyed by
					// `{tab_id}.{lang_code}`. Native bakes this dict at
					// build time and looks up the active language's value
					// at runtime for each tab. Stored alongside the rest
					// of the app's build_config — single source of truth,
					// replaces the old per-app `appress_trp_integration_<id>`
					// option that lived on the standalone /appress-integrations
					// detail page. `dict` is the sanitizer for runtime-keyed
					// nested maps (see `Apps_Controller::sanitize_dict_recursive`);
					// `object` would require pre-declared `fields` which
					// can't enumerate dynamic tab ids.
					'strings' => [
						'type'     => 'dict',
						'sanitize' => 'dict',
						'default'  => [],
					],
					// In-App String translations per TP language. Shape:
					//   { <lang>: { <I18N_KEY>: <translated text>, ... }, ... }
					// Build Engine iterates the outer map and emits a
					// locale slot for any language that has at least one
					// filled key (empty languages don't bloat the binary).
					// Stored via the `dict` sanitizer because the outer
					// language keys (vi, fr, …) are runtime data, not
					// pre-declared.
					'app_string_translations' => [
						'type'     => 'dict',
						'sanitize' => 'dict',
						'default'  => [],
					],
					// iOS Permission Description translations per TP
					// language. Shape:
					//   { <lang>: { location: '…', camera: '…', … } }
					// Build Engine writes one `<lang>.lproj/InfoPlist.strings`
					// per language so iOS shows the matching wording in
					// the system permission popup based on device locale.
					// Same key set as `build_config.ios_permissions.fields`.
					// Empty language buckets are skipped.
					'ios_permission_translations' => [
						'type'     => 'dict',
						'sanitize' => 'dict',
						'default'  => [],
					],
				]
			],

			// connection_token + central_app_id intentionally NOT declared
			// here. They live in their own DB columns (connection_token is
			// encrypted). Declaring them would also duplicate the values
			// into this plaintext JSON, leaking the token at rest.

			// ============================================================
			//   MOVED FROM live_config (2026-06-02 — bake-everything refactor)
			//   Everything below ships into the compiled app binary at build
			//   time. The Build Engine reads this JSON, embeds it into
			//   AppressBakedConfig.{swift,java} as a string literal, and the
			//   native runtime boots from the constant — no runtime fetch
			//   of `app.boot` across customer apps. Live updates touch only
			//   the (now-trimmed) `live_config` block: analytics + smart
			//   prefetch + inline-link / subscreen URL patterns.
			// ============================================================

			// (Custom CSS fields moved to top-level `settings` category — printed live via wp_head.)

			// ── Bottom Navigation ────────────────────────────────────────
			'bottom_navigation' => [
				'type' => 'object',
				'label' => __( 'Bottom Navigation', 'appress' ),
				'sanitize' => 'object',
				'default' => [],
				'fields' => [
					// Surfaced as a Native Features toggle so it sits with
					// other build-time feature gates. Toggling OFF strips
					// AppressBottomNavView from the binary (mutator FEATURE_KEYS).
					'enabled' => [ 'type' => 'boolean', 'label' => __( 'Bottom Navigation', 'appress' ), 'sanitize' => 'boolean', 'default' => true, 'ui' => [ 'group' => 'native_features', 'tier' => 'basic' ] ],
					// Toggles
					'hide_on_scroll' => [ 'type' => 'boolean', 'label' => __( 'Hide on Scroll', 'appress' ), 'sanitize' => 'boolean', 'default' => true, 'ui' => [ 'group' => 'nav_toggles' ] ],
					'active_item_top_border' => [ 'type' => 'boolean', 'label' => __( 'Active item top border', 'appress' ), 'sanitize' => 'boolean', 'default' => true, 'ui' => [ 'group' => 'nav_toggles' ] ],
					'show_on_subscreen' => [ 'type' => 'boolean', 'label' => __( 'Show on subscreen', 'appress' ), 'sanitize' => 'boolean', 'default' => false, 'ui' => [ 'group' => 'nav_toggles', 'hint' => __( 'Keep the bottom nav visible when a subscreen is pushed on top of a tab.', 'appress' ), 'show_if' => 'subscreen.enabled' ] ],
					// Colors — light/default mode.
					'background_color' => [ 'type' => 'color', 'label' => __( 'Background Color', 'appress' ), 'sanitize' => 'text', 'default' => '#ffffff', 'ui' => [ 'group' => 'nav_colors' ] ],
					'active_color' => [ 'type' => 'color', 'label' => __( 'Active Color', 'appress' ), 'sanitize' => 'text', 'default' => '#000000', 'ui' => [ 'group' => 'nav_colors' ] ],
					'normal_color' => [ 'type' => 'color', 'label' => __( 'Normal Color', 'appress' ), 'sanitize' => 'text', 'default' => '#9ca3af', 'ui' => [ 'group' => 'nav_colors' ] ],
					// Dark-mode variants — rendered in a SEPARATE
					// `Dark Mode` section inside the Bottom Navigation
					// `Styles` card; the section itself is gated by
					// `dark_mode.enabled` so admins only see these
					// fields once Auto Dark Mode is toggled on in
					// Native Features. Native code (iOS dynamic
					// `UIColor`, Android `applyColors` reapply) swaps
					// runtime values on every OS appearance flip.
					// Empty values fall through to the light hex
					// above so partial setups still render coherently.
					'background_color_dark'         => [ 'type' => 'color', 'label' => __( 'Background Color', 'appress' ), 'sanitize' => 'text', 'default' => '#111827', 'ui' => [ 'group' => 'nav_dark_colors' ] ],
					'active_color_dark'             => [ 'type' => 'color', 'label' => __( 'Active Color', 'appress' ),    'sanitize' => 'text', 'default' => '#ffffff', 'ui' => [ 'group' => 'nav_dark_colors' ] ],
					'normal_color_dark'             => [ 'type' => 'color', 'label' => __( 'Normal Color', 'appress' ),    'sanitize' => 'text', 'default' => '#6b7280', 'ui' => [ 'group' => 'nav_dark_colors' ] ],
					'indicator_background_color_dark' => [ 'type' => 'color', 'label' => __( 'Indicator Background', 'appress' ), 'sanitize' => 'text', 'default' => '#ef4444', 'ui' => [ 'group' => 'nav_dark_colors' ] ],
					'indicator_color_dark'          => [ 'type' => 'color', 'label' => __( 'Indicator Color', 'appress' ), 'sanitize' => 'text', 'default' => '#ffffff', 'ui' => [ 'group' => 'nav_dark_colors' ] ],
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
							// `type` carries the picker mode: `custom_url`,
							// `menu_toggle`, or any public post type slug
							// (`page`, `post`, `product`, `appress_screen`,
							// `any`, …). `BottomNavigationSettings.vue` builds
							// the Action Type dropdown from `Apppress_Config.post_types`
							// + the two static specials. `screen_id` is
							// legacy storage from the pre-PostSearchPicker
							// shape — kept for backward-compat with rows
							// saved before; current saves leave it empty
							// (final URL lives on `url`).
							'type' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => 'page' ],
							'screen_id' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => '' ],
							'url' => [ 'type' => 'url', 'sanitize' => 'url', 'default' => '' ],
							'menu_target' => [ 'type' => 'text', 'sanitize' => 'text', 'default' => 'left' ],
							// Per-item behaviour toggles. `enabled=false`
							// keeps the item's config but excludes it
							// from the build (engine filters in
							// `01-pre-config.js` → bottom-nav array passed
							// to injectors only has enabled items, so the
							// disabled tab never lands in
							// `AppressBakedConfig`). `preload=true` makes
							// the router eager-load the tab's WebView at
							// bootstrap so a later tap paints from cache.
							// `pull_to_refresh=true` enables the gesture
							// on this tab's screen — native consumers
							// (`AppressConfigService.isScreenPullToRefresh`)
							// read it via the standard screenData lookup.
							'enabled'         => [ 'type' => 'boolean', 'sanitize' => 'boolean', 'default' => true ],
							'preload'         => [ 'type' => 'boolean', 'sanitize' => 'boolean', 'default' => true ],
							'pull_to_refresh' => [ 'type' => 'boolean', 'sanitize' => 'boolean', 'default' => true ],
							'indicator' => [ 'type' => 'select', 'sanitize' => 'text', 'default' => 'none' ],
							'custom_indicator_style'     => [ 'type' => 'boolean', 'sanitize' => 'boolean', 'default' => false ],
							'indicator_background_color' => [ 'type' => 'color', 'sanitize' => 'text', 'default' => '' ],
							'indicator_color'            => [ 'type' => 'color', 'sanitize' => 'text', 'default' => '' ],
							// Per-item FEATURED style — exactly ONE item may be
							// featured at a time (the Vue admin auto-unsets the
							// others on toggle). A featured tab renders as a
							// prominent raised button: its own background + icon
							// colours, corner radius, size, raised offset above the
							// bar, border, and box shadow. In the app the LABEL is
							// dropped (icon only) so the enlarged button never
							// collides with text. `is_featured=false` → normal tab.
							'is_featured'                  => [ 'type' => 'boolean', 'sanitize' => 'boolean', 'default' => false ],
							'featured_background_color'      => [ 'type' => 'color',   'sanitize' => 'text',    'default' => '' ],
							'featured_background_color_dark' => [ 'type' => 'color',   'sanitize' => 'text',    'default' => '' ],
							'featured_icon_color'            => [ 'type' => 'color',   'sanitize' => 'text',    'default' => '' ],
							'featured_icon_color_dark'       => [ 'type' => 'color',   'sanitize' => 'text',    'default' => '' ],
							'featured_corner_radius'         => [ 'type' => 'number',  'sanitize' => 'number',  'default' => 999 ],
							'featured_size'                  => [ 'type' => 'number',  'sanitize' => 'number',  'default' => 56 ],
							'featured_raise'                 => [ 'type' => 'number',  'sanitize' => 'number',  'default' => 8 ],
							'featured_border_width'          => [ 'type' => 'number',  'sanitize' => 'number',  'default' => 0 ],
							'featured_border_color'          => [ 'type' => 'color',   'sanitize' => 'text',    'default' => '' ],
							'featured_border_color_dark'     => [ 'type' => 'color',   'sanitize' => 'text',    'default' => '' ],
							'featured_shadow_color'          => [ 'type' => 'color',   'sanitize' => 'text',    'default' => '#000000' ],
							'featured_shadow_opacity'        => [ 'type' => 'number',  'sanitize' => 'number',  'default' => 0 ],
							'featured_shadow_radius'         => [ 'type' => 'number',  'sanitize' => 'number',  'default' => 8 ],
							'featured_shadow_offset_y'       => [ 'type' => 'number',  'sanitize' => 'number',  'default' => 2 ],
						]
					]
				]
			],

			// ── Default Configuration (toggles first, then colors) ───────
			// `pull_to_refresh` moved to per-item bottom-nav config —
			// admin overrides per tab, no global default any more. The
			// native fallback chain (`AppressConfigService.isScreenPullToRefresh`
			// → screenData → static `true`) keeps existing baked
			// binaries that still ship the field harmless.
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
					'group' => 'splash_screen',
					// Footer renders under the logo in Default mode; the
					// IMAGE block has no footer slot to write into.
					'show_if' => 'splash_type=default',
					'placeholder' => __( 'e.g. © 2026 Brand Name', 'appress' ),
					'maxlength' => 80,
				]
			],

			// ── Left Side Menu ───────────────────────────────────────────
			'side_menu' => [
				'type' => 'object',
				'label' => __( 'Left Side Menu', 'appress' ),
				'sanitize' => 'object',
				'default' => [],
				'fields' => [
					'enabled' => [ 'type' => 'boolean', 'label' => __( 'Left Side Menu', 'appress' ), 'sanitize' => 'boolean', 'default' => false, 'ui' => [
						// Native Features toggle — strips left-drawer code from
						// the binary when off (mutator's `side_drawers` composite
						// strips shared drawer infrastructure only when BOTH
						// side_menu and right_menu are off).
						'group' => 'native_features',
						'tier'  => 'advanced'
					] ],
					'type' => [
						'type' => 'select',
						'label' => __( 'Content Source', 'appress' ),
						'sanitize' => 'text',
						// `page` (most common picked CPT) is the safe
						// runtime default — was `screen` (legacy
						// appress_screen-only pick); Vue's dropdown will
						// fall through to "Custom URL" when the admin
						// hasn't touched the field.
						'default' => 'page',
						'ui' => [
							'group' => 'side_menu_main',
							'show_if' => 'enabled',
							// Options are runtime-built by Vue from
							// `Apppress_Config.post_types` (every public CPT)
							// + the `Custom URL` static entry + `All`. Schema
							// `options` would just be stale documentation here
							// — see `SingleAppView.vue`'s `contentSourceOptions`
							// computed for the canonical list.
							'hint' => __( 'Pick a Page / Post / any public post type — or enter a custom URL.', 'appress' )
						]
					],
					// Single source of truth — picker / text-input both write here.
					'url' => [ 'type' => 'url', 'label' => __( 'URL', 'appress' ), 'sanitize' => 'url', 'default' => '', 'ui' => [ 'group' => 'side_menu_main', 'col_span' => 2, 'placeholder' => 'https://example.com/menu', 'show_if' => 'enabled' ] ],
					'width_percent' => [ 'type' => 'number', 'label' => __( 'Width (% of screen)', 'appress' ), 'sanitize' => 'number', 'default' => 90, 'ui' => [ 'group' => 'side_menu_main', 'col_span' => 2, 'placeholder' => '90', 'show_if' => 'enabled' ] ],
					// `background_color` field removed — the menu drawer is
					// entirely covered by its hosted WebView, so the wrapper
					// background only ever flashes for a single pre-paint
					// frame and is masked by the loaded page's own
					// `<body>` styling immediately after. Admin had no
					// observable way to use it.
				]
			],

			// ── Right Side Menu ──────────────────────────────────────────
			'right_menu' => [
				'type' => 'object',
				'label' => __( 'Right Side Menu', 'appress' ),
				'sanitize' => 'object',
				'default' => [],
				'fields' => [
					'enabled' => [ 'type' => 'boolean', 'label' => __( 'Right Side Menu', 'appress' ), 'sanitize' => 'boolean', 'default' => false, 'ui' => [
						// Native Features toggle (mirrors Left Side Menu).
						'group' => 'native_features',
						'tier'  => 'advanced'
					] ],
					'type' => [
						'type' => 'select',
						'label' => __( 'Content Source', 'appress' ),
						'sanitize' => 'text',
						// `page` (most common picked CPT) is the safe
						// runtime default — was `screen` (legacy
						// appress_screen-only pick); Vue's dropdown will
						// fall through to "Custom URL" when the admin
						// hasn't touched the field.
						'default' => 'page',
						'ui' => [
							'group' => 'right_menu_main',
							'show_if' => 'enabled',
							// Options are runtime-built by Vue from
							// `Apppress_Config.post_types` (every public CPT)
							// + the `Custom URL` static entry + `All`. Schema
							// `options` would just be stale documentation here
							// — see `SingleAppView.vue`'s `contentSourceOptions`
							// computed for the canonical list.
							'hint' => __( 'Pick a Page / Post / any public post type — or enter a custom URL.', 'appress' )
						]
					],
					'url' => [ 'type' => 'url', 'label' => __( 'URL', 'appress' ), 'sanitize' => 'url', 'default' => '', 'ui' => [ 'group' => 'right_menu_main', 'col_span' => 2, 'placeholder' => 'https://example.com/menu', 'show_if' => 'enabled' ] ],
					'width_percent' => [ 'type' => 'number', 'label' => __( 'Width (% of screen)', 'appress' ), 'sanitize' => 'number', 'default' => 90, 'ui' => [ 'group' => 'right_menu_main', 'col_span' => 2, 'placeholder' => '90', 'show_if' => 'enabled' ] ],
					// `background_color` removed (mirror of left side menu).
				]
			],

			// ── First Launch Screen ──────────────────────────────────────
			'first_launch' => [
				'type' => 'object',
				'label' => __( 'First Launch Screen', 'appress' ),
				'sanitize' => 'object',
				'default' => [],
				'fields' => [
					'enabled' => [ 'type' => 'boolean', 'label' => __( 'First Launch Screen', 'appress' ), 'sanitize' => 'boolean', 'default' => false, 'ui' => [
						// Surfaced in the Native Features card so it sits with
						// other build-time toggles. Toggling OFF strips
						// `AppressFirstLaunchService` from the binary via the
						// mutator's FEATURE_KEYS gate.
						'group' => 'native_features',
						'tier'  => 'advanced'
					] ],
					'type' => [
						'type' => 'select',
						'label' => __( 'Content Source', 'appress' ),
						'sanitize' => 'text',
						// `page` (most common picked CPT) is the safe
						// runtime default — was `screen` (legacy
						// appress_screen-only pick); Vue's dropdown will
						// fall through to "Custom URL" when the admin
						// hasn't touched the field.
						'default' => 'page',
						'ui' => [
							'group' => 'first_launch_main',
							'show_if' => 'enabled',
							// Options are runtime-built by Vue from
							// `Apppress_Config.post_types` (every public CPT)
							// + the `Custom URL` static entry + `All`. Schema
							// `options` would just be stale documentation here
							// — see `SingleAppView.vue`'s `contentSourceOptions`
							// computed for the canonical list.
							'hint' => __( 'Pick a Page / Post / any public post type — or enter a custom URL.', 'appress' )
						]
					],
					// Single source of truth. The `type` selector above is
					// purely a UX switch: `type=screen` shows a CPT picker
					// that fills this field; `type=url` shows a free-text
					// input bound to this field. Native consumes only `url`.
					'url' => [ 'type' => 'url', 'label' => __( 'URL', 'appress' ), 'sanitize' => 'url', 'default' => '', 'ui' => [ 'group' => 'first_launch_main', 'placeholder' => 'https://example.com/welcome', 'show_if' => 'enabled' ] ],
				]
			],

			// ── Auth Gate ────────────────────────────────────────────────
			'require_auth_to_open' => [
				'type' => 'boolean',
				'label' => __( 'Authentication Gate', 'appress' ),
				'sanitize' => 'boolean',
				'default' => false,
				'ui' => [
					// Surfaced as a toggle in the Native Features card so it
					// sits alongside Push Notifications / Google Sign-In etc.
					// — toggling OFF here strips `AppressAuthGateService` from
					// the binary (see mutator.js FEATURE_KEYS), so it's a
					// genuine build-time feature, not a runtime config tick.
					'group' => 'native_features',
					'tier'  => 'advanced'
				]
			],

			// ── Dark Mode (Native Features object — feature-gated) ───────
			// Build-time toggle. When OFF the mutator strips the entire
			// dark-mode native bridge + slave JS injection (see mutator.js
			// FEATURE_NAMES + Native Features card). Live values used at
			// runtime (which class + localStorage key to inject when the
			// device is in dark mode) live in `settings.dark_mode_options`
			// further down in the `settings` category — admin can edit
			// those without rebuilding.
			'dark_mode' => [
				'type' => 'object',
				'sanitize' => 'object',
				'default' => [],
				'fields' => [
					'enabled' => [
						'type' => 'boolean',
						'label' => __( 'Dark Mode', 'appress' ),
						'sanitize' => 'boolean',
						'default' => false,
						'ui' => [ 'group' => 'native_features', 'tier' => 'advanced' ]
					]
				]
			],
			'auth_gate' => [
				'type' => 'object',
				'label' => __( 'Auth Screen', 'appress' ),
				'sanitize' => 'object',
				'default' => [],
				'fields' => [
					'type' => [
						'type' => 'select',
						'label' => __( 'Content Source', 'appress' ),
						'sanitize' => 'text',
						// `page` (most common picked CPT) is the safe
						// runtime default — was `screen` (legacy
						// appress_screen-only pick); Vue's dropdown will
						// fall through to "Custom URL" when the admin
						// hasn't touched the field.
						'default' => 'page',
						'ui' => [
							'group' => 'require_auth',
							// Options are runtime-built by Vue from
							// `Apppress_Config.post_types` (every public CPT)
							// + the `Custom URL` static entry + `All`. Schema
							// `options` would just be stale documentation here
							// — see `SingleAppView.vue`'s `contentSourceOptions`
							// computed for the canonical list.
							'hint' => __( 'Pick a Page / Post / any public post type — or enter a custom URL.', 'appress' )
						]
					],
					// Single source of truth. `type` selector above is
					// purely a UX switch driving picker vs free-text input;
					// both write here, native consumes only `url`.
					'url' => [
						'type' => 'url',
						'label' => __( 'URL', 'appress' ),
						'sanitize' => 'url',
						'default' => '',
						'ui' => [
							'group' => 'require_auth',
							'placeholder' => 'https://example.com/login'
						]
					],
				]
			],

			// ── App Screens repeater removed 2026-06-09 ──────────────────
			// URLs travel directly on their consumer fields:
			//   `bottom_navigation.items[].url`, `first_launch.url`,
			//   `auth_gate.url`, `side_menu.url`, `right_menu.url`.
			// The `appress_screen` CPT (Appress → Screens) still exists
			// for site authors to manage screen-content posts — the admin
			// can pick from these posts via a CPT picker that fills the
			// target URL field, or type a Custom URL — both modes write
			// the same shape. apps-controller's save handler auto-builds
			// the legacy `app_screens` registry from referenced URLs so
			// native binaries unchanged at the wire level.

			// (Disable Web Ads, Analytics, Smart Prefetch, Page Routing fields
			//  moved to top-level `settings` category — distributed via the WP-
			//  side print hooks (wp_head printers, send_headers Cache-Control
			//  override, `window.AppressAppSettings`) instead of baking into
			//  the binary.)

			// ── In-App Strings ────────────────────────────────────────────────
			// Every user-facing string baked into the iOS Dictionary /
			// Android HashMap at build time. The Build Engine merges these
			// customer values OVER the default `i18n.json` in every locale
			// slot — so the cross-build `__cstring` fingerprint Apple's
			// 4.3(a) cluster ML reads varies per app even when the customer
			// only types in one language. Empty field → engine falls back to
			// the boilerplate default.
			//
			// Fields are organised by `ui.group` (`strings_network`,
			// `strings_biometric`, `strings_qr`, …) so the Vue admin can
			// split them into themed cards without re-listing the keys.
			'app_strings' => array_merge(
				[
					'type' => 'object',
					'label' => __( 'In-App Strings', 'appress' ),
					'sanitize' => 'object',
				],
				\Appress\app_strings_schema_block()
			),
		]
	],

	// ════════════════════════════════════════════════════════════════════
	//   Settings — fields the WP side distributes LIVE on every request.
	//   No build engine touch: customer admin saves a field here, the
	//   next page response carries the change with no rebuild required.
	//
	//   Distribution channels:
	//     - Custom CSS  → `Frontend_Controller::print_app_css` (wp_head).
	//     - Analytics + Web Ads → `print_analytics_js` + `print_ads_blocker_js`
	//                              (wp_head priority 0, before page scripts).
	//     - Smart Prefetch → `Injection_Controller::relax_cache_control_for_prefetch`
	//                         (send_headers, overrides WP's no-cache).
	//     - Subscreen rules → `Frontend_Controller::print_app_settings`
	//                          (wp_head, native bridge reads `window.AppressAppSettings`).
	//
	//   `save_config` writes these to the dedicated `settings` DB column
	//   (separate from `build_config`) so admin Settings-tab saves don't
	//   bump `update_time_hash` and the build engine never sees them in
	//   its payload.
	// ════════════════════════════════════════════════════════════════════
	'settings' => [
		'label'  => __( 'App Settings', 'appress' ),
		'fields' => [

			// ── Custom CSS injected into every WebView ───────────────────
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

			// ── Analytics ───────────────────────────────────────────────
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

			// ── Smart prefetch (server-side Cache-Control relax) ────────
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

			// ── Page routing overrides (Subscreen rules) ────────────────
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
			'subscreen_url_patterns' => [
				'type' => 'textarea',
				'label' => __( 'Open in subscreen (URL patterns)', 'appress' ),
				'sanitize' => 'textarea',
				'default' => '',
				'ui' => [
					'group' => 'inline_links',
					'col_span' => 2,
					'placeholder' => "/?s=*\n/listings/*\n*?orderby=*",
					'hint' => __( 'One URL pattern per line. * matches any characters.', 'appress' ),
				],
			],

			// ── Dark Mode runtime values ────────────────────────────────
			// Live: admin edits → next page load apply, no rebuild. The
			// build-time gate lives in `build_config.dark_mode.enabled`
			// (Native Features card) — when OFF the engine strips the
			// entire native bridge + slave JS injection, so these values
			// never reach the binary or the WebView. When ON, the slave
			// JS reads `window.AppressAppSettings.dark_mode_options` and
			// applies the configured class + localStorage signal so any
			// WordPress dark-mode plugin convention (Dark Mode Toggle
			// `darkmode--activated` + `darkmode=true`; WP Dark Mode
			// `wp-dark-mode-active` + `wp_dark_mode_active=true`;
			// custom themes) can plug in by typing its values here.
			'dark_mode_options' => [
				'type' => 'object',
				'sanitize' => 'object',
				'default' => [],
				'fields' => [
					'body_class' => [
						'type' => 'text',
						'label' => __( 'Body class', 'appress' ),
						'sanitize' => 'text',
						'default' => '',
						'ui' => [
							'group' => 'dark_mode',
							'placeholder' => 'darkmode--activated',
							'show_if' => 'dark_mode.enabled',
							'hint' => __( 'Class added to <body> when the device is in dark mode. Check your dark-mode plugin docs (e.g. "darkmode--activated", "wp-dark-mode-active").', 'appress' )
						]
					],
					'localstorage_key' => [
						'type' => 'text',
						'label' => __( 'LocalStorage key', 'appress' ),
						'sanitize' => 'text',
						'default' => '',
						'ui' => [
							'group' => 'dark_mode',
							'placeholder' => 'darkmode',
							'show_if' => 'dark_mode.enabled',
							'hint' => __( 'Storage key the plugin reads to remember user preference. Leave blank if your plugin only uses the body class.', 'appress' )
						]
					],
					'localstorage_value' => [
						'type' => 'text',
						'label' => __( 'LocalStorage value (when dark)', 'appress' ),
						'sanitize' => 'text',
						'default' => 'true',
						'ui' => [
							'group' => 'dark_mode',
							'placeholder' => 'true',
							'show_if' => 'dark_mode.enabled',
							'hint' => __( 'Value written to the key above when dark mode is active (typically "true" or "dark"). When light, the key is removed.', 'appress' )
						]
					],
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
			// Auto-publish AAB to Play Store tracks. Single role required:
			// Release Manager. Optional but recommended — admins who skip
			// this still get a buildable AAB they can upload manually via
			// Play Console.
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
					'hint' => __( 'Play Console → Setup → API access → Service accounts. Grant Release Manager role to enable auto-publish of AAB builds.', 'appress' )
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
