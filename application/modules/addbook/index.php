<?php
// addbook module — form page. All request handling is in module.conf.
global $url;
global $webroot;
global $addbook_errors, $addbook_dup_titles;

echo "<h4 class='rounded-top p-1' style='background: #d0d0d0;'>Добавить книгу в библиотеку</h4>";

// success banner after redirect
if (isset($_GET['added']) && ctype_digit($_GET['added'])) {
	$addedId = (int)$_GET['added'];
	$st = $dbh->prepare("SELECT title FROM libbook WHERE bookid = ?");
	$st->execute([$addedId]);
	if ($b = $st->fetch()) {
		$t = htmlspecialchars($b->title, ENT_QUOTES, 'UTF-8');
		echo "<div class='alert alert-success'>Книга «$t» добавлена: <a href='$webroot/book/view/$addedId'>открыть страницу книги</a></div>";
	}
}

foreach ($addbook_errors as $err) {
	echo "<div class='alert alert-danger'>" . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . "</div>";
}
if (!empty($addbook_dup_titles)) {
	echo "<div class='alert alert-warning'><b>Существующие книги с этим названием:</b><ul class='mb-0'>";
	foreach ($addbook_dup_titles as $d) {
		$t = htmlspecialchars($d->title, ENT_QUOTES, 'UTF-8');
		$a = htmlspecialchars((string)$d->authors, ENT_QUOTES, 'UTF-8');
		echo "<li><a href='$webroot/book/view/$d->bookid'>$t</a> — $a</li>";
	}
	echo "</ul></div>";
}

// prefill after a failed submit
$pfTitle = htmlspecialchars(trim((string)($_POST['title'] ?? '')), ENT_QUOTES, 'UTF-8');
$pfLang  = htmlspecialchars(trim((string)($_POST['lang'] ?? 'ru')), ENT_QUOTES, 'UTF-8');
$pfYear  = (int)($_POST['year'] ?? 0);
$pfAuthors = htmlspecialchars((string)($_POST['authors_json'] ?? '[]'), ENT_QUOTES, 'UTF-8');
$pfGenres = array_map('intval', (array)($_POST['genres'] ?? []));
$csrf = htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8');

echo <<< __HTML
<div class='card'><div class='card-body'>
<form method='POST' action='$webroot/addbook/create' enctype='multipart/form-data' id='addbookForm'>
<input type='hidden' name='csrf_token' value='$csrf'>
<input type='hidden' name='authors_json' id='authorsJson' value='$pfAuthors'>

<div class='mb-3'>
  <label class='form-label'><b>1. Название книги</b></label>
  <input type='text' class='form-control' name='title' id='bookTitle' required maxlength='254' value='$pfTitle'>
  <div id='titleMatches' class='form-text'></div>
  <div class='form-check d-none' id='confirmWrap'>
    <input class='form-check-input' type='checkbox' name='confirm_duplicate' value='1' id='confirmDup'>
    <label class='form-check-label' for='confirmDup'>Всё равно добавить (это другая книга с таким же названием)</label>
  </div>
</div>

<div class='mb-3'>
  <label class='form-label'><b>2. Авторы</b></label>
  <div id='authorChips' class='mb-2'></div>
  <input type='text' class='form-control' id='authorSearch' placeholder='Начните вводить имя автора (опечатки допустимы)…' autocomplete='off'>
  <div id='authorResults' class='list-group position-relative' style='z-index:10;'></div>
  <div class='mt-2'>
    <button type='button' class='btn btn-outline-secondary btn-sm' id='newAuthorToggle'>+ Новый автор</button>
    <div id='newAuthorForm' class='row g-2 mt-1 d-none'>
      <div class='col-sm-3'><input type='text' class='form-control form-control-sm' id='naLast' placeholder='Фамилия' maxlength='99'></div>
      <div class='col-sm-3'><input type='text' class='form-control form-control-sm' id='naFirst' placeholder='Имя' maxlength='99'></div>
      <div class='col-sm-3'><input type='text' class='form-control form-control-sm' id='naMiddle' placeholder='Отчество' maxlength='99'></div>
      <div class='col-sm-2'><input type='text' class='form-control form-control-sm' id='naNick' placeholder='Псевдоним' maxlength='33'></div>
      <div class='col-sm-1'><button type='button' class='btn btn-primary btn-sm' id='naAdd'>OK</button></div>
    </div>
  </div>
</div>

<div class='row mb-3'>
  <div class='col-sm-2'>
    <label class='form-label'>Язык</label>
    <input type='text' class='form-control' name='lang' value='$pfLang' maxlength='3' pattern='[a-zA-Z]{2,3}'>
  </div>
  <div class='col-sm-2'>
    <label class='form-label'>Год</label>
    <input type='number' class='form-control' name='year' value='$pfYear' min='0' max='2100'>
  </div>
  <div class='col-sm-8'>
    <label class='form-label'>Жанры (необязательно, до 10)</label>
    <select class='form-select' name='genres[]' multiple size='4'>
__HTML;

$gsel = array_flip($pfGenres);
$gs = $dbh->query("SELECT genreid, genredesc, genremeta FROM libgenrelist ORDER BY genremeta, genredesc");
$curMeta = null;
while ($g = $gs->fetch()) {
	if ($g->genremeta !== $curMeta) {
		if ($curMeta !== null) echo "</optgroup>";
		$curMeta = $g->genremeta;
		echo "<optgroup label='" . htmlspecialchars($curMeta, ENT_QUOTES, 'UTF-8') . "'>";
	}
	$sel = isset($gsel[(int)$g->genreid]) ? ' selected' : '';
	echo "<option value='$g->genreid'$sel>" . htmlspecialchars($g->genredesc, ENT_QUOTES, 'UTF-8') . "</option>";
}
if ($curMeta !== null) echo "</optgroup>";

