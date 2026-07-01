<?php

// CSRF token helpers
function generate_csrf_token() {
	if (!isset($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['csrf_token'];
}

function get_csrf_token() {
	if (!isset($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
	if (!isset($_SESSION['csrf_token'])) {
		return false;
	}
	return hash_equals($_SESSION['csrf_token'], $token);
}

function bbc2html($content) {
  $search = array (
    '/(\[b\])(.*?)(\[\/b\])/',
    '/(\[i\])(.*?)(\[\/i\])/',
    '/(\[u\])(.*?)(\[\/u\])/',
    '/(\[ul\])(.*?)(\[\/ul\])/',
    '/(\[li\])(.*?)(\[\/li\])/',
    '/(\[url=)(.*?)(\])(.*?)(\[\/url\])/',
    '/(\[url\])(.*?)(\[\/url\])/'
  );

  $replace = array (
    '<strong>$2</strong>',
    '<em>$2</em>',
    '<u>$2</u>',
    '<ul>$2</ul>',
    '<li>$2</li>',
    '<a href="$2" target="_blank">$4</a>',
    '<a href="$2" target="_blank">$2</a>'
  );

  return preg_replace($search, $replace, $content);
}


function show_gpager($page_count, $block_size = 100) {
	$page = isset($_GET['page']) ? intval($_GET['page']) : 0;
	if ($page_count <= 1) return;

	$b1 = $page - $block_size;
	$b2 = $block_size + $page;
	if ($b1 < 1)           $b1 = 1;
	if ($b2 > $page_count) $b2 = $page_count;

	$display_page = $page + 1;

	echo "<nav class='d-flex align-items-center flex-wrap gap-1 my-1'>";
	echo "<ul class='pagination pagination-sm mb-0'>";

	// First page
	$dis = ($page == 0) ? ' disabled' : '';
	echo "<li class='page-item$dis'><a class='page-link' href='?page=0' title='Первая страница'>"
	   . "<i class='fas fa-angle-double-left'></i></a></li>";

	// Previous block
	if ($b1 > 1) {
		echo "<li class='page-item'><a class='page-link' href='?page=", $b1 - 2,
		     "' title='Предыдущие'><i class='fas fa-angle-left'></i></a></li>";
	}

	// Numbered pages
	for ($p = $b1; $p <= $b2; $p++) {
		$active = ($p == $display_page) ? ' active' : '';
		echo "<li class='page-item$active'><a class='page-link' href='?page=", $p - 1, "'>$p</a></li>";
	}

	// Next block
	if ($b2 < $page_count) {
		echo "<li class='page-item'><a class='page-link' href='?page=$b2'"
		   . " title='Следующие'><i class='fas fa-angle-right'></i></a></li>";
	}

	// Last page
	$dis = ($page == $page_count - 1) ? ' disabled' : '';
	echo "<li class='page-item$dis'><a class='page-link' href='?page=", $page_count - 1,
	     "' title='Последняя страница'><i class='fas fa-angle-double-right'></i></a></li>";

	echo "</ul>";

	// Jump-to-page: onsubmit converts 1-based display value to 0-based page param
	echo "<form class='d-inline-flex align-items-center ms-2' method='get'"
	   . " onsubmit=\"this.elements['page'].value=Math.max(0,Math.min($page_count-1,"
	   . "parseInt(this.elements['pdisp'].value||1)-1));return true;\">"
	   . "<input type='hidden' name='page' value='$page'>"
	   . "<small class='text-muted me-1'>Стр.</small>"
	   . "<input type='number' name='pdisp' class='form-control form-control-sm' style='width:4.5rem'"
	   . " min='1' max='$page_count' value='$display_page'>"
	   . "<small class='text-muted mx-1'>/ $page_count</small>"
	   . "<button type='submit' class='btn btn-sm btn-outline-secondary'>→</button>"
	   . "</form>";

	echo "</nav>";
}



function pg_array_parse($literal){
    if ($literal == '') return;
    preg_match_all('/(?<=^\{|,)(([^,"{]*)|\s*"((?:[^"\\\\]|\\\\(?:.|[0-9]+|x[0-9a-f]+))*)"\s*)(,|(?<!^\{)(?=\}$))/i', $literal, $matches, PREG_SET_ORDER);
    $values = [];
    foreach ($matches as $match) {
        $values[] = $match[3] != '' ? stripcslashes($match[3]) : (strtolower($match[2]) == 'null' ? null : $match[2]);
    }
    return $values;
}

function to_pg_array($set) {
    settype($set, 'array'); // can be called with a scalar or array
    $result = array();
    foreach ($set as $t) {
        if (is_array($t)) {
            $result[] = to_pg_array($t);
        } else {
			if ($t === null) {
				$result[] = 'NULL';
				continue;
			}

			$t = (string)$t;
			if (!is_numeric($t)) {
				// PostgreSQL array string literal escaping: backslash and quote only.
				$t = strtr($t, array('\\' => '\\\\', '"' => '\\"'));
				$t = '"' . $t . '"';
			}
			$result[] = $t;
        }
    }
    return '{' . implode(",", $result) . '}'; // format
}


function book_small_pg($book, $webroot='',$full = false) {
	global $dbh;
	if (!isset($book->bookid)) {
		return;
	}
	$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
	echo "<div class='col-sm-2 col-6 mb-3'>";
	echo "<div style='height: 100%' class='cover rounded text-center d-flex align-items-end flex-column'>";
	echo "<a class='w-100' href='$webroot/book/view/$book->bookid'>";
	echo "<img class='w-100 card-image rounded-top' src='$webroot/extract_cover.php?sid=$book->bookid' />";

	$dt =DateTime::createFromFormat('Y-m-d H:i:se', $book->time)->format('Y-m-d');
	if (trim($book->filetype) == 'fb2') {
		$ft = 'success';
		$fhref = "$webroot/fb2.php?id=$book->bookid";
	} else {
		$ft = 'secondary';
		$fhref = "$webroot/usr.php?id=$book->bookid";
	}

	if ($book->year != 0) {
		$year = $book->year;
	} else {
		$year = $dt;
	}

	$show_fav_button = false;
	$fav = 'btn-outline-secondary';
	$fav_action = 'fav_book';
	if ($current_user_id > 0) {
		$show_fav_button = true;
		$stmt = $dbh->prepare("SELECT COUNT(*) cnt FROM fav WHERE user_id=:uid AND bookid=:id");
		$stmt->bindParam(":uid", $current_user_id);
		$stmt->bindParam(":id", $book->bookid);
		$stmt->execute();
		if ($stmt->fetch()->cnt > 0) {
			$fav = 'btn-primary';
			$fav_action = 'unfav_book';
		}
	}

	echo "<div>$book->title</div></a>";
	echo "<div class='btn-group w-100 mt-auto' role='group'>";
	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$year</button>";
	echo "<a href='$fhref' title='Скачать' type='button' class='btn btn-outline-$ft btn-sm'>$book->filetype</a>";
//	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$book->lang</button>";
	if ($show_fav_button) {
		$fav_id = $book->bookid;
		echo "<form method='POST' action='' style='display:inline;'>
			<input type='hidden' name='action' value='$fav_action' />
			<input type='hidden' name='id' value='$fav_id' />
			<input type='hidden' name='csrf_token' value='" . htmlspecialchars(get_csrf_token()) . "' />
			<button type='submit' title='В избранное' class='btn $fav btn-sm'><i class='fas fa-heart'></i></button>
		</form>";
	}
	
	echo "</div></div></div>\n";
}

function book_info_pg($book, $webroot = '', $full = false) {
	global $dbh;
	if (!isset($book->bookid)) {
		return;
	}
	$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
	echo "<div class='hic card mb-3' itemscope='' itemtype='http://schema.org/Book'>";
//	echo "<div class='card-header'>";
	echo "<h4 class='rounded-top' style='background: #d0d0d0;'><a class='book-link' href='$webroot/book/view/$book->bookid'><i class='fas'></i> $book->title</h4></a>";
//	echo "</div>";
	echo "<div class='card-body'>";
	echo "<div class='row'>";
	echo "<div class='col-sm-2'>";
	echo "<img class='w-100 card-image rounded cover' src='$webroot/extract_cover.php?sid=$book->bookid' />";

	$dt =DateTime::createFromFormat('Y-m-d H:i:se', $book->time)->format('Y-m-d');
	if (trim($book->filetype) == 'fb2') {
		$ft = 'success';
		$fhref = "$webroot/fb2.php?id=$book->bookid";
	} else {
		$ft = 'secondary';
		$fhref = "$webroot/usr.php?id=$book->bookid";
	}

	if ($book->year != 0) {
		$year = $book->year;
	} else {
		$year = $dt;
	}
	

	echo "<div class='btn-group w-100 mt-1' role='group'>";
	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$year</button>";
	echo "<a href='$fhref' title='Скачать' type='button' class='btn btn-outline-$ft btn-sm'>$book->filetype</a>";
//	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$book->lang</button>";
	if ($current_user_id > 0) {
		$stmt = $dbh->prepare("SELECT COUNT(*) cnt FROM fav WHERE user_id=:uid AND bookid=:id");
		$stmt->bindParam(":uid", $current_user_id);
		$stmt->bindParam(":id", $book->bookid);
		$stmt->execute();
		if ($stmt->fetch()->cnt > 0) {
			$fav = 'btn-primary';
			$fav_action = 'unfav_book';
		} else {
			$fav = 'btn-outline-secondary';
			$fav_action = 'fav_book';
		}
		echo "<form method='POST' action='' style='display:inline;'>
			<input type='hidden' name='action' value='$fav_action' />
			<input type='hidden' name='id' value='$book->bookid' />
			<input type='hidden' name='csrf_token' value='" . htmlspecialchars(get_csrf_token()) . "' />
			<button type='submit' title='В избранное' class='btn $fav btn-sm'><i class='fas fa-heart'></i></button>
		</form>";
	}
	echo "</div>";
	
	echo "</div><div class='col-sm-10'>";
	echo "<div class='authors-list'>";
	$stmt = $dbh->prepare("SELECT AvtorId, LastName, FirstName, nickname, middlename, File FROM libavtor a
		LEFT JOIN libavtorname USING(AvtorId)
		LEFT JOIN libapics USING(AvtorId)
		WHERE a.BookId=:id");
	$stmt->bindParam(":id", $book->bookid);
	$stmt->execute();
	while ($a = $stmt->fetch()) {
		echo "<div class='badge rounded-pill author'>";
		if ($a->file != '') {
			echo "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$a->avtorid' />";	
		}
		echo "<a href='$webroot/author/view/$a->avtorid'>$a->lastname $a->firstname $a->middlename $a->nickname</a>";
		echo "</div>";
	}
	echo "</div>";


	echo "<div style='margin-bottom: 3px;'>";
	$genres = $dbh->prepare("SELECT GenreId, GenreDesc FROM libgenre 
		JOIN libgenrelist USING(GenreId)
		WHERE BookId=:bookid");
	$genres->bindParam(":bookid", $book->bookid);
	$genres->execute();
	while ($g = $genres->fetch()) {
		echo "<a class='badge bg-success p-1 text-white' href='$webroot/?gid=$g->genreid'>$g->genredesc</a> ";
	}
	echo "</div>";
	
	echo "<div style='margin-bottom: 3px;'>";
	$seq = $dbh->prepare("SELECT SeqId, SeqName, SeqNumb FROM libseq
				JOIN libseqname USING(SeqId)
				WHERE BookId=:id");
	$seq->bindParam(":id", $book->bookid);
	$seq->execute();
	while ($s = $seq->fetch()) {
		echo "<a class='badge bg-danger p-1 text-white' href='$webroot/?sid=$s->seqid'>$s->seqname ";
		if ($s->seqnumb > 0) {
			echo " $s->seqnumb";
		}
		echo "</a> ";
	}
	echo "</div>";

	echo "<div style='margin-bottom: 3px;'>";
	if ($book->keywords != '') {
		$kw = explode(",", $book->keywords);
		foreach ($kw as $k) {
			echo "<a class='badge bg-secondary p-1 text-white' href='#'>$k</a> ";
		}
	}
	echo "</div>";

	echo "<div style='font-size: 0.8em;'>";
	if (isset($book->body)) {
		if ($full) {
			echo "<p>" . trim($book->body) . "</p>";
		} else {
			echo "<p>" . cut_str(trim(strip_tags($book->body))) . "</p>";
		}
	}
	echo "</div>";

	echo "</div>";
	echo "</div>";
	echo "</div></div>\n";
}

//date_default_timezone_set('Europe/Minsk');
//date_default_timezone_set('Etc/GMT-3');
$mytimezone = getenv('TZ')?getenv('TZ'):'UTC';
date_default_timezone_set($mytimezone);
setlocale(LC_ALL, 'rus_RUS');

$m_time = explode(" ",microtime());
$m_time = $m_time[0] + $m_time[1];
$starttime = $m_time;
$sql_time = 0;


$cdt = date('Y-m-d H:i:s');
$today_from =  date('Y-m-d') . ' 00:00:00';
$today_to   = date('Y-m-d') . ' 23:59:59';


function russian_date() {
 $translation = array(
 "am" => "дп",
 "pm" => "пп",
 "AM" => "ДП",
 "PM" => "ПП",
 "Monday" => "Понедельник",
 "Mon" => "Пн",
 "Tuesday" => "Вторник",
 "Tue" => "Вт",
 "Wednesday" => "Среда",
 "Wed" => "Ср",
 "Thursday" => "Четверг",
 "Thu" => "Чт",
 "Friday" => "Пятница",
 "Fri" => "Пт",
 "Saturday" => "Суббота",
 "Sat" => "Сб",
 "Sunday" => "Воскресенье",
 "Sun" => "Вс",
 "January" => "Января",
 "Jan" => "Янв",
 "February" => "Февраля",
 "Feb" => "Фев",
 "March" => "Марта",
 "Mar" => "Мар",
 "April" => "Апреля",
 "Apr" => "Апр",
 "May" => "Мая",
 "May" => "Мая",
 "June" => "Июня",
 "Jun" => "Июн",
 "July" => "Июля",
 "Jul" => "Июл",
 "August" => "Августа",
 "Aug" => "Авг",
 "September" => "Сентября",
 "Sep" => "Сен",
 "October" => "Октября",
 "Oct" => "Окт",
 "November" => "Ноября",
 "Nov" => "Ноя",
 "December" => "Декабря",
 "Dec" => "Дек",
 "st" => "ое",
 "nd" => "ое",
 "rd" => "е",
 "th" => "ое",
 );
 if (func_num_args() > 1) {
	$timestamp = func_get_arg(1);
	return strtr(date(func_get_arg(0), $timestamp), $translation);
 } else {
	return strtr(date(func_get_arg(0)), $translation);
 };
}
/***************************************************************************/
function transliterate($string){
  $cyr=array(
     "Щ", "Ш", "Ч","Ц", "Ю", "Я", "Ж","А","Б","В",
     "Г","Д","Е","Ё","З","И","Й","К","Л","М","Н",
     "О","П","Р","С","Т","У","Ф","Х","Ь","Ы","Ъ",
     "Э","Є", "Ї","І",
     "щ", "ш", "ч","ц", "ю", "я", "ж","а","б","в",
     "г","д","е","ё","з","и","й","к","л","м","н",
     "о","п","р","с","т","у","ф","х","ь","ы","ъ",
     "э","є", "ї","і", " "
  );
  $lat=array(
     "Shch","Sh","Ch","C","Yu","Ya","J","A","B","V",
     "G","D","e","e","Z","I","y","K","L","M","N",
     "O","P","R","S","T","U","F","H","", 
     "Y","" ,"E","E","Yi","I",
     "shch","sh","ch","c","Yu","Ya","j","a","b","v",
     "g","d","e","e","z","i","y","k","l","m","n",
     "o","p","r","s","t","u","f","h",
     "", "y","" ,"e","e","yi","i", "%20"
  );
  for($i=0; $i<count($cyr); $i++)  {
     $c_cyr = $cyr[$i];
     $c_lat = $lat[$i];
     $string = str_replace($c_cyr, $c_lat, $string);
  }
  $string = 
  	preg_replace(
  		"/([qwrtpsdfghklzxcvbnmQWRTPSDFGHKLZXCVBNM]+)[jJ]e/", 
  		"\${1}e", $string);
/*  $string = 
  	preg_replace(
  		"/([qwrtpsdfghklzxcvbnmQWRTPSDFGHKLZXCVBNM]+)[jJ]/", 
  		"\${1}'", $string);*/
  $string = preg_replace("/([eyuioaEYUIOA]+)[Kk]h/", "\${1}h", $string);
  $string = preg_replace("/^kh/", "h", $string);
  $string = preg_replace("/^Kh/", "H", $string);
  return $string;
}


function stars($rating, $webroot) {
    $fullStar = '<img alt="1" class="star" src="'.$webroot.'/i/s1.png" />';
    $emptyStar = '<img alt="0" class="star" src="'.$webroot.'/i/s0.png" />';
    $rating = $rating <= 5?$rating:5;
    $fullStarCount = (int)$rating;
    $emptyStarCount = 5 - $fullStarCount;
    $html = str_repeat($fullStar,$fullStarCount);
    $html .= str_repeat($emptyStar,$emptyStarCount);
    echo $html;
}

/***************************************************************************/
function cut_str($string, $maxlen=700) {
    $len = (mb_strlen($string) > $maxlen)
        ? mb_strripos(mb_substr($string, 0, $maxlen), ' ')
        : $maxlen
    ;
    $cutStr = mb_substr($string, 0, $len);
    return (mb_strlen($string) > $maxlen)
        ? $cutStr . '...'
        : $cutStr
    ;
}

/***************************************************************************/
function cut_str2($string, $maxlen=700) {
    $len = (mb_strlen($string) > $maxlen)
        ? mb_strripos(mb_substr($string, 0, $maxlen), ' ')
        : $maxlen
    ;
    $cutStr = mb_substr($string, 0, $len);
    return $cutStr . $len;
}

/***************************************************************************/
function clean_str($input) {
  if (!$input)
	return $input;

  $input = strip_tags($input);

  $input = str_replace ("\n"," ", $input);
  $input = str_replace ("\r","", $input);

  $input = preg_replace("/[^(\w)|(\x7F-\xFF)|^(_,\-,\.,\;,\@)|(\s)]/", " ", $input);

  return $input;
}

/***************************************************************************/
function decode_gurl($webroot,$mobile = false)  {
  global $last_modified, $url, $robot;
  global $sex_post;

 
  $urlx = parse_url(urldecode($_SERVER['REQUEST_URI']));

  //remove leading webroot e.g. http://192.168.1.101/flibusta/authors/index.php should produce module= authors
  // note this assumes path is not utf-8
  $path = $urlx['path'];
  if (!empty($webroot) && str_starts_with($path,$webroot) ) {
		$path = substr($path, strlen($webroot));
  }
  list($x, $module, $action, $var1, $var2, $var3) = array_pad(explode('/', $path), 6, null);

	// Normalize common front-controller paths to root route.
	if (is_string($module) && ($module === 'index.php' || $module === 'index')) {
		$module = '';
	}

  $url = new stdClass();

	$url->mod = sanitize_route_token($module);
	$url->action = sanitize_route_token($action);
  $url->var1 = intval($var1);
  $url->var2 = intval($var2);
  $url->var3 = intval($var3); 
  $url->title = '';
  $url->description = '';
  $url->mod_path = '';
  $url->mod_menu = '';
  $url->image = '';
  $url->noindex = 0;
  $url->index = 1;
  $url->follow = 1;
  $url->module_menu = '';
  $url->js = array();
  $url->editor = 0;
  $url->access = 0;
  $url->canonical = '';

  $menu = true;

  if ($url->mod == '') {
    $url->mod ='primary';
  }

	if (!in_array($url->mod, allowed_route_modules(), true)) {
		$url->mod = '404';
	}

  if (file_exists(ROOT_PATH . 'modules/' . $url->mod . '/module.conf')) {
    $last_modified = gmdate('D, d M Y H:i:s', filemtime(ROOT_PATH . 'modules/' . $url->mod . '/index.php')) . ' GMT';
    $url->module = ROOT_PATH . 'modules/' . $url->mod . '/index.php';
    $url->mod_path = ROOT_PATH . 'modules/' . $url->mod . '/';
  } else {
    $menu = false;
    include(ROOT_PATH . 'modules/404/module.conf');
    $url->module = ROOT_PATH . 'modules/404/index.php';
    $url->mod = '404';  
  }

  if ($url->access > 0) {
   // if (!is_admin()) {
      include(ROOT_PATH . 'modules/403/module.conf');
      $url->module = ROOT_PATH . 'modules/403/index.php';
      $url->mod = '403';
      $menu = false;
   // }
  }

  if ( (file_exists(ROOT_PATH . 'modules/' . $url->mod . '/module_menu.php')) && ($menu) ) {
    $url->module_menu = ROOT_PATH . 'modules/' . $url->mod . '/module_menu.php';
  }

  return $url;
}

function sanitize_route_token($token) {
	if (!is_string($token) || $token === '') {
		return '';
	}
	$token = strtolower($token);
	return preg_match('/^[a-z0-9_]+$/', $token) ? $token : '';
}

function allowed_route_modules() {
	return array(
		'primary',
		'book',
		'author',
		'authors',
		'series',
		'genres',
		'fav',
		'favlist',
		'help',
		'opds',
		'users',
		'service',
		'settings',
		'404',
	);
}

function safe_str($str) {
        return ($str)?preg_replace("/[^A-Za-z0-9 -_]/", '', $str):$str;
}


function mobile() {
        $devices = array(
                "android" => "android.*mobile",
                "androidtablet" => "android(?!.*mobile)",
                "iphone" => "(iphone|ipod)",
                "ipad" => "(ipad)",
                "generic" => "(kindle|mobile|mmp|midp|pocket|psp|symbian|smartphone|treo|up.browser|up.link|vodafone|wap|opera mini)"
        );
        $isMobile = false;
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
                $userAgent = $_SERVER['HTTP_USER_AGENT'];
        } else {
                $userAgent = "";
        }
        if (isset($_SERVER['HTTP_ACCEPT'])) {
               $accept = $_SERVER['HTTP_ACCEPT'];
        } else {
                $accept = '';
        }
        if (isset($_SERVER['HTTP_X_WAP_PROFILE']) || isset($_SERVER['HTTP_PROFILE'])) {
                $isMobile = true;
        } elseif (strpos($accept, 'text/vnd.wap.wml') > 0 || strpos($accept, 'application/vnd.wap.xhtml+xml') > 0) {
                $isMobile = true;
        } else {
                foreach ($devices as $device => $regexp) {
                        if (preg_match("/" . $devices[strtolower($device)] . "/i", $userAgent)) {
                                $isMobile = true;
                        }
                }
        }
        return $isMobile;
}

function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824)
        {
            $bytes = number_format($bytes / 1073741824, 2) . ' GB';
        }
        elseif ($bytes >= 1048576)
        {
            $bytes = number_format($bytes / 1048576, 2) . ' MB';
        }
        elseif ($bytes >= 1024)
        {
            $bytes = number_format($bytes / 1024, 2) . ' KB';
        }
        elseif ($bytes > 1)
        {
            $bytes = $bytes . ' bytes';
        }
        elseif ($bytes == 1)
        {
            $bytes = $bytes . ' byte';
        }
        else
        {
            $bytes = '0 bytes';
        }

        return $bytes;
    }

function opds_filetype_mime(string $filetype): string {
	static $map = [
		'fb2'  => 'application/fb2',
		'epub' => 'application/epub+zip',
		'pdf'  => 'application/pdf',
		'djvu' => 'image/vnd.djvu',
		'doc'  => 'application/msword',
		'txt'  => 'text/plain',
		'rtf'  => 'application/rtf',
		'mobi' => 'application/x-mobipocket-ebook',
		'chm'  => 'application/vnd.ms-htmlhelp',
	];
	return $map[strtolower(trim($filetype))] ?? 'application/octet-stream';
}

function opds_book($b,$webroot = '') {
	global $dbh;
	$x = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');

	try {
		$updated = (new DateTime($b->time))->format(DateTime::RFC3339);
	} catch (Exception $e) {
		$updated = $b->time;
	}

	echo "\n<entry><updated>" . $x($updated) . "</updated>";
	echo "<id>tag:book:" . intval($b->bookid) . "</id>";
	echo "<title>" . $x($b->title) . "</title>";

	$ann = $dbh->prepare("SELECT body annotation FROM libbannotations WHERE bookid=:id LIMIT 1");
	$ann->bindParam(":id", $b->bookid);
	$ann->execute();
	$an = ($tmp = $ann->fetch()) ? $tmp->annotation : '';

	$genres = $dbh->prepare("SELECT genrecode, GenreId, GenreDesc FROM libgenre
		JOIN libgenrelist USING(GenreId)
		WHERE bookid=:id");
	$genres->bindParam(":id", $b->bookid);
	$genres->execute();
	while ($g = $genres->fetch()) {
		echo "<category term=\"" . $x($webroot . '/subject/' . urlencode($g->genrecode)) . "\" label=\"" . $x($g->genredesc) . "\"/>";
	}

	$sq = '';
	$seq = $dbh->prepare("SELECT SeqId, SeqName, SeqNumb FROM libseq
		JOIN libseqname USING(SeqId)
		WHERE BookId=:id");
	$seq->bindParam(":id", $b->bookid);
	$seq->execute();
	while ($s = $seq->fetch()) {
		$ssq = $s->seqname;
		if ($s->seqnumb > 0) {
			$ssq .= " ($s->seqnumb) ";
		}
		$sq .= $ssq;
		echo "<link href=\"" . $x("$webroot/opds/list?seq_id=" . intval($s->seqid)) . "\" rel=\"related\" type=\"application/atom+xml\" title=\"" . $x("Все книги серии «$ssq»") . "\" />";
	}
	if ($sq != '') {
		$sq = "Сборник: $sq";
	}

	
	$au = $dbh->prepare("SELECT AvtorId, LastName, FirstName, nickname, middlename, File FROM libavtor a
		LEFT JOIN libavtorname USING(AvtorId)
		LEFT JOIN libapics USING(AvtorId)
		WHERE a.bookid=:id");
	$au->bindParam(":id", $b->bookid);
	$au->execute();
	while ($a = $au->fetch()) {
		echo "<author>";
		echo "<name>" . $x("$a->lastname $a->firstname $a->middlename") . "</name>";
		echo "<uri>/opds/author?author_id=" . intval($a->avtorid) . "</uri>";
		echo "</author>";
	}
	
	$au->execute();
	while ($a = $au->fetch()) {
		echo "\n<link href=\"" . $x("$webroot/opds/list?author_id=" . intval($a->avtorid)) . "\" rel=\"related\" type=\"application/atom+xml\" title=\"" . $x("Все книги автора $a->lastname $a->firstname $a->middlename") . "\" />";
	}
	echo "<dc:language>" . $x(trim($b->lang)) . "</dc:language>";
	if ($b->year > 0) {
		echo "<dc:issued>" . intval($b->year) . "</dc:issued>";
	}
	echo "<dc:format>" . $x(trim($b->filetype)) . "</dc:format>";
	echo "<dcterms:extent>" . $b->filesize . "</dcterms:extent>";

	$cleanAn = strip_tags(preg_replace('/\[[^\]]*\]/', '', $an));
	echo "\n<summary type=\"text\">" . $x($cleanAn);
	echo "\n" . $x($sq);
	echo "\n" . $x((string)($b->keywords ?? ''));
	if ($b->year > 0) {
		echo "\nГод издания: " . intval($b->year);
	}
	echo "\nФормат: " . $x(trim($b->filetype));
	echo "\nЯзык: " . $x(trim($b->lang));
	echo "\nРазмер: " . $x(formatSizeUnits($b->filesize));
	echo "\n</summary>";

	echo "\n<link rel=\"http://opds-spec.org/image/thumbnail\" href=\"" . $x("$webroot/extract_cover.php?id=" . intval($b->bookid)) . "\" type=\"image/jpeg\"/>";
	echo "\n<link rel=\"http://opds-spec.org/image\" href=\"" . $x("$webroot/extract_cover.php?id=" . intval($b->bookid)) . "\" type=\"image/jpeg\"/>";
	$ur = (trim($b->filetype) == 'fb2') ? 'fb2' : 'usr';
	$mime = opds_filetype_mime(trim($b->filetype));
	echo "\n<link href=\"" . $x("$webroot/$ur.php?id=" . intval($b->bookid)) . "\" rel=\"http://opds-spec.org/acquisition/open-access\" type=\"$mime\" />";
	echo "\n<link href=\"" . $x("$webroot/book/view/" . intval($b->bookid)) . "\" rel=\"alternate\" type=\"text/html\" title=\"Книга на сайте\" />";

	echo "</entry>\n";
}

function isValidIpOrSubnet(string $value): bool {
	if ($value === '') return true;
	if (strpos($value, '/') !== false) {
		[$ip, $prefix] = explode('/', $value, 2);
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
			&& ctype_digit($prefix)
			&& (int)$prefix >= 0
			&& (int)$prefix <= 32;
	}
	return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function ipInNetwork($ip, $range) {
	if (strpos($range,'/') == false) {
		return $ip === $range;
	}

	list($subnet, $bits) = explode('/', $range);
	$id = ip2long($ip);
	$subnet = ip2long($subnet);
	$mask = -1 << (32 - $bits);
	$subnet &= $mask;
	return ($ip & $mask) == $subnet;
}

define('LOGIN_OK',0);
define('LOGIN_BAD_PASSWORD',1);
define('LOGIN_AGENT_MISMATCH',2);
define('LOGIN_LOCKED_FAILCOUNT',3);
define('LOGIN_IP_LOCKED',4);
define('LOGIN_ADMIN_ACCESS_ATTEMPT',5);
define('LOGIN_OPDS_BAD_PASSWORD',6);

function record_login_attempt($pdo, $username,  $outcome) {
	$stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, username, user_agent,outcome) values (?,?,?,?)");
	$stmt->execute([$_SERVER['REMOTE_ADDR'],substr($_POST['username']?? 'unknown',0, 50), 
		substr($_SERVER['HTTP_USER_AGENT'] ?? 'unavailable',0, 512), $outcome]);
	if ($outcome > 0) {
		file_put_contents('/cache/login_attempts/flibusta_login_attempts.log', '[' .date('Y-m-d H:i:s') . "]: user=$username from=".$_SERVER['REMOTE_ADDR'] . " failure code=$outcome\n", FILE_APPEND | LOCK_EX);
		error_log("Flibusta Auth Failure: user=$username from = ".$_SERVER['REMOTE_ADDR']);
	}
}

function checkLogin($pdo, $minAdmin = false, $webroot= '') {
	$userIp = $_SERVER['REMOTE_ADDR'];
	if ((TRUSTED_NET != '')  && ipInNetwork($userIp,TRUSTED_NET) && !$minAdmin)
		return ;  // client  on trusted network, access grunted

	if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] != $_SERVER['HTTP_USER_AGENT']) {
		record_login_attempt($pdo,$_SESSION['username'] ?? 'Not known', LOGIN_AGENT_MISMATCH);
		$_SESSION = array();
		session_destroy();
		http_response_code(403);
		die("Session has been terminated for security reasons.");
	}

	if ($minAdmin && ($_SESSION['is_admin'] !== true )) {
		record_login_attempt($pdo,$_SESSION['username'], LOGIN_ADMIN_ACCESS_ATTEMPT);
		http_response_code(401);
		die("Access denied.");
	}
	if (isset($_SESSION['user_id'])) 
		return; // user is logged in , access grunted
	if (checkRememberMe($pdo,$webroot)) {
		return;
	}
	http_response_code(303); //redirect to login
	header("Location: ". $webroot."/login.php");
}

function checkOPDSLogin($pdo) {
	$userIp = $_SERVER['REMOTE_ADDR'];
	if ((TRUSTED_NET != '')  && ipInNetwork($userIp,TRUSTED_NET))
		return ;  // client on trusted network, access grunted
	$user = $_SERVER['PHP_AUTH_USER'] ?? null;
	$pass = $_SERVER['PHP_AUTH_PW'] ?? null;

	if (empty($user) && ! empty($_SERVER['HTTP_AUTHORIZATION'])) {
		$auth = explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));
		$user = $auth[0];
		$pass = $auth[1] ?? '';
	}
	if ($user && $pass) {
		$stmt = $pdo->prepare("SELECT id, password_hash FROM users where username=?");
		$stmt->execute([$user]);
		$userData = $stmt->fetch();

		if ($userData && password_verify($pass,$userData->password_hash))
			return;
	}

	if (empty($user)) {
		error_log("OPDS Auth failure: empty user from $userIp");
	} else {
		record_login_attempt($pdo,$user, LOGIN_OPDS_BAD_PASSWORD);
	}
	
	header('WWW-Authenticate: Basic realm="My OPDS Library"');
	header('HTTP/1.0 401 Unauthorized');
	echo "<xml version='1.0' encoding='UTF-8' ?>
	      <error>
		    <message>Authenitcation required</message>
		  </error>";
	exit;
}

function isAdminPath($url) {
	return ($url !== null &&  ($url->mod === 'service' || $url->mod === 'users'));
}

function login($pdo, $username, $password, $webroot,$set_remember_me) {

	if (rand(0,100) < 2) {
		cleanupUserMgmtTables($pdo);
	}
	$stmt = $pdo->prepare("SELECT COUNT(*) cnt FROM login_attempts WHERE username =  ? and attempt_time > NOW() - INTERVAL '15 minutes' AND outcome > 0");
	$stmt->execute([$username]);
	$failed_count = $stmt->fetch();
	if ($failed_count && ($failed_count->cnt> 10)) {
		record_login_attempt($pdo, $username, LOGIN_LOCKED_FAILCOUNT);
		sleep(2);
		return false;
	}
	$stmt = $pdo->prepare("SELECT COUNT(*) cnt FROM login_attempts WHERE ip_address = ? AND attempt_time > NOW() - INTERVAL '15 minutes' AND outcome > 0");
	$stmt->execute([$_SERVER['REMOTE_ADDR']]);
	$failed_count = $stmt->fetch();
	if ($failed_count && ($failed_count->cnt > 5)) {
		record_login_attempt($pdo, $username, LOGIN_IP_LOCKED);
		sleep(2);
		return false;
	}

	$stmt = $pdo->prepare("SELECT id,password_hash, is_admin from users WHERE username = ?");
	$stmt-> execute([$username]);

	$user = $stmt->fetch();

	if ($user && password_verify($password, $user->password_hash)) {
		record_login_attempt($pdo, $username, LOGIN_OK);
		$_SESSION['user_id'] = $user->id;
		$_SESSION['username'] = $username;
		$_SESSION['is_admin'] = (bool) $user->is_admin;
		$_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
		$_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'];
		// TODO fill in favorites
		if ($set_remember_me) {
			createRememberMeToken($pdo, $user->id, $webroot);
		} else {
			clearRememberMeToken($webroot);
		}
		return true;
	}
	record_login_attempt($pdo, $username, LOGIN_BAD_PASSWORD);
	return false;
}

function get_login_redirect($pdo, $user_id, $webroot) {
	$base   = $webroot ?: '/';
	$prefix = rtrim($webroot, '/');
	try {
		$stmt = $pdo->prepare("SELECT login_redirect, last_book FROM user_settings WHERE user_id = ?");
		$stmt->execute([$user_id]);
		$row = $stmt->fetch(PDO::FETCH_OBJ);
	} catch (Exception $e) {
		return $base;
	}
	$pref = $row ? $row->login_redirect : null;
	if ($pref === 'genres') {
		return $prefix . '/genres/';
	}
	if ($pref === 'favorites') {
		$stmt = $pdo->prepare("SELECT 1 FROM fav WHERE user_id = ? LIMIT 1");
		$stmt->execute([$user_id]);
		if ($stmt->fetch()) {
			return $prefix . '/fav/';
		}
	}
	if ($pref === 'last_book' && $row && !empty($row->last_book) && intval($row->last_book) > 0) {
		return $prefix . '/book/view/' . intval($row->last_book);
	}
	return $base;
}

function cleanupUserMgmtTables($pdo) {
	$pdo->query("DELETE FROM login_attempts  WHERE attempt_time < NOW() - INTERVAL '30 days'");
	$pdo->query("DELETE FROM php_sessions WHERE last_accessed < NOW() - INTERVAL '2 days'");
	$pdo->query("DELETE FROM user_tokens WHERE expires_at < NOW()");
	$pdo->query("VACUUM ANALYZE login_attempts");
	$pdo->query("VACUUM ANALYZE php_sessions");
	$pdo->query("VACUUM ANALYZE user_tokens");
}

function createRememberMeToken($pdo, $userId, $webroot) {
	$selector = bin2hex(random_bytes(6));
	$validator = bin2hex(random_bytes(32));
	$expires = date('Y-m-d H:i:s', strtotime('+30 days'));

	$tokenHash = hash('sha256',$validator);
	$stmt = $pdo->prepare("INSERT INTO user_tokens(selector, token_hash, user_id, expires_at) values(?,?,?,?)");
	$stmt->execute([$selector, $tokenHash, $userId, $expires]);

	$cookieValue = $selector.':'.$validator;
	setcookie('flibusta_remember_me',
				$cookieValue,
				['expires' => time() + (86400 *30),
				'path' => $webroot != "" ? $webroot : "/",
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
				]
	);
}

function checkRememberMe($pdo, $webroot) {
	if (empty($_SESSION['user_id']) && ! empty($_COOKIE['flibusta_remember_me'])) {
		$parts = explode(':', $_COOKIE['flibusta_remember_me']);
		if (count($parts) !== 2) {
			clearRememberMeToken($webroot);
			return false;
		}

		list($selector, $validator) = $parts;

		$stmt = $pdo->prepare("SELECT t.id, t.token_hash, t.user_id, u.username, u.is_admin FROM user_tokens t
		JOIN users u ON t.user_id = u.id
		WHERE t.selector = ? AND t.expires_at > NOW()");
		$stmt->execute([$selector]);
		$tokenData = $stmt->fetch();
		if ($tokenData && hash_equals($tokenData['token_hash'], hash('sha256',$validator))) {
			$_SESSION['user_id'] = $tokenData->user_id;
			$_SESSION['username'] = htmlspecialchars($tokenData->username);
			$_SESSION['is_admin'] = (bool) $tokenData->is_admin;
			$_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
			$pdo->prepare("DELETE FROM user_tokens WHERE id = ?")->execute([$tokenData->id]);
			createRememberMeToken($pdo,$tokenData->user_id,$webroot);
			return true;
		}
		clearRememberMeToken($webroot);
	}
	return false;
}

function clearRememberMeToken($webroot) {
	setcookie('flibusta_remember_me','', time() - 7200, $webroot != "" ? $webroot : "/");
}

function fetchMissingBook(int $id, string $ext): ?string {
	if (!FLIBUSTA_MISSING_BOOK_DOWNLOAD || FLIBUSTA_URL === '') {
		return null;
	}
	$localPath = LOCAL_LIBRARY_PATH . $id . '.' . $ext;
	if (file_exists($localPath)) {
		return $localPath;
	}

	$lockPath = CACHE_PATH . 'locks/book_' . $id . '_' . $ext . '.lock';
	$lockFh = fopen($lockPath, 'c');
	if ($lockFh === false) {
		return null;
	}
	flock($lockFh, LOCK_EX);

	try {
		if (file_exists($localPath)) {
			return $localPath;
		}

		$url = FLIBUSTA_URL . '/b/' . $id . '/' . $ext;
		$tmpPath = CACHE_PATH . 'tmp/book_' . $id . '_' . uniqid();

		$ch = curl_init($url);
		$fh = fopen($tmpPath, 'wb');
		curl_setopt_array($ch, [
			CURLOPT_FILE           => $fh,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_FAILONERROR    => true,
		]);
		$ok       = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		fclose($fh);
		if (!$ok || $httpCode !== 200 || !file_exists($tmpPath) || filesize($tmpPath) < 100) {
			@unlink($tmpPath);
			error_log("fetchMissingBook: failed to download book $id.$ext from $url (HTTP $httpCode)");
			return null;
		}

		// For fb2: the server may redirect to a .fb2.zip — extract the fb2 entry directly.
		if ($ext === 'fb2' && str_ends_with(strtolower(parse_url($finalUrl, PHP_URL_PATH) ?? ''), '.zip')) {
			$zip = new ZipArchive();
			if ($zip->open($tmpPath) !== true) {
				@unlink($tmpPath);
				error_log("fetchMissingBook: downloaded file for book $id is not a valid zip");
				return null;
			}
			$foundName = null;
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = $zip->getNameIndex($i);
				if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'fb2') {
					$foundName = $name;
					break;
				}
			}
			if ($foundName === null) {
				$zip->close();
				@unlink($tmpPath);
				error_log("fetchMissingBook: no fb2 entry found in downloaded zip for book $id");
				return null;
			}
			$srcStream = $zip->getStream($foundName);
			$dstFh = fopen($localPath, 'wb');
			stream_copy_to_stream($srcStream, $dstFh);
			fclose($srcStream);
			fclose($dstFh);
			$zip->close();
			@unlink($tmpPath);
			return $localPath;
		}

		rename($tmpPath, $localPath);
		return $localPath;
	} finally {
		flock($lockFh, LOCK_UN);
		fclose($lockFh);
	}
}

function resolve_inner_zip_book(string $outerZipPath, int $bookId, string $innerZipName, string $ext): ?string {
	$localPath = LOCAL_LIBRARY_PATH . $bookId . '.' . $ext;
	if (file_exists($localPath)) {
		return $localPath;
	}

	// Quick check: does the outer zip contain the inner zip entry at all?
	$outerZip = new ZipArchive();
	if ($outerZip->open($outerZipPath) !== true) {
		return null;
	}
	if ($outerZip->locateName($innerZipName) === false) {
		$outerZip->close();
		return null;
	}
	$outerZip->close();

	// Acquire per-book exclusive lock (file created on demand, no pre-creation needed)
	$lockPath = CACHE_PATH . 'locks/book_' . $bookId . '_' . $ext . '.lock';
	$lockFh = fopen($lockPath, 'c');
	if ($lockFh === false) {
		return null;
	}
	flock($lockFh, LOCK_EX);

	try {
		// Double-check after acquiring lock in case another request just finished
		if (file_exists($localPath)) {
			return $localPath;
		}

		$tmpDir = CACHE_PATH . 'tmp/book_' . $bookId . '_' . uniqid();
		mkdir($tmpDir, 0755);

		try {
			// Extract the inner zip bytes from the outer zip into a temp file
			$outerZip = new ZipArchive();
			if ($outerZip->open($outerZipPath) !== true) {
				return null;
			}
			$tmpInnerZip = $tmpDir . '/inner.zip';
			$innerStream = $outerZip->getStream($innerZipName);
			$tmpFh = fopen($tmpInnerZip, 'wb');
			stream_copy_to_stream($innerStream, $tmpFh);
			fclose($innerStream);
			fclose($tmpFh);
			$outerZip->close();

			// Find the first file with the expected extension inside the inner zip
			$innerZip = new ZipArchive();
			if ($innerZip->open($tmpInnerZip) !== true) {
				return null;
			}
			$foundName = null;
			for ($i = 0; $i < $innerZip->numFiles; $i++) {
				$name = $innerZip->getNameIndex($i);
				if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === $ext) {
					$foundName = $name;
					break;
				}
			}
			if ($foundName === null) {
				$innerZip->close();
				return null;
			}

			// Write book file to temp path, then atomically rename into the local cache
			$tmpBookPath = $tmpDir . '/' . $bookId . '.' . $ext;
			$srcStream = $innerZip->getStream($foundName);
			$dstFh = fopen($tmpBookPath, 'wb');
			stream_copy_to_stream($srcStream, $dstFh);
			fclose($srcStream);
			fclose($dstFh);
			$innerZip->close();

			rename($tmpBookPath, $localPath);
			return $localPath;

		} finally {
			foreach (glob($tmpDir . '/*') ?: [] as $f) {
				@unlink($f);
			}
			@rmdir($tmpDir);
		}
	} finally {
		flock($lockFh, LOCK_UN);
		fclose($lockFh);
	}
}