<?php
/**
 * /book/content/<id> — a book's own markup as a standalone document.
 *
 * Book files come from Flibusta dumps, the daily feed, torrents and on-the-fly
 * downloads, so their markup is untrusted. Rendered inline in the library page
 * it was a stored-XSS vector: a <script> in an fb2 body, an <img onerror=> in a
 * .txt or .html ran with the reader's session, and admins read books too.
 *
 * So the markup is served from its own URL and framed with a sandbox by
 * modules/book/index.php, the same isolation epub.js already gives epub. The
 * CSP below repeats that sandbox, so the document stays inert when opened
 * directly and not only when framed; the two grants match the iframe's sandbox
 * attribute — same-origin so the parent can size the frame to its content,
 * popups so a link inside a book still opens in a new tab. Neither grants
 * allow-scripts, which is what makes it safe to emit the markup verbatim.
 *
 * Included from modules/book/module.conf, which has already fetched $book.
 */
global $url, $book, $dbh, $webroot;

// module.conf runs before renderer.php, where the maintenance check normally
// lives, and this file exits before reaching it — so take the shared lock here,
// the way addbook/module.conf does for its own early-exit routes.
$_dblock = fopen(DBUPDATE_LOCK, 'r');
if ($_dblock === false || flock($_dblock, LOCK_SH | LOCK_NB) === false) {
	http_response_code(503);
	header('Content-Type: text/html; charset=utf-8');
	header('Refresh: 30');
	echo '<!doctype html><meta charset="utf-8"><p>Библиотека проходит техническое обслуживание.';
	exit;
}

/** The "file is too large to display" notice shared by both fb2 size checks. */
function book_content_too_big($filesize) {
	return '<b>Файл слишком большой для показа. Его размер ' . intval($filesize)
		. ', максимальный размер файла для показа ' . MAX_FB2_SIZE_2_DISPLAY . '</b><br>'
		. 'Вы можете скачать файл и читать локальную копию. '
		. 'Для скачивания воспользуйтесь линком fb2 под картинкой обложки';
}

function book_content_fb2(int $bid, ?ZipArchive $zip, ?string $dbFilename): string {
	$localFb2 = LOCAL_LIBRARY_PATH . $bid . '.fb2';
	if (file_exists($localFb2)) {
		$filesize = filesize($localFb2);
		if ($filesize > MAX_FB2_SIZE_2_DISPLAY) {
			return book_content_too_big($filesize);
		}
		$data = file_get_contents($localFb2);
	} else {
		if ($zip === null) {
			return '<b>Не удается прочесть файл в ZIP архиве</b>';
		}
		$fb2Entry = ($dbFilename && strtolower(pathinfo($dbFilename, PATHINFO_EXTENSION)) === 'fb2')
			? $dbFilename
			: $bid . '.fb2';
		$stat = $zip->statName($fb2Entry);
		if (!$stat) {
			return '<b>Не удается прочесть файл в ZIP архиве</b>';
		}
		if ($stat['size'] > MAX_FB2_SIZE_2_DISPLAY) {
			return book_content_too_big($stat['size']);
		}
		$data = $zip->getFromName($fb2Entry);
	}

	$fb2 = simplexml_load_string($data);
	// Bail out instead of dereferencing a failed parse, which used to print the
	// error and then carry on into a chain of warnings.
	if (!$fb2 || !isset($fb2->body)) {
		return 'FB2 Parse Error';
	}

	$images = array();
	foreach ($fb2->binary as $binary) {
		$id = $binary->attributes()['id'];
		$images["$id"] = $binary;
	}

	$sections = isset($fb2->body->section) ? $fb2->body->section : array($fb2->body);
	$content = '';
	foreach ($sections as $section) {
		$s = $section->asXML();
		$s = str_replace("<title>", "<subtitle>", $s);
		$s = str_replace("</title>", "</subtitle>", $s);
		$s = str_replace('<image l:href="#', '<img style="width:100%;" src="', $s);
		foreach (array_keys($images) as $i) {
			$s = str_replace($i, "data:image/jpeg;base64," . $images[$i], $s);
		}
		$content .= $s;
	}

	return str_replace("<p>***</p>", '<div class="divider div-transparent div-dot"></div>',
		str_replace("section>>", "section>", $content));
}

function book_content_txt(int $bid, ?ZipArchive $zip): string {
	$localTxt = LOCAL_LIBRARY_PATH . $bid . '.txt';
	if (file_exists($localTxt)) {
		$content = file_get_contents($localTxt);
	} elseif ($zip !== null) {
		$content = $zip->getFromName("$bid.txt");
	} else {
		$content = false;
	}
	if ($content === false) {
		return '<b>Не удается прочесть файл в ZIP архиве</b>';
	}
	if (!mb_detect_encoding($content, 'UTF-8', true)) {
		$content = iconv('windows-1251//IGNORE', 'UTF-8//IGNORE', $content);
	}
	// nl2p() escapes each line, so the "section>>" fix-up the fb2 path needs
	// cannot apply here any more.
	return '<section>' . str_replace('<p>***</p>',
		'<div class="divider div-transparent div-dot"></div>', nl2p($content)) . '</section>';
}

