<?php
// mobi.min.js turns the book into HTML and its render_to() injects that straight
// into a live element. The markup is the book's own, i.e. untrusted, so it goes
// into the sandboxed frame instead: the two halves render_to() performs —
// read_text() for the markup and render_image() for the embedded images — are
// driven here against the frame's document.
//
// The scroll position script comes from position.php, included by index.php.
echo "<script src='$webroot/js/mobi.min.js'></script>\n";
?>
<iframe id="bookframe" class="bookframe"
	sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
	referrerpolicy="no-referrer"></iframe>
<?php echo "<script src='$webroot/js/bookframe.js'></script>\n"; ?>
<script>
var bookFrameCss = <?= json_encode($webroot . '/css/style.css', JSON_UNESCAPED_SLASHES) ?>;

fetch(url).then(res => res.arrayBuffer()).then(arrayBuffer => {
	var mobi = new MobiFile(arrayBuffer);
	mobi.load();

	var frame = document.getElementById('bookframe');
	// Images are palm records, not URLs, so they can only be filled in after the
	// frame has parsed the markup.
	frame.addEventListener('load', function () {
		var doc = frame.contentDocument;
		if (!doc) {
			return;
		}
		var images = doc.getElementsByTagName('img');
		for (var i = 0; i < images.length; i++) {
			mobi.render_image(images, i);
		}
	});

	frame.srcdoc = '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
		+ '<meta name="viewport" content="width=device-width, initial-scale=1">'
		+ '<base target="_blank">'
		+ '<link rel="stylesheet" href="' + bookFrameCss + '">'
		+ '<style>body{margin:0;padding:0 .3rem;background:#fff}'
		+ 'img{max-width:100%;height:auto}</style>'
		+ '</head><body class="reader">' + mobi.read_text() + '</body></html>';
});
</script>
