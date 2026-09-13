/**
 * Sizes the sandboxed book iframe to its content.
 *
 * The frame holds the book's own untrusted markup (see modules/book/content.php).
 * It is same-origin — so this script can measure it — but carries no
 * allow-scripts, so nothing inside it can run. The frame is grown to its full
 * content height and never scrolls itself; the page scrolls, which keeps the
 * existing percent-based position saving in modules/book/position.php working
 * unchanged.
 */
(function () {
	var frame = document.getElementById('bookframe');
	if (!frame) {
		return;
	}
	var restored = false;

	function fit() {
		var doc;
		try {
			doc = frame.contentDocument;
		} catch (e) {
			return; // never happens same-origin, but do not break the page if it does
		}
		if (!doc || !doc.documentElement) {
			return;
		}
		var height = Math.max(
			doc.documentElement.scrollHeight,
			doc.body ? doc.body.scrollHeight : 0
		);
		if (height > 0) {
			frame.style.height = height + 'px';
		}
	}

	function onLoad() {
		fit();

		var doc = frame.contentDocument;
		if (doc) {
			// Images decode after load and change the height; so does a reflow
			// from a window resize.
			var images = doc.images || [];
			for (var i = 0; i < images.length; i++) {
				if (!images[i].complete) {
					images[i].addEventListener('load', fit);
					images[i].addEventListener('error', fit);
				}
			}
			if (doc.body && window.ResizeObserver) {
				new ResizeObserver(fit).observe(doc.body);
			}
		}
		window.addEventListener('resize', fit);

		// Only once the frame has its height can a percentage be resolved to a
		// scroll offset — the old DOMContentLoaded restore ran too early.
		if (!restored) {
			restored = true;
			if (typeof bookRestorePosition === 'function') {
				bookRestorePosition();
			}
		}
	}

	frame.addEventListener('load', onLoad);

	// A frame whose content is set synchronously (srcdoc) can already be done by
	// the time this runs, in which case no further load event is coming.
	var current = frame.contentDocument;
	if (current && current.readyState === 'complete' && current.body
			&& current.body.childNodes.length > 0) {
		onLoad();
	}
})();
