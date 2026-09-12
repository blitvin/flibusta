<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$savedPos = 0;
$savePositionUrlPrefix = '';
if ($current_user_id > 0) {
	$savePositionUrl = $webroot . '/save_position.php';
	$saveBookId      = (int)$url->var1;
	$saveCsrf        = get_csrf_token();
	$stmt = $dbh->prepare("SELECT pos FROM progress WHERE user_id=:uid AND bookid=:id LIMIT 1");
	$stmt->bindParam(":uid", $current_user_id);
	$stmt->bindParam(":id", $url->var1);
	$stmt->execute();
	if ($p = $stmt->fetch()) {
		$savedPos = (float)($p->pos ?? 0);
	}
}
?>
<script>
<?php if ($current_user_id > 0): ?>
var isScrolling;
var savePositionUrl = <?= json_encode($savePositionUrl, JSON_UNESCAPED_SLASHES) ?>;
var saveBookId = <?= (int)$saveBookId ?>;
var saveCsrf = <?= json_encode($saveCsrf) ?>;
window.addEventListener('scroll', function() {
	window.clearTimeout(isScrolling);
	isScrolling = setTimeout(function() {
		var pos = 100 / document.body.scrollHeight * window.scrollY;
		var x = new XMLHttpRequest();
		x.open("POST", savePositionUrl, true);
		x.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
		x.send("bookid=" + encodeURIComponent(saveBookId) + "&pos=" + encodeURIComponent(pos) + "&csrf_token=" + encodeURIComponent(saveCsrf));
	}, 66);
}, false);
<?php endif; ?>
fetch(url).then(res => res.arrayBuffer()).then(arrayBuffer => {
	var td = new TextDecoder("windows-1251");
	var htm = td.decode(arrayBuffer);
	document.getElementById("reader").insertAdjacentHTML('beforeend', htm);
<?php if ($current_user_id > 0 && $savedPos > 0): ?>
	window.scrollTo(0, document.body.scrollHeight / 100 * <?= $savedPos ?>);
<?php endif; ?>
});
</script>
