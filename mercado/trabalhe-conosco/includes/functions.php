<?php
function isLoggedIn() { return isset($_SESSION['worker_id']) && $_SESSION['worker_id'] > 0; }
function requireLogin() { if (!isLoggedIn()) { header('Location: login.php'); exit; } }
function getWorkerId() { return $_SESSION['worker_id'] ?? 0; }

function getWorker($id = null) {
    $id = $id ?? getWorkerId();
    if (!$id) return null;
    $stmt = getDB()->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getBalance($wid) {
    $stmt = getDB()->prepare("SELECT balance FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$wid]);
    return (float)($stmt->fetchColumn() ?: 0);
}

function getPendingBalance($wid) {
    $stmt = getDB()->prepare("SELECT COALESCE(SUM(shopper_earnings),0) FROM om_market_orders WHERE shopper_id = ? AND status IN ('shopping','ready_for_delivery','delivering')");
    $stmt->execute([$wid]);
    return (float)$stmt->fetchColumn();
}

function getEarningsToday($wid) {
    $stmt = getDB()->prepare("SELECT COALESCE(SUM(amount),0) FROM om_market_wallet_transactions WHERE worker_id = ? AND type = 'earning' AND DATE(created_at) = CURRENT_DATE");
    $stmt->execute([$wid]);
    return (float)$stmt->fetchColumn();
}

function getEarningsWeek($wid) {
    $stmt = getDB()->prepare("SELECT COALESCE(SUM(amount),0) FROM om_market_wallet_transactions WHERE worker_id = ? AND type = 'earning' AND created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)");
    $stmt->execute([$wid]);
    return (float)$stmt->fetchColumn();
}

function getOrdersToday($wid) {
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM om_market_orders WHERE shopper_id = ? AND status = 'completed' AND DATE(completed_at) = CURRENT_DATE");
    $stmt->execute([$wid]);
    return (int)$stmt->fetchColumn();
}

function getOrdersWeek($wid) {
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM om_market_orders WHERE shopper_id = ? AND status = 'completed' AND completed_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)");
    $stmt->execute([$wid]);
    return (int)$stmt->fetchColumn();
}

function getAvailableOffers($wid) {
    $stmt = getDB()->prepare("
        SELECT o.*, p.name as partner_name, p.logo as partner_logo, p.address as partner_address,
               (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as item_count,
               so.shopper_earning
        FROM om_shopper_offers so
        JOIN om_market_orders o ON so.order_id = o.order_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE so.shopper_id = ? AND so.status = 'pending'
        ORDER BY so.created_at DESC
    ");
    $stmt->execute([$wid]);
    return $stmt->fetchAll();
}

function getActiveOrder($wid) {
    $stmt = getDB()->prepare("
        SELECT o.*, p.name as partner_name, p.logo as partner_logo, p.address as partner_address,
               (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as item_count
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.shopper_id = ? AND o.status IN ('shopping','ready_for_delivery','delivering')
        LIMIT 1
    ");
    $stmt->execute([$wid]);
    return $stmt->fetch();
}

function acceptOffer($wid, $orderId) {
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM om_shopper_offers WHERE shopper_id = ? AND order_id = ? AND status = 'pending'");
        $stmt->execute([$wid, $orderId]);
        $offer = $stmt->fetch();
        if (!$offer) throw new Exception('Oferta não disponível');
        
        $pdo->prepare("UPDATE om_shopper_offers SET status = 'accepted', accepted_at = NOW() WHERE offer_id = ?")->execute([$offer['offer_id']]);
        $pdo->prepare("UPDATE om_shopper_offers SET status = 'rejected' WHERE order_id = ? AND shopper_id != ?")->execute([$orderId, $wid]);
        $pdo->prepare("UPDATE om_market_orders SET shopper_id = ?, status = 'shopping', shopping_started_at = NOW() WHERE order_id = ?")->execute([$wid, $orderId]);
        
        $pdo->commit();
        return ['success' => true, 'order_id' => $orderId];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function setOnlineStatus($wid, $online) {
    getDB()->prepare("UPDATE om_market_workers SET is_online = ?, last_online = NOW() WHERE worker_id = ?")->execute([$online ? 1 : 0, $wid]);
    return ['success' => true];
}

function formatMoney($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function sanitize($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
// ═══════════════════════════════════════════════════════════════════════════════
// NOTIFICATION HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Criar notificação para worker
 */
function createWorkerNotification($workerId, $title, $message, $type = 'info', $data = []) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO om_worker_notifications 
        (worker_id, title, message, type, data, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([$workerId, $title, $message, $type, json_encode($data)]);
}

/**
 * Enviar push notification (placeholder - integrar com OneSignal/Firebase)
 */
function sendPushNotification($workerId, $title, $body, $data = []) {
    // TODO: Integrar com serviço de push (OneSignal, Firebase, etc)
    // Por enquanto apenas cria notificação no banco
    createWorkerNotification($workerId, $title, $body, 'push', $data);
    return true;
}

/**
 * Notificar nova oferta
 */
function notifyNewOffer($workerId, $orderId, $earning) {
    $title = "Nova oferta de pedido!";
    $message = "Ganhe R$ " . number_format($earning, 2, ',', '.') . " - Aceite agora!";
    return sendPushNotification($workerId, $title, $message, [
        'type' => 'new_offer',
        'order_id' => $orderId,
        'earning' => $earning
    ]);
}

/**
 * Obter notificações não lidas
 */
function getUnreadNotifications($workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT * FROM om_worker_notifications 
        WHERE worker_id = ? AND read_at IS NULL 
        ORDER BY created_at DESC LIMIT 50
    ");
    $stmt->execute([$workerId]);
    return $stmt->fetchAll();
}

/**
 * Marcar notificação como lida
 */
function markNotificationRead($notificationId, $workerId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE om_worker_notifications SET read_at = NOW() WHERE notification_id = ? AND worker_id = ?");
    return $stmt->execute([$notificationId, $workerId]);
}