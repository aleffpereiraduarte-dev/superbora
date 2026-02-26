<?php
// Configurações seguras de sessão
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Gerar token CSRF se não existir
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Função para validar CSRF token
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Rate limiting
// Configurar conexão com banco primeiro
$_oc = dirname(dirname(__DIR__));
if (file_exists($_oc."/config.php")) {
    require_once($_oc."/config.php");
} else {
    error_log("Config file not found at expected location");
    die("Configuration error");
}

// Conexão será criada pela classe Database mais adiante - remover esta duplicação

$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$trusted_proxies = ['127.0.0.1', '::1', '10.0.0.1'];
if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && in_array($_SERVER['REMOTE_ADDR'], $trusted_proxies, true)) {
    $forwarded_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $potential_ip = filter_var(trim($forwarded_ips[0]), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    $client_ip = $potential_ip ?: $_SERVER['REMOTE_ADDR'];
}
$rate_key = 'rate_' . hash('sha256', session_id() . $client_ip);
// Definir prefix primeiro
$prefix = defined("DB_PREFIX") ? DB_PREFIX : "oc_";

// Validação rigorosa do prefix
if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,6}_$/', $prefix) || strlen($prefix) > 10) {
    error_log('Invalid table prefix detected: ' . $prefix);
    session_destroy();
    http_response_code(500);
    die('Configuration error');
}

// Whitelist de prefixes permitidos
$allowed_prefixes = ['oc_', 'onemundo_', 'mercado_'];
if (!in_array($prefix, $allowed_prefixes, true)) {
    error_log('Unauthorized table prefix: ' . $prefix);
    session_destroy();
    die('Configuration error');
}

$escaped_prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
$sanitized_tables = [
    'rate_limiting' => '`' . $escaped_prefix . 'rate_limiting`',
    'customer' => '`' . $escaped_prefix . 'customer`',
    'wallet' => '`' . $escaped_prefix . 'customer_wallet`',
    'transactions' => '`' . $escaped_prefix . 'wallet_transactions`'
];

// Rate limiting otimizado
$rate_factors = [
    $client_ip,
    session_id(),
    hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''),
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
    date('Y-m-d-H-i') // Janela menor
];
$rate_key = hash('sha256', implode('|', $rate_factors) . (defined('RATE_SALT') ? RATE_SALT : 'default_salt'));
// Inicializar conexão antes do rate limiting
$db = Database::getInstance();
$pdo = $db->getPDO();

// Rate limiting otimizado com procedure ou query única
$stmt = $pdo->prepare("
    INSERT INTO {$sanitized_tables['rate_limiting']} (ip_hash, attempts, last_attempt) 
    VALUES (?, 1, NOW()) 
    ON DUPLICATE KEY UPDATE 
        attempts = IF(last_attempt < DATE_SUB(NOW(), INTERVAL 1 MINUTE), 1, attempts + 1),
        last_attempt = NOW();
    SELECT attempts FROM {$sanitized_tables['rate_limiting']} WHERE ip_hash = ? LIMIT 1;
");
$stmt->execute([$rate_key, $rate_key]);
$stmt->nextRowset();
$rate_data = $stmt->fetch();
if ($rate_data && $rate_data['attempts'] > 30) {
    http_response_code(429);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Muitas requisições. Tente novamente em alguns minutos.']));
}

// Validar CSRF em requisições POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Token CSRF inválido']));
    }
}
// Headers de segurança configurados uma única vez
$security_headers = [
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'X-XSS-Protection' => '1; mode=block',
    'Content-Security-Policy' => "default-src 'self'; style-src 'self' https://fonts.googleapis.com 'unsafe-inline'; font-src 'self' https://fonts.gstatic.com; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self';",
    'Referrer-Policy' => 'strict-origin-when-cross-origin'
];

// Configurar HSTS apenas se HTTPS
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    $security_headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
}

// Headers devem ser enviados ANTES de qualquer output
if (!headers_sent()) {
    foreach ($security_headers as $header => $value) {
        header($header . ': ' . $value, true);
    }
    header_remove('X-Powered-By');
} else {
    error_log('Security headers not applied - output already started');
}

// Mover validações após headers
if (!isset($_SESSION['session_validated']) || 
    !hash_equals($_SESSION['session_fingerprint'] ?? '', hash('sha256', $_SERVER['HTTP_USER_AGENT'] . $client_ip))) {
    session_regenerate_id(true);
    $_SESSION = []; // Limpar dados da sessão anterior
    $_SESSION['session_validated'] = true;
    $_SESSION['session_start_time'] = time();
    $_SESSION['session_fingerprint'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] . $client_ip);
}
// Regenerar sessão periodicamente (a cada 30 min)
if (isset($_SESSION['session_start_time']) && (time() - $_SESSION['session_start_time']) > 1800) {
    session_regenerate_id(true);
    $_SESSION['session_start_time'] = time();
}
// Inicializar conexão única no início
try {
    $db = Database::getInstance();
    $pdo = $db->getPDO();
} catch (Exception $e) {
    error_log('Database initialization failed: ' . $e->getMessage());
    http_response_code(500);
    die('Service temporarily unavailable');
}

