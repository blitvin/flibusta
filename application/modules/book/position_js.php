<?php
// Client-side reading-position access, shared by every reader (fb2, txt, rtf,
// docx, html, mobi, pdf, epub, djvu).
//
//   flibPosition.load(function (value) { ... })  // value is null when unset
//   flibPosition.save(value)
//
// Nothing here depends on who is asking: the markup is identical for anonymous
// and logged-in visitors, which is what lets one cached copy of a book page
// serve everybody. Both the saved position and the CSRF token needed to save a
// new one come from user_state.php, which is never cached.
//
// load() always calls its callback exactly once - with null when there is no
// saved position, when nobody is logged in, or if the request fails - so a
// reader can drive its initial render from it unconditionally. save() is a no-op
// until the state has confirmed somebody is logged in.
//
// Expects $webroot and $bookid to be set by the including file. $kind is 'pos'
// (scroll offset or page number), 'epub' (CFI) or 'djvu' (page).
$posKind    = $posKind ?? 'pos';
$posSaveUrl = $webroot . ($posKind === 'epub' ? '/save_epub_position.php'
                        : ($posKind === 'djvu' ? '/save_djvu_position.php' : '/save_position.php'));
$posValueName = $posKind === 'epub' ? 'cfi' : ($posKind === 'djvu' ? 'page' : 'pos');
?>
<script>
// Tells fav.js to fold this book's position into the single state request.
window.FLIBUSTA_STATE_PARAMS = "?bookid=<?= (int)$bookid ?>&kind=<?= rawurlencode($posKind) ?>";

window.flibPosition = (function () {
	var bookId = <?= (int)$bookid ?>;
	var saveUrl = <?= json_encode($posSaveUrl, JSON_UNESCAPED_SLASHES) ?>;
	var valueName = <?= json_encode($posValueName) ?>;
	var state = null;      // filled from user_state.php
	var saveTimer = null;

	function load() {
		return window.flibUserState.load(window.FLIBUSTA_STATE_PARAMS);
	}

	return {
		bookId: bookId,
		// Hands the stored position to cb exactly once; null when there is none.
		load: function (cb) {
			load().then(function (s) {
				state = s;
				cb(s && s.logged_in && s.pos !== null && s.pos !== undefined && s.pos !== '' ? s.pos : null);
			});
		},
		// Debounced so a burst of scroll events results in one request. Silently
		// does nothing for anonymous visitors.
		save: function (value, delay) {
			clearTimeout(saveTimer);
			saveTimer = setTimeout(function () {
				load().then(function (s) {
					if (!s || !s.logged_in) return;
					var x = new XMLHttpRequest();
					x.open("POST", saveUrl, true);
					x.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
					x.send("bookid=" + encodeURIComponent(bookId)
						+ "&" + valueName + "=" + encodeURIComponent(value)
						+ "&csrf_token=" + encodeURIComponent(s.csrf));
				});
			}, delay || 66);
		}
	};
})();
</script>
