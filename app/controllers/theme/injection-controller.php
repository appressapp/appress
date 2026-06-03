<?php

namespace Appress\Controllers\Theme;

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
if (! defined('ABSPATH')) {
    exit;
}

class Injection_Controller extends \Appress\Controllers\Base_Controller
{
    /**
     * Only boot this controller and register hooks if we are inside the Appress Webview environment.
     * This saves resources on standard web requests since all 4 scripts below are app-exclusive.
     */
    protected function authorize()
    {
        return \Appress\is_app();
    }

    protected function hooks()
    {
        // All four inline assets live behind virtual handles registered on
        // wp_enqueue_scripts so WP prints them through its enqueue pipeline.
        // Native iOS/Android already override `--appress-status-bar-height`
        // at atDocumentStart (long before any wp_head hook), so emitting
        // these at the standard style/script print priorities is safe.
        $this->on('wp_enqueue_scripts', '@enqueue_native_assets');

        // Priority 999 runs AFTER WP core + most plugins have emitted
        // their own Cache-Control headers (nocache_headers plugins land
        // at the default priority). We overwrite last so the prefetched
        // WebView responses actually cache for the configured window.
        // Gated inside the method on URI exclusion rules + admin-chosen
        // duration; zero or missing duration = no-op (prefetch still
        // fires but HTTP cache stays as WP sent it).
        $this->on('send_headers', '@relax_cache_control_for_prefetch', 999);

        // Per-app native-class-ID indirection table — printed FIRST in
        // `<head>` so every later script (plugin static widget JS, theme
        // inline JS) can look up the salted bridge handler names the
        // mobile binary registered. Build-engine mutator scrambles every
        // `\bAppress[A-Z]\w*` symbol in native source to
        // `<salt><hmac12(suffix)>` to keep `Appress` out of the
        // submitted IPA's `__TEXT` segment (Apple 4.3(a)). Slave JS in
        // the webview can't know the per-build salt at compile time —
        // hence this runtime map. Plus a `<style>` alias so customer-
        // site CSS that reads literal `var(--appress-status-bar-height)`
        // (Bricks / Elementor compiled .css served by Nginx) resolves
        // through to the salted custom property the binary injects.
        // Gated on `is_app()` + a resolved `unique_class`; web requests
        // and legacy pre-Phase-4 apps fall through unchanged.
        $this->on( 'wp_head', '@emit_native_class_ids', 1 );

        // Heredoc inline JS in plugin integration controllers (woo
        // biometric account, smart banner, indicator, etc.) still emits
        // literal `window.AppressNativeBridge.postMessage(…)` calls
        // because refactoring every PHP heredoc to use the runtime
        // indirection lookup would touch ~60 sites with no functional
        // gain. The output buffer below catches them at render time
        // and rewrites the literal bridge handler names to the salted
        // form the mobile binary registers — same HMAC-SHA256(salt,
        // suffix) truncation as `mutator.js applyClassMutation`. Static
        // `.js` widget files served by Nginx bypass this buffer; they
        // use the explicit `window.AppressClassIds` indirection added
        // in the refactor above.
        //
        // PHP-localized config object names that live ENTIRELY on the
        // web side (`AppressBiometric`, `AppressNotificationsConfig`,
        // `AppressAccountDeletion`, `AppressBiometricI18n`,
        // `AppressClassIds` itself) skip the rewrite — they're set by
        // `wp_localize_script` for static .js consumers, never
        // referenced by the mobile binary, and rewriting them would
        // sever the static-JS read.
        $this->on('template_redirect', '@start_boundary_buffer', 0);
    }

