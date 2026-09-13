/**
 * Defence-in-depth scrub for the book formats rendered into the library page
 * itself rather than into the sandboxed frame.
 *
 * docx-preview and RTFJS build their DOM from parsed binary structures instead
 * of passing raw HTML through, so neither is a known injection path - but the
 * source file is untrusted, so strip the two things that would execute if one
 * of them ever let markup through: inline event handlers and script-bearing
 * URLs.
 */
function bookUnsafeUrl(value) {
	// Drop spaces and control characters before testing the scheme, so that a
	// URL written as "java<TAB>script:..." is still recognised. Done with a
	// char-code loop rather than a regex to keep literal control characters out
	// of this source file.
	var raw = String(value);
	var url = '';
	for (var i = 0; i < raw.length; i++) {
		if (raw.charCodeAt(i) > 32) {
			url += raw.charAt(i);
		}
	}
	if (/^data:/i.test(url)) {
		return !/^data:image\//i.test(url); // inline images are legitimate here
	}
	return /^(javascript|vbscript):/i.test(url);
}

function bookScrub(root) {
	if (!root) {
		return;
	}
	var nodes = root.querySelectorAll('*');
	for (var i = nodes.length - 1; i >= 0; i--) {
		var el = nodes[i];
		var tag = el.tagName ? el.tagName.toUpperCase() : '';
		if (tag === 'SCRIPT' || tag === 'IFRAME' || tag === 'OBJECT' || tag === 'EMBED') {
			if (el.parentNode) {
				el.parentNode.removeChild(el);
			}
			continue;
		}
		var attrs = el.attributes;
		for (var j = attrs.length - 1; j >= 0; j--) {
			var name = attrs[j].name;
			var value = attrs[j].value;
			if (/^on/i.test(name)) {
				el.removeAttribute(name);
			} else if (/^(href|src|xlink:href|formaction|action)$/i.test(name)
					&& value && bookUnsafeUrl(value)) {
				el.removeAttribute(name);
			}
		}
	}
}