class Database {
    private static $instance = null;
    private $pdo;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        try {
            $dsn = "mysql:host=".(defined("DB_HOSTNAME")?DB_HOSTNAME:"localhost").";dbname=".(defined("DB_DATABASE")?DB_DATABASE:"").";charset=utf8mb4";
            $this->pdo = new PDO($dsn, defined("DB_USERNAME")?DB_USERNAME:"", defined("DB_PASSWORD")?DB_PASSWORD:"", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                PDO::ATTR_TIMEOUT => 10
            ]);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Service temporarily unavailable');
        }
    }
    
    public function getPDO() {
        return $this->pdo;
    }
}
// Prefix já definido e validado anteriormente - usar variável existente
$session_customer_id = $_SESSION["mercado_customer_id"] ?? $_SESSION["customer_id"] ?? 0;

// Verificar consistência entre campos de sessão
if (isset($_SESSION["mercado_customer_id"]) && isset($_SESSION["customer_id"]) && 
    $_SESSION["mercado_customer_id"] !== $_SESSION["customer_id"]) {
    error_log('Session customer_id mismatch detected');
    session_destroy();
    header("Location: /mercado/login/");
    exit;
}

$customer_id = filter_var($session_customer_id, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 2147483647]
]);

if (!isset($_SESSION['authenticated']) || 
    $_SESSION['authenticated'] !== true || 
    $customer_id === false || 
    $customer_id <= 0) {
    session_destroy();
    header("Location: /mercado/login/"); 
    exit; 
}

// Validar se o customer_id existe no banco
// Remover validação duplicada - usar apenas a primeira validação que já foi feita
// Escapar prefix para uso seguro
$escaped_prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);

// Query otimizada que valida customer e busca carteira em uma só operação
// Cache das tabelas sanitizadas (uma vez por execução)
// Remover static desnecessário em escopo global
// Definir uma vez no início após validação do prefix
function getSanitizedTables($prefix) {
    // Dupla sanitização para máxima segurança
    $clean_prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
    if ($clean_prefix !== $prefix) {
        throw new InvalidArgumentException('Invalid prefix characters detected');
    }
    
    return [
        'customer' => '`' . $clean_prefix . 'customer`',
        'wallet' => '`' . $clean_prefix . 'customer_wallet`',
        'transactions' => '`' . $clean_prefix . 'wallet_transactions`',
        'rate_limiting' => '`' . $clean_prefix . 'rate_limiting`'
    ];
}
$sanitized_tables = getSanitizedTables($prefix);
$sql = "SELECT w.balance, w.cashback_balance, w.points, c.customer_id 
        FROM {$sanitized_tables['customer']} c 
        LEFT JOIN {$sanitized_tables['wallet']} w ON c.customer_id = w.customer_id 
        WHERE c.customer_id = ? AND c.status = '1'";
$stmt = $pdo->prepare($sql);
$stmt->execute([$customer_id]);
$result = $stmt->fetch();

class SecurityLogger {
    private static $salt;
    
    private static function getSalt() {
        if (self::$salt === null) {
            self::$salt = defined('LOG_SALT') ? LOG_SALT : 'default_salt_change_me';
        }
        return self::$salt;
    }
    
    public static function logSecurityEvent($event, $details = []) {
        $salt = self::getSalt();
        $log_data = [
            'event' => $event,
            'session_hash' => substr(hash('sha256', session_id() . $salt), 0, 6),
            'ip_hash' => substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . $salt), 0, 6),
            'timestamp' => date('c'),
            // Remover details sensíveis
            'details_count' => count($details)
        ];
        error_log('SECURITY: ' . json_encode($log_data));
    }
}

if (!$result) {
    SecurityLogger::logSecurityEvent('invalid_customer_access');
    session_destroy();
    header("Location: /mercado/login/"); 
    exit;
}

$wallet = $result;

