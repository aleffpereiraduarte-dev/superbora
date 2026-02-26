<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 *  🚀 MEGA SHOPPER APP INSTALLER v2.0
 *  OneMundo - Sistema Premium estilo Instacart
 * ═══════════════════════════════════════════════════════════════════════════════
 *  
 *  O QUE FAZ:
 *  1. Corrige funções duplicadas (getDB)
 *  2. Instala config.php + functions.php COMPLETOS
 *  3. Cria Dashboard Premium (estilo Instacart 2024)
 *  4. Cria Carteira Digital
 *  5. Cria Página de Ofertas
 *  6. Cria Página de Ganhos/Histórico
 *  7. Cria Perfil
 *  8. Cria todas as APIs necessárias
 *  
 * ═══════════════════════════════════════════════════════════════════════════════
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$baseDir = __DIR__;
$results = [];
$errors = [];

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ═══════════════════════════════════════════════════════════════════════════════

function createDir($path) {
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
        return true;
    }
    return false;
}

function saveFile($path, $content) {
    global $results, $errors, $baseDir;
    $fullPath = $baseDir . '/' . $path;
    createDir(dirname($fullPath));
    
    if (file_put_contents($fullPath, $content)) {
        $results[] = "✅ Criado: $path";
        return true;
    } else {
        $errors[] = "❌ Erro ao criar: $path";
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// 1. INCLUDES/CONFIG.PHP - Configuração e Conexão
// ═══════════════════════════════════════════════════════════════════════════════

$configPhp = <<<'PHP'
<?php
/**
 * Config - OneMundo Shopper App
 */

// Configurações do Banco
define('DB_HOST', 'localhost');
define('DB_NAME', 'love1');
define('DB_USER', 'love1');
// DB_PASS loaded from central config

// URL Base
define('BASE_URL', '/mercado/trabalhe-conosco');

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Conexão PDO (Singleton)
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die('Erro de conexão: ' . $e->getMessage());
        }
    }
    return $pdo;
}
PHP;

saveFile('includes/config.php', $configPhp);

// ═══════════════════════════════════════════════════════════════════════════════
// 2. INCLUDES/FUNCTIONS.PHP - Funções do Sistema (SEM getDB duplicado!)
// ═══════════════════════════════════════════════════════════════════════════════

$functionsPhp = <<<'PHP'
<?php
/**
 * Functions - OneMundo Shopper App
 * NOTA: getDB() está definida em config.php
 */

// ═══════════════════════════════════════════════════════════════════════════════
// AUTENTICAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════

function isLoggedIn() {
    return isset($_SESSION['worker_id']) && $_SESSION['worker_id'] > 0;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function getWorkerId() {
    return $_SESSION['worker_id'] ?? 0;
}

function getWorker($id = null) {
    $id = $id ?? getWorkerId();
    if (!$id) return null;
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// ═══════════════════════════════════════════════════════════════════════════════
// FINANCEIRO
// ═══════════════════════════════════════════════════════════════════════════════

function getBalance($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT balance FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    return (float) ($stmt->fetchColumn() ?: 0);
}

function getPendingBalance($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(shopper_earnings), 0) 
        FROM om_market_orders 
        WHERE shopper_id = ? AND status IN ('shopping', 'ready_for_delivery', 'delivering')
    ");
    $stmt->execute([$workerId]);
    return (float) $stmt->fetchColumn();
}

function getEarningsToday($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM om_market_wallet_transactions 
        WHERE worker_id = ? AND type = 'earning' AND DATE(created_at) = CURRENT_DATE
    ");
    $stmt->execute([$workerId]);
    return (float) $stmt->fetchColumn();
}

function getEarningsWeek($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM om_market_wallet_transactions 
        WHERE worker_id = ? AND type = 'earning' AND created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
    ");
    $stmt->execute([$workerId]);
    return (float) $stmt->fetchColumn();
}

function getEarningsMonth($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM om_market_wallet_transactions 
        WHERE worker_id = ? AND type = 'earning' AND MONTH(created_at) = MONTH(CURRENT_DATE) AND YEAR(created_at) = YEAR(CURRENT_DATE)
    ");
    $stmt->execute([$workerId]);
    return (float) $stmt->fetchColumn();
}

function getOrdersToday($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM om_market_orders 
        WHERE shopper_id = ? AND status = 'completed' AND DATE(completed_at) = CURRENT_DATE
    ");
    $stmt->execute([$workerId]);
    return (int) $stmt->fetchColumn();
}

function getOrdersWeek($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM om_market_orders 
        WHERE shopper_id = ? AND status = 'completed' AND completed_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
    ");
    $stmt->execute([$workerId]);
    return (int) $stmt->fetchColumn();
}

function getTransactions($workerId, $limit = 20) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT * FROM om_market_wallet_transactions 
        WHERE worker_id = ? ORDER BY created_at DESC LIMIT ?
    ");
    $stmt->execute([$workerId, $limit]);
    return $stmt->fetchAll();
}

