<?php
if ($url->mod !== 'service') {
	// check for db update - issue 503 status if update is in progress
	$filehandle = fopen(DBUPDATE_LOCK,"r");
	if (flock($filehandle,LOCK_SH|LOCK_NB) === false) {
		header('Refresh: 30');
		header('Content-Type: text/html; charset=utf-8');
		http_response_code(503);
		include_once(ROOT_PATH . 'webroot.php');
		echo <<< __HTML
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Техобслуживание — Библиотека</title>
<link href="$webroot/bootstrap/css/bootstrap.min.css" rel="stylesheet">
</head>
<body style="background-color: #343a40;">
<div class="container py-5">
<div class="card mx-auto shadow" style="max-width: 36rem;">
<div class="card-body text-center p-4">
<div class="spinner-border text-secondary mb-3" role="status" aria-hidden="true"></div>
<h1 class="h4">Идёт техническое обслуживание</h1>
<p class="mb-1">Библиотека обновляет базу данных и временно недоступна.</p>
<p class="text-muted mb-0">Страница обновляется сама каждые 30 секунд и откроется, как только обслуживание закончится.</p>
</div>
</div>
</div>
</body>
</html>
__HTML;
		die();
	}
}
?>
<!doctype html>
<html lang="ru">
<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

<?php
	if ($url->description != '') {
		echo "<meta name='description' content='$url->description' />";
	}

	if ($url->title != '') {
		$title = $url->title;
	} else {
		$title = 'Библиотека';
	}
	// $url->title is DB-origin for the book module (book/module.conf sets it from
	// libbook.title), and "</title><script>" would break out of RCDATA.
	echo "<title>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</title>";
	include_once(ROOT_PATH . 'webroot.php');
	$style_css = asset_url($webroot, 'css/style.css');
echo <<< __HTML

<link href="$webroot/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<script src="$webroot/bootstrap/js/bootstrap.bundle.min.js"></script>

<link rel="icon" href="$webroot/favicon.svg" sizes="any" type="image/svg+xml">

<link href="$webroot/css/all.min.css" rel="stylesheet">
<link href="$style_css" rel="stylesheet">
__HTML
?>
<style>
.pagination>li.active>a {
  background-color: #777 !important;
  border-color: #6d6d6d !important;
}

.badge {
    white-space: break-spaces;
}

.author {
        background: #dddddd;
}

.author a {
	color: #333;
	line-height: 24px;
	padding-left: 3px;
}

.contact {
         width: 24px;
         height: 24px;
}

</style>

</head>
<?php
// Active menu entry, keyed by menu item. Bootstrap 5 highlights
// .nav-link/.dropdown-item.active, so each $a[...] is spliced into the link's
// single-quoted class attribute and also closes it with aria-current.
$navItems = ['books' => ['primary', 'book'], 'authors' => ['authors', 'author'],
	'series' => ['series'], 'genres' => ['genres'], 'fav' => ['fav', 'favlist'],
	'help' => ['help'], 'service' => ['service'], 'users' => ['users'],
	'addbook' => ['addbook'], 'settings' => ['settings']];
$a = [];
foreach ($navItems as $key => $mods) {
	$a[$key] = in_array($url->mod, $mods, true) ? "active' aria-current='page" : '';
}
$adminActive = in_array($url->mod, ['service', 'users', 'addbook'], true) ? 'active' : '';

$is_admin = !empty($_SESSION['is_admin']);

echo <<< __HTML

<body style='background-color: #343a40;'>

<div class="container whb">
<nav class="navbar navbar-expand-lg navbar-dark rounded-bottom shadow" style="background-color: #3e3b6c;">
<div class="container-fluid">
  <a class="navbar-brand" href="$webroot/" title="Библиотека">
   &nbsp;Библиотека
  </a>
  <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Меню">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="collapse navbar-collapse" id="mainNav">
		<ul class="navbar-nav me-auto">
			<li class="nav-item"><a class='nav-link {$a['books']}' href="$webroot/">Книги</a></li>
			<li class="nav-item"><a class='nav-link {$a['authors']}' href="$webroot/authors/">Авторы</a></li>
			<li class="nav-item"><a class='nav-link {$a['series']}' href="$webroot/series/">Серии</a></li>
			<li class="nav-item"><a class='nav-link {$a['genres']}' href="$webroot/genres/">Жанры</a></li>
			<li class="nav-item"><a class='nav-link {$a['fav']}' href="$webroot/fav/">Полка</a></li>
			<li class="nav-item"><a class='nav-link {$a['help']}' href="$webroot/help/">Справка</a></li>

__HTML;

if ($is_admin) {
echo  <<< __HTML
			<li class="nav-item dropdown">
				<a class="nav-link dropdown-toggle $adminActive" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Администрирование</a>
				<ul class="dropdown-menu">
					<li><a class='dropdown-item {$a['service']}' href="$webroot/service/">Сервис</a></li>
					<li><a class='dropdown-item {$a['users']}' href="$webroot/users/">Пользователи</a></li>
					<li><a class='dropdown-item {$a['addbook']}' href="$webroot/addbook/">Добавить книги</a></li>
				</ul>
			</li>
__HTML;
}

echo "\t\t</ul>\n<div class='d-flex align-items-center'>\n";
if (! empty($_SESSION['username'])) {
	$user_name = htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8');
	$badge = $is_admin ? 'btn-warning' : 'btn-outline-info';
	$settings_item = (! empty($_SESSION['user_id']))
		? "<li><a class='dropdown-item {$a['settings']}' href='$webroot/settings/'>Настройки</a></li>"
		: '';
	echo <<< __HTML
<div class="dropdown">
	<button class="btn $badge btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">$user_name</button>
	<ul class="dropdown-menu dropdown-menu-lg-end">
		$settings_item
		<li><a class="dropdown-item" href="$webroot/login.php">Сменить пользователя</a></li>
	</ul>
</div>
__HTML;
} else {
	echo "<a class='btn btn-outline-light btn-sm' href='$webroot/login.php'>Войти</a>\n";
}
echo <<< __HTML
</div>
  </div>
</div>
</nav>
</div>
<div class="container whb">
<br />

__HTML;
try{
	if (file_exists($url->module)) {
	//	echo "<h1>$url->title</h1>";
		include($url->module);
	} else {
		echo 'Раздел не найден', 'Вы ввели неверный адрес, либо раздел находится в разработке.';
		header("HTTP/1.0 404 Not Found");
	}
}
finally {
		if ( isset($filehandle)) {
			flock($filehandle,LOCK_UN);
			fclose($filehandle);
		}
}
?>
<div>&nbsp;</div>
</div>



<footer class="container whb rounded-bottom mb-3 py-2 text-center small site-footer">
	<a href="<?= $webroot ?>/help/">Справка</a>
	<span class="mx-2" aria-hidden="true">·</span>
	<a href="<?= $webroot ?>/help/#opds" title="Как подключить библиотеку в приложении-читалке">OPDS для читалок</a>
</footer>




</body>
</html>

