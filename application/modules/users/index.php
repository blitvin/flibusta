<?php
// Users management module router

function users_is_https(): bool {
    if (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (! empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') {
        return true;
    }
    if (! empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        return true;
    }
    return false;
}

function users_render_tabs(string $active): void {
    $tabs = [
        'users' => 'Пользователи',
        'sessions' => 'Сессии',
        'tokens' => 'Remember-Me Токены',
        'logs' => 'Активность пользователей',
    ];

    echo "<div class='row'><div class='col-sm-12'><div class='card'>";
    echo "<h4 class='rounded-top p-1' style='background: #d0d0d0;'>Управление пользователями</h4>";
    echo "<div class='card-body'>";

    foreach ($tabs as $key => $title) {
        if ($key === $active) {
            echo "<span class='btn btn-secondary m-1 disabled'>$title</span>";
        } else {
            $href = '?';
            if ($key !== 'users') {
                $href .= $key;
            }
            echo "<a class='btn btn-outline-primary m-1' href='" . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . "'>$title</a>";
        }
    }

    echo "</div></div></div></div>";

    if (! users_is_https()) {
        echo "<div class='row mt-2'><div class='col-sm-12'><div class='alert alert-danger'>Внимание: соединение не защищено (HTTP). Использование HTTP вместо HTTPS повышает риск перехвата данных.</div></div></div>";
    }
}

$activeTab = 'users';
if (isset($_GET['sessions'])) {
    $activeTab = 'sessions';
} elseif (isset($_GET['tokens'])) {
    $activeTab = 'tokens';
} elseif (isset($_GET['logs'])) {
    $activeTab = 'logs';
}

if (rand(0,100) < 5) {
		cleanupUserMgmtTables($dbh);
}

users_render_tabs($activeTab);

switch ($activeTab) {
    case 'sessions':
        include __DIR__ . '/sessions.php';
        break;
    case 'tokens':
        include __DIR__ . '/tokens.php';
        break;
    case 'logs':
        include __DIR__ . '/logs.php';
        break;
    default:
        include __DIR__ . '/users_tab.php';
        break;
}
?>