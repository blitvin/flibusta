<?php
$dbname = getenv('FLIBUSTA_DBNAME')?getenv('FLIBUSTA_DBNAME'):'flibusta';
$dbhost = getenv('FLIBUSTA_DBHOST')?getenv('FLIBUSTA_DBHOST'):'postgres';
$dbuser = getenv('FLIBUSTA_DBUSER')?getenv('FLIBUSTA_DBUSER'):'flibusta';
if (getenv('FLIBUSTA_DBPASSWORD_FILE')){
		$dbpasswd = file_get_contents(getenv('FLIBUSTA_DBPASSWORD_FILE'));
		if ($dbpasswd) $dbpasswd = trim($dbpasswd);
}
if (empty($dbpasswd))
	$dbpasswd = getenv('FLIBUSTA_DBPASSWORD')?getenv('FLIBUSTA_DBPASSWORD'):'flibusta';
/*
Because of usage of trigram and other postgress specific features, it's not looks like any other db type 
 is goign to be supported in the foreseable future
 
$dbtype = getenv('FLIBUSTA_DBTYPE')?trim(strtolower(getenv('FLIBUSTA_DBTYPE'))):'postgres';
if ($dbtype != 'postgres') { // check for valid type, currently only postgress is supported, but in the future others e.g. mysql will be added
	error_log('unsupported db type '.$dbtype.', reverting to postgress');
	$dbtype = 'postgres';
}
$dsn = match($dbtype) {
	'postgres' => "pgsql:host=".$dbhost.";dbname=".$dbname.";options='--client_encoding=UTF8'",
	// dsn for supported db types should be added here
	default => "pgsql:host=".$dbhost.";dbname=".$dbname.";options='--client_encoding=UTF8'"
};
*/
$dsn = "pgsql:host=".$dbhost.";dbname=".$dbname.";options='--client_encoding=UTF8'";

if (PHP_SAPI === 'cli') {
	// CLI tools (update_zip_list.php, merge_local_books.php, sanitize_annotations.php,
	// migrate_positions_to_files.php) query immediately and some of them type-hint
	// PDO, so they get a real connection right away.
	try {
		$dbh = new PDO($dsn, $dbuser, $dbpasswd);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$dbh->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		$dbh->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
	} catch(Exception $e) {
		// L3: never leak connection details (DSN/host) to output.
		error_log('Flibusta DB connection failed: ' . $e->getMessage());
		http_response_code(500);
		die('Database temporarily unavailable.');
	}
} else {
	// Web requests connect on first query only: cache hits, already-extracted
	// covers and file-resolved downloads never touch Postgres at all.
	include_once(ROOT_PATH . 'LazyPDO.php');
	$dbh = new LazyPDO($dsn, $dbuser, $dbpasswd);
}

?>