<?php
/**
 * API: Cliente solicita troca de entregador (Uber-like)
 * POST /mercado/api/entregador/trocar.php
 *
 * Body: { order_id, motivo }
 * motivo: "demora", "muito_longe", "outro"
 *
 * Auth: Customer token (Bearer)
 *
 * - Only allowed when status = "delivering" and driver_arrived_at IS NULL
 * - Rate limit: max 2 driver changes per order
 * - NO penalty to old driver (customer-initiated)
 * - Triggers new dispatch wave
 * - Notifies old driver and customer
 */

// CORS and headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo nao permitido']);
    exit;
}

// Load OmAuth for customer authentication
$oc_root = dirname(dirname(dirname(__DIR__)));
require_once($oc_root . '/config.php');
require_once __DIR__ . '/config.php'; // getDB, jsonResponse, getInput

// Load auth classes
require_once dirname(__DIR__, 2) . '/../includes/classes/OmAuth.php';

$pdo = getDB();
OmAuth::getInstance()->setDb($pdo);

// ══════════════════════════════════════════════════════════════════════════════
// AUTHENTICATE CUSTOMER
// ══════════════════════════════════════════════════════════════════════════════
$token = om_auth()->getTokenFromRequest();
if (!$token) {
    jsonResponse(['success' => false, 'error' => 'Autenticacao necessaria'], 401);
}

$payload = om_auth()->validateToken($token);
if (!$payload) {
    jsonResponse(['success' => false, 'error' => 'Token invalido ou expirado'], 401);
}

// Allow customer type
if ($payload['type'] !== 'customer') {
    jsonResponse(['success' => false, 'error' => 'Apenas clientes podem solicitar troca de entregador'], 403);
}

$customer_id = (int)$payload['uid'];

// ══════════════════════════════════════════════════════════════════════════════
// INPUT VALIDATION
// ══════════════════════════════════════════════════════════════════════════════
$input = getInput();
$order_id = (int)($input['order_id'] ?? 0);
$motivo   = $input['motivo'] ?? 'outro';

$motivos_validos = ['demora', 'muito_longe', 'outro'];
if (!in_array($motivo, $motivos_validos)) {
    $motivo = 'outro';
}

if (!$order_id) {
    jsonResponse(['success' => false, 'error' => 'order_id obrigatorio'], 400);
}

// ══════════════════════════════════════════════════════════════════════════════
// FETCH ORDER
// ══════════════════════════════════════════════════════════════════════════════
$stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    jsonResponse(['success' => false, 'error' => 'Pedido nao encontrado'], 404);
}

// Verify this customer owns the order
if ((int)($order['customer_id'] ?? 0) !== $customer_id) {
    jsonResponse(['success' => false, 'error' => 'Este pedido nao pertence a voce'], 403);
}

// Must be in delivering status
if ($order['status'] !== 'delivering') {
    jsonResponse(['success' => false, 'error' => 'Troca so e permitida durante a entrega (status: delivering)'], 400);
}

// Driver must not have arrived yet
if (!empty($order['driver_arrived_at'])) {
    jsonResponse(['success' => false, 'error' => 'O entregador ja chegou ao destino. Troca nao permitida.'], 400);
}

// Must have a driver assigned
$old_driver_id = (int)($order['delivery_driver_id'] ?? 0);
if (!$old_driver_id) {
    jsonResponse(['success' => false, 'error' => 'Nenhum entregador atribuido a este pedido'], 400);
}

// Rate limit: max 2 driver changes per order
$reassign_count = (int)($order['reassign_count'] ?? 0);
$max_changes = 2;
if ($reassign_count >= $max_changes) {
    jsonResponse([
        'success' => false,
        'error' => 'Limite de trocas atingido. Maximo de ' . $max_changes . ' trocas por pedido.',
        'reassign_count' => $reassign_count,
        'max_changes' => $max_changes
    ], 429);
}

// ══════════════════════════════════════════════════════════════════════════════
// FETCH OLD DRIVER INFO
// ══════════════════════════════════════════════════════════════════════════════
$old_driver = validateDriver($old_driver_id);
$old_driver_name = $old_driver['name'] ?? 'Entregador #' . $old_driver_id;

// Map motivo
$motivo_desc = [
    'demora'      => 'Demora no atendimento',
    'muito_longe' => 'Entregador muito longe',
    'outro'       => 'Outro motivo'
];
$description = $motivo_desc[$motivo] ?? 'Outro motivo';