    /**
     * Output buffer that salts plugin-emitted `Appress<X>` heredoc
     * inline JS bridge calls to match the mobile binary's salted
     * handler names. Skips web-only PHP-localized config object names.
     */
    public function start_boundary_buffer() {
        $ids = \Appress\get_native_class_ids();
        if ( empty( $ids ) ) return;
        $ns      = (string) $ids['namespace'];
        $salt_lc = strtolower( $ns );

        ob_start( function ( $buffer ) use ( $ns, $salt_lc ) {
            if ( ! is_string( $buffer ) || $buffer === '' ) return $buffer;

            // Names set / read ONLY on the web side — never appear in
            // the submitted mobile binary, so salting them in HTML
            // would break the static-JS read. `AppressClassIds` is
            // the indirection table itself; the rest are
            // `wp_localize_script` config objects.
            static $excludes = [
                'AppressClassIds',
                'AppressBiometric',
                'AppressBiometricI18n',
                'AppressNotificationsConfig',
                'AppressAccountDeletion',
            ];

            $buffer = preg_replace_callback(
                '/\bAppress([A-Z]\w*)/',
                function ( $m ) use ( $ns, $excludes ) {
                    if ( in_array( $m[0], $excludes, true ) ) return $m[0];
                    return $ns . substr( hash_hmac( 'sha256', $m[1], $ns ), 0, 12 );
                },
                $buffer
            );
            // `window.Appress` bare namespace.
            $buffer = preg_replace(
                '/\bwindow\.Appress(?![A-Za-z0-9_])/',
                'window.' . $ns,
                $buffer
            );
            // CSS custom property — the binary's runtime injection
            // writes the salted custom property name. Customer
            // stylesheets in static .css files still read literal
            // `var(--appress-status-bar-height)` and resolve through
            // the `:root{…}` alias emitted by `emit_native_class_ids`;
            // PHP-emitted inline CSS goes through this buffer and gets
            // rewritten so the alias isn't needed for them.
            $buffer = str_replace(
                '--appress-status-bar-height',
                '--' . $salt_lc . '-status-bar-height',
                $buffer
            );
            return $buffer;
        } );
    }

    /**
     * Emit `<script>window.AppressClassIds = {…}</script>` + the
     * `--appress-status-bar-height` CSS alias as early in `<head>` as
     * possible so every downstream script + style block sees the
     * indirection map populated.
     */
    public function emit_native_class_ids() {
        $ids = \Appress\get_native_class_ids();
        if ( empty( $ids ) ) return;

        $salt    = (string) $ids['namespace'];
        $salt_lc = strtolower( $salt );

        // `window.AppressClassIds` lives only in the customer's
        // rendered HTML + the plugin's static .js widget files — never
        // in the submitted mobile binary — so the literal name is fine
        // to keep readable.
        echo '<script>window.AppressClassIds=' . wp_json_encode( $ids ) . ";</script>\n";

        // Runtime mirror script — wires customer's literal surface
        // (`window.Appress.*`, `class="appress-open-menu"`,
        // `data-appress-action="…"`) to the salted form the native
        // binary uses after mutator. Without this, every native JS
        // inject that says `<salt>.indicator.refresh()` or
        // `closest('.<saltLc>-open-menu')` would miss the customer's
        // legacy-named objects/elements. Runs at wp_head priority 1
        // (before any plugin/theme inline JS) + a MutationObserver to
        // catch SPAs / Vue / customer-late-rendered DOM.
        $ns_js  = wp_json_encode( $salt );
        $css_js = wp_json_encode( $salt_lc );
        echo "<script>(function(){var NS={$ns_js},CP={$css_js};if(NS==='Appress')return;"
            . "window[NS]=window[NS]||window.Appress||{};window.Appress=window[NS];"
            . "var MAP={'appress-open-menu':CP+'-open-menu','appress-open-right-menu':CP+'-open-right-menu',"
            . "'appress-qr-scanner-trigger':CP+'-qr-scanner-trigger',"
            . "'appress-dismiss-first-launch-screen':CP+'-dismiss-first-launch-screen',"
            . "'appress-sticky':CP+'-sticky','appress-preview-trigger':CP+'-preview-trigger'};"
            . "function mirror(el){if(!el||!el.classList)return;"
            . "for(var k in MAP){if(el.classList.contains(k)&&!el.classList.contains(MAP[k]))el.classList.add(MAP[k]);}"
            . "if(el.hasAttribute&&el.hasAttribute('data-appress-action')){"
            . "var v=el.getAttribute('data-appress-action');"
            . "if(!el.hasAttribute('data-'+CP+'-action'))el.setAttribute('data-'+CP+'-action',v);}}"
            . "function scan(r){if(!r)return;mirror(r);"
            . "if(r.querySelectorAll){var n=r.querySelectorAll('[class*=\"appress-\"],[data-appress-action]');"
            . "for(var i=0;i<n.length;i++)mirror(n[i]);}}"
            . "function boot(){scan(document.body||document.documentElement);"
            . "if(window.MutationObserver){new MutationObserver(function(ms){"
            . "for(var i=0;i<ms.length;i++){var a=ms[i].addedNodes;if(!a)continue;"
            . "for(var j=0;j<a.length;j++)if(a[j].nodeType===1)scan(a[j]);}})"
            . ".observe(document.documentElement,{childList:true,subtree:true});}}"
            . "if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);"
            . "else boot();})();</script>\n";

        // CSS alias: the binary's slave-JS injection writes the salted
        // `--<salt_lc>-status-bar-height` custom property at
        // documentStart. Customer stylesheets (Bricks compiled CSS,
        // Elementor per-page CSS, theme files) read literal
        // `var(--appress-status-bar-height)` because those .css files
        // are static and Nginx-served — PHP can't rewrite them. This
        // alias resolves the literal name through to the salted one,
        // so every customer surface keeps working without us leaking
        // the literal name into the binary's `__TEXT`.
        echo '<style>:root{--appress-status-bar-height:var(--' . esc_html( $salt_lc ) . "-status-bar-height,0px)}</style>\n";
    }

