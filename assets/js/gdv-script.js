(function () {
	'use strict';

	function trackClick(link) {
		if (!window.gdvData || !gdvData.ajaxUrl || !gdvData.nonce) {
			return;
		}

		var body = buildPayload({
			action: 'gdv_track_click',
			nonce: gdvData.nonce,
			file_id: link.getAttribute('data-file-id') || '',
			file_name: link.getAttribute('data-file-name') || '',
			folder_id: link.getAttribute('data-folder-id') || ''
		});

		if (navigator.sendBeacon) {
			var beacon = new Blob([body], {
				type: 'application/x-www-form-urlencoded; charset=UTF-8'
			});

			if (navigator.sendBeacon(gdvData.ajaxUrl, beacon)) {
				return;
			}
		}

		if (!window.fetch) {
			sendWithXhr(body);
			return;
		}

		window.fetch(gdvData.ajaxUrl, {
			method: 'POST',
			body: body,
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			credentials: 'same-origin',
			keepalive: true
		}).catch(function () {
			sendWithXhr(body);
		});
	}

	function buildPayload(fields) {
		var pairs = [];
		var key;

		for (key in fields) {
			if (Object.prototype.hasOwnProperty.call(fields, key)) {
				pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(fields[key]));
			}
		}

		return pairs.join('&');
	}

	function findPolicyLink(target) {
		while (target && target !== document) {
			if (target.className && (' ' + target.className + ' ').indexOf(' gdv-policy-link ') !== -1) {
				return target;
			}

			target = target.parentNode;
		}

		return null;
	}

	function sendWithXhr(body) {
		var request = new XMLHttpRequest();

		request.open('POST', gdvData.ajaxUrl, true);
		request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		request.send(body);
	}

	function handleTrackableEvent(event) {
		var link = findPolicyLink(event.target);

		if (!link) {
			return;
		}

		trackClick(link);
	}

	document.addEventListener('click', handleTrackableEvent);

	document.addEventListener('auxclick', function (event) {
		if (event.button === 1) {
			handleTrackableEvent(event);
		}
	});

	document.addEventListener('contextmenu', function (event) {
		handleTrackableEvent(event);
	});
}());
