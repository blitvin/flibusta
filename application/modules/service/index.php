<?php
global $service_name;
global $service_csrf_token;

function get2serviceName() {
	if (! isset($_GET['opname']))
		return 'не орпеделена';
	return serviceName2Label($_GET['opname']);
}
function serviceName2Label($opname) {
	switch($opname) {
		case 'empty':
			return "Очистить кэш";
		case 'getcovers':
			return "Скачать обложки";
		case 'import':
			return  "Обновить базу";
		case 'reindex':
			return "Сканирование ZIP";
		case 'download':
			return "Скачать базу";
		case 'getdaily':
			return "Скачать последние обновления";
		case 'unlockdb':
			return "Выйти из режима техобслуживания";
		default:
			return htmlspecialchars($opname, ENT_QUOTES, 'UTF-8');
	}
}

function get_ds($path){
	$io = popen ( '/usr/bin/du -sk ' . escapeshellarg($path), 'r' );
	$size = fgets ( $io, 4096);
	$size = substr ( $size, 0, strpos ( $size, "\t" ) );
	pclose ( $io );
	return round($size / 1024, 1);
}

function serviceActionButton($action, $label, $class, $token) {
	$safeAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
	$safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
	$safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
	echo "<form method='POST' style='display:inline;'>";
	echo "<input type='hidden' name='csrf_token' value='$safeToken'>";
	echo "<button class='btn $class m-1' type='submit' name='$safeAction'>$safeLabel</button>";
	echo "</form>";
}

