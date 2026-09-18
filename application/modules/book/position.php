<?php
/**
 * Reading-position plumbing shared by every format whose page scrolls
 * (fb2, txt, html, mobi, docx, rtf — all stored in the `progress` table).
 *
 * This used to be copy-pasted into each of those six renderers. epub and djvu
 * keep their own, because they store a CFI / page number rather than a percent.
 *
 * Emits: bookSavedPos (percent, 0 when none) and bookRestorePosition(), which
 * each renderer calls once its content has height.
 */
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$savedPos = 0;
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
var bookSavedPos = <?= json_encode($savedPos) ?>;

function bookRestorePosition() {
	if (bookSavedPos > 0) {
		window.scrollTo(0, document.body.scrollHeight / 100 * bookSavedPos);
	}
}
<?php if ($current_user_id > 0): ?>
var positionSaver = makePositionSaver(
	<?= json_encode($savePositionUrl, JSON_UNESCAPED_SLASHES) ?>,
	<?= (int)$saveBookId ?>,
	<?= json_encode($saveCsrf) ?>,
	'pos',
	1000
);
window.addEventListener('scroll', function() {
	// Rounded to two decimals so that sub-pixel scroll jitter does not look like a
	// new position and defeat the saver's "unchanged value" check.
	var pos = 100 / document.body.scrollHeight * window.scrollY;
	positionSaver.schedule(Math.round(pos * 100) / 100);
}, false);
<?php endif; ?>
</script>