    public function enqueue_native_assets()
    {
        $version = \Appress\get_assets_version();

        // Foundational sticky-system CSS variables.
        wp_register_style('appress-native-vars', false, [], $version);
        wp_enqueue_style('appress-native-vars');
        wp_add_inline_style('appress-native-vars', $this->native_css_vars());

        // Sticky observer JS (registers window.Appress.sticky).
        wp_register_script('appress-sticky', false, [], $version, false);
        wp_enqueue_script('appress-sticky');
        wp_add_inline_script('appress-sticky', $this->sticky_observer_js());

        // Per-app inline-link-selectors hydration (depends on appress-sticky
        // so it loads after the namespace setup runs).
        $app_id = \Appress\get_current_app_id();
        if ($app_id > 0) {
            $selectors = \Appress\get_inline_link_selectors($app_id);
            if (!empty($selectors)) {
                wp_register_script('appress-inline-link-selectors', false, ['appress-sticky'], $version, false);
                wp_enqueue_script('appress-inline-link-selectors');
                $ns = \Appress\get_js_namespace( $app_id );
                wp_add_inline_script(
                    'appress-inline-link-selectors',
                    "window.{$ns} = window.{$ns} || {}; window.{$ns}.inlineLinkSelectors = " . wp_json_encode( array_values( $selectors ) ) . ';',
                    'before'
                );
            }
        }

        // Native-feel behavior overrides (user-select etc.).
        wp_register_style('appress-native-behavior', false, [], $version);
        wp_enqueue_style('appress-native-behavior');
        wp_add_inline_style('appress-native-behavior', $this->native_behavior_css());
    }

    /**
     * Relax Cache-Control on app-origin requests so the native slave
     * WebViews' prefetch warm-up actually speeds up subsequent taps.
     *
     * Default WordPress behaviour for logged-in users is
     * `Cache-Control: no-store, no-cache, must-revalidate`, which
     * refuses to cache the prefetched response → prefetch has zero
     * effect. This filter overwrites with `private, max-age=N,
     * must-revalidate` for paths NOT in the exclude list.
     *
     * Scope:
     *   - Only runs because `Injection_Controller::authorize()` gates
     *     the whole controller on `\Appress\is_app()` — web browser
     *     requests never hit this function, so SEO + logged-in web
     *     state is untouched.
     *   - Per-request URI is matched against the admin-managed
     *     exclude list (cart / checkout / account / admin by default)
     *     → sensitive + dynamic paths keep their original headers.
     *   - Duration = 0 disables the whole integration (header left as-is,
     *     prefetch still fires but nothing is cached).
     */
    public function relax_cache_control_for_prefetch()
    {
        $app_id = \Appress\get_current_app_id();
        if ($app_id <= 0) {
            return;
        }

        $cfg = $this->load_prefetch_cache_config($app_id);
        if ($cfg['duration'] <= 0) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ($uri === '') {
            return;
        }

        foreach ($cfg['excludes'] as $needle) {
            if ($needle !== '' && strpos($uri, $needle) !== false) {
                return;
            }
        }

        // `private` keeps this out of shared caches (CDN / reverse
        // proxies) — the WebView is single-user so that's the right
        // store. `must-revalidate` keeps the WebView honest once the
        // TTL expires. `header()` without 2nd arg appends, but WP /
        // plugins typically used `send_origin_headers()` which uses
        // `nocache_headers()` — explicit `true` overwrites so our
        // value is authoritative.
        header(sprintf('Cache-Control: private, max-age=%d, must-revalidate', (int) $cfg['duration']), true);
        // Strip WP's Expires + Pragma that `nocache_headers()` sets —
        // leaving them behind would push the WebView to re-validate
        // and nullify the max-age.
        header_remove('Expires');
        header_remove('Pragma');
    }

