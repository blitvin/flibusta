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
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title>Вход в Библиотеку</title>
<link href="<?= $webroot ?>/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<script src="<?= $webroot ?>/bootstrap/js/bootstrap.bundle.min.js"></script>
<link href="<?= $webroot ?>/css/style.css" rel="stylesheet">
<style>
body {
    background-image: url('<?= $webroot ?>/bookshelf.jpeg');
    background-size: cover;
    background-repeat: no-repeat;
    background-attachment: fixed;
    min-height: 100vh;
}
</style>
</head>
<body class="d-flex align-items-center">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-8 col-md-6 col-lg-4">
            <div class="card mt-5 shadow">
                <div class="card-body p-4">
                    <h4 class="card-title mb-3">Вход в библиотеку</h4>
                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <form method="post" action="login.php">
                        <div class="mb-3">
                            <label for="username" class="form-label fw-semibold">Имя пользователя</label>
                            <input id="username" type="text" name="username" class="form-control" required
                                   autocomplete="username"
                                   value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label fw-semibold">Пароль</label>
                            <input id="password" type="password" name="password" class="form-control" required
                                   autocomplete="current-password">
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="showPwd"
                                       onclick="document.getElementById('password').type = this.checked ? 'text' : 'password'">
                                <label class="form-check-label" for="showPwd">Показать пароль</label>
                            </div>
                        </div>
                        <div class="mb-3 form-check">
                            <input class="form-check-input" type="checkbox" name="rememberMe" id="rememberMe"
                                   <?= $remember ? 'checked' : '' ?>>
                            <label class="form-check-label" for="rememberMe">Запомнить меня</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Войти</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
