<?php
// RTFJS returns built DOM nodes rather than raw HTML, so this format stays in
// the page rather than moving into the sandboxed frame; bookScrub() is the
// belt-and-braces pass over what it produced.
//
// The scroll position script comes from position.php, included by index.php.
echo <<< __HTML
<script src="$webroot/js/WMFJS.bundle.js"></script>
<script src="$webroot/js/EMFJS.bundle.js"></script>
<script src="$webroot/js/RTFJS.bundle.js"></script>
<script src="$webroot/js/booksanitize.js"></script>
<script>
__HTML
?>
fetch(url).then(res => res.arrayBuffer()).then(arrayBuffer => {
	RTFJS.loggingEnabled(false);
	WMFJS.loggingEnabled(false);
	EMFJS.loggingEnabled(false);
	const doc = new RTFJS.Document(arrayBuffer);
	doc.render().then(html => {
		var viewer = document.getElementById('reader');
		viewer.append(...html);
		bookScrub(viewer);
		bookRestorePosition();
	});
});
</script>
