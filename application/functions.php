<?php

/**
 * HTML-escape a value for safe interpolation into markup.
 * Use for every DB-derived / imported string echoed into HTML.
 */
function h($s) {
	return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Plain text to paragraphs.
 *
 * The input is the contents of a book file, i.e. untrusted, so each line is
 * escaped: markup inside a .txt must read as text rather than render.
 */
function nl2p($string) {
	$paragraphs = '';
	foreach (explode("\n", (string)$string) as $line) {
		if (trim($line)) {
			$paragraphs .= '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
		}
	}
	return $paragraphs;
}

/**
 * Format a libbook.time value as Y-m-d.
 * Dump rows carry second precision ('2011-05-11 20:19:21+00'), but a row
 * inserted with the column default (CURRENT_TIMESTAMP) carries microseconds,
 * which the strict format does not match. Never let an unparseable value
 * escalate to a fatal error on a whole listing page.
 */
function book_date($ts) {
	$ts = trim((string)($ts ?? ''));
	if ($ts === '') {
		return '';
	}
	$dt = DateTime::createFromFormat('Y-m-d H:i:se', $ts);
	if ($dt === false) {
		$dt = DateTime::createFromFormat('Y-m-d H:i:s.ue', $ts);
	}
	if ($dt === false) {
		try {
			$dt = new DateTime($ts);
		} catch (Throwable $e) {
			return '';
		}
	}
	return $dt->format('Y-m-d');
}

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
    '/(\[li\])(.*?)(\[\/li\])/'
  );

  $replace = array (
    '<strong>$2</strong>',
    '<em>$2</em>',
    '<u>$2</u>',
    '<ul>$2</ul>',
    '<li>$2</li>'
  );

  $out = preg_replace($search, $replace, $content);

  // [url] tags build the whole anchor in a callback so the URL is validated and
  // escaped BEFORE it becomes an attribute. Interpolating the captured URL
  // straight into href="$2" let a crafted tag close the attribute and add its
  // own, e.g. [url=" onmouseover="alert(1)]x[/url]; the sanitising post-pass
  // that used to follow could not catch it because its [^"]* stopped at the
  // injected quote, leaving the smuggled handler untouched.
  $anchor = function ($href, $text) {
    $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
    $scheme = strtolower(parse_url($href, PHP_URL_SCHEME) ?? '');
    $ok = ($scheme === 'http' || $scheme === 'https' || $scheme === 'mailto' || $scheme === '');
    return '<a href="' . ($ok ? h($href) : '#') . '" target="_blank">' . $text . '</a>';
  };

  // [url=URL]text[/url] — the link text keeps the allow-listed markup that
  // sanitize_annotations.php already vetted, so it is not escaped again.
  $out = preg_replace_callback('/\[url=(.*?)\](.*?)\[\/url\]/', function ($m) use ($anchor) {
    return $anchor($m[1], $m[2]);
  }, $out);

  // [url]URL[/url] — the URL is also the link text, so it must be escaped.
  $out = preg_replace_callback('/\[url\](.*?)\[\/url\]/', function ($m) use ($anchor) {
    return $anchor($m[1], h($m[1]));
  }, $out);

  return $out;
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

	$dt = book_date($book->time);
	if (trim($book->filetype) == 'fb2') {
		$fhref = "$webroot/fb2.php?id=$book->bookid";
	} else {
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
		// One lookup for the whole list, not one query per card.
		if (isset(user_favs($dbh, $current_user_id)['books'][intval($book->bookid)])) {
			$fav = 'btn-primary';
			$fav_action = 'unfav_book';
		}
	}

	echo "<div>" . h($book->title) . "</div></a>";

	// Row 1: year + download (split dropdown for fb2, plain button for others)
	echo "<div class='btn-group w-100 mt-auto' role='group'>";
	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$year</button>";
	if (trim($book->filetype) === 'fb2') {
		$bid = intval($book->bookid);
		echo "<a href='$fhref' class='btn btn-outline-success btn-sm'>fb2</a>";
		echo "<div class='btn-group btn-group-sm' role='group'>";
		echo "<button type='button' class='btn btn-outline-success btn-sm dropdown-toggle dropdown-toggle-split'"
		   . " data-bs-toggle='dropdown' aria-expanded='false'>"
		   . "<span class='visually-hidden'>Форматы</span></button>";
		echo "<ul class='dropdown-menu dropdown-menu-end'>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=zip' title='.fb2.zip'>.fb2.zip</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=epub2'>epub2</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=epub3'>epub3</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=kepub'>kepub</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=kfx'>kfx</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=azw8'>azw8</a></li>";
		echo "<li><hr class='dropdown-divider'></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=pdf'>pdf</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=txt'>txt</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=md'>md</a></li>";
		echo "</ul>";
		echo "</div>";
	} else {
		echo "<a href='$fhref' title='Скачать' class='btn btn-outline-secondary btn-sm'>" . h(trim($book->filetype)) . "</a>";
	}
	echo "</div>";

	// Row 2: О книге + Читать + (optional) Избранное
	echo "<div class='btn-group w-100 mt-1' role='group'>";
	echo "<a href='$webroot/book/view/$book->bookid/withannotation' class='btn btn-outline-info btn-sm'>О книге</a>";
	echo "<a href='$webroot/book/view/$book->bookid/contentonly' class='btn btn-outline-primary btn-sm'>Читать</a>";
	if ($show_fav_button) {
		$fav_id = $book->bookid;
		echo "<form method='POST' action='' style='display:inline;'>
			<input type='hidden' name='action' value='$fav_action' />
			<input type='hidden' name='id' value='$fav_id' />
			<input type='hidden' name='csrf_token' value='" . htmlspecialchars(get_csrf_token()) . "' />
			<button type='submit' title='В избранное' class='btn $fav btn-sm'><i class='fas fa-heart'></i></button>
		</form>";
	}
	echo "</div>";
	echo "</div></div>\n";
}

