<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$savedCfi = '';
$saveEpubUrl = '';
if ($current_user_id > 0) {
	$saveEpubUrl = $webroot . '/save_epub_position.php';
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
var saveCfiTimeout;

r.on("locationChanged", function(location) {
	if (!location || !location.start) return;
	var cfi = location.start.cfi || location.start;
	clearTimeout(saveCfiTimeout);
	saveCfiTimeout = setTimeout(function() {
		var xhr = new XMLHttpRequest();
		xhr.open("POST", saveEpubUrl, true);
		xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
		xhr.send("bookid=" + epubBookId + "&cfi=" + encodeURIComponent(cfi));
	}, 500);
});
<?php endif; ?>

var displayed = r.display(<?= $savedCfi ? json_encode($savedCfi) : 'undefined' ?>);
</script>
