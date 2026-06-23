<?php
header('Content-Type: application/atom+xml; charset=utf-8');

$letters = $_GET['letters'] ?? '';

if ($letters !== '') {
    $length_letters = mb_strlen($letters, 'UTF-8');
} else {
    $length_letters = 0;
}

echo '<?xml version="1.0" encoding="utf-8"?>';
echo <<< _XML
 <feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="http://opds-spec.org/2010/catalog"> <id>tag:root:authors</id>
 <title>Книги по авторам</title>
 <updated>$opds_updated</updated>
 <icon>/favicon.ico</icon>
 <link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
 <link href="$webroot/opds/authorsindex?letters={searchTerms}" rel="search" type="application/atom+xml" />
 <link href="$webroot/opds" rel="start" type="application/atom+xml;profile=opds-catalog" />\n
_XML;

$substr_len = $length_letters + 1;
$pattern = $letters . '[A-ZА-Я]';
$query = "
	SELECT alpha, COUNT(*) as cnt
	FROM (
		SELECT UPPER(SUBSTR(LastName, 1, ?)) as alpha
		FROM libavtorname
	) sub
	WHERE alpha SIMILAR TO ?
	GROUP BY alpha
	ORDER BY alpha";
$ai = $dbh->prepare($query);
$ai->execute([$substr_len, $pattern]);
while ($ach = $ai->fetchObject()) {
	$alphax = htmlspecialchars($ach->alpha, ENT_QUOTES | ENT_XML1, 'UTF-8');
	echo "\n<entry>";
	echo "<updated>$opds_updated</updated>";
	echo "<id>tag:authors:" . urlencode($ach->alpha) . "</id>";
	echo "<title>$alphax</title>";
	echo "<content type=\"text\">" . intval($ach->cnt) . " авторов на $alphax</content>";
	if ($ach->cnt > OPDS_AUTHORS_COUNT) {
		$url = "$webroot/opds/authorsindex?letters=" . urlencode($ach->alpha);
	} else {
		$url = "$webroot/opds/search?by=author&amp;q=" . urlencode($ach->alpha);
	}
	echo "<link href=\"$url\" type=\"application/atom+xml;profile=opds-catalog\" />";
	echo "</entry>";
}
echo '</feed>';
?>
