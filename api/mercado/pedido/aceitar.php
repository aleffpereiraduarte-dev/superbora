<?php
/**
 * POST /api/mercado/pedido/aceitar.php
 * Parceiro aceita o pedido
 * Body: { "order_id": 10 }
 *
 * Restaurante: pendente -> aceito (preparando implícito)
 * Mercado: pendente -> aceito
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/notify.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

// CSRF protection: session-auth endpoints require JSON content type
// (prevents cross-site form submissions since application/json triggers CORS preflight)
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

    // Buscar pedido com lock para evitar race condition
    $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND partner_id = ? FOR UPDATE");
    $stmt->execute([$order_id, $mercado_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        $db->rollBack();
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Aceitar pedidos pendentes OU ja confirmados (pagamento PIX/Stripe ja aprovado)
    if (!in_array($pedido['status'], ['pendente', 'confirmado'])) {
        $db->rollBack();
        response(false, null, "Pedido nao esta pendente (status atual: {$pedido['status']})", 409);
    }

    // Buscar categoria do parceiro
    $stmt = $db->prepare("SELECT categoria FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$mercado_id]);
    $parceiro = $stmt->fetch();
    $categoria = $parceiro['categoria'] ?? 'mercado';

    // Restaurante vai direto para 'preparando', mercado/supermercado para 'aceito'
    $novo_status = ($categoria === 'restaurante') ? 'preparando' : 'aceito';

    $stmt = $db->prepare("
        UPDATE om_market_orders SET
            status = ?,
            accepted_at = NOW(),
            partner_categoria = ?,
            date_modified = NOW()
        WHERE order_id = ?
    ");
    $stmt->execute([$novo_status, $categoria, $order_id]);
    $db->commit();

    // Notificar cliente
    $customer_id = (int)($pedido['customer_id'] ?? 0);
    if ($customer_id) {
        notifyCustomer($db, $customer_id,
            'Pedido aceito!',
            "Seu pedido #{$pedido['order_number']} foi aceito e esta sendo preparado.",
            '/mercado/pedido.php?id=' . $order_id
        );
    }

    error_log("[aceitar] Pedido #$order_id aceito por parceiro #$mercado_id | Status: $novo_status");

    response(true, [
        "order_id" => $order_id,
        "status" => $novo_status,
        "accepted_at" => date('c')
    ], "Pedido aceito!");

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[aceitar] Erro: " . $e->getMessage());
    response(false, null, "Erro ao aceitar pedido", 500);
}
