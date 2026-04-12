<?php
include_once(ROOT_PATH . "webroot.php");
$stmt = $dbh->prepare("SELECT * FROM libavtorname LEFT JOIN libapics USING(AvtorId) WHERE avtorid=:id");
$stmt->bindParam(":id", $url->var1);
$stmt->execute();
$a = $stmt->fetch();

echo "<div class='card mb-3'>";
echo "<div class='card-header'><h3>$a->lastname $a->firstname $a->middlename $a->nickname</h3></div>";
echo "<div class='card-body'><div class='row'>";

echo "<div class='col-sm-2 mb-3'>";
if (isset($a->file)) {
	echo "<img src='$webroot/extract_author.php?id=$a->avtorid' style='width: 100%;' class='card-image' /><br />";	
}
echo "<a class='btn btn-primary mt-2 w-100' href='$webroot/?aid=$a->avtorid'>Книги автора</a>";

try {
	$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
	if ($current_user_id > 0) {
		$stmt = $dbh->prepare("SELECT COUNT(*) cnt FROM fav WHERE user_id=:uid AND avtorid=:id");
		$stmt->bindParam(":uid", $current_user_id);
		$stmt->bindParam(":id", $a->avtorid);
		$stmt->execute();
		$is_fav = ($stmt->fetch()->cnt > 0);
		$action = $is_fav ? 'unfav_author' : 'fav_author';
		$button_text = $is_fav ? 'Из избранного' : 'В избранное';
		$button_class = $is_fav ? 'btn-warning' : 'btn-secondary';
		echo "<form method='POST' action='' style='display:contents;'>
			<input type='hidden' name='action' value='$action' />
			<input type='hidden' name='id' value='$a->avtorid' />
			<input type='hidden' name='csrf_token' value='" . (isset($_SESSION['csrf_token']) ? htmlspecialchars($_SESSION['csrf_token']) : '') . "' />
			<button type='submit' class='btn $button_class mt-2 w-100'>$button_text</button>
		</form>";
	}
} catch (PDOException $e) {
	//
}

echo "</div>";
echo "<div class='col-sm-10 mb-3'>";

$stmt = $dbh->prepare("SELECT * FROM libaannotations WHERE AvtorId=:id");
$stmt->bindParam(":id", $url->var1);
$stmt->execute();
while ($an = $stmt->fetch()) {
	echo "$an->title<br />";
	echo "<p>", bbc2html(nl2br($an->body)), "</p>";
}
echo '</div>';



echo "</div></div></div>";