function book_info_pg($book, $webroot = '', $full = false) {
	global $dbh;
	if (!isset($book->bookid)) {
		return;
	}
	$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
	echo "<div class='hic card mb-3' itemscope='' itemtype='http://schema.org/Book'>";
//	echo "<div class='card-header'>";
	echo "<h4 class='rounded-top' style='background: #d0d0d0;'><a class='book-link' href='$webroot/book/view/" . intval($book->bookid) . "'><i class='fas'></i> " . h($book->title) . "</h4></a>";
//	echo "</div>";
	echo "<div class='card-body'>";
	echo "<div class='row'>";
	echo "<div class='col-sm-2'>";
	echo "<img class='w-100 card-image rounded cover' src='$webroot/extract_cover.php?sid=$book->bookid' />";

	$dt = book_date($book->time);
	if (trim($book->filetype) == 'fb2') {
		$fhref = "$webroot/fb2.php?id=$book->bookid";
	} else {
		$fhref = "$webroot/usr.php?id=$book->bookid";
	}

	if ($book->year != 0) {
		$year = $book->year;
	} else {
		$year = $dt;
	}

	$fav = 'btn-outline-secondary';
	$fav_action = 'fav_book';
	if ($current_user_id > 0) {
		if (isset(user_favs($dbh, $current_user_id)['books'][intval($book->bookid)])) {
			$fav = 'btn-primary';
			$fav_action = 'unfav_book';
		}
	}

	// Row 1: year + download (split dropdown for fb2, plain button for others)
	echo "<div class='btn-group w-100 mt-1' role='group'>";
	echo "<button type='button' class='btn btn-outline-secondary btn-sm'>$year</button>";
	if (trim($book->filetype) === 'fb2') {
		$bid = intval($book->bookid);
		echo "<a href='$fhref' class='btn btn-outline-success btn-sm'>fb2</a>";
		echo "<div class='btn-group btn-group-sm' role='group'>";
		echo "<button type='button' class='btn btn-outline-success btn-sm dropdown-toggle dropdown-toggle-split'"
		   . " data-bs-toggle='dropdown' aria-expanded='false'>"
		   . "<span class='visually-hidden'>Форматы</span></button>";
		echo "<ul class='dropdown-menu dropdown-menu-end'>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=zip' title='.fb2.zip'>.fb2.zip</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=epub2'>epub2</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=epub3'>epub3</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=kepub'>kepub</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=kfx'>kfx</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=azw8'>azw8</a></li>";
		echo "<li><hr class='dropdown-divider'></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=pdf'>pdf</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=txt'>txt</a></li>";
		echo "<li><a class='dropdown-item' href='$webroot/fb2.php?id=$bid&final_format=md'>md</a></li>";
		echo "</ul>";
		echo "</div>";
	} else {
		echo "<a href='$fhref' title='Скачать' class='btn btn-outline-secondary btn-sm'>" . h(trim($book->filetype)) . "</a>";
	}
	echo "</div>";

	// Row 2: О книге + Читать + (optional) Избранное
	echo "<div class='btn-group w-100 mt-1' role='group'>";
	echo "<a href='$webroot/book/view/$book->bookid/withannotation' class='btn btn-outline-info btn-sm'>О книге</a>";
	echo "<a href='$webroot/book/view/$book->bookid/contentonly' class='btn btn-outline-primary btn-sm'>Читать</a>";
	if ($current_user_id > 0) {
		echo "<form method='POST' action='' style='display:inline;'>
			<input type='hidden' name='action' value='$fav_action' />
			<input type='hidden' name='id' value='$book->bookid' />
			<input type='hidden' name='csrf_token' value='" . htmlspecialchars(get_csrf_token()) . "' />
			<button type='submit' title='В избранное' class='btn $fav btn-sm'><i class='fas fa-heart'></i></button>
		</form>";
	}
	echo "</div>";

	echo "</div><div class='col-sm-10'>";
	// Authors, genres and series are library content, so they come from the cache
	// in one go instead of three queries per book.
	$header_lists = book_header_lists($dbh, intval($book->bookid));
	echo "<div class='authors-list'>";
	foreach ($header_lists['authors'] as $a) {
		echo "<div class='badge rounded-pill author'>";
		if ($a->file != '') {
			echo "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$a->avtorid' />";	
		}
		echo "<a href='$webroot/author/view/" . intval($a->avtorid) . "'>" . h("$a->lastname $a->firstname $a->middlename $a->nickname") . "</a>";
		echo "</div>";
	}
	echo "</div>";


	echo "<div style='margin-bottom: 3px;'>";
	foreach ($header_lists['genres'] as $g) {
		echo "<a class='badge bg-success p-1 text-white' href='$webroot/?gid=" . intval($g->genreid) . "'>" . h($g->genredesc) . "</a> ";
	}
	echo "</div>";

	echo "<div style='margin-bottom: 3px;'>";
	foreach ($header_lists['series'] as $s) {
		echo "<a class='badge bg-danger p-1 text-white' href='$webroot/?sid=" . intval($s->seqid) . "'>" . h($s->seqname) . " ";
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
			echo "<a class='badge bg-secondary p-1 text-white' href='#'>" . h($k) . "</a> ";
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
  $url->var2_str = sanitize_route_token($var2);
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
		'addbook',
		'404',
	);
}

