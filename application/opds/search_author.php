<?php
header('Content-Type: application/atom+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="utf-8"?>';
echo <<< _XML
 <feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="http://opds-spec.org/2010/catalog"> <id>tag:root:authors</id>
 <title>Поиск по авторам</title>
 <updated>$opds_updated</updated>
 <icon>/favicon.ico</icon>
 <link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
 <link href="$webroot/opds/authorsindex?letters={searchTerm}" rel="search" type="application/atom+xml" />
 <link href="$webroot/opds" rel="start" type="application/atom+xml;profile=opds-catalog" />

<entry>
 <updated>$opds_updated</updated>
 <id>tag:search:author</id>
 <title>Поиск авторов</title>
 <content type="text">Поиск авторов по фамилии</content>
 <link href="$webroot/opds/authorsindex?letters={searchTerm}" type="application/atom+xml;profile=opds-catalog" />
</entry>
_XML;

$q = $_GET['q'] ?? '';

if ($q === '') {
	die(':(');
}
$queryParam = $q . '%';
$authors = $dbh->prepare("SELECT *,
		(SELECT COUNT(*) FROM libavtor, libbook WHERE
		libbook.deleted='0' AND
		libbook.bookid=libavtor.bookid AND
		libavtor.avtorid=libavtorname.avtorid) cnt
		FROM libavtorname
		WHERE lastname ILIKE :q ORDER BY lastname, firstname");
$authors->bindParam(":q", $queryParam);
$authors->execute();
while ($a = $authors->fetch()) {
	if ($a->cnt > 0) {
		$namex = htmlspecialchars(trim("$a->lastname $a->firstname $a->middlename $a->nickname"), ENT_QUOTES | ENT_XML1, 'UTF-8');
		$cntStmt = $dbh->prepare("SELECT COUNT(*) as cnt FROM libbook JOIN libavtor USING(bookid) WHERE deleted='0' AND avtorid=:aid");
		$cntStmt->bindParam(':aid', $a->avtorid);
		$cntStmt->execute();
		$books_cnt = intval($cntStmt->fetch()->cnt);
		echo "\n<entry>";
		echo "<updated>$opds_updated</updated>";
		echo "<id>tag:author:" . intval($a->avtorid) . "</id>";
		echo "<title>$namex</title>";
		echo "<content type=\"text\">$books_cnt книг</content>";
		echo "<link href=\"$webroot/opds/author?author_id=" . intval($a->avtorid) . "\" type=\"application/atom+xml;profile=opds-catalog\" />";
		echo "</entry>";
	}
}
?>
</feed>
