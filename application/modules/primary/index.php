<?php
if (isset($_GET['fb2'])) {
	if ($_GET['fb2'] == '') {
		unset($_SESSION['fb2']);
	} else {
		$_SESSION['fb2'] = true;
	}
}
if (isset($_GET['ru'])) {
	if ($_GET['ru'] == '') {
		unset($_SESSION['ru']);
	} else {
		$_SESSION['ru'] = true;
	}
}
if (isset($_GET['q'])) {
	if ($_GET['q'] == '') {
		unset($_SESSION['search']);
	} else {
		$_SESSION['search'] = $_GET['q'];
	}
}

if (isset($_GET['aid'])) {
	if ($_GET['aid'] == '') {
		unset($_SESSION['filter_author']);
	} else {
		$_SESSION['filter_author'] = intval($_GET['aid']);
	}
}


if (isset($_GET['gid'])) {
	if ($_GET['gid'] == '') {
		unset($_SESSION['filter_genre']);
	} else {
		$_SESSION['filter_genre'] = intval($_GET['gid']);
	}
}

if (isset($_GET['sid'])) {
	if ($_GET['sid'] == '') {
		unset($_SESSION['filter_series']);
	} else {
		$_SESSION['filter_series'] = intval($_GET['sid']);
	}
}



if (isset($_GET['xgid'])) {
	if ($_GET['xgid'] == '') {
		unset($_SESSION['filter_xgenre']);
	} else {
		if ($_SESSION['filter_genre'] == intval($_GET['xgid'])) {
			unset($_SESSION['filter_genre']);
		}
		$_SESSION['filter_xgenre'] = intval($_GET['xgid']);
	}
}

// Personal hidden-genre list (Настройки -> "Скрытые жанры").
// The session key stores the NEGATION ("switched off for this session"), so the
// list applies by default with no session write at all - which also means
// sessions created before this feature existed behave correctly.
if (isset($_GET['xgl'])) {
	if ($_GET['xgl'] == '') {
		unset($_SESSION['xgenres_off']);
	} else {
		$_SESSION['xgenres_off'] = true;
	}
}


$filter = '';
$fcontent = '';
$join = '';
$cols = '';

$xgenres = isset($_SESSION['user_id']) ? get_excluded_genres($dbh, $_SESSION['user_id']) : [];
$xgenres_active = $xgenres && !isset($_SESSION['xgenres_off']);


$fcontent .= '<div class="btn-group mt-1 me-1" role="group">';
if (isset($_SESSION['fb2'])) {
	$filter .= "AND filetype='fb2' ";
	$fcontent .= "<a class='btn bg-dark text-white bg-opacity-90 text-white' href='$webroot/?fb2'>Только FB2</a> ";
} else {
	$fcontent .= "<a class='btn bg-dark text-white bg-opacity-50 text-white' href='$webroot/?fb2=1'>Все форматы</a> ";
}

if (isset($_SESSION['ru'])) {
	$filter .= "AND lang='ru' ";
	$fcontent .= "<a class='btn bg-success text-white bg-opacity-90 text-white' href='$webroot/?ru'>На русском</a> ";
} else {
	$fcontent .= "<a class='btn bg-success text-white bg-opacity-50 text-white' href='$webroot/?ru=1'>Все языки</a> ";
}

// Shown only to users who actually have a hidden-genre list.
if ($xgenres) {
	$n = count($xgenres);
	if ($xgenres_active) {
		$fcontent .= "<a class='btn bg-danger text-white bg-opacity-90' title='Скрыто жанров: $n. Список настраивается в разделе «Настройки».' href='$webroot/?xgl=1'>Без скрытых жанров</a> ";
	} else {
		$fcontent .= "<a class='btn bg-danger text-white bg-opacity-50' title='Скрытие жанров отключено до конца сеанса' href='$webroot/?xgl'>Все жанры</a> ";
	}
}
$fcontent .= '</div>';

