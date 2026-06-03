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

  // Per-app native-class-ID indirection (see back-button-widget.js).
  var IDS = window.AppressClassIds || {};
  var NB_KEY = IDS.native || 'AppressNativeBridge';
  var MB_KEY = IDS.master || 'AppressMasterBridge';

  function isInApp() {
    var nb = window[NB_KEY];
    if (nb && typeof nb.postMessage === 'function') return true;
    var mb = window[MB_KEY];
    if (mb && typeof mb.postMessage === 'function') return true;
    var mh = window.webkit && window.webkit.messageHandlers;
    return !!(mh && (mh[MB_KEY] || mh[NB_KEY]));
  }

  function postToBridge(payload) {
    var serialized = JSON.stringify(payload);
    try {
      var mh = window.webkit && window.webkit.messageHandlers;
      var iosHandler = mh && (mh[MB_KEY] || mh[NB_KEY]);
      if (iosHandler) {
        iosHandler.postMessage(serialized);
        return true;
      }
      var nb = window[NB_KEY];
      if (nb && typeof nb.postMessage === 'function') {
        nb.postMessage(serialized);
        return true;
      }
      var mb = window[MB_KEY];
      if (mb && typeof mb.postMessage === 'function') {
        mb.postMessage(serialized);
        return true;
      }
    } catch (e) {
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
