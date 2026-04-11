<?php
// Пользователи (основной таб)

function invalidateUserSessions($dbh, $user_id) {
    $stmt_delete = $dbh->prepare("DELETE FROM php_sessions WHERE user_id = ?");
    $stmt_delete->execute([$user_id]);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Обработка изменения ролей пользователей
$password_updated_username = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        die('CSRF validation failed.');
    }

    if (isset($_POST['to_admin'])) {
        $user_id = $_POST['id'];
        $stmt = $dbh->prepare("UPDATE users SET is_admin = true WHERE id = ?");
        $stmt->execute([$user_id]);
    }
    
    if (isset($_POST['to_regular_user'])) {
        $user_id = $_POST['id'];
        $stmt = $dbh->prepare("UPDATE users SET is_admin = false WHERE id = ?");
        $stmt->execute([$user_id]);
    }
    
    if (isset($_POST['delete_user'])) {
        $user_id = $_POST['id'];
        
        // Invalidate all sessions for this user before deleting
        invalidateUserSessions($dbh, $user_id);
        
        $stmt = $dbh->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        // Note: user_tokens are automatically deleted due to ON DELETE CASCADE constraint
    }
    
    if (isset($_POST['add_or_update_user'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        
        if (!empty($username) && !empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Check if user exists
            $stmt = $dbh->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $existing_user = $stmt->fetch(PDO::FETCH_OBJ);
            
            if ($existing_user) {
                // Update password for existing user
                $stmt = $dbh->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
                $stmt->execute([$hashed_password, $username]);
                
                // Invalidate all sessions for this user
                invalidateUserSessions($dbh, $existing_user->id);
                
                $password_updated_username = $username;
            } else {
                // Insert new user (is_admin defaults to FALSE)
                $stmt = $dbh->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
                $stmt->execute([$username, $hashed_password]);
            }
        }
    }
}

echo "<h4>Пользователи</h4>";

$stmt = $dbh->query("SELECT id, username, is_admin FROM users ORDER BY id");
$users = $stmt->fetchAll(PDO::FETCH_OBJ);

if ($users) {
    echo "<table class='table'><thead><tr><th>ID</th><th>Имя пользователя</th><th>Админ</th><th>Действия</th></tr></thead><tbody>";
    foreach ($users as $user) {
        $is_protected = ($user->id == $_SESSION['user_id']) || ($user->username == getenv('FLIBUSTA_APP_ADMIN'));
        echo "<tr>";
        echo "<td>" . htmlspecialchars($user->id) . "</td>";
        echo "<td>" . htmlspecialchars($user->username) . "</td>";
        echo "<td>" . ($user->is_admin ? 'Да' : 'Нет') . "</td>";
        echo "<td>";
        
        if (!$is_protected) {
            // Role change form
            if ($user->is_admin) {
                echo "<form method='POST' style='display:inline;'>";
                echo "<input type='hidden' name='id' value='" . htmlspecialchars($user->id) . "'>";
                echo "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfToken) . "'>";
                echo "<button type='submit' name='to_regular_user' class='btn btn-warning btn-sm'>Сделать читателем</button>";
                echo "</form>";
            } else {
                echo "<form method='POST' style='display:inline;'>";
                echo "<input type='hidden' name='id' value='" . htmlspecialchars($user->id) . "'>";
                echo "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfToken) . "'>";
                echo "<button type='submit' name='to_admin' class='btn btn-info btn-sm'>Сделать админом</button>";
                echo "</form>";
            }
            
            // Delete user form
            echo "<form method='POST' style='display:inline;' onsubmit='return confirm(\"Вы уверены, что хотите удалить пользователя?\");'>";
            echo "<input type='hidden' name='id' value='" . htmlspecialchars($user->id) . "'>";
            echo "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfToken) . "'>";
            echo "<button type='submit' name='delete_user' class='btn btn-danger btn-sm'>Удалить</button>";
            echo "</form>";
        } else {
            echo "<em>Защищён</em>";
        }
        
        echo "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
} else {
    echo "<p>Нет пользователей.</p>";
}

if ($password_updated_username) {
    echo "<div class='alert alert-success mt-3'>";
    echo "<strong>Пароль обновлён!</strong> Пароль для пользователя <strong>" . htmlspecialchars($password_updated_username) . "</strong> был успешно изменён.";
    echo "</div>";
}

// Form for adding/updating user
echo "<h5>Добавить/обновить пользователя</h5>";
echo "<form method='POST'>";
echo "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfToken) . "'>";
echo "<div class='form-group'>";
echo "<label for='username'>Имя пользователя:</label>";
echo "<input type='text' class='form-control' id='username' name='username' required>";
echo "</div>";
echo "<div class='form-group'>";
echo "<label for='password'>Пароль:</label>";
echo "<input type='password' class='form-control' id='password' name='password' required>";
echo "</div>";
echo "<button type='submit' name='add_or_update_user' class='btn btn-primary'>Сохранить</button>";
echo "</form>";
