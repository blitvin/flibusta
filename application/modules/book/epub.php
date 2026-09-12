<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$bookid = (int)$url->var1;
$posKind = 'epub';
include(ROOT_PATH . 'modules/book/position_js.php');
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

r.on("locationChanged", function(location) {
	if (!location || !location.start) return;
	flibPosition.save(location.start.cfi || location.start, 500);
});

// Render from the saved CFI when there is one, otherwise from the beginning.
// The position is fetched rather than embedded so this page stays identical for
// every reader and can be served from cache.
var displayed;
flibPosition.load(function (cfi) {
	displayed = cfi ? r.display(cfi) : r.display();
});
</script>
