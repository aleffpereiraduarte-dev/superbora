<?php
/**
 * OneMundo PRO - Header com dados do cliente
 * Incluir no início do index.php: include 'pro_header.php';
 */

// Carregar config OpenCart
$_pro_oc_root = dirname(__DIR__);
if (file_exists($_pro_oc_root . '/config.php') && !defined('DB_DATABASE')) {
    require_once($_pro_oc_root . '/config.php');
}

// Conectar banco
$_pro_pdo = null;
if (defined('DB_HOSTNAME') && defined('DB_DATABASE')) {
    try {
        $_pro_pdo = new PDO(
            "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
            DB_USERNAME, DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        $_pro_pdo = null;
    }
}

// Ler sessão do banco
$_pro_customer_id = 0;
$_pro_session_data = null;
$_pro_ocsessid = $_COOKIE['OCSESSID'] ?? $_COOKIE['PHPSESSID'] ?? '';

if ($_pro_pdo && $_pro_ocsessid) {
    try {
        $_pro_prefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';
        $_pro_stmt = $_pro_pdo->prepare("SELECT data FROM {$_pro_prefix}session WHERE session_id = ?");
        $_pro_stmt->execute([$_pro_ocsessid]);
        $_pro_row = $_pro_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($_pro_row && !empty($_pro_row['data'])) {
            $_pro_session_data = json_decode($_pro_row['data'], true);
            if (isset($_pro_session_data['customer_id'])) {
                $_pro_customer_id = (int)$_pro_session_data['customer_id'];
            }
        }
    } catch (PDOException $e) {}
}

$_pro_is_logged = $_pro_customer_id > 0;
$_pro_customer = null;
$_pro_address = null;
$_pro_cards = [];
$_pro_orders = ['total' => 0, 'value' => 0];

if ($_pro_is_logged && $_pro_pdo) {
    $_pro_prefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';
    
    try {
        // Cliente
        $_pro_stmt = $_pro_pdo->prepare("SELECT * FROM {$_pro_prefix}customer WHERE customer_id = ?");
        $_pro_stmt->execute([$_pro_customer_id]);
        $_pro_customer = $_pro_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Endereço (da sessão ou banco)
        if (isset($_pro_session_data['shipping_address'])) {
            $_pro_address = $_pro_session_data['shipping_address'];
        } else {
            $_pro_stmt = $_pro_pdo->prepare("SELECT a.*, z.code as zone_code FROM {$_pro_prefix}address a LEFT JOIN {$_pro_prefix}zone z ON a.zone_id = z.zone_id WHERE a.customer_id = ? LIMIT 1");
            $_pro_stmt->execute([$_pro_customer_id]);
            $_pro_address = $_pro_stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Cartões
        try {
            $_pro_stmt = $_pro_pdo->prepare("SELECT * FROM om_customer_cards WHERE customer_id = ? AND status = '1'");
            $_pro_stmt->execute([$_pro_customer_id]);
            $_pro_cards = $_pro_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {}
        
        // Pedidos
        $_pro_stmt = $_pro_pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(total),0) as value FROM {$_pro_prefix}order WHERE customer_id = ? AND order_status_id > 0");
        $_pro_stmt->execute([$_pro_customer_id]);
        $_pro_orders = $_pro_stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {}
}

// Saudação
$_pro_hora = (int)date('H');
$_pro_saudacao = ($_pro_hora >= 5 && $_pro_hora < 12) ? 'Bom dia' : (($_pro_hora >= 12 && $_pro_hora < 18) ? 'Boa tarde' : 'Boa noite');