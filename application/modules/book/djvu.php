<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$bookid = (int)$url->var1;
$posKind = 'djvu';
include_once(ROOT_PATH . "webroot.php");
include(ROOT_PATH . 'modules/book/position_js.php');
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

window.ViewerInstance.on(DjVu.Viewer.Events.PAGE_NUMBER_CHANGED, function() {
	flibPosition.save(window.ViewerInstance.getPageNumber(), 300);
});

window.ViewerInstance.loadDocumentByUrl(url).then(function() {
	flibPosition.load(function (p) {
		var page = parseInt(p, 10);
		if (page > 1) window.ViewerInstance.setPageNumber(page);
	});
});
</script>
