<?php
/**
 * ONEMUNDO MARKET - APP DO SHOPPER
 * Interface mobile para entregadores
 */
session_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once dirname(__DIR__) . '/config/database.php';
$pdo = getPDO();

$loggedIn = isset($_SESSION['shopper_id']);
$shopper = null;
$error = '';

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($email && $pass) {
        $stmt = $pdo->prepare("SELECT * FROM om_rh_users WHERE email = ? AND status = '1'");
        $stmt->execute([$email]);
        $rh = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rh) {
            if (password_verify($pass, $rh['password'])) {
                $rhId = $rh['rh_user_id'];
                $stmt2 = $pdo->prepare("SELECT * FROM om_market_shoppers WHERE rh_user_id = ?");
                $stmt2->execute([$rhId]);
                $shopperRow = $stmt2->fetch(PDO::FETCH_ASSOC);
                if (!$shopperRow) {
                    $stmt3 = $pdo->prepare("INSERT INTO om_market_shoppers (rh_user_id) VALUES (?)");
                    $stmt3->execute([$rhId]);
                    $shopperId = $pdo->lastInsertId();
                } else {
                    $shopperId = $shopperRow['shopper_id'];
                }
                $_SESSION['shopper_id'] = $shopperId;
                $_SESSION['shopper_name'] = $rh['name'];
                $loggedIn = true;
            } else $error = 'Senha incorreta';
        } else $error = 'Email não encontrado';
    }
}
if (($_POST['action'] ?? '') === 'logout') { session_destroy(); header('Location: ?'); exit; }

// Carregar shopper
if ($loggedIn) {
    $sid = intval($_SESSION['shopper_id']);
    // Implementar cache de sessão para dados do shopper
    if (!isset($_SESSION['shopper_data']) || ($_SESSION['shopper_cache_time'] ?? 0) < time() - 300) {
        $stmt = $pdo->prepare("SELECT s.*, u.name, u.email FROM om_market_shoppers s JOIN om_rh_users u ON s.rh_user_id = u.rh_user_id WHERE s.shopper_id = ?");
        $stmt->execute([$sid]);
        $shopperData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($shopperData) {
            $_SESSION['shopper_data'] = $shopperData;
            $_SESSION['shopper_cache_time'] = time();
        }
    }
    $shopper = $_SESSION['shopper_data'] ?? null;
    if (!$shopper) { session_destroy(); $loggedIn = false; }
}

