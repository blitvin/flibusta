<?php
// Таб просмотра токенов remember-me

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Обработка удаления токена.
// POST + CSRF, like the session tab: as a GET link any page the admin loaded —
// including a book's own markup — could delete tokens by embedding the URL.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        die('CSRF validation failed.');
    }

    $delete_id = (int)$_POST['delete_id'];
    $stmt = $dbh->prepare("DELETE FROM user_tokens WHERE id = ?");
    $stmt->execute([$delete_id]);
}

echo "<h4>Токены remember-me</h4>";

$stmt = $dbh->query("SELECT t.id, t.selector, t.expires_at, u.username FROM user_tokens t JOIN users u ON t.user_id = u.id ORDER BY t.expires_at DESC");
$tokens = $stmt->fetchAll(PDO::FETCH_OBJ);

if ($tokens) {
    echo "<table class='table'><thead><tr><th>ID</th><th>Селектор</th><th>Пользователь</th><th>Истекает</th><th>Действия</th></tr></thead><tbody>";
    foreach ($tokens as $token) {
        $expires_at = htmlspecialchars($token->expires_at);
        $is_expired = strtotime($token->expires_at) < time();
        $expires_class = $is_expired ? 'text-danger' : '';

        echo "<tr>";
        echo "<td>" . htmlspecialchars($token->id) . "</td>";
        echo "<td><code>" . htmlspecialchars($token->selector) . "</code></td>";
        echo "<td>" . htmlspecialchars($token->username) . "</td>";
        echo "<td class='$expires_class'>" . $expires_at . ($is_expired ? ' (истёк)' : '') . "</td>";
        echo "<td>";
        echo "<form method='POST' action='?tokens' style='display:inline;' onsubmit='return confirm(\"Вы уверены, что хотите удалить этот токен?\");'>";
        echo "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfToken) . "'>";
        echo "<input type='hidden' name='delete_id' value='" . htmlspecialchars($token->id) . "'>";
        echo "<button type='submit' class='btn btn-danger btn-sm'>Удалить</button>";
        echo "</form>";
        echo "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
} else {
    echo "<p>Нет активных токенов remember-me.</p>";
}
