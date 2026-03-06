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

// Signature validation — log mismatches but allow Twilio requests (CallSid present)
if (!empty($authToken) && !empty($twilioSignature)) {
    $scheme = 'https';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'superbora.com.br';
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
        error_log("[twilio-voice-ai] Signature mismatch — allowing (proxy may alter URL)");
    }
} elseif (empty($twilioSignature) && !isset($_POST['CallSid'])) {
    error_log("[twilio-voice-ai] Rejected: no signature and no CallSid");
    http_response_code(403);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Unauthorized</Say></Response>';
    exit;
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

    // Track user input + speech confidence
    $userInput = $speechResult ?: ($digits ?: '');
    $speechConfidence = isset($_POST['Confidence']) ? (float)$_POST['Confidence'] : 1.0;

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

    // Detect "repeat last order" intent
    if (!empty($userInput)) {
        $lowerInput = mb_strtolower($userInput, 'UTF-8');
        $repeatPhrases = ['mesmo pedido', 'mesmo de sempre', 'repete', 'repetir', 'igual ao ultimo', 'o de sempre', 'o mesmo', 'mesmo que o anterior', 'pedir o mesmo', 'quero o mesmo'];
        foreach ($repeatPhrases as $rp) {
            if (mb_strpos($lowerInput, $rp) !== false && $customerId && $storeId && empty($draftItems)) {
                $repeatItems = fetchLastOrderItems($db, $customerId, $storeId);
                if (!empty($repeatItems)) {
                    $aiContext['items'] = $repeatItems;
                    $draftItems = $repeatItems;
                    $aiContext['repeat_order'] = true;
                }
                break;
            }
        }

        // Detect "remove item" intent
        $removePhrases = ['tira', 'remove', 'retira', 'sem o', 'nao quero mais o', 'cancela o'];
        foreach ($removePhrases as $rp) {
            if (mb_strpos($lowerInput, $rp) !== false && !empty($draftItems)) {
                $aiContext['wants_remove'] = true;
                break;
            }
        }

        // Detect natural pauses — don't waste Claude call
        $pausePhrases = ['perai', 'pera', 'espera', 'um momento', 'calma', 'deixa eu pensar', 'hm', 'hmm', 'ah'];
        if (in_array(trim($lowerInput), $pausePhrases)) {
            $aiContext['history'] = $conversationHistory;
            saveAiContext($db, $callId, $aiContext);
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo '<Gather input="speech dtmf" timeout="12" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" numDigits="1">';
            echo '<Say voice="Polly.Camila" language="pt-BR">Sem pressa, estou aqui. Pode falar quando estiver pronto.</Say>';
            echo '</Gather>';
            echo '<Say voice="Polly.Camila" language="pt-BR">Nao consegui ouvir. Pode repetir?</Say>';
            echo '<Redirect method="POST">' . escXml($selfUrl) . '</Redirect>';
            echo '</Response>';
            exit;
        }

        // Low speech confidence — ask to repeat instead of processing garbage
        if ($speechConfidence < 0.4 && !empty($speechResult) && strlen($speechResult) < 30) {
            $aiContext['history'] = $conversationHistory;
            // Remove the low-confidence user message from history
            if (!empty($conversationHistory) && end($conversationHistory)['role'] === 'user') {
                array_pop($conversationHistory);
                $aiContext['history'] = $conversationHistory;
            }
            saveAiContext($db, $callId, $aiContext);
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" numDigits="1">';
            echo '<Say voice="Polly.Camila" language="pt-BR">Desculpa, nao consegui entender direito. Pode repetir por favor?</Say>';
            echo '</Gather>';
            echo '<Say voice="Polly.Camila" language="pt-BR">Se preferir, pode pressionar zero para falar com um atendente.</Say>';
            echo '<Redirect method="POST">' . escXml($selfUrl) . '</Redirect>';
            echo '</Response>';
            exit;
        }

        // Detect dietary/allergen questions
        $dietaryPhrases = ['alergia', 'alergico', 'alergica', 'gluten', 'sem gluten', 'lactose', 'sem lactose', 'vegetariano', 'vegetariana', 'vegano', 'vegana', 'ingredientes', 'contem', 'contem leite', 'intolerancia'];
        foreach ($dietaryPhrases as $dp) {
            if (mb_strpos($lowerInput, $dp) !== false) {
                $aiContext['dietary_question'] = true;
                break;
            }
        }

        // Detect scheduling intent
        $schedulePhrases = ['agendar', 'agendado', 'amanha', 'depois de amanha', 'para amanha', 'pra amanha', 'mais tarde', 'no almoco', 'ao meio dia', 'as 12', 'as 13', 'as 18', 'as 19', 'as 20', 'as 21', 'hora marcada', 'horario'];
        foreach ($schedulePhrases as $sp) {
            if (mb_strpos($lowerInput, $sp) !== false) {
                $aiContext['wants_schedule'] = true;
                break;
            }
        }

        // Express order: "o de sempre" / "pedido rapido" / "o mesmo de sempre"
        $expressPhrases = ['pedido rapido', 'o de sempre', 'o mesmo de sempre', 'repete tudo', 'igual sempre'];
        foreach ($expressPhrases as $ep) {
            if (mb_strpos($lowerInput, $ep) !== false && $customerId) {
                $aiContext['express_mode'] = true;
                break;
            }
        }
    }

    // Fetch store catalog if we have a store
    $menuText = '';
    $storeInfo = null;
    if ($storeId) {
        $menuText = fetchStoreMenu($db, $storeId);
        $storeInfo = fetchStoreInfo($db, $storeId);
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

    // Fetch popular items for this store (for smart suggestions)
    $popularItems = [];
    if ($storeId && $step === 'take_order') {
        $popStmt = $db->prepare("
            SELECT oi.product_name, COUNT(*) as order_count
            FROM om_market_order_items oi
            JOIN om_market_orders o ON o.order_id = oi.order_id
            WHERE o.partner_id = ? AND o.status NOT IN ('cancelled','refunded')
            AND oi.product_name IS NOT NULL AND oi.product_name != ''
            GROUP BY oi.product_name
            ORDER BY order_count DESC LIMIT 5
        ");
        $popStmt->execute([$storeId]);
        $popularItems = $popStmt->fetchAll();
    }

    // Fetch active promotions/coupons
    $activePromos = [];
    if ($step === 'take_order' || $step === 'confirm_order') {
        $promoStmt = $db->query("
            SELECT code, discount_type, discount_value, max_discount, min_order_value
            FROM om_market_coupons
            WHERE status = 'active' AND (valid_until IS NULL OR valid_until > NOW())
            AND (max_uses IS NULL OR current_uses < max_uses)
            LIMIT 3
        ");
        $activePromos = $promoStmt->fetchAll();
    }

    // Fetch customer's last payment method
    $lastPayment = null;
    if ($customerId && $step === 'get_payment') {
        $payStmt = $db->prepare("
            SELECT forma_pagamento FROM om_market_orders
            WHERE customer_id = ? AND status NOT IN ('cancelled','refunded')
            ORDER BY date_added DESC LIMIT 1
        ");
        $payStmt->execute([$customerId]);
        $lastPay = $payStmt->fetch();
        if ($lastPay) $lastPayment = $lastPay['forma_pagamento'];
    }

    // Customer total stats
    $customerStats = null;
    if ($customerId) {
        $statsStmt = $db->prepare("
            SELECT COUNT(*) as total_orders,
                   COALESCE(SUM(total), 0) as lifetime_value
            FROM om_market_orders
            WHERE customer_id = ? AND status NOT IN ('cancelled','refunded')
        ");
        $statsStmt->execute([$customerId]);
        $customerStats = $statsStmt->fetch();
    }

    // Fetch customer addresses — load early so we can auto-fill
    $savedAddresses = [];
    $defaultAddress = null;
    if ($customerId) {
        $addrStmt = $db->prepare("
            SELECT address_id, label, street, number, complement, neighborhood, city, state, zipcode, lat, lng, is_default
            FROM om_customer_addresses WHERE customer_id = ? AND is_active = '1'
            ORDER BY is_default DESC LIMIT 5
        ");
        $addrStmt->execute([$customerId]);
        $savedAddresses = $addrStmt->fetchAll();
        // Auto-detect default address
        foreach ($savedAddresses as $addr) {
            if ($addr['is_default']) {
                $defaultAddress = $addr;
                break;
            }
        }
        if (!$defaultAddress && !empty($savedAddresses)) {
            $defaultAddress = $savedAddresses[0]; // Most recent
        }
    }

    // -- Smart auto-skip: returning customer with 1 address + same payment --
    $canAutoSkipAddress = ($customerId && count($savedAddresses) === 1 && $defaultAddress);
    $canAutoSkipPayment = ($customerId && $lastPayment);

    // Express mode: auto-fill address and payment if returning customer says "o de sempre"
    if (!empty($aiContext['express_mode']) && $customerId && $storeId) {
        // Auto-apply last order if no items yet
        if (empty($draftItems)) {
            $repeatItems = fetchLastOrderItems($db, $customerId, $storeId);
            if (!empty($repeatItems)) {
                $aiContext['items'] = $repeatItems;
                $draftItems = $repeatItems;
                $aiContext['repeat_order'] = true;
            }
        }
        // Auto-apply address
        if ($canAutoSkipAddress && !$address) {
            $aiContext['address_index'] = 0;
            $aiContext['address'] = [
                'address_id' => (int)$defaultAddress['address_id'],
                'street' => $defaultAddress['street'], 'number' => $defaultAddress['number'],
                'complement' => $defaultAddress['complement'] ?? '', 'neighborhood' => $defaultAddress['neighborhood'],
                'city' => $defaultAddress['city'], 'state' => $defaultAddress['state'],
                'zipcode' => $defaultAddress['zipcode'] ?? '',
                'lat' => $defaultAddress['lat'] ?? null, 'lng' => $defaultAddress['lng'] ?? null,
                'full' => $defaultAddress['street'] . ', ' . $defaultAddress['number'] . ' - ' . $defaultAddress['neighborhood'],
            ];
            $address = $aiContext['address'];
        }
        // Auto-apply payment
        if ($canAutoSkipPayment && !$paymentMethod) {
            $aiContext['payment_method'] = $lastPayment;
            $paymentMethod = $lastPayment;
        }
        // If we have everything, jump to confirm
        if (!empty($draftItems) && $address && $paymentMethod) {
            $aiContext['step'] = 'confirm_order';
            $step = 'confirm_order';
        }
    }

    // Smart step-skip for returning customers (even without express mode)
    if ($step === 'get_address' && $canAutoSkipAddress && !$address) {
        // Auto-fill the default address — Claude will just confirm it
        $aiContext['auto_address_suggested'] = true;
    }
    if ($step === 'get_payment' && $canAutoSkipPayment && !$paymentMethod) {
        // Claude will suggest the last payment method
        $aiContext['auto_payment_suggested'] = true;
    }

    // -- Build system prompt --
    $extraData = [
        'store_info' => $storeInfo,
        'popular_items' => $popularItems ?? [],
        'active_promos' => $activePromos ?? [],
        'last_payment' => $lastPayment ?? null,
        'customer_stats' => $customerStats,
        'default_address' => $defaultAddress,
    ];
    $systemPrompt = buildSystemPrompt($step, $storeName, $menuText, $draftItems, $address, $paymentMethod, $customerName, $savedAddresses, $storeNames, $lastOrderItems ?? [], $aiContext, $extraData);

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
            if (!empty($newContext['scheduled_date'])) {
                $schedMsg = $newContext['scheduled_date'] . ' as ' . ($newContext['scheduled_time'] ?? '12:00');
                $finalMsg = "Perfeito! Seu pedido numero {$orderNumber} foi agendado para {$schedMsg}! "
                    . "O total e {$total} reais. "
                    . "Voce vai receber um SMS com todos os detalhes. "
                    . "Obrigado por pedir pelo SuperBora! Ate la!";
            } else {
                $finalMsg = "Perfeito! Seu pedido numero {$orderNumber} foi criado com sucesso! "
                    . "O total e {$total} reais. "
                    . "Voce vai receber um SMS com todos os detalhes do pedido. "
                    . "Obrigado por pedir pelo SuperBora! Tenha um otimo dia!";
            }

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
        SELECT c.name AS category, p.product_id, p.name, p.price, p.description,
               p.special_price, p.special_start, p.special_end,
               p.is_featured, p.is_combo, p.dietary_tags
        FROM om_market_products p
        LEFT JOIN om_market_categories c ON c.category_id = p.category_id
        WHERE p.partner_id = ? AND p.status = 1
        ORDER BY c.sort_order, p.sort_order
        LIMIT 60
    ");
    $stmt->execute([$storeId]);
    $products = $stmt->fetchAll();

    // Fetch all option groups + options for this store's products
    $productIds = array_column($products, 'product_id');
    $optionsByProduct = [];
    if (!empty($productIds)) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $optStmt = $db->prepare("
            SELECT og.product_id, og.name AS group_name, og.required, og.min_select, og.max_select,
                   o.id AS option_id, o.name AS option_name, o.price_extra
            FROM om_product_option_groups og
            JOIN om_product_options o ON o.group_id = og.id
            WHERE og.product_id IN ({$placeholders}) AND og.active = 1 AND o.available = 1
            ORDER BY og.product_id, og.sort_order, o.sort_order
        ");
        $optStmt->execute($productIds);
        foreach ($optStmt->fetchAll() as $opt) {
            $pid = (int)$opt['product_id'];
            if (!isset($optionsByProduct[$pid])) $optionsByProduct[$pid] = [];
            $gname = $opt['group_name'];
            if (!isset($optionsByProduct[$pid][$gname])) {
                $optionsByProduct[$pid][$gname] = [
                    'required' => (bool)$opt['required'],
                    'min' => (int)$opt['min_select'],
                    'max' => (int)$opt['max_select'],
                    'options' => [],
                ];
            }
            $optionsByProduct[$pid][$gname]['options'][] = [
                'id' => (int)$opt['option_id'],
                'name' => $opt['option_name'],
                'price' => (float)$opt['price_extra'],
            ];
        }
    }

    // Fetch allergen/nutrition info
    $nutritionByProduct = [];
    if (!empty($productIds)) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $nutStmt = $db->prepare("
            SELECT product_id, allergens, ingredients, calories
            FROM om_market_product_nutrition
            WHERE product_id IN ({$placeholders})
        ");
        $nutStmt->execute($productIds);
        foreach ($nutStmt->fetchAll() as $n) {
            $nutritionByProduct[(int)$n['product_id']] = $n;
        }
    }

    $text = '';
    $lastCat = '';
    $now = date('Y-m-d H:i:s');
    foreach ($products as $p) {
        $cat = $p['category'] ?? 'Outros';
        if ($cat !== $lastCat) {
            $text .= "\n=={$cat}==\n";
            $lastCat = $cat;
        }

        $pid = (int)$p['product_id'];
        $price = (float)$p['price'];
        $hasPromo = false;
        if ($p['special_price'] && (float)$p['special_price'] > 0) {
            $inRange = true;
            if ($p['special_start'] && $now < $p['special_start']) $inRange = false;
            if ($p['special_end'] && $now > $p['special_end']) $inRange = false;
            if ($inRange) {
                $hasPromo = true;
                $originalPrice = $price;
                $price = (float)$p['special_price'];
            }
        }

        $text .= "ID:{$pid} {$p['name']} R$" . number_format($price, 2, ',', '.');
        if ($hasPromo) {
            $text .= " [PROMO! de R$" . number_format($originalPrice, 2, ',', '.') . "]";
        }
        if ($p['is_featured']) $text .= " [DESTAQUE]";
        if ($p['is_combo']) $text .= " [COMBO]";
        if ($p['dietary_tags']) $text .= " [{$p['dietary_tags']}]";
        if ($p['description']) $text .= " ({$p['description']})";
        // Allergens
        if (isset($nutritionByProduct[$pid])) {
            $nut = $nutritionByProduct[$pid];
            if (!empty($nut['allergens'])) $text .= " [ALERGENOS: {$nut['allergens']}]";
            if (!empty($nut['calories']) && (float)$nut['calories'] > 0) $text .= " [{$nut['calories']}kcal]";
        }
        $text .= "\n";

        // Product options (sizes, toppings, etc)
        if (isset($optionsByProduct[$pid])) {
            foreach ($optionsByProduct[$pid] as $gname => $group) {
                $req = $group['required'] ? 'OBRIGATORIO' : 'opcional';
                $maxTxt = $group['max'] > 1 ? ", max {$group['max']}" : '';
                $text .= "  >> {$gname} ({$req}{$maxTxt}): ";
                $optTexts = [];
                foreach ($group['options'] as $o) {
                    $optStr = $o['name'];
                    if ($o['price'] > 0) $optStr .= " +R$" . number_format($o['price'], 2, ',', '.');
                    $optStr .= " (OPT:{$o['id']})";
                    $optTexts[] = $optStr;
                }
                $text .= implode(' | ', $optTexts) . "\n";
            }
        }
    }
    return $text ?: 'Cardapio nao disponivel';
}

/**
 * Fetch allergen info for a specific product
 */
function fetchProductAllergens(PDO $db, int $productId): ?array {
    $stmt = $db->prepare("
        SELECT allergens, ingredients, calories, proteins, carbohydrates, fat, fiber
        FROM om_market_product_nutrition WHERE product_id = ?
    ");
    $stmt->execute([$productId]);
    return $stmt->fetch() ?: null;
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
    array $context = [], array $extraData = []
): string {
    $hora = (int)date('H');
    $periodo = $hora < 12 ? 'bom dia' : ($hora < 18 ? 'boa tarde' : 'boa noite');

    $storeInfo = $extraData['store_info'] ?? null;
    $popularItems = $extraData['popular_items'] ?? [];
    $activePromos = $extraData['active_promos'] ?? [];
    $lastPayment = $extraData['last_payment'] ?? null;
    $customerStats = $extraData['customer_stats'] ?? null;
    $defaultAddress = $extraData['default_address'] ?? null;

    $prompt = "Voce e a Bora, assistente virtual do SuperBora — app de delivery de comida. Voce esta atendendo uma ligacao telefonica.\n\n";
    $prompt .= "PERSONALIDADE:\n";
    $prompt .= "- Voce e simpática, calorosa e eficiente — como uma amiga que trabalha num restaurante\n";
    $prompt .= "- Fale em portugues brasileiro natural, com expressoes do dia-a-dia\n";
    $prompt .= "- Use 'voce' (nunca 'senhor/senhora' a menos que o cliente use)\n";
    $prompt .= "- Pode rir (rsrs) se o cliente fizer piada\n";
    $prompt .= "- Demonstre entusiasmo com as escolhas: 'Hmm, otima escolha!', 'Esse e sucesso aqui!'\n";
    $prompt .= "- Se alguem perguntar sobre alergias/ingredientes, leve MUITO a serio — saude nao e brincadeira\n\n";
    $prompt .= "REGRAS OBRIGATORIAS:\n";
    $prompt .= "- Respostas CURTAS (maximo 3 frases) — sera lido por voz, ninguem quer ouvir um paragrafo\n";
    $prompt .= "- NUNCA invente precos ou produtos — use SOMENTE o cardapio fornecido\n";
    $prompt .= "- Se o cliente disser 'atendente', responda normalmente (o sistema detecta automaticamente)\n";
    $prompt .= "- Use o nome do cliente naturalmente (nao toda frase, mas de vez em quando)\n";
    $prompt .= "- Se o cliente fizer uma pergunta (quanto tempo, aceita pix, etc), responda ANTES de continuar o fluxo\n";
    $prompt .= "- SEMPRE que souber dados do cliente (endereco, pagamento), sugira usar os mesmos — economize tempo\n";
    $prompt .= "- Se produto tem OPCOES OBRIGATORIAS (tamanho, etc), SEMPRE pergunte ANTES de adicionar\n";
    $prompt .= "- Hora atual: " . date('H:i') . " ({$periodo})\n";
    $prompt .= "- Dia da semana: " . ['Domingo','Segunda','Terca','Quarta','Quinta','Sexta','Sabado'][(int)date('w')] . "\n\n";

    if ($customerName) {
        $prompt .= "CLIENTE: {$customerName}";
        if ($customerStats && (int)$customerStats['total_orders'] > 0) {
            $orders = (int)$customerStats['total_orders'];
            $value = number_format((float)$customerStats['lifetime_value'], 2, ',', '.');
            $prompt .= " (cliente fiel: {$orders} pedidos, R\${$value} total)";
            if ($orders >= 20) $prompt .= " [VIP]";
        }
        $prompt .= "\n\n";
    }

    // Step-specific instructions
    switch ($step) {
        case 'identify_store':
            $prompt .= "ETAPA ATUAL: Identificar o restaurante\n";
            $prompt .= "- Pergunte de qual restaurante o cliente quer pedir\n";
            $prompt .= "- Se ele ja disse um nome, tente fazer match com a lista abaixo (aceite nomes aproximados, abreviacoes)\n";
            $prompt .= "- Se encontrar, confirme: 'Voce quer pedir da [nome]?'\n";
            $prompt .= "- Se nao encontrar, sugira os 3 mais parecidos e pergunte\n";
            $prompt .= "- Se disser algo generico como 'pizza', 'lanche', 'acai', sugira restaurantes da categoria\n";
            $prompt .= "- Se disser 'tanto faz', 'qualquer um', 'nao sei', sugira os mais populares para o horario\n";
            $prompt .= "- Se disser 'o mesmo de sempre' ou 'o de sempre', sugira o restaurante favorito do cliente (marcado [favorito])\n";
            $prompt .= "- Se disser 'to com fome', 'quero comer', sem especificar, sugira 3 opcoes baseadas nos favoritos e horario\n\n";
            $prompt .= "RESTAURANTES DISPONIVEIS:\n" . implode("\n", $storeNames) . "\n\n";
            // Customer location context
            if (!empty($extraData['default_address'])) {
                $da = $extraData['default_address'];
                $prompt .= "LOCALIZACAO DO CLIENTE: {$da['neighborhood']}, {$da['city']}\n";
                $prompt .= "- Priorize restaurantes que entregam nessa regiao\n\n";
            }
            // Smart: suggest based on time
            $hora = (int)date('H');
            if ($hora >= 6 && $hora < 10) {
                $prompt .= "DICA: E manha — sugira padarias, cafeterias, opcoes de cafe da manha.\n";
            } elseif ($hora >= 11 && $hora < 14) {
                $prompt .= "DICA: E horario de almoco — sugira restaurantes com pratos executivos, marmitas, comida caseira.\n";
            } elseif ($hora >= 14 && $hora < 17) {
                $prompt .= "DICA: E tarde — sugira acai, lanches, cafeteria.\n";
            } elseif ($hora >= 18 && $hora < 22) {
                $prompt .= "DICA: E noite — sugira pizzarias, hamburguerias, japones.\n";
            } else {
                $prompt .= "DICA: E madrugada — sugira opcoes que abrem tarde.\n";
            }
            $prompt .= "\nINSTRUCAO ESPECIAL: Se identificar o restaurante com certeza, inclua na resposta o marcador [STORE:numero:nome] onde numero e o ID.\n";
            $prompt .= "Exemplo: Se o cliente quer 'Pizzaria Bella' e ela tem (ID:42), inclua [STORE:42:Pizzaria Bella] na resposta.\n";
            $prompt .= "NUNCA escreva a palavra 'ID' dentro do marcador. Apenas o numero.\n";
            break;

        case 'take_order':
            $prompt .= "ETAPA ATUAL: Anotar o pedido\n";
            $prompt .= "RESTAURANTE: {$storeName}\n";
            // Store info
            if ($storeInfo) {
                if ($storeInfo['rating']) $prompt .= "Nota: " . number_format((float)$storeInfo['rating'], 1) . "/5 | ";
                $prompt .= "Entrega: ~{$storeInfo['delivery_time']} min | ";
                $prompt .= "Taxa entrega: R$" . number_format((float)$storeInfo['delivery_fee'], 2, ',', '.') . "\n";
                if ($storeInfo['min_order'] > 0) {
                    $prompt .= "Pedido minimo: R$" . number_format((float)$storeInfo['min_order'], 2, ',', '.') . "\n";
                }
            }
            $prompt .= "\nCARDAPIO:\n{$menuText}\n\n";

            // Repeat order detected
            if (!empty($context['repeat_order']) && !empty($items)) {
                $prompt .= "*** O CLIENTE PEDIU PARA REPETIR O ULTIMO PEDIDO ***\n";
                $prompt .= "Os itens do ultimo pedido ja foram adicionados automaticamente:\n";
                $total = 0;
                foreach ($items as $item) {
                    $lineTotal = ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                    $total += $lineTotal;
                    $prompt .= "- {$item['quantity']}x {$item['name']} R$" . number_format($lineTotal, 2, ',', '.') . "\n";
                }
                $prompt .= "Subtotal: R$" . number_format($total, 2, ',', '.') . "\n";
                $prompt .= "- Confirme: 'Adicionei os mesmos itens do seu ultimo pedido: [lista]. Quer mudar algo ou esta tudo certo?'\n";
                $prompt .= "- Se disser que esta bom, inclua [NEXT_STEP]\n\n";
            } elseif (!empty($items)) {
                $prompt .= "ITENS JA ANOTADOS:\n";
                $total = 0;
                foreach ($items as $idx => $item) {
                    $lineTotal = ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                    $total += $lineTotal;
                    $prompt .= "- [{$idx}] {$item['quantity']}x {$item['name']} R$" . number_format($lineTotal, 2, ',', '.') . "\n";
                }
                $prompt .= "Subtotal parcial: R$" . number_format($total, 2, ',', '.') . "\n\n";
            }

            // Returning customer suggestions
            if (!empty($lastOrderItems) && empty($items)) {
                $prompt .= "ULTIMO PEDIDO DO CLIENTE NESTA LOJA:\n";
                foreach ($lastOrderItems as $li) {
                    $prompt .= "- {$li['quantity']}x {$li['product_name']} R$" . number_format((float)$li['unit_price'], 2, ',', '.') . "\n";
                }
                $prompt .= "- Mencione: 'Vi que voce ja pediu [itens] antes. Quer repetir ou algo diferente?'\n\n";
            }

            // Popular items
            if (!empty($popularItems) && empty($items)) {
                $prompt .= "MAIS PEDIDOS NESTA LOJA: ";
                $popNames = array_map(fn($p) => $p['product_name'], $popularItems);
                $prompt .= implode(', ', $popNames) . "\n";
                $prompt .= "- Se o cliente nao sabe o que pedir, sugira estes itens populares\n\n";
            }

            $prompt .= "COMPORTAMENTO INTELIGENTE:\n";
            $prompt .= "- Quando ele disser um produto, identifique no cardapio e confirme nome e preco\n";
            $prompt .= "- O cliente pode pedir VARIOS itens de uma vez — parse todos (ex: '2 coxinhas, 1 guarana e 1 pizza')\n";
            $prompt .= "- Pergunte a quantidade SOMENTE se nao foi especificada (se disse 'uma coca', entenda qty=1)\n";
            $prompt .= "- Se pedir algo que nao tem, sugira o mais parecido: 'Nao temos X, mas temos Y que e similar'\n";
            $prompt .= "- Faca upsell NATURAL (nao forcado): se pediu comida sem bebida, sugira 'Vai querer uma bebida pra acompanhar?'\n";
            $prompt .= "- Se pediu pizza, sugira borda recheada. Se pediu acai, sugira complemento\n";
            $prompt .= "- Apos cada item: 'Mais alguma coisa?'\n";
            $prompt .= "- Quando disser que acabou (so isso, e so, nao quero mais, pronto, pode fechar), finalize\n";
            $prompt .= "- Se perguntar 'quanto tempo demora?' responda com base na info do restaurante acima\n";
            $prompt .= "- Se pedir para TIRAR/REMOVER um item: inclua [REMOVE_ITEM:indice] (ex: [REMOVE_ITEM:0])\n";
            $prompt .= "- Se pedir para MUDAR QUANTIDADE: inclua [UPDATE_QTY:indice:nova_quantidade] (ex: [UPDATE_QTY:0:3])\n\n";

            // Option handling instructions
            $prompt .= "OPCOES DE PRODUTO (TAMANHOS, BORDAS, EXTRAS):\n";
            $prompt .= "- No cardapio acima, produtos podem ter OPCOES listadas com '>>' (ex: >> Tamanho, >> Borda Recheada)\n";
            $prompt .= "- Se uma opcao e OBRIGATORIA, voce DEVE perguntar ao cliente ANTES de adicionar o item\n";
            $prompt .= "- Ex: 'Pizza Margherita — qual tamanho? Broto, Media, Grande ou Gigante?'\n";
            $prompt .= "- Se opcao e opcional, ofereça naturalmente: 'Quer borda recheada? Temos catupiry e cheddar'\n";
            $prompt .= "- Inclua as opcoes selecionadas no marcador [ITEM] com [OPT:id1,id2,...] logo depois\n";
            $prompt .= "- O preco total do item = preco base + soma dos price_extra das opcoes\n";
            $prompt .= "- Ex: Pizza R$30 + Grande (+R$20) + Borda Catupiry (+R$8) = R$58\n\n";

            // Dietary/allergen awareness
            if (!empty($context['dietary_question'])) {
                $prompt .= "ALERTA: O CLIENTE PERGUNTOU SOBRE ALERGIAS/DIETA!\n";
                $prompt .= "- Verifique os marcadores [ALERGENOS] nos itens do cardapio acima\n";
                $prompt .= "- Se o produto NAO tem informacao de alergenos, diga: 'Nao tenho certeza sobre os ingredientes desse produto, recomendo confirmar com o restaurante'\n";
                $prompt .= "- Se tem alergenos listados, informe com clareza\n";
                $prompt .= "- NUNCA garanta que um produto e seguro se voce nao tem certeza\n\n";
            }

            // Scheduling
            if (!empty($context['wants_schedule'])) {
                $prompt .= "O CLIENTE QUER AGENDAR O PEDIDO!\n";
                $prompt .= "- Pergunte para quando: data e horario\n";
                $prompt .= "- Aceite expressoes como 'amanha ao meio dia', 'sexta as 19h', 'daqui 2 horas'\n";
                $prompt .= "- Quando definir, inclua [SCHEDULE:YYYY-MM-DD HH:MM] na resposta\n";
                $prompt .= "- Horario minimo: 30min no futuro. Maximo: 7 dias\n";
                $prompt .= "- Confirme: 'Pedido agendado para [data] as [hora], certo?'\n\n";
            }

            // Active promos
            if (!empty($activePromos)) {
                $prompt .= "CUPONS ATIVOS (mencione se o pedido atingir o minimo):\n";
                foreach ($activePromos as $promo) {
                    $desc = $promo['discount_type'] === 'percentage'
                        ? $promo['discount_value'] . '% de desconto'
                        : ($promo['discount_type'] === 'free_delivery' ? 'Frete gratis' : 'R$' . number_format((float)$promo['discount_value'], 2, ',', '.') . ' de desconto');
                    $min = $promo['min_order_value'] > 0 ? ' (pedido min R$' . number_format((float)$promo['min_order_value'], 2, ',', '.') . ')' : '';
                    $prompt .= "- Cupom {$promo['code']}: {$desc}{$min}\n";
                }
                $prompt .= "- Mencione o cupom SOMENTE quando o subtotal estiver perto do minimo: 'Se adicionar mais R$X, voce pode usar o cupom [CODE] e ganhar [desconto]!'\n\n";
            }

            $prompt .= "MARCADORES:\n";
            $prompt .= "- Para cada item SEM opcoes: [ITEM:product_id:quantidade:preco_total:nome]\n";
            $prompt .= "  Ex: [ITEM:123:2:12.90:Coxinha de Frango]\n";
            $prompt .= "- Para cada item COM opcoes: [ITEM:product_id:quantidade:preco_total:nome][OPT:opt_id1,opt_id2]\n";
            $prompt .= "  Ex: [ITEM:1241:1:58.00:Pizza Margherita Grande com Borda Catupiry][OPT:3,5]\n";
            $prompt .= "  (preco_total = preco base + extras das opcoes selecionadas)\n";
            $prompt .= "- Para remover: [REMOVE_ITEM:indice_do_item]\n";
            $prompt .= "- Para alterar qtd: [UPDATE_QTY:indice_do_item:nova_qtd]\n";
            $prompt .= "- Para agendar: [SCHEDULE:YYYY-MM-DD HH:MM]\n";
            $prompt .= "- Quando finalizar itens: [NEXT_STEP]\n";
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
                    $isDefault = !empty($addr['is_default']) ? ' [PADRAO]' : '';
                    $prompt .= "{$num}. {$label}: {$full}{$isDefault}\n";
                }
                $prompt .= "\n";
                if (count($savedAddresses) === 1) {
                    // Only one address — suggest directly
                    $prompt .= "- O cliente so tem 1 endereco salvo. Diga: 'Entrego no mesmo endereco de sempre, na [rua, numero - bairro]?'\n";
                    $prompt .= "- Se disser sim/pode/isso/la mesmo/o mesmo, inclua [ADDRESS:1] e [NEXT_STEP]\n";
                } else {
                    // Multiple — suggest the default one
                    $prompt .= "- Sugira o endereco PADRAO primeiro: 'Entrego no endereco de sempre, na [rua - bairro]? Ou em outro lugar?'\n";
                    $prompt .= "- Se disser sim/pode/isso/la mesmo, inclua [ADDRESS:1] e [NEXT_STEP]\n";
                    $prompt .= "- Se quiser outro, pergunte qual\n";
                }
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
            if ($lastPayment) {
                $paymentLabels = [
                    'dinheiro' => 'Dinheiro',
                    'pix' => 'PIX',
                    'credit_card' => 'Cartao de credito',
                    'debit_card' => 'Cartao de debito',
                    'credito' => 'Cartao de credito',
                    'debito' => 'Cartao de debito',
                ];
                $lastPayLabel = $paymentLabels[$lastPayment] ?? $lastPayment;
                $prompt .= "ULTIMO PAGAMENTO DO CLIENTE: {$lastPayLabel}\n";
                $prompt .= "- Pergunte: 'Da outra vez voce pagou com {$lastPayLabel}. Quer manter ou prefere outra forma?'\n";
                $prompt .= "- Se disser 'o mesmo', 'pode ser', 'mantém', use {$lastPayment}\n\n";
            }
            $prompt .= "- Opcoes: Dinheiro, PIX, Cartao de credito ou debito na maquininha\n";
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
            $deliveryFee = $storeInfo ? (float)$storeInfo['delivery_fee'] : 5.0;
            $serviceFee = round($subtotal * 0.08, 2);
            $total = $subtotal + $deliveryFee + $serviceFee;
            $prompt .= "\nSubtotal: R$" . number_format($subtotal, 2, ',', '.');
            $prompt .= "\nEntrega: R$" . number_format($deliveryFee, 2, ',', '.');
            $prompt .= "\nTaxa de servico: R$" . number_format($serviceFee, 2, ',', '.');
            $prompt .= "\nTOTAL: R$" . number_format($total, 2, ',', '.') . "\n\n";

            // ETA or scheduled time
            if (!empty($context['scheduled_date'])) {
                $schedDate = $context['scheduled_date'];
                $schedTime = $context['scheduled_time'] ?? '12:00';
                $prompt .= "PEDIDO AGENDADO PARA: {$schedDate} as {$schedTime}\n\n";
            } else {
                $eta = $storeInfo ? (int)$storeInfo['delivery_time'] : 40;
                $prompt .= "TEMPO ESTIMADO DE ENTREGA: ~{$eta} minutos\n";
                if ($storeInfo && $storeInfo['busy_mode']) {
                    $prompt .= "(Restaurante em modo OCUPADO — tempo pode ser um pouco maior)\n";
                }
                $prompt .= "\n";
            }

            if ($address) {
                $prompt .= "ENDERECO: " . ($address['full'] ?? ($address['street'] ?? 'N/A')) . "\n";
            }
            if ($payment) {
                $paymentLabels = [
                    'dinheiro' => 'Dinheiro',
                    'pix' => 'PIX',
                    'credit_card' => 'Cartao de credito',
                    'debit_card' => 'Cartao de debito',
                ];
                $payLabel = $paymentLabels[$payment] ?? $payment;
                $prompt .= "PAGAMENTO: {$payLabel}\n";
                if ($payment === 'dinheiro' && !empty($context['payment_change'])) {
                    $prompt .= "TROCO PARA: R$" . number_format((float)$context['payment_change'], 2, ',', '.') . "\n";
                }
                $prompt .= "\n";
            }

            // Active promos reminder
            if (!empty($activePromos)) {
                foreach ($activePromos as $promo) {
                    $minVal = (float)($promo['min_order_value'] ?? 0);
                    if ($subtotal >= $minVal && $minVal > 0) {
                        $desc = $promo['discount_type'] === 'percentage'
                            ? $promo['discount_value'] . '%'
                            : ($promo['discount_type'] === 'free_delivery' ? 'frete gratis' : 'R$' . number_format((float)$promo['discount_value'], 2, ',', '.'));
                        $prompt .= "CUPOM DISPONIVEL: {$promo['code']} ({$desc}) — o pedido atinge o minimo! Mencione.\n";
                        break;
                    }
                }
            }

            $prompt .= "\n- Leia o resumo: itens, total, endereco, forma de pagamento e tempo estimado\n";
            $prompt .= "- Pergunte: 'Posso confirmar o pedido?'\n";
            $prompt .= "- Se o cliente confirmar (sim, pode, confirma, isso, correto, manda, envia), inclua [CONFIRMED] na resposta\n";
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

    // Parse [ITEM:product_id:qty:price:name] optionally followed by [OPT:id1,id2,...]
    if (preg_match_all('/\[ITEM:(\d+):(\d+):([\d.]+):([^\]]+)\](?:\[OPT:([\d,]+)\])?/', $response, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $optionIds = [];
            if (!empty($m[5])) {
                $optionIds = array_map('intval', explode(',', $m[5]));
            }
            $newContext['items'][] = [
                'product_id' => (int)$m[1],
                'quantity' => (int)$m[2],
                'price' => (float)$m[3],
                'name' => trim($m[4]),
                'options' => $optionIds,
                'notes' => '',
            ];
        }
        $cleaned = preg_replace('/\[ITEM:\d+:\d+:[\d.]+:[^\]]+\](?:\[OPT:[\d,]+\])?/', '', $cleaned);
    }

    // Parse [SCHEDULE:YYYY-MM-DD HH:MM]
    if (preg_match('/\[SCHEDULE:(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})\]/', $response, $m)) {
        $newContext['scheduled_date'] = $m[1];
        $newContext['scheduled_time'] = $m[2];
        $cleaned = preg_replace('/\[SCHEDULE:[^\]]+\]/', '', $cleaned);
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

    // Parse [REMOVE_ITEM:index]
    if (preg_match_all('/\[REMOVE_ITEM:(\d+)\]/', $response, $matches, PREG_SET_ORDER)) {
        // Remove in reverse order to avoid index shifting
        $indicesToRemove = array_map(fn($m) => (int)$m[1], $matches);
        rsort($indicesToRemove);
        foreach ($indicesToRemove as $idx) {
            if (isset($newContext['items'][$idx])) {
                array_splice($newContext['items'], $idx, 1);
            }
        }
        $cleaned = preg_replace('/\[REMOVE_ITEM:\d+\]/', '', $cleaned);
    }

    // Parse [UPDATE_QTY:index:new_qty]
    if (preg_match_all('/\[UPDATE_QTY:(\d+):(\d+)\]/', $response, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $idx = (int)$m[1];
            $qty = (int)$m[2];
            if (isset($newContext['items'][$idx])) {
                if ($qty <= 0) {
                    array_splice($newContext['items'], $idx, 1);
                } else {
                    $newContext['items'][$idx]['quantity'] = $qty;
                }
            }
        }
        $cleaned = preg_replace('/\[UPDATE_QTY:\d+:\d+\]/', '', $cleaned);
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

        // Handle scheduling
        $isScheduled = !empty($context['scheduled_date']);
        if ($isScheduled) {
            $db->prepare("UPDATE om_market_orders SET is_scheduled = 1, scheduled_date = ?, scheduled_time = ? WHERE order_id = ?")
               ->execute([$context['scheduled_date'], $context['scheduled_time'] ?? '12:00', $orderId]);
        }

        // Insert items
        $stmtItem = $db->prepare("
            INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtOpt = $db->prepare("
            INSERT INTO om_order_item_options (order_item_id, option_id, option_group_name, option_name, price_extra)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($items as $item) {
            $qty = (int)($item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $itemTotal = round($price * $qty, 2);
            $stmtItem->execute([
                $orderId, $item['product_id'] ?? null, $item['name'],
                $qty, $price, $itemTotal, $item['notes'] ?? null,
            ]);

            // Insert item options if any
            $itemOptions = $item['options'] ?? [];
            if (!empty($itemOptions) && is_array($itemOptions)) {
                // Get the last inserted item ID
                $itemId = (int)$db->lastInsertId();
                if (!$itemId) {
                    $lastItem = $db->query("SELECT MAX(id) as id FROM om_market_order_items WHERE order_id = {$orderId}")->fetch();
                    $itemId = (int)($lastItem['id'] ?? 0);
                }
                if ($itemId > 0) {
                    foreach ($itemOptions as $optId) {
                        if (!is_int($optId)) continue;
                        // Look up option details
                        try {
                            $optInfo = $db->prepare("
                                SELECT o.name, o.price_extra, og.name AS group_name
                                FROM om_product_options o
                                JOIN om_product_option_groups og ON og.id = o.group_id
                                WHERE o.id = ?
                            ");
                            $optInfo->execute([$optId]);
                            $opt = $optInfo->fetch();
                            if ($opt) {
                                $stmtOpt->execute([$itemId, $optId, $opt['group_name'], $opt['name'], $opt['price_extra']]);
                            }
                        } catch (Exception $e) {
                            error_log("[twilio-voice-ai] Option insert error: " . $e->getMessage());
                        }
                    }
                }
            }
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

function fetchStoreInfo(PDO $db, int $storeId): ?array {
    $stmt = $db->prepare("
        SELECT name, rating, delivery_fee, delivery_time_min, delivery_time_max,
               busy_mode, current_prep_time, default_prep_time, min_order_value, is_open
        FROM om_market_partners WHERE partner_id = ?
    ");
    $stmt->execute([$storeId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $deliveryTime = $row['delivery_time_max'] ?? $row['delivery_time_min'] ?? 40;
    if ($row['busy_mode'] && $row['current_prep_time']) {
        $deliveryTime = (int)$row['current_prep_time'] + 15; // prep + delivery buffer
    }

    return [
        'name' => $row['name'],
        'rating' => $row['rating'],
        'delivery_fee' => (float)($row['delivery_fee'] ?? 5.00),
        'delivery_time' => $deliveryTime,
        'min_order' => (float)($row['min_order_value'] ?? 0),
        'is_open' => (bool)$row['is_open'],
        'busy_mode' => (bool)$row['busy_mode'],
    ];
}

function fetchLastOrderItems(PDO $db, int $customerId, int $storeId): array {
    $stmt = $db->prepare("
        SELECT oi.product_id, oi.name AS product_name, oi.quantity, oi.price AS unit_price
        FROM om_market_order_items oi
        JOIN om_market_orders o ON o.order_id = oi.order_id
        WHERE o.customer_id = ? AND o.partner_id = ? AND o.status NOT IN ('cancelled','refunded')
        ORDER BY o.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$customerId, $storeId]);
    $rows = $stmt->fetchAll();

    // Deduplicate by product — take the most recent occurrence
    $items = [];
    $seen = [];
    foreach ($rows as $r) {
        $key = $r['product_id'] ?? $r['product_name'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $items[] = [
            'product_id' => (int)($r['product_id'] ?? 0),
            'name' => $r['product_name'],
            'quantity' => (int)$r['quantity'],
            'price' => (float)$r['unit_price'],
            'options' => [],
            'notes' => '',
        ];
    }
    return $items;
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
