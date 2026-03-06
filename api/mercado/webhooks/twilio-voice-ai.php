<?php
/**
 * POST /api/mercado/webhooks/twilio-voice-ai.php
 *
 * Claude AI Multi-Turn Voice Conversation Handler
 *
 * This is the brain of the phone ordering system. It handles a multi-turn
 * voice conversation via Twilio <Gather> loops:
 *
 * Flow:
 *   1. Identify store (from initial speech or ask)
 *   2. Show popular items / ask what they want
 *   3. Build order items one by one
 *   4. Confirm full order with prices
 *   5. Get delivery address
 *   6. Get payment method
 *   7. Submit order + send SMS confirmation
 *
 * State is tracked in om_callcenter_calls.ai_context JSONB field.
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
require_once __DIR__ . '/../helpers/claude-client.php';
require_once __DIR__ . '/../helpers/ws-callcenter-broadcast.php';

header('Content-Type: text/xml; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Method not allowed</Say></Response>';
    exit;
}

// -- Validate Twilio Signature --
$authToken = $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: '';
$twilioSignature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

if (!empty($authToken) && !empty($twilioSignature)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $fullUrl = $scheme . '://' . $host . strtok($uri, '?');

    $params = $_POST;
    ksort($params);
    $dataString = $fullUrl;
    foreach ($params as $key => $value) {
        $dataString .= $key . $value;
    }
    $expectedSignature = base64_encode(hash_hmac('sha1', $dataString, $authToken, true));
    if (!hash_equals($expectedSignature, $twilioSignature)) {
        error_log("[twilio-voice-ai] Rejected: invalid signature");
        http_response_code(403);
        echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Unauthorized</Say></Response>';
        exit;
    }
}

// -- Parse Input --
$callSid = $_POST['CallSid'] ?? '';
$speechResult = $_POST['SpeechResult'] ?? '';
$digits = $_POST['Digits'] ?? '';
$callerPhone = $_POST['From'] ?? '';

error_log("[twilio-voice-ai] CallSid={$callSid} Speech=\"{$speechResult}\" Digits={$digits}");

// Build self URL for Gather action
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$selfUrl = $scheme . '://' . $host . strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$routeUrl = str_replace('twilio-voice-ai.php', 'twilio-voice-route.php', $selfUrl);

try {
    $db = getDB();
    $claude = new ClaudeClient('claude-sonnet-4-20250514', 30, 0);

    // -- Get call record and AI context --
    $stmt = $db->prepare("
        SELECT id, customer_id, customer_name, customer_phone, store_identified, notes
        FROM om_callcenter_calls
        WHERE twilio_call_sid = ?
        LIMIT 1
    ");
    $stmt->execute([$callSid]);
    $call = $stmt->fetch();

    if (!$call) {
        error_log("[twilio-voice-ai] No call record for CallSid={$callSid}");
        respondTwiml("Desculpe, houve um erro. Vou transferir voce para um atendente.", true);
    }

    $callId = (int)$call['id'];
    $customerName = $call['customer_name'];
    $customerId = $call['customer_id'] ? (int)$call['customer_id'] : null;

    // Load AI context from a separate column or notes JSON
    $aiContext = loadAiContext($db, $callId);
    $step = $aiContext['step'] ?? 'identify_store';
    $conversationHistory = $aiContext['history'] ?? [];

    // Track user input
    $userInput = $speechResult ?: ($digits ?: '');

    // Check for transfer to agent keywords (only direct "I want a human" requests)
    if (!empty($userInput)) {
        $lowerInput = mb_strtolower($userInput, 'UTF-8');
        $transferKeywords = ['atendente', 'agente', 'pessoa', 'humano', 'operador'];
        foreach ($transferKeywords as $kw) {
            if (mb_strpos($lowerInput, $kw) !== false) {
                transferToAgent($db, $callId, $callerPhone, $customerName, $customerId, $routeUrl);
                exit;
            }
        }

        // Detect support intents (order status, cancel, help) — redirect to AI support mode
        $supportKeywords = ['status', 'cancelar', 'cancela', 'rastrear', 'rastreio', 'cadê meu pedido', 'cade meu pedido', 'onde ta', 'onde está', 'meu pedido', 'reclamação', 'reclamacao', 'problema', 'reembolso', 'devolver'];
        foreach ($supportKeywords as $sk) {
            if (mb_strpos($lowerInput, $sk) !== false) {
                $aiContext['step'] = 'support';
                $step = 'support';
                break;
            }
        }
    }

    if ($digits === '0') {
        transferToAgent($db, $callId, $callerPhone, $customerName, $customerId, $routeUrl);
        exit;
    }

    // Add user message to history
    if (!empty($userInput)) {
        $conversationHistory[] = ['role' => 'user', 'content' => $userInput];
    }

    // -- Build context for Claude --
    $storeId = $aiContext['store_id'] ?? null;
    $storeName = $aiContext['store_name'] ?? $call['store_identified'] ?? null;
    $draftItems = $aiContext['items'] ?? [];
    $address = $aiContext['address'] ?? null;
    $paymentMethod = $aiContext['payment_method'] ?? null;

    // Fetch store catalog if we have a store
    $menuText = '';
    if ($storeId) {
        $menuText = fetchStoreMenu($db, $storeId);
    }

    // Fetch available stores for matching — prioritize customer's previous orders
    $storeNames = [];
    if ($step === 'identify_store' || !$storeId) {
        // Get stores customer has ordered from before (favorites first)
        $favoriteStoreIds = [];
        if ($customerId) {
            $favStmt = $db->prepare("
                SELECT DISTINCT o.partner_id, p.name, COUNT(*) as order_count, MAX(o.created_at) as last_order
                FROM om_market_orders o
                JOIN om_market_partners p ON p.partner_id = o.partner_id
                WHERE o.customer_id = ? AND o.status NOT IN ('cancelled','refunded')
                GROUP BY o.partner_id, p.name
                ORDER BY order_count DESC, last_order DESC
                LIMIT 5
            ");
            $favStmt->execute([$customerId]);
            while ($fav = $favStmt->fetch()) {
                $storeNames[] = $fav['name'] . ' (ID:' . $fav['partner_id'] . ') [pediu ' . $fav['order_count'] . 'x, favorito]';
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

    // Fetch customer's last order items for smart suggestions
    $lastOrderItems = [];
    if ($customerId && $storeId && $step === 'take_order' && empty($draftItems)) {
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

    // Fetch customer's recent orders for support mode
    if ($step === 'support') {
        $supportOrders = [];
        $phoneClean = preg_replace('/\D/', '', $callerPhone);
        $phoneSuffix = substr($phoneClean, -11);
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
            // Get items summary for each order
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
        $aiContext['support_orders'] = $supportOrders;
    }

    // Fetch customer addresses if we have a customer
    $savedAddresses = [];
    if ($customerId && ($step === 'get_address' || $step === 'confirm_order')) {
        $addrStmt = $db->prepare("
            SELECT address_id, label, street, number, complement, neighborhood, city, state, lat, lng
            FROM om_customer_addresses WHERE customer_id = ? AND is_active = '1'
            ORDER BY is_default DESC LIMIT 5
        ");
        $addrStmt->execute([$customerId]);
        $savedAddresses = $addrStmt->fetchAll();
    }

    // -- Build system prompt --
    $systemPrompt = buildSystemPrompt($step, $storeName, $menuText, $draftItems, $address, $paymentMethod, $customerName, $savedAddresses, $storeNames, $lastOrderItems ?? [], $aiContext);

    // -- Call Claude --
    // Keep conversation history manageable (last 8 turns)
    $recentHistory = array_slice($conversationHistory, -16);

    // Ensure alternating roles
    $cleanHistory = cleanConversationHistory($recentHistory);

    $result = $claude->send($systemPrompt, $cleanHistory, 600);

    if (!$result['success']) {
        error_log("[twilio-voice-ai] Claude error: " . ($result['error'] ?? 'unknown'));
        respondTwiml("Desculpe, estou com dificuldade para processar. Vou transferir para um atendente.", true);
    }

    $aiResponse = trim($result['text'] ?? '');
    error_log("[twilio-voice-ai] AI response: " . mb_substr($aiResponse, 0, 200));

    // -- Parse AI response for state transitions --
    $newContext = parseAiResponse($aiResponse, $aiContext, $db);
    $aiResponse = $newContext['cleaned_response'];

    // Add AI response to history
    $newContext['history'] = $conversationHistory;
    $newContext['history'][] = ['role' => 'assistant', 'content' => $aiResponse];

    // Check if order should be submitted
    if ($newContext['step'] === 'submit_order' && !empty($newContext['confirmed'])) {
        $orderResult = submitAiOrder($db, $callId, $customerId, $customerName, $callerPhone, $newContext);
        if ($orderResult['success']) {
            $orderNumber = $orderResult['order_number'];
            $total = number_format($orderResult['total'], 2, ',', '.');
            $finalMsg = "Perfeito! Seu pedido numero {$orderNumber} foi criado com sucesso! "
                . "O total e {$total} reais. "
                . "Voce vai receber um SMS com todos os detalhes do pedido. "
                . "Obrigado por pedir pelo SuperBora! Tenha um otimo dia!";

            // Broadcast
            ccBroadcastDashboard('ai_order_completed', [
                'call_id' => $callId,
                'order_id' => $orderResult['order_id'],
                'order_number' => $orderNumber,
                'total' => $orderResult['total'],
                'customer_name' => $customerName,
            ]);

            // Update call
            $db->prepare("UPDATE om_callcenter_calls SET status = 'completed', order_id = ?, ended_at = NOW() WHERE id = ?")
               ->execute([$orderResult['order_id'], $callId]);

            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo '<Say voice="Polly.Camila" language="pt-BR">' . escXml($finalMsg) . '</Say>';
            echo '<Hangup/>';
            echo '</Response>';

            saveAiContext($db, $callId, $newContext);
            exit;
        } else {
            $aiResponse = "Desculpe, houve um problema ao processar seu pedido: " . $orderResult['error'] . ". Quer tentar novamente?";
            $newContext['step'] = 'confirm_order';
            $newContext['confirmed'] = false;
        }
    }

    // Save context
    saveAiContext($db, $callId, $newContext);

    // -- Respond with TwiML --
    // If step is complete (hangup), just say and hangup
    if ($newContext['step'] === 'complete') {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo '<Say voice="Polly.Camila" language="pt-BR">' . escXml($aiResponse) . '</Say>';
        echo '<Hangup/>';
        echo '</Response>';
    } else {
        // Continue conversation with Gather
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" numDigits="1">';
        echo '<Say voice="Polly.Camila" language="pt-BR">' . escXml($aiResponse) . '</Say>';
        echo '</Gather>';
        // No input fallback — ask again
        echo '<Say voice="Polly.Camila" language="pt-BR">Nao consegui ouvir. Pode repetir por favor? Ou pressione zero para falar com um atendente.</Say>';
        echo '<Redirect method="POST">' . escXml($selfUrl) . '</Redirect>';
        echo '</Response>';
    }

} catch (Exception $e) {
    error_log("[twilio-voice-ai] Error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="Polly.Camila" language="pt-BR">Desculpe, ocorreu um erro. Vou transferir voce para um atendente.</Say>';
    echo '<Redirect method="POST">' . escXml($routeUrl) . '?Digits=0</Redirect>';
    echo '</Response>';
}

// ═══════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════

function escXml(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function respondTwiml(string $message, bool $transferToAgent = false): void {
    global $routeUrl;
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="Polly.Camila" language="pt-BR">' . escXml($message) . '</Say>';
    if ($transferToAgent) {
        echo '<Redirect method="POST">' . escXml($routeUrl) . '?Digits=0</Redirect>';
    } else {
        echo '<Hangup/>';
    }
    echo '</Response>';
    exit;
}

function transferToAgent(PDO $db, int $callId, string $phone, ?string $name, ?int $customerId, string $routeUrl): void {
    $db->prepare("UPDATE om_callcenter_calls SET status = 'queued' WHERE id = ?")->execute([$callId]);

    // Check how many agents are online
    $agentsOnline = 0;
    try {
        $agStmt = $db->query("SELECT COUNT(*) as cnt FROM om_callcenter_agents WHERE status = 'online'");
        $agentsOnline = (int)$agStmt->fetch()['cnt'];
    } catch (Exception $e) {}

    // Get current queue depth
    $queueDepth = 0;
    try {
        $qStmt = $db->query("SELECT COUNT(*) as cnt FROM om_callcenter_queue WHERE picked_at IS NULL AND abandoned_at IS NULL");
        $queueDepth = (int)$qStmt->fetch()['cnt'];
    } catch (Exception $e) {}

    // VIP priority for returning customers with many orders
    $priority = 5;
    if ($customerId) {
        try {
            $ordCount = $db->prepare("SELECT COUNT(*) as cnt FROM om_market_orders WHERE customer_id = ?");
            $ordCount->execute([$customerId]);
            $orderCount = (int)$ordCount->fetch()['cnt'];
            if ($orderCount >= 20) $priority = 1;
            elseif ($orderCount >= 10) $priority = 2;
            elseif ($orderCount >= 5) $priority = 3;
        } catch (Exception $e) {}
    }

    // Insert into queue
    $db->prepare("
        INSERT INTO om_callcenter_queue (call_id, customer_phone, customer_name, customer_id, priority, queued_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON CONFLICT DO NOTHING
    ")->execute([$callId, $phone, $name, $customerId, $priority]);

    ccBroadcastDashboard('queue_updated', [
        'call_id' => $callId,
        'customer_phone' => $phone,
        'customer_name' => $name,
        'action' => 'transfer_from_ai',
        'queue_depth' => $queueDepth + 1,
        'agents_online' => $agentsOnline,
    ]);

    // Build queue message
    $queueMsg = "Sem problema! Vou transferir voce para um atendente agora.";
    if ($agentsOnline === 0) {
        $queueMsg .= " No momento nao temos atendentes disponiveis, mas sua ligacao e muito importante. Aguarde na linha ou, se preferir, podemos ligar de volta. Pressione 1 para solicitar retorno.";
    } elseif ($queueDepth > 0) {
        $estimatedWait = $queueDepth * 2; // ~2 min per call estimate
        $queueMsg .= " Voce e o numero " . ($queueDepth + 1) . " na fila. Tempo estimado de espera: cerca de {$estimatedWait} minutos.";
    } else {
        $queueMsg .= " Aguarde um momento que ja vamos te atender.";
    }

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="Polly.Camila" language="pt-BR">' . escXml($queueMsg) . '</Say>';
    // Professional hold music — Twilio's built-in classical music
    echo '<Play loop="0">http://com.twilio.music.classical.s3.amazonaws.com/MARminimum_-_Pachelbels_Canon.mp3</Play>';
    echo '</Response>';
}

function loadAiContext(PDO $db, int $callId): array {
    $stmt = $db->prepare("SELECT notes FROM om_callcenter_calls WHERE id = ?");
    $stmt->execute([$callId]);
    $row = $stmt->fetch();
    $notes = $row['notes'] ?? '';
    if (!empty($notes) && $notes[0] === '{') {
        $ctx = json_decode($notes, true);
        if (is_array($ctx) && isset($ctx['_ai_context'])) {
            return $ctx['_ai_context'];
        }
    }
    return ['step' => 'identify_store', 'history' => [], 'items' => []];
}

function saveAiContext(PDO $db, int $callId, array $context): void {
    // Keep history compact — only last 10 turns
    if (isset($context['history']) && count($context['history']) > 20) {
        $context['history'] = array_slice($context['history'], -20);
    }
    $json = json_encode(['_ai_context' => $context], JSON_UNESCAPED_UNICODE);
    $db->prepare("UPDATE om_callcenter_calls SET notes = ? WHERE id = ?")->execute([$json, $callId]);
}

function fetchStoreMenu(PDO $db, int $storeId): string {
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

/**
 * Look up address from CEP via ViaCEP API (cached 24h)
 */
