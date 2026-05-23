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
        $savedPos = intval($p->pos ?? 0);
    }
}
echo "<script src='$webroot/js/pdf.js'></script>\n"; ?>

<div id="pdf-toolbar" style="position:sticky;top:0;z-index:10;background:#fff;padding:6px 0;text-align:center;border-bottom:1px solid #ccc;">
    <button id="prev" onclick="changePage(-1)">&#8249; Назад</button>
    &nbsp;
    <span>Страница <span id="pageNum">—</span> из <span id="pageCount">—</span></span>
    &nbsp;
    <button id="next" onclick="changePage(1)">Вперёд &#8250;</button>
    &nbsp;&nbsp;|&nbsp;&nbsp;
    <input id="gotoInput" type="number" min="1" style="width:4em;text-align:center;" placeholder="№">
    <button onclick="gotoPage()">Перейти</button>
</div>
<canvas id="pdf-canvas" style="display:block;margin:0 auto;max-width:100%;"></canvas>

<script>
var pdfjsLib = window['pdfjs-dist/build/pdf'];
<?php echo "pdfjsLib.GlobalWorkerOptions.workerSrc = '$webroot/js/pdf.worker.js';\n"; ?>

var currentPage = <?= max(1, $savedPos) ?>;
var numPages = 0;
var thePDF = null;
var renderTask = null;
var canvas = document.getElementById('pdf-canvas');
var ctx = canvas.getContext('2d');

<?php if ($current_user_id > 0): ?>
var savePositionUrlPrefix = <?= json_encode($savePositionUrlPrefix, JSON_UNESCAPED_SLASHES) ?>;
function savePage(pageNum) {
    var x = new XMLHttpRequest();
    x.open("GET", savePositionUrlPrefix + pageNum, true);
    x.send(null);
}
<?php else: ?>
function savePage(pageNum) {}
<?php endif; ?>

pdfjsLib.getDocument(url).promise.then(function(pdf) {
    thePDF = pdf;
    numPages = pdf.numPages;
    document.getElementById('pageCount').textContent = numPages;
    if (currentPage > numPages) currentPage = numPages;
    renderPage(currentPage);
});

function renderPage(pageNum) {
    document.getElementById('pageNum').textContent = pageNum;
    document.getElementById('prev').disabled = (pageNum <= 1);
    document.getElementById('next').disabled = (pageNum >= numPages);

    thePDF.getPage(pageNum).then(function(page) {
        var viewport = page.getViewport({scale: 1.5});
        canvas.height = viewport.height;
        canvas.width = viewport.width;
        if (renderTask) {
            renderTask.cancel();
            renderTask = null;
        }
        renderTask = page.render({canvasContext: ctx, viewport: viewport});
        renderTask.promise.catch(function() {});
        window.scrollTo(0, 0);
    });
}

function gotoPage() {
    var input = document.getElementById('gotoInput');
    var pageNum = parseInt(input.value, 10);
    input.value = '';
    if (isNaN(pageNum) || pageNum < 1 || pageNum > numPages) return;
    currentPage = pageNum;
    renderPage(currentPage);
    savePage(currentPage);
}

document.getElementById('gotoInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') gotoPage();
});

function changePage(delta) {
    var next = currentPage + delta;
    if (next < 1 || next > numPages) return;
    currentPage = next;
    renderPage(currentPage);
    savePage(currentPage);
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown' || e.key === 'PageDown') {
        changePage(1);
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp' || e.key === 'PageUp') {
        changePage(-1);
    }
});
</script>
