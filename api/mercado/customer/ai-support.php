<?php
/**
 * AI Customer Support (like iFood's "Rosie")
 * POST: {message, ticket_id?, order_id?}
 * Returns AI response with intent classification + escalation flag
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { response(false, null, 'Method not allowed', 405); }

$customerId = requireCustomerAuth();
checkRateLimit("ai_support:{$customerId}", 10, 60);
$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$ticketId = intval($input['ticket_id'] ?? 0);
$orderId = intval($input['order_id'] ?? 0);

if (empty($message)) { response(false, null, 'Message required', 400); }
if (strlen($message) > 1000) { response(false, null, 'Message too long', 400); }

$db = getDB();

// Validate order_id ownership if provided
if ($orderId) {
    $stmtOwner = $db->prepare("SELECT 1 FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
    $stmtOwner->execute([$orderId, $customerId]);
    if (!$stmtOwner->fetch()) {
        response(false, null, 'Order not found or does not belong to you', 404);
    }
}

// Get or create ticket
if (!$ticketId) {
    $stmt = $db->prepare("INSERT INTO om_ai_support_tickets (customer_id, order_id, status, category) VALUES (?, ?, 'open', 'general') RETURNING ticket_id");
    $stmt->execute([$customerId, $orderId ?: null]);
    $ticketId = $stmt->fetchColumn();
} else {
    $stmt = $db->prepare("SELECT ticket_id FROM om_ai_support_tickets WHERE ticket_id = ? AND customer_id = ?");
    $stmt->execute([$ticketId, $customerId]);
    if (!$stmt->fetch()) { response(false, null, 'Ticket not found', 404); }
}

// Load ticket history
$stmt = $db->prepare("SELECT role, content FROM om_ai_support_messages WHERE ticket_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$ticketId]);
$history = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

// Load order info if available
$orderInfo = '';
if ($orderId) {
    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.total, o.created_at, o.payment_method,
               p.business_name as partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.order_id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($order) {
        $orderInfo = "DADOS DO PEDIDO: #" . $order['order_id'] . " | Status: " . $order['status']
            . " | Total: R$ " . number_format($order['total'], 2, ',', '.')
            . " | Parceiro: " . $order['partner_name']
            . " | Data: " . $order['created_at']
            . " | Pagamento: " . $order['payment_method'];
    }
}

$systemPrompt = "Voce e o suporte ao cliente SuperBora. Ajude com problemas de pedidos, pagamentos, entregas.

REGRAS:
- Responda SEMPRE em portugues brasileiro
- Seja empatico e profissional
- Se o problema exigir intervencao humana (reembolso > R\$50, denuncias, problemas tecnicos complexos), defina escalate = true
- Para 'onde esta meu pedido', use os dados do pedido fornecidos
- NUNCA prometa reembolsos sem escalar para humano
- Retorne JSON: {\"response_text\": \"...\", \"intent\": \"order_status|refund|delivery|payment|general|escalate\", \"ai_confidence\": 0.0-1.0, \"escalate\": false}

POLITICAS:
- Cancelamento gratuito se pedido ainda nao aceito pelo parceiro
- Reembolso parcial ou total depende da situacao (escalar para humano)
- Tempo de entrega estimado: ~40 min para entregas normais
- Problemas com pagamento PIX: verificar em 'Meus Pedidos' se o pagamento foi confirmado

" . ($orderInfo ?: "Nenhum pedido especifico vinculado.");

$messages = [];
foreach ($history as $h) {
    $messages[] = ['role' => $h['role'], 'content' => $h['content']];
}
$messages[] = ['role' => 'user', 'content' => $message];

// Save user message
$stmt = $db->prepare("INSERT INTO om_ai_support_messages (ticket_id, role, content) VALUES (?, 'user', ?)");
$stmt->execute([$ticketId, $message]);

$claude = new ClaudeClient();
$result = $claude->send($systemPrompt, $messages, 1024);

if (!$result['success']) {
    response(true, [
        'ticket_id' => $ticketId,
        'response_text' => 'Desculpe, estou com dificuldade agora. Um atendente humano ira ajudar voce em breve.',
        'escalate' => true,
    ]);
}

$parsed = ClaudeClient::parseJson($result['text']);
$responseText = $parsed['response_text'] ?? $result['text'];
$escalate = $parsed['escalate'] ?? false;
$confidence = floatval($parsed['ai_confidence'] ?? 0.5);
$intent = $parsed['intent'] ?? 'general';

if ($confidence < 0.7) { $escalate = true; }

// Save assistant message
$stmt = $db->prepare("INSERT INTO om_ai_support_messages (ticket_id, role, content, metadata) VALUES (?, 'assistant', ?, ?::jsonb)");
$stmt->execute([$ticketId, $responseText, json_encode(['intent' => $intent, 'confidence' => $confidence, 'tokens' => $result['total_tokens'] ?? 0])]);

// Update ticket
$stmt = $db->prepare("UPDATE om_ai_support_tickets SET category = ?, ai_confidence = ?, status = ?, updated_at = NOW() WHERE ticket_id = ?");
$stmt->execute([$intent, $confidence, $escalate ? 'escalated' : 'open', $ticketId]);

response(true, [
    'ticket_id' => $ticketId,
    'response_text' => $responseText,
    'intent' => $intent,
    'escalate' => $escalate,
    'confidence' => $confidence,
]);
