<?php
/**
 * POST /api/mercado/subscription/subscribe.php
 * Subscribe customer to a box.
 * Body: { box_id, frequency, delivery_day, address_id, payment_method }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

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

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $boxId = (int)($input['box_id'] ?? 0);
    $frequency = strtolower(trim($input['frequency'] ?? 'weekly'));
    $deliveryDay = (int)($input['delivery_day'] ?? 1);
    $addressId = isset($input['address_id']) ? (int)$input['address_id'] : null;
    $paymentMethod = trim($input['payment_method'] ?? 'credit_card');

    if (!in_array($frequency, ['weekly', 'biweekly', 'monthly'], true)) {
        response(false, null, 'Frequencia invalida', 400);
    }
    if ($deliveryDay < 0 || $deliveryDay > 6) {
        response(false, null, 'delivery_day deve estar entre 0 e 6', 400);
    }

    // Verify box exists
    $stmt = $db->prepare("SELECT id, name, base_price FROM om_subscription_boxes WHERE id = :id AND is_active = true");
    $stmt->execute([':id' => $boxId]);
    $box = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$box) {
        response(false, null, 'Cesta nao encontrada', 404);
    }

    // Calculate next delivery date (next occurrence of delivery_day)
    $today = new DateTime();
    $todayDow = (int)$today->format('w'); // 0=Sun ... 6=Sat
    $daysAhead = ($deliveryDay - $todayDow + 7) % 7;
    if ($daysAhead === 0) $daysAhead = 7;
    $next = (clone $today)->modify("+{$daysAhead} days")->format('Y-m-d');

    // Insert subscription
    $stmt = $db->prepare(
        "INSERT INTO om_box_subscriptions
         (customer_id, box_id, frequency, delivery_day, address_id, payment_method, status, next_delivery)
         VALUES (:cid, :bid, :freq, :day, :addr, :pm, 'active', :nd)
         RETURNING id"
    );
    $stmt->execute([
        ':cid' => $customerId,
        ':bid' => $boxId,
        ':freq' => $frequency,
        ':day' => $deliveryDay,
        ':addr' => $addressId,
        ':pm' => $paymentMethod,
        ':nd' => $next,
    ]);
    $subId = (int)$stmt->fetchColumn();

    response(true, [
        'subscription_id' => $subId,
        'box_name' => $box['name'],
        'price' => (float)$box['base_price'],
        'next_delivery' => $next,
        'frequency' => $frequency,
    ]);
} catch (Exception $e) {
    error_log('[subscribe] ' . $e->getMessage());
    response(false, null, 'Erro ao assinar cesta', 500);
}