echo <<< __HTML
    </select>
  </div>
</div>

<div class='mb-3'>
  <label class='form-label'><b>3. Файл книги</b></label>
  <input type='file' class='form-control' name='bookfile' required
    accept='.fb2,.epub,.pdf,.djvu,.djv,.docx,.mobi,.html,.htm,.txt,.rtf,.zip'>
  <div class='form-text'>Поддерживаются fb2, epub, pdf, djvu, docx, mobi, html, txt, rtf — а также zip-архив, содержащий ровно один такой файл.</div>
</div>

<button class='btn btn-primary' type='submit'>Добавить книгу</button>
</form>
</div></div>

<script>
(function() {
  const base = '$webroot/addbook';
  let authors = [];
  try { authors = JSON.parse(document.getElementById('authorsJson').value) || []; } catch (e) { authors = []; }

  const chips = document.getElementById('authorChips');
  const json = document.getElementById('authorsJson');
  const search = document.getElementById('authorSearch');
  const results = document.getElementById('authorResults');

  function esc(s) { const d = document.createElement('span'); d.textContent = s; return d.innerHTML; }
  function label(a) {
    if (a.id) return (a.name || ('автор #' + a.id));
    return [a.last, a.first, a.middle].filter(Boolean).join(' ') + (a.nick ? ' («' + a.nick + '»)' : '');
  }
  function sync() {
    json.value = JSON.stringify(authors);
    chips.innerHTML = '';
    authors.forEach(function(a, i) {
      const b = document.createElement('span');
      b.className = 'badge bg-secondary me-1 mb-1';
      b.innerHTML = esc(label(a)) + (a.id ? '' : ' <em>(новый)</em>') +
        " <a href='#' data-i='" + i + "' class='text-white text-decoration-none'>&times;</a>";
      chips.appendChild(b);
    });
  }
  chips.addEventListener('click', function(e) {
    if (e.target.dataset.i !== undefined) { e.preventDefault(); authors.splice(+e.target.dataset.i, 1); sync(); }
  });

  let tmr = null;
  search.addEventListener('input', function() {
    clearTimeout(tmr);
    const q = search.value.trim();
    if (q.length < 2) { results.innerHTML = ''; return; }
    tmr = setTimeout(function() {
      fetch(base + '/authorsearch?q=' + encodeURIComponent(q))
        .then(function(r) { return r.json(); })
        .then(function(d) {
          results.innerHTML = '';
          (d.results || []).forEach(function(a) {
            const it = document.createElement('a');
            it.href = '#';
            it.className = 'list-group-item list-group-item-action py-1';
            it.innerHTML = esc(a.name) + " <span class='text-muted'>(" + a.cnt + " кн." + (a.local ? ', локальный' : '') + ")</span>";
            it.addEventListener('click', function(e) {
              e.preventDefault();
              if (!authors.some(function(x) { return x.id === a.id; })) { authors.push({id: a.id, name: a.name}); sync(); }
              results.innerHTML = ''; search.value = '';
            });
            results.appendChild(it);
          });
          if ((d.results || []).length === 0) {
            results.innerHTML = "<div class='list-group-item py-1 text-muted'>Совпадений нет — добавьте нового автора</div>";
          }
        });
    }, 300);
  });

  document.getElementById('newAuthorToggle').addEventListener('click', function() {
    document.getElementById('newAuthorForm').classList.toggle('d-none');
  });
  document.getElementById('naAdd').addEventListener('click', function() {
    const a = { last: document.getElementById('naLast').value.trim(),
                first: document.getElementById('naFirst').value.trim(),
                middle: document.getElementById('naMiddle').value.trim(),
                nick: document.getElementById('naNick').value.trim() };
    if (!a.last && !a.nick) { alert('Нужна фамилия или псевдоним'); return; }
    authors.push(a); sync();
    ['naLast','naFirst','naMiddle','naNick'].forEach(function(id) { document.getElementById(id).value = ''; });
    document.getElementById('newAuthorForm').classList.add('d-none');
  });

  // title duplicate pre-check
  const title = document.getElementById('bookTitle');
  const matches = document.getElementById('titleMatches');
  const confirmWrap = document.getElementById('confirmWrap');
  title.addEventListener('blur', function() {
    const q = title.value.trim();
    if (q.length < 2) return;
    fetch(base + '/titlecheck?q=' + encodeURIComponent(q))
      .then(function(r) { return r.json(); })
      .then(function(d) {
        const rs = d.results || [];
        if (rs.length === 0) { matches.innerHTML = ''; confirmWrap.classList.add('d-none'); return; }
        let html = '<b>Похожие книги в библиотеке:</b><ul class="mb-1">';
        rs.forEach(function(b) {
          html += '<li><a target="_blank" href="$webroot/book/view/' + b.id + '">' + esc(b.title) + '</a> — ' + esc(b.authors || '') + (b.exact ? ' <b>(точное совпадение)</b>' : '') + '</li>';
        });
        matches.innerHTML = html + '</ul>';
        if (d.exact) confirmWrap.classList.remove('d-none');
      });
  });

  document.getElementById('addbookForm').addEventListener('submit', function(e) {
    if (authors.length === 0) { e.preventDefault(); alert('Укажите хотя бы одного автора'); }
  });

  sync();
})();
</script>
__HTML;
