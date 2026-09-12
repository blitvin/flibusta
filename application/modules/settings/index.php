<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

if ($current_user_id === 0) {
    $login_url = htmlspecialchars($webroot . '/login.php', ENT_QUOTES, 'UTF-8');
    echo "<div class='alert alert-warning mt-3'>Для доступа к настройкам необходимо <a href='$login_url'>войти в систему</a>.</div>";
    return;
}

$csrfToken = get_csrf_token();

$stmt = $dbh->prepare("SELECT login_redirect, author_default_tab, book_view_mode FROM user_settings WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$saved = $stmt->fetch();
$login_redirect     = $saved ? $saved->login_redirect     : 'default';
$author_default_tab = $saved ? $saved->author_default_tab : 'alpha';
$book_view_mode     = $saved ? $saved->book_view_mode     : 'contentonly';

$excluded = get_excluded_genres($dbh, $current_user_id);

$password_success = '';
$password_error   = '';
$settings_success = '';
$xgenres_success  = '';
$xgenres_error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(403);
        die('CSRF validation failed.');
    }

    if (isset($_POST['change_password'])) {
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';

        if (empty($old_password) || empty($new_password)) {
            $password_error = 'Заполните все поля.';
        } else {
            $stmt = $dbh->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$current_user_id]);
            $user = $stmt->fetch();

            if ($user && password_verify($old_password, $user->password_hash)) {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $dbh->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$new_hash, $current_user_id]);
                // Invalidate other sessions, keep current one alive
                session_store_destroy_user((int)$current_user_id, session_id());
                bump_user_cache_epoch((int)$current_user_id, 'prefs');
                $password_success = 'Пароль успешно изменён.';
            } else {
                $password_error = 'Неверный текущий пароль.';
            }
        }
    } elseif (isset($_POST['save_settings'])) {
        $new_redirect = $_POST['login_redirect'] ?? 'default';
        if (!in_array($new_redirect, ['default', 'favorites', 'genres', 'last_book'], true)) {
            $new_redirect = 'default';
        }
        $new_author_tab = $_POST['author_default_tab'] ?? 'alpha';
        if (!in_array($new_author_tab, ['about', 'alpha', 'series', 'year'], true)) {
            $new_author_tab = 'alpha';
        }
        $new_book_mode = $_POST['book_view_mode'] ?? 'contentonly';
        if (!in_array($new_book_mode, ['withannotation', 'contentonly'], true)) {
            $new_book_mode = 'contentonly';
        }
        $stmt = $dbh->prepare("INSERT INTO user_settings (user_id, login_redirect, author_default_tab, book_view_mode)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (user_id) DO UPDATE SET login_redirect     = EXCLUDED.login_redirect,
                                                author_default_tab = EXCLUDED.author_default_tab,
                                                book_view_mode     = EXCLUDED.book_view_mode");
        $stmt->execute([$current_user_id, $new_redirect, $new_author_tab, $new_book_mode]);
        // Preferences change what this user's pages look like: drop their cached
        // pages and settings fragments right away, including for this request.
        bump_user_cache_epoch((int)$current_user_id, 'prefs');
        $login_redirect     = $new_redirect;
        $author_default_tab = $new_author_tab;
        $book_view_mode     = $new_book_mode;
        $settings_success   = 'Настройки сохранены.';
    } elseif (isset($_POST['save_excluded_genres'])) {
        // NB: this branch has its own submit name on purpose. The three
        // preference forms above all post save_settings and each posts only its
        // own field, so that branch rewrites all three columns; reusing it here
        // would silently reset the user's other preferences on every save.
        $ids = [];
        foreach ((array)($_POST['excluded_genres'] ?? []) as $g) {
            if (ctype_digit((string)$g)) {
                $ids[] = (int)$g;
            }
        }
        $ids = array_values(array_slice(array_unique($ids), 0, MAX_EXCLUDED_GENRES));
        if ($ids) {
            // Drop unknown ids silently, matching the "validate, coerce, never
            // error" idiom used by the other settings. DISTINCT because
            // libgenrelist's key is (genreid, genrecode): one genre can have
            // several alias rows.
            $in  = implode(',', $ids);   // safe: every element passed ctype_digit + (int)
            $chk = $dbh->query("SELECT DISTINCT genreid FROM libgenrelist WHERE genreid IN ($in)");
            $ok  = [];
            while ($r = $chk->fetch()) {
                $ok[] = (int)$r->genreid;
            }
            $ids = $ok;
        }
        try {
            $dbh->beginTransaction();
            $dbh->prepare("DELETE FROM user_excluded_genres WHERE user_id = ?")->execute([$current_user_id]);
            if ($ids) {
                $ins = $dbh->prepare("INSERT INTO user_excluded_genres (user_id, genreid)
                    VALUES (?, ?) ON CONFLICT DO NOTHING");
                foreach ($ids as $gid) {
                    $ins->execute([$current_user_id, $gid]);
                }
            }
            $dbh->commit();
            // Hidden genres filter every book listing this user sees.
            bump_user_cache_epoch((int)$current_user_id, 'prefs');
            $excluded        = $ids;   // refresh so the re-rendered checkboxes show post-save state
            $xgenres_success = 'Список скрытых жанров сохранён.';
        } catch (Exception $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('Flibusta: excluded genres save failed: ' . $e->getMessage());
            $xgenres_error = 'Не удалось сохранить список жанров.';
        }
    }
}

$checked = [
    'default'   => $login_redirect === 'default'    ? 'checked' : '',
    'favorites' => $login_redirect === 'favorites'  ? 'checked' : '',
    'genres'    => $login_redirect === 'genres'     ? 'checked' : '',
    'last_book' => $login_redirect === 'last_book'  ? 'checked' : '',
];
$tab_checked = [
    'about'  => $author_default_tab === 'about'  ? 'checked' : '',
    'alpha'  => $author_default_tab === 'alpha'  ? 'checked' : '',
    'series' => $author_default_tab === 'series' ? 'checked' : '',
    'year'   => $author_default_tab === 'year'   ? 'checked' : '',
];
$bvm_checked = [
    'withannotation' => $book_view_mode === 'withannotation' ? 'checked' : '',
    'contentonly'    => $book_view_mode === 'contentonly'    ? 'checked' : '',
];

// Genre dictionary for the hidden-genres card, grouped by genremeta.
// DISTINCT ON (genreid) because libgenrelist's key is (genreid, genrecode) and
// one genre may have several alias rows, which would render duplicate options.
// It needs its own subquery: Postgres requires ORDER BY to lead with the
// DISTINCT ON expression, but we want display order by meta then description.
$xg_rows = $dbh->query("SELECT genreid, genredesc, genremeta FROM (
        SELECT DISTINCT ON (genreid) genreid, genredesc, genremeta
        FROM libgenrelist ORDER BY genreid, genrecode
    ) t ORDER BY genremeta, genredesc");
$xg_selected = array_flip($excluded);

// Group rows by meta so a <details> block can be marked open when it holds a
// checked genre, and build the summary badges for the saved list.
$xg_groups = [];
$xg_labels = [];
while ($g = $xg_rows->fetch()) {
    $gid = (int)$g->genreid;
    $xg_groups[$g->genremeta][] = ['id' => $gid, 'desc' => $g->genredesc];
    if (isset($xg_selected[$gid])) {
        $xg_labels[$gid] = $g->genremeta . ': ' . $g->genredesc;
    }
}
?>

<div class="row mt-3">

  <div class="col-md-5">
    <div class="card mb-4">
      <div class="card-header"><h5 class="mb-0">Изменить пароль</h5></div>
      <div class="card-body">
        <?php if ($password_success): ?>
          <div class="alert alert-success"><?= htmlspecialchars($password_success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($password_error): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($password_error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-group mb-2">
            <label for="old_password">Текущий пароль:</label>
            <input type="password" class="form-control" id="old_password" name="old_password" required>
          </div>
          <div class="form-group mb-2">
            <label for="new_password">Новый пароль:</label>
            <input type="password" class="form-control" id="new_password" name="new_password" required>
          </div>
          <div class="form-check mt-1 mb-3">
            <input type="checkbox" class="form-check-input" id="show_password_settings"
              onclick="['old_password','new_password'].forEach(function(id){
                document.getElementById(id).type = this.checked ? 'text' : 'password';
              }, this)">
            <label class="form-check-label" for="show_password_settings">Показать пароли</label>
          </div>
          <button type="submit" name="change_password" class="btn btn-primary">Изменить пароль</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-5">
    <div class="card mb-4">
      <div class="card-header"><h5 class="mb-0">Страница после входа</h5></div>
      <div class="card-body">
        <?php if ($settings_success): ?>
          <div class="alert alert-success"><?= htmlspecialchars($settings_success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="login_redirect" id="redirect_default"
              value="default" <?= $checked['default'] ?>>
            <label class="form-check-label" for="redirect_default">Главная страница</label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="login_redirect" id="redirect_favorites"
              value="favorites" <?= $checked['favorites'] ?>>
            <label class="form-check-label" for="redirect_favorites">Избранное (если есть)</label>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="radio" name="login_redirect" id="redirect_genres"
              value="genres" <?= $checked['genres'] ?>>
            <label class="form-check-label" for="redirect_genres">Жанры</label>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="radio" name="login_redirect" id="redirect_last_book"
              value="last_book" <?= $checked['last_book'] ?>>
            <label class="form-check-label" for="redirect_last_book">Последняя открытая книга</label>
          </div>
          <button type="submit" name="save_settings" class="btn btn-primary">Сохранить</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-5">
    <div class="card mb-4">
      <div class="card-header"><h5 class="mb-0">Режим открытия книги по умолчанию</h5></div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="book_view_mode" id="bvm_content"
              value="contentonly" <?= $bvm_checked['contentonly'] ?>>
            <label class="form-check-label" for="bvm_content">Читать (только текст)</label>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="radio" name="book_view_mode" id="bvm_annotation"
              value="withannotation" <?= $bvm_checked['withannotation'] ?>>
            <label class="form-check-label" for="bvm_annotation">О книге (аннотация и отзывы)</label>
          </div>
          <button type="submit" name="save_settings" class="btn btn-primary">Сохранить</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-5">
    <div class="card mb-4">
      <div class="card-header"><h5 class="mb-0">Вкладка по умолчанию на странице автора</h5></div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="author_default_tab" id="tab_about"
              value="about" <?= $tab_checked['about'] ?>>
            <label class="form-check-label" for="tab_about">Об авторе</label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="author_default_tab" id="tab_alpha"
              value="alpha" <?= $tab_checked['alpha'] ?>>
            <label class="form-check-label" for="tab_alpha">По алфавиту</label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="author_default_tab" id="tab_series"
              value="series" <?= $tab_checked['series'] ?>>
            <label class="form-check-label" for="tab_series">По сериям</label>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="radio" name="author_default_tab" id="tab_year"
              value="year" <?= $tab_checked['year'] ?>>
            <label class="form-check-label" for="tab_year">По году</label>
          </div>
          <button type="submit" name="save_settings" class="btn btn-primary">Сохранить</button>
        </form>
      </div>
    </div>
  </div>

</div>

<div class="row">
  <div class="col-12">
    <div class="card mb-4">
      <div class="card-header">Скрытые жанры</div>
      <div class="card-body">

        <?php if ($xgenres_success !== ''): ?>
          <div class="alert alert-success"><?= htmlspecialchars($xgenres_success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($xgenres_error !== ''): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($xgenres_error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <p class="text-muted">
          Книги отмеченных жанров не будут показываться в списке книг на главной странице.
          Скрытие можно временно отключить кнопкой &laquo;Все жанры&raquo; на главной.
        </p>

        <p>
          <?php if (empty($xg_labels)): ?>
            <span class="text-muted">Ничего не скрыто.</span>
          <?php else: ?>
            <?php foreach ($xg_labels as $lbl): ?>
              <span class="badge bg-secondary mb-1"><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
          <?php endif; ?>
        </p>

        <form method="POST" id="xgenresForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <?php foreach ($xg_groups as $meta => $items): ?>
            <?php
              $groupHasChecked = false;
              foreach ($items as $it) {
                  if (isset($xg_selected[$it['id']])) { $groupHasChecked = true; break; }
              }
            ?>
            <details class="mb-2"<?= $groupHasChecked ? ' open' : '' ?>>
              <summary><?= htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') ?></summary>
              <div class="row ms-1 mt-1">
                <?php foreach ($items as $it): ?>
                  <div class="col-sm-6 col-md-4">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="excluded_genres[]"
                        id="xg_<?= $it['id'] ?>" value="<?= $it['id'] ?>"
                        <?= isset($xg_selected[$it['id']]) ? 'checked' : '' ?>>
                      <label class="form-check-label" for="xg_<?= $it['id'] ?>">
                        <?= htmlspecialchars($it['desc'], ENT_QUOTES, 'UTF-8') ?>
                      </label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endforeach; ?>

          <div class="mt-3">
            <button type="submit" name="save_excluded_genres" class="btn btn-primary">Сохранить</button>
            <button type="button" class="btn btn-outline-secondary" id="xgenresClear">Снять все</button>
            <span class="text-muted ms-2">Не более <?= MAX_EXCLUDED_GENRES ?> жанров.</span>
          </div>
        </form>

        <script>
        document.getElementById('xgenresClear').addEventListener('click', function () {
            document.querySelectorAll('#xgenresForm input[name="excluded_genres[]"]').forEach(function (c) {
                c.checked = false;
            });
        });
        </script>

      </div>
    </div>
  </div>
</div>
