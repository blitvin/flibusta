<?php
// Таб просмотра текущих сессий

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Обработка удаления сессии
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        die('CSRF validation failed.');
    }

    $delete_id = $_POST['delete_id'];
    $stmt = $dbh->prepare("DELETE FROM php_sessions WHERE id = ?");
    $stmt->execute([$delete_id]);
}

echo "<h4>Активные сессии</h4>";

$stmt = $dbh->query("SELECT id, last_accessed, username, user_agent, ip_address FROM php_sessions ORDER BY last_accessed DESC");
$sessions = $stmt->fetchAll(PDO::FETCH_OBJ);

if ($sessions) {
    echo "<table class='table'><thead><tr><th>ID сессии</th><th>Последний доступ</th><th>Пользователь</th><th>User-Agent</th><th>IP входа</th><th>Действия</th></tr></thead><tbody>";
    foreach ($sessions as $session) {
        $username = isset($session->username) ? htmlspecialchars($session->username) : '';
        $user_agent = isset($session->user_agent) ? htmlspecialchars($session->user_agent) : '';
        $login_ip = isset($session->ip_address) ? htmlspecialchars($session->ip_address) : '';
        echo "<tr>";
        echo "<td>" . htmlspecialchars($session->id) . "</td>";
        echo "<td>" . htmlspecialchars($session->last_accessed) . "</td>";
        echo "<td>" . $username . "</td>";
        echo "<td>" . $user_agent . "</td>";
        echo "<td>" . $login_ip . "</td>";
        echo "<td>";
        echo "<form method='POST' action='?sessions' style='display:inline;'>";
        echo "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfToken) . "'>";
        echo "<input type='hidden' name='delete_id' value='" . htmlspecialchars($session->id) . "'>";
        echo "<button type='submit' class='btn btn-danger btn-sm'>Удалить</button>";
        echo "</form>";
        echo "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
} else {
    echo "<p>Нет активных сессий.</p>";
}

