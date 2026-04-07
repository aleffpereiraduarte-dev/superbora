<?php
/**
 * GET /api/mercado/intelligence/courier-briefing.php?order_id=123
 * Generates personalized delivery instructions for the courier:
 *   - "Predio com porteiro - deixar na recepcao"
 *   - "Cliente prefere ligar antes de subir"
 *   - "Melhor entrada pelos fundos"
 *
 * Uses customer notes, address, past delivery history.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $orderId = (int)($_GET['order_id'] ?? 0);
    if (!$orderId) response(false, null, 'order_id obrigatorio', 400);

    $db = getDB();

    // Pull order + address + history
    $stmt = $db->prepare(
        "SELECT o.order_id, o.customer_id, o.notes, o.address_complement, o.address_number,
                o.address_street, o.address_neighborhood, o.address_city,
                c.name AS customer_name, c.phone
         FROM om_market_orders o
         LEFT JOIN om_customers c ON o.customer_id = c.customer_id
         WHERE o.order_id = :oid"
    );
    $stmt->execute([':oid' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) response(false, null, 'pedido nao encontrado', 404);

    // Past delivery notes for the same customer
    $stmt = $db->prepare(
        "SELECT notes FROM om_market_orders
         WHERE customer_id = :cid AND notes IS NOT NULL AND notes <> ''
           AND order_id <> :oid
         ORDER BY created_at DESC LIMIT 5"
    );
    $stmt->execute([':cid' => $order['customer_id'], ':oid' => $orderId]);
    $pastNotes = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'notes');

    $cacheFile = sys_get_temp_dir() . "/sb_briefing_{$orderId}.txt";
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 1800) {
        response(true, ['briefing' => file_get_contents($cacheFile)]);
    }

    $prompt = "Pedido #{$orderId}\n" .
              "Endereco: {$order['address_street']}, {$order['address_number']} " .
              ($order['address_complement'] ? '(' . $order['address_complement'] . ')' : '') .
              " - {$order['address_neighborhood']}, {$order['address_city']}\n" .
              "Observacoes do pedido: " . ($order['notes'] ?: '-') . "\n" .
              "Notas de entregas anteriores deste cliente:\n- " . (implode("\n- ", $pastNotes) ?: 'sem historico') . "\n\n" .
              "Voce eh o assistente do entregador. Em pt-BR, max 250 chars, escreva instrucoes praticas. " .
              "Liste em bullets curtos. Foque no que ajuda na entrega rapida.";

    $briefing = ClaudeClient::text(
        $prompt,
        'Voce ajuda entregadores. Direto, objetivo, sem firula.',
        300
    );
    if (!$briefing) response(false, null, 'falha gerando briefing', 502);

    @file_put_contents($cacheFile, $briefing);
    response(true, ['order_id' => $orderId, 'briefing' => $briefing]);
} catch (Exception $e) {
    error_log('[courier-briefing] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