if ($service_name !== false) { 
	// обработка нажатия на кнопку команды
	echo "<h4 class='rounded-top p-1' style='background: #d0d0d0;'>Команда ".serviceName2Label($service_name)." начинает выполнение</h4>";
	echo "<p>Через секунду страница начнет показ выполнения команды или, если команда быстро выполнится, вернется к дэшборду";
}
elseif ($command_running) {
	// частичный output выполнения
	echo "<h4 class='rounded-top p-1' style='background: #d0d0d0;'>Выполнение команды &quot;".get2serviceName()."&quot;</h4>";
	$op = file_get_contents(ADMINOPSTATUSFILE);
	echo "<div class='d-flex align-items-center m-3'>";
	echo nl2br(htmlspecialchars($op, ENT_QUOTES, 'UTF-8'));
	echo "<div class='spinner-border ms-auto' role='status' aria-hidden='true'></div></div>";
}  else { 
	// нет текущей команды
	echo <<< __HTML
<div class='row'>
<div class="col-sm-6">
<div class='card'>
<h4 class="rounded-top p-1" style="background: #d0d0d0;">Статистика</h4>
<div class='card-body'>
__HTML;
	$cache_size = get_ds(CACHE_PATH."covers") + get_ds(CACHE_PATH."authors");
	$books_size = round(get_ds(LIBRARY_PATH) / 1024, 1);
	$qtotal = $dbh->query("SELECT (SELECT MAX(time) FROM libbook) mmod, 
	(SELECT COUNT(*) FROM libbook) bcnt, (SELECT COUNT(*) FROM libbook WHERE deleted='0') bdcnt");
	$qtotal->execute();
	$total = $qtotal->fetch();

	
	echo "<table class='table'><tbody>";
	echo "<tr><td>Актуальность базы:</td><td>$total->mmod</td></tr>";
	echo "<tr><td>Всего произведений:</td><td>$total->bcnt</td></tr>";
	echo "<tr><td>Размер архива:</td><td>$books_size Gb</td></tr>";
	echo "<tr><td>Размер кэша:</td><td>$cache_size Mb</td></tr>";
	echo "<tr><td>Обложки скачены:</td><td>".nl2br(htmlspecialchars(file_get_contents(TIMESTAPS_PATH.'getcovers'), ENT_QUOTES, 'UTF-8'))."</td></tr>";
	echo "<tr><td>Проверка добавлений:</td><td>".nl2br(htmlspecialchars(file_get_contents(TIMESTAPS_PATH.'update_daily'), ENT_QUOTES, 'UTF-8'))."</td></tr>";
	echo "<tr><td>БД Флибусты скачена :</td><td>".nl2br(htmlspecialchars(file_get_contents(TIMESTAPS_PATH.'getsql'), ENT_QUOTES, 'UTF-8'))."</td></tr>";
	echo "<tr><td>Последний скан ZIP:</td><td>".nl2br(htmlspecialchars(file_get_contents(TIMESTAPS_PATH.'app_reindex'), ENT_QUOTES, 'UTF-8'))."</td></tr>";
	echo "</tbody></table>";
	echo <<< __HTML
</div>
</div>
</div>

<div class="col-sm-6">
<div class='card'>
<h4 class="rounded-top p-1" style="background: #d0d0d0;">Операции</h4>
<div class='card-body'>
<table class='table'><tbody>
<tr><td>
<?php serviceActionButton('import', 'Обновить базу данных', 'btn-primary', $service_csrf_token); ?>
</td><td>
<?php serviceActionButton('empty', 'Очистить кэш', 'btn-warning', $service_csrf_token); ?>
</td></tr>
<tr><td>
<?php serviceActionButton('download', 'Скачать базу данных', 'btn-warning', $service_csrf_token); ?>
</td><td>
<?php serviceActionButton('reindex', 'Сканирование ZIP', 'btn-primary', $service_csrf_token); ?>
</td></tr>
<tr><td>
<?php serviceActionButton('getcovers', 'Скачать обложки', 'btn-warning', $service_csrf_token); ?>
</td><td>
<?php serviceActionButton('getdaily', 'Скачать последние обновления', 'btn-warning', $service_csrf_token); ?>
</td></tr>
</tbody></table>
</div>
</div>
</div>
</div>

<div class='row'>
<div class="col-sm-12 mt-3">
<div class='card'>
<div class='card-body'>
<p>
Краткая справка по операциям
<ul>
<li><b>Обновить базу данных</b> заполнить таблицы БД заново из дампов, скаченных при выполнении команды "Скачать базу данных"</li>
<li><b>Очистить кэш</b> стереть кэш авторов и облжек</li>
<li><b>Скачать базу данных</b> Скачать текущий дамп  базы данных Флибусты</li>
<li><b>Сканирование ZIP</b> Определить заново местоположение книг в ZIP файлаx</li>
<li><b>Скачать обложки</b> Скачать архивы обложек с Флибусты</li>
<li><b>Скачать последние обновления</b> Скачать последние добавленные книги с Флибусты в локальный кэш. Чтобы новые книги стали доступны, запустите "Сканирование ZIP"</li>
</ul>
<p>
Иногда проходит несколько секунд до обновления страницы после нажатия кнопки операции. Это нормально, подождите немного.
<p>
Синие кнопки запускают операции изменяющие базу данных. Пока они не завершатся, библиотека в состоянии техобслуживания, 
получение новых книг, поиск и т.п. приостанавливаются. Желтые кнопки запускают операции, не влияющие на нормальную работу библиотеки.
<p>

LOG последней выполненной команды 
__HTML;
if (isset($_GET['opname'])){
	echo '('.serviceName2Label($_GET['opname']).')';
}
echo '<p>';
$op = file_get_contents(ADMINOPSTATUSFILE);;
	echo "<div class='d-flex align-items-center m-3'>";
	echo nl2br(htmlspecialchars($op, ENT_QUOTES, 'UTF-8'));
	echo "</div></div></div></div></div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service_name !== false) {
	switch ($service_name) {
		case 'empty':
			shell_exec('rm -f /cache/authors/*');
			shell_exec('rm -f /cache/covers/*');
			shell_exec('rm -f /cache/log/*');
			file_put_contents(ADMINOPSTATUSFILE, 'Очистка cache выполнена');
			break;
		case 'getcovers':
			shell_exec('stdbuf -o0 /tools/getcovers.sh  > '. ADMINOPSTATUSFILE.' &');
			break;
		case 'import':
			shell_exec('stdbuf -o0 /tools/app_import_sql.sh  > '. ADMINOPSTATUSFILE.' &');
			break;
		case 'reindex':
			shell_exec('stdbuf -o0 /tools/app_reindex.sh > '. ADMINOPSTATUSFILE.' &');
			break;
		case 'download':
			shell_exec('stdbuf -o0 /tools/getsql.sh  > '. ADMINOPSTATUSFILE.' &');
			break;
		case 'getdaily':
			shell_exec('stdbuf -o0 /tools/update_daily.sh  > '. ADMINOPSTATUSFILE.' &');
			break;
	}
}


