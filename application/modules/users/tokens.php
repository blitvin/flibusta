<?php
// Таб просмотра токенов remember-me

// Обработка удаления токена
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    $stmt = $dbh->prepare("DELETE FROM user_tokens WHERE id = ?");
    $stmt->execute([$delete_id]);
    // Можно добавить сообщение об успешном удалении, но пока просто удаляем
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
        echo "<td><a class='btn btn-danger btn-sm' href='?tokens&delete_id=" . htmlspecialchars($token->id) . "' onclick='return confirm(\"Вы уверены, что хотите удалить этот токен?\");'>Удалить</a></td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
} else {
    echo "<p>Нет активных токенов remember-me.</p>";
}