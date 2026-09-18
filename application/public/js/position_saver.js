/**
 * Reading-position saver shared by every renderer that stores a position
 * (modules/book/position.php, pdf.php, epub.php, djvu.php).
 *
 * Each of those used to POST straight from its own event handler, the scroll one
 * with a 66 ms debounce: continuous scrolling produced roughly fifteen requests a
 * second, and every one of them is a full PHP bootstrap (DB connect, session read,
 * upsert, session write) against a pool of ten workers.
 *
 * So: one POST per second at most, nothing sent when the value has not changed
 * since the last successful send, and the pending value flushed with sendBeacon
 * when the page is hidden or unloaded - a reader who closes the tab mid-debounce
 * still keeps their place.
 *
 * sendBeacon posts a URLSearchParams body as
 * application/x-www-form-urlencoded, which PHP parses into $_POST, so the CSRF
 * check in the save_*_position.php endpoints applies unchanged. The request is
 * same-origin, so the SameSite=Lax session cookie is sent with it.
 */
function makePositionSaver(url, bookId, csrf, field, delayMs) {
	var lastSent = null;   // last value handed to the server
	var pending = null;    // latest value seen, may not be sent yet
	var timer = null;

	function body(value) {
		var params = new URLSearchParams();
		params.set('bookid', bookId);
		params.set('csrf_token', csrf);
		params.set(field, value);
		return params;
	}

	function send(value, useBeacon) {
		if (value === null || value === lastSent) {
			return;
		}
		lastSent = value;
		var params = body(value);
		if (useBeacon && navigator.sendBeacon) {
			navigator.sendBeacon(url, params);
			return;
		}
		var x = new XMLHttpRequest();
		x.open('POST', url, true);
		x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		x.send(params.toString());
	}

	function flush() {
		if (timer !== null) {
			clearTimeout(timer);
			timer = null;
		}
		send(pending, true);
	}

	// pagehide covers navigation and, unlike unload, does not break the back /
	// forward cache. visibilitychange covers a tab switch or a phone being locked,
	// which on mobile is often the only event that fires before the page is frozen.
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') {
			flush();
		}
	});
	window.addEventListener('pagehide', flush);

	return {
		schedule: function (value) {
			pending = value;
			if (value === lastSent) {
				return;   // nothing to do: the server already has this value
			}
			if (timer !== null) {
				clearTimeout(timer);
			}
			timer = setTimeout(function () {
				timer = null;
				send(pending, false);
			}, delayMs || 1000);
		},
		flush: flush
	};
}
