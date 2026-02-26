<?php
/**
 * API: Entregador cancela entrega aceita
 * POST /mercado/api/entregador/cancelar.php
 *
 * Body: { order_id, driver_id, motivo }
 * motivo: "muito_longe", "problema_veiculo", "emergencia", "outro"
 *
 * - Remove driver assignment
 * - Applies penalty (-10 score)
 * - Triggers auto-reassign via new dispatch offer
 * - Notifies customer
 */
require_once __DIR__ . '/config.php';

// Only POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Metodo nao permitido'], 405);
}

$input = getInput();
$order_id  = (int)($input['order_id']  ?? 0);
$driver_id = (int)($input['driver_id'] ?? 0);
$motivo    = $input['motivo'] ?? 'outro';

// Validate motivo
$motivos_validos = ['muito_longe', 'problema_veiculo', 'emergencia', 'outro'];
if (!in_array($motivo, $motivos_validos)) {
    $motivo = 'outro';
}

if (!$order_id || !$driver_id) {
    jsonResponse(['success' => false, 'error' => 'order_id e driver_id obrigatorios'], 400);
}

$pdo = getDB();

// Validate driver exists
$driver = validateDriver($driver_id);
if (!$driver) {
    jsonResponse(['success' => false, 'error' => 'Motorista nao encontrado'], 404);
}

// Fetch order
$stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    jsonResponse(['success' => false, 'error' => 'Pedido nao encontrado'], 404);
}

// Verify this driver is assigned to this order
if (!$order['delivery_driver_id'] || (int)$order['delivery_driver_id'] !== $driver_id) {
    jsonResponse(['success' => false, 'error' => 'Voce nao e o entregador deste pedido'], 403);
}

// Cannot cancel if already delivered or completed
$uncancellable = ['delivered', 'completed', 'cancelled', 'refunded'];
if (in_array($order['status'], $uncancellable)) {
    jsonResponse(['success' => false, 'error' => 'Pedido ja finalizado, nao pode cancelar entrega'], 400);
}

// ══════════════════════════════════════════════════════════════════════════════
// PENALTY CONFIG
// ══════════════════════════════════════════════════════════════════════════════
$penalty_points = 10;
try {
    $config = $pdo->query("SELECT config_value FROM om_dispatch_config WHERE config_key = 'penalty_desistencia'")->fetchColumn();
    if ($config) $penalty_points = (int)$config;
} catch (Exception $e) {}

// Map motivo to description
$motivo_desc = [
    'muito_longe'      => 'Destino muito longe',
    'problema_veiculo' => 'Problema com veiculo',
    'emergencia'       => 'Emergencia pessoal',
    'outro'            => 'Outro motivo'
];
$description = $motivo_desc[$motivo] ?? 'Outro motivo';

