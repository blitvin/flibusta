<?php
header('Content-Type: application/atom+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="utf-8"?>';
echo <<< _XML
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="http://opds-spec.org/2010/catalog">
<id>tag:root:home</id>
<title>Книги по авторам</title>
_XML;
echo "<updated>$cdt</updated>";
echo <<< _XML
<icon>/favicon.ico</icon>
<link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
<link href="$webroot/opds/search?q={searchTerms}" rel="search" type="application/atom+xml" />
<link href="$webroot/opds/" rel="start" type="application/atom+xml;profile=opds-catalog" />
_XML;
echo '<link href="' . $webroot . '/opds/fav/" rel="self" type="application/atom+xml;profile=opds-catalog" />';

$user = $_SERVER['PHP_AUTH_USER'] ?? null;
$pass = $_SERVER['PHP_AUTH_PW'] ?? null;
if (empty($user) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
	$auth = explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));
	$user = $auth[0] ?? null;
	$pass = $auth[1] ?? null;
}

$user_id = 0;
if (!empty($user) && !empty($pass)) {
	$u = $dbh->prepare("SELECT id, password_hash FROM users WHERE username=:username LIMIT 1");
	$u->bindParam(':username', $user);
	$u->execute();
	if ($ud = $u->fetch()) {
		if (password_verify($pass, $ud->password_hash)) {
			$user_id = intval($ud->id);
		}
	}
}

$books = $dbh->prepare("SELECT DISTINCT b.*
		FROM fav f
		LEFT JOIN libbook b USING(bookid)
		WHERE user_id=:uid AND f.bookid IS NOT NULL");
$books->bindParam(":uid", $user_id);
$books->execute();

while ($b = $books->fetch()) {
	opds_book($b, $webroot);
}

echo "</feed>";
?>
