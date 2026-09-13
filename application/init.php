<?php
// Private self-hosted library: keep every response out of search indexes.
// Complements public/robots.txt and covers all dynamic responses regardless of
// which web server fronts php-fpm (in the external_services config nginx is external).
if (!headers_sent()) {
	header('X-Robots-Tag: noindex, nofollow');
}
define('ROOT_PATH', '/application/');
define('CACHE_PATH', '/cache/');
define('SQL_PATH', '/sql/');
define('LIBRARY_PATH', '/flibusta/');
define('LOCAL_LIBRARY_PATH', '/cache/local/');
define('DBUPDATE_LOCK','/cache/locks/dbupdate.lock');
define('ADMINOPLOCKFILE','/cache/locks/adminop.lock');
define('ADMINOPSTATUSFILE','/cache/status');
define('TIMESTAPS_PATH','/cache/timestamps/');
// First id assigned to locally added books/authors (addbook module). Flibusta
// dump ids are far below this, so `id >= LOCAL_ID_BASE` identifies local
// records and `id < LOCAL_ID_BASE` restricts a query to dump content.
// Must stay in sync with local_book_id_seq / local_author_id_seq (see
// tools/postgres_init.sql) and with the guarded define in
// tools/merge_local_books.php, which runs standalone under the CLI.
define('LOCAL_ID_BASE', 10000000);
define('RECORDS_PAGE', 10);
// Upper bound on a user's personal hidden-genre list. Also bounds the inlined
// IN (...) list built in modules/primary/index.php.
define('MAX_EXCLUDED_GENRES', 100);
define('BOOKS_PAGE', 10);
define('AUTHORS_PAGE', 50);
define('SERIES_PAGE', 50);
define('OPDS_FEED_COUNT', 100);
define('OPDS_AUTHORS_COUNT', 50);
define('COUNT_BOOKS', true);
include(ROOT_PATH . 'functions.php');
include(ROOT_PATH . 'dbinit.php');
include_once(ROOT_PATH . 'webroot.php');
$strFb2size = getenv('MAX_FB2_SIZE_2_DISPLAY');
if (is_numeric($strFb2size) && (((int)$strFb2size) > 1000000)) {
    define('MAX_FB2_SIZE_2_DISPLAY',(int)$strFb2size);
} else {
    define('MAX_FB2_SIZE_2_DISPLAY', 100000000);
}

$_trustedNet = getenv("FLIBUSTA_TRUSTED_NET") ?: "";
if ($_trustedNet !== '' && !isValidIpOrSubnet($_trustedNet)) {
    error_log("Flibusta: FLIBUSTA_TRUSTED_NET value '$_trustedNet' is not a valid IPv4 address or CIDR subnet — ignoring.");
    $_trustedNet = "";
}
define('TRUSTED_NET', $_trustedNet);
unset($_trustedNet);
define('ADMIN_ACCESS_BY_HTTPS', getenv("FLIBUSTA_ALLOW_ADMIN_ACCESS_BY_HTTP") !== 'true' ? true : false);
define('FLIBUSTA_URL', getenv('FLIBUSTA_URL') ?: 'https://flibusta.is');
define('FLIBUSTA_MISSING_BOOK_DOWNLOAD', getenv('FLIBUSTA_ENABLE_MISSING_BOOK_DOWNLOAD') !== 'false');

$isTrustedClient = (TRUSTED_NET !== '') && ipInNetwork($_SERVER['REMOTE_ADDR'] ?? '', TRUSTED_NET);
session_set_cookie_params([ 'lifetime' => $isTrustedClient ? 3600 * 24 * 365 : 3600 * 4,
                            'path' => $webroot != "" ? $webroot : "/",
                            'domain' => '',
                            'secure' => ADMIN_ACCESS_BY_HTTPS,
                            'httponly' => true,
                            'samesite' => 'Lax']);

ini_set('session.serialize_handler', 'php_serialize');
if (ADMIN_ACCESS_BY_HTTPS) {
    ini_set('session.cookie_secure', '1');   // Only send cookie over HTTPS
}
ini_set('session.cookie_secure', '1');   // Only send cookie over HTTPS
ini_set('session.cookie_httponly', '1'); // Prevent Javascript from stealing the cookie
ini_set('session.use_only_cookies', '1');

include_once __DIR__ . '/PostgresSessionHandler.php';
$handler = new PostgresSessionHandler($dbh, TRUSTED_NET);
session_set_save_handler($handler, true);


//session_start();
$tz =  getenv('TZ');
if ($tz !== false){
    date_default_timezone_set($tz);
}
error_reporting(E_ALL);

$cdt = date('Y-m-d H:i:s');
$opds_updated = date(DateTime::RFC3339);