function safe_str($str) {
        return ($str)?preg_replace("/[^A-Za-z0-9 _-]/", '', $str):$str;
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
	if (strpos($range, '/') === false) {
		return $ip === $range;
	}

	list($subnet, $bits) = explode('/', $range);
	$ipLong     = ip2long($ip);
	$subnetLong = ip2long($subnet);
	if ($ipLong === false || $subnetLong === false) {
		return false;
	}
	$bits = (int)$bits;
	if ($bits < 0 || $bits > 32) {
		return false;
	}
	$mask = ($bits === 0) ? 0 : (-1 << (32 - $bits));
	return (($ipLong & $mask) === ($subnetLong & $mask));
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

/**
 * Credentials carried by an HTTP Basic header, or [null, null].
 * The split has a limit of 2 so that a password containing ':' survives.
 */
function basic_auth_credentials() {
	$user = $_SERVER['PHP_AUTH_USER'] ?? null;
	$pass = $_SERVER['PHP_AUTH_PW'] ?? null;

	if (empty($user) && ! empty($_SERVER['HTTP_AUTHORIZATION'])) {
		$auth = explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)), 2);
		$user = $auth[0] ?? null;
		$pass = $auth[1] ?? '';
	}
	return [$user, $pass];
}

/**
 * True when this username or this client IP has too many recent failures.
 * Records the lockout itself, exactly as the form login used to do inline.
 *
 * Shared by the form login and by every HTTP Basic entry point: without this,
 * Basic auth (OPDS feeds and the direct file endpoints) would be an unthrottled
 * password-guessing channel against the same hashes the form login protects.
 */
function login_attempts_blocked($pdo, $username) {
	$stmt = $pdo->prepare("SELECT COUNT(*) cnt FROM login_attempts WHERE username = ? AND attempt_time > NOW() - INTERVAL '15 minutes' AND outcome > 0");
	$stmt->execute([$username]);
	$failed_count = $stmt->fetch();
	if ($failed_count && ($failed_count->cnt > 10)) {
		record_login_attempt($pdo, $username, LOGIN_LOCKED_FAILCOUNT);
		return true;
	}
	$stmt = $pdo->prepare("SELECT COUNT(*) cnt FROM login_attempts WHERE ip_address = ? AND attempt_time > NOW() - INTERVAL '15 minutes' AND outcome > 0");
	$stmt->execute([$_SERVER['REMOTE_ADDR']]);
	$failed_count = $stmt->fetch();
	if ($failed_count && ($failed_count->cnt > 5)) {
		record_login_attempt($pdo, $username, LOGIN_IP_LOCKED);
		return true;
	}
	return false;
}

/** How long a successfully verified Basic credential stays usable without a re-check. */
define('AUTH_CACHE_TTL', 300);

