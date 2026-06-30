<?php
header('Content-Type: application/atom+xml; charset=utf-8');

$letters      = $_GET['letters'] ?? '';
$letters_lc   = mb_strtolower($letters, 'UTF-8');
$prefix_len   = mb_strlen($letters_lc, 'UTF-8');
$like_pattern = $letters_lc . '%';
$next_pos     = $prefix_len + 1;

echo '<?xml version="1.0" encoding="utf-8"?>';
$self_url = "$webroot/opds/authorsindex" . ($letters !== '' ? '?letters=' . urlencode($letters_lc) : '');
echo <<< _XML
 <feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="http://opds-spec.org/2010/catalog">
 <id>tag:root:authors</id>
 <title>Книги по авторам</title>
 <updated>$opds_updated</updated>
 <icon>/favicon.ico</icon>
 <link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
 <link href="$webroot/opds/authorsindex?letters={searchTerms}" rel="search" type="application/atom+xml" />
 <link href="$webroot/opds" rel="start" type="application/atom+xml;profile=opds-catalog" />
 <link href="$self_url" rel="self" type="application/atom+xml;profile=opds-catalog" />

_XML;

// Total authors whose lastname starts with the given prefix (case-insensitive)
$cntStmt = $dbh->prepare("SELECT COUNT(*) cnt
    FROM libavtorname
    WHERE LOWER(lastname) LIKE :pattern");
$cntStmt->bindValue(':pattern', $like_pattern);
$cntStmt->execute();
$total = (int)$cntStmt->fetchObject()->cnt;

// Helper: emit an individual author entry
$emitAuthor = function ($a) use ($webroot, $opds_updated) {
    $namex = htmlspecialchars(
        trim("$a->lastname $a->firstname $a->middlename $a->nickname"),
        ENT_QUOTES | ENT_XML1, 'UTF-8'
    );
    echo "\n<entry>";
    echo "<updated>$opds_updated</updated>";
    echo "<id>tag:author:" . intval($a->avtorid) . "</id>";
    echo "<title>$namex</title>";
    echo "<content type=\"text\">" . intval($a->book_cnt) . " книг</content>";
    echo "<link href=\"$webroot/opds/author?author_id=" . intval($a->avtorid)
        . "\" type=\"application/atom+xml;profile=opds-catalog\" />";
    echo "</entry>";
};

if ($total <= OPDS_AUTHORS_COUNT) {
    // All matching authors, ordered by book count descending
    $stmt = $dbh->prepare("SELECT an.*,
            (SELECT COUNT(*) FROM libavtor la JOIN libbook lb ON lb.bookid=la.bookid
             WHERE lb.deleted='0' AND la.avtorid=an.avtorid) book_cnt
        FROM libavtorname an
        WHERE LOWER(an.lastname) LIKE :pattern
        ORDER BY book_cnt DESC, an.lastname, an.firstname");
    $stmt->bindValue(':pattern', $like_pattern);
    $stmt->execute();
    while ($a = $stmt->fetchObject()) {
        $emitAuthor($a);
    }
} else {
    // Sub-prefix catalog entries: group by the next character after the current prefix.
    // Subquery derives next_char once so GROUP BY can reference it by name,
    // avoiding the $1/$2 mismatch that occurs when the same named param appears
    // in both SELECT and GROUP BY of a native prepared statement.
    $subStmt = $dbh->prepare("SELECT next_char, COUNT(*) AS cnt
        FROM (
            SELECT LOWER(SUBSTR(lastname, :np, 1)) AS next_char
            FROM libavtorname
            WHERE LOWER(lastname) LIKE :pattern
              AND CHAR_LENGTH(lastname) > :plen
        ) sub
        GROUP BY next_char
        ORDER BY next_char");
    $subStmt->bindValue(':np',      $next_pos,    PDO::PARAM_INT);
    $subStmt->bindValue(':pattern', $like_pattern);
    $subStmt->bindValue(':plen',    $prefix_len,  PDO::PARAM_INT);
    $subStmt->execute();

    while ($row = $subStmt->fetchObject()) {
        $sub_lc    = $letters_lc . $row->next_char;
        $sub_title = htmlspecialchars(mb_strtoupper($sub_lc, 'UTF-8'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $sub_enc   = urlencode($sub_lc);
        echo "\n<entry>";
        echo "<updated>$opds_updated</updated>";
        echo "<id>tag:authors:$sub_enc</id>";
        echo "<title>$sub_title</title>";
        echo "<content type=\"text\">" . intval($row->cnt) . " авторов на $sub_title</content>";
        echo "<link href=\"$webroot/opds/authorsindex?letters=$sub_enc\""
            . " type=\"application/atom+xml;profile=opds-catalog\" />";
        echo "</entry>";
    }

    // Authors whose lastname exactly equals the current prefix (won't appear in any sub-prefix)
    if ($prefix_len >= 1) {
        $exactStmt = $dbh->prepare("SELECT an.*,
                (SELECT COUNT(*) FROM libavtor la JOIN libbook lb ON lb.bookid=la.bookid
                 WHERE lb.deleted='0' AND la.avtorid=an.avtorid) book_cnt
            FROM libavtorname an
            WHERE LOWER(an.lastname) = :exact
            ORDER BY book_cnt DESC, an.firstname");
        $exactStmt->bindValue(':exact', $letters_lc);
        $exactStmt->execute();
        while ($a = $exactStmt->fetchObject()) {
            $emitAuthor($a);
        }
    }
}

echo '</feed>';
