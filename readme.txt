=== Appress — Mobile App Builder ===
Contributors: appressapp
Tags: mobile app, app builder, push notifications, ios, android
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 8.3
Stable tag: 1.0.0.6
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
