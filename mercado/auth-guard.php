<?php
/**
 * Auth Guard - Proteção de Autenticação
 * Verifica se usuário está logado e tem permissão
 * 
 * CORRIGIDO: Adicionado session_name('OCSESSID') para usar mesma sessão do OpenCart
 */

if (!defined('AUTH_GUARD_LOADED')) {
    define('AUTH_GUARD_LOADED', true);
}

// Iniciar sessão se não estiver iniciada - USANDO OCSESSID DO OPENCART!
if (session_status() === PHP_SESSION_NONE) {
    session_name('OCSESSID');
    session_start();
}

/**
 * Verifica se usuário está autenticado
 */
function isAuthenticated() {
    return isset($_SESSION['user_id']) || isset($_SESSION['customer_id']) || isset($_SESSION['cliente_id']);
}

/**
 * Verifica se é admin
 */
function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

/**
 * Verifica se é shopper
 */
function isShopper() {
    return isset($_SESSION['shopper_id']) || isset($_SESSION['is_shopper']);
}

/**
 * Verifica se é delivery
 */
function isDelivery() {
    return isset($_SESSION['delivery_id']) || isset($_SESSION['is_delivery']);
}

/**
 * Verifica se é parceiro (mercado)
 */
function isPartner() {
    return isset($_SESSION['partner_id']) || isset($_SESSION['market_id']);
}

/**
 * Obtém ID do usuário atual
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? $_SESSION['customer_id'] ?? $_SESSION['cliente_id'] ?? null;
}

/**
 * Obtém dados do usuário atual
 */
function getCurrentUser() {
    return $_SESSION['user'] ?? $_SESSION['customer'] ?? null;
}

/**
 * Redireciona para login se não autenticado
 */
function requireAuth($redirectTo = '/mercado/login.php') {
    if (!isAuthenticated()) {
        header('Location: ' . $redirectTo);
        exit;
    }
}

/**
 * Redireciona para login de admin se não for admin
 */
function requireAdmin($redirectTo = '/mercado/admin/login.php') {
    if (!isAdmin()) {
        header('Location: ' . $redirectTo);
        exit;
    }
}

/**
 * Verifica token CSRF
 */
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Gera token CSRF
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica rate limit básico
 */
function checkRateLimit($key, $maxAttempts = 60, $perSeconds = 60) {
    $cacheKey = 'rate_limit_' . $key;
    
    if (!isset($_SESSION[$cacheKey])) {
        $_SESSION[$cacheKey] = ['count' => 0, 'reset' => time() + $perSeconds];
    }
    
    if (time() > $_SESSION[$cacheKey]['reset']) {
        $_SESSION[$cacheKey] = ['count' => 0, 'reset' => time() + $perSeconds];
    }
    
    $_SESSION[$cacheKey]['count']++;
    
    return $_SESSION[$cacheKey]['count'] <= $maxAttempts;
}

/**
 * Log de segurança
 */
function securityLog($action, $details = []) {
    $log = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => $action,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'user_id' => getCurrentUserId(),
        'details' => $details
    ];
    
    // Log para arquivo se necessário
    // error_log(json_encode($log));
}
?>
