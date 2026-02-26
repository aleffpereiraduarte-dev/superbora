<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * ONEMUNDO CSRF PROTECTION
 * Sistema de proteção contra Cross-Site Request Forgery
 * ══════════════════════════════════════════════════════════════════════════════
 */

class OmCsrf {
    private static $instance = null;
    private $tokenName = 'csrf_token';
    private $headerName = 'X-CSRF-Token';
    private $tokenExpiry = 3600; // 1 hora

    private function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Gera um novo token CSRF
     */
    public function generateToken(): string {
        $token = bin2hex(random_bytes(32));

        $_SESSION['csrf_tokens'][$token] = [
            'created' => time(),
            'expires' => time() + $this->tokenExpiry
        ];

        // Limpar tokens expirados
        $this->cleanExpiredTokens();

        return $token;
    }

    /**
     * Valida um token CSRF
     */
    public function validateToken(?string $token): bool {
        if (empty($token)) {
            return false;
        }

        if (!isset($_SESSION['csrf_tokens'][$token])) {
            return false;
        }

        $tokenData = $_SESSION['csrf_tokens'][$token];

        // Verificar expiração
        if ($tokenData['expires'] < time()) {
            unset($_SESSION['csrf_tokens'][$token]);
            return false;
        }

        // Token válido - remover para uso único (one-time token)
        unset($_SESSION['csrf_tokens'][$token]);

        return true;
    }

    /**
     * Valida token sem removê-lo (para múltiplos submits)
     */
    public function validateTokenPersistent(?string $token): bool {
        if (empty($token)) {
            return false;
        }

        if (!isset($_SESSION['csrf_tokens'][$token])) {
            return false;
        }

        return $_SESSION['csrf_tokens'][$token]['expires'] >= time();
    }

    /**
     * Obtém token da requisição (POST, header ou query)
     */
    public function getTokenFromRequest(): ?string {
        // Primeiro tenta header (para AJAX)
        $headers = getallheaders();
        if (!empty($headers[$this->headerName])) {
            return $headers[$this->headerName];
        }
        if (!empty($headers[strtolower($this->headerName)])) {
            return $headers[strtolower($this->headerName)];
        }

        // Depois POST
        if (!empty($_POST[$this->tokenName])) {
            return $_POST[$this->tokenName];
        }

        // Query string tokens removed for security — tokens must not appear in URLs
        // as they can leak via Referer headers, browser history, and server logs.

        return null;
    }

    /**
     * Middleware: Requer CSRF válido para requisições que modificam dados
     */
    public function requireValidToken(): void {
        // Métodos seguros não precisam de CSRF
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return;
        }

        $token = $this->getTokenFromRequest();

        if (!$this->validateToken($token)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Token CSRF inválido ou expirado. Recarregue a página.',
                'code' => 'CSRF_INVALID'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * Gera input hidden com token para formulários
     */
    public function getHiddenInput(): string {
        $token = $this->generateToken();
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($this->tokenName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Gera meta tag com token para AJAX
     */
    public function getMetaTag(): string {
        $token = $this->generateToken();
        return sprintf(
            '<meta name="csrf-token" content="%s">',
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Retorna nome do campo e header para uso em JS
     */
    public function getConfig(): array {
        return [
            'field_name' => $this->tokenName,
            'header_name' => $this->headerName,
            'token' => $this->generateToken()
        ];
    }

    /**
     * Limpa tokens expirados da sessão
     */
    private function cleanExpiredTokens(): void {
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
            return;
        }

        $now = time();
        foreach ($_SESSION['csrf_tokens'] as $token => $data) {
            if ($data['expires'] < $now) {
                unset($_SESSION['csrf_tokens'][$token]);
            }
        }

        // Limitar quantidade de tokens (proteção contra spam)
        if (count($_SESSION['csrf_tokens']) > 100) {
            // Manter apenas os 50 mais recentes
            uasort($_SESSION['csrf_tokens'], fn($a, $b) => $b['created'] - $a['created']);
            $_SESSION['csrf_tokens'] = array_slice($_SESSION['csrf_tokens'], 0, 50, true);
        }
    }
}

/**
 * Helper global
 */
function om_csrf(): OmCsrf {
    return OmCsrf::getInstance();
}
