<?php
$current_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

if ($current_user_id === 0) {
    $login_url = htmlspecialchars($webroot . '/login.php', ENT_QUOTES, 'UTF-8');
    echo "<div class='alert alert-warning mt-3'>Для доступа к настройкам необходимо <a href='$login_url'>войти в систему</a>.</div>";
    return;
}

$csrfToken = get_csrf_token();

$stmt = $dbh->prepare("SELECT login_redirect FROM user_settings WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$saved = $stmt->fetch();
$login_redirect = $saved ? $saved->login_redirect : 'default';

$password_success = '';
$password_error   = '';
$settings_success = '';

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
                $stmt = $dbh->prepare("DELETE FROM php_sessions WHERE user_id = ? AND id != ?");
                $stmt->execute([$current_user_id, session_id()]);
                $password_success = 'Пароль успешно изменён.';
            } else {
                $password_error = 'Неверный текущий пароль.';
            }
        }
    } elseif (isset($_POST['save_settings'])) {
        $new_redirect = $_POST['login_redirect'] ?? 'default';
        if (!in_array($new_redirect, ['default', 'favorites', 'genres'], true)) {
            $new_redirect = 'default';
        }
        $stmt = $dbh->prepare("INSERT INTO user_settings (user_id, login_redirect) VALUES (?, ?)
            ON CONFLICT (user_id) DO UPDATE SET login_redirect = EXCLUDED.login_redirect");
        $stmt->execute([$current_user_id, $new_redirect]);
        $login_redirect   = $new_redirect;
        $settings_success = 'Настройки сохранены.';
    }
}

$checked = [
    'default'   => $login_redirect === 'default'   ? 'checked' : '',
    'favorites' => $login_redirect === 'favorites' ? 'checked' : '',
    'genres'    => $login_redirect === 'genres'    ? 'checked' : '',
];
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
          <button type="submit" name="save_settings" class="btn btn-primary">Сохранить</button>
        </form>
      </div>
    </div>
  </div>

</div>
