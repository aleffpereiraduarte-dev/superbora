<?php
/**
 * GET  /api/mercado/subscription/my-subscriptions.php  - List active/paused subscriptions
 * POST /api/mercado/subscription/my-subscriptions.php  - Pause/resume/cancel a subscription
 *      Body: { subscription_id, action: "pause"|"resume"|"cancel" }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $customerId = 0;
    $token = om_auth()->getTokenFromRequest();
    if ($token) {
        $payload = om_auth()->validateToken($token);
        if ($payload && ($payload['type'] ?? '') === 'customer') {
            $customerId = (int)$payload['uid'];
        }
    }
    if (!$customerId) {
        response(false, null, 'Nao autorizado', 401);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $stmt = $db->prepare(
            "SELECT s.id, s.box_id, s.frequency, s.delivery_day, s.status,
                    s.next_delivery, s.created_at,
                    b.name AS box_name, b.description AS box_description,
                    b.image AS box_image, b.base_price, b.sample_items
             FROM om_box_subscriptions s
             JOIN om_subscription_boxes b ON s.box_id = b.id
             WHERE s.customer_id = :cid
             ORDER BY (s.status = 'active') DESC, s.next_delivery ASC NULLS LAST"
        );
        $stmt->execute([':cid' => $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['base_price'] = (float)$r['base_price'];
            if (is_string($r['sample_items'])) {
                $r['sample_items'] = json_decode($r['sample_items'], true) ?: [];
            }
        }
        response(true, ['subscriptions' => $rows]);
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $subId = (int)($input['subscription_id'] ?? 0);
        $action = strtolower(trim($input['action'] ?? ''));

        if (!in_array($action, ['pause', 'resume', 'cancel'], true)) {
            response(false, null, 'Acao invalida', 400);
        }

        // Verify ownership
        $stmt = $db->prepare("SELECT id, status FROM om_box_subscriptions WHERE id = :id AND customer_id = :cid");
        $stmt->execute([':id' => $subId, ':cid' => $customerId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sub) {
            response(false, null, 'Assinatura nao encontrada', 404);
        }

        $newStatus = $action === 'pause' ? 'paused' : ($action === 'cancel' ? 'cancelled' : 'active');
        $cancelledAt = $action === 'cancel' ? 'NOW()' : 'NULL';

        $sql = "UPDATE om_box_subscriptions SET status = :st, cancelled_at = " . $cancelledAt . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':st' => $newStatus, ':id' => $subId]);

        response(true, ['status' => $newStatus]);
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[my-subs] ' . $e->getMessage());
    response(false, null, 'Erro ao processar', 500);
}
