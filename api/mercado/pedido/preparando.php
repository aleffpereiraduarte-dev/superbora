<?php
/**
 * POST /api/mercado/pedido/preparando.php
 * Marca pedido como "preparando"
 * Body: { "order_id": 10 }
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/notify.php";
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
require_once __DIR__ . '/../helpers/eta-calculator.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

// CSRF protection: require JSON content type for session-auth endpoints
$ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') === false) {
    response(false, null, "Content-Type deve ser application/json", 400);
}

try {
    session_start();
    $db = getDB();

    $mercado_id = $_SESSION['mercado_id'] ?? 0;
    if (!$mercado_id) {
        response(false, null, "Nao autorizado", 401);
    }

    $input = getInput();
    $order_id = (int)($input['order_id'] ?? 0);

    if (!$order_id) {
        response(false, null, "order_id obrigatorio", 400);
    }

    $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND partner_id = ? FOR UPDATE");
    $stmt->execute([$order_id, $mercado_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        $db->rollBack();
        response(false, null, "Pedido nao encontrado", 404);
    }

    $statusPermitidos = ['aceito'];
    if (!in_array($pedido['status'], $statusPermitidos)) {
        $db->rollBack();
        if ($pedido['status'] === 'pendente') {
            response(false, null, "Aceite o pedido primeiro antes de iniciar o preparo", 409);
        }
        response(false, null, "Pedido nao pode ir para preparando (status atual: {$pedido['status']})", 409);
    }

    $updates = "status = 'preparando', preparing_started_at = NOW(), date_modified = NOW()";
    $params = [$order_id];

    $stmt = $db->prepare("UPDATE om_market_orders SET $updates WHERE order_id = ?");
    $stmt->execute($params);
    $db->commit();

    // WebSocket broadcast (never breaks the flow)
    try {
        $customer_id_ws = (int)($pedido['customer_id'] ?? 0);
        if ($customer_id_ws) {
            wsBroadcastToCustomer($customer_id_ws, 'order_update', [
                'order_id' => $order_id,
                'status' => 'preparando',
                'previous_status' => $pedido['status'],
            ]);
        }
        wsBroadcastToOrder($order_id, 'order_update', [
            'order_id' => $order_id,
            'status' => 'preparando',
        ]);
    } catch (\Throwable $e) {}

    // Notificar cliente
    $customer_id = (int)($pedido['customer_id'] ?? 0);
    if ($customer_id) {
        notifyCustomer($db, $customer_id,
            'Pedido em preparo!',
            "Seu pedido #{$pedido['order_number']} esta sendo preparado.",
            '/mercado/pedido.php?id=' . $order_id
        );
    }

    // WhatsApp notification with prep time ETA (never breaks the flow)
    try {
        $customerPhone = $pedido['customer_phone'] ?? '';
        if ($customerPhone) {
            // Calculate remaining prep time
            $prepMinutes = 0;
            try {
                $prepMinutes = (int)round(getAvgPrepTime($db, $mercado_id));
            } catch (\Throwable $etaErr) {
                error_log("[preparando] ETA calc error: " . $etaErr->getMessage());
                $prepMinutes = 15; // fallback
            }

            // Get partner name
            $partnerName = $pedido['partner_name'] ?? '';
            if (!$partnerName) {
                try {
                    $pStmt = $db->prepare("SELECT COALESCE(trade_name, name) as pname FROM om_market_partners WHERE partner_id = ?");
                    $pStmt->execute([$mercado_id]);
                    $pRow = $pStmt->fetch();
                    $partnerName = $pRow['pname'] ?? '';
                } catch (\Throwable $e) {}
            }

            $waResult = whatsappOrderPreparing($customerPhone, $pedido['order_number'], $partnerName, $prepMinutes);
            error_log("[preparando] WhatsApp pedido #{$pedido['order_number']} phone=****" . substr($customerPhone, -4) . " prep={$prepMinutes}min success=" . ($waResult['success'] ? 'yes' : 'no'));
        }
    } catch (\Throwable $waErr) {
        error_log("[preparando] WhatsApp error: " . $waErr->getMessage());
    }

    // Proactive WhatsApp: log to conversation (message already sent above)
    try {
        require_once __DIR__ . '/../helpers/whatsapp-order-updates.php';
        sendOrderStatusWhatsApp($db, $order_id, 'preparando', true);
    } catch (\Throwable $e) {
        error_log("[preparando] Proactive WA update error: " . $e->getMessage());
    }

    response(true, [
        "order_id" => $order_id,
        "status" => "preparando"
    ], "Pedido em preparo!");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[preparando] Erro: " . $e->getMessage());
    response(false, null, "Erro ao atualizar pedido", 500);
}