    /**
     * Read + cache (per-request) the prefetch cache settings for
     * this app. Static cache means the DB query for `live_config`
     * hits once regardless of how many sub-resource requests share
     * the PHP worker.
     *
     * @return array{duration:int, excludes:string[]}
     */
    private function load_prefetch_cache_config(int $app_id): array
    {
        static $cache = [];
        if (isset($cache[$app_id])) {
            return $cache[$app_id];
        }

        global $wpdb;
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT build_config FROM {$wpdb->prefix}appress_apps WHERE id = %d",
                $app_id
            )
        );
        $live = $raw ? json_decode($raw, true) : [];
        if (!is_array($live)) {
            $live = [];
        }

        $duration = isset($live['prefetch_cache_duration']) ? (int) $live['prefetch_cache_duration'] : 0;
        $excludes = [];
        if (!empty($live['prefetch_cache_exclude_paths']) && is_string($live['prefetch_cache_exclude_paths'])) {
            foreach (preg_split('/\r\n|\r|\n/', $live['prefetch_cache_exclude_paths']) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $excludes[] = $line;
                }
            }
        }

        $cache[$app_id] = [
            'duration' => max(0, $duration),
            'excludes' => $excludes,
        ];
        return $cache[$app_id];
    }

    /**
     * Sticky-system CSS body. `--appress-status-bar-height` is injected
     * by native iOS/Android at document start — we never define it here
     * (PHP would clobber the native value). All usages include the
     * fallback `var(--appress-status-bar-height, 0px)`.
     */
    private function native_css_vars(): string
    {
        return <<<'CSS'
.appress-sticky {
    position: sticky;
    top: var(--appress-status-bar-height, 0px);
    z-index: 999;
}
.appress-sticky.appress-stuck {
    top: 0 !important;
    padding-top: calc(var(--appress-orig-padding, 0px) + var(--appress-status-bar-height, 0px)) !important;
    margin-bottom: calc(var(--appress-orig-margin, 0px) - var(--appress-status-bar-height, 0px)) !important;
}
CSS;
    }

    /**
     * ── Smart Sticky System ────────────────────────────────────────────────
     * Class .appress-sticky auto-detects when an element becomes
     * stuck and extends its background into the transparent status bar zone.
     *
     * HOW IT WORKS:
     *   • Not stuck → top: var(--appress-status-bar-height). Status bar is transparent.
     *   • Becomes stuck → JS sets the original padding/margin via CSS variables.
     *   • Then dynamically adds padding-top (to fill status bar) AND subtracts
     *     margin-bottom (to prevent layout from pushing content down).
     *
     * USAGE (HTML):   <div class="filter-bar appress-sticky">Filter Results</div>
     * DYNAMIC CALLS:  window.Appress.sticky.observe(el)
     */
    private function sticky_observer_js(): string
    {
        return <<<'JS'
(function() {
    function setStatusBar(style) {
        if (window.AppressNativeBridge && typeof window.AppressNativeBridge.setStatusBar === "function") {
            window.AppressNativeBridge.setStatusBar(style);
        } else if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.bridge) {
            window.webkit.messageHandlers.bridge.postMessage({ type: "message", pluginId: "StatusBar", methodName: "setStyle", options: { style: style }, callbackId: "ap_" + Math.random() });
            if (window.webkit.messageHandlers.AppressNativeBridge) {
                window.webkit.messageHandlers.AppressNativeBridge.postMessage(JSON.stringify({ type: "appress_status_bar", style: style }));
            }
        } else if (window.Capacitor && window.Capacitor.Plugins) {
            if (!window.Capacitor.Plugins.StatusBar && typeof window.Capacitor.registerPlugin === "function") { window.Capacitor.registerPlugin("StatusBar"); }
            if (window.Capacitor.Plugins.StatusBar) { window.Capacitor.Plugins.StatusBar.setStyle({ style: style }).catch(function(){}); }
        }
    }

    function getLuminance(r, g, b) {
        var a = [r, g, b].map(function(v) {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return a[0] * 0.2126 + a[1] * 0.7152 + a[2] * 0.0722;
    }

    function detectColorAndSet(elm) {
        var bg = window.getComputedStyle(elm).backgroundColor;
        var curr = elm;
        while ((bg === "rgba(0, 0, 0, 0)" || bg === "transparent" || bg === "rgba(0,0,0,0)") && curr.parentElement) {
            curr = curr.parentElement;
            bg = window.getComputedStyle(curr).backgroundColor;
        }
        var rgba = bg.match(/\d+/g);
        if (rgba && rgba.length >= 3) {
            var lum = getLuminance(parseInt(rgba[0]), parseInt(rgba[1]), parseInt(rgba[2]));
            setStatusBar(lum > 0.5 ? "LIGHT" : "DARK");
        }
    }

    function observe(el) {
        if (el.dataset.appressObserved) return;
        el.dataset.appressObserved = "1";
        var s = document.createElement("div");
        s.style.cssText = "position:relative; top:calc(-1 * var(--appress-status-bar-height, 0px)); height:1px; margin-bottom:-1px; visibility:hidden; pointer-events:none;";
        el.parentNode.insertBefore(s, el);

        new IntersectionObserver(function(e) {
            var ent = e[0];
            var isStuck = !ent.isIntersecting && ent.boundingClientRect.top < 0;
            var isNativeApp = (window.Capacitor && window.Capacitor.Plugins) ||
                              (window.AppressNativeBridge && typeof window.AppressNativeBridge.setStatusBar === "function") ||
                              (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.bridge) ||
                              (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.AppressNativeBridge);

            if (isStuck && !el.dataset.hasStuck) {
                var cs = window.getComputedStyle(el);
                el.style.setProperty("--appress-orig-padding", cs.paddingTop);
                el.style.setProperty("--appress-orig-margin", cs.marginBottom);
                el.dataset.hasStuck = "1";
                if (isNativeApp && parseInt(getComputedStyle(document.documentElement).getPropertyValue("--appress-status-bar-height")) > 0) {
                    detectColorAndSet(el);
                }
            } else if (!isStuck && el.dataset.hasStuck) {
                el.dataset.hasStuck = "";
                if (isNativeApp && parseInt(getComputedStyle(document.documentElement).getPropertyValue("--appress-status-bar-height")) > 0) {
                    window.Appress.initialStatusBarStyle ? setStatusBar(window.Appress.initialStatusBarStyle) : detectColorAndSet(document.body);
                }
            }
            el.classList.toggle("appress-stuck", isStuck);
        }, { threshold: [1] }).observe(s);
    }

    function init() { document.querySelectorAll(".appress-sticky").forEach(observe); }
    if(document.readyState === "loading") { document.addEventListener("DOMContentLoaded", init); } else { init(); }
    window.Appress = window.Appress || {};
    window.Appress.sticky = { observe: observe };
})();
JS;
    }

    private function native_behavior_css(): string
    {
        return <<<'CSS'
html, body {
    -webkit-user-select: none !important;
    user-select: none !important;
    -webkit-touch-callout: none !important;
}
input, textarea, [contenteditable="true"] {
    -webkit-user-select: auto !important;
    user-select: auto !important;
}
CSS;
    }

}
