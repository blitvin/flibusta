<?php
header('Content-Type: application/atom+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="utf-8"?>';echo "\n";
echo '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="https://specs.opds.io/opds-1.2">';

$x = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');

$author_id = intval($_GET['author_id'] ?? 0);
if ($author_id === 0)
    die('author.php called without specifying id');

$seq_mode = isset($_GET['seq']);
if (! $seq_mode)  {
    $stmt = $dbh->prepare("SELECT a.LastName as LastName, a.MiddleName as MiddleName, a.FirstName as FirstName, a.NickName as NickName,
        aa.Body as Body,  p.File as picFile
        from libavtorname a
        LEFT JOIN libaannotations aa on a.avtorid = aa.avtorid
        LEFT JOIN libapics p on a.avtorid=p.avtorid
        where a.avtorID=:authorid ");
} else {
    $stmt = $dbh->prepare("SELECT LastName, MiddleName, FirstName, NickName from libavtorname where avtorID=:authorid ");
}

$stmt->bindParam(':authorid', $author_id);
$stmt->execute();
if ($a = $stmt->fetchObject()){
    $author_name = ($a->nickname !='')?"$a->firstname $a->middlename $a->lastname ($a->nickname)"
                            :"$a->firstname  $a->middlename $a->lastname";
    $author_name_x = $x($author_name);
    $aid_x = intval($author_id);

    if ($seq_mode) {
        echo "<id>tag:author:$aid_x:sequences</id>";
        echo "<title>$author_name_x : Книги по сериям</title>";
        echo "<updated>$opds_updated</updated>";
        echo <<< _XML
        <icon>/favicon.ico</icon>
        <link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
        <link href="$webroot/opds/search?by=author&amp;searchTerm={searchTerms}" rel="search" type="application/atom+xml" />
        <link href="$webroot/opds" rel="start" type="application/atom+xml;profile=opds-catalog" />
        _XML;
        $sequences = $dbh->prepare("SELECT distinct sn.seqid seqid, sn.seqname seqname
        from libseqname sn, libseq s, libavtor a
        where sn.seqid = s.seqid and s.bookId= a.bookId and a.avtorId= :aid");
        $sequences->bindParam(":aid", $author_id);
        $sequences->execute();
        while($seq = $sequences->fetchObject()){
            echo "<entry>\n";
            echo "<updated>$opds_updated</updated>\n";
            echo "<id>tag:sequence:" . intval($seq->seqid) . "</id>\n";
            echo "<title>" . $x($seq->seqname ?? '') . "</title>\n";
            echo "<link href=\"$webroot/opds/list?seq_id=" . intval($seq->seqid) . "\" type=\"application/atom+xml;profile=opds-catalog\" />\n";
            echo "</entry>\n";
        }
        $sequences = null;
    } else {
        echo "<id>tag:author:$aid_x</id>";
        echo "<title>$author_name_x</title>";
        echo "<updated>$opds_updated</updated>";
        echo <<< _XML
        <icon>/favicon.ico</icon>
        <link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
        <link href="$webroot/opds/search?by=author&amp;searchTerm={searchTerms}" rel="search" type="application/atom+xml" />
        <link href="$webroot/opds" rel="start" type="application/atom+xml;profile=opds-catalog" />

        _XML;
        if (!empty($a->body)) {
            echo "<entry>\n";
            echo "<updated>$opds_updated</updated>\n";
            echo "<id>tag:author:bio:$aid_x</id>\n";
            echo "<title>Об авторе</title>\n";
            if (!is_null($a->picfile)){
                echo "<link href=\"$webroot/extract_author.php?id=$aid_x\" rel=\"http://opds-spec.org/image\" type=\"image/jpeg\" />\n";
                echo "<link href=\"$webroot/extract_author.php?id=$aid_x\" rel=\"http://opds-spec.org/image/thumbnail\" type=\"image/jpeg\" />\n";
            }
            $bioHtml = bbc2html(htmlspecialchars($a->body, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            echo "<content type=\"text/html\"><![CDATA[$bioHtml]]></content>\n";
            echo "<link href=\"$webroot/author/view/$aid_x\" rel=\"alternate\" type=\"text/html\" title=\"Страница автора на сайте\" />\n";
            echo "<link href=\"$webroot/opds/list?author_id=$aid_x&amp;display_type=alphabet\" rel=\"http://www.feedbooks.com/opds/facet\" type=\"application/atom+xml;profile=opds-catalog\" title=\"Книги автора по алфавиту\" />\n";
            echo "<link href=\"$webroot/opds/author?author_id=$aid_x&amp;seq=1\" rel=\"http://www.feedbooks.com/opds/facet\" type=\"application/atom+xml;profile=opds-catalog\" title=\"Книжные серии с произведениями автора\" />\n";
            echo "<link href=\"$webroot/opds/list?author_id=$aid_x&amp;display_type=sequenceless\" rel=\"http://www.feedbooks.com/opds/facet\" type=\"application/atom+xml;profile=opds-catalog\" title=\"Книги автора вне серий\" />\n";
            echo "</entry>\n";
        }
        echo <<< _XML
        <entry>
        <updated>$opds_updated</updated>
        <title>Все книги автора (без сортировки)</title>
        <id>tag:author:$aid_x:list</id>
        <link href="$webroot/opds/list?author_id=$aid_x" type="application/atom+xml;profile=opds-catalog" />
        </entry>
        <entry>
        <updated>$opds_updated</updated>
        <title>Книги автора по алфавиту</title>
        <id>tag:author:$aid_x:alphabet</id>
        <link href="$webroot/opds/list?author_id=$aid_x&amp;display_type=alphabet" type="application/atom+xml;profile=opds-catalog" />
        </entry>
        <entry>
        <updated>$opds_updated</updated>
        <title>Книги автора по году издания</title>
        <id>tag:author:$aid_x:year</id>
        <link href="$webroot/opds/list?author_id=$aid_x&amp;display_type=year" type="application/atom+xml;profile=opds-catalog" />
        </entry>
        <entry>
        <updated>$opds_updated</updated>
        <title>Книжные серии с произведениями автора</title>
        <id>tag:author:$aid_x:sequences</id>
        <link href="$webroot/opds/author?author_id=$aid_x&amp;seq=1" type="application/atom+xml;profile=opds-catalog" />
        </entry>
        <entry>
        <updated>$opds_updated</updated>
        <title>Произведения вне серий</title>
        <id>tag:author:$aid_x:sequenceless</id>
        <link href="$webroot/opds/list?author_id=$aid_x&amp;display_type=sequenceless" type="application/atom+xml;profile=opds-catalog" />
        </entry>
        _XML;
    }
}
else
    die("author with id $author_id not found in the data base");
$stmt = null;
?>
</feed>