if (isset($_SESSION['filter_author'])) {
	$do_cnt = true;
	$filter .= 'AND avtorid=:aid ';
	$join .= 'LEFT JOIN libavtor a USING(BookId) ';
	$stmt = $dbh->prepare("SELECT * FROM libavtorname
		LEFT JOIN libapics USING(AvtorId)
		WHERE AvtorId=:id");
	$stmt->bindParam(":id", $_SESSION['filter_author']);
	$stmt->execute();
	$a = $stmt->fetch();

	$fcontent .= "<div class='badge rounded-pill author'>";
	if ($a->file != '') {
		$fcontent .= "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$a->avtorid' />";
	}
	$fcontent .= "<a href='$webroot/?aid'>$a->lastname $a->firstname $a->middlename $a->nickname</a> <i class='fas fa-times-circle'></i></div> ";
}

if (isset($_SESSION['filter_genre'])) {
	$filter .= 'AND g.genreid=:gid ';
	$join .= 'LEFT JOIN libgenre g USING(BookId) ';
	$stmt = $dbh->prepare("SELECT * FROM libgenrelist
		WHERE genreid=:id");
	$stmt->bindParam(":id", $_SESSION['filter_genre']);
	$stmt->execute();
	$g = $stmt->fetch();

	$fcontent .= "<div class='badge bg-success p-1 text-white'>";
	$fcontent .= "<a class='text-white' href='$webroot/?xgid=$g->genreid'>$g->genremeta: $g->genredesc <i class='fas fa-times-circle'></i></a></div> ";
}

if (isset($_SESSION['filter_xgenre'])) {
	// NB: the trailing space is required - the series and search fragments are
	// appended after this one, and "= 0AND ..." is a syntax error on PG 15+
	// ("trailing junk after numeric literal").
	$filter .= 'AND (SELECT COUNT(*) FROM libgenre xg WHERE xg.BookId=B.BookId AND xg.genreid=:xgid) = 0 ';
	$stmt = $dbh->prepare("SELECT * FROM libgenrelist
		WHERE genreid=:id");
	$stmt->bindParam(":id", $_SESSION['filter_xgenre']);
	$stmt->execute();
	$xg = $stmt->fetch();

	$fcontent .= "<div class='badge bg-secondary p-1 text-white'>";
	$fcontent .= "<a style='text-decoration: line-through;' class='text-white' href='$webroot/?xgid'>$xg->genremeta: $xg->genredesc <i class='fas fa-times-circle'></i></a></div> ";
}

if ($xgenres_active) {
	// Never hide the genre the user explicitly asked to browse, or the page
	// would come back empty with no explanation. Mirrors the gid/xgid
	// de-confliction near the top of this file.
	$applied = $xgenres;
	if (isset($_SESSION['filter_genre'])) {
		$applied = array_values(array_diff($applied, [intval($_SESSION['filter_genre'])]));
	}
	if ($applied) {
		// Ids are (int) values from get_excluded_genres(), never user text, so
		// inlining is safe. It is also deliberate: $filter is shared by the main
		// SELECT and the COUNT query below, so an inlined list keeps the two in
		// sync with no changes to either bind block (EMULATE_PREPARES is off, so
		// a placeholder cannot be reused and PDO cannot bind an array).
		$in = implode(',', $applied);
		$filter .= "AND NOT EXISTS (SELECT 1 FROM libgenre xg2 WHERE xg2.bookid=b.bookid AND xg2.genreid IN ($in)) ";
	}
}


if (isset($_SESSION['filter_series'])) {
	$do_cnt = true;
	$cols = 's.seqnumb,';
	$filter .= 'AND seqid=:sid ';
	$join .= 'LEFT JOIN libseq s USING(BookId) ';
	$stmt = $dbh->prepare("SELECT * FROM libseqname
		WHERE seqid=:id");
	$stmt->bindParam(":id", $_SESSION['filter_series']);
	$stmt->execute();
	$s = $stmt->fetch();

	$fcontent .= "<div class='badge bg-danger p-1 text-white'>";
	$fcontent .= "<a class='text-white' href='$webroot/?sid'>$s->seqname <i class='fas fa-times-circle'></i></a></div> ";
	$order = "s.seqnumb, $order";
	$seqname = $s->seqname;
	$seqid = $_SESSION['filter_series'];
}

if (isset($_SESSION['search'])) {
	$join .= 'LEFT JOIN libbook_ts bt ON bt.bookid = b.bookid ';
	$filter .= "AND (bt.vector @@ websearch_to_tsquery('russian', :search)
		OR EXISTS (
			SELECT 1 FROM libavtor la2
			JOIN libavtorname_ts at2 ON at2.avtorid = la2.avtorid
			WHERE la2.bookid = b.bookid
			AND at2.vector @@ websearch_to_tsquery('russian', :search2)
		)) ";
	$cols .= "ts_rank(COALESCE(bt.vector, to_tsvector('russian', '')), websearch_to_tsquery('russian', :search3)) AS _ts_rank, ";
	$order = "_ts_rank DESC, $order";

	$fcontent .= "<div class='badge bg-success p-1 text-white'>";
	$fcontent .= "<a class='text-white' href='$webroot/?q'>" . htmlspecialchars($_SESSION['search'], ENT_QUOTES, 'UTF-8') . " <i class='fas fa-times-circle'></i></a></div> ";
}

if (isset($_SESSION['filter_series'])) {
	// Neutral slot; the browser draws the button for whoever is logged in.
	$fcontent .= "<span class='float-end'>" . fav_slot('series', (int)$seqid, $seqname) . "</span> ";
}

echo "<div class='block rounded' style='margin-bottom:8px;'>";
echo "<form action='$webroot/'>";
?>

<div class="input-group mb-3">
   <input name="q" type="text" class="form-control" placeholder="Поиск по названию" aria-label="Поиск серии" aria-describedby="basic-addon2">
   <div class="input-group-append">
   <input type='submit' class="btn btn-outline-secondary" value='Поиск' type="button">

 </div>
</div>
</form>
<?php
echo $fcontent;

echo "</div>";


$sql = "SELECT *, $cols
        (SELECT Body FROM libbannotations WHERE BookId=b.BookId LIMIT 1) Body
		FROM libbook b
		$join
		WHERE deleted='0'
		$filter
		ORDER BY $order LIMIT " . RECORDS_PAGE . " OFFSET $start";

//echo "$sql";

$stmt = $dbh->prepare($sql);

if (isset($_SESSION['filter_author'])) {
	$stmt->bindParam(":aid", $_SESSION['filter_author']);
}
if (isset($_SESSION['filter_genre'])) {
	$stmt->bindParam(":gid", $_SESSION['filter_genre']);
}
if (isset($_SESSION['filter_xgenre'])) {
	$stmt->bindParam(":xgid", $_SESSION['filter_xgenre']);
}
if (isset($_SESSION['filter_series'])) {
	$stmt->bindParam(":sid", $_SESSION['filter_series']);
}
if (isset($_SESSION['search'])) {
	$stmt->bindParam(":search",  $_SESSION['search']);
	$stmt->bindParam(":search2", $_SESSION['search']);
	$stmt->bindParam(":search3", $_SESSION['search']);
}


try {
	$stmt->execute();
} catch (Exception $e) {
	$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
        header($protocol . ' 504 Gateway Time-out');

	echo "<div class='card m-3 border-danger'><div class='card-header bg-danger'>База данных</div><div class='card-body'>";
	echo "<h3>" . $e->getMessage() . "</h3>";
	echo "<p>Попробуйте упростить параметры поиска, убрать часть тэгов, направленность. Сервер маленький ^^.</p>";
	echo "</div></div>";
}

if (COUNT_BOOKS) {
	$sql = "SELECT COUNT(*) cnt
		FROM libbook b
		$join
		WHERE deleted='0'
		$filter";
	$stt = $dbh->prepare($sql);

	if (isset($_SESSION['filter_author'])) {
		$stt->bindParam(":aid", $_SESSION['filter_author']);
	}
	if (isset($_SESSION['filter_genre'])) {
		$stt->bindParam(":gid", $_SESSION['filter_genre']);
	}
	if (isset($_SESSION['filter_xgenre'])) {
		$stt->bindParam(":xgid", $_SESSION['filter_xgenre']);
	}
	if (isset($_SESSION['filter_series'])) {
		$stt->bindParam(":sid", $_SESSION['filter_series']);
	}
	if (isset($_SESSION['search'])) {
		$stt->bindParam(":search",  $_SESSION['search']);
		$stt->bindParam(":search2", $_SESSION['search']);
	}

	$stt->execute();
	$cnt = $stt->fetch()->cnt;
	echo "<span class='badge bg-primary mb-1'>Найдено: $cnt</span> ";
} else {
	$cnt = 2000;
}

$rcnt = $stmt->rowCount();
if ($rcnt < RECORDS_PAGE) {
	$cnt = $page * RECORDS_PAGE + $rcnt;
}

show_gpager(ceil($cnt / RECORDS_PAGE), 5);

$c = 0;
while ($book = $stmt->fetch()) {
	$c++;
	if ($c > 10) {
		break;
	}
	book_info_pg($book, $webroot);
}

show_gpager(ceil($cnt / RECORDS_PAGE), 5);

