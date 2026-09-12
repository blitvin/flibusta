<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$bookid = (int)$url->var1;
$posKind = 'pos';
include_once(ROOT_PATH . "webroot.php");
include(ROOT_PATH . 'modules/book/position_js.php');
echo "<script src='$webroot/js/jszip.min.js'></script>\n";
echo "<script src='$webroot/js/docx-preview.min.js'></script>\n";
?>
<script>
window.addEventListener('scroll', function() {
	flibPosition.save(100 / document.body.scrollHeight * window.scrollY);
}, false);
fetch(url).then(res => res.arrayBuffer()).then(arrayBuffer => {
	docx.renderAsync(arrayBuffer, document.getElementById("reader")).then(function() {
		flibPosition.load(function (p) {
			if (p) window.scrollTo(0, document.body.scrollHeight / 100 * parseFloat(p));
		});
	});
});
</script>
