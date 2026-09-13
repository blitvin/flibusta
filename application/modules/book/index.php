<?php
echo "<script>var url = '$webroot/usr.php?id=$url->var1';</script>";

// Determine view mode: URL selector overrides preference; fallback is contentonly
$_selector = $url->var2_str ?? '';
if (in_array($_selector, ['withannotation', 'contentonly'], true)) {
    $view_mode = $_selector;
} else {
    $view_mode = 'contentonly';
    $_uid = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
    if ($_uid > 0) {
        $_ps = $dbh->prepare("SELECT book_view_mode FROM user_settings WHERE user_id = ?");
        $_ps->execute([$_uid]);
        $_pr = $_ps->fetch();
        if ($_pr && in_array($_pr->book_view_mode, ['withannotation', 'contentonly'], true)) {
            $view_mode = $_pr->book_view_mode;
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
    $stmt = $dbh->prepare("SELECT name, text FROM libreviews WHERE bookid=:id ORDER BY time");
    $stmt->bindParam(":id", $url->var1);
    $stmt->execute();
    while ($r = $stmt->fetch()) {
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
    echo "<script src='$webroot/js/bookframe.js'></script>";
    echo "<iframe id='bookframe' class='bookframe' src='$webroot/book/content/$_bid'"
       . " sandbox='allow-same-origin allow-popups allow-popups-to-escape-sandbox'"
       . " referrerpolicy='no-referrer' title='"
       . htmlspecialchars($book->title, ENT_QUOTES, 'UTF-8') . "'></iframe>";
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
