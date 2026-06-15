<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$savedPos = 0;
$savePositionUrlPrefix = '';
if ($current_user_id > 0) {
	$savePositionUrlPrefix = $webroot . '/save_position.php?' .
		http_build_query(['bookid' => (int)$url->var1]) . '&pos=';
	$stmt = $dbh->prepare("SELECT pos FROM progress WHERE user_id=:uid AND bookid=:id LIMIT 1");
	$stmt->bindParam(":uid", $current_user_id);
	$stmt->bindParam(":id", $url->var1);
	$stmt->execute();
	if ($p = $stmt->fetch()) {
		$savedPos = (float)($p->pos ?? 0);
	}
}
?>
<?php if ($current_user_id > 0): ?>
<script>
var isScrolling;
var savePositionUrlPrefix = <?= json_encode($savePositionUrlPrefix, JSON_UNESCAPED_SLASHES) ?>;
window.addEventListener('scroll', function() {
	window.clearTimeout(isScrolling);
	isScrolling = setTimeout(function() {
		var x = new XMLHttpRequest();
		x.open("GET", savePositionUrlPrefix + (100 / document.body.scrollHeight * window.scrollY), true);
		x.send(null);
	}, 66);
}, false);
<?php if ($savedPos > 0): ?>
document.addEventListener("DOMContentLoaded", function() {
	window.scrollTo(0, document.body.scrollHeight / 100 * <?= $savedPos ?>);
});
<?php endif; ?>
</script>
<?php endif; ?>
<?php
$localTxt = LOCAL_LIBRARY_PATH . intval($url->var1) . '.txt';
if (file_exists($localTxt)) {
	$content = file_get_contents($localTxt);
} else {
	$content = $zip->getFromName("$url->var1.txt");
}
if (!mb_detect_encoding($content, 'UTF-8', true)) {
	$content = iconv('windows-1251//IGNORE', 'UTF-8//IGNORE', $content);
}
$content = nl2p($content);
echo "<section>";
echo str_replace("<p>***</p>", '<div class="divider div-transparent div-dot"></div>', str_replace("section>>", "section>", $content));
echo "</section>";
