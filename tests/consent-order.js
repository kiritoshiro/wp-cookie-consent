/*
 * Runs assets/js/banner.js against a tiny fake DOM for a returning visitor who
 * allowed analytics, and checks what reaches window.dataLayer, in order.
 *   rocket-loader: the <head> Consent Mode default has not run yet when
 *                  banner.js starts (Rocket Loader delays it) and runs after.
 *   native:        the default runs first, as it does with data-cfasync="false".
 * In both, the "granted" update must be queued before GA4's config.
 * Run: node tests/consent-order.js [path/to/banner.js]
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const bannerPath = process.argv[2] || path.join(__dirname, '..', 'assets', 'js', 'banner.js');
const banner = fs.readFileSync(bannerPath, 'utf8');

const HEAD_DEFAULT = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n"
	+ "gtag('consent','default',{ad_storage:'denied',analytics_storage:'denied',wait_for_update:500});";
const GA4_CONFIG = "window.dataLayer = window.dataLayer || [];\n"
	+ "function gtag(){dataLayer.push(arguments);}\n"
	+ "gtag('js', new Date());\n"
	+ "gtag('config', 'G-TEST');";

const CONFIG = {
	lang: 'lt',
	langs: { lt: 'LT' },
	strings: { lt: {} },
	categories: [
		{ slug: 'necessary', optional: false },
		{ slug: 'analytics', optional: true },
		{ slug: 'marketing', optional: true }
	],
	revision: 1,
	days: 180,
	implied: [],
	consentMode: true,
	gpc: false,
	log: false
};

/** An element that accepts any call; only what banner.js reads back is real. */
function fake(attrs) {
	const target = {
		attributes: [],
		style: {},
		classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
		getAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs || {}, name) ? attrs[name] : null; },
		setAttribute() {},
		querySelector() { return fake(); },
		querySelectorAll() { return []; },
		closest() { return null; }
	};
	return new Proxy(target, {
		get(t, prop) {
			if (prop in t) { return t[prop]; }
			if (typeof prop === 'symbol') { return undefined; }
			return function () { return fake(); };
		}
	});
}

function run(defaultFirst) {
	const store = {
		aicc_consent: JSON.stringify({ cats: { analytics: true, marketing: false }, rev: 1, ts: Date.now(), lang: 'lt', cid: 'returning-visitor-1' })
	};
	const ctx = {
		console,
		JSON,
		Date,
		Math,
		Object,
		Array,
		localStorage: { getItem: (k) => (k in store ? store[k] : null), setItem() {}, removeItem() {} },
		sessionStorage: { getItem: () => null, setItem() {} },
		navigator: {},
		CustomEvent: function () {}
	};
	ctx.window = ctx;
	vm.createContext(ctx);

	const root = fake({ 'data-aicc-config': JSON.stringify(CONFIG) });
	const blocked = [
		{ type: 'text/plain', 'data-aicc-cat': 'analytics', 'data-aicc-src': 'https://www.googletagmanager.com/gtag/js?id=G-TEST' },
		{ type: 'text/plain', 'data-aicc-cat': 'analytics', text: GA4_CONFIG }
	].map((a) => {
		const node = fake(a);
		const t = node.attributes; // the fake's own array
		Object.keys(a).filter((k) => k !== 'text').forEach((k) => t.push({ name: k, value: a[k] }));
		return { node, text: a.text || '' };
	});

	ctx.document = {
		readyState: 'interactive', // a deferred script runs after parsing
		getElementById: (id) => (id === 'aicc-root' ? root : null),
		querySelectorAll(sel) {
			if (sel.indexOf('script[type="text/plain"]') === 0) {
				return blocked.map((b) => {
					const n = b.node;
					// textContent and parentNode.replaceChild: an inline script runs as it is inserted.
					return new Proxy(n, {
						get(t, prop) {
							if (prop === 'textContent') { return b.text; }
							if (prop === 'parentNode') {
								return { replaceChild(fresh) { if (fresh.text) { vm.runInContext(fresh.text, ctx); } } };
							}
							return t[prop];
						}
					});
				});
			}
			return [];
		},
		createElement: () => ({ setAttribute() {}, text: '', src: '', type: '' }),
		addEventListener() {}
	};

	if (defaultFirst) { vm.runInContext(HEAD_DEFAULT, ctx); }
	vm.runInContext(banner, ctx);
	if (!defaultFirst) { vm.runInContext(HEAD_DEFAULT, ctx); } // Rocket Loader runs it now

	return Array.from(ctx.dataLayer || [], (args) => Array.from(args));
}

let failures = 0;
function check(label, condition) {
	console.log((condition ? 'PASS ' : 'FAIL ') + label);
	failures += condition ? 0 : 1;
}

/** gtag.js keeps defaults and updates apart; an update outranks a default whenever it arrived. */
function analyticsBeforeConfig(queue) {
	const config = queue.findIndex((a) => a[0] === 'config');
	const update = queue.findIndex((a) => a[0] === 'consent' && a[1] === 'update');
	return { config, update, granted: update !== -1 && queue[update][2].analytics_storage === 'granted' };
}

for (const [name, defaultFirst] of [['rocket-loader', false], ['native', true]]) {
	const queue = run(defaultFirst);
	const r = analyticsBeforeConfig(queue);
	check(name + ': GA4 config released', r.config !== -1);
	check(name + ': consent update granted analytics', r.granted);
	check(name + ': update queued before GA4 config', r.update !== -1 && r.update < r.config);
	if (defaultFirst) {
		const def = queue.findIndex((a) => a[0] === 'consent' && a[1] === 'default');
		check(name + ': default queued first', def === 0);
	}
}

process.exit(failures === 0 ? 0 : 1);