function lookupCep(string $cep): ?array {
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

/**
 * Find stores that deliver to a given CEP
 */
function findStoresByCep(PDO $db, string $cep): array {
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

function buildSystemPrompt(
    string $step, ?string $storeName, string $menuText, array $items,
    ?array $address, ?string $payment, ?string $customerName,
    array $savedAddresses, array $storeNames, array $lastOrderItems = [],
    array $context = []
): string {
    $hora = (int)date('H');
    $periodo = $hora < 12 ? 'bom dia' : ($hora < 18 ? 'boa tarde' : 'boa noite');

    $prompt = "Voce e a assistente virtual do SuperBora, um app de delivery de comida. Voce esta atendendo uma ligacao telefonica.\n\n";
    $prompt .= "REGRAS OBRIGATORIAS:\n";
    $prompt .= "- Fale SEMPRE em portugues brasileiro, de forma natural e amigavel\n";
    $prompt .= "- Respostas CURTAS (maximo 3 frases) — lembre que sera lido por voz\n";
    $prompt .= "- NUNCA invente precos ou produtos — use SOMENTE o cardapio fornecido\n";
    $prompt .= "- Se o cliente disser 'atendente', 'cancelar' ou algo parecido, responda normalmente (o sistema detecta automaticamente)\n";
    $prompt .= "- Seja educado e eficiente como um atendente profissional\n";
    $prompt .= "- Use o nome do cliente quando disponivel\n";
    $prompt .= "- Hora atual: " . date('H:i') . " ({$periodo})\n\n";

    if ($customerName) {
        $prompt .= "CLIENTE: {$customerName}\n\n";
    }

    // Step-specific instructions
    switch ($step) {
        case 'identify_store':
            $prompt .= "ETAPA ATUAL: Identificar o restaurante\n";
            $prompt .= "- Pergunte de qual restaurante o cliente quer pedir\n";
            $prompt .= "- Se ele ja disse um nome, tente fazer match com a lista abaixo\n";
            $prompt .= "- Se encontrar, confirme: 'Voce quer pedir da [nome]?'\n";
            $prompt .= "- Se nao encontrar, diga quais opcoes temos e pergunte novamente\n\n";
            $prompt .= "RESTAURANTES DISPONIVEIS:\n" . implode("\n", $storeNames) . "\n\n";
            // Smart: suggest based on time
            $hora = (int)date('H');
            if ($hora >= 6 && $hora < 10) {
                $prompt .= "DICA: E manha — se o cliente nao sabe o que quer, sugira padarias/cafeterias da lista.\n";
            } elseif ($hora >= 11 && $hora < 14) {
                $prompt .= "DICA: E horario de almoco — sugira restaurantes com pratos executivos ou marmitas.\n";
            } elseif ($hora >= 18 && $hora < 22) {
                $prompt .= "DICA: E noite — sugira pizzarias, hamburguerias ou restaurantes populares.\n";
            }
            $prompt .= "\nINSTRUCAO ESPECIAL: Se identificar o restaurante com certeza, inclua na resposta o marcador [STORE:numero:nome] onde numero e o ID.\n";
            $prompt .= "Exemplo: Se o cliente quer 'Pizzaria Bella' e ela tem (ID:42), inclua [STORE:42:Pizzaria Bella] na resposta.\n";
            $prompt .= "NUNCA escreva a palavra 'ID' dentro do marcador. Apenas o numero.\n";
            break;

        case 'take_order':
            $prompt .= "ETAPA ATUAL: Anotar o pedido\n";
            $prompt .= "RESTAURANTE: {$storeName}\n\n";
            $prompt .= "CARDAPIO:\n{$menuText}\n\n";
            if (!empty($items)) {
                $prompt .= "ITENS JA ANOTADOS:\n";
                $total = 0;
                foreach ($items as $item) {
                    $lineTotal = ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                    $total += $lineTotal;
                    $prompt .= "- {$item['quantity']}x {$item['name']} R$" . number_format($lineTotal, 2, ',', '.') . "\n";
                }
                $prompt .= "Subtotal parcial: R$" . number_format($total, 2, ',', '.') . "\n\n";
            }
            // Smart: show last order items if returning customer
            if (!empty($lastOrderItems)) {
                $prompt .= "ULTIMO PEDIDO DO CLIENTE NESTA LOJA:\n";
                foreach ($lastOrderItems as $li) {
                    $prompt .= "- {$li['quantity']}x {$li['product_name']} R$" . number_format((float)$li['unit_price'], 2, ',', '.') . "\n";
                }
                $prompt .= "\n- Se for a primeira mensagem, mencione: 'Vi que voce ja pediu [item] antes. Quer repetir ou pedir algo diferente?'\n\n";
            }
            $prompt .= "- Pergunte o que o cliente deseja pedir\n";
            $prompt .= "- Quando ele disser um produto, identifique no cardapio e confirme nome e preco\n";
            $prompt .= "- Pergunte a quantidade se nao foi especificada\n";
            $prompt .= "- Se o cliente pedir algo que nao tem no cardapio, sugira o mais parecido\n";
            $prompt .= "- Apos cada item, pergunte: 'Mais alguma coisa?'\n";
            $prompt .= "- Sugira complementos naturais (ex: bebida com comida, sobremesa)\n";
            $prompt .= "- Quando o cliente disser que e so isso, que nao quer mais nada, finalize\n\n";
            $prompt .= "INSTRUCAO ESPECIAL: Para cada item identificado, inclua [ITEM:product_id:quantidade:preco:nome] na resposta.\n";
            $prompt .= "Exemplo: [ITEM:123:2:12.90:Coxinha de Frango]\n";
            $prompt .= "Quando o cliente disser que nao quer mais nada, inclua [NEXT_STEP] na resposta.\n";
            break;

        case 'get_address':
            $prompt .= "ETAPA ATUAL: Confirmar endereco de entrega\n";
            $prompt .= "RESTAURANTE: {$storeName}\n\n";
            // If CEP was already provided, show the resolved address
            if (!empty($address['from_cep'])) {
                $prompt .= "ENDERECO ENCONTRADO PELO CEP ({$address['cep']}):\n";
                $prompt .= "Rua: {$address['street']}, Bairro: {$address['neighborhood']}, Cidade: {$address['city']}-{$address['state']}\n";
                $prompt .= "- Confirme o endereco com o cliente e pergunte o NUMERO da casa/apartamento\n";
                $prompt .= "- Quando tiver o numero, monte o endereco completo e inclua [ADDRESS_TEXT:rua, numero - bairro, cidade] e [NEXT_STEP]\n\n";
            } elseif (!empty($savedAddresses)) {
                $prompt .= "ENDERECOS SALVOS DO CLIENTE:\n";
                foreach ($savedAddresses as $i => $addr) {
                    $num = $i + 1;
                    $label = $addr['label'] ?? '';
                    $full = ($addr['street'] ?? '') . ', ' . ($addr['number'] ?? '') . ' - ' . ($addr['neighborhood'] ?? '');
                    $prompt .= "{$num}. {$label}: {$full}\n";
                }
                $prompt .= "\n- Pergunte se quer entregar em um dos enderecos salvos ou em outro lugar\n";
                $prompt .= "- Se escolher um salvo, inclua [ADDRESS:indice] (ex: [ADDRESS:1])\n";
            } else {
                $prompt .= "- O cliente nao tem enderecos salvos\n";
                $prompt .= "- Pergunte o endereco completo de entrega (rua, numero, bairro, cidade)\n";
                $prompt .= "- O cliente tambem pode informar apenas o CEP — o sistema busca o endereco automaticamente\n";
            }
            $prompt .= "- Se o cliente disser um CEP (8 digitos), inclua [CEP:00000000] na resposta\n";
            $prompt .= "- Quando tiver o endereco completo, inclua [ADDRESS_TEXT:endereco completo] e [NEXT_STEP]\n";
            break;

        case 'get_payment':
            $prompt .= "ETAPA ATUAL: Forma de pagamento\n";
            $prompt .= "- Pergunte a forma de pagamento: Dinheiro, PIX, Cartao de credito ou debito na maquininha\n";
            $prompt .= "- Se dinheiro, pergunte se precisa de troco e para quanto\n";
            $prompt .= "- Inclua [PAYMENT:metodo] na resposta (ex: [PAYMENT:dinheiro], [PAYMENT:pix], [PAYMENT:credit_card])\n";
            $prompt .= "- Se dinheiro com troco, inclua [PAYMENT:dinheiro:100] onde 100 e o valor do troco\n";
            $prompt .= "- Depois inclua [NEXT_STEP]\n";
            break;

        case 'confirm_order':
            $prompt .= "ETAPA ATUAL: Confirmar pedido completo\n";
            $prompt .= "RESTAURANTE: {$storeName}\n\n";
            $prompt .= "ITENS DO PEDIDO:\n";
            $subtotal = 0;
            foreach ($items as $item) {
                $lineTotal = ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                $subtotal += $lineTotal;
                $prompt .= "- {$item['quantity']}x {$item['name']} R$" . number_format($lineTotal, 2, ',', '.') . "\n";
            }
            $deliveryFee = 5.0; // Default, could be dynamic
            $serviceFee = round($subtotal * 0.08, 2);
            $total = $subtotal + $deliveryFee + $serviceFee;
            $prompt .= "\nSubtotal: R$" . number_format($subtotal, 2, ',', '.');
            $prompt .= "\nEntrega: R$" . number_format($deliveryFee, 2, ',', '.');
            $prompt .= "\nTaxa: R$" . number_format($serviceFee, 2, ',', '.');
            $prompt .= "\nTOTAL: R$" . number_format($total, 2, ',', '.') . "\n\n";

            if ($address) {
                $prompt .= "ENDERECO: " . ($address['full'] ?? ($address['street'] ?? 'N/A')) . "\n";
            }
            if ($payment) {
                $prompt .= "PAGAMENTO: {$payment}\n\n";
            }

            $prompt .= "- Leia o resumo do pedido para o cliente\n";
            $prompt .= "- Pergunte: 'Posso confirmar o pedido?'\n";
            $prompt .= "- Se o cliente confirmar (sim, pode, confirma, isso, correto), inclua [CONFIRMED] na resposta\n";
            $prompt .= "- Se quiser mudar algo, volte para a etapa adequada\n";
            break;

        case 'support':
            $prompt .= "ETAPA ATUAL: Suporte ao cliente\n";
            $prompt .= "O cliente quer ajuda com um pedido existente (status, cancelamento, problema, etc).\n\n";
            $prompt .= "Voce pode ajudar com:\n";
            $prompt .= "- Ver status de um pedido\n";
            $prompt .= "- Cancelar um pedido (apenas se status for 'confirmado' ou 'pendente')\n";
            $prompt .= "- Informar tempo estimado de entrega\n";
            $prompt .= "- Responder perguntas sobre pedidos anteriores\n";
            $prompt .= "- Se o problema for complexo demais, oferecer transferir para atendente humano\n\n";
            // Inject customer's recent orders
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
                    $prompt .= "- Pedido #{$ord['order_number']} | {$ord['partner_name']} | Status: {$statusLabel} | Total: R$" . number_format((float)$ord['total'], 2, ',', '.') . " | Data: {$ord['date_added']}\n";
                    if (!empty($ord['items_summary'])) {
                        $prompt .= "  Itens: {$ord['items_summary']}\n";
                    }
                }
                $prompt .= "\n";
            } else {
                $prompt .= "NENHUM PEDIDO ENCONTRADO para este telefone.\n";
                $prompt .= "- Informe que nao encontrou pedidos vinculados a este numero\n";
                $prompt .= "- Pergunte se quer fazer um novo pedido ou falar com atendente\n\n";
            }
            $prompt .= "MARCADORES ESPECIAIS:\n";
            $prompt .= "- Para cancelar pedido: [CANCEL_ORDER:order_number] (ex: [CANCEL_ORDER:SB00123])\n";
            $prompt .= "  SOMENTE se o status for 'confirmado' ou 'pendente'. Se ja estiver preparando ou saiu, diga que nao pode mais cancelar.\n";
            $prompt .= "- Para consultar status: [ORDER_STATUS:order_number]\n";
            $prompt .= "- Se o cliente quiser voltar a fazer pedido: [SWITCH_TO_ORDER]\n";
            $prompt .= "- Se precisar de atendente humano: diga que vai transferir (o sistema detecta automaticamente)\n";
            break;
    }

    return $prompt;
}

