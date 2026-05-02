<?php

include_once('../init.php');
ob_start();

session_start();

$error = '';
$username = '';
$remember = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login($dbh, $_POST['username'], $_POST['password'], $webroot, !empty($_POST['rememberMe']))) {
        $location = get_login_redirect($dbh, $_SESSION['user_id'], $webroot);
        header("Location: $location");
        http_response_code(303);
        exit;
    } else {
        $error = "Неправильное имя пользователя или пароль.";
    }
}
?>

<!doctype html>
<html lang="ru">
<head>

<meta charset="utf-8">
<title>Вход в Библиотеку</title>
<!-- подключите ваш общий CSS из проекта -->
<link rel="stylesheet" href="/css/style.css">
<style>
/* базовые улучшения, если нет общего оформления */
.login-page {
    max-width: 420px;
    margin: 60px auto;
    padding: 24px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #fafafa;
}
.login-page h1 { margin-bottom: 18px; }
.login-page .field { margin-bottom: 12px; }
.login-page label { display: block; margin-bottom: 4px; font-weight: 600; }
.login-page input[type="text"],
.login-page input[type="password"] {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #bbb;
    border-radius: 4px;
    font-size: 16px;
}
.login-page .error {
    color: #a00;
    margin-bottom: 12px;
}
.login-page .submit {
    margin-top: 14px;
}
body {
    background-image: url('<?php echo $webroot; ?>/bookshelf.jpeg');
    background-size: cover;
    background-repeat: no-repeat;
    background-attachment: fixed;
}
</style>
</head>
<body>
<div class="login-page">
    <h1>Вход в библиотеку</h1>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post" action="login.php">
        <div class="field">
            <label for="username">Имя пользователя</label>
            <input id="username" type="text" name="username" required
                   value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="field">
            <label for="password">Пароль</label>
            <input id="password" type="password" name="password" required>
            <label style="font-weight:normal; margin-top:4px;">
                <input type="checkbox" onclick="document.getElementById('password').type = this.checked ? 'text' : 'password'">
                Показать пароль
            </label>
        </div>
        <div class="field">
            <label>
                <input type="checkbox" name="rememberMe" <?= $remember ? 'checked' : '' ?>>
                Запомнить меня
            </label>
        </div>
        <div class="submit">
            <button type="submit">Войти</button>
        </div>
    </form>
</div>
</body>
</html>
