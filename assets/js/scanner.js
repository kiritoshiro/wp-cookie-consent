/* Adventistai – deep cookie scan (administrators only).
   Runs with blocking switched off so every script can set its cookies,
   then reports the names — never the values — back to the site. */
(function () {
	'use strict';

	var CFG = window.AICC_SCAN || {};
	if (!CFG.endpoint) { return; }

	var panel = document.createElement('div');
	panel.setAttribute('style', [
		'position:fixed', 'z-index:2147483647', 'right:16px', 'bottom:16px',
		'max-width:22rem', 'padding:14px 16px', 'background:#12140f', 'color:#f2f1ec',
		'font:13px/1.5 system-ui,sans-serif', 'border-radius:8px',
		'box-shadow:0 8px 30px rgba(0,0,0,.4)'
	].join(';'));
	panel.textContent = 'Gilus skenavimas: laukiama, kol užsikraus visi scenarijai…';
	document.body.appendChild(panel);

	function cookieNames() {
		return document.cookie
			.split(';')
			.map(function (part) { return part.split('=')[0].trim(); })
			.filter(function (name) { return name.length > 0; });
	}

	function storageKeys() {
		var keys = [];
		[window.localStorage, window.sessionStorage].forEach(function (store) {
			try {
				for (var i = 0; i < store.length; i++) { keys.push(store.key(i)); }
			} catch (e) { /* blocked */ }
		});
		return keys.filter(function (key) { return key && key.indexOf('wp-') !== 0; });
	}

	function keepScanning() {
		var here = new URL(window.location.href);
		Array.prototype.forEach.call(document.querySelectorAll('a[href]'), function (link) {
			var url;
			try { url = new URL(link.href, window.location.origin); } catch (e) { return; }
			if (url.origin !== here.origin || url.pathname === here.pathname) { return; }
			url.searchParams.set('aicc_deep_scan', '1');
			url.searchParams.set('_wpnonce', here.searchParams.get('_wpnonce') || '');
			link.href = url.toString();
		});
	}

	function report() {
		var cookies = cookieNames();
		var storage = storageKeys();

		fetch(CFG.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.nonce
			},
			body: JSON.stringify({ cookies: cookies, storage: storage })
		})
			.then(function (response) {
				if (!response.ok) { throw new Error('scan report failed'); }
				return response.json();
			})
			.then(function (data) {
				var found = (data && Array.isArray(data.new)) ? data.new.slice(0, 200) : [];
				panel.textContent = '';

				var title = document.createElement('strong');
				title.textContent = 'Skenavimas baigtas';
				panel.appendChild(title);

				var summary = document.createElement('div');
				summary.textContent = 'Rasta slapukų: ' + cookies.length + ', saugyklos raktų: ' + storage.length + '.';
				panel.appendChild(summary);

				var result = document.createElement('div');
				if (found.length) {
					result.appendChild(document.createTextNode('Nauji įrašai: '));
					found.forEach(function (name, index) {
						if (index > 0) { result.appendChild(document.createTextNode(', ')); }
						var code = document.createElement('code');
						code.textContent = String(name);
						result.appendChild(code);
					});
				} else {
					result.textContent = 'Naujų slapukų nerasta.';
				}
				panel.appendChild(result);

				var help = document.createElement('small');
				help.textContent = 'Naršykite svetainę toliau — kiekvienas puslapis bus nuskenuotas automatiškai.';
				panel.appendChild(help);
				keepScanning();
			})
			.catch(function () {
				panel.textContent = 'Nepavyko išsiųsti rezultatų. Perkraukite puslapį ir bandykite dar kartą.';
			});
	}

	window.addEventListener('load', function () {
		window.setTimeout(report, CFG.wait || 6000);
	});
}());
