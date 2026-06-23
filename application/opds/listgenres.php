<?php
header('Content-Type: application/atom+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="utf-8"?>';
$categoryTitle = htmlspecialchars($_GET['id'] ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
echo '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="http://opds-spec.org/2010/catalog"><id>tag:root</id>';
echo "<title>Жанры в $categoryTitle</title>";
echo "<updated>$opds_updated</updated>";
echo  <<< _XML
 <icon>/favicon.ico</icon>
 <link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
 <link href="$webroot/opds/search?q={searchTerms}" rel="search" type="application/atom+xml" />
 <link href="$webroot/opds/" rel="start" type="application/atom+xml;profile=opds-catalog" />

 _XML;

$gs = $dbh->prepare("SELECT *,
	(SELECT COUNT(*) FROM libgenre WHERE libgenre.genreid=g.genreid) cnt
	FROM libgenrelist g
	WHERE g.genremeta=:id");
$gs->bindParam(":id", $_GET['id']);
$gs->execute();

while ($g = $gs->fetch()) {
	$descx = htmlspecialchars($g->genredesc, ENT_QUOTES | ENT_XML1, 'UTF-8');
	echo "<entry>";
	echo "<updated>$opds_updated</updated>";
	echo "<id>tag:genre:" . htmlspecialchars($g->genrecode, ENT_QUOTES | ENT_XML1, 'UTF-8') . "</id>";
	echo "<title>$descx</title>";
	echo "<content type=\"text\">Книг: " . intval($g->cnt) . "</content>";
	echo "<link href=\"$webroot/opds/list/?genre_id=" . intval($g->genreid) . "\" type=\"application/atom+xml;profile=opds-catalog\" />";
	echo "</entry>\n";
}
echo '</feed>';
?>
