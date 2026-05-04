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
echo <<< __HTML
<script src="$webroot/js/WMFJS.bundle.js"></script>
<script src="$webroot/js/EMFJS.bundle.js"></script>
<script src="$webroot/js/RTFJS.bundle.js"></script>
<script>
__HTML
?>
<?php if ($current_user_id > 0): ?>
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
<?php endif; ?>
fetch(url).then(res => res.arrayBuffer()).then(arrayBuffer => {
	RTFJS.loggingEnabled(false);
	WMFJS.loggingEnabled(false);
	EMFJS.loggingEnabled(false);
	const doc = new RTFJS.Document(arrayBuffer);
	doc.render().then(html => {
		viewer = document.getElementById('reader');
		viewer.append(...html);
<?php if ($current_user_id > 0 && $savedPos > 0): ?>
		window.scrollTo(0, document.body.scrollHeight / 100 * <?= $savedPos ?>);
<?php endif; ?>
	});
});
</script>