/**
 * Cache key for a credential pair.
 *
 * Keyed by an HMAC under a secret that exists only in Redis, so the keyspace never
 * reveals a username, let alone a password, and entries cannot be precomputed by
 * anyone who can read the cache.
 */
function auth_cache_key(string $user, string $pass): string {
	return 'auth:' . hash_hmac('sha256', $user . "\0" . $pass, cache_secret());
}

/** Remembers a verified credential, and notes it under the user so it can be revoked. */
function auth_cache_store(string $key, int $userId, string $username, bool $isAdmin): void {
	$r = cache_handle();
	if ($r === null) {
		return;
	}
	cache_set($key, ['user_id' => $userId, 'username' => $username, 'is_admin' => $isAdmin], AUTH_CACHE_TTL);
	try {
		$indexKey = 'auth:user:' . $userId;
		$r->sAdd($indexKey, $key);
		// Deliberately longer than the entries it tracks, refreshed on every add, so
		// the index can never expire while a live entry still points at this user.
		$r->expire($indexKey, AUTH_CACHE_TTL * 2);
	} catch (Throwable $e) {
		cache_failed($e);
	}
}

/**
 * Revokes every cached credential of a user. Must be called whenever the password,
 * the role or the account itself changes - see user_security_changed().
 */
function auth_cache_invalidate_user(int $userId): void {
	$r = cache_handle();
	if ($r === null) {
		return;
	}
	try {
		$indexKey = 'auth:user:' . $userId;
		$keys = $r->sMembers($indexKey);
		if ($keys) {
			$r->del($keys);
		}
		$r->del($indexKey);
	} catch (Throwable $e) {
		cache_failed($e);
	}
}

/**
 * Verify HTTP Basic credentials, honouring the lockout counters.
 *
 * OPDS readers send Basic auth on every single request and never keep a session,
 * so this used to run two lockout COUNTs, a users lookup and a bcrypt verify for
 * each feed page and each book an e-reader fetched. A verified pair is therefore
 * remembered for AUTH_CACHE_TTL seconds.
 *
 * Only successes are cached, never failures, so the lockout counters still see
 * every wrong password. The accepted trade-off is the other direction: a lockout
 * that starts *after* a successful verification does not interrupt that client
 * until its cached entry expires.
 */
function basic_auth_ok($pdo, $user, $pass) {
	if (empty($user) || empty($pass)) {
		return false;
	}
	$cacheKey = auth_cache_key((string)$user, (string)$pass);
	if (cache_get($cacheKey) !== null) {
		return true;
	}
	if (login_attempts_blocked($pdo, $user)) {
		sleep(2);
		return false;
	}
	$stmt = $pdo->prepare("SELECT id, username, is_admin, password_hash FROM users WHERE username = ?");
	$stmt->execute([$user]);
	$userData = $stmt->fetch();
	if ($userData && password_verify($pass, $userData->password_hash)) {
		auth_cache_store($cacheKey, (int)$userData->id, (string)$userData->username, (bool)$userData->is_admin);
		return true;
	}
	return false;
}

/**
 * Everything that must be dropped when a user's password, role or account changes.
 *
 * Authorisation is decided from $_SESSION['is_admin'], written once at log-in, and
 * from cached Basic credentials - so a demoted admin would otherwise keep /service,
 * /users and /addbook for the life of a session, which is up to a year for a
 * trusted-network client. Their cached preferences go too, since the account they
 * describe may no longer exist.
 *
 * $keepSessionId spares one session: a user changing their own password stays
 * logged in here while being logged out everywhere else.
 */
