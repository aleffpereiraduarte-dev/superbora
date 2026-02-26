<?php
/**
 * GET /api/mercado/pedido/polling.php?since=2026-01-01T00:00:00
 * Polling leve: retorna pedidos modificados desde timestamp
 * Usado pelo painel para atualizar em tempo real
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

try {
    session_start();
    $db = getDB();

    $mercado_id = $_SESSION['mercado_id'] ?? 0;
    if (!$mercado_id) {
        response(false, null, "Nao autorizado", 401);
    }

    $since = $_GET['since'] ?? date('Y-m-d H:i:s', strtotime('-5 minutes'));

    // Sanitizar timestamp
    $sinceTime = strtotime($since);
    if (!$sinceTime) {
        $sinceTime = strtotime('-5 minutes');
    }
    $sinceFormatted = date('Y-m-d H:i:s', $sinceTime);

    // Buscar pedidos modificados desde 'since'
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.customer_name, o.customer_phone,
               o.status, o.total, o.subtotal, o.delivery_fee,
               o.date_added, o.date_modified, o.accepted_at, o.ready_at,
               o.timer_started, o.timer_expires, o.cancel_reason,
               o.delivery_address, o.forma_pagamento, o.notes,
               (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as itens_count
        FROM om_market_orders o
        WHERE o.partner_id = ?
          AND (o.date_modified >= ? OR o.date_added >= ?)
        ORDER BY o.date_added DESC
        LIMIT 50
    ");
    $stmt->execute([$mercado_id, $sinceFormatted, $sinceFormatted]);
    $pedidos = $stmt->fetchAll();

    // Contar pedidos pendentes
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM om_market_orders
        WHERE partner_id = ? AND status = 'pendente'
    ");
    $stmt->execute([$mercado_id]);
    $pendentes = (int)$stmt->fetchColumn();

    response(true, [
        "pedidos" => $pedidos,
        "pendentes" => $pendentes,
        "server_time" => date('c'),
        "since" => $sinceFormatted
    ]);

} catch (Exception $e) {
    error_log("[polling] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar pedidos", 500);
}
