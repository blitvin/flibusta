<?php
// Таб логов входа

echo "<h4>Логи попыток входа</h4>";

$outcomeLabels = [
    LOGIN_OK => 'Успешный вход',
    LOGIN_BAD_PASSWORD => 'Неверный пароль',
    LOGIN_AGENT_MISMATCH => 'Несовпадение User-Agent',
    LOGIN_LOCKED_FAILCOUNT => 'Пользователь заблокирован по количеству неудачных входов',
    LOGIN_IP_LOCKED => 'IP-адрес заблокирован',
    LOGIN_ADMIN_ACCESS_ATTEMPT => 'Попытка доступа администратора',
    LOGIN_OPDS_BAD_PASSWORD => 'Неверный пароль (OPDS)',
];

$stmt = $dbh->query("SELECT ip_address, username, user_agent, outcome, attempt_time FROM login_attempts ORDER BY attempt_time DESC LIMIT 100");
$attempts = $stmt->fetchAll(PDO::FETCH_OBJ);

if ($attempts) {
    echo "<table class='table'><thead><tr><th>IP адрес</th><th>Пользователь</th><th>User Agent</th><th>Результат</th><th>Время</th></tr></thead><tbody>";
    foreach ($attempts as $attempt) {
        $outcome = $outcomeLabels[$attempt->outcome] ?? "Неизвестный код ({$attempt->outcome})";
        echo "<tr><td>" . htmlspecialchars($attempt->ip_address) . "</td><td>" . htmlspecialchars(substr($attempt->username ?? '',0,50)) . "</td><td>" . htmlspecialchars(substr($attempt->user_agent ?? '', 0, 50)) . "</td><td>" . htmlspecialchars($outcome) . "</td><td>" . htmlspecialchars($attempt->attempt_time) . "</td></tr>";
    }
    echo "</tbody></table>";
} else {
    echo "<p>Нет записей о попытках входа.</p>";
}
