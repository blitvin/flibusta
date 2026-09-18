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

// __DIR__ rather than ROOT_PATH: the CLI tools (merge_local_books.php,
// update_zip_list.php, sanitize_annotations.php) include this file directly,
// without init.php having run.
require_once __DIR__ . '/LazyPDO.php';

// Nothing connects here any more - the first statement opens the connection, and
// a failure there is reported exactly as it was reported from this spot before
// (log the driver message, 500, generic text). See LazyPDO::fail().
$dbh = new LazyPDO($dsn, $dbuser, $dbpasswd, [
	PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_EMULATE_PREPARES   => false,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
]);

?>