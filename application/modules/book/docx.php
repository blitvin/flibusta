<?php
// docx-preview builds its DOM from the parsed document parts and needs a
// container attached to the page to measure, so this format stays in the page
// rather than moving into the sandboxed frame; bookScrub() is the belt-and-
// braces pass over what it produced.
//
// The scroll position script comes from position.php, included by index.php.
include_once(ROOT_PATH . "webroot.php");
echo "<script src='$webroot/js/jszip.min.js'></script>\n";
echo "<script src='$webroot/js/docx-preview.min.js'></script>\n";
echo "<script src='$webroot/js/booksanitize.js'></script>\n";
?>
<script>
fetch(url).then(res => res.arrayBuffer()).then(arrayBuffer => {
	var reader = document.getElementById("reader");
	docx.renderAsync(arrayBuffer, reader).then(function() {
		bookScrub(reader);
		bookRestorePosition();
	});
});
</script>
