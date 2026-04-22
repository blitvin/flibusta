<?php
define('ROOT_PATH', '/application/');
define('CACHE_PATH', '/cache/');
define('SQL_PATH', '/sql/');
define('LIBRARY_PATH', '/flibusta/');
define('LOCAL_LIBRARY_PATH', '/cache/local/');
define('DBUPDATE_LOCK','/cache/locks/dbupdate.lock');
define('ADMINOPLOCKFILE','/cache/locks/adminop.lock');
define('ADMINOPSTATUSFILE','/cache/status');
define('TIMESTAPS_PATH','/cache/timestamps/');
define('RECORDS_PAGE', 10);
define('BOOKS_PAGE', 10);
define('AUTHORS_PAGE', 50);
define('SERIES_PAGE', 50);
define('OPDS_FEED_COUNT', 100);
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

define ('TRUSTED_NET', getenv("FLIBUSTA_TURSTED_NET")?? "");
define ('ADMIN_ACCESS_BY_HTTPS', getenv("FLIBUSTA_ALLOW_ADMIN_ACCESS_BY_HTTP") !== 'true' ? true : false);

session_set_cookie_params([ 'lifetime' => 3600 * 4 ,
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
$handler = new PostgresSessionHandler($dbh);
session_set_save_handler($handler, true);


//session_start();
$tz =  getenv('TZ');
if ($tz !== false){
    date_default_timezone_set($tz);
}
error_reporting(E_ALL);

$cdt = date('Y-m-d H:i:s');

