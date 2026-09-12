<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$bookid = (int)$url->var1;
$posKind = 'pos';
include(ROOT_PATH . 'modules/book/position_js.php');
?>
<script>
window.addEventListener('scroll', function (event) {
	flibPosition.save(100 / document.body.scrollHeight * window.scrollY);
}, false);

document.addEventListener("DOMContentLoaded", function() {
	flibPosition.load(function (p) {
		if (p) window.scrollTo(0, document.body.scrollHeight / 100 * parseFloat(p));
	});
});
</script>
<?php


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

