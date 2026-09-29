/* Adventistai – Slapukų sutikimas */
(function () {
	'use strict';

	function boot() {
	var KEY = 'aicc_consent';
	var LANG_KEY = 'aicc_lang';
	var DISMISS_KEY = 'aicc_dismissed';

	var root = document.getElementById('aicc-root');
	if (!root) { return; }

	// Prefer the localized object; fall back to the copy carried by the markup,
	// which survives script deferring, concatenation and inline-script stripping.
	var CFG = window.AICC || {};
	if (!CFG.categories) {
		var raw = root.getAttribute('data-aicc-config');
		if (raw) {
			try { CFG = JSON.parse(raw); } catch (e) { CFG = {}; }
		}
	}
	if (!CFG.categories || !CFG.categories.length) {
		if (window.console) { window.console.warn('aicc: no configuration found'); }
		return;
	}
	window.AICC = CFG;
	if (root.getAttribute('data-aicc-ready') === '1') { return; }
	root.setAttribute('data-aicc-ready', '1');

	var banner = root.querySelector('.aicc-banner');
	var modal = root.querySelector('[data-aicc-modal]');
	var groupsBox = root.querySelector('[data-aicc-groups]');
	var langsBox = root.querySelector('[data-aicc-langs]');
	var fab = root.querySelector('.aicc-fab');

	var lang = CFG.lang;
	var state = null;          // saved consent, or null
	var draft = {};            // toggle state inside the panel
	var lastFocus = null;
	var hideTimer = null;

	/* ---------------- storage ---------------- */

	function read(key) {
		try { return window.localStorage.getItem(key); } catch (e) { return null; }
	}

	function write(key, value) {
		try { window.localStorage.setItem(key, value); } catch (e) { /* private mode */ }
	}

	function remove(key) {
		try { window.localStorage.removeItem(key); } catch (e) { /* ignore */ }
	}

	function dismissedThisSession() {
		try { return window.sessionStorage.getItem(DISMISS_KEY + '_' + CFG.revision) === '1'; } catch (e) { return false; }
	}

	function markDismissed() {
		try { window.sessionStorage.setItem(DISMISS_KEY + '_' + CFG.revision, '1'); } catch (e) { /* ignore */ }
	}

	function loadState() {
		var raw = read(KEY);
		if (!raw) { return null; }
		var data;
		try { data = JSON.parse(raw); } catch (e) { return null; }
		if (!data || typeof data !== 'object' || !data.cats || typeof data.cats !== 'object' || Array.isArray(data.cats)) { return null; }
		if (data.rev !== CFG.revision) { return null; }               // list changed — ask again
		var maxAge = (CFG.days || 180) * 86400000;
		if (typeof data.ts !== 'number' || !isFinite(data.ts) || data.ts <= 0 || data.ts > Date.now() + 300000 || (Date.now() - data.ts) > maxAge) { return null; }
		if (typeof data.cid !== 'string' || !/^[A-Za-z0-9-]{8,40}$/.test(data.cid)) { return null; }

		var clean = {};
		optionalCats().forEach(function (cat) { clean[cat.slug] = data.cats[cat.slug] === true; });

		return {
			cats: clean,
			rev: CFG.revision,
			ts: data.ts,
			lang: (typeof data.lang === 'string' && CFG.langs[data.lang]) ? data.lang : CFG.lang,
			cid: data.cid
		};
	}

	function uuid() {
		if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
		return 'c-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
	}

	function saveState(cats) {
		var clean = {};
		optionalCats().forEach(function (cat) { clean[cat.slug] = cats[cat.slug] === true; });
		state = {
			cats: clean,
			rev: CFG.revision,
			ts: Date.now(),
			lang: lang,
			cid: (state && state.cid) || uuid()
		};
		write(KEY, JSON.stringify(state));
		applyConsent();
		logConsent();
	}

	/* ---------------- helpers ---------------- */

	function S(key) {
		var pack = (CFG.strings && CFG.strings[lang]) || {};
		return pack[key] || key;
	}

	function optionalCats() {
		return CFG.categories.filter(function (c) { return c.optional; });
	}

	function impliedCats() {
		return CFG.implied || [];
	}

	function allowed(slug) {
		if (slug === 'necessary') { return true; }
		// Opt-out: before the visitor decides, the implied set counts as allowed.
		if (!state) { return impliedCats().indexOf(slug) !== -1; }
		return !!(state.cats && state.cats[slug] === true);
	}

	function allOn() {
		var out = {};
		optionalCats().forEach(function (c) { out[c.slug] = true; });
		return out;
	}

	function allOff() {
		var out = {};
		optionalCats().forEach(function (c) { out[c.slug] = false; });
		return out;
	}

	/* ---------------- unblocking ---------------- */

	function unblockScripts() {
		var nodes = document.querySelectorAll('script[type="text/plain"][data-aicc-cat]');
		Array.prototype.forEach.call(nodes, function (node) {
			if (!allowed(node.getAttribute('data-aicc-cat'))) { return; }

			var fresh = document.createElement('script');
			Array.prototype.forEach.call(node.attributes, function (attr) {
				if (attr.name === 'type' || attr.name.indexOf('data-aicc') === 0) { return; }
				fresh.setAttribute(attr.name, attr.value);
			});
			var originalType = node.getAttribute('data-aicc-type');
			if (originalType) { fresh.type = originalType; }

			var src = node.getAttribute('data-aicc-src');
			if (src) {
				fresh.async = false;   // keep execution order for injected scripts
				fresh.src = src;
			} else {
				fresh.text = node.textContent;
			}

			node.parentNode.replaceChild(fresh, node);
		});
	}

	function decode(b64) {
		var binary = window.atob(b64);
		var bytes = new Uint8Array(binary.length);
		for (var i = 0; i < binary.length; i++) { bytes[i] = binary.charCodeAt(i); }
		if (window.TextDecoder) { return new TextDecoder('utf-8').decode(bytes); }
		return binary;
	}

	function restoreEmbed(box) {
		var markup = box.getAttribute('data-aicc-embed');
		if (!markup || markup.length > 1048576 || !/^[A-Za-z0-9+/=]+$/.test(markup)) { return; }
		var holder = document.createElement('div');
		try { holder.innerHTML = decode(markup); } catch (e) { return; }
		var node = holder.firstElementChild;
		if (node && node.tagName === 'IFRAME' && holder.children.length === 1) {
			box.parentNode.replaceChild(node, box);
		}
	}

	function unblockEmbeds() {
		var boxes = document.querySelectorAll('.aicc-embed[data-aicc-cat]');
		Array.prototype.forEach.call(boxes, function (box) {
			if (allowed(box.getAttribute('data-aicc-cat'))) { restoreEmbed(box); }
		});
	}

	function consentModeUpdate() {
		if (!CFG.consentMode || typeof window.gtag !== 'function') { return; }
		window.gtag('consent', 'update', {
			ad_storage: allowed('marketing') ? 'granted' : 'denied',
			ad_user_data: allowed('marketing') ? 'granted' : 'denied',
			ad_personalization: allowed('marketing') ? 'granted' : 'denied',
			analytics_storage: allowed('analytics') ? 'granted' : 'denied',
			functionality_storage: allowed('functional') ? 'granted' : 'denied',
			personalization_storage: allowed('functional') ? 'granted' : 'denied'
		});
	}

	function applyConsent() {
		try {
			consentModeUpdate();
			unblockScripts();
			unblockEmbeds();
		} catch (e) {
			if (window.console) { window.console.warn('aicc: unblocking failed', e); }
		}
		renderStatus();
		toggleFab();
		try {
			document.dispatchEvent(new CustomEvent('aicc:consent', { detail: state }));
		} catch (e) { /* older browsers */ }
	}

	function logConsent() {
		if (!CFG.log || !CFG.endpoint || !state) { return; }
		try {
			var payload = JSON.stringify({
				cid: state.cid,
				categories: state.cats,
				lang: state.lang,
				url: window.location.href
			});
			if (navigator.sendBeacon) {
				navigator.sendBeacon(CFG.endpoint, new Blob([payload], { type: 'application/json' }));
			} else {
				fetch(CFG.endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: payload,
					keepalive: true
				}).catch(function () { });
			}
		} catch (e) { /* logging is best effort */ }
	}

	/* ---------------- rendering ---------------- */

	function applyStrings() {
		var nodes = root.querySelectorAll('[data-aicc-i18n]');
		Array.prototype.forEach.call(nodes, function (node) {
			var key = node.getAttribute('data-aicc-i18n');
			// Don't tell people only necessary cookies run when that isn't true.
			if (key === 'banner_text' && impliedCats().length) { key = 'banner_text_implied'; }
			node.textContent = S(key);
		});
		Array.prototype.forEach.call(document.querySelectorAll('.aicc-embed [data-aicc-i18n]'), function (node) {
			node.textContent = S(node.getAttribute('data-aicc-i18n'));
		});
		Array.prototype.forEach.call(document.querySelectorAll('.aicc-embed [data-aicc-i18n-embed]'), function (node) {
			var box = node.closest('.aicc-embed');
			var slug = box.getAttribute('data-aicc-cat');
			var cat = CFG.categories.filter(function (c) { return c.slug === slug; })[0];
			var label = cat ? cat.i18n[lang].label : slug;
			node.textContent = S('embed_text')
				.replace('%1$s', box.getAttribute('data-aicc-service') || '')
				.replace('%2$s', label);
		});
	}

	function renderGroups() {
		groupsBox.textContent = '';
		CFG.categories.forEach(function (cat) {
			var pack = cat.i18n[lang] || cat.i18n.lt;
			var row = document.createElement('div');
			row.className = 'aicc-group';

			var head = document.createElement('div');
			head.className = 'aicc-group__head';

			var name = document.createElement('span');
			name.className = 'aicc-group__name';
			name.textContent = pack.label;
			head.appendChild(name);

			if (cat.optional) {
				var label = document.createElement('label');
				label.className = 'aicc-switch';
				var input = document.createElement('input');
				input.type = 'checkbox';
				input.checked = !!draft[cat.slug];
				input.setAttribute('aria-label', pack.label);
				input.addEventListener('change', function () { draft[cat.slug] = input.checked; });
				var knob = document.createElement('span');
				label.appendChild(input);
				label.appendChild(knob);
				head.appendChild(label);
			} else {
				var always = document.createElement('span');
				always.className = 'aicc-group__always';
				always.textContent = S('always_on');
				head.appendChild(always);
			}

			var desc = document.createElement('p');
			desc.className = 'aicc-group__desc';
			desc.textContent = pack.description;

			row.appendChild(head);
			row.appendChild(desc);
			groupsBox.appendChild(row);
		});
	}

	function renderLangs() {
		if (!CFG.switcher || !langsBox) { return; }
		langsBox.hidden = false;
		langsBox.textContent = '';
		Object.keys(CFG.langs).forEach(function (code) {
			var button = document.createElement('button');
			button.type = 'button';
			button.textContent = code.toUpperCase();
			button.title = CFG.langs[code];
			button.setAttribute('aria-pressed', code === lang ? 'true' : 'false');
			button.addEventListener('click', function () {
				lang = code;
				write(LANG_KEY, code);
				applyStrings();
				renderGroups();
				renderLangs();
				renderStatus();
			});
			langsBox.appendChild(button);
		});
	}

	function renderStatus() {
		var nodes = document.querySelectorAll('[data-aicc-status]');
		if (!nodes.length) { return; }
		var text;
		if (!state) {
			text = S('status_none');
		} else {
			var names = optionalCats()
				.filter(function (c) { return state.cats[c.slug]; })
				.map(function (c) { return (c.i18n[lang] || c.i18n.lt).label; });
			var when = new Date(state.ts).toLocaleDateString();
			text = S('status_saved').replace('%s', when) + ' ' + S('status_allowed') + ' ' +
				(names.length ? names.join(', ') : S('status_only_nec'));
		}
		Array.prototype.forEach.call(nodes, function (node) { node.textContent = text; });
	}

	function toggleFab() {
		if (!fab) { return; }

		var bannerGone = !banner || banner.hidden;
		var show = false;

		if (CFG.floating === 'always') {
			show = bannerGone;
		} else if (CFG.floating === 'pending') {
			show = bannerGone && !state;   // only while the question is still open
		}

		fab.hidden = !show;
		fab.style.display = show ? '' : 'none';
	}

	/* ---------------- panel controls ---------------- */

	function stopTimer() {
		if (hideTimer) { window.clearTimeout(hideTimer); hideTimer = null; }
	}

	function startTimer() {
		stopTimer();
		if (!CFG.autoHide || CFG.autoHide < 1000) { return; }
		hideTimer = window.setTimeout(function () {
			if (state || !banner || banner.hidden) { return; }
			banner.hidden = true;      // stepping aside is NOT consent —
			markDismissed();           // nothing optional is unblocked here.
			toggleFab();
		}, CFG.autoHide);
	}

	function showBanner() {
		if (!banner) { return; }
		banner.style.display = '';
		banner.hidden = false;
		toggleFab();
		startTimer();
	}

	function hideBanner() {
		stopTimer();
		if (banner) {
			banner.hidden = true;
			banner.style.display = 'none';
		}
		toggleFab();
	}

	function openModal() {
		stopTimer();
		draft = {};
		var pre = CFG.precheck || [];
		optionalCats().forEach(function (c) {
			draft[c.slug] = state
				? allowed(c.slug)
				: (pre.indexOf(c.slug) !== -1 || impliedCats().indexOf(c.slug) !== -1);
		});
		renderGroups();
		lastFocus = document.activeElement;
		modal.style.display = '';
		modal.hidden = false;
		var first = modal.querySelector('.aicc-btn, .aicc-close');
		if (first) { first.focus(); }
	}

	function closeModal() {
		modal.hidden = true;
		modal.style.display = 'none';
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}

	function decide(cats) {
		hideBanner();      // dismiss first: the visitor answered, so the UI owes them
		closeModal();      // an immediate response even if a tracker misbehaves below.
		saveState(cats);
	}

	function target(event) {
		return event.target && event.target.closest ? event.target : null;
	}

	root.addEventListener('click', function (event) {
		var node = target(event);
		var trigger = node && node.closest('[data-aicc-action]');
		if (!trigger) { return; }
		event.preventDefault();

		switch (trigger.getAttribute('data-aicc-action')) {
			case 'accept': decide(allOn()); break;
			case 'reject': decide(allOff()); break;
			case 'save': decide(Object.assign({}, draft)); break;
			case 'open': openModal(); break;
			case 'close': closeModal(); break;
		}
	});

	document.addEventListener('click', function (event) {
		var node = target(event);
		var trigger = node && node.closest('[data-aicc-action="open"], [data-aicc-action="reject"], a[href="#aicc-settings"]');
		if (!trigger || root.contains(trigger)) { return; }
		event.preventDefault();
		if (trigger.getAttribute('data-aicc-action') === 'reject') { decide(allOff()); return; }
		openModal();
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && modal && !modal.hidden) { closeModal(); }
	});

	if (banner) {
		['pointerenter', 'focusin'].forEach(function (name) {
			banner.addEventListener(name, stopTimer);
		});
		banner.addEventListener('pointerleave', function () {
			if (!state && !banner.hidden) { startTimer(); }
		});
	}

	/* blocked-embed buttons */
	document.addEventListener('click', function (event) {
		var node = target(event);
		if (!node) { return; }
		var once = node.closest('[data-aicc-embed-once]');
		var allow = node.closest('[data-aicc-embed-allow]');
		if (!once && !allow) { return; }
		event.preventDefault();

		var box = (once || allow).closest('.aicc-embed');
		if (!box) { return; }

		if (allow) {
			var cats = Object.assign({}, (state && state.cats) || allOff());
			cats[box.getAttribute('data-aicc-cat')] = true;
			saveState(cats);
			hideBanner();
		} else {
			restoreEmbed(box);
		}
	});

	/* ---------------- boot ---------------- */

	var savedLang = read(LANG_KEY);
	if (savedLang && CFG.langs[savedLang]) { lang = savedLang; }

	state = loadState();
	root.hidden = false;

	applyStrings();
	renderLangs();
	renderStatus();

	applyConsent();   // runs either way: the implied set is live before any choice

	if (state) {
		/* already applied above */
	} else if (dismissedThisSession()) {
		hideBanner();              // still unanswered — only the quiet button remains
	} else {
		showBanner();
	}

	window.aiccOpenSettings = openModal;
	window.aiccWithdrawConsent = function () {
		remove(KEY);
		state = null;
		renderStatus();
		showBanner();
	};
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot, { once: true });
	} else {
		boot();
	}
}());
