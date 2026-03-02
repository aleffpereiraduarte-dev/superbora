<?php
/**
 * AI Customer Support (like iFood's "Rosie")
 * POST: {message, ticket_id?, order_id?}
 * Returns AI response with intent classification + escalation flag
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
setCorsHeaders();

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST' && $method !== 'GET') { response(false, null, 'Method not allowed', 405); }

$customerId = requireCustomerAuth();

// ═══════════════════════════════════════════════════════════════════
// GET - Return merged conversation (AI messages + admin replies)
// ═══════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $ticketId = intval($_GET['ticket_id'] ?? 0);
    if (!$ticketId) { response(false, null, 'ticket_id required', 400); }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare("SELECT ticket_id, status FROM om_ai_support_tickets WHERE ticket_id = ? AND customer_id = ?");
    $stmt->execute([$ticketId, $customerId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) { response(false, null, 'Ticket not found', 404); }

    // Fetch AI conversation messages
    $stmt = $db->prepare("SELECT message_id as id, role, content, created_at FROM om_ai_support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
    $stmt->execute([$ticketId]);
    $aiMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $merged = [];
    foreach ($aiMessages as $m) {
        $merged[] = [
            'id' => 'ai_' . $m['id'],
            'role' => $m['role'],          // 'user' or 'assistant'
            'content' => $m['content'],
            'sender_name' => $m['role'] === 'assistant' ? 'Suporte IA' : null,
            'created_at' => $m['created_at'],
        ];
    }

    // Check for bridged support ticket (admin replies)
    $stmt = $db->prepare("SELECT id FROM om_support_tickets WHERE referencia_tipo = 'ai_ticket' AND referencia_id = ?");
    $stmt->execute([$ticketId]);
    $bridgedTicket = $stmt->fetch(PDO::FETCH_ASSOC);

    $supportTicketId = null;
    if ($bridgedTicket) {
        $supportTicketId = (int)$bridgedTicket['id'];

        // Fetch admin/atendente/sistema messages from the bridged ticket
        // Exclude 'customer' and 'bot' messages — we only want human agent replies
        // Also exclude 'system' summary message (the first one with the conversation dump)
        $stmt = $db->prepare("
            SELECT id, remetente_tipo, remetente_nome, mensagem, created_at
            FROM om_support_messages
            WHERE ticket_id = ? AND remetente_tipo IN ('admin', 'atendente', 'sistema')
            ORDER BY created_at ASC
        ");
        $stmt->execute([$supportTicketId]);
        $adminMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($adminMessages as $m) {
            // Skip the initial system summary message (it's just the conversation dump)
            if ($m['remetente_tipo'] === 'sistema' || $m['remetente_tipo'] === 'system') {
                continue;
            }
            $merged[] = [
                'id' => 'agent_' . $m['id'],
                'role' => 'agent',
                'content' => $m['mensagem'],
                'sender_name' => $m['remetente_nome'] ?: 'Atendente',
                'created_at' => $m['created_at'],
            ];
        }
    }

    // Sort all messages by timestamp
    usort($merged, function($a, $b) {
        return strtotime($a['created_at']) - strtotime($b['created_at']);
    });

    response(true, [
        'ticket_id' => $ticketId,
        'status' => $ticket['status'],
        'support_ticket_id' => $supportTicketId,
        'escalated' => $ticket['status'] === 'escalated',
        'messages' => $merged,
    ]);
}

// ═══════════════════════════════════════════════════════════════════
// POST - Send message to AI support
// ═══════════════════════════════════════════════════════════════════
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
    // Limit: max 5 open tickets per customer to prevent abuse
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_ai_support_tickets WHERE customer_id = ? AND status IN ('open', 'escalated')");
    $stmtCount->execute([$customerId]);
    $openTickets = (int)$stmtCount->fetchColumn();
    if ($openTickets >= 5) {
        response(false, null, 'Voce ja tem 5 tickets abertos. Feche ou resolva os existentes antes de abrir novos.', 429);
    }

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
               p.name as partner_name
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

// Build message history with token limit guard
// Cap total history to ~8000 chars (~2000 tokens) to avoid exceeding API limits
$messages = [];
$totalChars = 0;
$maxHistoryChars = 8000;
foreach ($history as $h) {
    $content = $h['content'];
    $totalChars += strlen($content);
    if ($totalChars > $maxHistoryChars) {
        // Trim oldest messages if history is too long — keep most recent ones
        break;
    }
    $messages[] = ['role' => $h['role'], 'content' => $content];
}
// If history was truncated, reverse to keep most recent messages
if ($totalChars > $maxHistoryChars) {
    $messages = [];
    $totalChars = 0;
    foreach (array_reverse($history) as $h) {
        $content = $h['content'];
        $totalChars += strlen($content);
        if ($totalChars > $maxHistoryChars) break;
        array_unshift($messages, ['role' => $h['role'], 'content' => $content]);
    }
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
$newStatus = $escalate ? 'escalated' : 'open';
$stmt = $db->prepare("UPDATE om_ai_support_tickets SET category = ?, ai_confidence = ?, status = ?, updated_at = NOW() WHERE ticket_id = ?");
$stmt->execute([$intent, $confidence, $newStatus, $ticketId]);

// BUG FIX 1: Bridge escalated AI tickets to admin ticket queue (om_support_tickets)
// The admin panel queries om_support_tickets, not om_ai_support_tickets.
// When AI escalates, create a corresponding record so admins see it.
$supportTicketId = null;
if ($escalate) {
    try {
        // Check if already bridged (idempotency)
        $stmtCheck = $db->prepare("SELECT id FROM om_support_tickets WHERE referencia_tipo = 'ai_ticket' AND referencia_id = ?");
        $stmtCheck->execute([$ticketId]);
        $existing = $stmtCheck->fetch();

        if (!$existing) {
            // Get customer name
            $stmtName = $db->prepare("SELECT name FROM om_market_customers WHERE customer_id = ?");
            $stmtName->execute([$customerId]);
            $customerName = $stmtName->fetchColumn() ?: 'Cliente';

            // Build conversation summary for the admin
            $stmtSummary = $db->prepare("SELECT role, content FROM om_ai_support_messages WHERE ticket_id = ? ORDER BY created_at ASC LIMIT 20");
            $stmtSummary->execute([$ticketId]);
            $allMsgs = $stmtSummary->fetchAll(PDO::FETCH_ASSOC);

            $summary = "[Escalado do Suporte IA - Chat #{$ticketId}]\n\n";
            foreach ($allMsgs as $m) {
                $role = $m['role'] === 'user' ? 'Cliente' : 'IA';
                $summary .= "[{$role}]: " . $m['content'] . "\n\n";
            }

            // Map AI intent to support ticket category
            $categoryMap = [
                'order_status' => 'pedido',
                'refund' => 'reembolso',
                'delivery' => 'entrega',
                'payment' => 'pagamento',
                'general' => 'geral',
                'escalate' => 'geral',
            ];
            $ticketCategory = $categoryMap[$intent] ?? 'geral';

            // Generate unique ticket number
            $ticketNumber = 'TK-' . strtoupper(bin2hex(random_bytes(4)));

            // Determine subject from first user message or fallback
            $subject = "Escalado do Suporte IA #{$ticketId}";
            if (!empty($allMsgs)) {
                foreach ($allMsgs as $m) {
                    if ($m['role'] === 'user') {
                        $subject = mb_substr($m['content'], 0, 100);
                        break;
                    }
                }
            }

            // Insert into admin ticket queue
            $stmtBridge = $db->prepare("
                INSERT INTO om_support_tickets
                    (ticket_number, entidade_tipo, entidade_id, entidade_nome, assunto, categoria, status, prioridade, referencia_tipo, referencia_id, created_at, updated_at)
                VALUES (?, 'customer', ?, ?, ?, ?, 'em_atendimento', 'alta', 'ai_ticket', ?, NOW(), NOW())
                RETURNING id
            ");
            $stmtBridge->execute([$ticketNumber, $customerId, $customerName, $subject, $ticketCategory, $ticketId]);
            $supportTicketId = (int)$stmtBridge->fetchColumn();

            // Add conversation summary as first message
            $db->prepare("
                INSERT INTO om_support_messages (ticket_id, remetente_tipo, remetente_id, remetente_nome, mensagem, created_at)
                VALUES (?, 'system', ?, ?, ?, NOW())
            ")->execute([$supportTicketId, $customerId, $customerName, $summary]);
        } else {
            $supportTicketId = (int)$existing['id'];
        }
    } catch (\Throwable $e) {
        error_log("[ai-support] Failed to bridge ticket to admin queue: " . $e->getMessage());
        // Don't break the main flow — AI support still works, admin just won't see it immediately
    }
}

// BUG FIX 2: WebSocket broadcast for real-time updates
try {
    if ($escalate) {
        wsBroadcastToCustomer($customerId, 'ticket_update', [
            'ticket_id' => $ticketId,
            'support_ticket_id' => $supportTicketId,
            'status' => 'em_atendimento',
            'escalated' => true,
        ]);
    } else {
        wsBroadcastToCustomer($customerId, 'ticket_update', [
            'ticket_id' => $ticketId,
            'status' => 'aberto',
        ]);
    }
} catch (\Throwable $e) {
    error_log("[ai-support] WebSocket broadcast failed: " . $e->getMessage());
}

response(true, [
    'ticket_id' => $ticketId,
    'support_ticket_id' => $supportTicketId,
    'response_text' => $responseText,
    'intent' => $intent,
    'escalate' => $escalate,
    'confidence' => $confidence,
]);
