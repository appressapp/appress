=== Appress — Mobile App Builder ===
Contributors: appressapp
Tags: mobile app, app builder, push notifications, ios, android
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 8.3
Stable tag: 1.0.0.26
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your WordPress website into native iOS and Android apps. No coding, no redesign, no separate team.

== Description ==

Appress is a WordPress plugin that lets you build and manage multiple mobile apps from a single WordPress website.

With Appress, you can design app layouts and control every aspect of your mobile apps using the WordPress tools you already know — page builders, plugins, and CSS.

It effectively turns any WordPress user into a mobile app builder, no specialized development skills required.

= Why you should have a mobile app =

* **Higher conversion rate.** Mobile app users convert 3–4× more than mobile web visitors — they're faster to check out, quicker to book, and more likely to come back.
* **Free marketing through push notifications.** Reach every customer directly on their lock screen. No ad spend, no algorithm, no open rate guesswork. Send a broadcast and they see it.
* **Always one tap away.** An app icon on the home screen is the shortest path between your brand and your customer. No more Google searches, no more typing URLs.
* **Build loyalty and repeat visits.** Users open apps 5× more often than they visit mobile websites for the same brand.
* **Own your audience.** Emails bounce, ads get more expensive, social reach keeps dropping. App installs are yours — no middleman.
* **Stand out from competitors.** Most WordPress-based businesses don't have an app. An app instantly positions you as a more serious, more established brand.
* **Better UX than mobile web.** Native gestures, offline support, biometric login, smoother animations — the things your customers already expect from every other app on their phone.

= What you can do with Appress =

* Build iOS and Android apps from your existing WordPress content — no redesign needed
* **Real-time sync between your app and your WordPress site** — publish a post, change a price, update a listing, and your users see it instantly. No rebuilds, no redeploys.
* **Manage everything from the WordPress dashboard you already know** — no extra control panel, no second login, no new tools to learn.
* Run multiple apps from a single WordPress site (perfect for agencies, franchises, or multi-brand businesses)
* Send unlimited push notifications to your users
* Target push campaigns by app, platform, country, or customer segment
* Edit your app's navigation, colors, icons, and layout visually — no code
* Update your app's content instantly without rebuilding or re-submitting to the store
* Add user login, accounts, and gated content through the WordPress plugins you already use
* Attract new app users with a smart banner that appears on your mobile website

= Works with your existing stack =

* **Voxel** — theme for community and directory sites, with push notifications when users receive messages, reviews, orders, and more
* **FluentCRM** — target push campaigns by list, tag, or customer segment, just like email marketing
* **Uncanny Automator** — send push notifications from any workflow trigger you can think of
* **WooCommerce** — turn your store into a mobile shopping app
* **Elementor, Bricks, Gutenberg** — design app screens the way you're used to designing pages

= Requirements =

