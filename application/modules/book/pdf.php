<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$bookid = (int)$url->var1;
$posKind = 'pos';
include(ROOT_PATH . 'modules/book/position_js.php');
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

var currentPage = 1;
var numPages = 0;
var thePDF = null;
var renderTask = null;
var canvas = document.getElementById('pdf-canvas');
var ctx = canvas.getContext('2d');

function savePage(pageNum) { flibPosition.save(pageNum); }

pdfjsLib.getDocument(url).promise.then(function(pdf) {
    thePDF = pdf;
    numPages = pdf.numPages;
    document.getElementById('pageCount').textContent = numPages;
    renderPage(currentPage);
    // The saved page arrives asynchronously (see position_js.php); jump to it
    // once we have it, so the first render does not have to wait for the fetch.
    flibPosition.load(function (p) {
        var page = Math.max(1, Math.min(parseInt(p, 10) || 1, numPages));
        if (page !== currentPage) {
            currentPage = page;
            renderPage(currentPage);
        }
    });
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
