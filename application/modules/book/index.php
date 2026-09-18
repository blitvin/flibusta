<?php
echo "<script>var url = '$webroot/usr.php?id=$url->var1';</script>";

// Shared by position.php and the pdf/epub/djvu renderers below; only a logged-in
// reader has a position to store, so anonymous visitors do not fetch it.
if (isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0) {
    echo "<script src='$webroot/js/position_saver.js'></script>";
}

// Determine view mode: URL selector overrides preference; fallback is contentonly
$_selector = $url->var2_str ?? '';
if (in_array($_selector, ['withannotation', 'contentonly'], true)) {
    $view_mode = $_selector;
} else {
    $view_mode = 'contentonly';
    $_uid = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
    if ($_uid > 0) {
        $_pref = user_prefs($dbh, $_uid)->book_view_mode;
        if (in_array($_pref, ['withannotation', 'contentonly'], true)) {
            $view_mode = $_pref;
        }
    }
}

// Mode toggle shown at the top of every book page
$_bid = intval($url->var1);
echo "<div class='d-flex align-items-center justify-content-center gap-2 mb-2'>";
echo "<a href='$webroot/book/view/$_bid/withannotation' class='btn btn-sm "
    . ($view_mode === 'withannotation' ? 'btn-info' : 'btn-outline-info') . "'>О книге</a>";
if ($view_mode === 'contentonly') {
    echo "<span class='fw-bold'>" . htmlspecialchars($book->title, ENT_QUOTES, 'UTF-8') . "</span>";
}
echo "<a href='$webroot/book/view/$_bid/contentonly' class='btn btn-sm "
    . ($view_mode === 'contentonly' ? 'btn-primary' : 'btn-outline-primary') . "'>Читать</a>";
echo "</div>";

if ($view_mode === 'withannotation') {
    book_info_pg($book, $webroot, true);

    echo "<div class='card card-body p-3'><ul>";
    foreach (book_reviews($dbh, intval($url->var1)) as $r) {
        echo "<li><span class='badge bg-secondary'>" . htmlspecialchars($r->name, ENT_QUOTES, 'UTF-8') . "</span> "
           . htmlspecialchars($r->text, ENT_QUOTES, 'UTF-8') . "</li>";
    }
    echo "</ul></div>";
}


$ext = strtolower(trim($book->filetype));

// Formats whose content is the book's own markup are rendered by
// /book/content/<id> and framed in a sandbox, so that a <script> or an
// onerror= handler inside a book file cannot run with the reader's session.
// The rest are drawn by a JS library from the binary file (canvas for pdf/djvu,
// generated DOM for docx/rtf) or bring their own isolation (epub.js).
$framed_formats = ['fb2', 'txt', 'html', 'htm'];

// Formats that scroll the page and store a percentage in `progress`.
// epub and djvu keep their own position handling (CFI / page number).
$progress_formats = ['fb2', 'txt', 'html', 'htm', 'mobi', 'docx', 'rtf'];

if (in_array($ext, $progress_formats, true)) {
    include('position.php');
}

if (in_array($ext, $framed_formats, true)) {
    // The frame must exist before bookframe.js runs, or it has nothing to size.
    // The layout-critical declarations are inline as well as in .bookframe, so a
    // stale stylesheet cannot drop the frame back to its intrinsic 300x150 box.
    echo "<iframe id='bookframe' class='bookframe' src='$webroot/book/content/$_bid'"
       . " style='display:block;width:100%;border:0'"
       . " sandbox='allow-same-origin allow-popups allow-popups-to-escape-sandbox'"
       . " referrerpolicy='no-referrer' title='"
       . htmlspecialchars($book->title, ENT_QUOTES, 'UTF-8') . "'></iframe>";
    echo "<script src='$webroot/js/bookframe.js'></script>";
} else {
    // Resolves the archive, pre-extracts an inner zip and falls back to the
    // mirror, so the file is on disk before the viewer requests it via usr.php.
    $src = book_open_source($dbh, $_bid, $ext);
    if ($src === null) {
        echo "<p><b><center>Не удалось открыть книгу № " . $_bid . " , вероятно zip файл с книгой отсутсвует</center></b></p>\n";
    } elseif ($src['status'] !== 'ok') {
        // The archive path is server-side detail; book_open_source() logged it.
        echo "<p><b><center>Не удалось открыть архив с книгой</center></b></p>\n";
    } else {
        if ($src['zip'] !== null) {
            $src['zip']->close();
        }
        echo "<div id='reader' class='reader'>";

        if ($ext == 'epub') {
            include('epub.php');
        }

        if ($ext == 'pdf') {
            include('pdf.php');
        }

        if ($ext == 'mobi') {
            include('mobi.php');
        }

        if (($ext == 'djvu') || ($ext == 'djv')) {
            include('djvu.php');
        }

        if ($ext == 'rtf') {
            include('rtf.php');
        }

        if ($ext == 'docx') {
            include('docx.php');
        }

        echo "</div>";
    }
}