* WordPress 5.8 or later
* PHP 8.3 or later
* A free Appress account at [appress.app](https://appress.app) for app compilation

== Installation ==

1. Upload the `appress` folder to `/wp-content/plugins/` (or install through the WordPress plugin directory)
2. Activate the plugin
3. Go to **Appress** in your WordPress admin menu
4. Create a free account at [appress.app](https://appress.app) and connect your site
5. Configure your app's look and navigation
6. Click Build — Appress generates your native iOS and Android apps

== Frequently Asked Questions ==

= Do I need programming skills to use Appress? =

No. Everything is configured through the visual builder and your WordPress content editor.

= Do I need a separate website for my app? =

No. Appress turns your existing WordPress pages into native app screens. Edit once, update everywhere.

= Does it support both iOS and Android? =

Yes. Appress builds both iOS and Android apps from the same configuration. Publishing to the App Store requires a paid Apple Developer account; Google Play requires a one-time $25 registration.

= Can I send push notifications? =

Yes. You can send unlimited push notifications to all users or target specific segments (by app, country, language, or CRM list).

= Can I publish the apps to Google Play and the App Store? =

Yes. Appress can publish your app directly to Google Play and submit it to TestFlight / the App Store using your own developer accounts.

= What does Appress share with external services? =

Appress connects to `appress.app` to compile your app binaries. Your WordPress content stays on your own server. Push notifications are delivered through Firebase Cloud Messaging. No user tracking or telemetry is collected by the plugin.



== External services ==

This plugin connects to the following external services. Each is required to provide a specific feature and is only contacted under the conditions described below.

= Appress Central (https://my.appress.app) =

What it is and what it is used for: Appress Central is the SaaS backend that compiles your WordPress configuration into native iOS and Android binaries. It is provided by Appress.

What data is sent and when:
* When you connect your site, the plugin sends the one-time connection token you enter to verify ownership and pair your site with the build account.
* When you save app configuration changes, the plugin pushes the configuration JSON (app name, colors, icons, screen layouts, push settings, build target metadata) so the same configuration can be used to compile new builds.
* When you click Build, the plugin sends a build request referencing the paired site.

No content posts, no end-user data, and no comments are sent to Appress Central. Communication is over HTTPS.

Terms of Service: https://appress.app/terms/
Privacy Policy: https://appress.app/privacy-policy/

= Firebase Cloud Messaging (https://fcm.googleapis.com) =

What it is and what it is used for: Firebase Cloud Messaging is the push notification delivery service operated by Google. It is used to deliver push notifications from your WordPress site to your installed app users.

What data is sent and when:
* When the device registers, the app sends the FCM device token to your WordPress site (stored locally in your own database). The plugin does not send the token to Appress.
* When you trigger a broadcast or an event-based notification from the WordPress dashboard, the plugin sends the notification payload (title, body, image URL, target URL) and the list of FCM device tokens to FCM for delivery.

Terms of Service: https://firebase.google.com/terms
Privacy Policy: https://policies.google.com/privacy

= Google Identity / OAuth (https://oauth2.googleapis.com) =

What it is and what it is used for: Used only when the optional "Sign in with Google" feature is enabled. The plugin verifies the Google ID token presented by the mobile app against Google's tokeninfo endpoint to authenticate the user and create or update their WordPress account.

What data is sent and when: The Google ID token is sent to Google's tokeninfo endpoint at the moment a user taps "Sign in with Google" in the mobile app. No data is sent unless the feature is enabled and a sign-in is initiated.

Terms of Service: https://policies.google.com/terms
Privacy Policy: https://policies.google.com/privacy

= Apple Developer Resources (https://developer.apple.com) =

What it is and what it is used for: The plugin admin UI links to Apple Developer pages (e.g. Certificates, Identifiers, Membership Details) so administrators can fetch credentials needed for iOS push notifications and App Store submissions. These are read-only outbound links; the plugin does not call Apple Developer APIs from the server.

What data is sent and when: No data is sent to Apple by the plugin itself. The links open in a new tab when the administrator clicks them.

Terms of Service: https://developer.apple.com/terms/
Privacy Policy: https://www.apple.com/legal/privacy/



== Changelog ==

= 1.0.0.26 =
* Fixed status bar height resolving to 0 in the in-app Elementor / Bricks Status Bar Height widget.
* Requires Build Engine v1.0.19+.

= 1.0.0.25 =
* Fixed in-app status bar height resolving to 0 when the customer's compiled stylesheet referenced `var(--appress-status-bar-height)`.
* Requires Build Engine v1.0.19+.

= 1.0.0.24 =
* Fixed in-app biometric sign-in, indicator badges, and smart banner regressing after 1.0.0.22.
* Required alongside Build Engine v1.0.18+.

= 1.0.0.23 =
* Fixed in-app sticky and status-bar-height regression from 1.0.0.22.

= 1.0.0.22 =
* Each app built from this site now ships with its own unique JavaScript namespace and CSS variable names so the App Store and Play Store can no longer cluster your customers' apps as "similar apps".
* Required alongside Build Engine v1.0.18+.

= 1.0.0.21 =
* Settings page restructured — the "Build Information" tab is now called "Build Config", and the separate "Live App Builder" tab has been retired. Everything you used to configure across both is now in one place. Your existing settings carry over automatically; no re-entry needed.
* New "Open in subscreen" toggle in Native Features. When you turn it off, link taps inside the app load the page in-place on the current tab instead of pushing a modal subscreen on top — and the related settings (URL patterns, "Show bottom nav on subscreen") auto-hide so the form stays clean.
* When subscreen is disabled, the "Page routing overrides" section is hidden entirely — those overrides only made sense when subscreen was on, so they no longer clutter the form.
* Removed the in-app purchase (IAP) integration end-to-end. New builds no longer ship the IAP SDK and the related App Store / Play Store data-safety disclosure is no longer required.

= 1.0.0.20 =
* New onboarding tour pops up the first time you open the settings page for a new app — a 4-slide walkthrough of the app's building blocks (Build Information, App Screens, Bottom Navigation, Side Menus) with an inline phone mockup that animates each component as you step through.
* Every slide has a "Go to this section" button that jumps straight to the matching settings card (auto-switches tabs if needed) and a "Read docs" link that opens the relevant documentation page in a new tab.
* The tour can be re-opened any time from the new help button (?) in the page header. Use "Don't show again" to silence it permanently for that app, or just close it to have it reappear on the next visit.
* Removed the AdMob / in-app advertising fields from the app settings page — the plugin no longer ships ad SDK toggles. New builds are smaller and the related App Store / Play Store data-safety disclosure is no longer required.

= 1.0.0.19 =
* New "Splash Screen" section on the app settings page lets admins pick how the boot screen looks. Two modes: Default (your app logo centered on a background colour) and Custom Image (a full-screen image you upload).
* Default mode adds an optional "Show Loading Bar" toggle so the animated progress pill under the logo can be turned off for a cleaner static splash.
* Custom Image mode accepts a portrait image (1290×2796 recommended) — it's used full-bleed on every device and the background colour automatically matches the image edge so the system splash hand-off is seamless with no colour flash.
* Background Color picker now sits inside the Splash Screen section instead of mixed in with App Information, making the boot-screen settings easier to find.

= 1.0.0.18 =
* New "Native Features" panel on the app settings page lets admins toggle which native frameworks ship with the build — Push Notifications, Biometric (Face ID / Touch ID), Google Sign-In, Sign in with Apple, QR Scanner, and Geolocation. Turning a feature off removes the related SDK from the binary and skips its permission prompt, giving a smaller download size and a cleaner data-safety disclosure on the App Store / Play Store.
* Each toggle in Native Features now has a small info icon that opens the matching documentation page on docs.appress.app for setup steps and platform-specific notes.
* Every newly built app now ships with a completely unique internal fingerprint — different identifiers, request signatures, and log markers from every other Appress-built app — so the App Store no longer flags your customers' submissions as similar to other apps.
* The User-Agent each app sends to your website now carries the app's own identifier instead of a shared brand string, making access logs and analytics cleanly distinguish traffic between different apps.
* Push notifications now display the app's own name in the device's Notifications settings instead of a generic label.
* Fixed a database migration that previously ran only on wp-admin requests — the new column now lands on plugin load regardless of where the first request comes from, so frontend traffic no longer hits "unknown column" errors right after upgrading.
* Fixed the biometric AJAX endpoints (issue_token / exchange / revoke) failing to register on some installs.

= 1.0.0.17 =
* Failed builds are now clearly marked in red on the Builds tab, with a small info icon next to the status. Click the icon to see exactly what went wrong (for example, "iOS Signing values look incorrect — please double-check Build Information or contact support").
* Removed the "Try again" button on failed builds. To rebuild after fixing the cause, use the regular "Build now" button at the top of the app page — it creates a fresh build and is the same path you used to start the original one.

= 1.0.0.16 =
* Menu Toggle widget now supports a right-side drawer in addition to the left one — set the new Menu Target option to "Right" (Elementor/Bricks) or add `data-appress-menu-target="right"` (shortcode) to wire a button to the right drawer. Existing left-menu buttons keep working with no changes.
* Fixed: the in-app Back button could appear on the login / first-launch screens and tapping it sometimes led to a "page not found" error. The button now correctly stays hidden on those screens.

= 1.0.0.15 =
* Builds tab now shows your app version as "Appress App Version" with a clearer badge on each build row — green when the build uses the latest version, amber when an older one was used. Customer-facing label updated everywhere it was previously shown as a technical term.

= 1.0.0.14 =
* Android Signing: new "Play Console Verification Token" field for the case where Google Play asks you to prove ownership of your package name. Paste the token from Play Console's "Sign and upload an APK" prompt, rebuild your app, and the resulting APK passes the verification on first upload.

= 1.0.0.13 =
* Build dialog: free plans now see a clear "Upgrade Now" call-to-action with a direct link to manage their plan, plus a "Preview Free" shortcut for trying the app without buying a package.
* Fixed: cramped spacing on Build dialog headings and helper text caused by WordPress admin styles overriding the modal layout.

= 1.0.0.12 =
* Fixed: Updates tab "Latest available" picked the wrong version when releases had double-digit segments — now sorts releases with proper version comparison so 1.0.0.11 is correctly recognised as newer than 1.0.0.9.

= 1.0.0.11 =
* App config cache is now invalidated automatically whenever the plugin version changes or an integration is toggled on or off — installed apps pick up the fresh config on the next cold start without any extra admin save.

= 1.0.0.10 =
* Fixed: integration CSS rules (such as the Voxel popup spacing tweaks) now reach the preview app on reload.
* Fixed: Updates tab version compare handled double-digit version segments incorrectly (e.g. 1.0.0.10 was treated as older than 1.0.0.9).

= 1.0.0.9 =
* Removed the legacy "Preview" tab from the App settings page.

= 1.0.0.8 =
* Updates: clicking "Check for updates" now installs the new version in a single step instead of a separate confirm-then-install round.
* Plugins page: added a quick "Updates" link on the Appress row, next to "Onboard".
* The admin-wide update banner now offers a one-click "Update now" button.
* Rollback dropdown lists every published version (older, current, newer) for explicit reinstall.
* Fixed: "Latest available" row no longer shows a stray em-dash when the site is already up to date.

= 1.0.0.7 =
* New: Plugin updates and rollback. Check for new versions, install the latest release, or roll back to a previous version directly from Appress → Settings → Updates.
* New: Plugin updates show a notice across all admin pages when a new version is available, like other major plugins.

= 1.0.0.6 =
* TranslatePress: master toggle on the Integrations page now actually disables the integration site-wide — previously the boot endpoint kept emitting language variants even with the master off, pinning device-locale users to the wrong language.
* Google Sign-In on Android: the account chooser now appears on every tap instead of silently auto-selecting the previously-used Google account.

= 1.0.0.5 =
* TranslatePress: each user's preferred language now follows them across devices — the language set on their WordPress profile is auto-applied to the app on login.
* Sign in with Apple / QR Login / QR Scanner / Back / Menu Toggle widgets: the Size + Color controls now work for font icons too (Font Awesome, Themify, Fusion), not just SVG.
* Biometric and QR Login native popups are now translated into 15 languages and follow the app's active TranslatePress language.

= 1.0.0.4 =
* Push notification mark-read now also covers integration-driven pushes (Voxel events, etc.) — not just admin broadcasts. The track endpoint accepts campaign-only, source-only, or both.
* HMAC canonical builder uses raw request strings, fixing signature mismatches when a field is empty (e.g. campaign-less Voxel pushes).

= 1.0.0.3 =
* Push notification tap now marks the corresponding feed row as read — works for both built-in Appress notifications and integration-driven ones (Voxel events, etc.). The bell badge and feed list update live without a manual refresh.
* Notification feed component now refreshes in real time when the server marks rows read from any source.

= 1.0.0.2 =
* Refresh button next to Package ID — pulls latest package_id, app name and SHA-1 from Central without a full reconnect.
* Connection Token "Replace" turned into a pencil icon with tooltip; matches the new refresh icon style.
* Connect New App modal: tightened spacing and added a "View guide" link to the docs.

= 1.0.0.1 =
* Vietnamese translation (840+ strings).
* QR Login modal: slide-up animation, mobile bottom-sheet layout.
* QR Scanner button: separated trigger class from QR Login; only renders for logged-in users inside the app.
* Plugin Check warnings resolved (nonce/SQL/translator-comment annotations).

= 1.0.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0.20 =
Adds a 4-step onboarding tour for new apps with an animated phone mockup and "Go to section" / "Read docs" buttons on every slide. Removes the unused AdMob fields from the app settings page.

= 1.0.0.19 =
Adds a Splash Screen section to the app settings — choose Default (logo on background) or Custom Image (full-screen image upload). Default mode also gains a Show Loading Bar toggle.

= 1.0.0.18 =
Adds the Native Features panel to bundle only the SDKs each app actually uses, gives every new build a unique fingerprint so the App Store stops flagging similar apps, and ships push notifications under the app's own name.

= 1.0.0.17 =
Failed builds now show a clear red status with a clickable info icon explaining what went wrong. The Try again button is removed — use Build now to retry after fixing the cause.

= 1.0.0.16 =
Adds right-side menu drawer support to the Menu Toggle widget and fixes a stray Back button on the login screen that could lead to a 404.

= 1.0.0.15 =
Builds tab now labels your app version clearly as "Appress App Version" with a status badge on each build.

= 1.0.0.14 =
Adds a Play Console Verification Token field so you can prove package name ownership when Google Play asks for it on first upload.

= 1.0.0.13 =
Build dialog adds an Upgrade Now button for free plans and fixes cramped spacing on modal headings.

= 1.0.0.12 =
Fixes Updates tab picking the wrong "Latest available" version on releases with double-digit segments.

= 1.0.0.11 =
Plugin version changes and integration toggles now invalidate cached app configs automatically.

= 1.0.0.10 =
Fixes integration CSS not reaching the preview app on reload, and a version compare bug on the Updates tab.

= 1.0.0.9 =
Removes the legacy Preview tab from the App settings page.

= 1.0.0.8 =
One-click update flow, quick Plugins-row Updates link, and polish fixes on the Updates tab.

= 1.0.0.7 =
Adds in-dashboard plugin updates and rollback — check, install, or revert versions without leaving WordPress.

= 1.0.0.6 =
TranslatePress master toggle now disables the integration site-wide correctly; Android Google Sign-In always shows account chooser.

= 1.0.0.5 =
Per-user TranslatePress language now syncs to the app on login, icon Size/Color controls now work for font icons, native biometric/QR popups localized to 15 languages.

= 1.0.0.4 =
Push tap mark-read now works for integration pushes (Voxel events, etc.), not just broadcast campaigns.

= 1.0.0.3 =
Push notification tap now marks the matching feed row as read; the bell and feed list update live.

= 1.0.0.2 =
Adds a one-click refresh for Package ID and small UX polish on the connect/replace flows.

= 1.0.0.1 =
Adds Vietnamese translations, QR Login UX polish, and Plugin Check fixes.

= 1.0.0.0 =
Initial release.
