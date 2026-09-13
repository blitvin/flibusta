<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$savedCfi = '';
$saveEpubUrl = '';
if ($current_user_id > 0) {
	$saveEpubUrl = $webroot . '/save_epub_position.php';
	$saveCsrf    = get_csrf_token();
	$stmt = $dbh->prepare("SELECT cfi FROM epub_progress WHERE user_id=:uid AND bookid=:id LIMIT 1");
	$stmt->bindParam(":uid", $current_user_id);
	$stmt->bindParam(":id", $url->var1);
	$stmt->execute();
	if ($ep = $stmt->fetch()) {
		$savedCfi = $ep->cfi;
	}
}
echo "<script src='$webroot/js/jszip.min.js'></script>";
echo "<script src='$webroot/js/epub.min.js'></script>";
?>
<script>
const book = ePub({ replacements: 'blobUrl' });

// Books come from an untrusted source (Flibusta dumps), so epub.js renders every
// chapter into an iframe sandboxed with "allow-same-origin" only — Chrome therefore
// refuses to run any <script> the book carries and logs "Blocked script execution in
// 'about:srcdoc'". Drop those scripts while the chapter is still a DOM document, before
// it is serialized into the iframe: the rendered text is unchanged, the console stays
// clean, and the sandbox is not weakened (do NOT pass allowScriptedContent: true here —
// allow-scripts together with allow-same-origin lets book content escape the sandbox).
book.spine.hooks.content.register(function (doc) {
	if (!doc || !doc.querySelectorAll) return;
	var scripts = doc.querySelectorAll('script');
	for (var i = scripts.length - 1; i >= 0; i--) {
		if (scripts[i].parentNode) scripts[i].parentNode.removeChild(scripts[i]);
	}
});

book.open(url, 'epub');
var r = book.renderTo(document.body, {
	flow: "scrolled-doc",
	manager: "continuous",
	width: "69%"
});
r.themes.default({
	p: {
		'font-size': '1.2rem;',
		'font-weight': '400;',
		'line-height': '1.7;',
		'color': '#333;',
		'font-family': 'sans-serif;',
		'margin-bottom': '15px;'
	}
});

<?php if ($current_user_id > 0): ?>
var saveEpubUrl = <?= json_encode($saveEpubUrl, JSON_UNESCAPED_SLASHES) ?>;
var epubBookId = <?= (int)$url->var1 ?>;
var epubCsrf = <?= json_encode($saveCsrf) ?>;
var saveCfiTimeout;

r.on("locationChanged", function(location) {
	if (!location || !location.start) return;
	var cfi = location.start.cfi || location.start;
	clearTimeout(saveCfiTimeout);
	saveCfiTimeout = setTimeout(function() {
		var xhr = new XMLHttpRequest();
		xhr.open("POST", saveEpubUrl, true);
		xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
		xhr.send("bookid=" + encodeURIComponent(epubBookId) + "&cfi=" + encodeURIComponent(cfi) + "&csrf_token=" + encodeURIComponent(epubCsrf));
	}, 500);
});
<?php endif; ?>

var displayed = r.display(<?= $savedCfi ? json_encode($savedCfi) : 'undefined' ?>);
</script>