// APIs
if (isset($_GET['api']) && $loggedIn) {
    header('Content-Type: application/json');
    $sid = intval($_SESSION['shopper_id']);
    $api = $_GET['api'];
    
    if ($api === 'toggle_online') {
        $stmt = $pdo->prepare("SELECT is_online FROM om_market_shoppers WHERE shopper_id = ?");
        $stmt->execute([$sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $new = $row ? ($row['is_online'] ? 0 : 1) : 0;
        $stmt2 = $pdo->prepare("UPDATE om_market_shoppers SET is_online = ? WHERE shopper_id = ?");
        $stmt2->execute([$new, $sid]);
        echo json_encode(['success' => true, 'is_online' => $new]); exit;
    }
    
    if ($api === 'stats') {
        $stats = ['today_deliveries' => 0, 'today_earnings' => 0, 'total_deliveries' => 0, 'total_earnings' => 0, 'rating' => 5.0, 'pending_orders' => 0];
        $stmt = $pdo->prepare("SELECT total_deliveries, total_earnings, rating FROM om_market_shoppers WHERE shopper_id = ?");
        $stmt->execute([$sid]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $stats['total_deliveries'] = intval($row['total_deliveries']); $stats['total_earnings'] = floatval($row['total_earnings']); $stats['rating'] = floatval($row['rating']); }
        $stmt = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(delivery_fee),0) e FROM om_market_orders WHERE shopper_id = ? AND DATE(date_delivered) = CURRENT_DATE AND status = 'delivered'");
        $stmt->execute([$sid]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $stats['today_deliveries'] = intval($row['c']); $stats['today_earnings'] = floatval($row['e']); }
        $stmt = $pdo->query("SELECT COUNT(*) c FROM om_market_orders WHERE status = 'pending' AND shopper_id IS NULL");
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $stats['pending_orders'] = intval($row['c']);
        echo json_encode(['success' => true, 'stats' => $stats]); exit;
    }
    
    if ($api === 'available_orders') {
        $orders = [];
        $sql = "SELECT o.*, p.name as partner_name, p.trade_name, (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as item_count FROM om_market_orders o JOIN om_market_partners p ON o.partner_id = p.partner_id WHERE o.status = 'pending' AND o.shopper_id IS NULL ORDER BY o.date_added DESC LIMIT 20";
        $stmt = $pdo->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $orders[] = $row;
        echo json_encode(['success' => true, 'orders' => $orders]); exit;
    }
    
    if ($api === 'accept_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($input['order_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND status = 'pending' AND shopper_id IS NULL");
        $stmt->execute([$orderId]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            $stmt2 = $pdo->prepare("UPDATE om_market_orders SET shopper_id = ?, status = 'confirmed', date_confirmed = NOW() WHERE order_id = ?");
            $stmt2->execute([$sid, $orderId]);
            $stmt3 = $pdo->prepare("UPDATE om_market_shoppers SET is_busy = 1, current_order_id = ? WHERE shopper_id = ?");
            $stmt3->execute([$orderId, $sid]);
            $stmt4 = $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, message) VALUES (?, 'system', 'Shopper aceitou seu pedido!')");
            $stmt4->execute([$orderId]);
            echo json_encode(['success' => true]);
        } else echo json_encode(['success' => false, 'error' => 'Pedido não disponível']);
        exit;
    }
    
    if ($api === 'current_order') {
        $stmt = $pdo->prepare("SELECT o.*, p.name as partner_name, p.trade_name, p.address as partner_address, p.city as partner_city FROM om_market_orders o JOIN om_market_partners p ON o.partner_id = p.partner_id WHERE o.shopper_id = ? AND o.status IN ('confirmed', 'shopping', 'delivering') LIMIT 1");
        $stmt->execute([$sid]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order) {
            $items = [];
            $stmt2 = $pdo->prepare("SELECT oi.*, pb.image as product_image, pl.aisle, pl.section FROM om_market_order_items oi LEFT JOIN om_market_products_base pb ON oi.product_id = pb.product_id LEFT JOIN om_market_product_location pl ON pl.product_id = oi.product_id AND pl.partner_id = ? WHERE oi.order_id = ?");
            $stmt2->execute([$order['partner_id'], $order['order_id']]);
            while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) $items[] = $row;
            $order['items'] = $items;
            echo json_encode(['success' => true, 'order' => $order]);
        } else echo json_encode(['success' => true, 'order' => null]);
        exit;
    }
    
    if ($api === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($input['order_id'] ?? 0);
        $status = $input['status'] ?? '';
        if (!$orderId || !in_array($status, ['shopping', 'delivering', 'delivered'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
            exit;
        }
        $dateField = $status === 'shopping' ? 'date_shopping' : ($status === 'delivering' ? 'date_dispatched' : 'date_delivered');
        $stmt = $pdo->prepare("UPDATE om_market_orders SET status = ?, $dateField = NOW() WHERE order_id = ? AND shopper_id = ?");
        if ($stmt->execute([$status, $orderId, $sid])) {
            $msgs = ['shopping' => 'Shopper está fazendo suas compras!', 'delivering' => 'Shopper está a caminho!', 'delivered' => 'Pedido entregue!'];
            $stmt2 = $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, message) VALUES (?, 'system', ?)");
            $stmt2->execute([$orderId, $msgs[$status]]);
            if ($status === 'delivered') {
                $stmt3 = $pdo->prepare("SELECT delivery_fee FROM om_market_orders WHERE order_id = ?");
                $stmt3->execute([$orderId]);
                $feeRow = $stmt3->fetch(PDO::FETCH_ASSOC);
                $fee = $feeRow ? floatval($feeRow['delivery_fee']) : 0;
                $stmt4 = $pdo->prepare("UPDATE om_market_shoppers SET is_busy = 0, current_order_id = NULL, total_deliveries = total_deliveries + 1, total_earnings = total_earnings + ? WHERE shopper_id = ?");
                $stmt4->execute([$fee, $sid]);
            }
            echo json_encode(['success' => true]);
        } else { echo json_encode(['success' => false]); }
        exit;
    }
    
    if ($api === 'update_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $itemId = intval($input['item_id'] ?? 0);
        $status = $input['status'] ?? '';
        if (in_array($status, ['found', 'not_found', 'substituted'])) {
            $stmt = $pdo->prepare("UPDATE om_market_order_items SET status = ? WHERE item_id = ?");
            $stmt->execute([$status, $itemId]);
            echo json_encode(['success' => true]);
        } else echo json_encode(['success' => false]);
        exit;
    }
    
    if ($api === 'verify_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($input['order_id'] ?? 0);
        $code = strtoupper(trim($input['code'] ?? ''));
        $stmt = $pdo->prepare("SELECT verification_code FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
        $stmt->execute([$orderId, $sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo json_encode(['success' => true, 'valid' => $row['verification_code'] === $code]);
        } else echo json_encode(['success' => false]);
        exit;
    }
    
    if ($api === 'chat_messages') {
        $orderId = intval($_GET['order_id'] ?? 0);
        $messages = [];
        $stmt = $pdo->prepare("SELECT * FROM om_market_chat WHERE order_id = ? ORDER BY date_added ASC");
        $stmt->execute([$orderId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $messages[] = $row;
        echo json_encode(['success' => true, 'messages' => $messages]); exit;
    }
    
    if ($api === 'chat_send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $orderId = intval($input['order_id'] ?? 0);
        $msg = trim($input['message'] ?? '');
        if ($msg) {
            $stmt = $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, message) VALUES (?, 'shopper', ?, ?)");
            $stmt->execute([$orderId, $sid, $msg]);
            echo json_encode(['success' => true]);
        } else echo json_encode(['success' => false]);
        exit;
    }
    
    if ($api === 'history') {
        $orders = [];
        $stmt = $pdo->prepare("SELECT o.*, p.trade_name as partner_name FROM om_market_orders o JOIN om_market_partners p ON o.partner_id = p.partner_id WHERE o.shopper_id = ? AND o.status = 'delivered' ORDER BY o.date_delivered DESC LIMIT 50");
        $stmt->execute([$sid]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $orders[] = $row;
        echo json_encode(['success' => true, 'orders' => $orders]); exit;
    }
    
    echo json_encode(['success' => false]); exit;
}

$shopperName = $shopper ? explode(' ', $shopper['name'])[0] : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<meta name="theme-color" content="#f59e0b">
<title>OneMundo Shopper</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/shopper.css">
    <style>
<script src="assets/js/shopper.js" defer></script>
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100dvh}
.login{min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:20px}
.login-card{width:100%;max-width:400px;background:var(--card);border:1px solid var(--border);border-radius:24px;padding:40px 28px}
.login-card::before{content:'';display:block;height:4px;background:linear-gradient(90deg,var(--primary),var(--primary-dark));margin:-40px -28px 30px;border-radius:24px 24px 0 0}
.login-header{text-align:center;margin-bottom:32px}
.login-icon{width:80px;height:80px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));border-radius:20px;display:inline-flex;align-items:center;justify-content:center;font-size:40px;margin-bottom:16px}
.login-title{font-size:24px;font-weight:800}
.login-subtitle{font-size:14px;color:var(--text2);margin-top:6px}
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--text2);margin-bottom:8px}
.form-input{width:100%;padding:14px 16px;background:var(--bg);border:2px solid var(--border);border-radius:12px;color:var(--text);font-size:15px}
.form-input:focus{outline:none;border-color:var(--primary)}
.btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:16px;font-size:15px;font-weight:700;border:none;border-radius:12px;cursor:pointer}
.btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#000}
.btn-secondary{background:var(--bg);color:var(--text);border:2px solid var(--border)}
.error-msg{padding:14px;background:rgba(239,68,68,0.15);border-radius:12px;color:#fca5a5;font-size:13px;margin-bottom:18px}
.app{display:flex;flex-direction:column;min-height:100dvh}
.header{position:fixed;top:0;left:0;right:0;height:60px;background:var(--bg);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 16px;z-index:100;padding-top:env(safe-area-inset-top,0)}
.header-left{display:flex;align-items:center;gap:10px}
.header-icon{width:40px;height:40px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px}
.header-title{font-size:18px;font-weight:800}
.header-right{margin-left:auto;display:flex;gap:8px}
.header-btn{width:40px;height:40px;background:var(--card);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;cursor:pointer;color:var(--text)}
.online-toggle{background:var(--danger)}
.online-toggle.on{background:var(--success)}
.main{flex:1;padding:calc(68px + env(safe-area-inset-top,0)) 16px calc(80px + var(--safe))}
.page{display:none;animation:fadeIn .25s}
.page.active{display:block}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:20px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;text-align:center}
.stat-card.highlight{background:linear-gradient(135deg,var(--primary),var(--primary-dark));border:none}
.stat-card.highlight .stat-value,.stat-card.highlight .stat-label{color:#000}
.stat-icon{font-size:24px;margin-bottom:8px}
.stat-value{font-size:24px;font-weight:800}
.stat-label{font-size:11px;color:var(--text2);margin-top:4px}
.section-title{font-size:18px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:10px}
.order-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;margin-bottom:12px;cursor:pointer}
.order-header{display:flex;justify-content:space-between;margin-bottom:12px}
.order-number{font-size:14px;font-weight:700;color:var(--primary)}
.order-time{font-size:12px;color:var(--text2)}
.order-store{font-size:15px;font-weight:600;margin-bottom:8px}
.order-address{font-size:13px;color:var(--text2);margin-bottom:12px}
.order-meta{display:flex;gap:16px;font-size:13px;color:var(--text2)}
.order-footer{display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)}
.order-total{font-size:18px;font-weight:800;color:var(--success)}
.order-fee{font-size:14px;color:var(--primary);font-weight:600}
.accept-btn{padding:10px 20px;background:var(--success);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer}
.empty-state{text-align:center;padding:60px 20px}
.empty-icon{font-size:64px;margin-bottom:16px;opacity:0.5}
.empty-title{font-size:18px;font-weight:700;margin-bottom:8px}
.empty-text{font-size:14px;color:var(--text2)}
.current-order{background:var(--card);border:1px solid var(--border);border-radius:20px;overflow:hidden}
.current-order-header{background:linear-gradient(135deg,var(--primary),var(--primary-dark));padding:20px;color:#000}
.current-order-title{font-size:12px;font-weight:600;opacity:0.8}
.current-order-number{font-size:20px;font-weight:800}
.current-order-status{display:inline-block;padding:6px 12px;background:rgba(0,0,0,0.2);border-radius:20px;font-size:12px;font-weight:600;margin-top:10px}
.order-progress{display:flex;justify-content:space-between;padding:20px;border-bottom:1px solid var(--border)}
.progress-step{text-align:center;flex:1;position:relative}
.progress-step::after{content:'';position:absolute;top:14px;left:50%;width:100%;height:2px;background:var(--border)}
.progress-step:last-child::after{display:none}
.progress-step.done .progress-dot{background:var(--success);border-color:var(--success)}
.progress-step.done::after{background:var(--success)}
.progress-dot{width:28px;height:28px;background:var(--bg);border:2px solid var(--border);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;position:relative;z-index:1}
.progress-label{font-size:10px;color:var(--text2);margin-top:8px}
.order-info{padding:16px}
.info-section{margin-bottom:16px}
.info-title{font-size:12px;color:var(--text2);margin-bottom:8px;font-weight:600}
.info-content{font-size:14px}
.action-buttons{display:flex;gap:10px;padding:16px}
.action-btn{flex:1;padding:14px;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px}
.action-btn.primary{background:var(--primary);color:#000}
.action-btn.success{background:var(--success);color:#fff}
.action-btn.secondary{background:var(--bg);color:var(--text);border:2px solid var(--border)}
.items-section{padding:16px;border-top:1px solid var(--border)}
.items-title{font-size:14px;font-weight:700;margin-bottom:12px}
.item-card{display:flex;gap:12px;padding:12px;background:var(--bg);border-radius:12px;margin-bottom:10px}
.item-image{width:60px;height:60px;background:var(--card);border-radius:10px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.item-image img{width:100%;height:100%;object-fit:cover}
.item-info{flex:1}
.item-name{font-size:14px;font-weight:600;margin-bottom:4px}
.item-qty{font-size:13px;color:var(--text2)}
.item-location{font-size:12px;color:var(--primary);margin-top:6px}
.item-actions{display:flex;gap:6px}
.item-btn{width:36px;height:36px;border:none;border-radius:10px;font-size:16px;cursor:pointer}
.item-btn.found{background:var(--success);color:#fff}
.item-btn.not-found{background:var(--danger);color:#fff}
.item-status{padding:4px 8px;border-radius:6px;font-size:11px;font-weight:600;margin-top:6px;display:inline-block}
.item-status.found{background:rgba(16,185,129,0.2);color:#6ee7b7}
.item-status.not_found{background:rgba(239,68,68,0.2);color:#fca5a5}
.chat-container{display:flex;flex-direction:column;height:calc(100dvh - 150px)}
.chat-header{padding:16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.chat-avatar{width:44px;height:44px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px}
.chat-name{font-size:15px;font-weight:700}
.chat-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px}
.chat-message{max-width:80%;padding:12px 16px;border-radius:16px;font-size:14px}
.chat-message.shopper{background:var(--primary);color:#000;align-self:flex-end}
.chat-message.customer{background:var(--card);align-self:flex-start}
.chat-message.system{background:var(--bg);color:var(--text2);align-self:center;font-size:12px}
.chat-input-bar{padding:12px;border-top:1px solid var(--border);display:flex;gap:10px}
.chat-input{flex:1;padding:12px 16px;background:var(--card);border:1px solid var(--border);border-radius:24px;color:var(--text);font-size:14px}
.chat-send{width:44px;height:44px;background:var(--primary);border:none;border-radius:50%;color:#000;font-size:18px;cursor:pointer}
.verify-modal{position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;visibility:hidden;transition:.3s}
.verify-modal.show{opacity:1;visibility:visible}
.verify-content{background:var(--card);border-radius:24px;padding:32px;text-align:center;width:100%;max-width:360px}
.verify-icon{font-size:48px;margin-bottom:16px}
.verify-title{font-size:20px;font-weight:800;margin-bottom:8px}
.verify-subtitle{font-size:14px;color:var(--text2);margin-bottom:24px}
.verify-input{width:100%;padding:16px;font-size:24px;font-weight:800;text-align:center;letter-spacing:8px;background:var(--bg);border:2px solid var(--border);border-radius:12px;color:var(--text);text-transform:uppercase;margin-bottom:16px}
.verify-buttons{display:flex;gap:10px}
.verify-buttons .btn{flex:1}
.nav{position:fixed;bottom:0;left:0;right:0;height:calc(70px + var(--safe));padding-bottom:var(--safe);background:var(--card);border-top:1px solid var(--border);display:flex;z-index:100}
.nav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;color:var(--text2);cursor:pointer}
.nav-item.active{color:var(--primary)}
.nav-icon{font-size:22px}
.nav-label{font-size:10px;font-weight:600}
.nav-badge{position:absolute;top:6px;right:50%;transform:translateX(18px);min-width:18px;height:18px;background:var(--danger);border-radius:9px;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center}
.toast{position:fixed;bottom:100px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px 20px;z-index:300;opacity:0;transition:.25s}
.toast.show{transform:translateX(-50%) translateY(0);opacity:1}
.toast.success{border-color:var(--success)}
.toast.error{border-color:var(--danger)}
.loading{display:flex;justify-content:center;padding:40px}
.spinner{width:32px;height:32px;border:3px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.profile-header{text-align:center;padding:20px;background:var(--card);border-radius:20px;margin-bottom:20px}
.profile-avatar{width:80px;height:80px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:36px;margin-bottom:12px}
.profile-name{font-size:20px;font-weight:800}
.profile-email{font-size:13px;color:var(--text2);margin-top:4px}
.profile-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border);border-radius:16px;overflow:hidden;margin-bottom:20px}
.profile-stat{background:var(--card);padding:16px;text-align:center}
.profile-stat-value{font-size:20px;font-weight:800;color:var(--primary)}
.profile-stat-label{font-size:11px;color:var(--text2);margin-top:4px}
</style>

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
<?php if (!$loggedIn): ?>
<div class="login"><div class="login-card"><div class="login-header"><div class="login-icon">🚴</div><h1 class="login-title">OneMundo Shopper</h1><p class="login-subtitle">Login com conta do RH</p></div><?php if ($error): ?><div class="error-msg">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?><?php $csrf_token = bin2hex(random_bytes(32)); $_SESSION['csrf_token'] = $csrf_token; ?>
<form method="POST">
<input type="hidden" name="action" value="login">
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

// E verificação no PHP:
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    die('Invalid CSRF token');
}<div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-input" required></div><div class="form-group"><label class="form-label">Senha</label><input type="password" name="password" class="form-input" required></div><button type="submit" class="btn btn-primary">🔐 Entrar</button></form></div></div>
<?php else: ?>
<div class="app">
<header class="header"><div class="header-left"><div class="header-icon">🚴</div><span class="header-title">Shopper</span></div><div class="header-right"><button class="header-btn online-toggle" id="onlineToggle" onclick="toggleOnline()"><span id="onlineIcon">🔴</span></button><form method="POST" style="margin:0"><input type="hidden" name="action" value="logout"><button type="submit" class="header-btn">🚪</button></form></div></header>
<main class="main">
<div class="page active" id="page-home">
<h2 style="font-size:22px;font-weight:800;margin-bottom:6px">Olá, <?= htmlspecialchars($shopperName) ?>! 👋</h2>
<p style="color:var(--text2);font-size:14px;margin-bottom:20px">Pronto para entregas?</p>
<div class="stats-grid">
<div class="stat-card highlight"><div class="stat-icon">💰</div><div class="stat-value" id="statEarnings">R$ 0</div><div class="stat-label">Ganhos Hoje</div></div>
<div class="stat-card"><div class="stat-icon">📦</div><div class="stat-value" id="statDeliveries">0</div><div class="stat-label">Entregas Hoje</div></div>
<div class="stat-card"><div class="stat-icon">⭐</div><div class="stat-value" id="statRating">5.0</div><div class="stat-label">Avaliação</div></div>
<div class="stat-card"><div class="stat-icon">🏆</div><div class="stat-value" id="statTotal">0</div><div class="stat-label">Total</div></div>
</div>
<div id="currentOrderSection" style="display:none"><h3 class="section-title">🔔 Pedido Atual</h3><div id="currentOrderCard"></div></div>
<div id="availableSection"><h3 class="section-title">📋 Disponíveis <span id="pendingCount" style="background:var(--danger);color:#fff;padding:2px 8px;border-radius:10px;font-size:12px">0</span></h3><div id="availableOrders"><div class="loading"><div class="spinner"></div></div></div></div>
</div>
<div class="page" id="page-order"><div id="orderContent"><div class="empty-state"><div class="empty-icon">📦</div><div class="empty-title">Nenhum pedido</div></div></div></div>
<div class="page" id="page-chat"><div id="chatContent"><div class="empty-state"><div class="empty-icon">💬</div><div class="empty-title">Nenhum chat</div></div></div></div>
<div class="page" id="page-history"><h2 class="section-title">📜 Histórico</h2><div id="historyList"><div class="loading"><div class="spinner"></div></div></div></div>
<div class="page" id="page-profile">
<div class="profile-header"><div class="profile-avatar">🚴</div><div class="profile-name"><?= htmlspecialchars($shopper['name'] ?? '') ?></div><div class="profile-email"><?= htmlspecialchars($shopper['email'] ?? '') ?></div></div>
<div class="profile-stats"><div class="profile-stat"><div class="profile-stat-value" id="pDeliveries">0</div><div class="profile-stat-label">Entregas</div></div><div class="profile-stat"><div class="profile-stat-value" id="pEarnings">R$0</div><div class="profile-stat-label">Total</div></div><div class="profile-stat"><div class="profile-stat-value" id="pRating">5.0</div><div class="profile-stat-label">Rating</div></div></div>
<form method="POST"><input type="hidden" name="action" value="logout"><button type="submit" class="btn btn-secondary" style="color:var(--danger)">🚪 Sair</button></form>
</div>
</main>
<nav class="nav">
<div class="nav-item active" data-page="home"><span class="nav-icon">🏠</span><span class="nav-label">Início</span></div>
<div class="nav-item" data-page="order" style="position:relative"><span class="nav-icon">📦</span><span class="nav-label">Pedido</span><span class="nav-badge" id="orderBadge" style="display:none">1</span></div>
<div class="nav-item" data-page="chat"><span class="nav-icon">💬</span><span class="nav-label">Chat</span></div>
<div class="nav-item" data-page="history"><span class="nav-icon">📜</span><span class="nav-label">Histórico</span></div>
<div class="nav-item" data-page="profile"><span class="nav-icon">👤</span><span class="nav-label">Perfil</span></div>
</nav>
</div>
<div class="verify-modal" id="verifyModal"><div class="verify-content"><div class="verify-icon">🔐</div><h3 class="verify-title">Código de Verificação</h3><p class="verify-subtitle">Peça o código ao cliente</p><input type="text" class="verify-input" id="verifyInput" maxlength="6" placeholder="______"><div class="verify-buttons"><button class="btn btn-secondary" onclick="closeVerify()">Cancelar</button><button class="btn btn-primary" onclick="verifyCode()">Verificar</button></div></div></div>
<div class="toast" id="toast"></div>
<?php endif; ?>
<script>
const S={isOnline:<?=$shopper['is_online']??0?>,order:null,page:'home',messages:[]};
document.addEventListener('DOMContentLoaded',()=>{updateOnlineUI();loadStats();loadAvailable();loadCurrent();document.querySelectorAll('.nav-item').forEach(n=>n.addEventListener('click',()=>goTo(n.dataset.page)));// Implementar WebSocket ou polling mais inteligente
let pollInterval = 30000;
let isActive = true;

document.addEventListener('visibilitychange', () => {
    isActive = !document.hidden;
    pollInterval = isActive ? 30000 : 120000;
});

function smartPoll() {
    if (isActive) {
        loadStats();
        if (S.page === 'home') {
            loadAvailable();
            loadCurrent();
        }
    }
    setTimeout(smartPoll, pollInterval);
}
smartPoll();});
function goTo(p){S.page=p;document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));document.querySelector(`[data-page="${p}"]`).classList.add('active');document.querySelectorAll('.page').forEach(pg=>pg.classList.remove('active'));document.getElementById(`page-${p}`).classList.add('active');if(p==='history')loadHistory();if(p==='profile')loadProfile();if(p==='order')renderOrder();if(p==='chat')loadChat()}
async function toggleOnline(){try{const r=await fetch('?api=toggle_online');const d=await r.json();if(d.success){S.isOnline=d.is_online;updateOnlineUI();toast(S.isOnline?'Online! 🟢':'Offline 🔴',S.isOnline?'success':'')}}catch(e){}}
function updateOnlineUI(){const btn=document.getElementById('onlineToggle'),ic=document.getElementById('onlineIcon');if(S.isOnline){btn.classList.add('on');ic.textContent='🟢'}else{btn.classList.remove('on');ic.textContent='🔴'}}
async function loadStats(){try{const r=await fetch('?api=stats');const d=await r.json();if(d.success){const s=d.stats;document.getElementById('statEarnings').textContent=`R$ ${s.today_earnings.toFixed(0)}`;document.getElementById('statDeliveries').textContent=s.today_deliveries;document.getElementById('statRating').textContent=s.rating.toFixed(1);document.getElementById('statTotal').textContent=s.total_deliveries;document.getElementById('pendingCount').textContent=s.pending_orders}}catch(e){}}
async function loadAvailable(){try{const r=await fetch('?api=available_orders');const d=await r.json();const c=document.getElementById('availableOrders');if(d.success&&d.orders.length){c.innerHTML=d.orders.map(o=>`<div class="order-card"><div class="order-header"><span class="order-number">${o.order_number}</span><span class="order-time">${new Date(o.date_added).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})}</span></div><div class="order-store">🏪 ${o.trade_name||o.partner_name}</div><div class="order-address">📍 ${o.delivery_address}, ${o.delivery_number}</div><div class="order-meta"><span>📦 ${o.item_count} itens</span><span>🕐 ${o.delivery_time_start||'--'}</span></div><div class="order-footer"><span class="order-total">R$ ${parseFloat(o.total).toFixed(2)}</span><span class="order-fee">Ganho: R$ ${parseFloat(o.delivery_fee).toFixed(2)}</span><button class="accept-btn" onclick="event.stopPropagation();accept(${o.order_id})">Aceitar</button></div></div>`).join('')}else{c.innerHTML='<div class="empty-state"><div class="empty-icon">📭</div><div class="empty-title">Nenhum pedido</div></div>'}}catch(e){}}
async function accept(id){try{const r=await fetch('?api=accept_order',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id:id})});const d=await r.json();if(d.success){toast('Aceito! 🎉','success');loadCurrent();loadAvailable();goTo('order')}else toast(d.error||'Erro','error')}catch(e){toast('Erro','error')}}
async function loadCurrent(){try{const r=await fetch('?api=current_order');const d=await r.json();if(d.success&&d.order){S.order=d.order;document.getElementById('orderBadge').style.display='flex';document.getElementById('currentOrderSection').style.display='block';document.getElementById('availableSection').style.display='none';renderCurrentCard();renderOrder()}else{S.order=null;document.getElementById('orderBadge').style.display='none';document.getElementById('currentOrderSection').style.display='none';document.getElementById('availableSection').style.display='block'}}catch(e){}}
function renderCurrentCard(){if(!S.order)return;const o=S.order;document.getElementById('currentOrderCard').innerHTML=`<div class="order-card" onclick="goTo('order')"><div class="order-header"><span class="order-number">${o.order_number}</span><span style="background:var(--primary);color:#000;padding:4px 10px;border-radius:10px;font-size:11px">${statusLabel(o.status)}</span></div><div class="order-store">🏪 ${o.trade_name||o.partner_name}</div><div class="order-meta"><span>📦 ${o.items.length} itens</span><span>💰 R$ ${parseFloat(o.total).toFixed(2)}</span></div></div>`}
function renderOrder(){const c=document.getElementById('orderContent');if(!S.order){c.innerHTML='<div class="empty-state"><div class="empty-icon">📦</div><div class="empty-title">Nenhum pedido</div></div>';return}const o=S.order,pr=progress(o.status);let items=o.items.map(i=>`<div class="item-card"><div class="item-image">${i.product_image?`<img src="${i.product_image}">`:'📦'}</div><div class="item-info"><div class="item-name">${i.product_name}</div><div class="item-qty">${i.quantity}x • R$ ${parseFloat(i.unit_price).toFixed(2)}</div>${i.aisle?`<div class="item-location">📍 Corredor ${i.aisle}</div>`:''}${i.status!=='pending'?`<span class="item-status ${i.status}">${itemStatus(i.status)}</span>`:''}</div>${o.status==='shopping'&&i.status==='pending'?`<div class="item-actions"><button class="item-btn found" onclick="markItem(${i.item_id},'found')">✓</button><button class="item-btn not-found" onclick="markItem(${i.item_id},'not_found')">✗</button></div>`:''}</div>`).join('');
c.innerHTML=`<div class="current-order"><div class="current-order-header"><div class="current-order-title">PEDIDO</div><div class="current-order-number">${o.order_number}</div><div class="current-order-status">${statusLabel(o.status)}</div></div><div class="order-progress"><div class="progress-step ${pr>=1?'done':''}"><div class="progress-dot">✓</div><div class="progress-label">Aceito</div></div><div class="progress-step ${pr>=2?'done':''}"><div class="progress-dot">🛒</div><div class="progress-label">Comprando</div></div><div class="progress-step ${pr>=3?'done':''}"><div class="progress-dot">🚴</div><div class="progress-label">Entregando</div></div><div class="progress-step ${pr>=4?'done':''}"><div class="progress-dot">✓</div><div class="progress-label">Entregue</div></div></div><div class="order-info"><div class="info-section"><div class="info-title">🏪 Mercado</div><div class="info-content">${o.trade_name||o.partner_name}<br>${o.partner_address} - ${o.partner_city}</div></div><div class="info-section"><div class="info-title">📦 Entrega</div><div class="info-content">${o.customer_name} • ${o.customer_phone}<br>${o.delivery_address}, ${o.delivery_number}<br>${o.delivery_neighborhood} - ${o.delivery_city}</div></div></div><div class="action-buttons">${o.status==='confirmed'?'<button class="action-btn primary" onclick="updateStatus(\'shopping\')">🛒 Iniciar Compras</button>':''}${o.status==='shopping'?'<button class="action-btn success" onclick="updateStatus(\'delivering\')">✓ Finalizar Compras</button>':''}${o.status==='delivering'?'<button class="action-btn secondary" onclick="openMaps()">🗺️ Navegar</button><button class="action-btn success" onclick="openVerify()">✓ Entregar</button>':''}</div><div class="items-section"><div class="items-title">Lista (${o.items.length} itens)</div>${items}</div></div>`}
function progress(s){return{confirmed:1,shopping:2,delivering:3,delivered:4}[s]||0}
function statusLabel(s){return{confirmed:'Confirmado',shopping:'Comprando',delivering:'Entregando',delivered:'Entregue'}[s]||s}
function itemStatus(s){return{found:'✓ Encontrado',not_found:'✗ Não encontrado'}[s]||s}
async function updateStatus(s){if(!S.order)return;try{const r=await fetch('?api=update_status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id:S.order.order_id,status:s})});const d=await r.json();if(d.success){toast('Atualizado! ✓','success');loadCurrent()}else toast('Erro','error')}catch(e){toast('Erro','error')}}
async function markItem(id,s){try{await fetch('?api=update_item',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({item_id:id,status:s})});toast(s==='found'?'Encontrado ✓':'Não encontrado',s==='found'?'success':'error');loadCurrent()}catch(e){}}
function openVerify(){document.getElementById('verifyModal').classList.add('show');document.getElementById('verifyInput').value='';document.getElementById('verifyInput').focus()}
function closeVerify(){document.getElementById('verifyModal').classList.remove('show')}
async function verifyCode(){const code=document.getElementById('verifyInput').value.trim().toUpperCase();if(code.length!==6){toast('Digite 6 dígitos','error');return}try{const r=await fetch('?api=verify_code',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id:S.order.order_id,code})});const d=await r.json();if(d.success&&d.valid){closeVerify();updateStatus('delivered')}else toast('Código incorreto','error')}catch(e){toast('Erro','error')}}
function openMaps(){if(!S.order)return;const o=S.order;window.open(`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(o.delivery_address+','+o.delivery_number+','+o.delivery_city)}`,'_blank')}
async function loadChat(){const c=document.getElementById('chatContent');if(!S.order){c.innerHTML='<div class="empty-state"><div class="empty-icon">💬</div><div class="empty-title">Nenhum chat</div></div>';return}const o=S.order;c.innerHTML=`<div class="chat-container"><div class="chat-header"><div class="chat-avatar">👤</div><div><div class="chat-name">${o.customer_name}</div></div></div><div class="chat-messages" id="chatMsgs"></div><div class="chat-input-bar"><input type="text" class="chat-input" id="chatInput" placeholder="Mensagem..."><button class="chat-send" onclick="sendMsg()">➤</button></div></div>`;document.getElementById('chatInput').addEventListener('keypress',e=>{if(e.key==='Enter')sendMsg()});fetchMsgs()}
async function fetchMsgs(){if(!S.order)return;try{const r=await fetch(`?api=chat_messages&order_id=${S.order.order_id}`);const d=await r.json();if(d.success){S.messages=d.messages;renderMsgs()}}catch(e){}}
function renderMsgs(){const c=document.getElementById('chatMsgs');if(!c)return;c.innerHTML=S.messages.map(m=>`<div class="chat-message ${m.sender_type}">${m.message}</div>`).join('');c.scrollTop=c.scrollHeight}
async function sendMsg(){const inp=document.getElementById('chatInput'),msg=inp.value.trim();if(!msg||!S.order)return;inp.value='';try{await fetch('?api=chat_send',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id:S.order.order_id,message:msg})});fetchMsgs()}catch(e){}}
async function loadHistory(){try{const r=await fetch('?api=history');const d=await r.json();const c=document.getElementById('historyList');if(d.success&&d.orders.length){c.innerHTML=d.orders.map(o=>`<div class="order-card"><div class="order-header"><span class="order-number">${o.order_number}</span><span class="order-time">${new Date(o.date_delivered).toLocaleDateString('pt-BR')}</span></div><div class="order-store">🏪 ${o.partner_name}</div><div class="order-footer"><span class="order-total">R$ ${parseFloat(o.total).toFixed(2)}</span><span class="order-fee">+R$ ${parseFloat(o.delivery_fee).toFixed(2)}</span></div></div>`).join('')}else c.innerHTML='<div class="empty-state"><div class="empty-icon">📜</div><div class="empty-title">Sem histórico</div></div>'}catch(e){}}
async function loadProfile(){try{const r=await fetch('?api=stats');const d=await r.json();if(d.success){const s=d.stats;document.getElementById('pDeliveries').textContent=s.total_deliveries;document.getElementById('pEarnings').textContent=`R$${s.total_earnings.toFixed(0)}`;document.getElementById('pRating').textContent=s.rating.toFixed(1)}}catch(e){}}
function toast(msg,type=''){const t=document.getElementById('toast');t.textContent=msg;t.className=`toast ${type} show`;setTimeout(()=>t.classList.remove('show'),3000)}
</script>

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
</body>
</html>
