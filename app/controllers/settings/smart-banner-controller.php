<?php
namespace Appress\Controllers\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Banner — single integration, two surfaces.
 *
 * iOS Safari renders Apple's own banner from a meta tag, so we emit
 * `<meta name="apple-itunes-app">` in `<head>`. Every other browser
 * (Android Chrome, iOS Chrome, desktop, etc.) gets a tiny self-rendered
 * top banner injected at footer-time — same admin toggle, same chosen
 * app, just two different render paths to give the right experience
 * on each platform.
 *
 * Skipped entirely when:
 *   - Already inside the Appress app shell (re-promoting to user inside
 *     the app makes no sense).
 *   - The user has dismissed the JS banner this session (handled
 *     client-side via localStorage; meta tag is harmless even if Safari
 *     redisplays).
 *   - Selected app has no IDs at all (apple_store_id + package_id both
 *     empty).
 */
class Smart_Banner_Controller extends \Appress\Controllers\Base_Controller {

	protected function hooks() {
		$this->on( 'wp_head',   '@output_meta',   1 );
		$this->on( 'wp_footer', '@output_script', 99 );
	}

	private function get_active_app(): ?array {
		$cfg = \Appress\get( 'site_settings.smart_banner', [] );
		if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) ) {
			return null;
		}
		$app_id = isset( $cfg['app_id'] ) ? (int) $cfg['app_id'] : 0;
		if ( $app_id <= 0 ) {
			return null;
		}

		global $wpdb;
		// `$wpdb->prefix` is configured in wp-config.php (trusted) and
		// 'appress_apps' is a literal — the composed table name is safe
		// to interpolate. `$wpdb->prepare()` placeholders cover values,
		// not identifiers, so a manual ignore is the canonical pattern
		// used by WP core itself for this construction.
		$table = $wpdb->prefix . 'appress_apps';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, app_name, build_config FROM {$table} WHERE id = %d", $app_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$bi = json_decode( (string) ( $row['build_config'] ?? '{}' ), true );
		if ( ! is_array( $bi ) ) {
			$bi = [];
		}
		// Strip non-digits so admins pasting "id1234567890" or full URLs still work.
		$apple = preg_replace( '/\D+/', '', (string) ( $bi['apple_store_id'] ?? '' ) );
		$pkg   = (string) ( $bi['package_id'] ?? '' );
		if ( $apple === '' && $pkg === '' ) {
			return null;
		}
		return [
			'id'       => (int) $row['id'],
			'name'     => (string) $row['app_name'],
			'apple_id' => $apple,
			'package'  => $pkg,
			'logo'     => (string) ( $bi['logo'] ?? '' ),
		];
	}

	private function should_skip(): bool {
		if ( is_admin() ) {
			return true;
		}
		if ( function_exists( 'Appress\\is_app' ) && \Appress\is_app() ) {
			return true;
		}
		return false;
	}

	public function output_meta() {
		if ( $this->should_skip() ) {
			return;
		}
		$app = $this->get_active_app();
		if ( ! $app || $app['apple_id'] === '' ) {
			return;
		}
		$current_url = $this->current_url();
		printf(
			'<meta name="apple-itunes-app" content="app-id=%s, app-argument=%s">' . "\n",
			esc_attr( $app['apple_id'] ),
			esc_attr( $current_url )
		);
	}

	public function output_script() {
		if ( $this->should_skip() ) {
			return;
		}
		$app = $this->get_active_app();
		if ( ! $app ) {
			return;
		}

		// Build store URLs once server-side. Empty string = platform
		// not configured; the JS gates render based on what's present.
		$ios_url     = $app['apple_id'] !== '' ? 'https://apps.apple.com/app/id' . $app['apple_id'] : '';
		$android_url = $app['package']  !== '' ? 'https://play.google.com/store/apps/details?id=' . rawurlencode( $app['package'] ) : '';

		$payload = wp_json_encode( [
			'name'    => $app['name'],
			'logo'    => $app['logo'],
			'iosUrl'  => $ios_url,
			'androidUrl' => $android_url,
		] );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "<script>(function(){var c=" . $payload . ";";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->banner_js();
		echo "})();</script>";
	}

	/**
	 * Self-rendering banner script. Gates:
	 *   - Skip Appress WebView (native bridge present).
	 *   - Skip iOS Safari → Apple's own banner is already shown via
	 *     the meta tag.
	 *   - Skip when user has dismissed in this browser (localStorage).
	 *   - Pick `iosUrl` for iOS browsers other than Safari, otherwise
	 *     `androidUrl`. Bail when the picked URL is empty.
	 */
	private function banner_js(): string {
		return <<<'JS'
if (window.AppressNativeBridge || (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.AppressNativeBridge)) return;
var ua = navigator.userAgent || '';
var isIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
var isSafari = isIOS && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua);
if (isSafari) return; // Native iOS banner from <meta> covers this.
var isAndroid = /Android/.test(ua);
if (!isIOS && !isAndroid) return; // Desktop — no banner.
try { if (localStorage.getItem('appress_smart_banner_dismissed') === '1') return; } catch(e) {}
var url = isIOS ? c.iosUrl : c.androidUrl;
if (!url) return;
var bar = document.createElement('div');
bar.setAttribute('style','position:fixed;top:0;left:0;right:0;z-index:2147483646;display:flex;align-items:center;gap:10px;padding:8px 10px;background:#f8f8f8;border-bottom:1px solid rgba(0,0,0,0.12);font:13px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#000;box-shadow:0 1px 2px rgba(0,0,0,0.05)');
var close = document.createElement('button');
close.textContent = '✕';
close.setAttribute('aria-label','Close');
close.setAttribute('style','flex-shrink:0;width:22px;height:22px;border:0;background:transparent;color:#666;font-size:16px;line-height:1;cursor:pointer;padding:0');
close.onclick = function(){ try{ localStorage.setItem('appress_smart_banner_dismissed','1'); }catch(e){} bar.remove(); document.body.style.paddingTop = ''; };
var icon;
if (c.logo) { icon = document.createElement('img'); icon.src = c.logo; icon.setAttribute('style','width:40px;height:40px;border-radius:8px;flex-shrink:0;object-fit:cover'); }
else { icon = document.createElement('div'); icon.setAttribute('style','width:40px;height:40px;border-radius:8px;background:#007aff;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0'); icon.textContent = (c.name||'A').charAt(0).toUpperCase(); }
var text = document.createElement('div');
text.setAttribute('style','flex:1;min-width:0;line-height:1.2');
text.innerHTML = '<div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+(c.name||'')+'</div><div style="font-size:11px;color:#666">'+(isIOS?'View in App Store':'Get it on Google Play')+'</div>';
var open = document.createElement('a');
open.href = url;
open.target = '_blank';
open.rel = 'noopener';
open.textContent = isIOS ? 'View' : 'Get';
open.setAttribute('style','flex-shrink:0;padding:6px 14px;background:#007aff;color:#fff;border-radius:14px;text-decoration:none;font-weight:600;font-size:13px');
bar.appendChild(close); bar.appendChild(icon); bar.appendChild(text); bar.appendChild(open);
function mount(){ document.body.appendChild(bar); document.body.style.paddingTop = (bar.offsetHeight||56)+'px'; }
if (document.body) mount(); else document.addEventListener('DOMContentLoaded', mount);
JS;
	}

	private function current_url(): string {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return home_url( '/' );
		}
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
		$path   = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		return esc_url_raw( $scheme . '://' . $host . $path );
	}
}