// ══════════════════════════════════════════════════════════════════════════════
// BEGIN TRANSACTION
// ══════════════════════════════════════════════════════════════════════════════
$pdo->beginTransaction();
try {

    // Track reassign count
    $reassign_count = (int)($order['reassign_count'] ?? 0) + 1;

    // 1. Remove driver from order, set status back to awaiting delivery
    $new_status = 'awaiting_delivery';
    // If the order was already picked up (has delivery_picked_at), use pronto_coleta
    if (!empty($order['delivery_picked_at'])) {
        $new_status = 'pronto_coleta';
    }

    $stmt = $pdo->prepare("
        UPDATE om_market_orders SET
            delivery_driver_id = NULL,
            delivery_name = NULL,
            driver_dispatch_at = NULL,
            driver_arrived_at = NULL,
            delivery_accepted_at = NULL,
            wait_fee = 0,
            status = ?,
            reassign_count = ?
        WHERE order_id = ?
    ");
    $stmt->execute([$new_status, $reassign_count, $order_id]);

    // 2. Set driver as available again
    $pdo->prepare("UPDATE om_boraum_drivers SET is_available = 1 WHERE driver_id = ?")->execute([$driver_id]);

    // 3. Apply penalty: reduce score_interno
    $pdo->prepare("
        UPDATE om_market_deliveries
        SET score_interno = GREATEST(0, COALESCE(score_interno, 100) - ?),
            total_desistencias = COALESCE(total_desistencias, 0) + 1
        WHERE delivery_id = ?
    ")->execute([$penalty_points, $driver_id]);

    // 4. Log penalty in om_driver_penalties
    $pdo->prepare("
        INSERT INTO om_driver_penalties (driver_id, order_id, reason, points_lost, description, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ")->execute([$driver_id, $order_id, $motivo, $penalty_points, 'Cancelamento de entrega: ' . $description]);

    // 5. Cancel existing offer for this driver
    $pdo->prepare("
        UPDATE om_delivery_offers
        SET status = 'cancelled', cancelled_reason = 'driver_cancelou'
        WHERE order_id = ? AND accepted_by = ?
    ")->execute([$order_id, $driver_id]);

    // Also cancel from om_market_driver_offers if exists
    try {
        $pdo->prepare("
            UPDATE om_market_driver_offers
            SET status = 'cancelled'
            WHERE order_id = ? AND driver_id = ? AND status = 'accepted'
        ")->execute([$order_id, $driver_id]);
    } catch (Exception $e) {}

    // 6. Record in order history
    try {
        $pdo->prepare("
            INSERT INTO om_market_order_history (order_id, status, comment, created_at)
            VALUES (?, ?, ?, NOW())
        ")->execute([
            $order_id,
            'driver_cancelled',
            'Entregador ' . ($driver['name'] ?? '#' . $driver_id) . ' cancelou: ' . $description . ' (reassign #' . $reassign_count . ')'
        ]);
    } catch (Exception $e) {}

    // ══════════════════════════════════════════════════════════════════════════
    // 7. AUTO-REASSIGN: Create new delivery offer for cron wave dispatch
    // ══════════════════════════════════════════════════════════════════════════
    $partner_id = (int)($order['market_id'] ?? $order['partner_id'] ?? 0);

    // Calculate delivery earning
    $delivery_earning = 8.90; // base
    try {
        // Try to get store coordinates for distance calc
        $store_stmt = $pdo->prepare("SELECT latitude, longitude FROM om_market_partners WHERE partner_id = ?");
        $store_stmt->execute([$partner_id]);
        $store = $store_stmt->fetch();

        $dest_lat = (float)($order['shipping_lat'] ?? $order['shipping_latitude'] ?? 0);
        $dest_lng = (float)($order['shipping_lng'] ?? $order['shipping_longitude'] ?? 0);

        if ($store && $store['latitude'] && $dest_lat) {
            $R = 6371;
            $dLat = deg2rad($dest_lat - $store['latitude']);
            $dLon = deg2rad($dest_lng - $store['longitude']);
            $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($store['latitude'])) * cos(deg2rad($dest_lat)) * sin($dLon/2) * sin($dLon/2);
            $dist_km = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
            $delivery_earning = 8.90 + ($dist_km * 1.50);
        }
    } catch (Exception $e) {}

    // Insert new pending offer for the wave cron to pick up
    $pdo->prepare("
        INSERT INTO om_delivery_offers
        (order_id, partner_id, delivery_earning, status, current_wave, wave_started_at, expires_at, created_at)
        VALUES (?, ?, ?, 'pending', 1, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW())
    ")->execute([$order_id, $partner_id, round($delivery_earning, 2)]);

    $new_offer_id = $pdo->lastInsertId();

    // Log the dispatch trigger
    try {
        $pdo->prepare("
            INSERT INTO om_dispatch_log (order_id, action, details, created_at)
            VALUES (?, 'reassign_triggered', ?, NOW())
        ")->execute([
            $order_id,
            json_encode([
                'reason' => 'driver_cancelled',
                'old_driver_id' => $driver_id,
                'motivo' => $motivo,
                'reassign_count' => $reassign_count,
                'new_offer_id' => $new_offer_id
            ])
        ]);
    } catch (Exception $e) {}

    $pdo->commit();

    // ══════════════════════════════════════════════════════════════════════════
    // 8. NOTIFICATIONS (outside transaction)
    // ══════════════════════════════════════════════════════════════════════════

    // Notify customer: searching for new driver
    $customer_id = (int)($order['customer_id'] ?? 0);
    if ($customer_id) {
        try {
            // Use sendNotification from config/notify.php
            $notify_path = dirname(__DIR__, 2) . '/api/mercado/config/notify.php';
            if (file_exists($notify_path)) {
                require_once $notify_path;
                // Re-get PDO since notify may use different connection
                sendNotification($pdo, $customer_id, 'customer',
                    'Buscando novo entregador',
                    'Estamos buscando outro entregador para seu pedido #' . $order_id . '. Voce sera notificado assim que um novo entregador aceitar.',
                    ['type' => 'driver_reassign', 'order_id' => $order_id, 'reassign_count' => $reassign_count]
                );
            } else {
                // Fallback: insert notification directly
                $pdo->prepare("
                    INSERT INTO om_market_notifications (user_id, user_type, title, body, data, is_read, created_at)
                    VALUES (?, 'customer', ?, ?, ?, 0, NOW())
                ")->execute([
                    $customer_id,
                    'Buscando novo entregador',
                    'Estamos buscando outro entregador para seu pedido #' . $order_id . '. Voce sera notificado assim que um novo entregador aceitar.',
                    json_encode(['type' => 'driver_reassign', 'order_id' => $order_id, 'reassign_count' => $reassign_count])
                ]);
            }
        } catch (Exception $e) {
            error_log("[cancelar] Erro ao notificar cliente: " . $e->getMessage());
        }
    }

    // Push notification queue for customer
    try {
        $pdo->prepare("
            INSERT INTO om_notifications_queue (user_type, user_id, title, body, icon, data, priority, sound)
            VALUES ('customer', ?, 'Buscando novo entregador', ?, '🔄', ?, 'high', 'alert')
        ")->execute([
            $customer_id,
            'Estamos buscando outro entregador para seu pedido.',
            json_encode(['type' => 'driver_reassign', 'order_id' => $order_id])
        ]);
    } catch (Exception $e) {}

    // Chat system message
    try {
        $pdo->prepare("
            INSERT INTO om_order_chat (order_id, sender_type, sender_id, message)
            VALUES (?, 'system', 0, ?)
        ")->execute([
            $order_id,
            'O entregador cancelou a entrega. Estamos buscando um novo entregador para voce.'
        ]);
    } catch (Exception $e) {}

    // Get new score
    $new_score = 100;
    try {
        $stmt = $pdo->prepare("SELECT score_interno FROM om_market_deliveries WHERE delivery_id = ?");
        $stmt->execute([$driver_id]);
        $score = $stmt->fetchColumn();
        if ($score !== false) $new_score = (int)$score;
    } catch (Exception $e) {}

    jsonResponse([
        'success'         => true,
        'message'         => 'Entrega cancelada. Penalidade aplicada.',
        'order_id'        => $order_id,
        'new_status'      => $new_status,
        'penalty_points'  => $penalty_points,
        'new_score'       => $new_score,
        'reassign_count'  => $reassign_count,
        'new_offer_id'    => (int)$new_offer_id,
        'auto_reassign'   => true
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Erro ao cancelar entrega: ' . $e->getMessage()], 500);
}
