<?php
global $service_name;

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
		default:
			return htmlspecialchars($opname, ENT_QUOTES, 'UTF-8');
	}
}

function get_ds($path){
	$io = popen ( '/usr/bin/du -sk ' . $path, 'r' );
	$size = fgets ( $io, 4096);
	$size = substr ( $size, 0, strpos ( $size, "\t" ) );
	pclose ( $io );
	return round($size / 1024, 1);
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
	$qtotal = $dbh->query("SELECT (SELECT MAX(time) FROM libbook) mmod, (SELECT COUNT(*) FROM libbook) bcnt, (SELECT COUNT(*) FROM libbook WHERE deleted='0') bdcnt");
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
<tr><td><a class='btn btn-primary m-1' href='?import=sql'>Обновить базу</a></td>
<td><a class='btn btn-warning m-1' href='?empty=cache'>Очистить кэш</a></td></tr>
<tr><td><a class='btn btn-primary m-1' href='?download=sql'>Скачать базу</a></td>
<td><a class='btn btn-primary m-1' href='?reindex'>Сканирование ZIP</a></td></tr>
<tr><td><a class ='btn btn-primary m-1' href='?getcovers'>Скачать обложки</a></td>
<td><a class='btn  btn-primary m-1' href='?getdaily'>Скачать последние обновления</a></td></tr>
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
<li><b>Обновить базу</b> заполнить таблицы заново из дампов, скаченных при выполнении команды "Скачать базу"</li>
<li><b>Очистить кэш</b> стереть кэш авторов и облжек</li>
<li><b>Скачать базу</b> Скачать текущий дамп  базы данных Флибусты</li>
<li><b>Сканирование ZIP</b> Определить заново местоположение книг в ZIP файлаx</li>
<li><b>Скачать обложки</b> Скачать архивы обложек с Флибусты</li>
<li><b>Скачать последние обновления</b> Скачать последние добавленные книги с Флибусты в лркальный кэш. Для использования
 надо вручную переписать их в /flibusta и выполнить "Сканирование ZIP"</li>
</ul>
<p>
Иногда проходит несколько секунд до обновления страницы после нажатия кнопки операции. Это нормально, подождите немного.
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

if (isset($_GET['empty'])) {
	shell_exec('rm -f /cache/authors/*');
	shell_exec('rm -f /cache/covers/*');
	shell_exec('rm -f /cache/log/*');
	file_put_contents(ADMINOPSTATUSFILE, 'Очистка cache выполнена');
}

if (isset($_GET['getcovers'])) {
	shell_exec('stdbuf -o0 /tools/getcovers.sh  > '. ADMINOPSTATUSFILE.' &');
	
}


if (isset($_GET['import'])) {
		shell_exec('stdbuf -o0 /tools/app_import_sql.sh  > '. ADMINOPSTATUSFILE.' &');
		
}
if (isset($_GET['reindex'])) {
		shell_exec('stdbuf -o0 /tools/app_reindex.sh > '. ADMINOPSTATUSFILE.' &');		
}

if (isset($_GET['download'])) {
		shell_exec('stdbuf -o0 /tools/getsql.sh  > '. ADMINOPSTATUSFILE.' &');
}
	
if (isset($_GET['getdaily'])) {
		shell_exec('stdbuf -o0 /tools/update_daily.sh  > '. ADMINOPSTATUSFILE.' &');
}