// Se não há dados de carteira, criar com valores padrão
if (!$wallet['balance'] && !$wallet['cashback_balance'] && !$wallet['points']) {
    $wallet_table_safe = '`' . str_replace('`', '``', $prefix) . 'customer_wallet`';
    $sql = "INSERT INTO {$wallet_table_safe} (customer_id, balance, cashback_balance, points) VALUES (?, 0, 0, 0) ON DUPLICATE KEY UPDATE customer_id = customer_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$customer_id]);
}
// Garantir valores padrão
$wallet['balance'] = $wallet['balance'] ?? 0;
$wallet['cashback_balance'] = $wallet['cashback_balance'] ?? 0;
$wallet['points'] = $wallet['points'] ?? 0;
// Definir limite de transações com validação robusta
$transaction_limit = 30; // valor padrão
if (defined('WALLET_TRANSACTION_LIMIT')) {
    $limit = filter_var(WALLET_TRANSACTION_LIMIT, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 100]
    ]);
    if ($limit !== false) {
        $transaction_limit = $limit;
    }
}
// Usar tabela já sanitizada
$sql = "SELECT type, description, amount, created_at FROM {$sanitized_tables['transactions']} WHERE customer_id = ? ORDER BY created_at DESC LIMIT ?";
// Sugestão: criar índice composto (customer_id, created_at) na tabela
$stmt = $pdo->prepare($sql);
$stmt->execute([$customer_id, (int)$transaction_limit]);
$transactions = $stmt->fetchAll();
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Minha Carteira - Mercado OneMundo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/wallet.css">
<link rel="stylesheet" href="/assets/css/wallet-extended.css">


<!-- HEADER PREMIUM v3.0 -->
<style>

/* ══════════════════════════════════════════════════════════════════════════════
   🎨 HEADER PREMIUM v3.0 - OneMundo Mercado
   ══════════════════════════════════════════════════════════════════════════════ */

/* Variáveis do Header */
:root {
    --header-bg: rgba(255, 255, 255, 0.92);
    --header-bg-scrolled: rgba(255, 255, 255, 0.98);
    --header-blur: 20px;
    --header-shadow: 0 4px 30px rgba(0, 0, 0, 0.08);
    --header-border: rgba(0, 0, 0, 0.04);
    --header-height: 72px;
    --header-height-mobile: 64px;
}

/* Header Principal */
.header, .site-header, [class*="header-main"] {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 1000 !important;
    background: var(--header-bg) !important;
    backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    -webkit-backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    border-bottom: 1px solid var(--header-border) !important;
    box-shadow: none !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    height: auto !important;
    min-height: var(--header-height) !important;
}

.header.scrolled, .site-header.scrolled {
    background: var(--header-bg-scrolled) !important;
    box-shadow: var(--header-shadow) !important;
}

/* Container do Header */
.header-inner, .header-content, .header > div:first-child {
    max-width: 1400px !important;
    margin: 0 auto !important;
    padding: 12px 24px !important;
    display: flex !important;
    align-items: center !important;
    gap: 20px !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOCALIZAÇÃO - Estilo Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.location-btn, .endereco, [class*="location"], [class*="endereco"], [class*="address"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 10px 18px !important;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(16, 185, 129, 0.04)) !important;
    border: 1px solid rgba(16, 185, 129, 0.15) !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    min-width: 200px !important;
    max-width: 320px !important;
}

.location-btn:hover, .endereco:hover, [class*="location"]:hover {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.06)) !important;
    border-color: rgba(16, 185, 129, 0.25) !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15) !important;
}

/* Ícone de localização */
.location-btn svg, .location-btn i, [class*="location"] svg {
    width: 22px !important;
    height: 22px !important;
    color: #10b981 !important;
    flex-shrink: 0 !important;
}

/* Texto da localização */
.location-text, .endereco-text {
    flex: 1 !important;
    min-width: 0 !important;
}

.location-label, .entregar-em {
    font-size: 11px !important;
    font-weight: 500 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin-bottom: 2px !important;
}

.location-address, .endereco-rua {
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* Seta da localização */
.location-arrow, .location-btn > svg:last-child {
    width: 16px !important;
    height: 16px !important;
    color: #94a3b8 !important;
    transition: transform 0.2s ease !important;
}

.location-btn:hover .location-arrow {
    transform: translateX(3px) !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   TEMPO DE ENTREGA - Badge Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.delivery-time, .tempo-entrega, [class*="delivery-time"], [class*="tempo"] {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 10px 16px !important;
    background: linear-gradient(135deg, #0f172a, #1e293b) !important;
    border-radius: 12px !important;
    color: white !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2) !important;
    transition: all 0.3s ease !important;
}

.delivery-time:hover, .tempo-entrega:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.25) !important;
}

