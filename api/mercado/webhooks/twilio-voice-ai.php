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
require_once __DIR__ . '/../helpers/voice-tts.php';
if (file_exists(__DIR__ . '/../helpers/ai-memory.php')) {
    require_once __DIR__ . '/../helpers/ai-memory.php';
}

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
    $claude = new ClaudeClient('claude-sonnet-4-20250514', 45, 0);

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
        // Try to create a record if none exists (possible timing issue between voice.php and route.php)
        error_log("[twilio-voice-ai] No call record for CallSid={$callSid} — creating one");
        try {
            $db->prepare("
                INSERT INTO om_callcenter_calls (twilio_call_sid, customer_phone, direction, status, started_at)
                VALUES (?, ?, 'inbound', 'ai_handling', NOW())
                ON CONFLICT (twilio_call_sid) DO NOTHING
            ")->execute([$callSid, $callerPhone]);
            // Re-fetch
            $stmt->execute([$callSid]);
            $call = $stmt->fetch();
        } catch (Exception $e) {
            error_log("[twilio-voice-ai] Failed to create call record: " . $e->getMessage());
        }
    }
    if (!$call) {
        error_log("[twilio-voice-ai] Still no call record — re-prompting");
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
        echo ttsSayOrPlay("Desculpa, me fala de novo. O que você precisa?");
        echo '</Gather>';
        echo ttsSayOrPlay("Aperta zero se quiser falar com alguém.");
        echo '<Redirect method="POST">' . escXml($selfUrl) . '</Redirect>';
        echo '</Response>';
        exit;
    }

    $callId = (int)$call['id'];
    $customerName = $call['customer_name'];
    $customerId = $call['customer_id'] ? (int)$call['customer_id'] : null;

    // Load AI context from a separate column or notes JSON
    $aiContext = loadAiContext($db, $callId);
    $step = $aiContext['step'] ?? 'identify_store';
    $conversationHistory = $aiContext['history'] ?? [];

    // If digits look like a CEP (5-8 digits), treat as speech
    if (strlen($digits) >= 5 && ctype_digit($digits) && empty($speechResult)) {
        $speechResult = $digits;
    }

    // Track user input + speech confidence
    $userInput = $speechResult ?: ($digits ?: '');
    $speechConfidence = isset($_POST['Confidence']) ? (float)$_POST['Confidence'] : 1.0;

    // -- Smart intent detection --
    if (!empty($userInput)) {
        $lowerInput = mb_strtolower($userInput, 'UTF-8');

        // 1. Transfer to human agent
        $transferKeywords = ['atendente', 'agente', 'pessoa', 'humano', 'operador', 'falar com alguem', 'falar com alguém', 'transfere', 'passa pra alguem'];
        foreach ($transferKeywords as $kw) {
            if (mb_strpos($lowerInput, $kw) !== false) {
                transferToAgent($db, $callId, $callerPhone, $customerName, $customerId, $routeUrl);
                exit;
            }
        }

        // 2. Goodbye / end call — handle gracefully
        $goodbyePhrases = ['tchau', 'até mais', 'ate mais', 'falou', 'valeu', 'obrigado tchau', 'obrigada tchau', 'pode desligar', 'era só isso', 'nao quero mais nada', 'nada mais'];
        if (in_array(trim($lowerInput), $goodbyePhrases) || (mb_strpos($lowerInput, 'tchau') !== false && mb_strlen($lowerInput) < 30)) {
            // Check if they have a pending order in progress
            $hasDraftItems = !empty($aiContext['items'] ?? []);
            if ($hasDraftItems) {
                // Don't end yet — warn about items
                $aiContext['wants_goodbye'] = true;
                // Let Claude handle: "Peraí, você tem itens no pedido! Quer finalizar ou cancelar?"
            } else {
                saveAiContext($db, $callId, $aiContext);
                $db->prepare("UPDATE om_callcenter_calls SET status = 'completed', ended_at = NOW() WHERE id = ?")->execute([$callId]);
                echo '<?xml version="1.0" encoding="UTF-8"?>';
                echo '<Response>';
                echo ttsSayOrPlay("Beleza! Se precisar de algo, é só ligar. Tchau e bom apetite!");
                echo '<Hangup/>';
                echo '</Response>';
                exit;
            }
        }

        // 3. Thank you (without goodbye) — acknowledge and continue
        $thankPhrases = ['obrigado', 'obrigada', 'valeu', 'brigado', 'brigada'];
        $isThanks = false;
        foreach ($thankPhrases as $tp) {
            if (mb_strpos($lowerInput, $tp) !== false && mb_strpos($lowerInput, 'tchau') === false) {
                $isThanks = true;
                break;
            }
        }
        // Don't waste Claude call for standalone "obrigado"
        if ($isThanks && mb_strlen(trim($lowerInput)) < 15 && empty($aiContext['items'] ?? []) && ($step === 'identify_store' || $step === 'question')) {
            $aiContext['history'] = $conversationHistory;
            saveAiContext($db, $callId, $aiContext);
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
            echo ttsSayOrPlay("De nada! Mais alguma coisa?");
            echo '</Gather>';
            echo ttsSayOrPlay("Se não precisar de mais nada, pode desligar. Tchau!");
            echo '<Hangup/>';
            echo '</Response>';
            exit;
        }

        // 4. Support intents — switch to support mode from any step
        $supportKeywords = ['status', 'cancelar', 'cancela', 'rastrear', 'rastreio', 'cadê meu pedido', 'cade meu pedido', 'onde ta', 'onde está', 'meu pedido', 'reclamação', 'reclamacao', 'problema', 'reembolso', 'devolver', 'pedido anterior', 'pedido atrasado', 'nao chegou', 'não chegou', 'veio errado', 'faltou', 'cobraram errado', 'estorno'];
        foreach ($supportKeywords as $sk) {
            if (mb_strpos($lowerInput, $sk) !== false) {
                $aiContext['step'] = 'support';
                $step = 'support';
                // Save where they were so they can come back
                $aiContext['previous_step'] = $step !== 'support' ? ($aiContext['step'] ?? 'identify_store') : null;
                break;
            }
        }

        // 5. Question/doubt intent — only if not already in support or mid-order
        if ($step !== 'support' && $step !== 'take_order' && $step !== 'confirm_order') {
            $questionKeywords = ['duvida', 'dúvida', 'pergunta', 'como funciona', 'quanto custa', 'informação', 'informacao', 'vocês entregam', 'voces entregam', 'qual horário', 'qual horario', 'como faço', 'como faco', 'preciso de ajuda'];
            foreach ($questionKeywords as $qk) {
                if (mb_strpos($lowerInput, $qk) !== false) {
                    $aiContext['step'] = 'question';
                    $step = 'question';
                    break;
                }
            }
        }

        // 6. "Yes/No" detection — track for Claude context
        $yesWords = ['sim', 'isso', 'pode', 'manda', 'bora', 'confirma', 'certo', 'tá bom', 'ta bom', 'isso mesmo', 'exato', 'é isso', 'e isso', 'uhum', 'aham', 'claro', 'com certeza', 'quero', 'quero sim'];
        $noWords = ['não', 'nao', 'nope', 'nem', 'de jeito nenhum', 'nada', 'mudei de ideia', 'deixa quieto', 'esquece', 'cancelar'];
        $isYes = in_array(trim($lowerInput), $yesWords);
        $isNo = in_array(trim($lowerInput), $noWords);
        if ($isYes) $aiContext['last_answer'] = 'yes';
        if ($isNo) $aiContext['last_answer'] = 'no';
    }

    if ($digits === '0' && empty($speechResult)) {
        transferToAgent($db, $callId, $callerPhone, $customerName, $customerId, $routeUrl);
        exit;
    }

    // Handle empty input (Gather timed out) — don't waste Claude call
    if (empty($userInput)) {
        $silenceCount = ($aiContext['silence_count'] ?? 0) + 1;
        $aiContext['silence_count'] = $silenceCount;
        $aiContext['history'] = $conversationHistory;
        saveAiContext($db, $callId, $aiContext);

        $silencePrompts = [
            "Oi, tá aí? Pode falar que eu tô ouvindo!",
            "Opa, não ouvi nada. Pode falar mais perto do telefone?",
            "Hmm, acho que a ligação tá ruim. Pode repetir o que você precisa?",
        ];

        if ($silenceCount >= 4) {
            // Too many silences — end gracefully
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo ttsSayOrPlay("Parece que a ligação caiu. Se precisar, é só ligar de novo! Tchau!");
            echo '<Hangup/>';
            echo '</Response>';
            $db->prepare("UPDATE om_callcenter_calls SET status = 'completed', ended_at = NOW() WHERE id = ?")->execute([$callId]);
            exit;
        }

        $msg = $silencePrompts[min($silenceCount - 1, count($silencePrompts) - 1)];
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo '<Gather input="speech dtmf" timeout="10" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
        echo ttsSayOrPlay($msg);
        echo '</Gather>';
        echo ttsSayOrPlay("Aperta zero se quiser falar com alguém.");
        echo '<Redirect method="POST">' . escXml($selfUrl) . '</Redirect>';
        echo '</Response>';
        exit;
    }

    // Reset silence counter when we get input
    $aiContext['silence_count'] = 0;

    // Add user message to history
    $conversationHistory[] = ['role' => 'user', 'content' => $userInput];

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
            echo '<Gather input="speech dtmf" timeout="15" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
            echo ttsSayOrPlay("Tranquilo, fica à vontade!");
            echo '</Gather>';
            echo ttsSayOrPlay("Oi, ainda tá aí? Pode falar quando quiser!");
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
            echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
            echo ttsSayOrPlay("Desculpa, não peguei. Pode repetir?");
            echo '</Gather>';
            echo ttsSayOrPlay("Se preferir, aperta zero que eu te passo pra alguém.");
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

    // Detect CEP in user input (if they say it during conversation)
    if (!empty($userInput) && ($step === 'identify_store' || $step === 'question' || $step === 'get_address') && empty($aiContext['cep_data'])) {
        $digitsOnly = preg_replace('/\D/', '', $userInput);
        // Also match spoken CEP like "13040-106"
        if (strlen($digitsOnly) !== 8 && preg_match('/(\d{5})\s*-?\s*(\d{3})/', $userInput, $cepM)) {
            $digitsOnly = $cepM[1] . $cepM[2];
        }
        if (strlen($digitsOnly) === 8 && preg_match('/^[0-9]{5}/', $digitsOnly)) {
            $ctx = stream_context_create(['http' => ['timeout' => 3], 'ssl' => ['verify_peer' => false]]);
            $json = @file_get_contents("https://viacep.com.br/ws/{$digitsOnly}/json/", false, $ctx);
            $cepResolved = false;
            if ($json) {
                $data = json_decode($json, true);
                if ($data && empty($data['erro'])) {
                    $aiContext['cep'] = $digitsOnly;
                    $aiContext['cep_data'] = [
                        'street' => $data['logradouro'] ?? '',
                        'neighborhood' => $data['bairro'] ?? '',
                        'city' => $data['localidade'] ?? '',
                        'state' => $data['uf'] ?? '',
                        'cep' => $digitsOnly,
                    ];
                    $cepResolved = true;
                }
            }
            // If CEP not found, try base (xxxxx000)
            if (!$cepResolved) {
                $cepBase = substr($digitsOnly, 0, 5) . '000';
                if ($cepBase !== $digitsOnly) {
                    $json2 = @file_get_contents("https://viacep.com.br/ws/{$cepBase}/json/", false, $ctx);
                    if ($json2) {
                        $data2 = json_decode($json2, true);
                        if ($data2 && empty($data2['erro'])) {
                            $aiContext['cep'] = $digitsOnly;
                            $aiContext['cep_data'] = [
                                'street' => '', 'neighborhood' => $data2['bairro'] ?? '',
                                'city' => $data2['localidade'] ?? '', 'state' => $data2['uf'] ?? '',
                                'cep' => $digitsOnly,
                            ];
                            $cepResolved = true;
                        }
                    }
                }
            }
            // Find nearby stores by CEP prefix and city
            if ($cepResolved || true) { // Even without ViaCEP, try to match by CEP prefix
                $aiContext['cep'] = $aiContext['cep'] ?? $digitsOnly;
                $cep3 = substr($digitsOnly, 0, 3);
                $cep5 = substr($digitsOnly, 0, 5);
                $allSt = $db->query("SELECT partner_id, name, cep, cep_inicio, cep_fim, city FROM om_market_partners WHERE status = '1' AND name != '' ORDER BY rating DESC NULLS LAST LIMIT 50")->fetchAll();
                $aiContext['nearby_stores'] = [];
                foreach ($allSt as $p) {
                    $inicio = preg_replace('/\D/', '', $p['cep_inicio'] ?? '');
                    $fim = preg_replace('/\D/', '', $p['cep_fim'] ?? '');
                    if ($inicio && $fim) {
                        $len = strlen($inicio);
                        $check = $len === 5 ? $cep5 : ($len === 3 ? $cep3 : $digitsOnly);
                        if (intval($check) >= intval($inicio) && intval($check) <= intval($fim)) {
                            $aiContext['nearby_stores'][] = ['id' => (int)$p['partner_id'], 'name' => $p['name']];
                            continue;
                        }
                    }
                    $partnerCep = preg_replace('/\D/', '', $p['cep'] ?? '');
                    if ($partnerCep && substr($partnerCep, 0, 3) === $cep3) {
                        $aiContext['nearby_stores'][] = ['id' => (int)$p['partner_id'], 'name' => $p['name']];
                        continue;
                    }
                    // City match
                    if (!empty($aiContext['cep_data']['city']) && !empty($p['city'])) {
                        $cepCity = mb_strtolower(trim($aiContext['cep_data']['city']), 'UTF-8');
                        $storeCity = mb_strtolower(trim($p['city']), 'UTF-8');
                        if ($cepCity === $storeCity) {
                            $aiContext['nearby_stores'][] = ['id' => (int)$p['partner_id'], 'name' => $p['name']];
                        }
                    }
                }
                // Fallback: show all stores
                if (empty($aiContext['nearby_stores'])) {
                    foreach ($allSt as $p) {
                        if (!empty(trim($p['name']))) {
                            $aiContext['nearby_stores'][] = ['id' => (int)$p['partner_id'], 'name' => $p['name']];
                        }
                    }
                }
                error_log("[twilio-voice-ai] CEP detected mid-conversation: {$digitsOnly} → " . ($aiContext['cep_data']['city'] ?? 'unknown') . " | " . count($aiContext['nearby_stores']) . " stores");
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

    // -- Load AI memory for returning customers --
    $memoryContext = '';
    $memoryGreetingHint = null;
    if (function_exists('aiMemoryBuildContext')) {
        try {
            $memoryContext = aiMemoryBuildContext($db, $callerPhone, $customerId);
            $memoryGreetingHint = aiMemoryGetGreeting($db, $callerPhone, $customerId);
        } catch (Exception $e) {
            error_log("[twilio-voice-ai] Memory load error (non-fatal): " . $e->getMessage());
        }
    }

    // Track conversation turns
    $turnCount = ($aiContext['turn_count'] ?? 0) + 1;
    $aiContext['turn_count'] = $turnCount;

    // Track if AI already did upsell (don't repeat)
    $didUpsell = $aiContext['did_upsell'] ?? false;

    // -- Build system prompt --
    $extraData = [
        'store_info' => $storeInfo,
        'popular_items' => $popularItems ?? [],
        'active_promos' => $activePromos ?? [],
        'last_payment' => $lastPayment ?? null,
        'customer_stats' => $customerStats,
        'default_address' => $defaultAddress,
        'memory_context' => $memoryContext,
        'memory_greeting_hint' => $memoryGreetingHint,
        'turn_count' => $turnCount,
        'did_upsell' => $didUpsell,
        'wants_goodbye' => $aiContext['wants_goodbye'] ?? false,
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
        respondTwiml("Desculpe, tô com dificuldade pra processar. Vou transferir pra um atendente.", true);
    }

    $aiResponse = trim($result['text'] ?? '');
    error_log("[twilio-voice-ai] AI response: " . mb_substr($aiResponse, 0, 200));

    // -- Parse AI response for state transitions --
    $newContext = parseAiResponse($aiResponse, $aiContext, $db);
    $aiResponse = $newContext['cleaned_response'];

    // Track if AI did an upsell (detect "bebida", "sobremesa", "quer adicionar" in response)
    $upsellPhrases = ['bebida', 'sobremesa', 'quer adicionar', 'quer incluir', 'combinar com', 'acompanha'];
    foreach ($upsellPhrases as $up) {
        if (mb_strpos(mb_strtolower($aiResponse, 'UTF-8'), $up) !== false) {
            $newContext['did_upsell'] = true;
            break;
        }
    }

    // Clear wants_goodbye flag after processing
    $newContext['wants_goodbye'] = false;

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
                $schedMsg = $newContext['scheduled_date'] . ' às ' . ($newContext['scheduled_time'] ?? '12:00');
                $finalMsg = "Show, tá feito! Seu pedido {$orderNumber} tá agendado pra {$schedMsg}. "
                    . "Total de {$total} reais. "
                    . "Vou te mandar um SMS com tudo certinho. "
                    . "Valeu por pedir pelo SuperBora! Até lá!";
            } else {
                $finalMsg = "Pronto, pedido feito! Número {$orderNumber}, total de {$total} reais. "
                    . "Já já você recebe um SMS com os detalhes. "
                    . "Valeu por pedir pelo SuperBora! Bom apetite!";
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

            // Learn from this order for future personalization
            if (function_exists('aiMemoryLearn')) {
                try {
                    aiMemoryLearn($db, $callerPhone, $customerId, [
                        'store_id' => $newContext['store_id'] ?? null,
                        'store_name' => $newContext['store_name'] ?? $storeName,
                        'items' => $newContext['items'] ?? [],
                        'payment_method' => $newContext['payment_method'] ?? null,
                        'total' => $orderResult['total'],
                        'hour' => (int)date('H'),
                        'day_of_week' => (int)date('w'),
                    ]);
                } catch (Exception $e) {
                    error_log("[twilio-voice-ai] Memory learn error (non-fatal): " . $e->getMessage());
                }
            }

            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo ttsSayOrPlay($finalMsg);
            echo '<Hangup/>';
            echo '</Response>';

            saveAiContext($db, $callId, $newContext);
            exit;
        } else {
            $aiResponse = "Desculpe, deu um probleminha ao processar seu pedido: " . $orderResult['error'] . ". Quer tentar de novo?";
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
        echo ttsSayOrPlay($aiResponse);
        echo '<Hangup/>';
        echo '</Response>';
    } else {
        // Continue conversation with Gather — enhanced speech for better recognition
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo '<Gather input="speech dtmf" timeout="10" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
        echo ttsSayOrPlay($aiResponse);
        echo '</Gather>';
        echo ttsSayOrPlay("Oi, tô aqui! Pode falar, eu tô ouvindo.");
        echo '<Redirect method="POST">' . escXml($selfUrl) . '</Redirect>';
        echo '</Response>';
    }

} catch (Exception $e) {
    error_log("[twilio-voice-ai] Error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    // On error, re-prompt instead of auto-transferring to agent
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
    echo ttsSayOrPlay("Desculpa, deu um probleminha aqui. Pode repetir o que você precisa?");
    echo '</Gather>';
    echo ttsSayOrPlay("Aperta zero se quiser falar com alguém.");
    echo '<Redirect method="POST">' . escXml($selfUrl) . '</Redirect>';
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
    echo ttsSayOrPlay($message);
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
    $queueMsg = "Beleza! Vou te passar pra um atendente.";
    if ($agentsOnline === 0) {
        $queueMsg .= " Agora não tem ninguém disponível, mas fica na linha que já já alguém te atende, tá?";
    } elseif ($queueDepth > 0) {
        $estimatedWait = $queueDepth * 2;
        $queueMsg .= " Tem " . $queueDepth . " pessoa na sua frente, uns {$estimatedWait} minutinhos de espera.";
    } else {
        $queueMsg .= " Já já alguém te atende!";
    }

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo ttsSayOrPlay($queueMsg);
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
                $req = $group['required'] ? 'OBRIGATÓRIO' : 'opcional';
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
    return $text ?: 'Cardápio não disponível';
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

    $prompt = "Você é a Bora, atendente do SuperBora (delivery de comida). Você tá numa ligação telefônica com um cliente.\n\n";

    $prompt .= "## QUEM VOCÊ É\n";
    $prompt .= "Você é a Bora — uma atendente humana, esperta, que manja de comida e conhece os restaurantes da região.\n";
    $prompt .= "Você é a garota que trabalha no delivery há anos, conhece os clientes pelo nome, sabe o cardápio de cor.\n";
    $prompt .= "Tem personalidade forte: opinião sobre comida, humor, carisma. Não é robô — é gente de verdade.\n";
    $prompt .= "Você genuinamente se importa com o cliente: quer que ele coma bem, pague justo, receba rápido.\n\n";

    $prompt .= "## COMO FALAR — REGRAS ABSOLUTAS\n";
    $prompt .= "1. MÁXIMO 2 frases curtas por resposta. Isso vira áudio de voz — ninguém aguenta ouvir textão.\n";
    $prompt .= "2. Contrações OBRIGATÓRIAS: 'tô', 'tá', 'pra', 'pro', 'né', 'cê', 'num' (em um), 'dum' (de um)\n";
    $prompt .= "3. NUNCA repita informação que já disse na conversa. Zero redundância.\n";
    $prompt .= "4. NUNCA diga: 'Como posso te ajudar?', 'Estou aqui para ajudar', 'como assistente', 'como IA'\n";
    $prompt .= "5. NUNCA invente preços ou produtos — use SÓ o cardápio fornecido\n";
    $prompt .= "6. SEMPRE use acentos corretos: você, não, é, tá, tô, já, aí, até, também, opções, endereço\n";
    $prompt .= "7. Use 'você' (nunca senhor/senhora). Nome do cliente SÓ na primeira interação.\n";
    $prompt .= "8. Alergia/ingrediente sem info → 'não tenho certeza, melhor confirmar com o restaurante'\n\n";

    $prompt .= "## VOCABULÁRIO NATURAL\n";
    $prompt .= "Expressões de confirmação (varie!): 'show!', 'beleza!', 'anotado!', 'fechou!', 'pode crê!', 'bora!', 'boa!', 'massa!', 'top!', 'ótimo!', 'perfeito!', 'certinho!', 'combinado!'\n";
    $prompt .= "Reações naturais: 'hmm', 'ahh', 'eita', 'uau', 'opa', 'iii', 'olha só', 'vish'\n";
    $prompt .= "Transições: 'e aí', 'então', 'agora', 'bom', 'olha', 'ah', 'pois é'\n";
    $prompt .= "REGRA: NUNCA use a mesma expressão 2x seguidas. Se disse 'show!', a próxima tem que ser outra.\n\n";

    $prompt .= "## INTELIGÊNCIA CONVERSACIONAL AVANÇADA\n\n";

    $prompt .= "### Leitura emocional do cliente\n";
    $prompt .= "- PRESSA (respostas curtas, 'rápido', 'logo') → seja ultra-direto, zero enrolação, pule elogios\n";
    $prompt .= "- INDECISO ('não sei', 'tanto faz', silêncio longo) → sugira com convicção: 'Olha, a X daqui é absurda, todo mundo pede!'\n";
    $prompt .= "- DE BOA (batendo papo, risadas) → relaxe, comente a comida, brinque junto\n";
    $prompt .= "- FRUSTRADO (reclamação, tom seco) → empatia primeiro, solução depois: 'Putz, entendo. Deixa eu resolver isso.'\n";
    $prompt .= "- CONFUSO (respostas sem sentido, contradições) → reformule com carinho: 'Só pra eu entender: você quer X ou Y?'\n";
    $prompt .= "- IDOSO (fala devagar, repete) → paciência extra, confirme cada etapa, fale mais claro\n";
    $prompt .= "- CRIANÇA no telefone → seja super simpática, pergunte se tem adulto por perto pro pagamento\n\n";

    $prompt .= "### Recuperação de conversa\n";
    $prompt .= "- Se não entendeu → 'Desculpa, não peguei. Pode repetir?' (NUNCA finja que entendeu)\n";
    $prompt .= "- Se o cliente disse algo ambíguo → peça esclarecimento naturalmente: 'Quando cê fala X, é o Y ou o Z?'\n";
    $prompt .= "- Se o cliente muda de ideia no meio → aceite numa boa: 'De boa! Então vamos trocar.'\n";
    $prompt .= "- Se o cliente interrompe/corta → pare e ouça, não tente completar a frase dele\n";
    $prompt .= "- Se o cliente fala com outra pessoa no fundo → espere e pergunte: 'Oi, decidiu?'\n";
    $prompt .= "- Se o áudio veio cortado/parcial → não processe como pedido, peça pra repetir\n\n";

    $prompt .= "### Troca de contexto (FUNDAMENTAL)\n";
    $prompt .= "O cliente pode mudar de assunto A QUALQUER MOMENTO. Exemplos:\n";
    $prompt .= "- Tá fazendo pedido → pergunta 'ah, e meu outro pedido, chegou?' → mude pra suporte, depois volte\n";
    $prompt .= "- Tá tirando dúvida → 'bom, então quero pedir' → mude pra pedido\n";
    $prompt .= "- Tá no endereço → 'peraí, quero adicionar mais um item' → volte pros itens\n";
    $prompt .= "- Tá confirmando → 'na verdade tira a coca' → remova e re-confirme\n";
    $prompt .= "SEMPRE se adapte. Nunca diga 'primeiro precisamos terminar X'. Siga o cliente.\n\n";

    $prompt .= "### Multi-intent (cliente pede 2 coisas de uma vez)\n";
    $prompt .= "- 'Quero pizza e também ver meu pedido' → trate suporte primeiro (é mais urgente), depois inicie pedido\n";
    $prompt .= "- 'Me vê 2 coxinhas e uma coca grande' → anote os 3 itens de uma vez\n";
    $prompt .= "- 'Manda pro mesmo lugar, paga em PIX' → preencha endereço E pagamento juntos\n\n";

    $prompt .= "### Proatividade inteligente\n";
    $prompt .= "- Se pediu comida sem bebida → 'Vai querer uma bebida?' (1x só, não insista)\n";
    $prompt .= "- Se pediu pra 1 pessoa → não sugira combo família. Se pediu pra vários → sugira combo se tiver\n";
    $prompt .= "- Se tem promoção relevante → mencione NATURALMENTE: 'Ah, e hoje tem [promo]!'\n";
    $prompt .= "- Se o total tá quase atingindo frete grátis → 'Falta só R$X pro frete grátis, quer adicionar algo?'\n";
    $prompt .= "- Se é horário de pico → avise: 'Tá um pouco movimentado, pode demorar uns minutinhos a mais'\n";
    $prompt .= "- NUNCA sugira mais de 1 upsell por conversa. Respeite o NÃO do cliente.\n\n";

    $prompt .= "### Humor e personalidade\n";
    $prompt .= "- Humor leve é BEM-VINDO: 'Eita, tá com fome hein!' / 'Escolha boa, eu pediria a mesma!'\n";
    $prompt .= "- Se o cliente faz piada → ria junto: 'Haha, com certeza!'\n";
    $prompt .= "- Fim de semana → pode ser mais solta: 'Fim de semana pede pizza, né?'\n";
    $prompt .= "- Madrugada → 'Fominha da madruga? Haha, conheço bem!'\n";
    $prompt .= "- Pedido grande → 'Opa, vai ter festa aí? Haha'\n";
    $prompt .= "- Mesmo pedido de sempre → 'O clássico de sempre, né? Você não erra!'\n";
    $prompt .= "- NUNCA force humor se o cliente tá sério ou com pressa\n\n";

    $prompt .= "### Situações especiais\n";
    $prompt .= "- NÚMERO ERRADO: Se o cliente parece confuso sobre o que é o SuperBora → 'Aqui é o SuperBora, delivery de comida! Posso te ajudar?'\n";
    $prompt .= "- TROTE: Se parecer trote (risos no fundo, pedidos absurdos) → trate normalmente mas não processe pedidos falsos\n";
    $prompt .= "- SURDO/MUDO: Se só receber DTMF (números) sem voz → guie por números: 'Aperta 1 pra pedir, 2 pra ver pedido'\n";
    $prompt .= "- BARULHO: Se o áudio tá cheio de ruído → 'Tá um pouco difícil de ouvir, pode falar mais perto do telefone?'\n";
    $prompt .= "- GRUPO decidindo junto → tenha paciência: 'Sem pressa, discute aí que eu espero!'\n";
    $prompt .= "- RECLAMAÇÃO GRAVE → empatia total, não minimize: 'Putz, sinto muito por isso. Vou resolver pra você.'\n\n";

    $prompt .= "## FLUXO PRINCIPAL\n";
    $prompt .= "O que o cliente pode querer:\n";
    $prompt .= "1. FAZER PEDIDO → restaurante → itens → endereço → pagamento → confirma\n";
    $prompt .= "2. TIRAR DÚVIDA → responda e pergunte se quer pedir\n";
    $prompt .= "3. SUPORTE → ver status, cancelar, problema com pedido\n";
    $prompt .= "4. MISTO → combinar qualquer coisa acima (ex: ver status + fazer novo pedido)\n";
    $prompt .= "Se não ficou claro o que ele quer: 'E aí, vai querer pedir algo ou precisa de ajuda com outra coisa?'\n\n";
    $prompt .= "## CONTEXTO\n";
    $prompt .= "- Hora: " . date('H:i') . " ({$periodo})\n";
    $prompt .= "- Dia: " . ['Domingo','Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira','Sexta-feira','Sábado'][(int)date('w')] . "\n";

    // Turn count info
    $turnCount = $extraData['turn_count'] ?? 0;
    if ($turnCount > 0) {
        $prompt .= "- Turno da conversa: #{$turnCount}\n";
    }
    if ($turnCount > 8) {
        $prompt .= "- ⚠️ Conversa longa — seja mais direto, tente concluir logo\n";
    }

    // Upsell tracking
    if (!empty($extraData['did_upsell'])) {
        $prompt .= "- Já fez upsell nessa conversa — NÃO sugira mais nada\n";
    }

    // Goodbye with pending items
    if (!empty($extraData['wants_goodbye'])) {
        $prompt .= "- ⚠️ CLIENTE QUER DESLIGAR mas tem itens no pedido! Pergunte: 'Peraí, você tem itens no pedido! Quer finalizar ou cancelar?'\n";
    }

    $hora = (int)date('H');
    $dow = (int)date('w');
    if ($dow === 0 || $dow === 6) {
        $prompt .= "- Fim de semana — clientes pedem mais, sugira combos se relevante\n";
    }
    if ($hora >= 23 || $hora < 6) {
        $prompt .= "- Madrugada — seja compreensivo\n";
    }
    $prompt .= "\n";

    // Memory context (learned from past interactions)
    $memoryCtx = $extraData['memory_context'] ?? '';
    $memoryHint = $extraData['memory_greeting_hint'] ?? null;
    if (!empty($memoryCtx)) {
        $prompt .= "## MEMÓRIA DO CLIENTE (informações de ligações anteriores)\n";
        $prompt .= $memoryCtx . "\n";
        $prompt .= "USE essas informações pra personalizar o atendimento! Ex: 'Vi que você sempre pede da [loja], quer repetir?'\n";
        $prompt .= "Mas NÃO despeje tudo de uma vez — use naturalmente ao longo da conversa.\n\n";
    }
    if (!empty($memoryHint)) {
        $prompt .= "DICA DE ABORDAGEM: {$memoryHint}\n\n";
    }

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
            $prompt .= "## ETAPA: Descobrir de onde o cliente quer pedir\n\n";

            // Active order context
            if (!empty($context['has_active_orders']) && !empty($context['active_order'])) {
                $ao = $context['active_order'];
                $statusMap = ['pending' => 'esperando confirmação', 'accepted' => 'aceito', 'preparing' => 'sendo preparado', 'em_preparo' => 'sendo preparado', 'ready' => 'pronto', 'delivering' => 'a caminho', 'saiu_entrega' => 'a caminho'];
                $prompt .= "⚠️ PEDIDO ATIVO: #{$ao['order_number']} da {$ao['partner_name']} — status: " . ($statusMap[$ao['status']] ?? $ao['status']) . "\n";
                $prompt .= "Se o cliente perguntar sobre esse pedido, mude pra modo suporte.\n\n";
            }

            // CEP context
            if (!empty($context['cep_data'])) {
                $cd = $context['cep_data'];
                $prompt .= "LOCALIZAÇÃO (CEP {$cd['cep']}): {$cd['neighborhood']}, {$cd['city']}-{$cd['state']}\n\n";
            }
            if (!empty($context['nearby_stores'])) {
                $prompt .= "RESTAURANTES NA REGIÃO:\n";
                foreach ($context['nearby_stores'] as $ns) {
                    $prompt .= "- {$ns['name']} (ID:{$ns['id']})\n";
                }
                $prompt .= "\n";
            }
            if (!empty($context['detected_cuisine'])) {
                $prompt .= "O CLIENTE QUER: {$context['detected_cuisine']} — filtre restaurantes que servem isso\n\n";
            }

            $prompt .= "INTELIGÊNCIA PARA IDENTIFICAR RESTAURANTE:\n";
            $prompt .= "- Nome exato: 'Pizzaria Bella' → match direto\n";
            $prompt .= "- Nome aproximado/errado: 'pizaria bela', 'a bella' → match fuzzy (encontre o mais parecido)\n";
            $prompt .= "- Apelido/abreviação: 'no burger', 'na bella', 'lá do açaí' → tente combinar\n";
            $prompt .= "- Comida genérica: 'pizza', 'lanche', 'açaí' → sugira 2-3 restaurantes relevantes\n";
            $prompt .= "- Indeciso: 'não sei', 'tanto faz' → sugira 2-3 populares pro horário atual\n";
            $prompt .= "- Habitual: 'o de sempre', 'o mesmo' → use favorito [favorito] se disponível\n";
            $prompt .= "- CEP/endereço: → use [CEP:12345678] se for número, senão pergunte CEP\n";
            $prompt .= "- Sem contexto nenhum: 'quero pedir' → pergunte o tipo de comida ou restaurante\n";
            $prompt .= "- Se já tem CEP/nearby_stores → NÃO peça CEP de novo!\n\n";

            $prompt .= "RESTAURANTES DISPONÍVEIS:\n" . implode("\n", $storeNames) . "\n\n";

            if (!empty($extraData['default_address'])) {
                $da = $extraData['default_address'];
                $prompt .= "ENDEREÇO SALVO: {$da['neighborhood']}, {$da['city']}\n\n";
            }

            $hora = (int)date('H');
            if ($hora >= 6 && $hora < 10) $prompt .= "HORÁRIO: Manhã — sugira café, padaria, café da manhã\n";
            elseif ($hora >= 11 && $hora < 14) $prompt .= "HORÁRIO: Almoço — sugira executivos, marmita, caseira, self-service\n";
            elseif ($hora >= 14 && $hora < 17) $prompt .= "HORÁRIO: Tarde — sugira açaí, lanches, cafeteria, sobremesa\n";
            elseif ($hora >= 18 && $hora < 22) $prompt .= "HORÁRIO: Noite — sugira pizza, hambúrguer, japonês, churrasco\n";
            else $prompt .= "HORÁRIO: Madrugada — sugira o que funciona nesse horário, seja compreensivo\n";

            $prompt .= "\nMARCADORES:\n";
            $prompt .= "- Restaurante identificado: [STORE:id:nome] (ex: [STORE:42:Pizzaria Bella])\n";
            $prompt .= "- CEP detectado: [CEP:12345678]\n";
            $prompt .= "- Quer suporte: mude o tom pra suporte (os pedidos serão carregados automaticamente)\n";
            break;

        case 'take_order':
            $prompt .= "## ETAPA: Anotar o pedido\n";
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
                $prompt .= "*** O CLIENTE PEDIU PARA REPETIR O ÚLTIMO PEDIDO ***\n";
                $prompt .= "Os itens do último pedido já foram adicionados automaticamente:\n";
                $total = 0;
                foreach ($items as $item) {
                    $lineTotal = ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                    $total += $lineTotal;
                    $prompt .= "- {$item['quantity']}x {$item['name']} R$" . number_format($lineTotal, 2, ',', '.') . "\n";
                }
                $prompt .= "Subtotal: R$" . number_format($total, 2, ',', '.') . "\n";
                $prompt .= "- Confirme: 'Adicionei os mesmos itens do seu último pedido: [lista]. Quer mudar algo ou tá tudo certo?'\n";
                $prompt .= "- Se disser que tá bom, inclua [NEXT_STEP]\n\n";
            } elseif (!empty($items)) {
                $prompt .= "ITENS JÁ ANOTADOS:\n";
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
                $prompt .= "ÚLTIMO PEDIDO DO CLIENTE NESTA LOJA:\n";
                foreach ($lastOrderItems as $li) {
                    $prompt .= "- {$li['quantity']}x {$li['product_name']} R$" . number_format((float)$li['unit_price'], 2, ',', '.') . "\n";
                }
                $prompt .= "- Mencione: 'Vi que você já pediu [itens] antes. Quer repetir ou algo diferente?'\n\n";
            }

            // Popular items
            if (!empty($popularItems) && empty($items)) {
                $prompt .= "MAIS PEDIDOS NESTA LOJA: ";
                $popNames = array_map(fn($p) => $p['product_name'], $popularItems);
                $prompt .= implode(', ', $popNames) . "\n";
                $prompt .= "- Se o cliente não sabe o que pedir, sugira estes itens populares\n\n";
            }

            $prompt .= "COMO ANOTAR PEDIDO:\n";
            $prompt .= "- Identifique no cardápio, confirme com preço: 'Uma coxinha por R\$6,50, anotado!'\n";
            $prompt .= "- Cliente pode pedir VÁRIOS de uma vez: '2 coxinhas e 1 guaraná, show!'\n";
            $prompt .= "- Sem quantidade = 1 (ex: 'uma coca' = qty 1, 'coca' = qty 1)\n";
            $prompt .= "- Nome aproximado: 'x-burguer' pode ser 'X-Burger', 'coxinha de queijo' pode ser 'Coxinha Recheada Queijo'\n";
            $prompt .= "- Produto não existe → sugira parecido: 'Esse não tem, mas tem Y que é bem parecido!'\n";
            $prompt .= "- Produto ambíguo (vários match) → pergunte: 'Tem a Coxinha Tradicional e a Especial, qual você quer?'\n";
            $prompt .= "- Upsell NATURAL (1x só na conversa): comida sem bebida → 'Vai querer uma bebida?'\n";
            $prompt .= "- Depois de anotar → 'Mais alguma coisa ou fecha?'\n";
            $prompt .= "- Fechar: 'só isso', 'pronto', 'fecha', 'não quero mais', 'pode fechar', 'tá bom' → [NEXT_STEP]\n";
            $prompt .= "- Tirar item: 'tira a coca', 'sem a coxinha' → [REMOVE_ITEM:índice]\n";
            $prompt .= "- Mudar qtd: 'coloca 3 coxinhas', 'na verdade quero 2' → [UPDATE_QTY:índice:nova_qtd]\n";
            $prompt .= "- Cliente pede resumo: 'o que eu pedi?', 'quanto tá?' → liste itens e subtotal\n\n";

            $prompt .= "OPÇÕES DE PRODUTO (TAMANHOS, BORDAS, EXTRAS):\n";
            $prompt .= "- Produtos com >> no cardápio têm opções (tamanho, sabor, borda, etc.)\n";
            $prompt .= "- Opção OBRIGATÓRIA → DEVE perguntar ANTES de adicionar: 'Qual tamanho? Broto, Média, Grande?'\n";
            $prompt .= "- Opção opcional → ofereça natural: 'Quer borda recheada? Tem catupiry e cheddar'\n";
            $prompt .= "- Preço total = base + extras selecionados (ex: Pizza R\$30 + Grande +R\$20 + Borda +R\$8 = R\$58)\n";
            $prompt .= "- INCLUA opções no marcador: [ITEM:id:qty:preço_total:nome][OPT:id1,id2]\n\n";

            $prompt .= "SITUAÇÕES ESPECIAIS NO PEDIDO:\n";
            $prompt .= "- 'Meia a meia' / 'metade X metade Y' → se a loja tiver essa opção, use. Senão: 'Essa loja não tem meia a meia, quer uma de cada?'\n";
            $prompt .= "- 'Sem cebola' / 'sem tomate' → anote como observação mas NÃO mude o preço\n";
            $prompt .= "- 'Extra queijo' / 'dobro de bacon' → se tiver opção de extra no cardápio, use. Senão: anote como obs\n";
            $prompt .= "- Pedido grande (5+ itens) → resuma no final: 'Então ficou: [lista]. Certo?'\n";
            $prompt .= "- Cliente muda de restaurante no meio → pergunte: 'Quer cancelar esse pedido e ir pra outro lugar?'\n";
            $prompt .= "- 'Quanto tá o total?' → calcule e informe o subtotal até agora\n\n";

            // Dietary/allergen awareness
            if (!empty($context['dietary_question'])) {
                $prompt .= "ALERTA: O CLIENTE PERGUNTOU SOBRE ALERGIAS/DIETA!\n";
                $prompt .= "- Verifique os marcadores [ALÉRGENOS] nos itens do cardápio acima\n";
                $prompt .= "- Se o produto NÃO tem informação de alérgenos, diga: 'Não tenho certeza sobre os ingredientes desse produto, recomendo confirmar com o restaurante'\n";
                $prompt .= "- Se tem alérgenos listados, informe com clareza\n";
                $prompt .= "- NUNCA garanta que um produto é seguro se você não tem certeza\n\n";
            }

            // Scheduling
            if (!empty($context['wants_schedule'])) {
                $prompt .= "O CLIENTE QUER AGENDAR O PEDIDO!\n";
                $prompt .= "- Pergunte para quando: data e horário\n";
                $prompt .= "- Aceite expressões como 'amanhã ao meio dia', 'sexta às 19h', 'daqui 2 horas'\n";
                $prompt .= "- Quando definir, inclua [SCHEDULE:YYYY-MM-DD HH:MM] na resposta\n";
                $prompt .= "- Horário mínimo: 30min no futuro. Máximo: 7 dias\n";
                $prompt .= "- Confirme: 'Pedido agendado para [data] às [hora], certo?'\n\n";
            }

            // Active promos
            if (!empty($activePromos)) {
                $prompt .= "CUPONS ATIVOS (mencione se o pedido atingir o minimo):\n";
                foreach ($activePromos as $promo) {
                    $desc = $promo['discount_type'] === 'percentage'
                        ? $promo['discount_value'] . '% de desconto'
                        : ($promo['discount_type'] === 'free_delivery' ? 'Frete grátis' : 'R$' . number_format((float)$promo['discount_value'], 2, ',', '.') . ' de desconto');
                    $min = $promo['min_order_value'] > 0 ? ' (pedido mín R$' . number_format((float)$promo['min_order_value'], 2, ',', '.') . ')' : '';
                    $prompt .= "- Cupom {$promo['code']}: {$desc}{$min}\n";
                }
                $prompt .= "- Mencione o cupom SOMENTE quando o subtotal estiver perto do mínimo: 'Se adicionar mais R$X, você pode usar o cupom [CODE] e ganhar [desconto]!'\n\n";
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
            $prompt .= "## ETAPA: Endereço de entrega\n";
            $prompt .= "RESTAURANTE: {$storeName}\n\n";

            if (!empty($address['from_cep'])) {
                // CEP already provided earlier
                $prompt .= "O CLIENTE JÁ DEU O CEP ({$address['cep']}):\n";
                $prompt .= "Rua: {$address['street']}, Bairro: {$address['neighborhood']}, {$address['city']}-{$address['state']}\n\n";
                $prompt .= "- Confirme: 'Achei seu endereço na {$address['street']}, bairro {$address['neighborhood']}. Qual o número da casa?'\n";
                $prompt .= "- Quando tiver o número, pergunte se tem complemento (apto, bloco)\n";
                $prompt .= "- Depois inclua [ADDRESS_TEXT:rua, número - bairro, cidade] e [NEXT_STEP]\n\n";
            } elseif (!empty($savedAddresses)) {
                $prompt .= "ENDEREÇOS SALVOS:\n";
                foreach ($savedAddresses as $i => $addr) {
                    $num = $i + 1;
                    $label = $addr['label'] ?? '';
                    $full = ($addr['street'] ?? '') . ', ' . ($addr['number'] ?? '') . ' - ' . ($addr['neighborhood'] ?? '');
                    $isDefault = !empty($addr['is_default']) ? ' [PADRÃO]' : '';
                    $prompt .= "{$num}. {$label}: {$full}{$isDefault}\n";
                }
                $prompt .= "\n";
                if (count($savedAddresses) === 1) {
                    $prompt .= "Só tem 1 endereço salvo. Sugira direto: 'Mando pro endereço de sempre, lá na [rua - bairro]?'\n";
                    $prompt .= "Se confirmar → [ADDRESS:1] [NEXT_STEP]\n";
                } else {
                    $prompt .= "Sugira o padrão: 'Mando pro mesmo lugar de sempre? Lá na [rua - bairro]?'\n";
                    $prompt .= "Se confirmar → [ADDRESS:1] [NEXT_STEP]. Se quiser outro, pergunte qual\n";
                }
            } else {
                $prompt .= "Sem endereço salvo.\n";
                if (!empty($context['cep_data'])) {
                    // We have CEP from earlier in the conversation
                    $cd = $context['cep_data'];
                    $prompt .= "CEP DO CLIENTE: {$cd['cep']} — {$cd['street']}, {$cd['neighborhood']}, {$cd['city']}\n";
                    $prompt .= "Pergunte só o número e complemento\n";
                } else {
                    $prompt .= "Pergunte o CEP primeiro: 'Me fala seu CEP pra eu puxar o endereço rapidinho'\n";
                    $prompt .= "Ou se preferir, pode falar o endereço completo\n";
                }
            }
            $prompt .= "\nMARCADORES:\n";
            $prompt .= "- CEP: [CEP:12345678]\n";
            $prompt .= "- Endereço salvo: [ADDRESS:1]\n";
            $prompt .= "- Endereço digitado: [ADDRESS_TEXT:rua, número - bairro, cidade]\n";
            $prompt .= "- Quando tiver endereço → [NEXT_STEP]\n";
            break;

        case 'get_payment':
            $prompt .= "## ETAPA: Como vai pagar?\n";
            if ($lastPayment) {
                $paymentLabels = [
                    'dinheiro' => 'dinheiro', 'pix' => 'PIX',
                    'credit_card' => 'cartão de crédito', 'debit_card' => 'cartão de débito',
                    'credito' => 'cartão de crédito', 'debito' => 'cartão de débito',
                ];
                $lastPayLabel = $paymentLabels[$lastPayment] ?? $lastPayment;
                $prompt .= "Última vez pagou com {$lastPayLabel}.\n";
                $prompt .= "Pergunte: 'Da última vez você pagou com {$lastPayLabel}, mantém ou quer trocar?'\n";
                $prompt .= "Se disser 'o mesmo', 'pode', 'mantém' → use {$lastPayment}\n\n";
            }
            $prompt .= "Opções: Dinheiro, PIX, Cartão (crédito/débito na maquininha)\n";
            $prompt .= "Se dinheiro → pergunte se precisa de troco e pra quanto\n\n";
            $prompt .= "MARCADORES:\n";
            $prompt .= "- [PAYMENT:dinheiro], [PAYMENT:pix], [PAYMENT:credit_card], [PAYMENT:debit_card]\n";
            $prompt .= "- Com troco: [PAYMENT:dinheiro:100]\n";
            $prompt .= "- Depois → [NEXT_STEP]\n";
            break;

        case 'confirm_order':
            $prompt .= "## ETAPA: Confirmar o pedido\n";
            $prompt .= "RESTAURANTE: {$storeName}\n\n";
            $prompt .= "ITENS:\n";
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
            $prompt .= "\nTaxa de serviço: R$" . number_format($serviceFee, 2, ',', '.');
            $prompt .= "\nTOTAL: R$" . number_format($total, 2, ',', '.') . "\n\n";

            // ETA or scheduled time
            if (!empty($context['scheduled_date'])) {
                $schedDate = $context['scheduled_date'];
                $schedTime = $context['scheduled_time'] ?? '12:00';
                $prompt .= "PEDIDO AGENDADO PARA: {$schedDate} às {$schedTime}\n\n";
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
                    'credit_card' => 'Cartão de crédito',
                    'debit_card' => 'Cartão de débito',
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
                            : ($promo['discount_type'] === 'free_delivery' ? 'frete grátis' : 'R$' . number_format((float)$promo['discount_value'], 2, ',', '.'));
                        $prompt .= "CUPOM DISPONÍVEL: {$promo['code']} ({$desc}) — o pedido atinge o mínimo! Mencione.\n";
                        break;
                    }
                }
            }

            $prompt .= "\nResuma de forma NATURAL e rápida. Ex:\n";
            $prompt .= "'Então fica: 1 pizza margherita grande e 2 cocas. Total de R$58 com entrega. Mando pro seu endereço na rua tal. Pagamento em PIX. Chega em uns 40 minutinhos. Posso mandar?'\n\n";
            $prompt .= "Se confirmar (sim, pode, manda, isso, bora, confirma) → [CONFIRMED]\n";
            $prompt .= "Se quiser mudar algo → volte pra etapa certa\n";
            break;

        case 'question':
            $prompt .= "## ETAPA: Tirar dúvida do cliente\n";
            $prompt .= "Responda de forma clara e CURTA.\n\n";

            $prompt .= "RESPOSTAS PRONTAS:\n";
            $prompt .= "- Como funciona → 'Cê escolhe o restaurante, monta o pedido, e a gente entrega!'\n";
            $prompt .= "- Taxa de entrega → 'Depende do restaurante, geralmente R\$5 a R\$15'\n";
            $prompt .= "- Tempo de entrega → 'Varia, geralmente 30 a 60 minutos'\n";
            $prompt .= "- Formas de pagamento → 'Dinheiro, PIX ou cartão na maquininha'\n";
            $prompt .= "- Horário → 'Cada restaurante tem seu horário. Quer que eu veja se algum tá aberto?'\n";
            $prompt .= "- Pedido mínimo → 'Depende do restaurante. Se quiser, eu vejo pra você!'\n";
            $prompt .= "- Raio de entrega → 'Depende do restaurante. Me fala seu CEP que eu vejo quem entrega aí'\n";
            $prompt .= "- Cadastro → 'Você pode baixar o app SuperBora ou pedir por aqui mesmo!'\n";
            $prompt .= "- Trabalhar no SuperBora → 'Que legal! Você pode se cadastrar como entregador no app'\n";
            $prompt .= "- Parceria/restaurante quer entrar → 'Posso transferir pro setor de parcerias!'\n\n";

            $prompt .= "PERGUNTAS QUE NÃO SABE → 'Hmm, essa eu não sei responder. Quer que eu te passe pra alguém que pode ajudar?'\n\n";

            if (!empty($storeNames)) {
                $prompt .= "RESTAURANTES: " . implode(", ", array_map(fn($s) => explode(' (ID:', $s)[0], array_slice($storeNames, 0, 10))) . "\n\n";
            }
            $prompt .= "Depois de responder → 'Mais alguma dúvida ou quer fazer um pedido?'\n";
            $prompt .= "Quer pedir → [SWITCH_TO_ORDER]\n";
            $prompt .= "Encerrar → 'Beleza! Se precisar, é só ligar. Tchau!'\n";
            break;

        case 'support':
            $prompt .= "## ETAPA: Suporte ao cliente\n";
            $prompt .= "O cliente precisa de ajuda.\n\n";

            $prompt .= "O QUE VOCÊ PODE FAZER:\n";
            $prompt .= "- Ver status de pedido → informe de forma clara e humana\n";
            $prompt .= "- Cancelar pedido → SÓ se status for 'pendente' ou 'confirmado'\n";
            $prompt .= "- Informar tempo estimado → baseado no status\n";
            $prompt .= "- Problemas simples → resolva você mesma\n";
            $prompt .= "- Problemas complexos → ofereça transferir pro atendente\n\n";

            $prompt .= "COMO DAR STATUS DE FORMA HUMANA:\n";
            $prompt .= "- Pendente → 'O restaurante ainda não confirmou, mas normalmente aceita em poucos minutos.'\n";
            $prompt .= "- Confirmado → 'Já foi aceito! O restaurante vai começar a preparar.'\n";
            $prompt .= "- Preparando → 'Tá sendo preparado agora! Já já sai pra entrega.'\n";
            $prompt .= "- Pronto → 'Tá pronto! Só esperando o entregador pegar.'\n";
            $prompt .= "- Saiu pra entrega → 'Já saiu! Tá a caminho, deve chegar logo!'\n";
            $prompt .= "- Entregue → 'Já foi entregue! Se não recebeu, pode ser um problema. Quer que eu transfira pra um atendente?'\n";
            $prompt .= "- Cancelado → 'Esse pedido foi cancelado. Quer fazer um novo?'\n\n";

            $prompt .= "CANCELAMENTO — REGRAS:\n";
            $prompt .= "- Pendente/Confirmado → pode cancelar: 'Cancelado! Quer fazer outro pedido?'\n";
            $prompt .= "- Preparando → 'Putz, já tá sendo preparado. Não consigo cancelar mais. Quer falar com um atendente pra ver se resolve?'\n";
            $prompt .= "- Saiu pra entrega → 'Já saiu pra entrega, não dá pra cancelar. Mas posso transferir pra um atendente resolver.'\n";
            $prompt .= "- SEMPRE confirme antes de cancelar: 'Tem certeza que quer cancelar o pedido #X?'\n\n";

            $prompt .= "RECLAMAÇÕES — COMO LIDAR:\n";
            $prompt .= "- Pedido atrasado → 'Entendo a frustração. O pedido tá [status]. Às vezes demora um pouquinho mais no horário de pico.'\n";
            $prompt .= "- Pedido errado → 'Putz, sinto muito! Isso não deveria ter acontecido. Vou transferir pra um atendente que vai resolver pra você.'\n";
            $prompt .= "- Comida fria/ruim → empatia total, ofereça transferir\n";
            $prompt .= "- Cobrança errada → ofereça transferir (não tem como resolver por telefone)\n";
            $prompt .= "- NUNCA minimize: 'acontece', 'é normal'. SEMPRE valide: 'Putz, realmente chato isso.'\n\n";

            if (!empty($context['support_orders'])) {
                $prompt .= "PEDIDOS RECENTES DO CLIENTE:\n";
                foreach ($context['support_orders'] as $ord) {
                    $statusMap = [
                        'pendente' => 'Pendente', 'confirmado' => 'Confirmado',
                        'preparando' => 'Em preparo', 'em_preparo' => 'Em preparo',
                        'pronto' => 'Pronto', 'saiu_entrega' => 'Saiu pra entrega',
                        'delivering' => 'Saiu pra entrega', 'entregue' => 'Entregue',
                        'cancelled' => 'Cancelado', 'refunded' => 'Reembolsado',
                    ];
                    $statusLabel = $statusMap[$ord['status']] ?? $ord['status'];
                    $prompt .= "- #{$ord['order_number']} | {$ord['partner_name']} | {$statusLabel} | R\$" . number_format((float)$ord['total'], 2, ',', '.') . " | {$ord['date_added']}\n";
                    if (!empty($ord['items_summary'])) {
                        $prompt .= "  → {$ord['items_summary']}\n";
                    }
                }
                $prompt .= "\n";
                $prompt .= "Se o cliente perguntar 'meu pedido' sem especificar → assuma o mais recente.\n";
                $prompt .= "Se tiver mais de 1 ativo → pergunte qual: 'Você tem 2 pedidos: um da X e outro da Y. Qual deles?'\n\n";
            } else {
                $prompt .= "NENHUM PEDIDO ENCONTRADO para este telefone.\n";
                $prompt .= "Diga: 'Hmm, não achei nenhum pedido nesse número. Pode ser outro telefone? Ou quer fazer um pedido novo?'\n\n";
            }

            $prompt .= "MARCADORES:\n";
            $prompt .= "- Cancelar: [CANCEL_ORDER:SB00123] (confirme antes!)\n";
            $prompt .= "- Status: [ORDER_STATUS:SB00123]\n";
            $prompt .= "- Voltar a pedir: [SWITCH_TO_ORDER]\n";
            $prompt .= "- Transferir: diga 'vou te transferir' (sistema detecta 'atendente' na fala dele)\n";
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
        array_unshift($clean, ['role' => 'user', 'content' => 'Olá']);
    }
    // Ensure non-empty
    if (empty($clean)) {
        $clean[] = ['role' => 'user', 'content' => 'Olá, quero fazer um pedido'];
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
            return ['success' => false, 'error' => 'Loja ou itens não definidos'];
        }

        // Get store info
        $stmt = $db->prepare("SELECT name, delivery_fee FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$storeId]);
        $store = $stmt->fetch();
        if (!$store) return ['success' => false, 'error' => 'Loja não encontrada'];

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
            $address = ['full' => 'Endereço a confirmar', 'manual' => true];
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
            return ['success' => false, 'error' => "Pedido {$orderNumber} não encontrado."];
        }

        $cancellable = ['pendente', 'confirmado'];
        if (!in_array($order['status'], $cancellable)) {
            $statusLabels = [
                'preparando' => 'já está sendo preparado',
                'pronto' => 'já está pronto',
                'saiu_entrega' => 'já saiu para entrega',
                'entregue' => 'já foi entregue',
                'cancelled' => 'já está cancelado',
            ];
            $reason = $statusLabels[$order['status']] ?? 'não pode ser cancelado no status atual';
            return ['success' => false, 'error' => "Não foi possível cancelar o pedido {$orderNumber} porque ele {$reason}. Se precisar, posso transferir pra um atendente."];
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
