<?php

class PostgresSessionHandler implements SessionHandlerInterface {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function open($savePath, $sessionName): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read($id): string {
        $stmt = $this->pdo->prepare("SELECT data FROM php_sessions WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetchColumn();
        
        // Postgres BYTEA may come back as stream or string depending on driver settings.
        if ($result === false || $result === null) {
            return '';
        }
        if (is_resource($result)) {
            return stream_get_contents($result);
        }
        return (string)$result;
    }

    public function write($id, $data): bool {
        $userId = null;
        $username = null;
        if (isset($_SESSION) && is_array($_SESSION)) {
            if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
                $userId = (int)$_SESSION['user_id'];
            }
            if (isset($_SESSION['username']) && is_scalar($_SESSION['username'])) {
                $username = substr((string)$_SESSION['username'], 0, 50);
            }
        }
        $ipAddress = isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 64) : null;
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 512) : null;

        // We use an "UPSERT" (ON CONFLICT) to handle creating or updating
        $sql = "INSERT INTO php_sessions (id, data, user_id, username, ip_address, user_agent)
                VALUES (:id, :data, :user_id, :username, :ip_address, :user_agent)
                ON CONFLICT (id) DO UPDATE SET
                data = EXCLUDED.data,
                user_id = EXCLUDED.user_id,
                username = EXCLUDED.username,
                ip_address = EXCLUDED.ip_address,
                user_agent = EXCLUDED.user_agent,
                last_accessed = CURRENT_TIMESTAMP";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'id' => $id,
            'data' => $data, // PDO handles string-to-bytea conversion
            'user_id' => $userId,
            'username' => $username,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    public function destroy($id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function gc($maxlifetime): int|false {
        // Delete sessions that haven't been touched in X seconds
        $sql = "DELETE FROM php_sessions WHERE last_accessed < NOW() - INTERVAL '1 second' * ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$maxlifetime]);
        return $stmt->rowCount();
    }
}