.delivery-time svg, .tempo-entrega svg, .delivery-time i {
    width: 18px !important;
    height: 18px !important;
    color: #10b981 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOGO - Design Moderno
   ══════════════════════════════════════════════════════════════════════════════ */

.logo, .site-logo, [class*="logo"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    text-decoration: none !important;
    transition: transform 0.3s ease !important;
}

.logo:hover {
    transform: scale(1.02) !important;
}

.logo-icon, .logo img, .logo svg {
    width: 48px !important;
    height: 48px !important;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
    border-radius: 14px !important;
    padding: 10px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.logo:hover .logo-icon, .logo:hover img {
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4) !important;
    transform: rotate(-3deg) !important;
}

.logo-text, .logo span, .site-title {
    font-size: 1.5rem !important;
    font-weight: 800 !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
    background-clip: text !important;
    letter-spacing: -0.02em !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   BUSCA - Search Bar Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.search-container, .search-box, [class*="search"], .busca {
    flex: 1 !important;
    max-width: 600px !important;
    position: relative !important;
}

.search-input, input[type="search"], input[name*="search"], input[name*="busca"], .busca input {
    width: 100% !important;
    padding: 14px 20px 14px 52px !important;
    background: #f1f5f9 !important;
    border: 2px solid transparent !important;
    border-radius: 16px !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
}

.search-input:hover, input[type="search"]:hover {
    background: #e2e8f0 !important;
}

.search-input:focus, input[type="search"]:focus {
    background: #ffffff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12), inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
    outline: none !important;
}

.search-input::placeholder {
    color: #94a3b8 !important;
    font-weight: 400 !important;
}

/* Ícone da busca */
.search-icon, .search-container svg, .busca svg {
    position: absolute !important;
    left: 18px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 22px !important;
    height: 22px !important;
    color: #94a3b8 !important;
    pointer-events: none !important;
    transition: color 0.3s ease !important;
}

.search-input:focus + .search-icon,
.search-container:focus-within svg {
    color: #10b981 !important;
}

/* Botão de busca por voz (opcional) */
.search-voice-btn {
    position: absolute !important;
    right: 12px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 36px !important;
    height: 36px !important;
    background: transparent !important;
    border: none !important;
    border-radius: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.search-voice-btn:hover {
    background: rgba(16, 185, 129, 0.1) !important;
}

.search-voice-btn svg {
    width: 20px !important;
    height: 20px !important;
    color: #64748b !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   CARRINHO - Cart Button Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.cart-btn, .carrinho-btn, [class*="cart"], [class*="carrinho"], a[href*="cart"], a[href*="carrinho"] {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 52px !important;
    height: 52px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border: none !important;
    border-radius: 16px !important;
    cursor: pointer !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
}

.cart-btn:hover, .carrinho-btn:hover, [class*="cart"]:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4) !important;
}

.cart-btn:active {
    transform: translateY(-1px) scale(0.98) !important;
}

.cart-btn svg, .carrinho-btn svg, [class*="cart"] svg {
    width: 26px !important;
    height: 26px !important;
    color: white !important;
}

/* Badge do carrinho */
.cart-badge, .carrinho-badge, [class*="cart-count"], [class*="badge"] {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 24px !important;
    height: 24px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    color: white !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 6px !important;
    border: 3px solid white !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: badge-pulse 2s ease-in-out infinite !important;
}

@keyframes badge-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

/* ══════════════════════════════════════════════════════════════════════════════
   MENU MOBILE
   ══════════════════════════════════════════════════════════════════════════════ */

.menu-btn, .hamburger, [class*="menu-toggle"] {
    display: none !important;
    width: 44px !important;
    height: 44px !important;
    background: #f1f5f9 !important;
    border: none !important;
    border-radius: 12px !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.menu-btn:hover {
    background: #e2e8f0 !important;
}

.menu-btn svg {
    width: 24px !important;
    height: 24px !important;
    color: #475569 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   RESPONSIVO
   ══════════════════════════════════════════════════════════════════════════════ */

@media (max-width: 1024px) {
    .search-container, .search-box {
        max-width: 400px !important;
    }
    
    .location-btn, .endereco {
        max-width: 250px !important;
    }
}

@media (max-width: 768px) {
    :root {
        --header-height: var(--header-height-mobile);
    }
    
    .header-inner, .header-content {
        padding: 10px 16px !important;
        gap: 12px !important;
    }
    
    /* Esconder busca no header mobile - mover para baixo */
    .search-container, .search-box, [class*="search"]:not(.search-icon) {
        position: absolute !important;
        top: 100% !important;
        left: 0 !important;
        right: 0 !important;
        max-width: 100% !important;
        padding: 12px 16px !important;
        background: white !important;
        border-top: 1px solid #e2e8f0 !important;
        display: none !important;
    }
    
    .search-container.active {
        display: block !important;
    }
    
    /* Logo menor */
    .logo-icon, .logo img {
        width: 42px !important;
        height: 42px !important;
        border-radius: 12px !important;
    }
    
    .logo-text {
        display: none !important;
    }
    
    /* Localização compacta */
    .location-btn, .endereco {
        min-width: auto !important;
        max-width: 180px !important;
        padding: 8px 12px !important;
    }
    
    .location-label, .entregar-em {
        display: none !important;
    }
    
    .location-address {
        font-size: 13px !important;
    }
    
    /* Tempo de entrega menor */
    .delivery-time, .tempo-entrega {
        padding: 8px 12px !important;
        font-size: 12px !important;
    }
    
    /* Carrinho menor */
    .cart-btn, .carrinho-btn {
        width: 46px !important;
        height: 46px !important;
        border-radius: 14px !important;
    }
    
    .cart-btn svg {
        width: 22px !important;
        height: 22px !important;
    }
    
    /* Mostrar menu button */
    .menu-btn, .hamburger {
        display: flex !important;
    }
}

@media (max-width: 480px) {
    .location-btn, .endereco {
        max-width: 140px !important;
    }
    
    .delivery-time, .tempo-entrega {
        display: none !important;
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   ANIMAÇÕES DE ENTRADA
   ══════════════════════════════════════════════════════════════════════════════ */

@keyframes headerSlideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.header, .site-header {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *, .header-content > * {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *:nth-child(1) { animation-delay: 0.05s !important; }
.header-inner > *:nth-child(2) { animation-delay: 0.1s !important; }
.header-inner > *:nth-child(3) { animation-delay: 0.15s !important; }
.header-inner > *:nth-child(4) { animation-delay: 0.2s !important; }
.header-inner > *:nth-child(5) { animation-delay: 0.25s !important; }

/* ══════════════════════════════════════════════════════════════════════════════
   AJUSTES DE BODY PARA HEADER FIXED
   ══════════════════════════════════════════════════════════════════════════════ */

body {
    padding-top: calc(var(--header-height) + 10px) !important;
}

@media (max-width: 768px) {
    body {
        padding-top: calc(var(--header-height-mobile) + 10px) !important;
    }
}

</style>
</head>
<body>
<header class="header"><div class="header-inner"><a href="/mercado/perfil/" class="back-btn">← Voltar</a><h1 class="page-title">💳 Minha Carteira</h1></div></header>
<main class="main">
<?php
// Mover para o início do arquivo, após as configurações de segurança
if (!function_exists('formatMoney')) {
    function formatMoney($value) {
        if (!is_numeric($value)) {
            return '0,00';
        }
        $floatValue = max(0, floatval($value));
        return number_format($floatValue, 2, ',', '.');
    }
}
?>

// ... resto do código PHP ...

<div class="wallet-label">Saldo Disponível</div>

// Validação rigorosa de valores monetários
$balance = filter_var($wallet["balance"] ?? 0, FILTER_VALIDATE_FLOAT, [
    'options' => ['min_range' => 0, 'max_range' => 999999.99]
]);
$balance = ($balance === false) ? 0 : $balance;

$cashback_balance = filter_var($wallet["cashback_balance"] ?? 0, FILTER_VALIDATE_FLOAT, [
    'options' => ['min_range' => 0, 'max_range' => 999999.99]
]);
$cashback_balance = ($cashback_balance === false) ? 0 : $cashback_balance;

$points = filter_var($wallet["points"] ?? 0, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => 2147483647]
]);
$points = ($points === false) ? 0 : $points;

// Validar limites máximos
// Definir limites baseados em configuração
$max_balance = defined('MAX_WALLET_BALANCE') ? MAX_WALLET_BALANCE : 999999.99;
$max_points = defined('MAX_WALLET_POINTS') ? MAX_WALLET_POINTS : 999999;

if ($balance > $max_balance || $cashback_balance > $max_balance || $points > $max_points) {
    // Log detalhado para auditoria
    $audit_data = [
        'customer_hash' => hash('sha256', $customer_id . 'wallet_audit'),
        'original_balance' => $balance,
        'original_cashback' => $cashback_balance,
        'original_points' => $points,
        'session_id' => substr(hash('sha256', session_id()), 0, 16),
        'timestamp' => date('Y-m-d H:i:s'),
        'ip_hash' => hash('sha256', $client_ip)
    ];
    error_log('WALLET_AUDIT: ' . json_encode($audit_data));
    
    // Aplicar valores seguros
    $balance = min($balance, $max_balance);
    $cashback_balance = min($cashback_balance, $max_balance);
    $points = min($points, $max_points);
    
    // Inserir registro de auditoria no banco
    try {
        $audit_sql = "INSERT INTO {$sanitized_tables['rate_limiting']} (ip_hash, attempts, last_attempt) VALUES (?, 999, NOW()) ON DUPLICATE KEY UPDATE attempts = 999";
        $pdo->prepare($audit_sql)->execute(['audit_' . $audit_data['customer_hash']]);
    } catch (Exception $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}
$totalBalance = $balance + $cashback_balance;
$positive_types = ["cashback","credit","bonus","referral","refund"];
<?php
// Consolidar todo o PHP de preparação dos dados
function e($value, $context = 'html') { 
    if ($value === null || $value === '') return '';
    
    switch ($context) {
        case 'attr':
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        case 'js':
            // Para JavaScript, remover aspas do JSON e validar
            $encoded = json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) return '""';
            return $encoded;
        case 'css':
            return preg_replace('/[^a-zA-Z0-9\-_]/', '', $value);
        case 'url':
            return urlencode($value);
        default:
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

$formatted_values = [
    'total' => formatMoney($totalBalance),
    'cashback' => formatMoney($cashback_balance),
    'balance' => formatMoney($balance),
    'points' => number_format($points)
];
?>
<?php
// Separar lógica de apresentação
function renderWalletCard($formatted_values) {
    ob_start();
    ?>
    <div class="wallet-balance">R$ <?= e($formatted_values['total'], 'html') ?></div>
    <div class="wallet-stats">
        <div class="wallet-stat-item">
            <div class="wallet-stat-value">R$ <?= e($formatted_values['cashback']) ?></div>
            <div class="wallet-stat-label">Cashback</div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

echo renderWalletCard($formatted_values);
?>
        <div class="wallet-stat-item">
            <div class="wallet-stat-value">R$ <?= e($formatted_values['balance']) ?></div>
            <div class="wallet-stat-label">Créditos</div>
        </div>
        <div class="wallet-stat-item"><div class="wallet-stat-value"><?= e($formatted_values['points']) ?></div><div class="wallet-stat-label">Pontos</div></div>
    </div>
</div>
<div class="card">
    <h3 class="card-title">📋 Extrato</h3>
    <?php
// Mover para função separada no início do arquivo
// Mover para o início do arquivo e usar efetivamente
function processTransactions($transactions, $positive_types) {
    if (empty($transactions)) return [];
    
    $positive_lookup = array_flip($positive_types);
    $processed = [];
    $current_time = time();
    $min_timestamp = $current_time - (365 * 24 * 60 * 60); // 1 ano
    $max_future = $current_time + 300; // 5 minutos no futuro
    
    $current_time = time();
    $min_timestamp = $current_time - (365 * 24 * 60 * 60);
    $max_future = $current_time + 300;
    
    foreach ($transactions as $t) {
        $pos = isset($positive_lookup[$t["type"] ?? '']);
        $created_at = $t["created_at"] ?? '';
        $timestamp = $created_at ? strtotime($created_at) : false;
        
        if (!is_numeric($timestamp) || $timestamp < $min_timestamp || $timestamp > $max_future) {
            $timestamp = $current_time;
        }
        
        $amount = abs(floatval($t["amount"] ?? 0));
        
        $processed[] = [
            'description' => htmlspecialchars($t["description"] ?: ucfirst($t["type"] ?? ''), ENT_QUOTES, 'UTF-8'),
            'date' => date("d/m/Y H:i", $timestamp),
            'amount' => formatMoney($amount),
            'positive' => $pos
        ];
    }
    return $processed;
}
$processed_transactions = processTransactions($transactions, $positive_types);
if (empty($processed_transactions)): ?>
    <div class="empty"><p>Nenhuma transação ainda</p></div>
<?php else: foreach ($processed_transactions as $transaction): ?>
    <div class="transaction-item">
        <div class="transaction-icon <?= $transaction['positive'] ? 'positive' : 'negative' ?>"><?= $transaction['positive'] ? "💰" : "💳" ?></div>
        <div class="transaction-details">
            <div class="transaction-title"><?= e($transaction['description']) ?></div>
            <div class="transaction-date"><?= e($transaction['date']) ?></div>
        </div>
<div class="transaction-amount <?= $transaction['positive'] ? 'positive' : 'negative' ?>"><?= $transaction['positive'] ? "+" : "-" ?>R$ <?= e($transaction['amount']) ?></div>
    </div>
    <?php endforeach; endif; ?>
</div>
</main>

<script>
// Header scroll effect
(function() {
    const header = document.querySelector('.header, .site-header, [class*="header-main"]');
    if (!header) return;
    
    let lastScroll = 0;
    let ticking = false;
    
    function updateHeader() {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
        
        // Hide/show on scroll (opcional)
        /*
        if (currentScroll > lastScroll && currentScroll > 100) {
            header.style.transform = 'translateY(-100%)';
        } else {
            header.style.transform = 'translateY(0)';
        }
        */
        
        lastScroll = currentScroll;
        ticking = false;
    }
    
    window.addEventListener('scroll', function() {
        if (!ticking) {
            requestAnimationFrame(updateHeader);
            ticking = true;
        }
    });
    
    // Cart badge animation
    window.animateCartBadge = function() {
        const badge = document.querySelector('.cart-badge, .carrinho-badge, [class*="cart-count"]');
        if (badge) {
            badge.style.transform = 'scale(1.3)';
            setTimeout(() => {
                badge.style.transform = 'scale(1)';
            }, 200);
        }
    };
    
    // Mobile search toggle
    const searchToggle = document.querySelector('.search-toggle, [class*="search-btn"]');
    const searchContainer = document.querySelector('.search-container, .search-box');
    
    if (searchToggle && searchContainer) {
        searchToggle.addEventListener('click', function() {
            searchContainer.classList.toggle('active');
        });
    }
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     🎨 ONEMUNDO HEADER PREMIUM v3.0 - CSS FINAL UNIFICADO
═══════════════════════════════════════════════════════════════════════════════ -->
<style id="om-header-final">
/* RESET */
.mkt-header, .mkt-header-row, .mkt-logo, .mkt-logo-box, .mkt-logo-text,
.mkt-user, .mkt-user-avatar, .mkt-guest, .mkt-cart, .mkt-cart-count, .mkt-search,
.om-topbar, .om-topbar-main, .om-topbar-icon, .om-topbar-content,
.om-topbar-label, .om-topbar-address, .om-topbar-arrow, .om-topbar-time {
    all: revert;
}

/* TOPBAR VERDE */
.om-topbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 14px 20px !important;
    background: linear-gradient(135deg, #047857 0%, #059669 40%, #10b981 100%) !important;
    color: #fff !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    position: relative !important;
    overflow: hidden !important;
}

.om-topbar::before {
    content: '' !important;
    position: absolute !important;
    top: 0 !important;
    left: -100% !important;
    width: 100% !important;
    height: 100% !important;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent) !important;
    transition: left 0.6s ease !important;
}

.om-topbar:hover::before { left: 100% !important; }
.om-topbar:hover { background: linear-gradient(135deg, #065f46 0%, #047857 40%, #059669 100%) !important; }

.om-topbar-main {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex: 1 !important;
    min-width: 0 !important;
}

.om-topbar-icon {
    width: 40px !important;
    height: 40px !important;
    background: rgba(255,255,255,0.18) !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar:hover .om-topbar-icon {
    background: rgba(255,255,255,0.25) !important;
    transform: scale(1.05) !important;
}

.om-topbar-icon svg { width: 20px !important; height: 20px !important; color: #fff !important; }

.om-topbar-content { flex: 1 !important; min-width: 0 !important; }

.om-topbar-label {
    font-size: 11px !important;
    font-weight: 500 !important;
    opacity: 0.85 !important;
    margin-bottom: 2px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: block !important;
}

.om-topbar-address {
    font-size: 14px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-width: 220px !important;
}

.om-topbar-arrow {
    width: 32px !important;
    height: 32px !important;
    background: rgba(255,255,255,0.12) !important;
    border-radius: 8px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    transition: all 0.3s ease !important;
    margin-right: 12px !important;
}

.om-topbar:hover .om-topbar-arrow {
    background: rgba(255,255,255,0.2) !important;
    transform: translateX(3px) !important;
}

.om-topbar-arrow svg { width: 16px !important; height: 16px !important; color: #fff !important; }

.om-topbar-time {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 8px 14px !important;
    background: rgba(0,0,0,0.2) !important;
    border-radius: 50px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar-time:hover { background: rgba(0,0,0,0.3) !important; transform: scale(1.02) !important; }
.om-topbar-time svg { width: 16px !important; height: 16px !important; color: #34d399 !important; }

/* HEADER BRANCO */
.mkt-header {
    background: #ffffff !important;
    padding: 0 !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 9999 !important;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08) !important;
    border-bottom: none !important;
}

.mkt-header-row {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 14px 20px !important;
    margin-bottom: 0 !important;
    background: #fff !important;
    border-bottom: 1px solid rgba(0,0,0,0.06) !important;
}

/* LOGO */
.mkt-logo {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-decoration: none !important;
    flex-shrink: 0 !important;
}

.mkt-logo-box {
    width: 44px !important;
    height: 44px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 14px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 22px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-logo:hover .mkt-logo-box {
    transform: scale(1.05) rotate(-3deg) !important;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45) !important;
}

.mkt-logo-text {
    font-size: 20px !important;
    font-weight: 800 !important;
    color: #10b981 !important;
    letter-spacing: -0.02em !important;
}

/* USER */
.mkt-user { margin-left: auto !important; text-decoration: none !important; }

.mkt-user-avatar {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 50% !important;
    color: #fff !important;
    font-weight: 700 !important;
    font-size: 16px !important;
    box-shadow: 0 3px 12px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.mkt-user-avatar:hover {
    transform: scale(1.08) !important;
    box-shadow: 0 5px 18px rgba(16, 185, 129, 0.4) !important;
}

.mkt-user.mkt-guest {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: #f1f5f9 !important;
    border-radius: 12px !important;
    transition: all 0.3s ease !important;
}

.mkt-user.mkt-guest:hover { background: #e2e8f0 !important; }
.mkt-user.mkt-guest svg { width: 24px !important; height: 24px !important; color: #64748b !important; }

/* CARRINHO */
.mkt-cart {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 46px !important;
    height: 46px !important;
    background: linear-gradient(135deg, #1e293b, #0f172a) !important;
    border: none !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    box-shadow: 0 4px 15px rgba(15, 23, 42, 0.25) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-cart:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.3) !important;
}

.mkt-cart:active { transform: translateY(-1px) scale(0.98) !important; }
.mkt-cart svg { width: 22px !important; height: 22px !important; color: #fff !important; }

.mkt-cart-count {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 22px !important;
    height: 22px !important;
    padding: 0 6px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    border-radius: 11px !important;
    color: #fff !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 2px solid #fff !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: cartPulse 2s ease-in-out infinite !important;
}

@keyframes cartPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }

/* BUSCA */
.mkt-search {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    background: #f1f5f9 !important;
    border-radius: 14px !important;
    padding: 0 16px !important;
    margin: 0 16px 16px !important;
    border: 2px solid transparent !important;
    transition: all 0.3s ease !important;
}

.mkt-search:focus-within {
    background: #fff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1) !important;
}

.mkt-search svg {
    width: 20px !important;
    height: 20px !important;
    color: #94a3b8 !important;
    flex-shrink: 0 !important;
    transition: color 0.3s ease !important;
}

.mkt-search:focus-within svg { color: #10b981 !important; }

.mkt-search input {
    flex: 1 !important;
    border: none !important;
    background: transparent !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    outline: none !important;
    padding: 14px 0 !important;
    width: 100% !important;
}

.mkt-search input::placeholder { color: #94a3b8 !important; }

/* RESPONSIVO */
@media (max-width: 480px) {
    .om-topbar { padding: 12px 16px !important; }
    .om-topbar-icon { width: 36px !important; height: 36px !important; }
    .om-topbar-address { max-width: 150px !important; font-size: 13px !important; }
    .om-topbar-arrow { display: none !important; }
    .om-topbar-time { padding: 6px 10px !important; font-size: 11px !important; }
    .mkt-header-row { padding: 12px 16px !important; }
    .mkt-logo-box { width: 40px !important; height: 40px !important; font-size: 18px !important; }
    .mkt-logo-text { font-size: 18px !important; }
    .mkt-cart { width: 42px !important; height: 42px !important; }
    .mkt-search { margin: 0 12px 12px !important; }
    .mkt-search input { font-size: 14px !important; padding: 12px 0 !important; }
}

/* ANIMAÇÕES */
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.mkt-header { animation: slideDown 0.4s ease !important; }

::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
::selection { background: rgba(16, 185, 129, 0.2); color: #047857; }
</style>

<script>
(function() {
    var h = document.querySelector('.mkt-header');
    if (h && !document.querySelector('.om-topbar')) {
        var t = document.createElement('div');
        t.className = 'om-topbar';
        t.innerHTML = '<div class="om-topbar-main"><div class="om-topbar-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><div class="om-topbar-content"><div class="om-topbar-label">Entregar em</div><div class="om-topbar-address" id="omAddrFinal">Carregando...</div></div><div class="om-topbar-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div></div><div class="om-topbar-time"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>25-35 min</div>';
        h.insertBefore(t, h.firstChild);
        fetch('/mercado/api/address.php?action=list').then(r=>r.json()).then(d=>{var el=document.getElementById('omAddrFinal');if(el&&d.current)el.textContent=d.current.address_1||'Selecionar';}).catch(()=>{});
    }
    var l = document.querySelector('.mkt-logo');
    if (l && !l.querySelector('.mkt-logo-text')) {
        var s = document.createElement('span');
        s.className = 'mkt-logo-text';
        s.textContent = 'Mercado';
        l.appendChild(s);
    }
})();
</script>
</body></html>