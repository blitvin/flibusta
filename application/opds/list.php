<?php
header('Content-Type: application/atom+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="utf-8"?>';

$filter = "deleted='0' ";
$join = '';
$orderby = ' time DESC ';
$title = 'в новинках';
$urlParams = [];

if (isset($_GET['genre_id'])) {
	$gid = intval($_GET['genre_id']);
	$filter .= 'AND genreid=:gid ';
	$join .= 'LEFT JOIN libgenre g USING(BookId) ';
	$orderby = ' time DESC ';
	$stmt = $dbh->prepare("SELECT * FROM libgenrelist
		WHERE genreid=:gid");
	$stmt->bindParam(":gid", $gid);
	$stmt->execute();
	$g = $stmt->fetch();
	$title = "в $g->genremeta: $g->genredesc";
	$urlParams[] = 'genre_id=' . $gid;
}

if (isset($_GET['seq_id'])) {
	$sid = intval($_GET['seq_id']);
	$filter .= 'AND seqid=:sid';
	$join .= 'LEFT JOIN libseq s USING(BookId) ';
	$orderby = " s.seqnumb ";
	$stmt = $dbh->prepare("SELECT * FROM libseqname
		WHERE seqid=:sid");
	$stmt->bindParam(":sid", $sid);
	$stmt->execute();
	$s = $stmt->fetch();
	$title = "в сборнике $s->seqname";
	$urlParams[] = 'seq_id=' . $sid;
}

if (isset($_GET['author_id'])) {
	$aid = intval($_GET['author_id']);
	$filter .= 'AND avtorid=:aid ';
	$join .= 'JOIN libavtor USING (bookid) JOIN libavtorname USING (avtorid) ';

	$display_type = (isset($_GET['display_type']))? ($_GET['display_type'] ?? '') : '';
	if ($display_type == 'sequenceless') {
		$filter .= 'AND s.seqid is null ';
		$join .= ' LEFT JOIN libseq s ON s.bookId= b.bookId ';
		$orderby = ' time DESC ';
	} else if ($display_type == 'year'){
		$orderby = ' year ';
	} else if ($display_type == 'alphabet') {
		$orderby = ' title ';
	} else {
		$orderby = ' time DESC ';
	}
	$stmt = $dbh->prepare("SELECT * FROM libavtorname WHERE avtorid=:aid");
	$stmt->bindParam(":aid", $aid);
	$stmt->execute();
	$a = $stmt->fetch();
	$title = ($a->nickname !='')?"$a->firstname $a->middlename $a->lastname ($a->nickname)"
			:"$a->firstname  $a->middlename $a->lastname";
	$urlParams[] = 'author_id=' . $aid;
	if ($display_type !== '') $urlParams[] = 'display_type=' . urlencode($display_type);
}

$page = max(0, intval($_GET['page'] ?? 0));
$offset = $page * OPDS_FEED_COUNT;

$countStmt = $dbh->prepare("SELECT COUNT(DISTINCT b.bookid) FROM libbook b $join WHERE $filter");
if (isset($gid)) $countStmt->bindParam(":gid", $gid);
if (isset($sid)) $countStmt->bindParam(":sid", $sid);
if (isset($aid)) $countStmt->bindParam(":aid", $aid);
$countStmt->execute();
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / OPDS_FEED_COUNT));

$baseUrl = $webroot . '/opds/list' . (count($urlParams) ? '?' . implode('&amp;', $urlParams) : '');
$sep = count($urlParams) ? '&amp;' : '?';
$selfUrl  = $baseUrl . $sep . 'page=' . $page;
$firstUrl = $baseUrl . $sep . 'page=0';
$lastUrl  = $baseUrl . $sep . 'page=' . ($totalPages - 1);

echo <<< _XML
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="http://opds-spec.org/2010/catalog">
<id>tag:root:home</id>
_XML;
echo "<title>Книги $title</title>";
echo "<updated>$opds_updated</updated>";
echo "<os:totalResults>$totalCount</os:totalResults>";
echo "<os:itemsPerPage>" . OPDS_FEED_COUNT . "</os:itemsPerPage>";
echo "<os:startIndex>" . ($offset + 1) . "</os:startIndex>";
echo "\n<icon>/favicon.ico</icon>";
echo "\n<link href=\"$webroot/opds-opensearch.xml.php\" rel=\"search\" type=\"application/opensearchdescription+xml\" />";
echo "\n<link href=\"$webroot/opds/search?q={searchTerms}\" rel=\"search\" type=\"application/atom+xml\" />";
echo "\n<link href=\"$webroot/opds/\" rel=\"start\" type=\"application/atom+xml;profile=opds-catalog\" />";
echo "\n<link href=\"$selfUrl\" rel=\"self\" type=\"application/atom+xml;profile=opds-catalog\" />";
echo "\n<link href=\"$firstUrl\" rel=\"first\" type=\"application/atom+xml;profile=opds-catalog\" />";
echo "\n<link href=\"$lastUrl\" rel=\"last\" type=\"application/atom+xml;profile=opds-catalog\" />";
if ($page > 0) {
	$prevUrl = $baseUrl . $sep . 'page=' . ($page - 1);
	echo "\n<link href=\"$prevUrl\" rel=\"previous\" type=\"application/atom+xml;profile=opds-catalog\" />";
}
if ($page < $totalPages - 1) {
	$nextUrl = $baseUrl . $sep . 'page=' . ($page + 1);
	echo "\n<link href=\"$nextUrl\" rel=\"next\" type=\"application/atom+xml;profile=opds-catalog\" />";
}

$books = $dbh->prepare("SELECT b.*
	FROM libbook b
	$join
	WHERE
	$filter
	ORDER BY $orderby
	LIMIT " . OPDS_FEED_COUNT . " OFFSET $offset");

if (isset($gid)) $books->bindParam(":gid", $gid);
if (isset($sid)) $books->bindParam(":sid", $sid);
if (isset($aid)) $books->bindParam(":aid", $aid);

$books->execute();

while ($b = $books->fetch()) {
	opds_book($b, $webroot);
}

echo "</feed>";
?>
