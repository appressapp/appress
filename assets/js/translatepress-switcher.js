/**
 * `[appress_translatepress_switcher]` — language switcher behaviour.
 *
 * Two surfaces, one event listener:
 *   1. Inside the Appress mobile app — posts a `translatepress.changeLanguage`
 *      message to the native bridge. Native persists the language, then
 *      cold-restarts (via AppressColdRestartController) so every native
 *      module re-applies the cached translatepress variant on the next
 *      bootstrap. The page itself does NOT navigate; the native cold-
 *      restart owns the transition + shows AppressAuthOverlayView.
 *   2. Plain web — the link's `href` (or the <select>'s `data-url`)
 *      handles a normal TRP redirect, identical to TRP's own switcher.
 *
 * Bridge contract — must match the
 * `translatepress.changeLanguage` handler registered by
 * AppressTranslatepressController via AppressIntegrationBridge:
 *
 *   { method: "translatepress.changeLanguage", args: { lang: "<code>" } }
 *
 * Posted via `AppressNativeBridge.postMessage(...)` (Android) or
 * `window.webkit.messageHandlers.AppressNativeBridge.postMessage(...)`
 * (iOS) — same primary bridge every other widget uses.
 */
(function () {
  'use strict';

  function isInApp() {
    // Android slave (tab / subscreen): AppressNativeBridge.postMessage.
    if (window.AppressNativeBridge && typeof window.AppressNativeBridge.postMessage === 'function') {
      return true;
    }
    // Android master (Capacitor WebView): AppressMasterBridge.postMessage —
    // distinct JSInterface from slave (master also exposes typed methods
    // like authReport, biometricEnable; postMessage is the generic
    // integration-dispatch entry point).
    if (window.AppressMasterBridge && typeof window.AppressMasterBridge.postMessage === 'function') {
      return true;
    }
    // iOS: master (Capacitor WebView) or slave (tab / subscreen) bridge.
    var mh = window.webkit && window.webkit.messageHandlers;
    return !!(mh && (mh.AppressMasterBridge || mh.AppressNativeBridge));
  }

  function postToBridge(payload) {
    // All handlers (iOS master, iOS slave, Android master, Android slave)
    // parse the body as a JSON string — always stringify regardless of
    // platform.
    var serialized = JSON.stringify(payload);
    try {
      var mh = window.webkit && window.webkit.messageHandlers;
      var iosHandler = mh && (mh.AppressMasterBridge || mh.AppressNativeBridge);
      if (iosHandler) {
        iosHandler.postMessage(serialized);
        return true;
      }
      // Android slave first — most pages render inside a slave tab.
      if (window.AppressNativeBridge && typeof window.AppressNativeBridge.postMessage === 'function') {
        window.AppressNativeBridge.postMessage(serialized);
        return true;
      }
      // Android master fallback — switcher rendered on auth gate /
      // first launch page (Capacitor master WebView, no slave bridge).
      if (window.AppressMasterBridge && typeof window.AppressMasterBridge.postMessage === 'function') {
        window.AppressMasterBridge.postMessage(serialized);
        return true;
      }
    } catch (e) {
      // Bridge failure shouldn't strand the user. Log and let the
      // default link navigation take over.
      console.error('[appress-trp-switcher] bridge failed', e);
    }
    return false;
  }

  function saveUserLanguageServerSide(lang) {
    // Fire-and-forget persist to WP-native `user_meta.locale` (the same
    // key WP's built-in profile language picker writes to) for logged-in
    // users so next login from any device auto-applies this pick via
    // the native `translatepress.get_user_language` lookup. Guest hits
    // the endpoint's auth gate → 401 silently; no UX impact. We do NOT
    // await — the native bridge below fires immediately so the cold-
    // restart starts even if the server save is slow.
    try {
      var body = 'lang=' + encodeURIComponent(lang);
      fetch('/?appress=1&action=translatepress.save_user_language', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
        keepalive: true
      }).catch(function () {});
    } catch (e) {}
  }

  function changeLanguage(lang) {
    saveUserLanguageServerSide(lang);
    return postToBridge({ method: 'translatepress.changeLanguage', args: { lang: lang } });
  }

  function onItemClick(e) {
    if (!isInApp()) return; // Web fallback — let the <a href> redirect happen.

    var target = e.target;
    while (target && !target.matches('.appress-trp-switcher__item')) {
      target = target.parentElement;
    }
    if (!target) return;

    var lang = target.getAttribute('data-lang');
    if (!lang) return;

    e.preventDefault();
    changeLanguage(lang);
  }

  function onSelectChange(e) {
    var select = e.target;
    var option = select.options[select.selectedIndex];
    if (!option) return;
    var lang = option.value;

    if (isInApp()) {
      changeLanguage(lang);
      return;
    }
    var url = option.getAttribute('data-url');
    if (url) {
      window.location.href = url;
    }
  }

  function bootstrap() {
    document.querySelectorAll('.appress-trp-switcher__item').forEach(function (el) {
      el.addEventListener('click', onItemClick);
    });
    document.querySelectorAll('.appress-trp-switcher__select').forEach(function (el) {
      el.addEventListener('change', onSelectChange);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
  } else {
    bootstrap();
  }
})();
