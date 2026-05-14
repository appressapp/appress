<?php

namespace Appress\Controllers;

// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
if ( ! defined('ABSPATH') ) {
	exit;
}

class Ajax_Controller extends Base_Controller {

	/**
	 * Custom AJAX handler for better performance compared to admin-ajax.php
	 *
	 * @link  https://woocommerce.wordpress.com/2015/07/30/custom-ajax-endpoints-in-2-4/
	 * @since 1.0
	 */
	protected function hooks() {
		$this->on( 'init', '@define_ajax', 0 );
		$this->on( 'template_redirect', '@do_ajax', 0 );
	}

	protected function define_ajax() {
		if ( empty( $_GET['appress'] ) ) {
			return;
		}

		// WP-CORE constant — many plugins/themes branch on `DOING_AJAX` to
		// skip heavy main-query bootstrap. Defining here ensures our custom
		// `?appress=1` endpoint is treated as ajax even though we don't go
		// through wp-admin/admin-ajax.php. Must use the WP-core name verbatim.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		if ( ! defined( 'DOING_AJAX' ) ) define( 'DOING_AJAX', true );

		if ( ! defined( 'APPRESS_AJAX_HIDE_ERRORS' ) ) {
			define( 'APPRESS_AJAX_HIDE_ERRORS', true );
		}

		// prevent malformed JSON
		if ( APPRESS_AJAX_HIDE_ERRORS ) {
			ini_set( 'display_errors', 0 );
			$GLOBALS['wpdb']->hide_errors();
		}

		// close session to allow concurrent requests
		session_write_close();

		// Tell every page-cache layer we know about to bypass this request.
		// Runs at `init` priority 0 — well before WP Rocket / W3TC / LiteSpeed
		// / WP Super Cache / Hyper Cache / WP Fastest Cache / Cache Enabler
		// make their cache-the-output decision. Edge caches in front of PHP
		// (Cloudflare full-page cache, Nginx fastcgi_cache, Varnish) need a
		// rule on their side — we can't reach them from here, but the
		// `Cache-Control: no-store, private` headers from `nocache_headers()`
		// in do_ajax() at least give well-configured ones the right hint.
		$this->signal_no_cache_to_plugins();
	}

	/**
	 * Broadcast "do not cache" to every WP-side cache plugin we know about.
	 * Plugins gate their cache decision on a mix of constants, filters and
	 * actions — we set all of them so the response is fresh regardless of
	 * which one(s) the site has installed. Cheap to run; no-ops when the
	 * plugin in question isn't loaded.
	 */
	private function signal_no_cache_to_plugins() {
		// W3 Total Cache + WP Super Cache + WP Rocket + Hyper Cache + WP
		// Fastest Cache + Cache Enabler all check this constant before
		// writing the page to disk. Defining it here is the single most
		// portable signal.
		// These are WP-CORE/cache-plugin global constants — names are fixed
		// by the cache layer contract, can't be prefixed with our plugin name.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		if ( ! defined( 'DONOTCACHEPAGE' ) )   define( 'DONOTCACHEPAGE',   true );
		if ( ! defined( 'DONOTCACHEDB' ) )     define( 'DONOTCACHEDB',     true );
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

		// LiteSpeed Cache — `do_action` fires its internal nocache flag,
		// the filter is the older API some versions still honor.
		// LiteSpeed's hook name, not ours — can't prefix.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'litespeed_control_set_nocache', 'appress ajax endpoint' );
		add_filter( 'litespeed_can_cache', '__return_false', 999 );

		// WP Rocket — DONOTCACHEPAGE is enough for the disk cache layer,
		// but explicitly disabling the optimization passes (minify, JS
		// defer, lazy-load) prevents Rocket from rewriting JSON responses.
		add_filter( 'do_rocket_post_dynamic_cache', '__return_false' );
		add_filter( 'rocket_minify_html',    '__return_false' );
		add_filter( 'rocket_minify_js',      '__return_false' );
		add_filter( 'rocket_minify_css',     '__return_false' );
		add_filter( 'rocket_lazyload_html',  '__return_false' );

		// Cloudflare APO + page rules read the cache-bypass cookie / no-store
		// header. We send `nocache_headers()` later in do_ajax(), which emits
		// the standard `Cache-Control: no-store, no-cache, must-revalidate`.
		// For sites that route through Cloudflare's full-page cache, that's
		// the only signal that crosses the edge — any stronger guarantee
		// requires a Cache Rule on the Cloudflare side.
	}

	protected function do_ajax() {
		if ( empty( $_GET['appress'] ) ) {
			return;
		}

		// send headers
		if ( ! headers_sent() ) {
			send_origin_headers();
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
			header( 'X-Robots-Tag: noindex' );
			send_nosniff_header();
			nocache_headers();
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			headers_sent( $file, $line );
			trigger_error( "Cannot set headers - headers already sent by {$file} on line {$line}", E_USER_NOTICE );
		}

		// 'action' parameter is required
		if ( empty( $_REQUEST['action'] ) ) {
			wp_die();
		}

		global $wp_query;
		$wp_query->set( 'appress-action', sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) );
		$action = $wp_query->get( 'appress-action' );

		if ( is_user_logged_in() ) {
			// an action must be registered
			if ( ! has_action( "appress_ajax_{$action}" ) ) {
				wp_die();
			}

			status_header(200);
			do_action( "appress_ajax_{$action}" );
		} else {
			// an action must be registered
			if ( ! has_action( "appress_ajax_nopriv_{$action}" ) ) {
				wp_die();
			}

			status_header(200);
			do_action( "appress_ajax_nopriv_{$action}" );
		}

		wp_die();
	}
}
