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
echo "<script src='$webroot/js/pdf.js'></script>\n"; ?>

<script>
var pdfjsLib = window['pdfjs-dist/build/pdf'];

<?php echo "pdfjsLib.GlobalWorkerOptions.workerSrc = '$webroot/js/pdf.worker.js';\n"; ?>

var currPage = 1;
var numPages = 0;
var thePDF = null;

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

pdfjsLib.getDocument(url).promise.then(function(pdf) {
        thePDF = pdf;
        numPages = pdf.numPages;
        pdf.getPage( 1 ).then( handlePages );
});


function handlePages(page) {
    var viewport = page.getViewport( {scale: 1.5} );

	viewer = document.getElementById('reader');
    var canvas = document.createElement( "canvas" );
    canvas.style.display = "block";
    var context = canvas.getContext('2d');

    viewer.appendChild(canvas);
    canvas.height = viewport.height;
    canvas.width = viewport.width;
    page.render({canvasContext: context, viewport: viewport});
    var line = document.createElement("hr");
    document.body.appendChild( line );

    currPage++;
    if ( thePDF !== null && currPage <= numPages ) {
        thePDF.getPage( currPage ).then( handlePages );
    } else {
<?php if ($savedPos > 0): ?>
        window.scrollTo(0, document.body.scrollHeight / 100 * <?= $savedPos ?>);
<?php endif; ?>
    }
}

</script>
