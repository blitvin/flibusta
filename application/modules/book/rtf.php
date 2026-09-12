<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$bookid = (int)$url->var1;
$posKind = 'pos';
include(ROOT_PATH . 'modules/book/position_js.php');
echo <<< __HTML
<script src="$webroot/js/WMFJS.bundle.js"></script>
<script src="$webroot/js/EMFJS.bundle.js"></script>
<script src="$webroot/js/RTFJS.bundle.js"></script>
<script>
__HTML
?>
window.addEventListener('scroll', function() {
	flibPosition.save(100 / document.body.scrollHeight * window.scrollY);
}, false);
fetch(url).then(res => res.arrayBuffer()).then(arrayBuffer => {
	RTFJS.loggingEnabled(false);
	WMFJS.loggingEnabled(false);
	EMFJS.loggingEnabled(false);
	const doc = new RTFJS.Document(arrayBuffer);
	doc.render().then(html => {
		viewer = document.getElementById('reader');
		viewer.append(...html);
		flibPosition.load(function (p) {
			if (p) window.scrollTo(0, document.body.scrollHeight / 100 * parseFloat(p));
		});
	});
});
</script>
