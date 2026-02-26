<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * 🔒 SECURITY CORE - OneMundo Mercado
 * ══════════════════════════════════════════════════════════════════════════════
 * Segurança centralizada - incluir no início de cada arquivo
 * ══════════════════════════════════════════════════════════════════════════════
 */

// Iniciar sessão segura
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Headers de segurança
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ══════════════════════════════════════════════════════════════════════════════
// CSRF Protection
// ══════════════════════════════════════════════════════════════════════════════
function generateCSRF() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRF($token = null) {
    $token = $token ?? ($_POST['csrf_token'] ?? '');
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRF()) . '">';
}

// ══════════════════════════════════════════════════════════════════════════════
// Rate Limiting (Session-based - simples e eficaz)
// ══════════════════════════════════════════════════════════════════════════════
function checkRateLimit($key = 'login', $maxAttempts = 5, $decayMinutes = 15) {
    $rateLimitKey = "rate_limit_{$key}";
    
    if (!isset($_SESSION[$rateLimitKey])) {
        $_SESSION[$rateLimitKey] = ['count' => 0, 'first_attempt' => time()];
    }
    
    $data = &$_SESSION[$rateLimitKey];
    
    // Reset se passou o tempo
    if (time() - $data['first_attempt'] > ($decayMinutes * 60)) {
        $data = ['count' => 0, 'first_attempt' => time()];
    }
    
    return $data['count'] < $maxAttempts;
}

function incrementRateLimit($key = 'login') {
    $rateLimitKey = "rate_limit_{$key}";
    if (!isset($_SESSION[$rateLimitKey])) {
        $_SESSION[$rateLimitKey] = ['count' => 0, 'first_attempt' => time()];
    }
    $_SESSION[$rateLimitKey]['count']++;
}

function clearRateLimit($key = 'login') {
    unset($_SESSION["rate_limit_{$key}"]);
}

function getRateLimitRemaining($key = 'login', $decayMinutes = 15) {
    $rateLimitKey = "rate_limit_{$key}";
    if (!isset($_SESSION[$rateLimitKey])) {
        return $decayMinutes * 60;
    }
    $elapsed = time() - $_SESSION[$rateLimitKey]['first_attempt'];
    return max(0, ($decayMinutes * 60) - $elapsed);
}

// ══════════════════════════════════════════════════════════════════════════════
// Sanitização e Validação
// ══════════════════════════════════════════════════════════════════════════════
function sanitizeEmail($email) {
    $email = trim($email);
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
}

function sanitizePhone($phone) {
    return preg_replace('/\D/', '', $phone);
}

function sanitizeString($str, $maxLength = 255) {
    $str = trim($str);
    $str = strip_tags($str);
    $str = htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    return substr($str, 0, $maxLength);
}

// ══════════════════════════════════════════════════════════════════════════════
// Conexão Segura com Banco
// ══════════════════════════════════════════════════════════════════════════════
function getSecureDB() {
    static $pdo = null;
    if ($pdo === null) {
        if (!function_exists('getPDO')) {
            require_once dirname(__DIR__) . '/config/database.php';
        }
        $pdo = getPDO();
    }
    return $pdo;
}

// ══════════════════════════════════════════════════════════════════════════════
// Session Security
// ══════════════════════════════════════════════════════════════════════════════
function regenerateSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function destroySession() {
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

// ══════════════════════════════════════════════════════════════════════════════
// Funções de Login Seguro
// ══════════════════════════════════════════════════════════════════════════════
function secureLogin($userId, $userType, $userData = []) {
    regenerateSession();
    
    $_SESSION["{$userType}_id"] = $userId;
    $_SESSION["{$userType}_logged_at"] = time();
    $_SESSION['user_agent_hash'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    
    foreach ($userData as $key => $value) {
        $_SESSION["{$userType}_{$key}"] = $value;
    }
    
    clearRateLimit('login');
}

function verifySession($userType) {
    if (empty($_SESSION["{$userType}_id"])) {
        return false;
    }
    
    // Verificar user agent (proteção contra session hijacking)
    $currentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    if (isset($_SESSION['user_agent_hash']) && $_SESSION['user_agent_hash'] !== $currentHash) {
        destroySession();
        return false;
    }
    
    // Verificar timeout (2 horas)
    if (isset($_SESSION["{$userType}_logged_at"]) && 
        (time() - $_SESSION["{$userType}_logged_at"]) > 7200) {
        destroySession();
        return false;
    }
    
    return true;
}

// ══════════════════════════════════════════════════════════════════════════════
// Helper para mensagens de erro genéricas (evita enumeration)
// ══════════════════════════════════════════════════════════════════════════════
function genericLoginError() {
    return 'Email ou senha incorretos';
}
