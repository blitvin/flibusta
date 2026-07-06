<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
if ($current_user_id > 0) {
	$savePositionUrl = $webroot . '/save_position.php';
	$saveBookId      = (int)$url->var1;
	$saveCsrf        = get_csrf_token();
	?>
	<script>
	var isScrolling;
	var savePositionUrl = <?php echo json_encode($savePositionUrl, JSON_UNESCAPED_SLASHES); ?>;
	var saveBookId = <?php echo (int)$saveBookId; ?>;
	var saveCsrf = <?php echo json_encode($saveCsrf); ?>;

	window.addEventListener('scroll', function (event) {
		window.clearTimeout(isScrolling);
		isScrolling = setTimeout(function() {
			var pos = 100 / document.body.scrollHeight * window.scrollY;
			var x = new XMLHttpRequest();
			x.open("POST", savePositionUrl, true);
			x.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
			x.send("bookid=" + encodeURIComponent(saveBookId) + "&pos=" + encodeURIComponent(pos) + "&csrf_token=" + encodeURIComponent(saveCsrf));
		}, 66);
	}, false);
	</script>
	<?php

	$stmt = $dbh->prepare("SELECT pos FROM progress WHERE user_id=:uid AND bookid=:id LIMIT 1");
	$stmt->bindParam(":uid", $current_user_id);
	$stmt->bindParam(":id", $url->var1);
	$stmt->execute();
	if ($p = $stmt->fetch()) {
		$scrollPos = (float)($p->pos ?? 0);
		echo "<script>";
		echo 'document.addEventListener("DOMContentLoaded", function(event) {';
		echo "window.scrollTo(0, (document.body.scrollHeight / 100 *" . $scrollPos . "));\n";
		echo "});\n";
		echo "</script>";
	}
}


$content = '';

$localFb2 = LOCAL_LIBRARY_PATH . intval($url->var1) . '.fb2';
if (file_exists($localFb2)) {
	$filesize = filesize($localFb2);
	if ($filesize > MAX_FB2_SIZE_2_DISPLAY) {
		echo "<b>Файл слишком большой для показа. Его  размер $filesize, максимальный размер файла для показа ".MAX_FB2_SIZE_2_DISPLAY."</b><br>".PHP_EOL;
		echo "Вы можете скачать файл и читать локальную копию. Для скачивания воспользуйтесь линком fb2 под картиркой обложки";
		die();
	}
	$data = file_get_contents($localFb2);
} else {
	$fb2Entry = (isset($dbFilename) && $dbFilename && strtolower(pathinfo($dbFilename, PATHINFO_EXTENSION)) === 'fb2')
		? $dbFilename
		: $url->var1 . '.fb2';
	$stat = $zip->statName($fb2Entry);
	if (!$stat) {
		echo "<b>Не удается прочесть файл в ZIP архиве</b>";
		return;
	}
	$filesize = $stat['size'];
	if ($filesize > MAX_FB2_SIZE_2_DISPLAY) {
		echo "<b>Файл слишком большой для показа. Его  размер $filesize, максимальный размер файла для показа ".MAX_FB2_SIZE_2_DISPLAY."</b><br>".PHP_EOL;
		echo "Вы можете скачать файл и читать локальную копию. Для скачивания воспользуйтесь линком fb2 под картиркой обложки";
		die();
	}
	$data = $zip->getFromName($fb2Entry);
}

$fb2 = simplexml_load_string($data);
echo ($fb2 ? '' : 'FB2 Parse Error'), PHP_EOL;

$images = array();
foreach ($fb2->binary as $binary) {
	$id = $binary->attributes()['id'];
	$images["$id"] = $binary;
}

if (isset($fb2->body->section)) {
	foreach ($fb2->body->section as $section) {
		$s = $section->asXML();
		$s = str_replace("<title>", "<subtitle>", $s);
		$s = str_replace("</title>", "</subtitle>", $s);
		$s = str_replace('<image l:href="#', '<img style="width:100%;" src="', $s);
		foreach (array_keys($images) as $i) {
			$s = str_replace($i, "data:image/jpeg;base64," . $images[$i], $s);
		}
		$content .= $s;
	}
} else {
	$s = $fb2->body->asXML();
	$s = str_replace("<title>", "<subtitle>", $s);
	$s = str_replace("</title>", "</subtitle>", $s);
	$s = str_replace('<image l:href="#', '<img style="width:100%;" src="', $s);
	foreach (array_keys($images) as $i) {
		$s = str_replace($i, "data:image/jpeg;base64," . $images[$i], $s);
	}
	$content .= $s;
}
echo str_replace("<p>***</p>",  '<div class="divider div-transparent div-dot"></div>', str_replace("section>>", "section>", $content));

