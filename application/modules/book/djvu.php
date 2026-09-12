<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$savedPage = 1;
$saveDjvuUrlPrefix = '';
if ($current_user_id > 0) {
	$saveDjvuUrl = $webroot . '/save_djvu_position.php';
	$saveBookId  = (int)$url->var1;
	$saveCsrf    = get_csrf_token();
	$stmt = $dbh->prepare("SELECT page FROM djvu_progress WHERE user_id=:uid AND bookid=:id LIMIT 1");
	$stmt->bindParam(":uid", $current_user_id);
	$stmt->bindParam(":id", $url->var1);
	$stmt->execute();
	if ($dp = $stmt->fetch()) {
		$savedPage = (int)($dp->page ?? 1);
	}
}
include_once(ROOT_PATH . "webroot.php");
echo "<script src='$webroot/js/djvu.js'></script>\n";
echo "<script src='$webroot/js/djvu_viewer.js'></script>\n";
?>
<script>
window.ViewerInstance = new DjVu.Viewer();
window.ViewerInstance.render(document.querySelector("#reader"));
window.ViewerInstance.configure({
	viewMode: 'single',
	language: 'ru'
});

<?php if ($current_user_id > 0): ?>
var saveDjvuUrl = <?= json_encode($saveDjvuUrl, JSON_UNESCAPED_SLASHES) ?>;
var saveBookId = <?= (int)$saveBookId ?>;
var saveCsrf = <?= json_encode($saveCsrf) ?>;
var saveDjvuTimeout;

window.ViewerInstance.on(DjVu.Viewer.Events.PAGE_NUMBER_CHANGED, function() {
	var page = window.ViewerInstance.getPageNumber();
	clearTimeout(saveDjvuTimeout);
	saveDjvuTimeout = setTimeout(function() {
		var x = new XMLHttpRequest();
		x.open("POST", saveDjvuUrl, true);
		x.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
		x.send("bookid=" + encodeURIComponent(saveBookId) + "&page=" + encodeURIComponent(page) + "&csrf_token=" + encodeURIComponent(saveCsrf));
	}, 300);
});
<?php endif; ?>

window.ViewerInstance.loadDocumentByUrl(url).then(function() {
<?php if ($current_user_id > 0 && $savedPage > 1): ?>
	window.ViewerInstance.setPageNumber(<?= $savedPage ?>);
<?php endif; ?>
});
</script>
