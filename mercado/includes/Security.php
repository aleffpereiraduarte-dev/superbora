<?php
/**
 * Security.php - Sistema de Segurança OneMundo
 * Rate Limiting, CSRF, Audit Log, Input Validation
 */

class Security {
    private static $pdo = null;

    // Rate limiting config
    const RATE_LIMIT_WINDOW = 60; // segundos
    const RATE_LIMIT_MAX_REQUESTS = 60; // requests por janela
    const RATE_LIMIT_API_MAX = 30; // requests para APIs sensíveis

    /**
     * Inicializa conexão PDO
     */
    private static function getDB() {
        if (self::$pdo === null) {
            $configFile = dirname(__DIR__, 2) . '/config.php';
            if (file_exists($configFile)) {
                require_once $configFile;
            }
            self::$pdo = new PDO(
                "mysql:host=" . (defined('DB_HOSTNAME') ? DB_HOSTNAME : 'localhost') .
                ";dbname=" . (defined('DB_DATABASE') ? DB_DATABASE : 'love1') .
                ";charset=utf8mb4",
                defined('DB_USERNAME') ? DB_USERNAME : 'root',
                defined('DB_PASSWORD') ? DB_PASSWORD : '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        return self::$pdo;
    }

    /**
     * Criar tabelas de segurança se não existirem
     */
    public static function initTables() {
        $pdo = self::getDB();

        // Tabela de rate limiting
        $pdo->exec("CREATE TABLE IF NOT EXISTS om_security_rate_limit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            endpoint VARCHAR(255) NOT NULL,
            requests INT DEFAULT 1,
            window_start DATETIME NOT NULL,
            INDEX idx_ip_endpoint (ip, endpoint),
            INDEX idx_window (window_start)
        ) ENGINE=InnoDB");

        // Tabela de audit log
        $pdo->exec("CREATE TABLE IF NOT EXISTS om_security_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_type ENUM('admin', 'shopper', 'delivery', 'customer', 'system') NOT NULL,
            user_id INT DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(50) DEFAULT NULL,
            entity_id INT DEFAULT NULL,
            details JSON DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_type, user_id),
            INDEX idx_action (action),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB");

        // Tabela de CSRF tokens
        $pdo->exec("CREATE TABLE IF NOT EXISTS om_security_csrf_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(128) NOT NULL,
            token VARCHAR(64) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used TINYINT DEFAULT 0,
            INDEX idx_session (session_id),
            INDEX idx_token (token),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB");

        // Limpar tokens expirados
        $pdo->exec("DELETE FROM om_security_csrf_tokens WHERE expires_at < NOW()");
        $pdo->exec("DELETE FROM om_security_rate_limit WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }

    /**
     * Rate Limiting
     */
    public static function checkRateLimit($endpoint = 'default', $maxRequests = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $maxRequests = $maxRequests ?? self::RATE_LIMIT_MAX_REQUESTS;

        try {
            $pdo = self::getDB();

            // Verificar requests na janela atual
            $stmt = $pdo->prepare("
                SELECT requests, window_start
                FROM om_security_rate_limit
                WHERE ip = ? AND endpoint = ? AND window_start > DATE_SUB(NOW(), INTERVAL ? SECOND)
                ORDER BY window_start DESC LIMIT 1
            ");
            $stmt->execute([$ip, $endpoint, self::RATE_LIMIT_WINDOW]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                if ($row['requests'] >= $maxRequests) {
                    // Limite excedido
                    self::auditLog('system', null, 'rate_limit_exceeded', 'security', null, [
                        'ip' => $ip,
                        'endpoint' => $endpoint,
                        'requests' => $row['requests']
                    ]);
                    return false;
                }

                // Incrementar contador
                $stmt = $pdo->prepare("
                    UPDATE om_security_rate_limit
                    SET requests = requests + 1
                    WHERE ip = ? AND endpoint = ? AND window_start > DATE_SUB(NOW(), INTERVAL ? SECOND)
                ");
                $stmt->execute([$ip, $endpoint, self::RATE_LIMIT_WINDOW]);
            } else {
                // Nova janela
                $stmt = $pdo->prepare("
                    INSERT INTO om_security_rate_limit (ip, endpoint, requests, window_start)
                    VALUES (?, ?, 1, NOW())
                ");
                $stmt->execute([$ip, $endpoint]);
            }

            return true;
        } catch (Exception $e) {
            error_log("Rate limit error: " . $e->getMessage());
            return true; // Em caso de erro, permitir (fail open)
        }
    }

    /**
     * Gerar token CSRF
     */
    public static function generateCSRFToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = bin2hex(random_bytes(32));
        $sessionId = session_id();

        try {
            $pdo = self::getDB();
            $stmt = $pdo->prepare("
                INSERT INTO om_security_csrf_tokens (session_id, token, expires_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
            ");
            $stmt->execute([$sessionId, $token]);
        } catch (Exception $e) {
            error_log("CSRF token generation error: " . $e->getMessage());
        }

        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    /**
     * Validar token CSRF
     */
    public static function validateCSRFToken($token) {
        if (empty($token)) {
            return false;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Verificar na sessão primeiro (mais rápido)
        if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
            return true;
        }

        // Verificar no banco
        try {
            $pdo = self::getDB();
            $stmt = $pdo->prepare("
                SELECT id FROM om_security_csrf_tokens
                WHERE token = ? AND session_id = ? AND expires_at > NOW() AND used = 0
            ");
            $stmt->execute([$token, session_id()]);
            $row = $stmt->fetch();

            if ($row) {
                // Marcar como usado
                $stmt = $pdo->prepare("UPDATE om_security_csrf_tokens SET used = 1 WHERE id = ?");
                $stmt->execute([$row['id']]);
                return true;
            }
        } catch (Exception $e) {
            error_log("CSRF validation error: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Audit Log
     */
    public static function auditLog($userType, $userId, $action, $entityType = null, $entityId = null, $details = null) {
        try {
            $pdo = self::getDB();
            $stmt = $pdo->prepare("
                INSERT INTO om_security_audit_log
                (user_type, user_id, action, entity_type, entity_id, details, ip, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userType,
                $userId,
                $action,
                $entityType,
                $entityId,
                $details ? json_encode($details) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
            ]);
        } catch (Exception $e) {
            error_log("Audit log error: " . $e->getMessage());
        }
    }

    /**
     * Validação de Input
     */
    public static function sanitizeInput($input, $type = 'string') {
        if ($input === null) {
            return null;
        }

        switch ($type) {
            case 'int':
                return filter_var($input, FILTER_VALIDATE_INT) !== false ? intval($input) : 0;

            case 'float':
                return filter_var($input, FILTER_VALIDATE_FLOAT) !== false ? floatval($input) : 0.0;

            case 'email':
                return filter_var($input, FILTER_VALIDATE_EMAIL) ?: '';

            case 'phone':
                return preg_replace('/[^0-9]/', '', $input);

            case 'alphanumeric':
                return preg_replace('/[^a-zA-Z0-9]/', '', $input);

            case 'html':
                return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');

            case 'string':
            default:
                return trim(strip_tags($input));
        }
    }

    /**
     * Validar senha forte
     */
    public static function validatePassword($password) {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Senha deve ter no mínimo 8 caracteres';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Senha deve conter ao menos uma letra maiúscula';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Senha deve conter ao menos uma letra minúscula';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Senha deve conter ao menos um número';
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * Middleware para APIs - aplicar rate limit e CSRF
     */
    public static function apiMiddleware($requireCSRF = false, $maxRequests = null) {
        $endpoint = $_SERVER['REQUEST_URI'] ?? 'unknown';

        // Rate limiting
        if (!self::checkRateLimit($endpoint, $maxRequests)) {
            http_response_code(429);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Muitas requisições. Tente novamente em alguns segundos.',
                'code' => 'RATE_LIMIT_EXCEEDED'
            ]);
            exit;
        }

        // CSRF para POST/PUT/DELETE
        if ($requireCSRF && in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
            $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            if (!self::validateCSRFToken($token)) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => 'Token de segurança inválido. Recarregue a página.',
                    'code' => 'INVALID_CSRF_TOKEN'
                ]);
                exit;
            }
        }
    }

    /**
     * Headers de segurança
     */
    public static function setSecurityHeaders() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

// Inicializar tabelas na primeira execução
try {
    Security::initTables();
} catch (Exception $e) {
    error_log("Security init error: " . $e->getMessage());
}
