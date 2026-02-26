<?php
/**
 * Session Manager - OneMundo Delivery
 * Gerencia sessões de forma segura
 */

class SessionManager {
    private $sessionStarted = false;
    
    public function __construct() {
        $this->startSecureSession();
    }
    
    public function startSecureSession() {
        if ($this->sessionStarted || session_status() === PHP_SESSION_ACTIVE) {
            $this->sessionStarted = true;
            return;
        }
        
        // Configurações de segurança da sessão
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Lax');
        
        session_start();
        $this->sessionStarted = true;
        
        // Regenerar ID periodicamente (a cada 30 minutos)
        if (!isset($_SESSION['session_created'])) {
            $_SESSION['session_created'] = time();
        } elseif (time() - $_SESSION['session_created'] > 1800) {
            $this->regenerate();
            $_SESSION['session_created'] = time();
        }
    }
    
    public function regenerate() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
    
    public function destroy() {
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        
        session_destroy();
    }
    
    public function get($key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }
    
    public function set($key, $value) {
        $_SESSION[$key] = $value;
    }
    
    public function has($key) {
        return isset($_SESSION[$key]);
    }
    
    public function remove($key) {
        unset($_SESSION[$key]);
    }
    
    public function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public function validateCSRFToken($token) {
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public function setUserAgentHash() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $_SESSION['user_agent_hash'] = hash('sha256', $ua . session_id());
    }
    
    public function validateUserAgent() {
        if (!isset($_SESSION['user_agent_hash'])) {
            return true; // Primeira vez
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $current_hash = hash('sha256', $ua . session_id());
        return hash_equals($_SESSION['user_agent_hash'], $current_hash);
    }
}