function parseAiResponse(string $response, array $context, PDO $db): array {
    $newContext = $context;
    $cleaned = $response;

    // Parse [STORE:ID:name] — also handle [STORE:ID:142:name] if Claude includes literal "ID:"
    if (preg_match('/\[STORE:(?:ID:)?(\d+):([^\]]+)\]/', $response, $m)) {
        $newContext['store_id'] = (int)$m[1];
        $newContext['store_name'] = trim($m[2]);
        $newContext['step'] = 'take_order';
        $cleaned = preg_replace('/\[STORE:(?:ID:)?\d+:[^\]]+\]/', '', $cleaned);

        // Update call record
        $db->prepare("UPDATE om_callcenter_calls SET store_identified = ? WHERE id = ?")
           ->execute([$newContext['store_name'], $context['call_id'] ?? 0]);
    }

    // Parse [ITEM:product_id:qty:price:name]
    if (preg_match_all('/\[ITEM:(\d+):(\d+):([\d.]+):([^\]]+)\]/', $response, $matches, PREG_SET_ORDER)) {
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

    // Parse [ADDRESS:index] (saved address)
    if (preg_match('/\[ADDRESS:(\d+)\]/', $response, $m)) {
        $idx = (int)$m[1] - 1; // 1-based to 0-based
        // Will be resolved in submit
        $newContext['address_index'] = $idx;
        $cleaned = preg_replace('/\[ADDRESS:\d+\]/', '', $cleaned);
    }

    // Parse [ADDRESS_TEXT:text]
    if (preg_match('/\[ADDRESS_TEXT:([^\]]+)\]/', $response, $m)) {
        $newContext['address'] = ['full' => trim($m[1]), 'manual' => true];
        $cleaned = preg_replace('/\[ADDRESS_TEXT:[^\]]+\]/', '', $cleaned);
    }

    // Parse [CEP:12345678] — auto-lookup address via ViaCEP
    if (preg_match('/\[CEP:(\d{5,8})\]/', $response, $m)) {
        $cepData = lookupCep($m[1]);
        if ($cepData) {
            $newContext['cep_data'] = $cepData;
            // Don't auto-advance — need to ask for house number
            $newContext['address'] = [
                'street' => $cepData['street'],
                'neighborhood' => $cepData['neighborhood'],
                'city' => $cepData['city'],
                'state' => $cepData['state'],
                'cep' => $cepData['cep'],
                'from_cep' => true,
            ];
            // Also find nearby stores if not already identified
            if (empty($newContext['store_id'])) {
                $nearbyStores = findStoresByCep($db, $m[1]);
                if (!empty($nearbyStores)) {
                    $newContext['nearby_stores'] = $nearbyStores;
                }
            }
        }
        $cleaned = preg_replace('/\[CEP:\d+\]/', '', $cleaned);
    }

    // Parse [PAYMENT:method] or [PAYMENT:method:change]
    if (preg_match('/\[PAYMENT:(\w+)(?::(\d+))?\]/', $response, $m)) {
        $newContext['payment_method'] = $m[1];
        if (!empty($m[2])) {
            $newContext['payment_change'] = (float)$m[2];
        }
        $cleaned = preg_replace('/\[PAYMENT:\w+(?::\d+)?\]/', '', $cleaned);
    }

    // Parse [NEXT_STEP]
    if (strpos($response, '[NEXT_STEP]') !== false) {
        $currentStep = $newContext['step'] ?? 'identify_store';
        $stepOrder = ['identify_store', 'take_order', 'get_address', 'get_payment', 'confirm_order', 'submit_order'];
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
        $cancelResult = cancelOrderByNumber($db, $orderNumber);
        if ($cancelResult['success']) {
            $cleaned = preg_replace('/\[CANCEL_ORDER:[^\]]+\]/', '', $cleaned);
            $cleaned .= ' Pedido ' . $orderNumber . ' foi cancelado com sucesso.';
        } else {
            $cleaned = preg_replace('/\[CANCEL_ORDER:[^\]]+\]/', '', $cleaned);
            $cleaned .= ' ' . $cancelResult['error'];
        }
    }

    // Parse [ORDER_STATUS:SB00123] — just a signal, info already in prompt
    if (preg_match('/\[ORDER_STATUS:[^\]]+\]/', $response)) {
        $cleaned = preg_replace('/\[ORDER_STATUS:[^\]]+\]/', '', $cleaned);
    }

    // Parse [SWITCH_TO_ORDER] — customer wants to go back to ordering
    if (strpos($response, '[SWITCH_TO_ORDER]') !== false) {
        $newContext['step'] = 'identify_store';
        $newContext['items'] = [];
        $newContext['store_id'] = null;
        $newContext['store_name'] = null;
        $newContext['address'] = null;
        $newContext['payment_method'] = null;
        $cleaned = str_replace('[SWITCH_TO_ORDER]', '', $cleaned);
    }

    $newContext['cleaned_response'] = trim(preg_replace('/\s+/', ' ', $cleaned));
    return $newContext;
}

function cleanConversationHistory(array $history): array {
    $clean = [];
    $lastRole = null;
    foreach ($history as $msg) {
        if (!isset($msg['role']) || !isset($msg['content'])) continue;
        if (empty(trim($msg['content']))) continue;
        // Merge consecutive same-role messages
        if ($msg['role'] === $lastRole && !empty($clean)) {
            $clean[count($clean) - 1]['content'] .= ' ' . $msg['content'];
        } else {
            $clean[] = ['role' => $msg['role'], 'content' => $msg['content']];
            $lastRole = $msg['role'];
        }
    }
    // Ensure starts with user
    if (!empty($clean) && $clean[0]['role'] !== 'user') {
        array_unshift($clean, ['role' => 'user', 'content' => 'Ola']);
    }
    // Ensure non-empty
    if (empty($clean)) {
        $clean[] = ['role' => 'user', 'content' => 'Ola, quero fazer um pedido'];
    }
    return $clean;
}

function submitAiOrder(PDO $db, int $callId, ?int $customerId, ?string $customerName, string $customerPhone, array $context): array {
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
                'confirmado', ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?,
                ?, 'pendente', ?,
                ?, 'callcenter_ai', NOW()
            ) RETURNING order_id
        ");
        $stmt->execute([
            $customerId, $storeId, $customerName, $customerPhone,
            $subtotal, $deliveryFee, $serviceFee, $total,
            $deliveryAddress,
            $address['street'] ?? '', $address['city'] ?? '', $address['state'] ?? '', $address['zipcode'] ?? '',
            $address['lat'] ?? null, $address['lng'] ?? null,
            $paymentMethod, $codigoEntrega,
            "Pedido via IA telefone - call #{$callId}",
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
            VALUES (?, 'confirmado', 'Pedido criado via IA por telefone', 'system', NOW())
        ")->execute([$orderId]);

        $db->commit();

        // Send SMS
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
            error_log("[twilio-voice-ai] SMS error: " . $e->getMessage());
        }

        // Notify partner
        try {
            require_once __DIR__ . '/../helpers/notify.php';
            if ($customerId) {
                notifyCustomer($db, $customerId,
                    "Novo pedido {$orderNumber}",
                    "Seu pedido foi criado pela assistente virtual SuperBora. Total: R$" . number_format($total, 2, ',', '.'),
                    '/pedidos', ['order_id' => $orderId, 'type' => 'order_created']
                );
            }
        } catch (Exception $e) {
            error_log("[twilio-voice-ai] Notify error: " . $e->getMessage());
        }

        error_log("[twilio-voice-ai] Order created: {$orderNumber} total=R\${$total} items=" . count($items));

        return [
            'success' => true,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'total' => $total,
        ];

    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        error_log("[twilio-voice-ai] Submit error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function cancelOrderByNumber(PDO $db, string $orderNumber): array {
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
            return ['success' => false, 'error' => "Nao foi possivel cancelar o pedido {$orderNumber} porque ele {$reason}. Se precisar, posso transferir para um atendente."];
        }

        $db->beginTransaction();
        $db->prepare("UPDATE om_market_orders SET status = 'cancelled' WHERE order_id = ?")->execute([$order['order_id']]);
        $db->prepare("
            INSERT INTO om_order_timeline (order_id, status, description, actor_type, created_at)
            VALUES (?, 'cancelled', 'Cancelado pelo cliente via IA por telefone', 'system', NOW())
        ")->execute([$order['order_id']]);
        $db->commit();

        error_log("[twilio-voice-ai] Order {$orderNumber} cancelled via AI support");
        return ['success' => true];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("[twilio-voice-ai] Cancel error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Erro ao cancelar: ' . $e->getMessage()];
    }
}
