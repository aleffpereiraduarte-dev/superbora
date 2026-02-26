<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  ⚙️ CONFIG - TRABALHE CONOSCO / WORKER APP v2.1                              ║
 * ║  Com sessão isolada para evitar conflito com outros sistemas                ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

// Carregar config central SEGURA (le do .env)
require_once dirname(__DIR__) . '/config/database.php';

// Aliases para compatibilidade com codigo legado
if (!defined('DB_HOSTNAME')) define('DB_HOSTNAME', DB_HOST);
if (!defined('DB_DATABASE')) define('DB_DATABASE', DB_NAME);
if (!defined('DB_USERNAME')) define('DB_USERNAME', DB_USER);
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', DB_PASS);

// Versão
define('APP_VERSION', '2.1');

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// ═══════════════════════════════════════════════════════════════════════════════
// SESSÃO ISOLADA PARA WORKERS
// ═══════════════════════════════════════════════════════════════════════════════
// Usar nome de sessão diferente para não conflitar com RH e outros sistemas
if (session_status() === PHP_SESSION_NONE) {
    session_name('WORKER_SESSID');
    session_start();
}

// Funcoes getPDO() e getMySQLi() ja definidas em /config/database.php

// Alias para compatibilidade
if (!function_exists('getDB')) {
    function getDB() { return getPDO(); }
}

/**
 * Helpers
 */
function formatMoney($v) {
    return "R$ " . number_format((float)$v, 2, ",", ".");
}

function formatDate($d) {
    if (!$d) return '-';
    return date('d/m/Y H:i', strtotime($d));
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Verificar login de worker
 */
function requireWorkerLogin() {
    if (!isset($_SESSION['worker_id'])) {
        if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
            jsonResponse(['success' => false, 'error' => 'Não autenticado'], 401);
        }
        header('Location: login.php');
        exit;
    }
}

/**
 * Obter dados do worker logado
 */
function getWorker($worker_id = null) {
    $id = $worker_id ?? ($_SESSION['worker_id'] ?? null);
    if (!$id) return null;
    
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM om_workers WHERE worker_id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Sincronizar worker com om_market_shoppers
 */
function syncWorkerToShopper($worker_id) {
    $pdo = getPDO();
    
    $stmt = $pdo->prepare("SELECT * FROM om_workers WHERE worker_id = ?");
    $stmt->execute([$worker_id]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$worker) return false;
    
    // Verificar se já existe
    $stmt = $pdo->prepare("SELECT shopper_id FROM om_market_shoppers WHERE email = ?");
    $stmt->execute([$worker['email']]);
    $existing = $stmt->fetch();
    
    $can_deliver = ($worker['is_delivery'] ?? 0) ? 1 : 0;
    
    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE om_market_shoppers 
            SET name = ?, phone = ?, can_deliver = ?, status = 1
            WHERE shopper_id = ?
        ");
        $stmt->execute([$worker['name'], $worker['phone'] ?? '', $can_deliver, $existing['shopper_id']]);
        $_SESSION['shopper_id'] = $existing['shopper_id'];
        return $existing['shopper_id'];
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO om_market_shoppers (partner_id, name, email, phone, can_deliver, status, is_online, rating, created_at)
            VALUES (?, ?, ?, ?, ?, 1, 0, 5.0, NOW())
        ");
        $stmt->execute([
            $worker['partner_id'] ?? 1,
            $worker['name'],
            $worker['email'],
            $worker['phone'] ?? '',
            $can_deliver
        ]);
        $_SESSION['shopper_id'] = $pdo->lastInsertId();
        return $_SESSION['shopper_id'];
    }
}

/**
 * Buscar ofertas disponíveis
 */
function getAvailableOffers($shopper_id) {
    $pdo = getPDO();
    
    $stmt = $pdo->prepare("SELECT partner_id FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $shopper = $stmt->fetch();
    if (!$shopper) return [];
    
    $stmt = $pdo->prepare("
        SELECT so.*, o.order_number, o.total, o.shipping_address,
               p.name as partner_name, TIMESTAMPDIFF(SECOND, NOW(), so.expires_at) as seconds_left
        FROM om_shopper_offers so
        JOIN om_market_orders o ON so.order_id = o.order_id
        JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE so.status = 'pending' AND so.expires_at > NOW()
          AND o.partner_id = ? AND o.shopper_id IS NULL
        ORDER BY so.shopper_earning DESC LIMIT 20
    ");
    $stmt->execute([$shopper['partner_id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Buscar pedido ativo
 */
function getActiveOrder($shopper_id) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT o.*, p.name as partner_name, p.address as partner_address
        FROM om_market_orders o
        JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.shopper_id = ? AND o.status IN ('shopping', 'picking', 'ready', 'delivering')
        ORDER BY o.created_at DESC LIMIT 1
    ");
    $stmt->execute([$shopper_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Estatísticas do dia
 */
function getTodayStats($shopper_id) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_orders, COALESCE(SUM(shopper_earning), 0) as total_earnings
        FROM om_market_orders
        WHERE shopper_id = ? AND status = 'delivered' AND DATE(delivered_at) = CURRENT_DATE
    ");
    $stmt->execute([$shopper_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
