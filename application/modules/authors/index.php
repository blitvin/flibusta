<style>
.c {
	background: #eee;
	border-radius: 50%;
	border-color: #eee;
}
</style>

<?php

include_once(ROOT_PATH . "webroot.php");
$filter2 = "";
$letter = 'А%';
$get = '';

if (isset($_GET['q'])) {
	if ($_GET['q'] == '') {
		unset($_SESSION['authors_q']);
	} else {
		$_SESSION['authors_q'] = $_GET['q'];
		unset($_SESSION['authors_letter']);
	}
}
if (isset($_GET['letter'])) {
	$l = mb_strtolower($_GET['letter']);
	unset($_SESSION['authors_q']);
	if ($l != '') {
		$_SESSION['authors_letter'] = $l;
	} else {
		unset($_SESSION['authors_letter']);
	}
}

if (isset($_SESSION['authors_letter'])) {
	$get = $_SESSION['authors_letter'];
	$letter = $get . '%';
}

echo "<ul class='pagination'>";
	foreach (range(chr(0xC0), chr(0xDF)) as $b) {
		$l = iconv('CP1251', 'UTF-8', $b);
		if ($l == mb_strtoupper($get)) {
			$cc = 'active';
		} else {
			$cc = '';
		}
		echo "<li class='page-item $cc'><a class='page-link' href='$webroot/authors/?letter=" . urlencode($l) . "'>$l</a></li>";
	}
echo "</ul>";
echo "<ul class='pagination'>";
	foreach (range('A', 'Z') as $b) {
		$l = iconv('CP1251', 'UTF-8', $b);
		if ($l == mb_strtoupper($get)) {
			$cc = 'active';
		} else {
			$cc = '';
		}
		echo "<li class='page-item $cc'><a class='page-link' href='$webroot/authors/?letter=" . urlencode($l) . "'>$l</a></li>";
	}

echo "</ul>";


echo "<form action='$webroot/authors/'>\n";
?>
<div class="input-group mb-3">
  <input name="q" type="text" class="form-control" placeholder="Поиск автора" aria-label="Поиск серии" aria-describedby="basic-addon2">
  <div class="input-group-append">

    <input type='submit' class="btn btn-outline-secondary" value='Поиск' type="button">
  </div>
</div>
</form>

<?php
$start = AUTHORS_PAGE * $page;

if (isset($_SESSION['authors_q'])) {
	$q = $_SESSION['authors_q'];
	$hasBooks = "EXISTS (SELECT 1 FROM libavtor la JOIN libbook lb ON lb.bookid=la.bookid
		WHERE la.avtorid=an.avtorid AND lb.deleted='0')";

	$cntStmt = $dbh->prepare("SELECT COUNT(*) cnt
		FROM libavtorname an
		JOIN libavtorname_ts at ON at.avtorid = an.avtorid
		WHERE at.vector @@ websearch_to_tsquery('russian', :q)
		AND $hasBooks");
	$cntStmt->bindParam(':q', $q);
	$cntStmt->execute();
	$cnt = $cntStmt->fetch()->cnt;

	$stmt = $dbh->prepare("SELECT an.*,
			(SELECT COUNT(*) FROM libavtor la JOIN libbook lb ON lb.bookid=la.bookid
			 WHERE lb.deleted='0' AND la.avtorid=an.avtorid) cnt,
			ts_rank(at.vector, websearch_to_tsquery('russian', :q2)) AS rank,
			lap.file
		FROM libavtorname an
		JOIN libavtorname_ts at ON at.avtorid = an.avtorid
		LEFT JOIN libapics lap ON lap.avtorid = an.avtorid
		WHERE at.vector @@ websearch_to_tsquery('russian', :q3)
		AND $hasBooks
		ORDER BY rank DESC, an.lastname
		LIMIT " . AUTHORS_PAGE . " OFFSET $start");
	$stmt->bindParam(':q2', $q);
	$stmt->bindParam(':q3', $q);
	$stmt->execute();
} else {
	$cntStmt = $dbh->prepare("SELECT COUNT(*) cnt FROM libavtorname
		WHERE lower(libavtorname.lastname) LIKE :letter");
	$cntStmt->bindParam(":letter", $letter);
	$cntStmt->execute();
	$cnt = $cntStmt->fetch()->cnt;

	$stmt = $dbh->prepare("SELECT *,
			(SELECT COUNT(*) FROM libavtor WHERE libavtor.avtorid=libavtorname.avtorid) cnt
			FROM libavtorname
			LEFT JOIN libapics USING(AvtorId)
			WHERE LOWER(libavtorname.lastname) LIKE :letter
			ORDER BY firstname LIMIT " . AUTHORS_PAGE . " OFFSET $start");
	$stmt->bindParam(":letter", $letter);
	$stmt->execute();
}

echo '<div class="row">';
show_gpager(ceil($cnt / AUTHORS_PAGE), 5);
while ($a = $stmt->fetch()) {
	if ($a->cnt > 0) {
		echo "<div class='col col-sm-6 mb-3 d-flex justify-content-between'>";
		echo "<a class='mw-100 rounded-pill author' href='$webroot/author/view/$a->avtorid'>";
		if ($a->file != '') {
			echo "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$a->avtorid' />";	
		}
		echo "&nbsp;$a->lastname $a->firstname $a->middlename $a->nickname&nbsp;</a>";
		echo "<div class='badge bg-secondary'>$a->cnt</div>";
		echo "</div>";

	}
}
echo "</div>";

show_gpager(ceil($cnt / AUTHORS_PAGE), 5);
