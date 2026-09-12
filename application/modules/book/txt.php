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
document.addEventListener("DOMContentLoaded", function() {
	flibPosition.load(function (p) {
		if (p) window.scrollTo(0, document.body.scrollHeight / 100 * parseFloat(p));
	});
});
</script>
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
