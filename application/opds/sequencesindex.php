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
 <title>Книги по сериям</title>
 <updated>$opds_updated</updated>
 <icon>/favicon.ico</icon>
 <link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
 <link href="$webroot/opds/authorsindex?letters={searchTerms}" rel="search" type="application/atom+xml" />
 <link href="$webroot/opds" rel="start" type="application/atom+xml;profile=opds-catalog" />\n
_XML;

$query="
	SELECT UPPER(SUBSTR(SeqName, 1, ".($length_letters + 1).")) as alpha, COUNT(*) as cnt
	FROM libseqname
	WHERE UPPER(SUBSTR(SeqName, 1, ".($length_letters + 1).")) SIMILAR TO :pattern
	GROUP BY UPPER(SUBSTR(SeqName, 1, ".($length_letters + 1)."))
	ORDER BY alpha";
$ai = $dbh->prepare($query);
$bindparam1 = $letters."_";
$ai->bindParam(":pattern",$bindparam1);
$ai->execute();
while ($ach = $ai->fetchObject()) {
	$alphax = htmlspecialchars($ach->alpha, ENT_QUOTES | ENT_XML1, 'UTF-8');
    if ($ach->cnt > 30) {
	    echo "\n<entry>";
	    echo "<updated>$opds_updated</updated>";
	    echo "<id>tag:sequences:" . urlencode($ach->alpha) . "</id>";
	    echo "<title>$alphax</title>";
	    echo "<content type=\"text\">" . intval($ach->cnt) . " книжных серий на $alphax</content>";
        echo "<link href=\"$webroot/opds/sequencesindex?letters=" . urlencode($ach->alpha) . "\" type=\"application/atom+xml;profile=opds-catalog\" />";
	    echo "</entry>";
	} else {
        $sq = $dbh->prepare("SELECT SeqName, SeqId
                from libseqname
                where UPPER(SUBSTR(SeqName, 1, ".($length_letters + 1).")) = :pattern
                ORDER BY UPPER(SeqName)");
        $sq->bindParam(":pattern", $ach->alpha);
        $sq->execute();
        while($s = $sq->fetchObject()){
            echo "\n<entry>";
	        echo "<updated>$opds_updated</updated>";
	        echo "<id>tag:sequence:" . intval($s->seqid) . "</id>";
            echo "<title>" . htmlspecialchars($s->seqname, ENT_QUOTES | ENT_XML1, 'UTF-8') . "</title>";
            echo "<content type=\"text\"></content>";
	        echo "<link href=\"$webroot/opds/list?seq_id=" . intval($s->seqid) . "\" type=\"application/atom+xml;profile=opds-catalog\" />";
	        echo "</entry>";
        }
        $sq = null;
	}
}
echo '</feed>';
?>