function user_security_changed(int $userId, ?string $keepSessionId = null): void {
	if ($userId <= 0) {
		return;
	}
	session_store()->deleteUserSessions($userId, $keepSessionId);
	auth_cache_invalidate_user($userId);
	cache_del("user:$userId:last_book");
	user_prefs_invalidate($userId);
	user_favs_invalidate($userId);
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

	if ($minAdmin && empty($_SESSION['is_admin'])) {
		record_login_attempt($pdo,$_SESSION['username'] ?? 'Not known', LOGIN_ADMIN_ACCESS_ATTEMPT);
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
	// Stop here: without the exit the caller carried on and rendered the whole
	// protected page into the redirect's body, so any client that does not
	// follow redirects (curl, a feed reader, a scraper) read it in full.
	exit;
}

function checkOPDSLogin($pdo) {
	$userIp = $_SERVER['REMOTE_ADDR'];
	if ((TRUSTED_NET != '')  && ipInNetwork($userIp,TRUSTED_NET))
		return ;  // client on trusted network, access grunted

	[$user, $pass] = basic_auth_credentials();
	if (basic_auth_ok($pdo, $user, $pass)) {
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

/**
 * Access check for the direct file endpoints — fb2.php, usr.php,
 * extract_cover.php, extract_author.php. They are reached both by the browser
 * (session cookie) and by OPDS readers following acquisition / thumbnail links
 * (HTTP Basic), so accept either, plus the trusted network and a remember-me
 * cookie. Dies with 401 when none applies.
 *
 * The caller must have started the session before calling this.
 */
function checkFileAccess($pdo, $webroot = '') {
	if ((TRUSTED_NET != '') && ipInNetwork($_SERVER['REMOTE_ADDR'] ?? '', TRUSTED_NET)) {
		return;
	}
	if (isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0) {
		return;
	}
	if (checkRememberMe($pdo, $webroot)) {
		return;
	}

	[$user, $pass] = basic_auth_credentials();
	if (basic_auth_ok($pdo, $user, $pass)) {
		return;
	}
	if (! empty($user)) {
		record_login_attempt($pdo, $user, LOGIN_OPDS_BAD_PASSWORD);
	}

	header('WWW-Authenticate: Basic realm="My OPDS Library"');
	http_response_code(401);
	die('Authentication required.');
}

function isAdminPath($url) {
	return ($url !== null &&  ($url->mod === 'service' || $url->mod === 'users' || $url->mod === 'addbook'));
}

function login($pdo, $username, $password, $webroot,$set_remember_me) {

	if (rand(0,100) < 2) {
		cleanupUserMgmtTables($pdo);
	}
	if (login_attempts_blocked($pdo, $username)) {
		sleep(2);
		return false;
	}

	$stmt = $pdo->prepare("SELECT id,password_hash, is_admin from users WHERE username = ?");
	$stmt-> execute([$username]);

	$user = $stmt->fetch();

	if ($user && password_verify($password, $user->password_hash)) {
		record_login_attempt($pdo, $username, LOGIN_OK);
		session_regenerate_id(true); // L1: prevent session fixation on login
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

/**
 * A user's personal hidden-genre list, as an array of ints.
 *
 * Now part of user_prefs(), which is read through the cache and dropped by every
 * writer; still never kept in $_SESSION, where a per-device copy would go stale
 * after a save somewhere else.
 */
function get_excluded_genres($pdo, $user_id) {
	return user_prefs($pdo, intval($user_id))->excluded_genres;
}

function cleanupUserMgmtTables($pdo) {
	$pdo->query("DELETE FROM login_attempts  WHERE attempt_time < NOW() - INTERVAL '30 days'");
	// Session housekeeping belongs to whichever backend holds them. On Redis this
	// only tidies index entries - expiry there is the TTL's job, and unlike this
	// sweep it does not cut trusted-network sessions off after two days.
	session_store()->deleteIdle(2 * 86400);
	$pdo->query("DELETE FROM user_tokens WHERE expires_at < NOW()");
	$pdo->query("VACUUM ANALYZE login_attempts");
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
                // Same rule as the session cookie: Secure unless the operator
                // explicitly opted into plain HTTP, otherwise remember-me is
                // silently dead on an HTTP deployment.
                'secure' => ADMIN_ACCESS_BY_HTTPS,
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
		// NB: ATTR_DEFAULT_FETCH_MODE is FETCH_OBJ, so this row is an object —
		// the old array access raised "Cannot use object of type stdClass as
		// array" and made every remember-me login fail with a 500.
		if ($tokenData && hash_equals($tokenData->token_hash, hash('sha256',$validator))) {
			session_regenerate_id(true); // L1: prevent session fixation on remember-me login
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

/**
 * Locate a book's file and make it readable.
 *
 * Resolves the outer archive from book_zip, pre-extracts a one-book inner zip
 * into LOCAL_LIBRARY_PATH and falls back to downloading from the mirror, so
 * that afterwards the caller can read either LOCAL_LIBRARY_PATH<id>.<ext> or
 * the returned open ZipArchive. This is the lookup that modules/book/index.php
 * used to carry twice (once per branch) and that the /book/content route needs
 * as well.
 *
 * Returns null when the book cannot be located at all, otherwise
 * ['status' => 'ok'|'archive_error', 'zip' => ZipArchive|null, 'dbFilename' => ?string].
 */
function book_open_source($dbh, int $id, string $ext): ?array {
	$usr = ($ext === 'fb2') ? 0 : 1;
	$zipName = book_zip_filename($dbh, $id, $usr);

	$meta = book_meta($dbh, $id);
	$dbFilename = ($meta && $meta->filename !== null && $meta->filename !== '') ? $meta->filename : null;

	if ($zipName === '') {
		// Not covered by any local archive — try the configured mirror. The
		// renderers then read the downloaded file from LOCAL_LIBRARY_PATH, so an
		// unopened ZipArchive is handed back, exactly as this branch did before.
		if (fetchMissingBook($id, $ext) === null) {
			return null;
		}
		return ['status' => 'ok', 'zip' => null, 'dbFilename' => $dbFilename];
	}

	// Pre-extract any inner zip so the file is ready in LOCAL_LIBRARY_PATH before
	// it is needed. libfilename may hold the real name (e.g. Olga_Gromyiko.fb2.zip).
	$innerZipName = ($dbFilename && strtolower(pathinfo($dbFilename, PATHINFO_EXTENSION)) === 'zip')
		? $dbFilename
		: $id . '.' . $ext . '.zip';
	resolve_inner_zip_book($zipName, $id, $innerZipName, $ext);

	$zip = new ZipArchive();
	if ($zip->open($zipName) !== true) {
		error_log("book_open_source: cannot open archive {$zipName} for book $id");
		return ['status' => 'archive_error', 'zip' => null, 'dbFilename' => $dbFilename];
	}
	return ['status' => 'ok', 'zip' => $zip, 'dbFilename' => $dbFilename];
}

/* ===================================================================== caches
 *
 * Library content (the lib* tables and book_zip) only changes when a dump is
 * imported, the archives are rescanned or a book is added locally, yet the same
 * handful of rows is re-read on every page view, every download and every cover.
 * The helpers below put that behind the optional Redis cache, keyed by a library
 * generation that those three operations bump - so nothing has to hunt down
 * individual entries, and an installation without Redis keeps working unchanged
 * (each helper still memoises per request).
 *
 * User-owned data is a different matter and is NOT cached here, with two narrow
 * exceptions carrying their own invalidation: user_prefs() and user_favs().
 */

/** How long cached library content lives; the generation makes it stale sooner. */
define('BOOK_CACHE_TTL', 7 * 86400);

/**
 * Everything about a book that the pages, the download endpoints and the readers
 * need: the libbook row plus its annotation, its stored file name and its first
 * author.
 *
 * Replaces both `SELECT b.*` (whose md5 bytea column comes back as a stream that
 * cannot be cached) and the seven-way LEFT JOIN that fb2.php used to run just to
 * build a download file name - that join multiplied the row out across every genre,
 * series and author of the book and then kept the first row.
 *
 * Returns null for an unknown id; the miss itself is remembered briefly so that a
 * scan of made-up ids cannot turn into a query each.
 */
function book_meta($dbh, int $id): ?object {
	static $memo = [];
	if ($id <= 0) {
		return null;
	}
	if (array_key_exists($id, $memo)) {
		return $memo[$id];
	}

	$key = book_cache_key((string)$id);
	$row = cache_get($key);
	if ($row === null) {
		$stmt = $dbh->prepare("SELECT b.bookid, b.filesize, b.\"time\", b.title, b.title1, b.lang,
				b.filetype, b.year, b.deleted, b.fileauthor, b.keywords,
				encode(b.md5, 'hex') md5hex,
				(SELECT Body FROM libbannotations WHERE BookId = b.bookid LIMIT 1) body,
				(SELECT filename FROM libfilename WHERE BookId = b.bookid LIMIT 1) filename,
				(SELECT CONCAT(an.LastName, ' ', an.FirstName)
					FROM libavtor a JOIN libavtorname an USING(AvtorId)
					WHERE a.BookId = b.bookid ORDER BY a.pos LIMIT 1) author_name
			FROM libbook b WHERE b.bookid = :id LIMIT 1");
		$stmt->bindValue(":id", $id, PDO::PARAM_INT);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_OBJ);
		if ($row) {
			cache_set($key, $row, BOOK_CACHE_TTL);
		} else {
			// Remember the miss too, but only briefly: a walk over made-up ids should
			// not cost a query each, while a book that appears later must show up
			// without waiting a week.
			$row = false;
			cache_set($key, false, 600);
		}
	}
	$memo[$id] = ($row === false) ? null : $row;
	return $memo[$id];
}

/**
 * The archive holding a book, or '' when none does.
 *
 * Answered from the generated index file (see zipindex.php), which every
 * deployment has, with the book_zip table as the fallback until the first rescan
 * after an upgrade.
 */
function book_zip_filename($dbh, int $id, int $usr): string {
	include_once(__DIR__ . '/zipindex.php');
	$fromIndex = zip_index_lookup($id, $usr);
	if ($fromIndex !== null) {
		return book_zip_resolve_path($fromIndex);
	}

	static $warned = false;
	if (!$warned) {
		error_log('Flibusta: no zip index yet, resolving archives from book_zip - run "Сканирование ZIP" to build it.');
		$warned = true;
	}
	$stmt = $dbh->prepare("SELECT filename FROM book_zip WHERE ? BETWEEN start_id AND end_id AND usr = ?");
	$stmt->execute([$id, $usr]);
	$row = $stmt->fetch();
	return $row ? book_zip_resolve_path((string)$row->filename) : '';
}

/**
 * Turns a stored archive name into a path that can be opened.
 *
 * Current scans store the full path (/flibusta/... or /cache/local/...), but rows
 * written by the retired tools/app_update_zip_list.php hold a bare file name, and an
 * installation that has not rescanned since the upgrade still serves those - through
 * the generated index, which build_zip_index.php copies straight out of the table.
 * A bare name reaches realpath() and ZipArchive::open() as a relative path and fails
 * everywhere at once, so resolve it here, in the one place every caller goes through.
 */
function book_zip_resolve_path(string $name): string {
	if ($name === '' || strpos($name, '/') !== false) {
		return $name;
	}
	static $warned = false;
	if (!$warned) {
		error_log("Flibusta: archive index holds bare file names ('$name') - resolving them against "
			. LIBRARY_PATH . ' and ' . LOCAL_LIBRARY_PATH . '; run "Сканирование ZIP" to rebuild it.');
		$warned = true;
	}
	foreach ([LIBRARY_PATH, LOCAL_LIBRARY_PATH] as $dir) {
		if (is_file($dir . $name)) {
			return $dir . $name;
		}
	}
	return LIBRARY_PATH . $name;
}

/**
 * The entry inside an fb2 archive that holds this book, or null when none does.
 *
 * Flibusta's fb2 archives name every entry "<id>.fb2"; the original file name that
 * libfilename carries belongs to usr archives (there the entries really are
 * Author_Title.rar and friends). Trusting libfilename for fb2 therefore names a
 * non-existent entry, so ask the archive which of the candidates it actually has.
 *
 * Returns null when neither candidate is present - including the case where the
 * libfilename value names an inner zip, which the caller unpacks instead.
 */
function fb2_archive_entry(string $zipPath, int $id, ?string $dbFilename): ?string {
	$zip = new ZipArchive();
	if ($zip->open($zipPath) !== true) {
		error_log("Flibusta: cannot open archive $zipPath for book $id");
		return null;
	}
	try {
		foreach ([$id . '.fb2', $dbFilename] as $candidate) {
			if ($candidate !== null && $candidate !== '' && $zip->locateName($candidate) !== false) {
				return $candidate;
			}
		}
		return null;
	} finally {
		$zip->close();
	}
}

/**
 * Authors, genres and series of a book - the badges on the info card.
 *
 * @return array{authors: list<object>, genres: list<object>, series: list<object>}
 */
function book_header_lists($dbh, int $id): array {
	static $memo = [];
	if (isset($memo[$id])) {
		return $memo[$id];
	}
	$lists = cache_remember(book_cache_key('hdr:' . $id), BOOK_CACHE_TTL, static function () use ($dbh, $id) {
		$authors = $dbh->prepare("SELECT AvtorId, LastName, FirstName, nickname, middlename, File FROM libavtor a
			LEFT JOIN libavtorname USING(AvtorId)
			LEFT JOIN libapics USING(AvtorId)
			WHERE a.BookId = :id");
		$authors->bindValue(":id", $id, PDO::PARAM_INT);
		$authors->execute();

		$genres = $dbh->prepare("SELECT GenreId, GenreDesc FROM libgenre
			JOIN libgenrelist USING(GenreId)
			WHERE BookId = :id");
		$genres->bindValue(":id", $id, PDO::PARAM_INT);
		$genres->execute();

		$series = $dbh->prepare("SELECT SeqId, SeqName, SeqNumb FROM libseq
			JOIN libseqname USING(SeqId)
			WHERE BookId = :id");
		$series->bindValue(":id", $id, PDO::PARAM_INT);
		$series->execute();

		return [
			'authors' => $authors->fetchAll(PDO::FETCH_OBJ),
			'genres'  => $genres->fetchAll(PDO::FETCH_OBJ),
			'series'  => $series->fetchAll(PDO::FETCH_OBJ),
		];
	});
	$memo[$id] = is_array($lists) ? $lists : ['authors' => [], 'genres' => [], 'series' => []];
	return $memo[$id];
}

/** Flibusta's own comments on a book. */
function book_reviews($dbh, int $id): array {
	return cache_remember(book_cache_key('rev:' . $id), BOOK_CACHE_TTL, static function () use ($dbh, $id) {
		$stmt = $dbh->prepare("SELECT name, text FROM libreviews WHERE bookid = :id ORDER BY time");
		$stmt->bindValue(":id", $id, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_OBJ);
	}) ?: [];
}

/**
 * A user's preferences, including their hidden-genre list.
 *
 * These are read on hot pages - book_view_mode on every book page,
 * author_default_tab on every author page, the hidden genres on every listing -
 * and change only from the settings page.
 *
 * This does NOT contradict the rule that settings are never cached in $_SESSION:
 * that rule exists because a session is per device and long-lived, so a copy there
 * goes stale the moment the user saves on another device. There is one shared copy
 * here, and every writer deletes it, so all devices see a change on their next
 * request. last_book is deliberately absent - it is written on every book view and
 * read only at log-in, so the DB stays its only home.
 */
function user_prefs($dbh, int $userId): object {
	if ($userId <= 0) {
		return (object)['login_redirect' => null, 'author_default_tab' => null,
			'book_view_mode' => null, 'excluded_genres' => []];
	}
	// Request-scope memo kept in a global, not a function static, so that
	// user_prefs_invalidate() can clear it too - a save and the re-render that
	// follows happen in the same request.
	if (isset($GLOBALS['__flibusta_prefs'][$userId])) {
		return $GLOBALS['__flibusta_prefs'][$userId];
	}

	$prefs = cache_remember("user:$userId:prefs", 86400, static function () use ($dbh, $userId) {
		try {
			$stmt = $dbh->prepare("SELECT login_redirect, author_default_tab, book_view_mode
				FROM user_settings WHERE user_id = ?");
			$stmt->execute([$userId]);
			$row = $stmt->fetch(PDO::FETCH_OBJ);

			$genres = [];
			$gstmt = $dbh->prepare("SELECT genreid FROM user_excluded_genres WHERE user_id = ? ORDER BY genreid");
			$gstmt->execute([$userId]);
			while ($g = $gstmt->fetch()) {
				// The single point of origin that makes it safe for callers to inline
				// these ids into an SQL IN (...) list.
				$genres[] = (int)$g->genreid;
			}

			return (object)[
				'login_redirect'     => $row->login_redirect ?? null,
				'author_default_tab' => $row->author_default_tab ?? null,
				'book_view_mode'     => $row->book_view_mode ?? null,
				'excluded_genres'    => $genres,
			];
		} catch (Exception $e) {
			// Fail open: a broken preference must never break browsing.
			error_log('Flibusta: user preferences read failed: ' . $e->getMessage());
			return null;
		}
	});

	if (!is_object($prefs)) {
		$prefs = (object)['login_redirect' => null, 'author_default_tab' => null,
			'book_view_mode' => null, 'excluded_genres' => []];
	}
	$GLOBALS['__flibusta_prefs'][$userId] = $prefs;
	return $prefs;
}

/** Drops the cached preferences after a save. */
function user_prefs_invalidate(int $userId): void {
	unset($GLOBALS['__flibusta_prefs'][$userId]);
	cache_del("user:$userId:prefs");
}

/**
 * A user's favorites as lookup maps: ['books' => [id => true], 'authors' => ...,
 * 'series' => ...].
 *
 * Listing pages render ten book cards, and each card used to ask the DB whether
 * that one book was a favorite. One query for the whole list replaces all of them,
 * and with Redis even that one goes away.
 */
function user_favs($dbh, int $userId): array {
	$empty = ['books' => [], 'authors' => [], 'series' => []];
	if ($userId <= 0) {
		return $empty;
	}
	// A global, not a function static, so user_favs_invalidate() can clear it
	// within the request that toggled a favorite.
	if (isset($GLOBALS['__flibusta_favs'][$userId])) {
		return $GLOBALS['__flibusta_favs'][$userId];
	}

	$favs = cache_remember("user:$userId:fav", 86400, static function () use ($dbh, $userId) {
		try {
			$stmt = $dbh->prepare("SELECT bookid, avtorid, seqid FROM fav WHERE user_id = ?");
			$stmt->execute([$userId]);
			$out = ['books' => [], 'authors' => [], 'series' => []];
			while ($row = $stmt->fetch()) {
				if (!empty($row->bookid)) {
					$out['books'][(int)$row->bookid] = true;
				}
				if (!empty($row->avtorid)) {
					$out['authors'][(int)$row->avtorid] = true;
				}
				if (!empty($row->seqid)) {
					$out['series'][(int)$row->seqid] = true;
				}
			}
			return $out;
		} catch (Exception $e) {
			error_log('Flibusta: favorites read failed: ' . $e->getMessage());
			return null;
		}
	});

	$GLOBALS['__flibusta_favs'][$userId] = is_array($favs) ? $favs : $empty;
	return $GLOBALS['__flibusta_favs'][$userId];
}

/** Drops the cached favorites after any change to a user's fav rows. */
function user_favs_invalidate(int $userId): void {
	unset($GLOBALS['__flibusta_favs'][$userId]);
	cache_del("user:$userId:fav");
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