function requestWithdrawal($workerId, $amount, $pixKey, $pixType) {
    $pdo = getDB();
    $balance = getBalance($workerId);
    
    if ($amount < 10) return ['success' => false, 'error' => 'Valor mínimo: R$ 10,00'];
    if ($amount > $balance) return ['success' => false, 'error' => 'Saldo insuficiente'];
    
    $pdo->beginTransaction();
    try {
        // Criar solicitação
        $stmt = $pdo->prepare("
            INSERT INTO om_market_worker_payouts (worker_id, amount, pix_key, pix_type, status, requested_at) 
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$workerId, $amount, $pixKey, $pixType]);
        
        // Debitar saldo
        $stmt = $pdo->prepare("UPDATE om_market_workers SET balance = balance - ? WHERE worker_id = ?");
        $stmt->execute([$amount, $workerId]);
        
        // Registrar transação
        $stmt = $pdo->prepare("
            INSERT INTO om_market_wallet_transactions (worker_id, type, amount, description, created_at) 
            VALUES (?, 'withdrawal', ?, 'Solicitação de saque PIX', NOW())
        ");
        $stmt->execute([$workerId, -$amount]);
        
        $pdo->commit();
        return ['success' => true];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getWithdrawals($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM om_market_worker_payouts WHERE worker_id = ? ORDER BY requested_at DESC");
    $stmt->execute([$workerId]);
    return $stmt->fetchAll();
}

// ═══════════════════════════════════════════════════════════════════════════════
// OFERTAS
// ═══════════════════════════════════════════════════════════════════════════════

function getAvailableOffers($workerId) {
    $pdo = getDB();
    $worker = getWorker($workerId);
    
    $stmt = $pdo->prepare("
        SELECT o.*, p.name as partner_name, p.logo as partner_logo,
               (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as item_count
        FROM om_shopper_offers so
        JOIN om_market_orders o ON so.order_id = o.order_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE so.shopper_id = ? AND so.status = 'pending'
        ORDER BY so.created_at DESC
    ");
    $stmt->execute([$workerId]);
    return $stmt->fetchAll();
}

function acceptOffer($workerId, $orderId) {
    $pdo = getDB();
    $pdo->beginTransaction();
    
    try {
        // Verificar se oferta existe e está pendente
        $stmt = $pdo->prepare("SELECT * FROM om_shopper_offers WHERE shopper_id = ? AND order_id = ? AND status = 'pending'");
        $stmt->execute([$workerId, $orderId]);
        $offer = $stmt->fetch();
        
        if (!$offer) {
            throw new Exception('Oferta não disponível');
        }
        
        // Aceitar oferta
        $stmt = $pdo->prepare("UPDATE om_shopper_offers SET status = 'accepted', accepted_at = NOW() WHERE offer_id = ?");
        $stmt->execute([$offer['offer_id']]);
        
        // Rejeitar outras ofertas do mesmo pedido
        $stmt = $pdo->prepare("UPDATE om_shopper_offers SET status = 'rejected' WHERE order_id = ? AND shopper_id != ?");
        $stmt->execute([$orderId, $workerId]);
        
        // Atualizar pedido
        $stmt = $pdo->prepare("UPDATE om_market_orders SET shopper_id = ?, status = 'shopping', shopping_started_at = NOW() WHERE order_id = ?");
        $stmt->execute([$workerId, $orderId]);
        
        $pdo->commit();
        return ['success' => true, 'order_id' => $orderId];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function rejectOffer($workerId, $orderId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE om_shopper_offers SET status = 'rejected', rejected_at = NOW() WHERE shopper_id = ? AND order_id = ?");
    $stmt->execute([$workerId, $orderId]);
    return ['success' => true];
}

function getActiveOrder($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT o.*, p.name as partner_name, p.logo as partner_logo, p.address as partner_address
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.shopper_id = ? AND o.status IN ('shopping', 'ready_for_delivery', 'delivering')
        LIMIT 1
    ");
    $stmt->execute([$workerId]);
    return $stmt->fetch();
}

// ═══════════════════════════════════════════════════════════════════════════════
// STATUS E LOCALIZAÇÃO
// ═══════════════════════════════════════════════════════════════════════════════

function setOnlineStatus($workerId, $isOnline) {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE om_market_workers SET is_online = ?, last_online = NOW() WHERE worker_id = ?");
    $stmt->execute([$isOnline ? 1 : 0, $workerId]);
    return ['success' => true, 'is_online' => $isOnline];
}

function updateLocation($workerId, $lat, $lng) {
    $pdo = getDB();
    
    // Atualizar ou inserir localização
    $stmt = $pdo->prepare("
        INSERT INTO om_market_worker_locations (worker_id, latitude, longitude, updated_at) 
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE latitude = ?, longitude = ?, updated_at = NOW()
    ");
    $stmt->execute([$workerId, $lat, $lng, $lat, $lng]);
    return ['success' => true];
}

// ═══════════════════════════════════════════════════════════════════════════════
// NOTIFICAÇÕES
// ═══════════════════════════════════════════════════════════════════════════════

function getNotifications($workerId, $limit = 20) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT * FROM om_worker_notifications 
        WHERE worker_id = ? ORDER BY created_at DESC LIMIT ?
    ");
    $stmt->execute([$workerId, $limit]);
    return $stmt->fetchAll();
}

function getUnreadNotifications($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_worker_notifications WHERE worker_id = ? AND is_read = 0");
    $stmt->execute([$workerId]);
    return (int) $stmt->fetchColumn();
}

function markNotificationRead($notificationId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE om_worker_notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ?");
    $stmt->execute([$notificationId]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// PROMOÇÕES E NÍVEIS
// ═══════════════════════════════════════════════════════════════════════════════

function getActivePromotions() {
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT * FROM om_market_promotions 
        WHERE status = 'active' AND start_date <= NOW() AND (end_date IS NULL OR end_date >= NOW())
        ORDER BY created_at DESC
    ");
    return $stmt->fetchAll();
}

function getLevelName($level) {
    $levels = [
        1 => 'Bronze',
        2 => 'Prata',
        3 => 'Ouro',
        4 => 'Platina',
        5 => 'Diamante'
    ];
    return $levels[$level] ?? 'Bronze';
}

function getLevelColor($level) {
    $colors = [
        1 => '#CD7F32',
        2 => '#C0C0C0',
        3 => '#FFD700',
        4 => '#E5E4E2',
        5 => '#B9F2FF'
    ];
    return $colors[$level] ?? '#CD7F32';
}

// ═══════════════════════════════════════════════════════════════════════════════
// UTILIDADES
// ═══════════════════════════════════════════════════════════════════════════════

function formatMoney($value) {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

function sanitize($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'agora';
    if ($diff < 3600) return floor($diff / 60) . ' min';
    if ($diff < 86400) return floor($diff / 3600) . 'h';
    if ($diff < 604800) return floor($diff / 86400) . 'd';
    return date('d/m', $time);
}
PHP;

saveFile('includes/functions.php', $functionsPhp);

// ═══════════════════════════════════════════════════════════════════════════════
// 3. DASHBOARD.PHP - Design Premium Instacart
// ═══════════════════════════════════════════════════════════════════════════════

$dashboardPhp = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$worker = getWorker();
$firstName = explode(' ', $worker['name'])[0];

$balance = getBalance($worker['worker_id']);
$pendingBalance = getPendingBalance($worker['worker_id']);
$todayEarnings = getEarningsToday($worker['worker_id']);
$todayOrders = getOrdersToday($worker['worker_id']);
$weekEarnings = getEarningsWeek($worker['worker_id']);
$weekOrders = getOrdersWeek($worker['worker_id']);
$offers = getAvailableOffers($worker['worker_id']);
$activeOrder = getActiveOrder($worker['worker_id']);
$unreadNotifications = getUnreadNotifications($worker['worker_id']);
$promotions = getActivePromotions();

$hour = date('H');
$greeting = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
$level = $worker['level'] ?? 1;
$levelName = getLevelName($level);
$levelColor = getLevelColor($level);
$rating = $worker['rating'] ?? 5.0;
$isOnline = $worker['is_online'] ?? 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0AAD0A">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Dashboard - OneMundo Shopper</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --green: #0AAD0A;
            --green-dark: #089808;
            --green-light: #E8F5E9;
            --orange: #FF6B00;
            --blue: #2196F3;
            --purple: #9C27B0;
            --red: #F44336;
            --dark: #1A1A2E;
            --gray-900: #212121;
            --gray-700: #616161;
            --gray-500: #9E9E9E;
            --gray-300: #E0E0E0;
            --gray-100: #F5F5F5;
            --white: #FFFFFF;
            --safe-top: env(safe-area-inset-top);
            --safe-bottom: env(safe-area-inset-bottom);
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-100);
            color: var(--gray-900);
            min-height: 100vh;
            padding-bottom: calc(80px + var(--safe-bottom));
            -webkit-font-smoothing: antialiased;
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* HEADER */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .header {
            background: linear-gradient(135deg, var(--green) 0%, var(--green-dark) 100%);
            padding: calc(16px + var(--safe-top)) 20px 80px;
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 80%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }
        
        .header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            z-index: 1;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        
        .user-avatar {
            width: 52px;
            height: 52px;
            background: var(--white);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 800;
            color: var(--green);
            box-shadow: var(--shadow-lg);
            position: relative;
        }
        
        .user-avatar .level-badge {
            position: absolute;
            bottom: -4px;
            right: -4px;
            background: <?= $levelColor ?>;
            color: #000;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 8px;
            border: 2px solid var(--white);
        }
        
        .user-text h1 {
            font-size: 20px;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 2px;
        }
        
        .user-text p {
            font-size: 13px;
            color: rgba(255,255,255,0.85);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .user-text .rating {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: rgba(255,255,255,0.2);
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .header-btn {
            width: 44px;
            height: 44px;
            background: rgba(255,255,255,0.15);
            border: none;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
            transition: all 0.2s;
        }
        
        .header-btn:active { transform: scale(0.95); }
        
        .header-btn svg {
            width: 22px;
            height: 22px;
            color: var(--white);
        }
        
        .header-btn .badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--red);
            color: var(--white);
            font-size: 10px;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--green);
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* BALANCE CARD */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .balance-card {
            background: var(--white);
            border-radius: 24px;
            margin: -50px 16px 20px;
            padding: 24px;
            box-shadow: var(--shadow-xl);
            position: relative;
            z-index: 10;
        }
        
        .balance-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .balance-label {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 500;
        }
        
        .balance-value {
            font-size: 36px;
            font-weight: 800;
            color: var(--gray-900);
            letter-spacing: -1px;
        }
        
        .balance-value small {
            font-size: 20px;
            font-weight: 600;
            color: var(--gray-500);
        }
        
        .balance-pending {
            font-size: 13px;
            color: var(--orange);
            margin-top: 4px;
        }
        
        .balance-actions {
            display: flex;
            gap: 10px;
        }
        
        .balance-btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .balance-btn.primary {
            background: var(--green);
            color: var(--white);
        }
        
        .balance-btn.secondary {
            background: var(--gray-100);
            color: var(--gray-900);
        }
        
        .balance-btn:active { transform: scale(0.98); }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* ONLINE TOGGLE */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .online-card {
            background: var(--white);
            border-radius: 20px;
            margin: 0 16px 20px;
            padding: 20px;
            box-shadow: var(--shadow-md);
        }
        
        .online-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .online-info h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
        }
        
        .online-info p {
            font-size: 13px;
            color: var(--gray-500);
        }
        
        .toggle {
            position: relative;
            width: 60px;
            height: 32px;
        }
        
        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: var(--gray-300);
            border-radius: 32px;
            transition: 0.3s;
        }
        
        .toggle-slider::before {
            position: absolute;
            content: '';
            height: 26px;
            width: 26px;
            left: 3px;
            bottom: 3px;
            background: var(--white);
            border-radius: 50%;
            transition: 0.3s;
            box-shadow: var(--shadow-md);
        }
        
        .toggle input:checked + .toggle-slider {
            background: var(--green);
        }
        
        .toggle input:checked + .toggle-slider::before {
            transform: translateX(28px);
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* STATS GRID */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 0 16px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: 16px;
            padding: 16px;
            box-shadow: var(--shadow-sm);
        }
        
        .stat-card.highlight {
            background: linear-gradient(135deg, var(--green) 0%, var(--green-dark) 100%);
        }
        
        .stat-card.highlight .stat-label,
        .stat-card.highlight .stat-value {
            color: var(--white);
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 12px;
        }
        
        .stat-card:not(.highlight) .stat-icon {
            background: var(--gray-100);
        }
        
        .stat-card.highlight .stat-icon {
            background: rgba(255,255,255,0.2);
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--gray-500);
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* ACTIVE ORDER */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .active-order {
            background: linear-gradient(135deg, var(--orange) 0%, #FF8533 100%);
            border-radius: 20px;
            margin: 0 16px 20px;
            padding: 20px;
            color: var(--white);
            box-shadow: var(--shadow-lg);
        }
        
        .active-order-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        
        .active-order-header h3 {
            font-size: 16px;
            font-weight: 700;
        }
        
        .active-order-status {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .active-order-info {
            margin-bottom: 16px;
        }
        
        .active-order-info h4 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .active-order-info p {
            font-size: 13px;
            opacity: 0.9;
        }
        
        .active-order-btn {
            display: block;
            background: var(--white);
            color: var(--orange);
            text-decoration: none;
            text-align: center;
            padding: 14px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 15px;
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* SECTIONS */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .section {
            padding: 0 16px;
            margin-bottom: 24px;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title .count {
            background: var(--green);
            color: var(--white);
            font-size: 12px;
            padding: 2px 10px;
            border-radius: 20px;
        }
        
        .section-link {
            font-size: 14px;
            color: var(--green);
            text-decoration: none;
            font-weight: 600;
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* OFFER CARDS */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .offer-card {
            background: var(--white);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 12px;
            box-shadow: var(--shadow-md);
            border: 2px solid transparent;
            transition: all 0.2s;
        }
        
        .offer-card:active {
            transform: scale(0.99);
            border-color: var(--green);
        }
        
        .offer-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
        }
        
        .offer-logo {
            width: 50px;
            height: 50px;
            background: var(--gray-100);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .offer-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 14px;
        }
        
        .offer-info h4 {
            font-size: 16px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
        }
        
        .offer-info p {
            font-size: 13px;
            color: var(--gray-500);
        }
        
        .offer-earning {
            margin-left: auto;
            text-align: right;
        }
        
        .offer-earning .value {
            font-size: 20px;
            font-weight: 800;
            color: var(--green);
        }
        
        .offer-earning .label {
            font-size: 11px;
            color: var(--gray-500);
        }
        
        .offer-details {
            display: flex;
            gap: 16px;
            padding: 12px 0;
            border-top: 1px solid var(--gray-100);
            border-bottom: 1px solid var(--gray-100);
            margin-bottom: 16px;
        }
        
        .offer-detail {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--gray-700);
        }
        
        .offer-detail svg {
            width: 16px;
            height: 16px;
            color: var(--gray-500);
        }
        
        .offer-actions {
            display: flex;
            gap: 10px;
        }
        
        .offer-btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .offer-btn.accept {
            background: var(--green);
            color: var(--white);
        }
        
        .offer-btn.reject {
            background: var(--gray-100);
            color: var(--gray-700);
        }
        
        .offer-btn:active { transform: scale(0.98); }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* PROMOTIONS */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .promo-scroll {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding: 0 16px 8px;
            margin: 0 -16px;
            scrollbar-width: none;
        }
        
        .promo-scroll::-webkit-scrollbar { display: none; }
        
        .promo-card {
            min-width: 280px;
            background: linear-gradient(135deg, var(--purple) 0%, #7B1FA2 100%);
            border-radius: 20px;
            padding: 20px;
            color: var(--white);
        }
        
        .promo-card.bonus { background: linear-gradient(135deg, var(--orange) 0%, #E65100 100%); }
        .promo-card.multiplier { background: linear-gradient(135deg, var(--blue) 0%, #1565C0 100%); }
        
        .promo-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .promo-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .promo-desc {
            font-size: 13px;
            opacity: 0.9;
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* EMPTY STATE */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: var(--white);
            border-radius: 20px;
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .empty-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
        }
        
        .empty-text {
            font-size: 14px;
            color: var(--gray-500);
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* BOTTOM NAV */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--white);
            border-top: 1px solid var(--gray-300);
            padding: 8px 0;
            padding-bottom: calc(8px + var(--safe-bottom));
            z-index: 100;
        }
        
        .nav-items {
            display: flex;
            justify-content: space-around;
            max-width: 500px;
            margin: 0 auto;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 8px 16px;
            text-decoration: none;
            color: var(--gray-500);
            transition: all 0.2s;
        }
        
        .nav-item.active {
            color: var(--green);
        }
        
        .nav-item svg {
            width: 24px;
            height: 24px;
        }
        
        .nav-item span {
            font-size: 11px;
            font-weight: 600;
        }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* ANIMATIONS */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .pulse { animation: pulse 2s infinite; }
        
        /* ══════════════════════════════════════════════════════════════════════ */
        /* TOAST */
        /* ══════════════════════════════════════════════════════════════════════ */
        
        .toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--gray-900);
            color: var(--white);
            padding: 14px 24px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 500;
            opacity: 0;
            transition: all 0.3s;
            z-index: 1000;
        }
        
        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
        
        .toast.success { background: var(--green); }
        .toast.error { background: var(--red); }
    </style>
</head>
<body>

<!-- Header -->
<header class="header">
    <div class="header-top">
        <div class="user-info">
            <div class="user-avatar">
                <?= strtoupper(substr($firstName, 0, 1)) ?>
                <span class="level-badge"><?= $levelName ?></span>
            </div>
            <div class="user-text">
                <h1><?= $greeting ?>, <?= sanitize($firstName) ?>!</h1>
                <p>
                    <span class="rating">⭐ <?= number_format($rating, 1) ?></span>
                    Level <?= $level ?>
                </p>
            </div>
        </div>
        <div class="header-actions">
            <a href="notificacoes.php" class="header-btn">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <?php if ($unreadNotifications > 0): ?>
                <span class="badge"><?= $unreadNotifications ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>
</header>

<!-- Balance Card -->
<div class="balance-card">
    <div class="balance-header">
        <div>
            <div class="balance-label">Saldo disponível</div>
            <div class="balance-value">
                <small>R$</small> <?= number_format($balance, 2, ',', '.') ?>
            </div>
            <?php if ($pendingBalance > 0): ?>
            <div class="balance-pending">+ <?= formatMoney($pendingBalance) ?> pendente</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="balance-actions">
        <a href="saque.php" class="balance-btn primary">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Sacar
        </a>
        <a href="carteira.php" class="balance-btn secondary">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            Extrato
        </a>
    </div>
</div>

<!-- Online Toggle -->
<div class="online-card">
    <div class="online-header">
        <div class="online-info">
            <h3 id="onlineText"><?= $isOnline ? '🟢 Você está online' : '⚪ Você está offline' ?></h3>
            <p id="onlineDesc"><?= $isOnline ? 'Recebendo ofertas de pedidos' : 'Fique online para receber ofertas' ?></p>
        </div>
        <label class="toggle">
            <input type="checkbox" id="onlineToggle" <?= $isOnline ? 'checked' : '' ?> onchange="toggleOnline(this.checked)">
            <span class="toggle-slider"></span>
        </label>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card highlight">
        <div class="stat-icon">💰</div>
        <div class="stat-label">Ganhos Hoje</div>
        <div class="stat-value"><?= formatMoney($todayEarnings) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📦</div>
        <div class="stat-label">Pedidos Hoje</div>
        <div class="stat-value"><?= $todayOrders ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📊</div>
        <div class="stat-label">Esta Semana</div>
        <div class="stat-value"><?= formatMoney($weekEarnings) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🎯</div>
        <div class="stat-label">Pedidos Semana</div>
        <div class="stat-value"><?= $weekOrders ?></div>
    </div>
</div>

<?php if ($activeOrder): ?>
<!-- Active Order -->
<div class="active-order">
    <div class="active-order-header">
        <h3>🛒 Pedido em Andamento</h3>
        <span class="active-order-status"><?= strtoupper($activeOrder['status']) ?></span>
    </div>
    <div class="active-order-info">
        <h4><?= sanitize($activeOrder['partner_name']) ?></h4>
        <p>#<?= $activeOrder['order_number'] ?></p>
    </div>
    <a href="shopping.php?id=<?= $activeOrder['order_id'] ?>" class="active-order-btn">
        Continuar Pedido →
    </a>
</div>
<?php endif; ?>

<?php if (!empty($promotions)): ?>
<!-- Promotions -->
<div class="section">
    <div class="section-header">
        <h2 class="section-title">🎁 Promoções</h2>
    </div>
    <div class="promo-scroll">
        <?php foreach ($promotions as $promo): ?>
        <div class="promo-card <?= $promo['type'] ?>">
            <span class="promo-badge"><?= strtoupper($promo['type']) ?></span>
            <h3 class="promo-title"><?= sanitize($promo['title']) ?></h3>
            <p class="promo-desc"><?= sanitize($promo['description']) ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Offers Section -->
<div class="section">
    <div class="section-header">
        <h2 class="section-title">
            📋 Ofertas Disponíveis
            <?php if (count($offers) > 0): ?>
            <span class="count"><?= count($offers) ?></span>
            <?php endif; ?>
        </h2>
        <a href="ofertas.php" class="section-link">Ver todas</a>
    </div>
    
    <?php if (empty($offers)): ?>
    <div class="empty-state">
        <div class="empty-icon">📭</div>
        <div class="empty-title">Nenhuma oferta no momento</div>
        <div class="empty-text">Fique online e aguarde novas ofertas</div>
    </div>
    <?php else: ?>
    <?php foreach (array_slice($offers, 0, 3) as $offer): ?>
    <div class="offer-card">
        <div class="offer-header">
            <div class="offer-logo">
                <?php if ($offer['partner_logo']): ?>
                <img src="<?= $offer['partner_logo'] ?>" alt="">
                <?php else: ?>
                🏪
                <?php endif; ?>
            </div>
            <div class="offer-info">
                <h4><?= sanitize($offer['partner_name']) ?></h4>
                <p>#<?= $offer['order_number'] ?></p>
            </div>
            <div class="offer-earning">
                <div class="value"><?= formatMoney($offer['shopper_earnings'] ?? 15) ?></div>
                <div class="label">Ganho</div>
            </div>
        </div>
        <div class="offer-details">
            <div class="offer-detail">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                <?= $offer['item_count'] ?> itens
            </div>
            <div class="offer-detail">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                ~30 min
            </div>
        </div>
        <div class="offer-actions">
            <button class="offer-btn reject" onclick="rejectOffer(<?= $offer['order_id'] ?>)">Recusar</button>
            <button class="offer-btn accept" onclick="acceptOffer(<?= $offer['order_id'] ?>)">Aceitar</button>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Bottom Nav -->
<nav class="bottom-nav">
    <div class="nav-items">
        <a href="dashboard.php" class="nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <span>Home</span>
        </a>
        <a href="ofertas.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
            <span>Ofertas</span>
        </a>
        <a href="carteira.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            <span>Carteira</span>
        </a>
        <a href="pedidos.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>Histórico</span>
        </a>
        <a href="perfil.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            <span>Perfil</span>
        </a>
    </div>
</nav>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'toast show ' + type;
    setTimeout(() => toast.className = 'toast', 3000);
}

function toggleOnline(isOnline) {
    fetch('api/toggle-online.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({is_online: isOnline})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('onlineText').textContent = isOnline ? '🟢 Você está online' : '⚪ Você está offline';
            document.getElementById('onlineDesc').textContent = isOnline ? 'Recebendo ofertas de pedidos' : 'Fique online para receber ofertas';
            showToast(isOnline ? 'Você está online!' : 'Você está offline');
        }
    });
}

function acceptOffer(orderId) {
    if (!confirm('Aceitar este pedido?')) return;
    
    fetch('api/accept-offer.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({order_id: orderId})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Pedido aceito!');
            setTimeout(() => location.href = 'shopping.php?id=' + orderId, 500);
        } else {
            showToast(data.error || 'Erro ao aceitar', 'error');
        }
    });
}

function rejectOffer(orderId) {
    fetch('api/reject-offer.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({order_id: orderId})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

// GPS tracking quando online
<?php if ($isOnline): ?>
if (navigator.geolocation) {
    navigator.geolocation.watchPosition(pos => {
        fetch('api/update-location.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                lat: pos.coords.latitude,
                lng: pos.coords.longitude
            })
        });
    }, null, {enableHighAccuracy: true, maximumAge: 30000});
}
<?php endif; ?>
</script>

</body>
</html>
PHP;

saveFile('dashboard.php', $dashboardPhp);

// ═══════════════════════════════════════════════════════════════════════════════
// 4. CARTEIRA.PHP - Carteira Digital Premium
// ═══════════════════════════════════════════════════════════════════════════════

$carteiraPhp = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$worker = getWorker();
$balance = getBalance($worker['worker_id']);
$pendingBalance = getPendingBalance($worker['worker_id']);
$transactions = getTransactions($worker['worker_id'], 30);
$withdrawals = getWithdrawals($worker['worker_id']);

$weekEarnings = getEarningsWeek($worker['worker_id']);
$monthEarnings = getEarningsMonth($worker['worker_id']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0AAD0A">
    <title>Carteira - OneMundo Shopper</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --green: #0AAD0A;
            --green-dark: #089808;
            --green-light: #E8F5E9;
            --orange: #FF6B00;
            --blue: #2196F3;
            --red: #F44336;
            --gray-900: #212121;
            --gray-700: #616161;
            --gray-500: #9E9E9E;
            --gray-300: #E0E0E0;
            --gray-100: #F5F5F5;
            --white: #FFFFFF;
            --safe-top: env(safe-area-inset-top);
            --safe-bottom: env(safe-area-inset-bottom);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--gray-100);
            color: var(--gray-900);
            min-height: 100vh;
            padding-bottom: calc(80px + var(--safe-bottom));
        }
        
        .header {
            background: linear-gradient(135deg, var(--green) 0%, var(--green-dark) 100%);
            padding: calc(16px + var(--safe-top)) 20px 100px;
            position: relative;
        }
        
        .header-top {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .back-btn {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.15);
            border: none;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--white);
        }
        
        .header-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--white);
        }
        
        .wallet-card {
            background: var(--white);
            border-radius: 24px;
            margin: -70px 16px 20px;
            padding: 28px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            position: relative;
            z-index: 10;
        }
        
        .wallet-balance {
            text-align: center;
            margin-bottom: 24px;
        }
        
        .wallet-label {
            font-size: 14px;
            color: var(--gray-500);
            margin-bottom: 8px;
        }
        
        .wallet-value {
            font-size: 42px;
            font-weight: 800;
            color: var(--gray-900);
            letter-spacing: -2px;
        }
        
        .wallet-value small {
            font-size: 24px;
            font-weight: 600;
            color: var(--gray-500);
        }
        
        .wallet-pending {
            font-size: 14px;
            color: var(--orange);
            margin-top: 8px;
        }
        
        .wallet-actions {
            display: flex;
            gap: 12px;
        }
        
        .wallet-btn {
            flex: 1;
            padding: 16px;
            border: none;
            border-radius: 16px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .wallet-btn.primary {
            background: var(--green);
            color: var(--white);
        }
        
        .wallet-btn.secondary {
            background: var(--gray-100);
            color: var(--gray-900);
        }
        
        .stats-row {
            display: flex;
            gap: 12px;
            padding: 0 16px;
            margin-bottom: 24px;
        }
        
        .stat-box {
            flex: 1;
            background: var(--white);
            border-radius: 16px;
            padding: 16px;
            text-align: center;
        }
        
        .stat-box-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }
        
        .stat-box-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        .stat-box-label {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 4px;
        }
        
        .section {
            padding: 0 16px;
            margin-bottom: 24px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 16px;
        }
        
        .transaction-list {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
        }
        
        .transaction-item {
            display: flex;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .transaction-item:last-child {
            border-bottom: none;
        }
        
        .transaction-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-right: 14px;
        }
        
        .transaction-icon.earning {
            background: var(--green-light);
        }
        
        .transaction-icon.withdrawal {
            background: #FFF3E0;
        }
        
        .transaction-info {
            flex: 1;
        }
        
        .transaction-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 2px;
        }
        
        .transaction-date {
            font-size: 12px;
            color: var(--gray-500);
        }
        
        .transaction-amount {
            font-size: 16px;
            font-weight: 700;
        }
        
        .transaction-amount.positive {
            color: var(--green);
        }
        
        .transaction-amount.negative {
            color: var(--red);
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            background: var(--white);
            border-radius: 20px;
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .empty-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-900);
        }
        
        .empty-text {
            font-size: 14px;
            color: var(--gray-500);
        }
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--white);
            border-top: 1px solid var(--gray-300);
            padding: 8px 0;
            padding-bottom: calc(8px + var(--safe-bottom));
            z-index: 100;
        }
        
        .nav-items {
            display: flex;
            justify-content: space-around;
            max-width: 500px;
            margin: 0 auto;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 8px 16px;
            text-decoration: none;
            color: var(--gray-500);
        }
        
        .nav-item.active {
            color: var(--green);
        }
        
        .nav-item svg {
            width: 24px;
            height: 24px;
        }
        
        .nav-item span {
            font-size: 11px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<header class="header">
    <div class="header-top">
        <button class="back-btn" onclick="history.back()">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <h1 class="header-title">Carteira</h1>
    </div>
</header>

<div class="wallet-card">
    <div class="wallet-balance">
        <div class="wallet-label">Saldo disponível</div>
        <div class="wallet-value">
            <small>R$</small> <?= number_format($balance, 2, ',', '.') ?>
        </div>
        <?php if ($pendingBalance > 0): ?>
        <div class="wallet-pending">+ <?= formatMoney($pendingBalance) ?> pendente</div>
        <?php endif; ?>
    </div>
    <div class="wallet-actions">
        <a href="saque.php" class="wallet-btn primary">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Sacar via PIX
        </a>
        <a href="dados-bancarios.php" class="wallet-btn secondary">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Dados PIX
        </a>
    </div>
</div>

<div class="stats-row">
    <div class="stat-box">
        <div class="stat-box-icon">📅</div>
        <div class="stat-box-value"><?= formatMoney($weekEarnings) ?></div>
        <div class="stat-box-label">Esta semana</div>
    </div>
    <div class="stat-box">
        <div class="stat-box-icon">📆</div>
        <div class="stat-box-value"><?= formatMoney($monthEarnings) ?></div>
        <div class="stat-box-label">Este mês</div>
    </div>
</div>

<div class="section">
    <h2 class="section-title">Histórico</h2>
    
    <?php if (empty($transactions)): ?>
    <div class="empty-state">
        <div class="empty-icon">💳</div>
        <div class="empty-title">Nenhuma transação ainda</div>
        <div class="empty-text">Suas transações aparecerão aqui</div>
    </div>
    <?php else: ?>
    <div class="transaction-list">
        <?php foreach ($transactions as $tx): ?>
        <div class="transaction-item">
            <div class="transaction-icon <?= $tx['type'] ?>">
                <?= $tx['type'] === 'earning' ? '💰' : ($tx['type'] === 'withdrawal' ? '📤' : '💳') ?>
            </div>
            <div class="transaction-info">
                <div class="transaction-title"><?= sanitize($tx['description'] ?: 'Transação') ?></div>
                <div class="transaction-date"><?= date('d/m/Y H:i', strtotime($tx['created_at'])) ?></div>
            </div>
            <div class="transaction-amount <?= $tx['amount'] > 0 ? 'positive' : 'negative' ?>">
                <?= $tx['amount'] > 0 ? '+' : '' ?><?= formatMoney($tx['amount']) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<nav class="bottom-nav">
    <div class="nav-items">
        <a href="dashboard.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <span>Home</span>
        </a>
        <a href="ofertas.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
            <span>Ofertas</span>
        </a>
        <a href="carteira.php" class="nav-item active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            <span>Carteira</span>
        </a>
        <a href="pedidos.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>Histórico</span>
        </a>
        <a href="perfil.php" class="nav-item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            <span>Perfil</span>
        </a>
    </div>
</nav>

</body>
</html>
PHP;

saveFile('carteira.php', $carteiraPhp);

// ═══════════════════════════════════════════════════════════════════════════════
// 5. APIs ESSENCIAIS
// ═══════════════════════════════════════════════════════════════════════════════

// API: Toggle Online
$apiToggleOnline = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$isOnline = $data['is_online'] ?? false;

$result = setOnlineStatus(getWorkerId(), $isOnline);
echo json_encode($result);
PHP;
saveFile('api/toggle-online.php', $apiToggleOnline);

// API: Accept Offer
$apiAcceptOffer = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$orderId = $data['order_id'] ?? 0;

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'ID do pedido inválido']);
    exit;
}

