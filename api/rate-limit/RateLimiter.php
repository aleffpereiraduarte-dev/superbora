<?php
/**
 * OneMundo - Rate Limiter simples baseado em sessão/IP
 */
class RateLimiter {

    /**
     * Verifica rate limit
     * @param int $maxRequests Máximo de requisições permitidas
     * @param int $windowSeconds Janela de tempo em segundos
     * @param string|null $key Chave customizada (default: IP + endpoint)
     * @return bool true se permitido, false se bloqueado (já envia resposta 429)
     */
    public static function check(int $maxRequests = 10, int $windowSeconds = 60, ?string $key = null): bool {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        if (!$key) {
            $key = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . ($_SERVER['REQUEST_URI'] ?? '/');
        }

        $storageKey = 'rate_limit_' . md5($key);
        $now = time();

        if (!isset($_SESSION[$storageKey])) {
            $_SESSION[$storageKey] = [];
        }

        // Remove timestamps expirados
        $_SESSION[$storageKey] = array_filter($_SESSION[$storageKey], function($timestamp) use ($now, $windowSeconds) {
            return ($now - $timestamp) < $windowSeconds;
        });

        // Verifica se excedeu o limite
        if (count($_SESSION[$storageKey]) >= $maxRequests) {
            http_response_code(429);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Muitas requisições. Tente novamente em alguns segundos.'
            ]);
            return false;
        }

        // Registra requisição
        $_SESSION[$storageKey][] = $now;

        return true;
    }
}
