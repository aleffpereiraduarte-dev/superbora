<?php
/**
 * GET /api/mercado/admin/dispute-brief.php?complaint_id=N
 *
 * Generates an executive brief for an admin opening a complaint case:
 *   - Summary of the complaint
 *   - Order context (items, value, partner reputation)
 *   - Customer history (LTV, prior disputes)
 *   - Cited evidence (chat messages, photos, refund history)
 *   - Recommended action with reasoning
 *
 * Saves the admin 5+ minutes of digging through tabs.
 *
 * Auth: admin/rh JWT.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, 'Token nao fornecido', 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || !in_array($payload['type'] ?? '', ['admin', 'rh'], true)) {
        response(false, null, 'Acesso restrito a admin/rh', 403);
    }

    $complaintId = (int)($_GET['complaint_id'] ?? 0);
    if (!$complaintId) response(false, null, 'complaint_id obrigatorio', 400);

    // Load complaint + order + customer + partner in one shot
    $stmt = $db->prepare("
        SELECT
            c.id, c.motivo, c.descricao, c.status AS complaint_status, c.created_at AS complaint_created_at,
            o.order_id, o.total, o.subtotal, o.delivery_fee, o.status AS order_status, o.date_added AS order_date,
            o.customer_id, o.partner_id,
            p.name AS partner_name,
            cu.name AS customer_name, cu.email AS customer_email
        FROM om_market_order_complaints c
        JOIN om_market_orders o ON o.order_id = c.order_id
        JOIN om_market_partners p ON p.partner_id = o.partner_id
        JOIN om_customers cu ON cu.customer_id = o.customer_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$complaintId]);
    $base = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$base) response(false, null, 'Reclamacao nao encontrada', 404);

    // Order items
    $stmtItems = $db->prepare("
        SELECT name, quantity, price
        FROM om_market_order_items
        WHERE order_id = ?
        LIMIT 30
    ");
    $stmtItems->execute([(int)$base['order_id']]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Customer history: LTV + prior complaints
    $stmtCust = $db->prepare("
        SELECT
            COUNT(*) AS total_orders,
            COUNT(*) FILTER (WHERE status = 'entregue') AS delivered,
            COALESCE(SUM(total) FILTER (WHERE status = 'entregue'), 0) AS ltv
        FROM om_market_orders
        WHERE customer_id = ?
    ");
    $stmtCust->execute([(int)$base['customer_id']]);
    $custStats = $stmtCust->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtPriorComplaints = $db->prepare("
        SELECT COUNT(*) FROM om_market_order_complaints
        WHERE customer_id = ? AND id != ?
    ");
    $stmtPriorComplaints->execute([(int)$base['customer_id'], $complaintId]);
    $priorCount = (int)$stmtPriorComplaints->fetchColumn();

    // Partner reputation: avg rating + complaint rate
    $stmtPartner = $db->prepare("
        SELECT
            COALESCE((SELECT AVG(rating) FROM om_market_reviews WHERE partner_id = ?), 0) AS avg_rating,
            (SELECT COUNT(*) FROM om_market_orders WHERE partner_id = ? AND date_added > NOW() - INTERVAL '30 days') AS orders_30d,
            (SELECT COUNT(*) FROM om_market_order_complaints c2
             JOIN om_market_orders o2 ON o2.order_id = c2.order_id
             WHERE o2.partner_id = ? AND c2.created_at > NOW() - INTERVAL '30 days') AS complaints_30d
    ");
    $stmtPartner->execute([(int)$base['partner_id'], (int)$base['partner_id'], (int)$base['partner_id']]);
    $partnerStats = $stmtPartner->fetch(PDO::FETCH_ASSOC) ?: [];

    // Chat messages (if any) — driver/customer
    $chatMessages = [];
    try {
        $stmtChat = $db->prepare("
            SELECT sender_role, message, created_at
            FROM om_market_order_chat
            WHERE order_id = ?
            ORDER BY created_at ASC
            LIMIT 30
        ");
        $stmtChat->execute([(int)$base['order_id']]);
        $chatMessages = $stmtChat->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* table name may differ */ }

    // Existing AI mediation (if complaint-mediator was already called)
    $existingMediation = null;
    try {
        $stmtMed = $db->prepare("SELECT * FROM om_complaint_mediations WHERE complaint_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmtMed->execute([$complaintId]);
        $existingMediation = $stmtMed->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { /* table may not exist yet */ }

    $complaintAgeHours = (int)((time() - strtotime($base['complaint_created_at'])) / 3600);

    $sysPrompt = "Voce eh assistente do time de suporte de um app de delivery brasileiro. " .
                 "Gere um BRIEF executivo para o admin que esta abrindo essa disputa.\n" .
                 "REGRAS:\n" .
                 "- summary: max 200 chars, RESUMO objetivo do que aconteceu\n" .
                 "- key_facts: 3-5 bullets curtos com fatos relevantes (datas, valores, status)\n" .
                 "- red_flags: bullets de coisas SUSPEITAS (ex: cliente abriu muitas reclamacoes; loja com taxa alta de problemas; valor alto)\n" .
                 "- recommended_action: refund_full, refund_partial, ask_partner_evidence, dismiss_complaint, escalate_legal, custom\n" .
                 "- recommended_action_reason: max 200 chars\n" .
                 "- estimated_resolution_minutes: int\n" .
                 "- priority: low, medium, high, urgent\n" .
                 "- Tom direto, profissional, sem firula. Linguagem de ops/CS.\n" .
                 "- Responda APENAS JSON";

    $userPrompt = "RECLAMACAO #{$complaintId}\n" .
                  "Idade: {$complaintAgeHours}h | Status: {$base['complaint_status']}\n" .
                  "Motivo: {$base['motivo']}\n" .
                  "Descricao: " . substr($base['descricao'], 0, 800) . "\n\n" .
                  "PEDIDO #{$base['order_id']}\n" .
                  "Loja: {$base['partner_name']}\n" .
                  "Total R\$ " . number_format((float)$base['total'], 2, ',', '.') . " (subtotal " . number_format((float)$base['subtotal'], 2, ',', '.') . " + frete " . number_format((float)$base['delivery_fee'], 2, ',', '.') . ")\n" .
                  "Status pedido: {$base['order_status']}\n" .
                  "Itens: " . json_encode($items, JSON_UNESCAPED_UNICODE) . "\n\n" .
                  "CLIENTE:\n" .
                  "Nome: {$base['customer_name']}\n" .
                  "Pedidos: " . (int)($custStats['total_orders'] ?? 0) . " (" . (int)($custStats['delivered'] ?? 0) . " entregues)\n" .
                  "LTV: R\$ " . number_format((float)($custStats['ltv'] ?? 0), 2, ',', '.') . "\n" .
                  "Reclamacoes anteriores: {$priorCount}\n\n" .
                  "LOJA:\n" .
                  "Avg rating: " . number_format((float)($partnerStats['avg_rating'] ?? 0), 1) . "\n" .
                  "Pedidos ultimos 30d: " . (int)($partnerStats['orders_30d'] ?? 0) . "\n" .
                  "Reclamacoes ultimos 30d: " . (int)($partnerStats['complaints_30d'] ?? 0) . "\n\n" .
                  (empty($chatMessages) ? '' : "CHAT (" . count($chatMessages) . " msgs):\n" . json_encode(array_slice($chatMessages, 0, 15), JSON_UNESCAPED_UNICODE) . "\n\n") .
                  ($existingMediation ? "MEDIACAO AI ANTERIOR:\n" . json_encode($existingMediation, JSON_UNESCAPED_UNICODE) . "\n\n" : '') .
                  'Responda APENAS JSON: {"summary":"...","key_facts":["..."],"red_flags":["..."],"recommended_action":"...","recommended_action_reason":"...","estimated_resolution_minutes":int,"priority":"..."}';

    $parsed = ClaudeClient::sendFastJson($userPrompt, $sysPrompt, 1200);
    if (!$parsed) {
        response(false, null, 'AI indisponivel', 502);
    }

    // Validate
    $allowedActions = ['refund_full', 'refund_partial', 'ask_partner_evidence', 'dismiss_complaint', 'escalate_legal', 'custom'];
    $action = in_array($parsed['recommended_action'] ?? '', $allowedActions, true)
        ? $parsed['recommended_action']
        : 'custom';
    $allowedPriorities = ['low', 'medium', 'high', 'urgent'];
    $priority = in_array($parsed['priority'] ?? '', $allowedPriorities, true) ? $parsed['priority'] : 'medium';

    response(true, [
        'complaint_id' => $complaintId,
        'summary' => mb_substr($parsed['summary'] ?? '', 0, 240),
        'key_facts' => array_slice($parsed['key_facts'] ?? [], 0, 8),
        'red_flags' => array_slice($parsed['red_flags'] ?? [], 0, 8),
        'recommended_action' => $action,
        'recommended_action_reason' => mb_substr($parsed['recommended_action_reason'] ?? '', 0, 240),
        'estimated_resolution_minutes' => max(1, min(120, (int)($parsed['estimated_resolution_minutes'] ?? 5))),
        'priority' => $priority,
        'context' => [
            'customer_ltv' => (float)($custStats['ltv'] ?? 0),
            'customer_prior_complaints' => $priorCount,
            'partner_avg_rating' => round((float)($partnerStats['avg_rating'] ?? 0), 1),
            'partner_complaint_rate_30d' => $partnerStats['orders_30d'] > 0
                ? round(($partnerStats['complaints_30d'] / $partnerStats['orders_30d']) * 100, 1)
                : 0,
            'has_existing_mediation' => $existingMediation !== null,
            'complaint_age_hours' => $complaintAgeHours,
        ],
    ], 'OK');

} catch (Exception $e) {
    error_log('[dispute-brief] ' . $e->getMessage());
    response(false, null, 'Erro', 500);
}