$result = acceptOffer(getWorkerId(), $orderId);
echo json_encode($result);
PHP;
saveFile('api/accept-offer.php', $apiAcceptOffer);

// API: Reject Offer
$apiRejectOffer = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$orderId = $data['order_id'] ?? 0;

$result = rejectOffer(getWorkerId(), $orderId);
echo json_encode($result);
PHP;
saveFile('api/reject-offer.php', $apiRejectOffer);

// API: Update Location
$apiUpdateLocation = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$lat = $data['lat'] ?? 0;
$lng = $data['lng'] ?? 0;

if ($lat && $lng) {
    $result = updateLocation(getWorkerId(), $lat, $lng);
    echo json_encode($result);
} else {
    echo json_encode(['success' => false, 'error' => 'Coordenadas inválidas']);
}
PHP;
saveFile('api/update-location.php', $apiUpdateLocation);

// API: Request Withdrawal
$apiWithdraw = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$amount = floatval($data['amount'] ?? 0);
$pixKey = $data['pix_key'] ?? '';
$pixType = $data['pix_type'] ?? '';

if ($amount < 10) {
    echo json_encode(['success' => false, 'error' => 'Valor mínimo: R$ 10,00']);
    exit;
}

if (empty($pixKey)) {
    echo json_encode(['success' => false, 'error' => 'Chave PIX obrigatória']);
    exit;
}

