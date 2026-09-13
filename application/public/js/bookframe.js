/**
 * Sizes the sandboxed book iframe to its content.
 *
 * The frame holds the book's own untrusted markup (see modules/book/content.php).
 * It is same-origin - so this script can measure it - but carries no
 * allow-scripts, so nothing inside it can run. The frame is grown to its full
 * content height and never scrolls itself; the page scrolls, which keeps the
 * existing percent-based position saving in modules/book/position.php working
 * unchanged.
 */
(function () {
	function setup(frame) {
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
			// scroll offset - the old DOMContentLoaded restore ran too early.
			if (!restored) {
				restored = true;
				if (typeof bookRestorePosition === 'function') {
					bookRestorePosition();
				}
			}
		}

		frame.addEventListener('load', onLoad);

		// A frame whose content is already in place by the time this runs - set
		// synchronously via srcdoc, or served from cache - has no further load
		// event coming. An untouched frame is about:blank with an empty body,
		// which the childNodes check rules out.
		var current = frame.contentDocument;
		if (current && current.readyState === 'complete' && current.body
				&& current.body.childNodes.length > 0) {
			onLoad();
		}
	}

	// This is a classic script, so it runs the moment it is parsed. Included
	// above the iframe it would find nothing and silently leave the frame at its
	// CSS min-height, so fall back to waiting for the document to be parsed.
	var frame = document.getElementById('bookframe');
	if (frame) {
		setup(frame);
	} else if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			var late = document.getElementById('bookframe');
			if (late) {
				setup(late);
			}
		});
	}
})();
