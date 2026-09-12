<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$bookid = (int)$url->var1;
$posKind = 'pos';
include(ROOT_PATH . 'modules/book/position_js.php');
?>
<script>
window.addEventListener('scroll', function() {
	flibPosition.save(100 / document.body.scrollHeight * window.scrollY);
}, false);
fetch(url).then(res => res.arrayBuffer()).then(arrayBuffer => {
	var td = new TextDecoder("windows-1251");
	var htm = td.decode(arrayBuffer);
	document.getElementById("reader").insertAdjacentHTML('beforeend', htm);
	flibPosition.load(function (p) {
		if (p) window.scrollTo(0, document.body.scrollHeight / 100 * parseFloat(p));
	});
});
</script>
