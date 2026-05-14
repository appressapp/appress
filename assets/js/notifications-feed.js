/**
 * Appress Notifications Feed — vanilla JS mount for `.appress-notifications` containers.
 *
 * Shared runtime for the `[appress_notifications]` shortcode, Elementor widget,
 * and Bricks element. The container is server-rendered as a bare skeleton with
 * data-attrs; this script reads config off the DOM and owns everything else:
 * fetching, rendering, pagination, mark-read, delete. Keeps builders simple
 * (they only emit a `<div class="appress-notifications" data-*>`).
 *
 * Global hook: `window.AppressNotificationsFeed.mount()` re-scans for new
 * unmounted instances — builders that inject DOM after initial page load
 * (Elementor editor preview, Bricks live-refresh) should call it.
 */
(function () {
	'use strict';

	var CFG = window.AppressNotificationsConfig || {};
	var I18N = CFG.i18n || {};
	var AJAX_URL = CFG.ajaxUrl || (window.location.origin + '/?appress=1');

	function t(key, fallback) {
		return typeof I18N[key] === 'string' && I18N[key].length ? I18N[key] : fallback;
	}

	function escapeHtml(str) {
		return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
			return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
		});
	}

	// Accepts three shapes:
	//   - MySQL "YYYY-MM-DD HH:MM:SS" (UTC, wp current_time mysql GMT=1) — from list AJAX.
	//   - ISO8601 "...Z" — defensive fallback.
	//   - Numeric seconds/ms since epoch — native FCM bridge posts seconds.
	// Normalize all to ms so `Date.parse`-equivalent math works.
	function parseUtc(ts) {
		if (ts == null || ts === '') return NaN;
		if (typeof ts === 'number') {
			return ts < 1e12 ? ts * 1000 : ts;
		}
		var s = String(ts);
		if (/^\d+$/.test(s)) {
			var n = parseInt(s, 10);
			return n < 1e12 ? n * 1000 : n;
		}
		return Date.parse(/Z$/.test(s) ? s : s.replace(' ', 'T') + 'Z');
	}

	function relativeTime(ts) {
		var when = parseUtc(ts);
		if (isNaN(when)) return '';
		var diff = Math.max(0, Date.now() - when);
		var sec = Math.floor(diff / 1000);
		if (sec < 60) return t('justNow', 'just now');
		var min = Math.floor(sec / 60);
		if (min < 60) return min + 'm';
		var hr = Math.floor(min / 60);
		if (hr < 24) return hr + 'h';
		var d = Math.floor(hr / 24);
		if (d < 7) return d + 'd';
		try { return new Date(when).toLocaleDateString(); } catch (e) { return ''; }
	}

	function isRecentlyArrived(ts) {
		// Dots only pulse for items created in the last 60s — enough to catch
		// notifications pushed via polling/live-refresh without noise on old items.
		var when = parseUtc(ts);
		return !isNaN(when) && (Date.now() - when) < 60 * 1000;
	}

	function post(action, bodyParams) {
		// Append the CSRF nonce on every mutating request — server rejects
		// without it. `getJson()` (read-only list) is safe to skip.
		var params = Object.assign({}, bodyParams || {}, { nonce: CFG.nonce || '' });
		var body = new URLSearchParams(params).toString();
		return fetch(AJAX_URL + '&action=' + encodeURIComponent(action), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
		});
	}

	function getJson(action, queryParams) {
		var q = new URLSearchParams(queryParams || {}).toString();
		var url = AJAX_URL + '&action=' + encodeURIComponent(action) + (q ? '&' + q : '');
		return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}

	function NotificationsFeed(root) {
		this.root = root;
		this.limit = parseInt(root.getAttribute('data-limit'), 10) || 10;
		this.emptyMessage = root.getAttribute('data-empty') || t('empty', 'No notifications yet.');
		this.showMarkAll = root.getAttribute('data-mark-all') === '1';
		this.showClearAll = root.getAttribute('data-clear-all') === '1';
		// Demo mode = builder canvas (Elementor / Bricks editor). Renders
		// hardcoded sample items so admins see realistic typography / spacing
		// while editing instead of an empty state. Mutations are visual-only
		// (no AJAX POST) so the demo can be re-explored without re-rendering.
		this.demo = root.getAttribute('data-demo') === '1';
		this.cursor = null;
		this.hasMore = true;
		this.loading = false;
		this.unreadCount = 0;
		this.itemCount = 0;
		this.buildSkeleton();
		this.bindEvents();
		if (this.demo) {
			this.renderDemo();
		} else {
			this.fetchPage();
		}
	}

	// Hardcoded sample items — shapes match the list endpoint payload so the
	// same renderItem() path runs (no special demo template to drift from prod).
	NotificationsFeed.prototype.renderDemo = function () {
		var now = Math.floor(Date.now() / 1000);
		var samples = [
			{ id: 'demo-1', source: 'appress', subject: 'New sign-in detected',
			  body: "A new sign-in on Chrome on macOS just happened. If this wasn't you, change your password.",
			  url: '#', image: '', is_read: false, created_at: now - 90 },
			{ id: 'demo-2', source: 'appress', subject: 'Order confirmation',
			  body: 'Thanks! Your order #12453 has been placed and is being prepared.',
			  url: '#', image: '', is_read: false, created_at: now - 25 * 60 },
			{ id: 'demo-3', source: 'appress', subject: 'Alice replied to you',
			  body: '"Thanks for the tip — that worked perfectly!" — on My Latest Post',
			  url: '#', image: '', is_read: true, created_at: now - 3 * 60 * 60 },
			{ id: 'demo-4', source: 'appress', subject: 'Welcome to the app',
			  body: 'Get started by exploring your dashboard and customizing your profile.',
			  url: '#', image: '', is_read: true, created_at: now - 2 * 24 * 60 * 60 },
		];
		// Honour `Items per page` in the builder canvas so admins can verify
		// the control actually works. Repeat-cycle the 4-sample pool when
		// limit exceeds it — good enough for a styling preview.
		var items = [];
		for (var i = 0; i < this.limit; i++) {
			items.push(Object.assign({}, samples[i % samples.length], { id: 'demo-' + (i + 1) }));
		}
		this.appendItems(items);
		this.itemCount = items.length;
		this.unreadCount = items.filter(function (x) { return !x.is_read; }).length;
		this.updateHeader(this.unreadCount);
		this.hasMore = false;
		this.$more.hidden = true;
		this.$loader.hidden = true;
		this.$empty.hidden = true;
	};

	NotificationsFeed.prototype.buildSkeleton = function () {
		var headerBtns = '';
		if (this.showMarkAll)  headerBtns += '<button type="button" class="appress-notifications__btn appress-notifications__mark-all">' + escapeHtml(t('markAllRead', 'Mark all read')) + '</button>';
		if (this.showClearAll) headerBtns += '<button type="button" class="appress-notifications__btn appress-notifications__clear-all">' + escapeHtml(t('clearAll', 'Clear all')) + '</button>';

		this.root.innerHTML = [
			'<div class="appress-notifications__header" hidden>',
				'<span class="appress-notifications__title" data-unread-count></span>',
				'<div class="appress-notifications__actions">', headerBtns, '</div>',
			'</div>',
			'<ul class="appress-notifications__list" role="list"></ul>',
			'<div class="appress-notifications__loader" aria-live="polite" hidden>' + escapeHtml(t('loading', 'Loading…')) + '</div>',
			'<button type="button" class="appress-notifications__more" hidden>' + escapeHtml(t('loadMore', 'Load more')) + '</button>',
			'<div class="appress-notifications__empty" hidden>',
				'<svg class="appress-notifications__empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">',
					'<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>',
					'<path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
				'</svg>',
				'<span class="appress-notifications__empty-message">' + escapeHtml(this.emptyMessage) + '</span>',
			'</div>',
		].join('');

		this.$header   = this.root.querySelector('.appress-notifications__header');
		this.$title    = this.root.querySelector('[data-unread-count]');
		this.$list     = this.root.querySelector('.appress-notifications__list');
		this.$loader   = this.root.querySelector('.appress-notifications__loader');
		this.$more     = this.root.querySelector('.appress-notifications__more');
		this.$empty    = this.root.querySelector('.appress-notifications__empty');
		this.$markAll  = this.root.querySelector('.appress-notifications__mark-all');
		this.$clearAll = this.root.querySelector('.appress-notifications__clear-all');
	};

	NotificationsFeed.prototype.bindEvents = function () {
		var self = this;
		this.$list.addEventListener('click', function (e) { self.onListClick(e); });
		this.$more.addEventListener('click', function () { self.fetchPage(); });
		if (this.$markAll)  this.$markAll.addEventListener('click', function () { self.markAllRead(); });
		if (this.$clearAll) this.$clearAll.addEventListener('click', function () { self.clearAll(); });
	};

	NotificationsFeed.prototype.fetchPage = function () {
		if (this.loading || !this.hasMore) return;
		this.loading = true;
		this.$loader.hidden = false;
		this.$more.hidden = true;

		var self = this;
		var params = { limit: this.limit };
		if (this.cursor) params.cursor = this.cursor;
		getJson('notifications.list', params).then(function (json) {
			if (!json || !json.success) {
				throw new Error(json && json.message ? json.message : 'Request failed');
			}
			self.appendItems(json.data.items || []);
			self.cursor = json.data.next_cursor || null;
			self.hasMore = !!json.data.has_more;
			self.itemCount += (json.data.items || []).length;
			self.updateHeader(json.data.unread_count || 0);
			self.$more.hidden = !self.hasMore;
			self.$empty.hidden = self.itemCount > 0;
		}).catch(function (err) {
			// Swallow — feed failure shouldn't break host page. Log for diagnostics.
			if (window.console) console.warn('[appress-notifications]', err);
		}).finally(function () {
			self.loading = false;
			self.$loader.hidden = true;
		});
	};

	NotificationsFeed.prototype.appendItems = function (items) {
		var frag = document.createDocumentFragment();
		for (var i = 0; i < items.length; i++) {
			frag.appendChild(this.renderItem(items[i]));
		}
		this.$list.appendChild(frag);
	};

	// Inline bell SVG shown when an item has no image. Kept as a constant so
	// every fallback render is identical — strokes/sizes are driven by CSS.
	var BELL_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
		'<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>' +
		'<path d="M13.73 21a2 2 0 0 1-3.46 0"/>' +
		'</svg>';

	NotificationsFeed.prototype.renderItem = function (item) {
		var li = document.createElement('li');
		li.className = 'appress-notifications__item';
		if (!item.is_read) li.classList.add('appress-notifications__item--unread');
		if (isRecentlyArrived(item.created_at)) li.classList.add('appress-notifications__item--new');
		li.setAttribute('data-id', item.id);
		if (item.source) li.setAttribute('data-source', item.source);

		var href = item.url ? escapeHtml(item.url) : '#';
		// Always render a media cell to the left — image if provided, bell SVG
		// fallback otherwise. Keeps alignment identical regardless of the item
		// source (WooCommerce orders with product image, plain app events…).
		var mediaHtml;
		if (item.image) {
			mediaHtml =
				'<span class="appress-notifications__media appress-notifications__media--image">' +
					'<img src="' + escapeHtml(item.image) + '" alt="" loading="lazy">' +
				'</span>';
		} else {
			mediaHtml = '<span class="appress-notifications__media appress-notifications__media--bell">' + BELL_SVG + '</span>';
		}
		var bodyHtml = item.body ? '<span class="appress-notifications__body">' + escapeHtml(item.body) + '</span>' : '';

		li.innerHTML = [
			'<a class="appress-notifications__link" href="' + href + '"',
			   item.url ? '' : ' aria-disabled="true" tabindex="-1"',
			'>',
				mediaHtml,
				'<span class="appress-notifications__content">',
					'<span class="appress-notifications__subject">' + escapeHtml(item.subject) + '</span>',
					bodyHtml,
					'<time class="appress-notifications__time" datetime="' + escapeHtml(item.created_at) + '">' + escapeHtml(relativeTime(item.created_at)) + '</time>',
				'</span>',
			'</a>',
			'<button type="button" class="appress-notifications__delete" aria-label="' + escapeHtml(t('deleteItem', 'Delete notification')) + '">×</button>',
		].join('');

		return li;
	};

	NotificationsFeed.prototype.updateHeader = function (unreadCount) {
		this.unreadCount = unreadCount;
		// Header is shown whenever there are items — buttons work even without unread
		// (Clear all works on every item; Mark all read no-ops on zero-unread).
		var showHeader = this.itemCount > 0 && (this.showMarkAll || this.showClearAll || unreadCount > 0);
		this.$header.hidden = !showHeader;
		if (this.$title) {
			var tmpl = t('unreadCount', '%d unread');
			this.$title.textContent = unreadCount > 0 ? tmpl.replace('%d', unreadCount) : '';
		}
		if (this.$markAll) {
			this.$markAll.hidden = unreadCount === 0;
		}
	};

	NotificationsFeed.prototype.onListClick = function (e) {
		// Delete button takes precedence — it must not navigate.
		var del = e.target.closest('.appress-notifications__delete');
		if (del) {
			e.preventDefault();
			e.stopPropagation();
			var liDel = del.closest('.appress-notifications__item');
			if (liDel) this.deleteItem(liDel);
			return;
		}

		var link = e.target.closest('.appress-notifications__link');
		if (!link) return;
		var li = link.closest('.appress-notifications__item');
		if (!li) return;

		// Fire-and-forget mark-read, optimistic UI — don't block the navigation.
		if (li.classList.contains('appress-notifications__item--unread')) {
			li.classList.remove('appress-notifications__item--unread');
			this.updateHeader(Math.max(0, this.unreadCount - 1));
			var id = li.getAttribute('data-id');
			if (!this.demo) post('notifications.mark_read', { id: id }).catch(function () {});
		}
		// In the builder canvas every link is a sample item — never let the
		// click navigate (would unload the editor preview). On the frontend,
		// only suppress the # placeholder.
		if (this.demo || !link.getAttribute('href') || link.getAttribute('href') === '#') {
			e.preventDefault();
		}
	};

	NotificationsFeed.prototype.deleteItem = function (li) {
		var id = li.getAttribute('data-id');
		var wasUnread = li.classList.contains('appress-notifications__item--unread');
		// Optimistic remove — if the request fails we log but don't rollback; the
		// row will reappear on next fetch anyway, which is acceptable.
		li.remove();
		this.itemCount = Math.max(0, this.itemCount - 1);
		if (wasUnread) this.updateHeader(Math.max(0, this.unreadCount - 1));
		else this.updateHeader(this.unreadCount);
		this.$empty.hidden = this.itemCount > 0;
		if (this.demo) return;
		post('notifications.delete', { id: id }).catch(function () {});
	};

	NotificationsFeed.prototype.markAllRead = function () {
		var self = this;
		var applyVisual = function () {
			var nodes = self.$list.querySelectorAll('.appress-notifications__item--unread');
			for (var i = 0; i < nodes.length; i++) nodes[i].classList.remove('appress-notifications__item--unread');
			self.updateHeader(0);
		};
		if (this.demo) { applyVisual(); return; }
		post('notifications.mark_all_read').then(applyVisual).catch(function () {});
	};

	NotificationsFeed.prototype.clearAll = function () {
		if (!window.confirm(t('clearAllConfirm', 'Delete all notifications? This cannot be undone.'))) return;
		var self = this;
		var applyVisual = function () {
			self.$list.innerHTML = '';
			self.cursor = null;
			self.hasMore = false;
			self.itemCount = 0;
			self.$more.hidden = true;
			self.updateHeader(0);
			self.$empty.hidden = false;
		};
		if (this.demo) { applyVisual(); return; }
		post('notifications.delete_all').then(applyVisual).catch(function () {});
	};

	// Full resync — wipe the list and refetch page 1. Reserved for state
	// divergence (mark_read in another WebView, manual integration sync).
	// For incoming FCM pushes, use `prependItem()` instead: the native bridge
	// emits the new row via `appress:notification:received` with the full
	// item payload, so we can splice it in without a round-trip.
	NotificationsFeed.prototype.refresh = function () {
		this.cursor = null;
		this.hasMore = true;
		this.itemCount = 0;
		this.$list.innerHTML = '';
		this.$more.hidden = true;
		this.$empty.hidden = true;
		this.fetchPage();
	};

	// Insert a new item at the top of the list without a server fetch.
	// Called by the native FCM bridge when a push arrives: the server has
	// already persisted the row (Notification_Service pipeline) and the
	// native handler hands us the full item shape, so the feed can add
	// the `<li>` directly — no loading flash, no scroll reset, no refetch.
	NotificationsFeed.prototype.prependItem = function (item) {
		if (!item || !item.id) return;
		// Duplicate guard — ignore re-emits for the same id. Protects against
		// double events from foreground + background delivery paths.
		var idStr = String(item.id);
		var existing = this.$list.querySelector('[data-id="' + idStr.replace(/"/g, '\\"') + '"]');
		if (existing) return;
		// Normalize: native shape uses same keys as the list endpoint. Fill in
		// sensible defaults for missing fields rather than rendering blanks.
		if (typeof item.is_read === 'undefined' || item.is_read === null) item.is_read = false;
		if (!item.created_at) {
			// Server stores `YYYY-MM-DD HH:MM:SS` UTC (no Z). Match that shape so
			// relativeTime() parses it correctly as UTC.
			item.created_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
		}
		if (typeof item.source === 'undefined') item.source = 'appress';
		var li = this.renderItem(item);
		if (this.$list.firstChild) {
			this.$list.insertBefore(li, this.$list.firstChild);
		} else {
			this.$list.appendChild(li);
		}
		this.itemCount += 1;
		this.updateHeader(this.unreadCount + (item.is_read ? 0 : 1));
		this.$empty.hidden = true;
	};

	var mountedInstances = [];

	function mount() {
		var nodes = document.querySelectorAll('.appress-notifications:not(.is-mounted)');
		for (var i = 0; i < nodes.length; i++) {
			nodes[i].classList.add('is-mounted');
			mountedInstances.push(new NotificationsFeed(nodes[i]));
		}
	}

	function refresh() {
		for (var i = 0; i < mountedInstances.length; i++) {
			try { mountedInstances[i].refresh(); } catch (e) {}
		}
	}

	function prependItem(item) {
		for (var i = 0; i < mountedInstances.length; i++) {
			try { mountedInstances[i].prependItem(item); } catch (e) {}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mount);
	} else {
		mount();
	}

	window.AppressNotificationsFeed = { mount: mount, refresh: refresh, prependItem: prependItem };

	// Native bridge fan-out: `AppressBridgeController.broadcastFromNative(eventType:"notification:changed")`
	// dispatches `appress:notification:changed` on every WebView's window so any
	// mounted feed can `refresh()` to pick up server-side state changes (push
	// tap mark-read, mark_all_read from another tab, etc.). `notification:received`
	// carries a full item payload and prepends without a refetch.
	window.addEventListener('appress:notification:changed', function () { refresh(); });
	window.addEventListener('appress:notification:received', function (e) {
		var item = e && e.detail;
		if (item) prependItem(item);
	});

	// Elementor editor preview: the widget DOM is injected via AJAX *after*
	// this script loads, so the initial mount() pass on DOMContentLoaded
	// finds nothing. Hook `frontend/element_ready/<widget>.default` so every
	// re-render (drag, setting change, Save & reload) picks up the fresh
	// `.appress-notifications` node. Safe no-op when Elementor isn't present.
	var hookElementor = function () {
		if (!window.elementorFrontend || !window.elementorFrontend.hooks) return false;
		window.elementorFrontend.hooks.addAction(
			'frontend/element_ready/appress-notifications.default',
			function () { mount(); }
		);
		return true;
	};
	if (!hookElementor()) {
		window.addEventListener('elementor/frontend/init', hookElementor);
	}

	// Builder catch-all via MutationObserver. Bricks (and any builder that
	// rewrites the canvas DOM over AJAX) doesn't fire a predictable JS event
	// we can hook into like Elementor does — so we watch the document for
	// added `.appress-notifications` nodes and mount them. On the public
	// frontend this observer effectively never fires (DOM is static after
	// initial load) so the overhead is negligible.
	if (typeof MutationObserver !== 'undefined') {
		var observerMount = function (node) {
			if (!(node instanceof Element)) return;
			if (node.matches && node.matches('.appress-notifications:not(.is-mounted)')) {
				mount();
				return;
			}
			if (node.querySelector && node.querySelector('.appress-notifications:not(.is-mounted)')) {
				mount();
			}
		};
		var bodyObserver = new MutationObserver(function (mutations) {
			for (var i = 0; i < mutations.length; i++) {
				var added = mutations[i].addedNodes;
				for (var j = 0; j < added.length; j++) {
					observerMount(added[j]);
				}
			}
		});
		var attach = function () {
			if (document.body) bodyObserver.observe(document.body, { childList: true, subtree: true });
		};
		if (document.body) {
			attach();
		} else {
			document.addEventListener('DOMContentLoaded', attach);
		}
	}
})();
