<?php
/**
 * cbr/cbz viewer.
 *
 * A comic is an archive of images, so there is nothing to parse and no library to
 * load: comic_pages() unpacks it once (see functions.php) and this shows the pages
 * one at a time, served by public/comic_page.php.
 *
 * Position handling follows djvu.php - both count in pages, so both use
 * save_djvu_position.php and the djvu_progress table.
 *
 * Included from index.php, which has already opened <div id='reader'>.
 */

$comic_pages_list = comic_pages($dbh, $_bid, $ext);

if ($comic_pages_list === null) {
    // Unpacking failed - a solid RAR archive, a corrupt file, a book that is not
    // in the local archives. The reason is in the log; the reader gets the file.
    echo "<div class='alert alert-warning text-center' role='alert'>";
    echo "Не удалось распаковать архив комикса для показа в браузере. Файл можно скачать и открыть в программе для чтения комиксов.";
    echo "</div>";
    echo "<div class='text-center mb-3'>";
    echo "<a class='btn btn-primary' href='$webroot/usr.php?id=$_bid'>Скачать (" . htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') . ")</a>";
    echo "</div>";
    return;
}

$comic_total = count($comic_pages_list);

$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$savedPage = 1;
if ($current_user_id > 0) {
    $stmt = $dbh->prepare("SELECT page FROM djvu_progress WHERE user_id = ? AND bookid = ? LIMIT 1");
    $stmt->execute([$current_user_id, $_bid]);
    if ($dp = $stmt->fetch()) {
        $savedPage = max(1, min($comic_total, (int)($dp->page ?? 1)));
    }
}
?>
<div class="comic-viewer">
  <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
    <button type="button" class="btn btn-outline-primary btn-sm" id="comicPrev" aria-label="Предыдущая страница">&larr;</button>
    <span class="badge bg-secondary" id="comicCounter"></span>
    <button type="button" class="btn btn-outline-primary btn-sm" id="comicNext" aria-label="Следующая страница">&rarr;</button>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="comicFit">По высоте</button>
  </div>
  <div class="text-center">
    <img id="comicImage" alt="" style="max-width:100%;height:auto;cursor:pointer" />
  </div>
  <div class="d-flex align-items-center justify-content-center gap-2 mt-2">
    <input type="range" class="form-range" style="max-width:420px" id="comicRange" min="1" value="1" />
  </div>
</div>
<script>
(function () {
    var total = <?= (int)$comic_total ?>;
    var bookId = <?= (int)$_bid ?>;
    var base = <?= json_encode($webroot . '/comic_page.php', JSON_UNESCAPED_SLASHES) ?>;
    var page = <?= (int)$savedPage ?>;
    var fitHeight = false;

    var img = document.getElementById('comicImage');
    var counter = document.getElementById('comicCounter');
    var range = document.getElementById('comicRange');
    var fitBtn = document.getElementById('comicFit');
    range.max = total;

<?php if ($current_user_id > 0): ?>
    var saver = makePositionSaver(
        <?= json_encode($webroot . '/save_djvu_position.php', JSON_UNESCAPED_SLASHES) ?>,
        bookId,
        <?= json_encode(get_csrf_token()) ?>,
        'page',
        1000
    );
<?php else: ?>
    var saver = null;
<?php endif; ?>

    function pageUrl(n) {
        return base + '?id=' + bookId + '&page=' + n;
    }

    function show(n, remember) {
        page = Math.min(total, Math.max(1, n));
        img.src = pageUrl(page);
        img.alt = 'Страница ' + page + ' из ' + total;
        counter.textContent = page + ' / ' + total;
        range.value = page;
        if (remember !== false && saver) {
            saver.schedule(page);
        }
        // The next page is usually a moment away, so fetch it while this one is read.
        if (page < total) {
            var pre = new Image();
            pre.src = pageUrl(page + 1);
        }
    }

    document.getElementById('comicPrev').addEventListener('click', function () { show(page - 1); });
    document.getElementById('comicNext').addEventListener('click', function () { show(page + 1); });
    range.addEventListener('input', function () { show(parseInt(range.value, 10)); });
    img.addEventListener('click', function () { show(page + 1); });

    fitBtn.addEventListener('click', function () {
        fitHeight = !fitHeight;
        if (fitHeight) {
            img.style.maxWidth = '';
            img.style.maxHeight = '100vh';
            fitBtn.textContent = 'По ширине';
        } else {
            img.style.maxWidth = '100%';
            img.style.maxHeight = '';
            fitBtn.textContent = 'По высоте';
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) {
            return;
        }
        if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') {
            show(page + 1);
            e.preventDefault();
        } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
            show(page - 1);
            e.preventDefault();
        } else if (e.key === 'Home') {
            show(1);
            e.preventDefault();
        } else if (e.key === 'End') {
            show(total);
            e.preventDefault();
        }
    });

    show(page, false);
})();
</script>