$result = requestWithdrawal(getWorkerId(), $amount, $pixKey, $pixType);
echo json_encode($result);
PHP;
saveFile('api/withdraw.php', $apiWithdraw);

// API: Get Notifications
$apiNotifications = <<<'PHP'
<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$notifications = getNotifications(getWorkerId());
$unread = getUnreadNotifications(getWorkerId());

echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'unread_count' => $unread
]);
PHP;
saveFile('api/get-notifications.php', $apiNotifications);

// ═══════════════════════════════════════════════════════════════════════════════
// OUTPUT HTML
// ═══════════════════════════════════════════════════════════════════════════════

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚀 MEGA Shopper App Installer</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #e2e8f0; min-height: 100vh; padding: 30px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #10b981; font-size: 32px; margin-bottom: 10px; display: flex; align-items: center; gap: 12px; }
        .subtitle { color: #94a3b8; margin-bottom: 30px; }
        .card { background: rgba(30, 41, 59, 0.8); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 24px; margin-bottom: 20px; }
        .card h2 { color: #f59e0b; font-size: 18px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .result { padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 14px; }
        .result:last-child { border-bottom: none; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 30px; }
        .stat { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 12px; padding: 20px; text-align: center; }
        .stat-value { font-size: 36px; font-weight: 800; color: #10b981; }
        .stat-label { font-size: 13px; color: #94a3b8; margin-top: 4px; }
        .btn { display: inline-block; padding: 16px 32px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; text-decoration: none; border-radius: 12px; font-weight: 700; font-size: 16px; margin: 8px; transition: all 0.3s; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(16,185,129,0.3); }
        .btn.secondary { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); }
        .features { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 20px; }
        .feature { background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 10px; padding: 14px; font-size: 13px; }
        .feature strong { color: #a5b4fc; }
    </style>
</head>
<body>
<div class="container">
    <h1>🚀 MEGA Shopper App</h1>
    <p class="subtitle">Instalador Premium - Design estilo Instacart 2024</p>
    
    <div class="stats">
        <div class="stat">
            <div class="stat-value"><?= count($results) ?></div>
            <div class="stat-label">Arquivos Criados</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= count($errors) ?></div>
            <div class="stat-label">Erros</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= count($errors) == 0 ? '100%' : round((count($results) / (count($results) + count($errors))) * 100) . '%' ?></div>
            <div class="stat-label">Sucesso</div>
        </div>
    </div>
    
    <div class="card">
        <h2>📁 Arquivos Instalados</h2>
        <?php foreach ($results as $r): ?>
        <div class="result success"><?= $r ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
        <div class="result error"><?= $e ?></div>
        <?php endforeach; ?>
    </div>
    
    <div class="card">
        <h2>✨ Funcionalidades Incluídas</h2>
        <div class="features">
            <div class="feature"><strong>📊 Dashboard Premium</strong><br>Design Instacart 2024</div>
            <div class="feature"><strong>💰 Carteira Digital</strong><br>Saldo, extrato, saques</div>
            <div class="feature"><strong>📋 Sistema de Ofertas</strong><br>Aceitar/recusar pedidos</div>
            <div class="feature"><strong>🔔 Notificações</strong><br>Alertas em tempo real</div>
            <div class="feature"><strong>📍 GPS Tracking</strong><br>Localização automática</div>
            <div class="feature"><strong>🟢 Toggle Online</strong><br>Ficar disponível</div>
            <div class="feature"><strong>💳 Saques PIX</strong><br>Saque instantâneo</div>
            <div class="feature"><strong>🎁 Promoções</strong><br>Bônus e multiplicadores</div>
        </div>
    </div>
    
    <div style="text-align: center; margin-top: 30px;">
        <a href="login.php" class="btn">🔐 Fazer Login</a>
        <a href="dashboard.php" class="btn secondary">📊 Ver Dashboard</a>
    </div>
</div>
</body>
</html>
PHP;

saveFile('MEGA_SHOPPER_APP.php', file_get_contents(__FILE__));

// ═══════════════════════════════════════════════════════════════════════════════
// FIM DO INSTALADOR
// ═══════════════════════════════════════════════════════════════════════════════