// ══════════════════════════════════════════════════════════════════════════════
// BEGIN TRANSACTION
// ══════════════════════════════════════════════════════════════════════════════
$pdo->beginTransaction();
try {

    $new_reassign_count = $reassign_count + 1;

    // 1. Remove driver from order, set status back to awaiting_delivery
    $stmt = $pdo->prepare("
        UPDATE om_market_orders SET
            delivery_driver_id = NULL,
            delivery_name = NULL,
            driver_dispatch_at = NULL,
            driver_arrived_at = NULL,
            delivery_accepted_at = NULL,
            wait_fee = 0,
            status = 'awaiting_delivery',
            reassign_count = ?
        WHERE order_id = ?
    ");
    $stmt->execute([$new_reassign_count, $order_id]);

    // 2. Set old driver as available (NO penalty - customer initiated)
    $pdo->prepare("UPDATE om_boraum_drivers SET is_available = 1 WHERE driver_id = ?")->execute([$old_driver_id]);

    // 3. Cancel existing offers for this driver
    try {
        $pdo->prepare("
            UPDATE om_delivery_offers
            SET status = 'cancelled', cancelled_reason = 'cliente_trocou'
            WHERE order_id = ? AND accepted_by = ?
        ")->execute([$order_id, $old_driver_id]);
    } catch (Exception $e) {}

    try {
        $pdo->prepare("
            UPDATE om_market_driver_offers
            SET status = 'cancelled'
            WHERE order_id = ? AND driver_id = ? AND status = 'accepted'
        ")->execute([$order_id, $old_driver_id]);
    } catch (Exception $e) {}

    // 4. Record in order history
    try {
        $pdo->prepare("
            INSERT INTO om_market_order_history (order_id, status, comment, created_at)
            VALUES (?, ?, ?, NOW())
        ")->execute([
            $order_id,
            'driver_swapped',
            'Cliente solicitou troca de entregador (' . $old_driver_name . '): ' . $description . ' (troca #' . $new_reassign_count . ')'
        ]);
    } catch (Exception $e) {}

    // ══════════════════════════════════════════════════════════════════════════
    // 5. TRIGGER NEW DISPATCH: Insert new offer for wave cron
    // ══════════════════════════════════════════════════════════════════════════
    $partner_id = (int)($order['market_id'] ?? $order['partner_id'] ?? 0);

    // Calculate delivery earning
    $delivery_earning = 8.90;
    try {
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

    // Create new pending offer for wave cron to process
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
                'reason' => 'customer_swap',
                'old_driver_id' => $old_driver_id,
                'motivo' => $motivo,
                'reassign_count' => $new_reassign_count,
                'new_offer_id' => $new_offer_id
            ])
        ]);
    } catch (Exception $e) {}

    $pdo->commit();

    // ══════════════════════════════════════════════════════════════════════════
    // 6. NOTIFICATIONS (outside transaction)
    // ══════════════════════════════════════════════════════════════════════════

    // Load notification helper
    $notify_path = dirname(__DIR__, 2) . '/api/mercado/config/notify.php';
    $notify_loaded = false;
    if (file_exists($notify_path)) {
        require_once $notify_path;
        $notify_loaded = true;
    }

    // Notify old driver: customer requested change
    try {
        if ($notify_loaded) {
            sendNotification($pdo, $old_driver_id, 'motorista',
                'Troca de entregador',
                'O cliente solicitou troca de entregador para o pedido #' . $order_id . '. Voce esta disponivel para novas entregas.',
                ['type' => 'driver_swap_out', 'order_id' => $order_id]
            );
        }
        // Also push queue
        $pdo->prepare("
            INSERT INTO om_notifications_queue (user_type, user_id, title, body, icon, data, priority, sound)
            VALUES ('delivery', ?, 'Troca de entregador', ?, '🔄', ?, 'high', 'alert')
        ")->execute([
            $old_driver_id,
            'O cliente solicitou troca de entregador para o pedido #' . $order_id,
            json_encode(['type' => 'driver_swap_out', 'order_id' => $order_id])
        ]);
    } catch (Exception $e) {
        error_log("[trocar] Erro ao notificar driver antigo: " . $e->getMessage());
    }

    // Notify customer: searching for new driver
    try {
        if ($notify_loaded) {
            sendNotification($pdo, $customer_id, 'customer',
                'Buscando novo entregador',
                'Estamos buscando um novo entregador para seu pedido #' . $order_id . '. Voce sera notificado quando um novo entregador aceitar.',
                ['type' => 'driver_swap_searching', 'order_id' => $order_id, 'reassign_count' => $new_reassign_count]
            );
        }
        $pdo->prepare("
            INSERT INTO om_notifications_queue (user_type, user_id, title, body, icon, data, priority, sound)
            VALUES ('customer', ?, 'Buscando novo entregador', ?, '🔄', ?, 'normal', 'default')
        ")->execute([
            $customer_id,
            'Buscando novo entregador para seu pedido.',
            json_encode(['type' => 'driver_swap_searching', 'order_id' => $order_id])
        ]);
    } catch (Exception $e) {
        error_log("[trocar] Erro ao notificar cliente: " . $e->getMessage());
    }

    // Chat system message
    try {
        $pdo->prepare("
            INSERT INTO om_order_chat (order_id, sender_type, sender_id, message)
            VALUES (?, 'system', 0, ?)
        ")->execute([
            $order_id,
            'O cliente solicitou troca de entregador. Buscando novo entregador...'
        ]);
    } catch (Exception $e) {}

    jsonResponse([
        'success'              => true,
        'message'              => 'Buscando novo entregador',
        'order_id'             => $order_id,
        'old_driver_released'  => true,
        'old_driver_name'      => $old_driver_name,
        'reassign_count'       => $new_reassign_count,
        'max_changes'          => $max_changes,
        'remaining_changes'    => $max_changes - $new_reassign_count,
        'new_offer_id'         => (int)$new_offer_id,
        'auto_reassign'        => true
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Erro ao trocar entregador: ' . $e->getMessage()], 500);
}
