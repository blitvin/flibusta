<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$is_admin = !empty($_SESSION['is_admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$token = $_POST['csrf_token'] ?? '';
	if (validate_csrf_token($token)) {
		$local_action = $_POST['local_action'] ?? '';
		if ($current_user_id > 0 && $local_action === 'import_uuid') {
			$import_uuid = $_POST['list_uuid'] ?? '';
			$stmt = $dbh->prepare("INSERT INTO fav (user_id, bookid, avtorid, seqid)
				SELECT :uid, f.bookid, f.avtorid, f.seqid
				FROM fav f
				WHERE f.list_uuid=:uuid
				ON CONFLICT DO NOTHING");
			$stmt->bindParam(':uid', $current_user_id);
			$stmt->bindParam(':uuid', $import_uuid);
			$stmt->execute();
		}
		if ($is_admin && $local_action === 'delete_uuid') {
			$delete_uuid = $_POST['list_uuid'] ?? '';
			$stmt = $dbh->prepare("DELETE FROM fav_lists WHERE list_uuid=:uuid");
			$stmt->bindParam(':uuid', $delete_uuid);
			$stmt->execute();
			$stmt = $dbh->prepare("DELETE FROM fav WHERE list_uuid=:uuid");
			$stmt->bindParam(':uuid', $delete_uuid);
			$stmt->execute();
		}
	}
}

if ($current_user_id > 0) {
	echo "<h4 class='mb-3'>Мое избранное</h4>";

	$books_stmt = $dbh->prepare("SELECT DISTINCT b.*
		FROM fav f
		JOIN libbook b USING(bookid)
		WHERE f.user_id=:uid AND f.bookid IS NOT NULL
		ORDER BY b.time DESC");
	$books_stmt->bindParam(':uid', $current_user_id);
	$books_stmt->execute();
	$user_books = $books_stmt->fetchAll();

	$authors_stmt = $dbh->prepare("SELECT DISTINCT a.AvtorId avtorid, a.LastName lastname, a.FirstName firstname, a.MiddleName middlename, a.NickName nickname, p.File file
		FROM fav f
		JOIN libavtorname a ON a.AvtorId=f.avtorid
		LEFT JOIN libapics p ON p.AvtorId=a.AvtorId
		WHERE f.user_id=:uid AND f.avtorid IS NOT NULL
		ORDER BY a.LastName, a.FirstName, a.MiddleName, a.NickName");
	$authors_stmt->bindParam(':uid', $current_user_id);
	$authors_stmt->execute();
	$user_authors = $authors_stmt->fetchAll();

	$series_stmt = $dbh->prepare("SELECT DISTINCT s.SeqId seqid, s.SeqName seqname
		FROM fav f
		JOIN libseqname s ON s.SeqId=f.seqid
		WHERE f.user_id=:uid AND f.seqid IS NOT NULL
		ORDER BY s.SeqName");
	$series_stmt->bindParam(':uid', $current_user_id);
	$series_stmt->execute();
	$user_series = $series_stmt->fetchAll();

	if (!empty($user_books)) {
		echo "<h5 class='mb-2'>Книги</h5>";
		echo "<div class='contaner'><div class='row equal'>";
		foreach ($user_books as $book) {
			book_small_pg($book, $webroot);
		}
		echo "</div></div>";
	}

	if (!empty($user_authors)) {
		echo "<h5 class='mt-3 mb-2'>Авторы</h5>";
		echo "<div class='card mb-3'><div class='card-body'>";
		foreach ($user_authors as $author) {
			echo "<div class='d-flex align-items-center justify-content-between mb-2'>";
			echo "<div class='d-flex align-items-center gap-2'>";
			if (!empty($author->file)) {
				echo "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$author->avtorid' />";
			}
			echo "<a class='mw-100 rounded-pill author' href='$webroot/author/view/$author->avtorid'>$author->lastname $author->firstname $author->middlename $author->nickname</a>";
			echo "</div>";
			echo "<form method='POST' action='' class='ms-2'>
				<input type='hidden' name='action' value='unfav_author' />
				<input type='hidden' name='id' value='$author->avtorid' />
				<input type='hidden' name='csrf_token' value='" . htmlspecialchars(get_csrf_token()) . "' />
				<button type='submit' class='btn btn-sm btn-warning'>Из избранного</button>
			</form>";
			echo "</div>";
		}
		echo "</div></div>";
	}

	if (!empty($user_series)) {
		echo "<h5 class='mt-3 mb-2'>Серии</h5>";
		echo "<div class='card mb-3'><div class='card-body'>";
		foreach ($user_series as $series) {
			echo "<div class='d-flex align-items-center justify-content-between mb-2'>";
			echo "<a class='mw-100 text-dark' href='$webroot/?sid=$series->seqid'>$series->seqname</a>";
			echo "<form method='POST' action='' class='ms-2'>
				<input type='hidden' name='action' value='unfav_seq' />
				<input type='hidden' name='id' value='$series->seqid' />
				<input type='hidden' name='csrf_token' value='" . htmlspecialchars(get_csrf_token()) . "' />
				<button type='submit' class='btn btn-sm btn-warning'>Из избранного</button>
			</form>";
			echo "</div>";
		}
		echo "</div></div>";
	}

	if (empty($user_books) && empty($user_authors) && empty($user_series)) {
		echo "<div class='alert alert-secondary'>Ваше избранное пока пусто.</div>";
	}
} else {
	echo "<div class='alert alert-secondary'>Для показа личного избранного требуется вход в систему.</div>";
}

$lists = $dbh->prepare("SELECT list_uuid, name FROM fav_lists ORDER BY name");
$lists->execute();

while ($list = $lists->fetch()) {
	$legacy_books_stmt = $dbh->prepare("SELECT DISTINCT b.*
		FROM fav f
		JOIN libbook b USING(bookid)
		WHERE f.list_uuid=:uuid AND f.bookid IS NOT NULL
		ORDER BY b.time DESC");
	$legacy_books_stmt->bindParam(':uuid', $list->list_uuid);
	$legacy_books_stmt->execute();
	$legacy_books = $legacy_books_stmt->fetchAll();

	$legacy_authors_stmt = $dbh->prepare("SELECT DISTINCT a.AvtorId avtorid, a.LastName lastname, a.FirstName firstname, a.MiddleName middlename, a.NickName nickname, p.File file
		FROM fav f
		JOIN libavtorname a ON a.AvtorId=f.avtorid
		LEFT JOIN libapics p ON p.AvtorId=a.AvtorId
		WHERE f.list_uuid=:uuid AND f.avtorid IS NOT NULL
		ORDER BY a.LastName, a.FirstName, a.MiddleName, a.NickName");
	$legacy_authors_stmt->bindParam(':uuid', $list->list_uuid);
	$legacy_authors_stmt->execute();
	$legacy_authors = $legacy_authors_stmt->fetchAll();

	$legacy_series_stmt = $dbh->prepare("SELECT DISTINCT s.SeqId seqid, s.SeqName seqname
		FROM fav f
		JOIN libseqname s ON s.SeqId=f.seqid
		WHERE f.list_uuid=:uuid AND f.seqid IS NOT NULL
		ORDER BY s.SeqName");
	$legacy_series_stmt->bindParam(':uuid', $list->list_uuid);
	$legacy_series_stmt->execute();
	$legacy_series = $legacy_series_stmt->fetchAll();

	if (empty($legacy_books) && empty($legacy_authors) && empty($legacy_series)) {
		continue;
	}

	echo "<div class='card mb-3'>";
	echo "<div class='card-header d-flex justify-content-between align-items-center'>";
	echo "<div><strong>$list->name</strong></div>";
	echo "<div class='d-flex gap-2'>";
	if ($current_user_id > 0) {
		echo "<form method='POST' action=''>
			<input type='hidden' name='local_action' value='import_uuid' />
			<input type='hidden' name='list_uuid' value='$list->list_uuid' />
			<input type='hidden' name='csrf_token' value='" . htmlspecialchars(get_csrf_token()) . "' />
			<button type='submit' class='btn btn-sm btn-outline-primary'>Импортировать</button>
		</form>";
	}
	if ($is_admin) {
		echo "<form method='POST' action=''>
			<input type='hidden' name='local_action' value='delete_uuid' />
			<input type='hidden' name='list_uuid' value='$list->list_uuid' />
			<input type='hidden' name='csrf_token' value='" . htmlspecialchars(get_csrf_token()) . "' />
			<button type='submit' class='btn btn-sm btn-outline-danger'>Удалить список</button>
		</form>";
	}
	echo "</div>";
	echo "</div>";

	echo "<div class='card-body'>";
	if (!empty($legacy_books)) {
		echo "<h6 class='mb-2'>Книги</h6>";
		echo "<div class='contaner'><div class='row equal mb-2'>";
		foreach ($legacy_books as $book) {
			book_small_pg($book, $webroot);
		}
		echo "</div></div>";
	}

	if (!empty($legacy_authors)) {
		echo "<h6 class='mb-2'>Авторы</h6>";
		foreach ($legacy_authors as $author) {
			echo "<div class='d-flex align-items-center mb-2 gap-2'>";
			if (!empty($author->file)) {
				echo "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$author->avtorid' />";
			}
			echo "<a class='mw-100 rounded-pill author' href='$webroot/author/view/$author->avtorid'>$author->lastname $author->firstname $author->middlename $author->nickname</a>";
			echo "</div>";
		}
	}

	if (!empty($legacy_series)) {
		echo "<h6 class='mb-2 mt-2'>Серии</h6>";
		foreach ($legacy_series as $series) {
			echo "<div class='mb-2'><a class='mw-100 text-dark' href='$webroot/?sid=$series->seqid'>$series->seqname</a></div>";
		}
	}
	echo "</div>";
	echo "</div>";
}

?>