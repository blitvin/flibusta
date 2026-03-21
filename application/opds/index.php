<?php

// check whether DB is in the process of maintenance , return status 503 if yes
$filehandle = fopen(DBUPDATE_LOCK,"r");
if (flock($filehandle,LOCK_SH|LOCK_NB) === false) {
	http_response_code(503);
	die();
}
switch ($url->action) {
	case 'list':
		include('list.php');
		break;
	case 'authorsindex':
		include('authorsindex.php');
		break;
	case 'author':
		include('author.php');
		break;
	case 'sequencesindex':
		include('sequencesindex.php');
		break;
	case 'genres':
		include('genres.php');
		break;
	case 'listgenres':
		include('listgenres.php');
		break;
	case 'fav':
		include('fav.php');
		break;
	case 'favs':
		include('favs.php');
		break;
	case 'search':
		include('search.php');
		break;

	default:
		include('main.php');
}
