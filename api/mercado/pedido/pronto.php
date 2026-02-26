<?php
/**
 * POST /api/mercado/pedido/pronto.php
 * Marca pedido como "pronto" + auto-chama driver (Feature 5)
 * Body: { "order_id": 10 }
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/notify.php";
require_once __DIR__ . "/../helpers/delivery.php";
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

    $statusPermitidos = ['preparando'];
    if (!in_array($pedido['status'], $statusPermitidos)) {
        $db->rollBack();
        if ($pedido['status'] === 'aceito' || $pedido['status'] === 'pendente') {
            response(false, null, "Marque o pedido como 'preparando' antes de finalizar", 409);
        }
        response(false, null, "Pedido nao pode ser marcado como pronto (status atual: {$pedido['status']})", 409);
    }

    // Feature 5: Determine delivery_type based on partner options (inside transaction)
    $stmt = $db->prepare("SELECT categoria, aceita_boraum, entrega_propria, aceita_retirada FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$mercado_id]);
    $parceiro = $stmt->fetch();

    $categoria = $pedido['partner_categoria'] ?? $parceiro['categoria'] ?? 'mercado';
    $aceitaBoraum = (bool)($parceiro['aceita_boraum'] ?? true);
    $entregaPropria = (bool)($parceiro['entrega_propria'] ?? false);
    $isPickup = (bool)($pedido['is_pickup'] ?? false);
    $categorias_mercado = ['mercado', 'supermercado'];

    // Determine delivery_type to set atomically with status change
    $deliveryType = null;
    if ($isPickup) {
        $deliveryType = 'retirada';
    } elseif ($entregaPropria && !$aceitaBoraum) {
        $deliveryType = 'proprio';
    } elseif ($aceitaBoraum) {
        $deliveryType = 'boraum';
    }

    // Marcar como pronto + set delivery_type atomically
    if ($deliveryType) {
        $stmt = $db->prepare("
            UPDATE om_market_orders SET
                status = 'pronto',
                ready_at = NOW(),
                delivery_type = ?,
                date_modified = NOW()
            WHERE order_id = ?
        ");
        $stmt->execute([$deliveryType, $order_id]);
    } else {
        $stmt = $db->prepare("
            UPDATE om_market_orders SET
                status = 'pronto',
                ready_at = NOW(),
                date_modified = NOW()
            WHERE order_id = ?
        ");
        $stmt->execute([$order_id]);
    }
    $db->commit();

    // Post-commit: Notificar cliente (mensagem diferente para pickup vs entrega)
    $customer_id = (int)($pedido['customer_id'] ?? 0);
    if ($customer_id) {
        if ($isPickup) {
            notifyCustomer($db, $customer_id,
                'Pedido pronto para retirada!',
                "Seu pedido #{$pedido['order_number']} esta pronto! Dirija-se ao estabelecimento para retirar.",
                '/mercado/pedido.php?id=' . $order_id
            );
        } else {
            notifyCustomer($db, $customer_id,
                'Pedido pronto!',
                "Seu pedido #{$pedido['order_number']} esta pronto! Estamos chamando um entregador.",
                '/mercado/pedido.php?id=' . $order_id
            );
        }
    }

    // Post-commit: Auto-dispatch BoraUm (external API call, must be outside transaction)
    $entrega = null;
    if ($isPickup) {
        error_log("[pronto] Pedido #$order_id e retirada - sem dispatch");
    } elseif ($entregaPropria && !$aceitaBoraum) {
        error_log("[pronto] Pedido #$order_id usa entrega propria do parceiro");
    } elseif ($aceitaBoraum) {
        // Despachar BoraUm para qualquer parceiro que aceita (mercado, restaurante, loja)
        $entrega = dispatchToBoraUm($db, $pedido);
        error_log("[pronto] Auto-dispatch BoraUm para pedido #$order_id | Categoria: $categoria | Resultado: " . json_encode($entrega));
    } else {
        error_log("[pronto] Pedido #$order_id sem dispatch automatico (propria=$entregaPropria, boraum=$aceitaBoraum)");
    }

    response(true, [
        "order_id" => $order_id,
        "status" => "pronto",
        "ready_at" => date('c'),
        "entrega" => $entrega
    ], "Pedido pronto!" . ($entrega && $entrega['success'] ? " Entregador sendo chamado." : ""));

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[pronto] Erro: " . $e->getMessage());
    response(false, null, "Erro ao atualizar pedido", 500);
}