/**
 * Legacy .doc (Word 97-2003) as text.
 *
 * .docx is a zip of XML that docx-preview.min.js renders in the browser; .doc is a
 * binary OLE2 container nothing here can read, so 4500-odd books used to have no
 * reading view at all. antiword converts it server-side (GPL v2, like this
 * project), and the result goes through the same nl2p() path as a .txt - which is
 * also why 'doc' is a framed format: what reaches the browser is markup this code
 * generated, not markup out of the book file.
 *
 * Text only: images, tables and formatting do not survive. antiword has been
 * unmaintained since 2005 and does fail on some files, hence the fallback notice -
 * the download button on the page above still gives the reader the original.
 */
function book_content_doc(int $bid, ?ZipArchive $zip): string {
	$localDoc = LOCAL_LIBRARY_PATH . $bid . '.doc';
	$tmpDoc   = null;
	if (!file_exists($localDoc)) {
		if ($zip === null) {
			return '<b>Не удается прочесть файл в ZIP архиве</b>';
		}
		$data = $zip->getFromName("$bid.doc");
		if ($data === false) {
			return '<b>Не удается прочесть файл в ZIP архиве</b>';
		}
		$tmpDoc = CACHE_PATH . 'tmp/doc_' . $bid . '_' . uniqid() . '.doc';
		if (@file_put_contents($tmpDoc, $data) === false) {
			error_log("book_content_doc: cannot write $tmpDoc");
			return '<b>Не удается прочесть файл книги</b>';
		}
		$localDoc = $tmpDoc;
	}

	// -m UTF-8.txt is what makes antiword emit UTF-8; without it Cyrillic comes
	// out as question marks.
	exec('antiword -m UTF-8.txt ' . escapeshellarg($localDoc) . ' 2>&1', $lines, $code);
	if ($tmpDoc !== null) {
		@unlink($tmpDoc);
	}
	$text = implode("\n", $lines);
	if ($code !== 0 || trim($text) === '') {
		error_log("book_content_doc: antiword failed for book $bid (exit $code): "
			. trim(implode('; ', array_slice($lines, 0, 2))));
		return '<b>Не удалось преобразовать .doc для показа в браузере.</b><br>'
			. 'Файл можно скачать и открыть в текстовом редакторе — воспользуйтесь кнопкой «Скачать» на странице книги.';
	}

	return '<section>' . str_replace('<p>***</p>',
		'<div class="divider div-transparent div-dot"></div>', nl2p($text)) . '</section>';
}

function book_content_html(int $bid, ?ZipArchive $zip, string $ext): string {
	$localHtml = LOCAL_LIBRARY_PATH . $bid . '.' . $ext;
	if (file_exists($localHtml)) {
		$content = file_get_contents($localHtml);
	} elseif ($zip !== null) {
		$content = $zip->getFromName("$bid.$ext");
	} else {
		$content = false;
	}
	if ($content === false) {
		return '<b>Не удается прочесть файл в ZIP архиве</b>';
	}
	// The browser-side renderer decoded every file as windows-1251; detect
	// instead, so a UTF-8 .html is no longer mangled.
	if (!mb_detect_encoding($content, 'UTF-8', true)) {
		$content = iconv('windows-1251//IGNORE', 'UTF-8//IGNORE', $content);
	}
	return $content;
}

$bid = intval($url->var1);
$ext = isset($book->filetype) ? strtolower(trim($book->filetype)) : '';

header('Content-Type: text/html; charset=utf-8');
header('Content-Security-Policy: sandbox allow-same-origin allow-popups allow-popups-to-escape-sandbox');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

if (!isset($book->bookid)) {
	http_response_code(404);
	$body = '<b>Книга не найдена</b>';
} else {
	$src = book_open_source($dbh, $bid, $ext);
	if ($src === null) {
		$body = '<b>Не удалось открыть книгу № ' . $bid . ', вероятно zip файл с книгой отсутствует</b>';
	} elseif ($src['status'] !== 'ok') {
		$body = '<b>Не удалось открыть архив с книгой</b>';
	} else {
		switch ($ext) {
			case 'fb2':
				$body = book_content_fb2($bid, $src['zip'], $src['dbFilename']);
				break;
			case 'txt':
				$body = book_content_txt($bid, $src['zip']);
				break;
			case 'html':
			case 'htm':
				$body = book_content_html($bid, $src['zip'], $ext);
				break;
			case 'doc':
				$body = book_content_doc($bid, $src['zip']);
				break;
			default:
				$body = '<b>Формат ' . htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') . ' здесь не отображается</b>';
		}
		if ($src['zip'] !== null) {
			$src['zip']->close();
		}
	}
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<base target="_blank">
<link href="<?= htmlspecialchars($webroot, ENT_QUOTES, 'UTF-8') ?>/css/style.css" rel="stylesheet">
<style>
body { margin: 0; padding: 0 0.3rem; background: #fff; }
img { max-width: 100%; height: auto; }
</style>
</head>
<body class="reader">
<?= $body ?>
</body>
</html>
<?php
exit;
