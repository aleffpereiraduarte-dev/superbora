<?php
/**
 * POST /api/mercado/webhooks/zapi-callcenter.php
 * Z-API WhatsApp Inbound Webhook for Call Center
 *
 * Full AI ordering bot — same intelligence as the voice handler (twilio-voice-ai.php)
 * but optimized for text-based WhatsApp conversations.
 *
 * Flow:
 *   1. Greeting → identify store
 *   2. Show menu highlights → take order items
 *   3. Confirm order summary
 *   4. Get/confirm delivery address
 *   5. Get payment method
 *   6. Submit order + send confirmation
 *
 * State tracked in om_callcenter_whatsapp.ai_context JSONB.
 */

// Load env
if (file_exists(__DIR__ . '/../../../.env')) {
    $envFile = file(__DIR__ . '/../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim(trim($value), '"\'');
        }
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once __DIR__ . '/../helpers/ws-callcenter-broadcast.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Validate Z-API Client Token ─────────────────────────────────────────
$clientToken = $_ENV['ZAPI_CLIENT_TOKEN'] ?? getenv('ZAPI_CLIENT_TOKEN') ?: '';
$headerToken = $_SERVER['HTTP_CLIENT_TOKEN'] ?? $_SERVER['HTTP_X_CLIENT_TOKEN'] ?? '';

if (!empty($clientToken) && !empty($headerToken)) {
    if (!hash_equals($clientToken, $headerToken)) {
        error_log("[zapi-callcenter] Rejected: invalid client token");
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// ── Parse Webhook Payload ───────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!$payload) {
    error_log("[zapi-callcenter] Invalid JSON body");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Only handle received messages
$type = $payload['type'] ?? '';
if (!in_array($type, ['ReceivedCallback', 'MessageStatusCallback'], true)) {
    echo json_encode(['ok' => true, 'ignored' => $type]);
    exit;
}

if ($type === 'MessageStatusCallback') {
    echo json_encode(['ok' => true]);
    exit;
}

// Skip group messages
if ($payload['isGroup'] ?? false) {
    echo json_encode(['ok' => true, 'ignored' => 'group']);
    exit;
}

$phone = $payload['phone'] ?? '';
$messageBody = $payload['body']['message'] ?? $payload['text']['message'] ?? $payload['body'] ?? '';
if (is_array($messageBody)) {
    $messageBody = $messageBody['message'] ?? json_encode($messageBody);
}
$messageId = $payload['messageId'] ?? $payload['id'] ?? '';
$mediaUrl = $payload['image']['imageUrl'] ?? $payload['audio']['audioUrl'] ?? $payload['document']['documentUrl'] ?? null;

$messageType = 'text';
if (isset($payload['image'])) $messageType = 'image';
elseif (isset($payload['audio'])) $messageType = 'audio';
elseif (isset($payload['document'])) $messageType = 'document';
elseif (isset($payload['location'])) $messageType = 'location';

if (empty($phone)) {
    error_log("[zapi-callcenter] Missing phone in webhook payload");
    http_response_code(400);
    echo json_encode(['error' => 'Missing phone']);
    exit;
}

$phone = preg_replace('/\D/', '', $phone);

error_log("[zapi-callcenter] Inbound from {$phone}: " . mb_substr($messageBody, 0, 100));

try {
    $db = getDB();

    // ── Look up customer ────────────────────────────────────────────────
    $customerId = null;
    $customerName = null;
    $phoneSuffix = substr($phone, -11);
    $custStmt = $db->prepare("
        SELECT customer_id, name FROM om_customers
        WHERE REPLACE(REPLACE(phone, '+', ''), '-', '') LIKE ?
        LIMIT 1
    ");
    $custStmt->execute(['%' . $phoneSuffix]);
    $customer = $custStmt->fetch();
    if ($customer) {
        $customerId = (int)$customer['customer_id'];
        $customerName = $customer['name'];
    }

    // ── Find or create conversation ─────────────────────────────────────
    $convStmt = $db->prepare("
        SELECT id, status, agent_id, ai_context FROM om_callcenter_whatsapp
        WHERE phone = ? AND status != 'closed'
        ORDER BY created_at DESC LIMIT 1
    ");
    $convStmt->execute([$phone]);
    $conversation = $convStmt->fetch();

    if ($conversation) {
        $conversationId = (int)$conversation['id'];
        $convStatus = $conversation['status'];
        $agentId = $conversation['agent_id'] ? (int)$conversation['agent_id'] : null;
        $aiContext = json_decode($conversation['ai_context'] ?? '{}', true) ?: [];

        $db->prepare("
            UPDATE om_callcenter_whatsapp
            SET last_message_at = NOW(),
                unread_count = unread_count + 1,
                customer_id = COALESCE(?, customer_id),
                customer_name = COALESCE(?, customer_name)
            WHERE id = ?
        ")->execute([$customerId, $customerName, $conversationId]);
    } else {
        $convStmt = $db->prepare("
            INSERT INTO om_callcenter_whatsapp (phone, customer_id, customer_name, status, last_message_at)
            VALUES (?, ?, ?, 'bot', NOW())
            RETURNING id
        ");
        $convStmt->execute([$phone, $customerId, $customerName]);
        $conversationId = (int)$convStmt->fetch()['id'];
        $convStatus = 'bot';
        $agentId = null;
        $aiContext = [];
    }

    // ── Store the message ───────────────────────────────────────────────
    $msgStmt = $db->prepare("
        INSERT INTO om_callcenter_wa_messages
            (conversation_id, direction, sender_type, message, message_type, media_url)
        VALUES (?, 'inbound', 'customer', ?, ?, ?)
        RETURNING id
    ");
    $msgStmt->execute([$conversationId, $messageBody, $messageType, $mediaUrl]);
    $msgId = (int)$msgStmt->fetch()['id'];

    // ── Broadcast to dashboard ──────────────────────────────────────────
    $broadcastData = [
        'conversation_id' => $conversationId,
        'message_id' => $msgId,
        'phone' => $phone,
        'customer_name' => $customerName,
        'message' => mb_substr($messageBody, 0, 200),
        'message_type' => $messageType,
        'status' => $convStatus,
    ];
    ccBroadcastDashboard('whatsapp_message', $broadcastData);

    if ($agentId) {
        ccBroadcastAgent($agentId, 'whatsapp_message', $broadcastData);
    }

    // ── AI Auto-Response (bot mode only) ────────────────────────────────
    if ($convStatus === 'bot' && $messageType === 'text' && !empty($messageBody)) {
        $botResult = handleBotConversation($db, $conversationId, $messageBody, $aiContext, $customerName, $customerId, $phone);

        if ($botResult) {
            // Send main response
            $sendResult = sendWhatsApp($phone, $botResult['response']);

            // Store outbound message
            $db->prepare("
                INSERT INTO om_callcenter_wa_messages
                    (conversation_id, direction, sender_type, message, message_type, ai_suggested)
                VALUES (?, 'outbound', 'bot', ?, 'text', true)
            ")->execute([$conversationId, $botResult['response']]);

            // Send follow-up message if present (e.g. menu, order summary)
            if (!empty($botResult['follow_up'])) {
                usleep(500000); // 500ms delay for natural feel
                sendWhatsApp($phone, $botResult['follow_up']);

                $db->prepare("
                    INSERT INTO om_callcenter_wa_messages
                        (conversation_id, direction, sender_type, message, message_type, ai_suggested)
                    VALUES (?, 'outbound', 'bot', ?, 'text', true)
                ")->execute([$conversationId, $botResult['follow_up']]);
            }

            // Save updated context
            $db->prepare("UPDATE om_callcenter_whatsapp SET ai_context = ? WHERE id = ?")
               ->execute([json_encode($botResult['context'], JSON_UNESCAPED_UNICODE), $conversationId]);

            // Handle transfer to agent
            if ($botResult['transfer'] ?? false) {
                $db->prepare("UPDATE om_callcenter_whatsapp SET status = 'waiting' WHERE id = ?")
                   ->execute([$conversationId]);

                ccBroadcastDashboard('whatsapp_transfer', [
                    'conversation_id' => $conversationId,
                    'phone' => $phone,
                    'customer_name' => $customerName,
                    'reason' => 'customer_requested',
                ]);
            }

            // Handle order submitted
            if ($botResult['order_submitted'] ?? false) {
                ccBroadcastDashboard('ai_order_completed', [
                    'conversation_id' => $conversationId,
                    'order_id' => $botResult['order_id'] ?? null,
                    'order_number' => $botResult['order_number'] ?? null,
                    'total' => $botResult['order_total'] ?? 0,
                    'customer_name' => $customerName,
                    'source' => 'whatsapp',
                ]);
            }

            if ($sendResult['success']) {
                error_log("[zapi-callcenter] Bot replied to {$phone} step=" . ($botResult['context']['step'] ?? '?'));
            } else {
                error_log("[zapi-callcenter] Bot reply failed: " . ($sendResult['message'] ?? 'unknown'));
            }
        }
    }

    echo json_encode(['ok' => true, 'conversation_id' => $conversationId, 'message_id' => $msgId]);

} catch (Exception $e) {
    error_log("[zapi-callcenter] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}


// ═══════════════════════════════════════════════════════════════════════════
// FULL AI BOT — Order-taking state machine
// ═══════════════════════════════════════════════════════════════════════════

function handleBotConversation(PDO $db, int $conversationId, string $userMessage, array $context, ?string $customerName, ?int $customerId, string $phone): ?array {
    try {
        $claude = new ClaudeClient('claude-sonnet-4-20250514', 30, 0);

        // Initialize context if needed
        if (empty($context['step'])) {
            $context['step'] = 'greeting';
            $context['items'] = [];
            $context['history'] = [];
            $context['store_id'] = null;
            $context['store_name'] = null;
            $context['address'] = null;
            $context['payment_method'] = null;
            $context['message_count'] = 0;
        }

        $step = $context['step'];
        $context['message_count'] = ($context['message_count'] ?? 0) + 1;

        // Check for transfer keywords
        $lowerMsg = mb_strtolower($userMessage, 'UTF-8');
        $transferKeywords = ['atendente', 'agente', 'pessoa', 'humano', 'operador'];
        foreach ($transferKeywords as $kw) {
            if (mb_strpos($lowerMsg, $kw) !== false) {
                $context['step'] = 'transferred';
                return [
                    'response' => "Sem problema! Vou transferir voce para um atendente. Aguarde um momento que alguem ja vai te atender. 🙋",
                    'follow_up' => null,
                    'context' => $context,
                    'transfer' => true,
                ];
            }
        }

        // Check for abandon current order (only when actively building)
        if (in_array($lowerMsg, ['desistir', 'nao quero mais', 'esquece']) && !empty($context['items'])) {
            $context = ['step' => 'greeting', 'items' => [], 'history' => [], 'message_count' => 0];
            return [
                'response' => "Tudo bem, pedido cancelado! Se quiser fazer um novo pedido depois, e so me mandar uma mensagem. 😊",
                'follow_up' => null,
                'context' => $context,
            ];
        }

        // Detect support intents — redirect to AI support mode instead of raw cancel
        $supportKeywords = ['status', 'cancelar', 'cancela', 'rastrear', 'rastreio', 'cadê meu pedido', 'cade meu pedido', 'onde ta', 'onde está', 'meu pedido', 'reclamação', 'reclamacao', 'problema', 'reembolso', 'devolver'];
        if ($step !== 'support') {
            foreach ($supportKeywords as $sk) {
                if (mb_strpos($lowerMsg, $sk) !== false) {
                    $context['step'] = 'support';
                    $step = 'support';
                    break;
                }
            }
        }

        // Smart: Auto-detect CEP in user message (8 digits that look like a CEP)
        $detectedCep = null;
        if (preg_match('/\b(\d{5})-?(\d{3})\b/', $userMessage, $cepMatch)) {
            $possibleCep = $cepMatch[1] . $cepMatch[2];
            // Verify it's a real CEP via ViaCEP
            $cepData = lookupCepWA($possibleCep);
            if ($cepData) {
                $detectedCep = $possibleCep;
                $context['cep_data'] = $cepData;
                $context['address'] = [
                    'street' => $cepData['street'],
                    'neighborhood' => $cepData['neighborhood'],
                    'city' => $cepData['city'],
                    'state' => $cepData['state'],
                    'cep' => $cepData['cep'],
                    'from_cep' => true,
                ];
                // If still picking a store, find nearby stores
                if (empty($context['store_id'])) {
                    $nearbyStores = findStoresByCepWA($db, $possibleCep);
                    if (!empty($nearbyStores)) {
                        $context['nearby_stores'] = $nearbyStores;
                    }
                }
                error_log("[zapi-callcenter] Auto-detected CEP {$possibleCep}: {$cepData['street']}, {$cepData['city']}");
            }
        }

        // Build conversation history for Claude
        $history = $context['history'] ?? [];
        $history[] = ['role' => 'user', 'content' => $userMessage];

        // Get data needed for the current step
        $storeId = $context['store_id'] ?? null;
        $storeName = $context['store_name'] ?? null;
        $items = $context['items'] ?? [];
        $address = $context['address'] ?? null;
        $payment = $context['payment_method'] ?? null;

        $menuText = '';
        if ($storeId) {
            $menuText = fetchStoreMenuWA($db, $storeId);
        }

        $storeNames = [];
        $favoriteStoreIds = [];
        if ($step === 'greeting' || $step === 'identify_store' || !$storeId) {
            // Show customer's favorite stores first
            if ($customerId) {
                $favStmt = $db->prepare("
                    SELECT DISTINCT o.partner_id, p.name, COUNT(*) as order_count, MAX(o.created_at) as last_order
                    FROM om_market_orders o
                    JOIN om_market_partners p ON p.partner_id = o.partner_id
                    WHERE o.customer_id = ? AND o.status NOT IN ('cancelled','refunded')
                    GROUP BY o.partner_id, p.name
                    ORDER BY order_count DESC, last_order DESC LIMIT 5
                ");
                $favStmt->execute([$customerId]);
                while ($fav = $favStmt->fetch()) {
                    $storeNames[] = $fav['name'] . ' (ID:' . $fav['partner_id'] . ') [pediu ' . $fav['order_count'] . 'x]';
                    $favoriteStoreIds[] = (int)$fav['partner_id'];
                }
            }

            $storesStmt = $db->query("SELECT partner_id, name FROM om_market_partners WHERE status = '1' ORDER BY name LIMIT 40");
            while ($row = $storesStmt->fetch()) {
                if (!in_array((int)$row['partner_id'], $favoriteStoreIds)) {
                    $storeNames[] = $row['name'] . ' (ID:' . $row['partner_id'] . ')';
                }
            }
        }

        // Last order items for smart suggestions
        $lastOrderItems = [];
        if ($customerId && $storeId && $step === 'take_order' && empty($items)) {
            $lastStmt = $db->prepare("
                SELECT oi.product_name, oi.quantity, oi.unit_price
                FROM om_market_order_items oi
                JOIN om_market_orders o ON o.order_id = oi.order_id
                WHERE o.customer_id = ? AND o.partner_id = ? AND o.status NOT IN ('cancelled','refunded')
                ORDER BY o.created_at DESC LIMIT 5
            ");
            $lastStmt->execute([$customerId, $storeId]);
            $lastOrderItems = $lastStmt->fetchAll();
        }

        // Fetch customer orders for support mode
        if ($step === 'support') {
            $supportOrders = [];
            $phoneSuffix = substr($phone, -11);
            $orderStmt = $db->prepare("
                SELECT o.order_id, o.order_number, o.status, o.total, o.date_added,
                       o.forma_pagamento, o.delivery_address, p.name AS partner_name
                FROM om_market_orders o
                JOIN om_market_partners p ON p.partner_id = o.partner_id
                WHERE (o.customer_id = ? OR REPLACE(REPLACE(o.customer_phone, '+', ''), '-', '') LIKE ?)
                ORDER BY o.date_added DESC LIMIT 10
            ");
            $orderStmt->execute([$customerId ?? 0, '%' . $phoneSuffix]);
            while ($ord = $orderStmt->fetch()) {
                $itemsStmt = $db->prepare("SELECT name, quantity FROM om_market_order_items WHERE order_id = ? LIMIT 5");
                $itemsStmt->execute([$ord['order_id']]);
                $orderItems = $itemsStmt->fetchAll();
                $itemsSummary = implode(', ', array_map(fn($i) => $i['quantity'] . 'x ' . $i['name'], $orderItems));

                $supportOrders[] = [
                    'order_id' => $ord['order_id'],
                    'order_number' => $ord['order_number'],
                    'status' => $ord['status'],
                    'total' => $ord['total'],
                    'date_added' => $ord['date_added'],
                    'partner_name' => $ord['partner_name'],
                    'items_summary' => $itemsSummary,
                ];
            }
            $context['support_orders'] = $supportOrders;
        }

        $savedAddresses = [];
        if ($customerId && in_array($step, ['get_address', 'confirm_order'])) {
            $addrStmt = $db->prepare("
                SELECT address_id, label, street, number, complement, neighborhood, city, state, lat, lng
                FROM om_customer_addresses WHERE customer_id = ? AND is_active = '1'
                ORDER BY is_default DESC LIMIT 5
            ");
            $addrStmt->execute([$customerId]);
            $savedAddresses = $addrStmt->fetchAll();
        }

        // Build system prompt
        $systemPrompt = buildWASystemPrompt($step, $storeName, $menuText, $items, $address, $payment, $customerName, $savedAddresses, $storeNames, $customerId, $lastOrderItems ?? [], $context);

        // Keep history manageable
        $recentHistory = array_slice($history, -16);
        $cleanHistory = cleanWAHistory($recentHistory);

        $result = $claude->send($systemPrompt, $cleanHistory, 800);

        if (!$result['success']) {
            error_log("[zapi-callcenter] Claude error: " . ($result['error'] ?? 'unknown'));
            return null;
        }

        $aiResponse = trim($result['text'] ?? '');
        error_log("[zapi-callcenter] AI step={$step} response: " . mb_substr($aiResponse, 0, 200));

        // Parse response for state transitions
        $newContext = parseWAResponse($aiResponse, $context, $db);
        $mainResponse = $newContext['cleaned_response'];

        // Add AI response to history
        $newContext['history'] = $history;
        $newContext['history'][] = ['role' => 'assistant', 'content' => $mainResponse];

        // Limit history size
        if (count($newContext['history']) > 20) {
            $newContext['history'] = array_slice($newContext['history'], -20);
        }

        $followUp = null;

        // When store is newly identified, send a menu preview
        if (($newContext['store_id'] ?? null) && !$storeId && $newContext['store_id'] !== $storeId) {
            $menuPreview = fetchMenuPreview($db, $newContext['store_id']);
            if ($menuPreview) {
                $followUp = "📋 *Cardapio " . ($newContext['store_name'] ?? '') . ":*\n\n" . $menuPreview . "\n\nO que voce gostaria de pedir?";
            }
        }

        // Handle order submission
        if ($newContext['step'] === 'submit_order' && !empty($newContext['confirmed'])) {
            $orderResult = submitWAOrder($db, $conversationId, $customerId, $customerName, $phone, $newContext);

            if ($orderResult['success']) {
                $orderNumber = $orderResult['order_number'];
                $total = number_format($orderResult['total'], 2, ',', '.');

                $mainResponse = "✅ *Pedido Confirmado!*\n\n"
                    . "Numero: *{$orderNumber}*\n"
                    . "Total: *R\${$total}*\n\n"
                    . "Voce vai receber um SMS com os detalhes. O restaurante ja esta preparando!\n\n"
                    . "Obrigada por pedir pelo SuperBora! 😊";

                $newContext['step'] = 'complete';
                $newContext['submitted_order_id'] = $orderResult['order_id'];

                return [
                    'response' => $mainResponse,
                    'follow_up' => null,
                    'context' => $newContext,
                    'order_submitted' => true,
                    'order_id' => $orderResult['order_id'],
                    'order_number' => $orderNumber,
                    'order_total' => $orderResult['total'],
                ];
            } else {
                $mainResponse = "Desculpe, houve um problema ao processar seu pedido: " . $orderResult['error'] . "\n\nQuer tentar novamente?";
                $newContext['step'] = 'confirm_order';
                $newContext['confirmed'] = false;
            }
        }

        // If the conversation is complete and user sends a new message, restart
        if ($step === 'complete') {
            $newContext = ['step' => 'greeting', 'items' => [], 'history' => [], 'message_count' => 1];
            $newContext['history'][] = ['role' => 'user', 'content' => $userMessage];
            // Re-run through Claude with fresh context
            return handleBotConversation($db, $conversationId, $userMessage, $newContext, $customerName, $customerId, $phone);
        }

        return [
            'response' => $mainResponse,
            'follow_up' => $followUp,
            'context' => $newContext,
        ];

    } catch (Exception $e) {
        error_log("[zapi-callcenter] Bot error: " . $e->getMessage());
        return null;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// WhatsApp-specific system prompt builder
// ═══════════════════════════════════════════════════════════════════════════

function buildWASystemPrompt(
    string $step, ?string $storeName, string $menuText, array $items,
    ?array $address, ?string $payment, ?string $customerName,
    array $savedAddresses, array $storeNames, ?int $customerId,
    array $lastOrderItems = [], array $context = []
): string {
    $hora = (int)date('H');
    $periodo = $hora < 12 ? 'bom dia' : ($hora < 18 ? 'boa tarde' : 'boa noite');

    $prompt = "Voce e a assistente virtual do SuperBora por WhatsApp. SuperBora e um app de delivery de comida.\n\n";
    $prompt .= "REGRAS OBRIGATORIAS:\n";
    $prompt .= "- Fale SEMPRE em portugues brasileiro, de forma amigavel e natural\n";
    $prompt .= "- Use emojis com moderacao para deixar a conversa agradavel\n";
    $prompt .= "- Respostas claras e organizadas, use *negrito* para destaques (sintaxe WhatsApp)\n";
    $prompt .= "- NUNCA invente precos ou produtos — use SOMENTE o cardapio fornecido\n";
    $prompt .= "- Se o cliente pedir algo que nao tem no cardapio, diga que nao esta disponivel e sugira algo parecido\n";
    $prompt .= "- Seja educado e eficiente como um atendente profissional\n";
    $prompt .= "- Maximo 500 caracteres por resposta (limitar para leitura no celular)\n";
    $prompt .= "- Hora atual: " . date('H:i') . " ({$periodo})\n\n";

    if ($customerName) {
        $prompt .= "CLIENTE: {$customerName}" . ($customerId ? " (cadastrado)" : " (nao cadastrado)") . "\n\n";
    }

    switch ($step) {
        case 'greeting':
        case 'identify_store':
            $prompt .= "ETAPA: Identificar restaurante\n";
            $prompt .= "- Se e a primeira mensagem, cumprimente e pergunte de qual restaurante quer pedir\n";
            $prompt .= "- Se o cliente ja disse um nome, faca match com a lista abaixo\n";
            $prompt .= "- Se encontrar, confirme o nome e avance\n";
            $prompt .= "- Se nao encontrar, mostre 5 opcoes populares e pergunte qual prefere\n\n";
            $prompt .= "RESTAURANTES:\n" . implode("\n", $storeNames) . "\n\n";
            $prompt .= "MARCADORES (inclua na resposta, serao removidos antes de enviar):\n";
            $prompt .= "- Se identificar o restaurante: [STORE:ID:nome]\n";
            $prompt .= "  Exemplo: [STORE:42:Pizzaria Bella]\n";
            break;

        case 'take_order':
            $prompt .= "ETAPA: Anotar pedido\n";
            $prompt .= "RESTAURANTE: *{$storeName}*\n\n";
            $prompt .= "CARDAPIO:\n{$menuText}\n\n";
            // Smart: returning customer suggestions
            if (!empty($lastOrderItems)) {
                $prompt .= "ULTIMO PEDIDO DO CLIENTE NESTA LOJA:\n";
                foreach ($lastOrderItems as $li) {
                    $prompt .= "- {$li['quantity']}x {$li['product_name']} R$" . number_format((float)$li['unit_price'], 2, ',', '.') . "\n";
                }
                $prompt .= "\n- Na primeira mensagem, pergunte: 'Vi que voce ja pediu [itens] antes. Quer repetir ou experimentar algo diferente?'\n\n";
            }
            if (!empty($items)) {
                $prompt .= "ITENS JA ANOTADOS:\n";
                $total = 0;
                foreach ($items as $item) {
                    $lineTotal = ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                    $total += $lineTotal;
                    $prompt .= "- {$item['quantity']}x {$item['name']} R$" . number_format($lineTotal, 2, ',', '.') . "\n";
                }
                $prompt .= "Subtotal: R$" . number_format($total, 2, ',', '.') . "\n\n";
            }
            $prompt .= "- Quando o cliente disser um produto, identifique no cardapio, confirme nome e preco\n";
            $prompt .= "- Pergunte quantidade se nao informada\n";
            $prompt .= "- Se o cliente pedir algo que nao tem, sugira o mais parecido do cardapio\n";
            $prompt .= "- Sugira complementos naturais (ex: bebida com comida, sobremesa)\n";
            $prompt .= "- Apos cada item: 'Mais alguma coisa?'\n";
            $prompt .= "- Quando disser que acabou (so isso, e so, nao, pronto), finalize\n\n";
            $prompt .= "MARCADORES:\n";
            $prompt .= "- Para cada item: [ITEM:product_id:quantidade:preco:nome]\n";
            $prompt .= "  Ex: [ITEM:123:2:12.90:Coxinha de Frango]\n";
            $prompt .= "- Quando finalizar itens: [NEXT_STEP]\n";
            break;

        case 'get_address':
            $prompt .= "ETAPA: Endereco de entrega\n";
            $prompt .= "RESTAURANTE: *{$storeName}*\n\n";
            // If CEP was already resolved
            if (!empty($address['from_cep'])) {
                $prompt .= "ENDERECO ENCONTRADO PELO CEP ({$address['cep']}):\n";
                $prompt .= "Rua: {$address['street']}, Bairro: {$address['neighborhood']}, Cidade: {$address['city']}-{$address['state']}\n";
                $prompt .= "- Confirme com o cliente e pergunte o NUMERO da casa/apto\n";
                $prompt .= "- Quando tiver: [ADDRESS_TEXT:rua, numero - bairro, cidade] e [NEXT_STEP]\n\n";
            } elseif (!empty($savedAddresses)) {
                $prompt .= "ENDERECOS SALVOS:\n";
                foreach ($savedAddresses as $i => $addr) {
                    $num = $i + 1;
                    $label = $addr['label'] ?? '';
                    $full = ($addr['street'] ?? '') . ', ' . ($addr['number'] ?? '') . ' - ' . ($addr['neighborhood'] ?? '');
                    $prompt .= "{$num}. {$label}: {$full}\n";
                }
                $prompt .= "\n- Pergunte se quer entregar em um dos enderecos salvos ou outro\n";
                $prompt .= "- Se escolher salvo: [ADDRESS:indice]\n";
            } else {
                $prompt .= "- Peca o endereco completo (rua, numero, bairro, cidade)\n";
                $prompt .= "- O cliente pode enviar o CEP tambem — o sistema busca automaticamente\n";
            }
            $prompt .= "- Se o cliente enviar um CEP (8 digitos): [CEP:00000000]\n";
            $prompt .= "- Com endereco definido: [ADDRESS_TEXT:endereco completo] e [NEXT_STEP]\n";
            break;

        case 'get_payment':
            $prompt .= "ETAPA: Forma de pagamento\n";
            $prompt .= "- Opcoes: Dinheiro, PIX, Cartao na maquininha\n";
            $prompt .= "- Se dinheiro, pergunte se precisa de troco\n";
            $prompt .= "- Marcadores:\n";
            $prompt .= "  [PAYMENT:dinheiro], [PAYMENT:pix], [PAYMENT:credit_card]\n";
            $prompt .= "  Com troco: [PAYMENT:dinheiro:100]\n";
            $prompt .= "- Depois: [NEXT_STEP]\n";
            break;

        case 'confirm_order':
            $prompt .= "ETAPA: Confirmar pedido\n";
            $prompt .= "RESTAURANTE: *{$storeName}*\n\n";
            $prompt .= "ITENS:\n";
            $subtotal = 0;
            foreach ($items as $item) {
                $lineTotal = ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                $subtotal += $lineTotal;
                $prompt .= "- {$item['quantity']}x {$item['name']} R$" . number_format($lineTotal, 2, ',', '.') . "\n";
            }
            $deliveryFee = 5.0;
            $serviceFee = round($subtotal * 0.08, 2);
            $total = $subtotal + $deliveryFee + $serviceFee;
            $prompt .= "\nSubtotal: R$" . number_format($subtotal, 2, ',', '.');
            $prompt .= "\nEntrega: R$" . number_format($deliveryFee, 2, ',', '.');
            $prompt .= "\nTaxa: R$" . number_format($serviceFee, 2, ',', '.');
            $prompt .= "\n*Total: R$" . number_format($total, 2, ',', '.') . "*\n\n";

            if ($address) {
                $prompt .= "ENDERECO: " . ($address['full'] ?? ($address['street'] ?? 'N/A')) . "\n";
            }
            if ($payment) {
                $prompt .= "PAGAMENTO: {$payment}\n\n";
            }

            $prompt .= "- Mostre o resumo completo e bonito do pedido\n";
            $prompt .= "- Pergunte: 'Posso confirmar?'\n";
            $prompt .= "- Se confirmar (sim, pode, confirma, isso, correto, ok): [CONFIRMED]\n";
            $prompt .= "- Se quiser mudar algo, volte para a etapa adequada\n";
            break;

        case 'support':
            $prompt .= "ETAPA: Suporte ao cliente\n";
            $prompt .= "O cliente quer ajuda com um pedido existente (status, cancelamento, problema, etc).\n\n";
            $prompt .= "Voce pode ajudar com:\n";
            $prompt .= "- Ver status de um pedido\n";
            $prompt .= "- Cancelar um pedido (apenas se status for 'confirmado' ou 'pendente')\n";
            $prompt .= "- Informar tempo estimado\n";
            $prompt .= "- Responder perguntas sobre pedidos\n";
            $prompt .= "- Se o problema for complexo, oferecer falar com atendente\n\n";
            if (!empty($context['support_orders'])) {
                $prompt .= "PEDIDOS RECENTES DO CLIENTE:\n";
                foreach ($context['support_orders'] as $ord) {
                    $statusMap = [
                        'pendente' => 'Pendente',
                        'confirmado' => 'Confirmado (aguardando preparo)',
                        'preparando' => 'Em preparo',
                        'pronto' => 'Pronto para entrega',
                        'saiu_entrega' => 'Saiu para entrega',
                        'entregue' => 'Entregue',
                        'cancelled' => 'Cancelado',
                        'refunded' => 'Reembolsado',
                    ];
                    $statusLabel = $statusMap[$ord['status']] ?? $ord['status'];
                    $prompt .= "- Pedido *#{$ord['order_number']}* | {$ord['partner_name']} | Status: {$statusLabel} | Total: R$" . number_format((float)$ord['total'], 2, ',', '.') . " | Data: {$ord['date_added']}\n";
                    if (!empty($ord['items_summary'])) {
                        $prompt .= "  Itens: {$ord['items_summary']}\n";
                    }
                }
                $prompt .= "\n";
            } else {
                $prompt .= "NENHUM PEDIDO ENCONTRADO para este numero.\n";
                $prompt .= "- Informe que nao encontrou pedidos\n";
                $prompt .= "- Pergunte se quer fazer um novo pedido ou falar com atendente\n\n";
            }
            $prompt .= "MARCADORES:\n";
            $prompt .= "- Para cancelar pedido: [CANCEL_ORDER:SB00123]\n";
            $prompt .= "  SOMENTE se status for 'confirmado' ou 'pendente'\n";
            $prompt .= "- Para voltar a fazer pedido: [SWITCH_TO_ORDER]\n";
            break;
    }

    return $prompt;
}

// ═══════════════════════════════════════════════════════════════════════════
// Parse AI response markers (same logic as voice, adapted for WhatsApp)
// ═══════════════════════════════════════════════════════════════════════════

function parseWAResponse(string $response, array $context, PDO $db): array {
    $newContext = $context;
    $cleaned = $response;

    // Parse [STORE:ID:name] — also handle [STORE:ID:142:name] if Claude includes literal "ID:"
    if (preg_match('/\[STORE:(?:ID:)?(\d+):([^\]]+)\]/', $response, $m)) {
        $newContext['store_id'] = (int)$m[1];
        $newContext['store_name'] = trim($m[2]);
        $newContext['step'] = 'take_order';
        $cleaned = preg_replace('/\[STORE:(?:ID:)?\d+:[^\]]+\]/', '', $cleaned);
    }

    // Parse [ITEM:product_id:qty:price:name]
    if (preg_match_all('/\[ITEM:(\d+):(\d+):([\d.]+):([^\]]+)\]/', $response, $matches, PREG_SET_ORDER)) {
        if (!isset($newContext['items'])) $newContext['items'] = [];
        foreach ($matches as $m) {
            $newContext['items'][] = [
                'product_id' => (int)$m[1],
                'quantity' => (int)$m[2],
                'price' => (float)$m[3],
                'name' => trim($m[4]),
                'options' => [],
                'notes' => '',
            ];
        }
        $cleaned = preg_replace('/\[ITEM:\d+:\d+:[\d.]+:[^\]]+\]/', '', $cleaned);
    }

    // Parse [ADDRESS:index]
    if (preg_match('/\[ADDRESS:(\d+)\]/', $response, $m)) {
        $newContext['address_index'] = (int)$m[1] - 1;
        $cleaned = preg_replace('/\[ADDRESS:\d+\]/', '', $cleaned);
    }

    // Parse [ADDRESS_TEXT:text]
    if (preg_match('/\[ADDRESS_TEXT:([^\]]+)\]/', $response, $m)) {
        $newContext['address'] = ['full' => trim($m[1]), 'manual' => true];
        $cleaned = preg_replace('/\[ADDRESS_TEXT:[^\]]+\]/', '', $cleaned);
    }

    // Parse [CEP:12345678] — auto-lookup address via ViaCEP
    if (preg_match('/\[CEP:(\d{5,8})\]/', $response, $m)) {
        $cepData = lookupCepWA($m[1]);
        if ($cepData) {
            $newContext['cep_data'] = $cepData;
            $newContext['address'] = [
                'street' => $cepData['street'],
                'neighborhood' => $cepData['neighborhood'],
                'city' => $cepData['city'],
                'state' => $cepData['state'],
                'cep' => $cepData['cep'],
                'from_cep' => true,
            ];
            if (empty($newContext['store_id'])) {
                $nearbyStores = findStoresByCepWA($db, $m[1]);
                if (!empty($nearbyStores)) {
                    $newContext['nearby_stores'] = $nearbyStores;
                }
            }
        }
        $cleaned = preg_replace('/\[CEP:\d+\]/', '', $cleaned);
    }

    // Parse [PAYMENT:method:change?]
    if (preg_match('/\[PAYMENT:(\w+)(?::(\d+))?\]/', $response, $m)) {
        $newContext['payment_method'] = $m[1];
        if (!empty($m[2])) {
            $newContext['payment_change'] = (float)$m[2];
        }
        $cleaned = preg_replace('/\[PAYMENT:\w+(?::\d+)?\]/', '', $cleaned);
    }

    // Parse [NEXT_STEP]
    if (strpos($response, '[NEXT_STEP]') !== false) {
        $currentStep = $newContext['step'] ?? 'greeting';
        $stepOrder = ['greeting', 'identify_store', 'take_order', 'get_address', 'get_payment', 'confirm_order', 'submit_order'];
        $currentIdx = array_search($currentStep, $stepOrder);
        if ($currentIdx !== false && $currentIdx < count($stepOrder) - 1) {
            $newContext['step'] = $stepOrder[$currentIdx + 1];
        }
        $cleaned = str_replace('[NEXT_STEP]', '', $cleaned);
    }

    // Parse [CONFIRMED]
    if (strpos($response, '[CONFIRMED]') !== false) {
        $newContext['confirmed'] = true;
        $newContext['step'] = 'submit_order';
        $cleaned = str_replace('[CONFIRMED]', '', $cleaned);
    }

    // Parse [CANCEL_ORDER:SB00123]
    if (preg_match('/\[CANCEL_ORDER:([^\]]+)\]/', $response, $m)) {
        $orderNumber = trim($m[1]);
        $cancelResult = cancelOrderByNumberWA($db, $orderNumber);
        if ($cancelResult['success']) {
            $cleaned = preg_replace('/\[CANCEL_ORDER:[^\]]+\]/', '', $cleaned);
            $cleaned .= "\n\n✅ Pedido {$orderNumber} cancelado com sucesso!";
        } else {
            $cleaned = preg_replace('/\[CANCEL_ORDER:[^\]]+\]/', '', $cleaned);
            $cleaned .= "\n\n❌ " . $cancelResult['error'];
        }
    }

    // Parse [ORDER_STATUS:...]
    if (preg_match('/\[ORDER_STATUS:[^\]]+\]/', $response)) {
        $cleaned = preg_replace('/\[ORDER_STATUS:[^\]]+\]/', '', $cleaned);
    }

    // Parse [SWITCH_TO_ORDER]
    if (strpos($response, '[SWITCH_TO_ORDER]') !== false) {
        $newContext['step'] = 'greeting';
        $newContext['items'] = [];
        $newContext['store_id'] = null;
        $newContext['store_name'] = null;
        $newContext['address'] = null;
        $newContext['payment_method'] = null;
        $cleaned = str_replace('[SWITCH_TO_ORDER]', '', $cleaned);
    }

    // Clean up whitespace
    $newContext['cleaned_response'] = trim(preg_replace('/\n{3,}/', "\n\n", $cleaned));
    return $newContext;
}

// ═══════════════════════════════════════════════════════════════════════════
// Fetch store menu (for Claude context)
// ═══════════════════════════════════════════════════════════════════════════

function lookupCepWA(string $cep): ?array {
    $cep = preg_replace('/\D/', '', $cep);
    if (strlen($cep) !== 8) return null;

    $cacheFile = "/tmp/viacep_{$cep}.json";
    $json = false;
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $json = @file_get_contents($cacheFile);
    }
    if (!$json) {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $json = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/", false, $ctx);
        if ($json) @file_put_contents($cacheFile, $json);
    }
    if (!$json) return null;
    $data = json_decode($json, true);
    if (!$data || !empty($data['erro'])) return null;
    return [
        'street' => $data['logradouro'] ?? '',
        'neighborhood' => $data['bairro'] ?? '',
        'city' => $data['localidade'] ?? '',
        'state' => $data['uf'] ?? '',
        'cep' => $cep,
    ];
}

function findStoresByCepWA(PDO $db, string $cep): array {
    $cep = preg_replace('/\D/', '', $cep);
    if (strlen($cep) !== 8) return [];
    $cep3 = substr($cep, 0, 3);
    $cep5 = substr($cep, 0, 5);
    $stores = [];
    $stmt = $db->query("SELECT partner_id, name, cep, cep_inicio, cep_fim FROM om_market_partners WHERE status = 1 ORDER BY rating DESC NULLS LAST LIMIT 50");
    foreach ($stmt->fetchAll() as $p) {
        $inicio = preg_replace('/\D/', '', $p['cep_inicio'] ?? '');
        $fim = preg_replace('/\D/', '', $p['cep_fim'] ?? '');
        if ($inicio && $fim) {
            $len = strlen($inicio);
            $check = $len === 5 ? $cep5 : ($len === 3 ? $cep3 : $cep);
            if (intval($check) >= intval($inicio) && intval($check) <= intval($fim)) {
                $stores[] = ['id' => (int)$p['partner_id'], 'name' => $p['name']];
                continue;
            }
        }
        $partnerCep = preg_replace('/\D/', '', $p['cep'] ?? '');
        if ($partnerCep && substr($partnerCep, 0, 3) === $cep3) {
            $stores[] = ['id' => (int)$p['partner_id'], 'name' => $p['name']];
        }
    }
    return $stores;
}

function fetchStoreMenuWA(PDO $db, int $storeId): string {
    $stmt = $db->prepare("
        SELECT c.name AS category, p.product_id, p.name, p.price, p.description
        FROM om_market_products p
        LEFT JOIN om_market_categories c ON c.category_id = p.category_id
        WHERE p.partner_id = ? AND p.status = 1
        ORDER BY c.sort_order, p.sort_order
        LIMIT 60
    ");
    $stmt->execute([$storeId]);
    $products = $stmt->fetchAll();
    $text = '';
    $lastCat = '';
    foreach ($products as $p) {
        $cat = $p['category'] ?? 'Outros';
        if ($cat !== $lastCat) {
            $text .= "\n=={$cat}==\n";
            $lastCat = $cat;
        }
        $text .= "ID:{$p['product_id']} {$p['name']} R$" . number_format((float)$p['price'], 2, ',', '.');
        if ($p['description']) $text .= " ({$p['description']})";
        $text .= "\n";
    }
    return $text ?: 'Cardapio nao disponivel';
}

// ═══════════════════════════════════════════════════════════════════════════
// Fetch menu preview for WhatsApp (formatted nicely for the customer)
// ═══════════════════════════════════════════════════════════════════════════

function fetchMenuPreview(PDO $db, int $storeId): ?string {
    $stmt = $db->prepare("
        SELECT c.name AS category, p.name, p.price
        FROM om_market_products p
        LEFT JOIN om_market_categories c ON c.category_id = p.category_id
        WHERE p.partner_id = ? AND p.status = 1
        ORDER BY c.sort_order, p.sort_order
        LIMIT 20
    ");
    $stmt->execute([$storeId]);
    $products = $stmt->fetchAll();

    if (empty($products)) return null;

    $text = '';
    $lastCat = '';
    foreach ($products as $p) {
        $cat = $p['category'] ?? 'Outros';
        if ($cat !== $lastCat) {
            $text .= "\n*{$cat}*\n";
            $lastCat = $cat;
        }
        $price = number_format((float)$p['price'], 2, ',', '.');
        $text .= "  {$p['name']} — R\${$price}\n";
    }

    return trim($text);
}

// ═══════════════════════════════════════════════════════════════════════════
// Clean conversation history for Claude API
// ═══════════════════════════════════════════════════════════════════════════

function cleanWAHistory(array $history): array {
    $clean = [];
    $lastRole = null;
    foreach ($history as $msg) {
        if (!isset($msg['role']) || !isset($msg['content'])) continue;
        if (empty(trim($msg['content']))) continue;
        if ($msg['role'] === $lastRole && !empty($clean)) {
            $clean[count($clean) - 1]['content'] .= "\n" . $msg['content'];
        } else {
            $clean[] = ['role' => $msg['role'], 'content' => $msg['content']];
            $lastRole = $msg['role'];
        }
    }
    if (!empty($clean) && $clean[0]['role'] !== 'user') {
        array_unshift($clean, ['role' => 'user', 'content' => 'Ola']);
    }
    if (empty($clean)) {
        $clean[] = ['role' => 'user', 'content' => 'Ola, quero fazer um pedido'];
    }
    return $clean;
}

// ═══════════════════════════════════════════════════════════════════════════
// Submit order from WhatsApp bot
// ═══════════════════════════════════════════════════════════════════════════

function submitWAOrder(PDO $db, int $conversationId, ?int $customerId, ?string $customerName, string $phone, array $context): array {
    try {
        $storeId = $context['store_id'] ?? null;
        $items = $context['items'] ?? [];
        $address = $context['address'] ?? null;
        $paymentMethod = $context['payment_method'] ?? 'dinheiro';
        $paymentChange = $context['payment_change'] ?? null;

        if (!$storeId || empty($items)) {
            return ['success' => false, 'error' => 'Loja ou itens nao definidos'];
        }

        // Get store info
        $stmt = $db->prepare("SELECT name, delivery_fee FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$storeId]);
        $store = $stmt->fetch();
        if (!$store) return ['success' => false, 'error' => 'Loja nao encontrada'];

        // Resolve saved address if needed
        if (isset($context['address_index']) && $customerId && !$address) {
            $addrStmt = $db->prepare("
                SELECT address_id, label, street, number, complement, neighborhood, city, state, zipcode, lat, lng
                FROM om_customer_addresses WHERE customer_id = ? AND is_active = '1'
                ORDER BY is_default DESC LIMIT 5
            ");
            $addrStmt->execute([$customerId]);
            $addrs = $addrStmt->fetchAll();
            $idx = (int)$context['address_index'];
            if (isset($addrs[$idx])) {
                $a = $addrs[$idx];
                $address = [
                    'address_id' => (int)$a['address_id'],
                    'street' => $a['street'], 'number' => $a['number'],
                    'complement' => $a['complement'], 'neighborhood' => $a['neighborhood'],
                    'city' => $a['city'], 'state' => $a['state'], 'zipcode' => $a['zipcode'],
                    'lat' => $a['lat'], 'lng' => $a['lng'],
                    'full' => $a['street'] . ', ' . $a['number'] . ' - ' . $a['neighborhood'] . ', ' . $a['city'],
                ];
            }
        }

        if (!$address) {
            $address = ['full' => 'Endereco a confirmar', 'manual' => true];
        }

        // Calculate totals
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
        }
        $deliveryFee = (float)($store['delivery_fee'] ?? 5.00);
        $serviceFee = round($subtotal * 0.08, 2);
        $total = round($subtotal + $deliveryFee + $serviceFee, 2);

        $deliveryAddress = $address['full'] ?? 'N/A';
        $codigoEntrega = strtoupper(bin2hex(random_bytes(3)));

        // Format phone for order
        $customerPhone = '+' . ltrim($phone, '+');

        $db->beginTransaction();

        // Create order
        $stmt = $db->prepare("
            INSERT INTO om_market_orders (
                customer_id, partner_id, customer_name, customer_phone,
                status, subtotal, delivery_fee, service_fee, total,
                delivery_address, shipping_address, shipping_city, shipping_state, shipping_cep,
                shipping_lat, shipping_lng,
                forma_pagamento, payment_status, codigo_entrega,
                notes, source, date_added
            ) VALUES (
                ?, ?, ?, ?,
                'confirmado', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente', ?, ?, 'callcenter_whatsapp', NOW()
            ) RETURNING order_id
        ");
        $stmt->execute([
            $customerId, $storeId, $customerName, $customerPhone,
            $subtotal, $deliveryFee, $serviceFee, $total,
            $deliveryAddress,
            $address['street'] ?? '', $address['city'] ?? '', $address['state'] ?? '', $address['zipcode'] ?? '',
            $address['lat'] ?? null, $address['lng'] ?? null,
            $paymentMethod, $codigoEntrega,
            "Pedido via WhatsApp IA - conversa #{$conversationId}",
        ]);
        $orderId = (int)$stmt->fetch()['order_id'];

        $orderNumber = 'SB' . str_pad($orderId, 5, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE om_market_orders SET order_number = ? WHERE order_id = ?")->execute([$orderNumber, $orderId]);

        // Insert items
        $stmtItem = $db->prepare("
            INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($items as $item) {
            $qty = (int)($item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $itemTotal = round($price * $qty, 2);
            $stmtItem->execute([
                $orderId, $item['product_id'] ?? null, $item['name'],
                $qty, $price, $itemTotal, $item['notes'] ?? null,
            ]);
        }

        // Timeline
        $db->prepare("
            INSERT INTO om_order_timeline (order_id, status, description, actor_type, created_at)
            VALUES (?, 'confirmado', 'Pedido criado via WhatsApp IA', 'system', NOW())
        ")->execute([$orderId]);

        $db->commit();

        // Send SMS confirmation
        try {
            require_once __DIR__ . '/../helpers/callcenter-sms.php';
            sendOrderSummary($customerPhone, [
                'partner_name' => $store['name'],
                'items' => $items,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'service_fee' => $serviceFee,
                'discount' => 0,
                'total' => $total,
                'payment_method' => $paymentMethod,
                'payment_change' => $paymentChange,
                'submitted_order_id' => $orderId,
            ]);
        } catch (Exception $e) {
            error_log("[zapi-callcenter] SMS error: " . $e->getMessage());
        }

        // Notify customer in-app
        try {
            require_once __DIR__ . '/../helpers/notify.php';
            if ($customerId) {
                notifyCustomer($db, $customerId,
                    "Novo pedido {$orderNumber}",
                    "Seu pedido foi criado pela assistente WhatsApp SuperBora. Total: R$" . number_format($total, 2, ',', '.'),
                    '/pedidos', ['order_id' => $orderId, 'type' => 'order_created']
                );
            }
        } catch (Exception $e) {
            error_log("[zapi-callcenter] Notify error: " . $e->getMessage());
        }

        error_log("[zapi-callcenter] Order created: {$orderNumber} total=R\${$total} items=" . count($items));

        return [
            'success' => true,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'total' => $total,
        ];

    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        error_log("[zapi-callcenter] Submit error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function cancelOrderByNumberWA(PDO $db, string $orderNumber): array {
    try {
        $stmt = $db->prepare("SELECT order_id, status FROM om_market_orders WHERE order_number = ? LIMIT 1");
        $stmt->execute([$orderNumber]);
        $order = $stmt->fetch();

        if (!$order) {
            return ['success' => false, 'error' => "Pedido {$orderNumber} nao encontrado."];
        }

        $cancellable = ['pendente', 'confirmado'];
        if (!in_array($order['status'], $cancellable)) {
            $statusLabels = [
                'preparando' => 'ja esta sendo preparado',
                'pronto' => 'ja esta pronto',
                'saiu_entrega' => 'ja saiu para entrega',
                'entregue' => 'ja foi entregue',
                'cancelled' => 'ja esta cancelado',
            ];
            $reason = $statusLabels[$order['status']] ?? 'nao pode ser cancelado no status atual';
            return ['success' => false, 'error' => "Nao foi possivel cancelar {$orderNumber} porque {$reason}."];
        }

        $db->beginTransaction();
        $db->prepare("UPDATE om_market_orders SET status = 'cancelled' WHERE order_id = ?")->execute([$order['order_id']]);
        $db->prepare("
            INSERT INTO om_order_timeline (order_id, status, description, actor_type, created_at)
            VALUES (?, 'cancelled', 'Cancelado pelo cliente via WhatsApp IA', 'system', NOW())
        ")->execute([$order['order_id']]);
        $db->commit();

        error_log("[zapi-callcenter] Order {$orderNumber} cancelled via WhatsApp support");
        return ['success' => true];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("[zapi-callcenter] Cancel error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Erro ao cancelar: ' . $e->getMessage()];
    }
}
