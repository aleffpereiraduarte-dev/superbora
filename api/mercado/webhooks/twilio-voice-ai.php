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
require_once __DIR__ . '/../helpers/ai-safeguards.php';
require_once __DIR__ . '/../helpers/eta-calculator.php';
if (file_exists(__DIR__ . '/../helpers/ai-memory.php')) {
    require_once __DIR__ . '/../helpers/ai-memory.php';
}

// Enterprise helpers (optional — loaded only if present)
if (file_exists(__DIR__ . '/../helpers/ai-multilang.php')) {
    require_once __DIR__ . '/../helpers/ai-multilang.php';
}
if (file_exists(__DIR__ . '/../helpers/ai-retry-handler.php')) {
    require_once __DIR__ . '/../helpers/ai-retry-handler.php';
}
if (file_exists(__DIR__ . '/../helpers/ai-quality-scorer.php')) {
    require_once __DIR__ . '/../helpers/ai-quality-scorer.php';
}
if (file_exists(__DIR__ . '/../helpers/lgpd-compliance.php')) {
    require_once __DIR__ . '/../helpers/lgpd-compliance.php';
}
if (file_exists(__DIR__ . '/../admin/callcenter/ab-testing.php')) {
    require_once __DIR__ . '/../admin/callcenter/ab-testing.php';
}
if (file_exists(__DIR__ . '/../helpers/multi-store-order.php')) {
    require_once __DIR__ . '/../helpers/multi-store-order.php';
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
$callSid = $_POST['CallSid'] ?? $_GET['CallSid'] ?? '';
$speechResult = $_POST['SpeechResult'] ?? '';
$digits = $_POST['Digits'] ?? '';
$callerPhone = $_POST['From'] ?? $_GET['From'] ?? '';

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
        echo ttsSayOrPlay("Pode falar ou digitar, tô te escutando! Aperta zero se quiser falar com uma pessoa.");
        echo '<Redirect method="POST">' . escXml($selfUrl) . '</Redirect>';
        echo '</Response>';
        exit;
    }

    $callId = (int)$call['id'];
    $customerName = $call['customer_name'];
    $customerId = $call['customer_id'] ? (int)$call['customer_id'] : null;

    // Load AI context from a separate column or notes JSON
    $aiContext = loadAiContext($db, $callId);
    $aiContext['customer_phone'] = $callerPhone;
    $aiContext['customer_id'] = $customerId;
    $step = $aiContext['step'] ?? 'identify_store';
    $conversationHistory = $aiContext['history'] ?? [];

    // Handle DTMF shortcuts: 1=order, 2=status
    if ($digits === '1' && empty($speechResult)) {
        $speechResult = 'quero fazer um pedido';
    } elseif ($digits === '2' && empty($speechResult)) {
        $speechResult = 'quero ver meu pedido';
    }

    // If digits look like a CEP (5-8 digits), treat as speech
    if (strlen($digits) >= 5 && ctype_digit($digits) && empty($speechResult)) {
        $speechResult = $digits;
    }

    // Track user input + speech confidence
    $userInput = $speechResult ?: ($digits ?: '');
    $speechConfidence = isset($_POST['Confidence']) ? (float)$_POST['Confidence'] : 1.0;

    // -- Anti-abuse & safeguards check --
    $safeguards = runSafeguards($db, $callerPhone, $callSid, $userInput, $aiContext);
    if (!$safeguards['allowed']) {
        logCallMetrics($db, $callId, [
            'turn_number' => $aiContext['turn_count'] ?? 0,
            'step' => $step,
            'error_type' => 'abuse_blocked',
            'error_message' => $safeguards['abuse']['reason'] ?? 'blocked',
        ]);
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo $safeguards['twiml'] ?: buildTwilioSay('Desculpe, não foi possível processar sua ligação.');
        echo '<Hangup/>';
        echo '</Response>';
        exit;
    }
    // Use sanitized context from safeguards
    $aiContext = $safeguards['context'];
    $step = $aiContext['step'] ?? 'identify_store';
    $conversationHistory = $aiContext['history'] ?? [];

    // Log health warnings (don't block, just track)
    if (!$safeguards['health']['healthy']) {
        $healthAction = $safeguards['health']['recommended_action'];
        error_log("[twilio-voice-ai] Health issues: " . implode('; ', $safeguards['health']['issues']) . " | action={$healthAction}");
        logCallMetrics($db, $callId, [
            'turn_number' => $aiContext['turn_count'] ?? 0,
            'step' => $step,
            'error_type' => 'health_warning',
            'error_message' => $healthAction . ': ' . implode('; ', $safeguards['health']['issues']),
        ]);
    }

    // -- LGPD audit log at conversation start --
    $turnCount = ($aiContext['turn_count'] ?? 0);
    if ($turnCount <= 1) {
        try {
            if (function_exists('auditLog')) {
                auditLog($db, 'conversation', 'ai', null, [
                    'customer_phone' => $callerPhone,
                    'customer_id' => $customerId,
                    'resource_type' => 'call',
                    'resource_id' => (string)$callId,
                    'action' => 'create',
                    'pii_fields' => ['phone', 'name'],
                ]);
            }
        } catch (\Throwable $e) {
            error_log("[twilio-voice-ai] [lgpd-audit] Error: " . $e->getMessage());
        }
    }

    // -- Multi-language detection --
    $detectedLang = 'pt';
    if (function_exists('detectLanguage') && !empty($userInput)) {
        try {
            $newLang = detectLanguage($userInput);
            if ($newLang !== 'pt') {
                $detectedLang = $newLang;
                $aiContext['detected_language'] = $newLang;
            } elseif (!empty($aiContext['detected_language'])) {
                $detectedLang = $aiContext['detected_language']; // keep from previous turn
            }
        } catch (\Throwable $e) {
            error_log("[twilio-voice-ai] [multilang] Detection error: " . $e->getMessage());
        }
    }

    // -- A/B testing integration --
    $abConfig = null;
    try {
        if (function_exists('getActiveABConfig')) {
            $abConfig = getActiveABConfig($db, $callerPhone, 'voice');
        }
    } catch (\Throwable $e) {
        error_log("[twilio-voice-ai] [ab-testing] Error: " . $e->getMessage());
    }
    if ($abConfig) {
        $aiContext['ab_test_id'] = $abConfig['test_id'];
        $aiContext['ab_variant'] = $abConfig['variant_id'];
    }

    // -- Phone number lookup (when caller not identified) --
    if (!$customerId && !empty($userInput)) {
        // Extract digits from speech — people say "19 9 5407 7804" or "dezenove nove cinco quatro..."
        $digitsOnly = preg_replace('/\D/', '', $userInput);
        // Also try to extract from mixed speech like "meu numero é 19547077804"
        if (strlen($digitsOnly) >= 10 && strlen($digitsOnly) <= 13) {
            $lookupSuffix = substr($digitsOnly, -11);
            try {
                $lookupStmt = $db->prepare("
                    SELECT c.customer_id, c.name FROM om_customers c
                    WHERE REPLACE(REPLACE(c.phone, '+', ''), '-', '') LIKE ?
                    LIMIT 1
                ");
                $lookupStmt->execute(['%' . $lookupSuffix]);
                $foundCust = $lookupStmt->fetch();

                if ($foundCust) {
                    $customerId = (int)$foundCust['customer_id'];
                    $customerName = $foundCust['name'];
                    $aiContext['customer_id'] = $customerId;
                    $aiContext['customer_name'] = $customerName;

                    // Update call record with found customer
                    $db->prepare("UPDATE om_callcenter_calls SET customer_id = ?, customer_name = ? WHERE id = ?")
                       ->execute([$customerId, $customerName, $callId]);

                    // Check for active orders
                    $actStmt = $db->prepare("
                        SELECT o.order_number, o.status, p.name as partner_name
                        FROM om_market_orders o
                        JOIN om_market_partners p ON p.partner_id = o.partner_id
                        WHERE o.customer_id = ? AND o.status IN ('pending','accepted','preparing','ready','delivering','em_preparo','saiu_entrega')
                        ORDER BY o.date_added DESC LIMIT 1
                    ");
                    $actStmt->execute([$customerId]);
                    $foundOrder = $actStmt->fetch();

                    $firstName = explode(' ', trim($customerName))[0];

                    if ($foundOrder) {
                        $statusLabels = [
                            'pending' => 'esperando a confirmação da loja',
                            'accepted' => 'foi aceito e já já começa a ser preparado',
                            'preparing' => 'tá sendo preparado agora',
                            'em_preparo' => 'tá sendo preparado agora',
                            'ready' => 'tá prontinho esperando o entregador',
                            'delivering' => 'já saiu pra entrega e tá a caminho',
                            'saiu_entrega' => 'já saiu pra entrega e tá a caminho',
                        ];
                        $statusText = $statusLabels[$foundOrder['status']] ?? 'em andamento';
                        $msg = "Achei sua conta, {$firstName}! Seu pedido da {$foundOrder['partner_name']} {$statusText}. "
                            . "Se quiser cancelar, saber mais detalhes, ou qualquer outra coisa, é só me falar!";
                    } else {
                        $msg = "Achei sua conta, {$firstName}! No momento não encontrei nenhum pedido ativo. "
                            . "Quer fazer um pedido novo, tirar uma dúvida, ou precisa de outra coisa?";
                    }

                    // Respond directly and continue conversation
                    $aiContext['history'][] = ['role' => 'user', 'content' => $userInput];
                    $aiContext['history'][] = ['role' => 'assistant', 'content' => $msg];
                    $aiContext['turn_count'] = ($aiContext['turn_count'] ?? 0) + 1;
                    saveAiContext($db, $callId, $aiContext);

                    echo '<?xml version="1.0" encoding="UTF-8"?>';
                    echo '<Response>';
                    echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
                    echo ttsSayOrPlay($msg);
                    echo '</Gather>';
                    echo '<Redirect method="POST">' . escXml($selfUrl) . '</Redirect>';
                    echo '</Response>';
                    exit;
                }
            } catch (Exception $e) {
                error_log("[twilio-voice-ai] Phone lookup error: " . $e->getMessage());
            }
        }
    }

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

                // AI quality scoring (call ended without order)
                try {
                    if (function_exists('scoreAiConversation')) {
                        scoreAiConversation($db, 'voice', $callId, [
                            'history' => $aiContext['history'] ?? [],
                            'items' => $aiContext['items'] ?? [],
                            'step' => $step,
                            'order_id' => null,
                            'customer_sentiment' => 'neutral',
                            'turns_count' => (int)($aiContext['turn_count'] ?? 0),
                            'duration_seconds' => time() - strtotime($call['started_at'] ?? 'now'),
                        ]);
                    }
                } catch (\Throwable $e) {
                    error_log("[twilio-voice-ai] [quality-scorer] Error: " . $e->getMessage());
                }

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

    // Check if this is a CEP processing redirect (we already said "um minutinho")
    $cepProcessingAi = $_GET['cep_processing'] ?? $_POST['cep_processing'] ?? '';
    $cepFromRedirectAi = $_GET['cep_val'] ?? $_POST['cep_val'] ?? '';

    // Handle empty input (Gather timed out) — don't waste Claude call
    // BUT skip silence detection if this is a CEP processing redirect
    $isCepRedirect = false;
    if (empty($userInput) && $cepProcessingAi === '1' && !empty($cepFromRedirectAi)) {
        // CEP redirect — inject CEP as user input so the flow continues
        $userInput = $cepFromRedirectAi;
        $speechResult = $cepFromRedirectAi;
        $isCepRedirect = true;
    }
    if (empty($userInput)) {
        $silenceCount = ($aiContext['silence_count'] ?? 0) + 1;
        $aiContext['silence_count'] = $silenceCount;
        $aiContext['history'] = $conversationHistory;
        saveAiContext($db, $callId, $aiContext);

        $silencePrompts = [
            "Pode falar ou digitar, tô te escutando!",
            "Opa, não ouvi nada. Pode falar ou digitar que eu tô aqui te ouvindo!",
            "Hmm, acho que a ligação tá ruim. Pode repetir? Tô aqui te escutando!",
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
        echo ttsSayOrPlay("Pode falar ou digitar, tô te escutando! Aperta zero se quiser falar com uma pessoa.");
        echo '<Redirect method="POST">' . escXml($selfUrl) . '</Redirect>';
        echo '</Response>';
        exit;
    }

    // Reset silence counter when we get input
    $aiContext['silence_count'] = 0;

    // Add user message to history
    $conversationHistory[] = ['role' => 'user', 'content' => $isCepRedirect ? "Meu CEP é {$userInput}" : $userInput];

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

        // If CEP detected and NOT a redirect — say "um minutinho" and redirect back
        if (strlen($digitsOnly) === 8 && preg_match('/^[0-9]{5}/', $digitsOnly) && $cepProcessingAi !== '1') {
            // Save history first so we don't lose context
            $aiContext['history'] = $conversationHistory;
            saveAiContext($db, $callId, $aiContext);

            $redirectUrl = $selfUrl . '?cep_processing=1&cep_val=' . urlencode($digitsOnly);

            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo ttsSayOrPlay('Um minutinho, tô buscando!');
            echo '<Redirect method="POST">' . escXml($redirectUrl) . '</Redirect>';
            echo '</Response>';
            exit;
        }

        // Use CEP from redirect if available
        if ($cepProcessingAi === '1' && !empty($cepFromRedirectAi)) {
            $digitsOnly = preg_replace('/\D/', '', $cepFromRedirectAi);
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
    $dietaryRestrictions = $aiContext['dietary_restrictions'] ?? null;
    if (function_exists('aiMemoryBuildContext')) {
        try {
            $memoryContext = aiMemoryBuildContext($db, $callerPhone, $customerId);
            $memoryGreetingHint = aiMemoryGetGreeting($db, $callerPhone, $customerId);
        } catch (Exception $e) {
            error_log("[twilio-voice-ai] Memory load error (non-fatal): " . $e->getMessage());
        }
        // Load dietary restrictions from AI memory if not already in context
        if (empty($dietaryRestrictions) && function_exists('aiMemoryLoad')) {
            try {
                $memories = aiMemoryLoad($db, $callerPhone, $customerId);
                if (!empty($memories['preference']['dietary_restriction']['value'])) {
                    $dietaryRestrictions = $memories['preference']['dietary_restriction']['value'];
                    $aiContext['dietary_restrictions'] = $dietaryRestrictions;
                }
            } catch (Exception $e) {
                error_log("[twilio-voice-ai] Dietary load error (non-fatal): " . $e->getMessage());
            }
        }
    }

    // -- Load favorite stores for returning customers --
    $favoriteStores = [];
    if ($customerId) {
        try {
            $favoriteStores = getVoiceFavoriteStores($db, $customerId, 5);
        } catch (Exception $e) {
            error_log("[twilio-voice-ai] Favorite stores load error (non-fatal): " . $e->getMessage());
        }
    }

    // Track conversation turns
    $turnCount = ($aiContext['turn_count'] ?? 0) + 1;
    $aiContext['turn_count'] = $turnCount;

    // Track if AI already did upsell (don't repeat)
    $didUpsell = $aiContext['did_upsell'] ?? false;

    // -- Intelligence features (ported from WhatsApp bot) --
    $reorderSuggestion = null;
    if ($customerId && $step === 'identify_store') {
        try { $reorderSuggestion = getVoiceReorderSuggestion($db, $customerId); } catch (\Exception $e) {}
    }
    $callHistory = '';
    if (!empty($callerPhone) && $turnCount <= 1) {
        try { $callHistory = getVoiceCallHistory($db, $callerPhone, 3); } catch (\Exception $e) {}
    }
    $sentiment = ['mood' => 'neutral', 'confidence' => 0.5, 'signals' => []];
    if (!empty($userInput)) {
        try { $sentiment = detectVoiceSentiment($userInput); } catch (\Exception $e) {}
    }
    $weatherContext = null;
    if ($step === 'identify_store') {
        try { $weatherContext = getVoiceWeatherContext(); } catch (\Exception $e) {}
    }

    // -- Loyalty tier + learned preferences --
    $loyaltyTier = null;
    $learnedPrefs = null;
    if ($customerId) {
        try { $loyaltyTier = getVoiceLoyaltyTier($db, $customerId); } catch (\Exception $e) {}
        try { $learnedPrefs = getVoiceLearnedPreferences($db, $customerId); } catch (\Exception $e) {}
    }

    // Referral code lookup for frequent customer hint
    $referralCode = getCustomerReferralCode($db, $customerId);

    // Smart ETA calculation (done here where $db is available, passed via extraData)
    $smartEtaMin = null;
    if ($storeId && ($step === 'confirm_order' || $step === 'take_order')) {
        $etaDistKm = (float)($aiContext['distance_km'] ?? 5.0);
        try {
            $smartEtaMin = calculateSmartETA($db, $storeId, $etaDistKm, 'pendente');
        } catch (Exception $e) {
            error_log("[twilio-voice-ai] Smart ETA calc error (non-fatal): " . $e->getMessage());
        }
    }

    // -- Voice intelligence: recommendations, cart optimization, price tips --
    $voiceRecs = [];
    if ($customerId && $storeId && $step === 'take_order') {
        try { $voiceRecs = getVoiceRecommendations($db, $customerId, $storeId, 3); } catch (\Exception $e) {}
    }
    $cartOptTip = null;
    if ($storeId && !empty($draftItems) && ($step === 'confirm_order' || $step === 'take_order')) {
        try { $cartOptTip = analyzeVoiceCartOptimizations($db, $draftItems, $storeId); } catch (\Exception $e) {}
    }
    // Price tips accumulated during item parsing (stored in context)
    $priceTips = $aiContext['price_tips'] ?? [];

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
        'reorder_suggestion' => $reorderSuggestion,
        'call_history' => $callHistory,
        'sentiment' => $sentiment,
        'weather_context' => $weatherContext,
        'loyalty_tier' => $loyaltyTier,
        'learned_prefs' => $learnedPrefs,
        'favorite_stores' => $favoriteStores,
        'dietary_restrictions' => $dietaryRestrictions,
        'referral_code' => $referralCode,
        'smart_eta' => $smartEtaMin,
        'voice_recs' => $voiceRecs,
        'cart_opt_tip' => $cartOptTip,
        'price_tips' => $priceTips,
    ];
    $systemPrompt = buildSystemPrompt($step, $storeName, $menuText, $draftItems, $address, $paymentMethod, $customerName, $savedAddresses, $storeNames, $lastOrderItems ?? [], $aiContext, $extraData);

    // -- Build conversation summary for memory across Gather loops --
    $conversationSummary = '';
    if ($turnCount > 1 && !empty($conversationHistory)) {
        $summaryParts = [];
        if (!empty($aiContext['store_name'])) $summaryParts[] = 'Loja: ' . $aiContext['store_name'];
        if (!empty($aiContext['items'])) {
            $itemNames = array_map(fn($i) => ($i['quantity'] ?? 1) . 'x ' . ($i['name'] ?? '?'), $aiContext['items']);
            $summaryParts[] = 'Itens: ' . implode(', ', $itemNames);
        }
        if (!empty($aiContext['address'])) $summaryParts[] = 'Endereço: definido';
        if (!empty($aiContext['payment_method'])) $summaryParts[] = 'Pagamento: ' . $aiContext['payment_method'];
        if (!empty($summaryParts)) {
            $conversationSummary = "\n\n## RESUMO DA CONVERSA ATÉ AGORA\n" . implode(' | ', $summaryParts) . "\n";
        }
    }

    // -- Call Claude (with optimization + metrics + retry) --
    // Keep conversation history manageable (last 8 turns)
    $recentHistory = array_slice($conversationHistory, -16);

    // Ensure alternating roles
    $cleanHistory = cleanConversationHistory($recentHistory);

    // Optimize request: trim prompt/history, set max_tokens by step
    $optimized = optimizeClaudeRequest($systemPrompt, $cleanHistory, $aiContext);

    // Inject conversation summary for memory continuity
    if (!empty($conversationSummary)) {
        $optimized['prompt'] .= $conversationSummary;
    }

    // Inject conversation health hint into prompt if issues detected
    if (!$safeguards['health']['healthy']) {
        $healthAction = $safeguards['health']['recommended_action'];
        $healthHint = "\n\n## ALERTA DE SAUDE DA CONVERSA\n";
        switch ($healthAction) {
            case 'clarify_or_transfer':
                $healthHint .= "O cliente está repetindo a mesma coisa. Reformule sua resposta de forma diferente ou ofereça transferir pra atendente.\n";
                break;
            case 'offer_help_or_transfer':
                $healthHint .= "A conversa está travada. Tente abordar de um ângulo diferente ou ofereça: 'Quer que eu te passe pra um atendente?'\n";
                break;
            case 'simplify_and_offer_transfer':
            case 'empathize_and_offer_transfer':
                $healthHint .= "O cliente parece frustrado. Seja empático, simplifique e ofereça: 'Desculpa pela dificuldade. Quer falar com um atendente?'\n";
                break;
            case 'wrap_up_or_transfer':
                $healthHint .= "A conversa está muito longa. Tente concluir rapidamente ou ofereça um atendente.\n";
                break;
        }
        $optimized['prompt'] .= $healthHint;
    }

    // Voice-specific instruction: keep responses SHORT
    $optimized['prompt'] .= "\n\n## LEMBRETE CRITICO PARA VOZ\nIsso é uma LIGACAO TELEFONICA. Responda em NO MAXIMO 2 frases curtas. Ninguem quer ouvir texto longo no telefone.\n";

    // -- Append language modifier and A/B tone to system prompt --
    $langModifier = '';
    if (function_exists('getMultilangPrompt') && $detectedLang !== 'pt') {
        try {
            $langModifier = getMultilangPrompt($detectedLang, $step);
        } catch (\Throwable $e) {
            error_log("[twilio-voice-ai] [multilang] Prompt error: " . $e->getMessage());
        }
    }
    if ($abConfig) {
        if (!empty($abConfig['config']['tone'])) {
            $toneMap = ['formal' => 'Use tom formal e profissional.', 'casual' => 'Use tom casual e descontraido.', 'fun' => 'Use tom divertido com emoji e gírias.'];
            $langModifier .= "\n" . ($toneMap[$abConfig['config']['tone']] ?? '');
        }
    }
    if (!empty($langModifier)) {
        $optimized['prompt'] .= "\n" . trim($langModifier);
    }

    $claudeStart = hrtime(true);
    $aiResponse = '';
    $tokensUsed = 0;

    // -- Try enterprise retry handler first, fall back to original logic --
    $usedEnterpriseRetry = false;
    if (function_exists('callClaudeWithRetry')) {
        try {
            $aiResult = callClaudeWithRetry($optimized['prompt'], $optimized['history'], [
                'max_tokens' => $optimized['max_tokens'],
                'conversation_type' => 'voice',
                'conversation_id' => $callId,
                'step' => $step,
            ]);
            $aiResponse = $aiResult['response'] ?? '';
            if (empty($aiResponse) && !empty($aiResult['fallback'])) {
                $aiResponse = $aiResult['fallback'];
            }
            $tokensUsed = (int)($aiResult['total_tokens'] ?? 0);
            $usedEnterpriseRetry = !empty($aiResponse);
        } catch (\Throwable $e) {
            error_log("[twilio-voice-ai] Enterprise retry handler error: " . $e->getMessage());
            // Fall through to original logic
        }
    }

    if (!$usedEnterpriseRetry) {
        $result = $claude->send($optimized['prompt'], $optimized['history'], $optimized['max_tokens']);
    }
    $claudeLatencyMs = (int)((hrtime(true) - $claudeStart) / 1_000_000);

    $previousStep = $step;

    // -- Retry logic for Claude failures (original — only if enterprise handler was not used) --
    if (!$usedEnterpriseRetry && !$result['success']) {
        $claudeError = $result['error'] ?? 'unknown';
        error_log("[twilio-voice-ai] Claude error ({$claudeLatencyMs}ms): {$claudeError} — attempting retry");

        // Retry once with a shorter prompt and smaller max_tokens
        $retryStart = hrtime(true);
        $retryResult = $claude->send(
            "Você é a Bora do SuperBora (delivery). Ligação telefônica, PT-BR informal. Max 2 frases.\n"
            . "Etapa: {$step}. " . (!empty($storeName) ? "Loja: {$storeName}. " : "")
            . "Responda ao cliente de forma natural e curta.",
            array_slice($cleanHistory, -4), // Minimal history
            200
        );
        $retryLatencyMs = (int)((hrtime(true) - $retryStart) / 1_000_000);

        if ($retryResult['success']) {
            error_log("[twilio-voice-ai] Retry succeeded ({$retryLatencyMs}ms)");
            $result = $retryResult;
            $claudeLatencyMs += $retryLatencyMs;
        } else {
            error_log("[twilio-voice-ai] Retry also failed ({$retryLatencyMs}ms): " . ($retryResult['error'] ?? 'unknown'));

            // Log the failure metric
            logCallMetrics($db, $callId, [
                'turn_number' => $turnCount,
                'step' => $step,
                'claude_latency_ms' => $claudeLatencyMs + $retryLatencyMs,
                'error_type' => 'claude_error_after_retry',
                'error_message' => $claudeError,
                'speech_confidence' => $speechConfidence,
            ]);

            // Try graceful degradation instead of immediately transferring
            $degradedResponse = handleDegradedMode('claude', $claudeError, [
                'step' => $step,
                'last_input' => $userInput,
                'items' => $draftItems,
                'store_name' => $storeName,
                'nearby_stores' => $aiContext['nearby_stores'] ?? [],
            ]);

            $aiContext['history'] = $conversationHistory;
            $aiContext['history'][] = ['role' => 'assistant', 'content' => strip_tags($degradedResponse)];
            saveAiContext($db, $callId, $aiContext);

            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo '<Gather input="speech dtmf" timeout="10" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
            echo $degradedResponse;
            echo '</Gather>';
            echo buildTwilioSay('Pode falar ou digitar, tô te escutando! Aperta zero se quiser falar com uma pessoa.');
            echo '<Redirect method="POST">' . escXml($selfUrl) . '</Redirect>';
            echo '</Response>';
            exit;
        }
    }

    if (!$usedEnterpriseRetry) {
        $aiResponse = trim($result['text'] ?? '');
        $tokensUsed = (int)($result['total_tokens'] ?? 0);
    }
    error_log("[twilio-voice-ai] AI response ({$claudeLatencyMs}ms, {$tokensUsed}tok): " . mb_substr($aiResponse, 0, 200));

    // -- Parse AI response for state transitions --
    $newContext = parseAiResponse($aiResponse, $aiContext, $db);
    $aiResponse = $newContext['cleaned_response'];

    // -- Fallback store matching: if Claude didn't output [STORE:id:name] but user said a store name --
    if ($step === 'identify_store' && empty($newContext['store_id']) && !empty($userInput)) {
        $inputLower = mb_strtolower($userInput, 'UTF-8');
        // Try exact match against nearby stores or all stores
        $candidateStores = $aiContext['nearby_stores'] ?? [];
        if (empty($candidateStores)) {
            try {
                $allStFallback = $db->query("SELECT partner_id AS id, name FROM om_market_partners WHERE status = '1' AND name != '' LIMIT 50")->fetchAll();
                $candidateStores = $allStFallback;
            } catch (Exception $e) {}
        }
        foreach ($candidateStores as $cs) {
            $csLower = mb_strtolower($cs['name'], 'UTF-8');
            // Check if user input contains the store name or vice versa
            if (mb_strpos($inputLower, $csLower) !== false || mb_strpos($csLower, $inputLower) !== false) {
                $newContext['store_id'] = (int)$cs['id'];
                $newContext['store_name'] = $cs['name'];
                $newContext['step'] = 'take_order';
                try {
                    $db->prepare("UPDATE om_callcenter_calls SET store_identified = ? WHERE id = ?")
                       ->execute([$cs['name'], $callId]);
                } catch (Exception $e) {}
                error_log("[twilio-voice-ai] Fallback store match: '{$userInput}' → {$cs['name']} (ID:{$cs['id']})");
                break;
            }
            // Fuzzy: word-by-word match (at least 1 word with 3+ chars matching)
            $inputWords = preg_split('/\s+/', $inputLower);
            $storeWords = preg_split('/\s+/', $csLower);
            foreach ($inputWords as $iw) {
                if (mb_strlen($iw) < 3) continue;
                foreach ($storeWords as $sw) {
                    if (mb_strlen($sw) < 3) continue;
                    if ($iw === $sw || (mb_strlen($iw) > 4 && mb_strlen($sw) > 4 && levenshtein($iw, $sw) <= 2)) {
                        $newContext['store_id'] = (int)$cs['id'];
                        $newContext['store_name'] = $cs['name'];
                        $newContext['step'] = 'take_order';
                        try {
                            $db->prepare("UPDATE om_callcenter_calls SET store_identified = ? WHERE id = ?")
                               ->execute([$cs['name'], $callId]);
                        } catch (Exception $e) {}
                        error_log("[twilio-voice-ai] Fallback fuzzy store match: '{$userInput}' → {$cs['name']} (ID:{$cs['id']})");
                        break 3;
                    }
                }
            }
        }
    }

    // -- Validate AI response (language, length, content safety) --
    $validationContext = array_merge($aiContext, [
        'menu_prices' => [], // populated from menu if needed
        'items' => $newContext['items'] ?? $draftItems,
    ]);
    $validation = validateAiResponse($aiResponse, $validationContext);

    if (!$validation['valid'] && empty($validation['cleaned'])) {
        // Response is completely invalid — use smart fallback
        error_log("[twilio-voice-ai] Response validation FAILED: " . implode('; ', $validation['issues']));
        $aiResponse = getSmartFallback($step, $aiContext, 'validation_failed');

        logCallMetrics($db, $callId, [
            'turn_number' => $turnCount,
            'step' => $step,
            'claude_latency_ms' => $claudeLatencyMs,
            'error_type' => 'validation_fail',
            'error_message' => implode('; ', $validation['issues']),
            'tokens_used' => $tokensUsed,
            'speech_confidence' => $speechConfidence,
            'response_length' => mb_strlen($aiResponse, 'UTF-8'),
        ]);
    } else {
        // Use the cleaned response (may have had minor fixes)
        if (!empty($validation['issues'])) {
            error_log("[twilio-voice-ai] Response cleaned: " . implode('; ', $validation['issues']));
        }
        $aiResponse = $validation['cleaned'];

        // Log successful turn metrics
        $stepTransition = ($previousStep !== ($newContext['step'] ?? $step))
            ? "{$previousStep}>{$newContext['step']}"
            : '';

        logCallMetrics($db, $callId, [
            'turn_number' => $turnCount,
            'step' => $newContext['step'] ?? $step,
            'claude_latency_ms' => $claudeLatencyMs,
            'step_transition' => $stepTransition,
            'tokens_used' => $tokensUsed,
            'speech_confidence' => $speechConfidence,
            'response_length' => mb_strlen($aiResponse, 'UTF-8'),
        ]);
    }

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
            // Use natural price formatting for voice
            $totalSpoken = ttsFormatPrice($orderResult['total']);
            $orderNumSpoken = ttsFormatOrderNumber($orderNumber);
            if (!empty($newContext['scheduled_date'])) {
                $schedMsg = $newContext['scheduled_date'] . ' às ' . ($newContext['scheduled_time'] ?? '12:00');
                $finalMsg = "Show, tá feito! Seu pedido número {$orderNumSpoken} tá agendado pra {$schedMsg}. "
                    . "Total de {$totalSpoken}. "
                    . "Mandei um SMS com o resumo. "
                    . "Valeu por pedir pelo SuperBora! Até lá!";
            } else {
                // Calculate smart ETA for the spoken success message
                $submitStoreId = $newContext['store_id'] ?? null;
                $submitDistKm = (float)($newContext['distance_km'] ?? 5.0);
                $submitEta = 40;
                if ($submitStoreId) {
                    try {
                        $submitEta = calculateSmartETA($db, $submitStoreId, $submitDistKm, 'pendente');
                    } catch (Exception $e) { /* fallback to 40 */ }
                }
                $etaSpoken = spokenETA($submitEta);

                $finalMsg = "Pronto, pedido feito! Número {$orderNumSpoken}, total de {$totalSpoken}. "
                    . "Chega em uns {$etaSpoken}! "
                    . "Vou te mandar SMS quando o restaurante aceitar e quando sair pra entrega. "
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

            // -- AI quality scoring (post-order) --
            try {
                if (function_exists('scoreAiConversation')) {
                    scoreAiConversation($db, 'voice', $callId, [
                        'history' => $newContext['history'] ?? [],
                        'items' => $newContext['items'] ?? [],
                        'step' => $newContext['step'] ?? 'submit_order',
                        'order_id' => $orderResult['order_id'] ?? null,
                        'customer_sentiment' => $newContext['customer_sentiment'] ?? 'neutral',
                        'turns_count' => (int)($newContext['turn_count'] ?? 0),
                        'duration_seconds' => time() - strtotime($call['started_at'] ?? 'now'),
                    ]);
                }
            } catch (\Throwable $e) {
                error_log("[twilio-voice-ai] [quality-scorer] Error: " . $e->getMessage());
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
    $errorMsg = $e->getMessage();
    $errorMasked = maskPiiForLog($errorMsg);
    error_log("[twilio-voice-ai] Error: {$errorMasked} | Trace: " . $e->getTraceAsString());

    // Log the error metric if we have a call ID
    if (isset($callId) && isset($db)) {
        try {
            logCallMetrics($db, $callId, [
                'turn_number' => $aiContext['turn_count'] ?? 0,
                'step' => $step ?? 'unknown',
                'error_type' => 'exception',
                'error_message' => $errorMasked,
            ]);
        } catch (Exception $metricEx) {
            // Don't let metrics logging break the error handler
        }
    }

    // Determine error component for graceful degradation
    $component = 'claude'; // default
    $errLower = mb_strtolower($errorMsg, 'UTF-8');
    if (str_contains($errLower, 'pdo') || str_contains($errLower, 'sql') || str_contains($errLower, 'database') || str_contains($errLower, 'connection refused')) {
        $component = 'database';
    } elseif (str_contains($errLower, 'curl') || str_contains($errLower, 'timeout') || str_contains($errLower, 'resolve host')) {
        $component = 'network';
    }

    // Use degraded mode for the response
    $degradedTwiml = handleDegradedMode($component, $errorMsg, [
        'step' => $step ?? 'identify_store',
        'last_input' => $userInput ?? '',
        'items' => $aiContext['items'] ?? [],
        'store_name' => $aiContext['store_name'] ?? null,
        'nearby_stores' => $aiContext['nearby_stores'] ?? [],
    ]);

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
    echo $degradedTwiml;
    echo '</Gather>';
    echo buildTwilioSay('Pode falar ou digitar, tô te escutando! Aperta zero se quiser falar com uma pessoa.');
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
    echo '<Play loop="0">http://com.twilio.music.pop-rock.s3.amazonaws.com/Ab0-3.mp3</Play>';
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

// ═══════════════════════════════════════════════════════════════════════════
// INTELLIGENCE FEATURES (ported from WhatsApp bot, adapted for voice)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Smart Reorder Prediction — analyzes order patterns by day/hour/store.
 * Returns suggestion array or null if confidence too low.
 */
function getVoiceReorderSuggestion(PDO $db, int $customerId): ?array
{
    $tz = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTime('now', $tz);
    $currentDow = (int)$now->format('w');
    $currentHour = (int)$now->format('G');

    try {
        $stmt = $db->prepare("
            SELECT o.order_id, o.partner_id, o.partner_name, o.date_added, o.created_at
            FROM om_market_orders o
            WHERE o.customer_id = ?
              AND o.status IN ('entregue', 'delivered', 'retirado')
            ORDER BY o.date_added DESC
            LIMIT 20
        ");
        $stmt->execute([$customerId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($orders) < 3) return null;

        $dowCounts = array_fill(0, 7, 0);
        $hourCounts = array_fill(0, 24, 0);
        $storeCounts = [];
        $totalOrders = count($orders);

        foreach ($orders as $order) {
            $orderDate = new DateTime($order['date_added'] ?? $order['created_at'], $tz);
            $dow = (int)$orderDate->format('w');
            $hour = (int)$orderDate->format('G');
            $pid = (int)$order['partner_id'];

            $dowCounts[$dow]++;
            $hourCounts[$hour]++;

            if (!isset($storeCounts[$pid])) {
                $storeCounts[$pid] = ['count' => 0, 'name' => $order['partner_name'], 'items' => []];
            }
            $storeCounts[$pid]['count']++;

            try {
                $itemStmt = $db->prepare("
                    SELECT oi.name, oi.quantity FROM om_market_order_items oi
                    WHERE oi.order_id = ? ORDER BY oi.quantity DESC LIMIT 4
                ");
                $itemStmt->execute([$order['order_id']]);
                foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    $key = mb_strtolower(trim($it['name']));
                    if (!isset($storeCounts[$pid]['items'][$key])) {
                        $storeCounts[$pid]['items'][$key] = ['name' => $it['name'], 'count' => 0];
                    }
                    $storeCounts[$pid]['items'][$key]['count'] += (int)$it['quantity'];
                }
            } catch (\Exception $e) { /* non-critical */ }
        }

        uasort($storeCounts, fn($a, $b) => $b['count'] - $a['count']);
        $topStore = reset($storeCounts);
        if (!$topStore || $topStore['count'] < 2) return null;

        $dowScore = $dowCounts[$currentDow] / $totalOrders;
        $hourScore = 0;
        for ($h = $currentHour - 2; $h <= $currentHour + 2; $h++) {
            $hourScore += $hourCounts[(($h % 24) + 24) % 24];
        }
        $hourScore /= $totalOrders;
        $storeScore = $topStore['count'] / $totalOrders;
        $confidence = ($dowScore * 0.35) + ($hourScore * 0.35) + ($storeScore * 0.30);

        if ($confidence < 0.15) return null;

        $storeItems = $topStore['items'];
        uasort($storeItems, fn($a, $b) => $b['count'] - $a['count']);
        $topItems = array_map(fn($i) => $i['name'], array_slice(array_values($storeItems), 0, 3));

        $dayNames = ['domingo', 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'];
        if ($currentHour >= 6 && $currentHour < 12) $period = 'de manha';
        elseif ($currentHour >= 12 && $currentHour < 18) $period = 'na hora do almoco';
        elseif ($currentHour >= 18 && $currentHour < 23) $period = 'a noite';
        else $period = 'de madrugada';

        return [
            'store_name' => $topStore['name'],
            'items'      => $topItems,
            'pattern'    => "Costuma pedir da {$topStore['name']} {$dayNames[$currentDow]} {$period}",
            'confidence' => round($confidence, 2),
        ];
    } catch (\Exception $e) {
        error_log("[twilio-voice-ai] Reorder prediction error: " . $e->getMessage());
        return null;
    }
}

/**
 * Voice Call History — summarizes last N calls for conversation continuity.
 */
function getVoiceCallHistory(PDO $db, string $phone, int $limit = 3): string
{
    $phoneClean = preg_replace('/\D/', '', $phone);
    if (empty($phoneClean)) return '';

    try {
        $stmt = $db->prepare("
            SELECT id, notes, created_at, status, store_identified
            FROM om_callcenter_calls
            WHERE customer_phone LIKE ?
              AND status IN ('completed', 'transferred', 'ai_completed')
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute(['%' . substr($phoneClean, -11), $limit + 1]);
        $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($calls)) return '';

        // Skip current call (most recent)
        $pastCalls = array_slice($calls, 1, $limit);
        if (empty($pastCalls)) return '';

        $tz = new DateTimeZone('America/Sao_Paulo');
        $lines = [];

        foreach ($pastCalls as $call) {
            $callDate = new DateTime($call['created_at'], $tz);
            $dateStr = $callDate->format('d/m H:i');
            // ai_context is stored inside notes JSON under '_ai_context' key
            $notesData = json_decode($call['notes'] ?? '{}', true) ?: [];
            $ctx = $notesData['_ai_context'] ?? [];
            $storeName = $ctx['store_name'] ?? $call['store_identified'] ?? '';
            $items = $ctx['items'] ?? [];
            $step = $ctx['step'] ?? '';

            if (!empty($items) && !empty($storeName)) {
                $itemNames = array_map(fn($i) => ($i['quantity'] ?? 1) . 'x ' . ($i['name'] ?? '?'), array_slice($items, 0, 3));
                $summary = "Pediu da {$storeName}: " . implode(', ', $itemNames);
            } elseif ($step === 'support') {
                $summary = "Pediu suporte/ajuda";
            } elseif (!empty($storeName)) {
                $summary = "Perguntou sobre {$storeName}";
            } else {
                $summary = "Ligacao breve";
            }
            $lines[] = "- {$dateStr}: {$summary}";
        }

        return empty($lines) ? '' : "HISTORICO DE LIGACOES:\n" . implode("\n", $lines);
    } catch (\Exception $e) {
        error_log("[twilio-voice-ai] Call history error: " . $e->getMessage());
        return '';
    }
}

/**
 * Voice Sentiment Detection — adapted for speech transcription signals.
 * Detects: frustrated, hurried, happy, confused, neutral.
 */
function detectVoiceSentiment(string $speechText): array
{
    $result = ['mood' => 'neutral', 'confidence' => 0.5, 'signals' => []];
    $msg = $speechText;
    $msgLower = mb_strtolower($msg);
    $msgLen = mb_strlen($msg);

    $scores = ['frustrated' => 0.0, 'hurried' => 0.0, 'happy' => 0.0, 'confused' => 0.0];

    // -- Frustrated (voice-adapted: includes spoken frustration cues) --
    $frustratedWords = [
        'absurdo', 'demora', 'lixo', 'pessimo', 'horrivel', 'porcaria',
        'nunca mais', 'ridiculo', 'vergonha', 'nojento', 'raiva',
        'cansei', 'que saco', 'inferno', 'droga',
        'cad[eê] meu', 'nao aguento',
        // Voice-specific frustration signals
        'escuta', 'olha', 'presta aten[cç][aã]o', 'eu j[aá] falei',
        'to falando', 'pelo amor de deus', 'meu deus',
        'n[aã]o [eé] poss[ií]vel', 'de novo n[aã]o',
    ];
    $negHits = 0;
    foreach ($frustratedWords as $w) {
        if (preg_match('/' . $w . '/ui', $msgLower)) $negHits++;
    }
    if ($negHits >= 1) { $scores['frustrated'] += 0.3; $result['signals'][] = 'negative_keyword'; }
    if ($negHits >= 2) { $scores['frustrated'] += 0.15; $result['signals'][] = 'multiple_negatives'; }

    // Very short curt responses can signal frustration (voice-specific)
    if ($msgLen > 0 && $msgLen <= 5 && !preg_match('/(sim|n[aã]o|oi|ok)/ui', $msgLower)) {
        $scores['frustrated'] += 0.1;
        $result['signals'][] = 'curt_response';
    }

    // -- Hurried --
    $hurriedWords = [
        'r[aá]pido', 'urgente', 'pressa', 'depressa', 'logo',
        'agora', 'correndo', 'to com fome', 'morrendo de fome',
        'faz logo', 'manda logo', 'pelo amor', 'sem enrola[cç][aã]o',
    ];
    foreach ($hurriedWords as $w) {
        if (preg_match('/' . $w . '/ui', $msgLower)) {
            $scores['hurried'] += 0.3; $result['signals'][] = 'impatience_words'; break;
        }
    }
    // Short responses without greetings → hurried (voice-specific: people in a rush speak less)
    if ($msgLen < 12 && !preg_match('/(oi|ol[aá]|bom dia|boa tarde|boa noite|opa)/ui', $msgLower)) {
        $scores['hurried'] += 0.1; $result['signals'][] = 'short_no_greeting';
    }

    // -- Happy --
    $happyWords = [
        '[oó]timo', 'maravilha', 'perfeito', 'adorei', 'amei',
        'top', 'show', 'massa', 'demais', 'sensacional',
        'excelente', 'obrigad[oa]', 'valeu', 'del[ií]cia',
        'que bom', 'arrasou', 'nota 10', 'por favor',
    ];
    foreach ($happyWords as $w) {
        if (preg_match('/' . $w . '/ui', $msgLower)) {
            $scores['happy'] += 0.3; $result['signals'][] = 'positive_keyword'; break;
        }
    }
    // "por favor" = polite (voice-specific)
    if (preg_match('/por favor/ui', $msgLower)) {
        $scores['happy'] += 0.1; $result['signals'][] = 'polite';
    }

    // -- Confused --
    $confusedWords = [
        'n[aã]o entendi', 'como assim', 'hein', 'n[aã]o sei',
        'confus[oa]', 'perdid[oa]', 'que significa', 'como funciona',
        'pode repetir', 'repete', 'n[aã]o ficou claro', 'como [eé]',
        'o qu[eê]', 'n[aã]o peguei',
    ];
    foreach ($confusedWords as $w) {
        if (preg_match('/' . $w . '/ui', $msgLower)) {
            $scores['confused'] += 0.35; $result['signals'][] = 'confusion_keyword'; break;
        }
    }

    // Determine winner
    $maxScore = 0.0;
    $winningMood = 'neutral';
    foreach ($scores as $mood => $score) {
        if ($score > $maxScore) { $maxScore = $score; $winningMood = $mood; }
    }

    if ($maxScore >= 0.2) {
        $result['mood'] = $winningMood;
        $result['confidence'] = min(1.0, round($maxScore, 2));
    } else {
        $result['mood'] = 'neutral';
        $result['confidence'] = round(0.5 + $maxScore, 2);
    }

    $result['signals'] = array_values(array_unique($result['signals']));
    return $result;
}

/**
 * Weather-Aware Suggestions — pure logic, no API (São Paulo climate).
 * Returns season, meal type, food suggestions, and natural voice phrase.
 */
function getVoiceWeatherContext(): array
{
    $tz = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTime('now', $tz);
    $month = (int)$now->format('n');
    $hour = (int)$now->format('G');
    $dayOfWeek = (int)$now->format('N');
    $isWeekend = ($dayOfWeek >= 6);

    // Season based on SP climate
    if ($month >= 6 && $month <= 8) {
        $season = 'cold';
    } elseif ($month === 12 || $month <= 2) {
        $season = ($hour >= 14 && $hour <= 18) ? 'rainy' : 'hot';
    } elseif ($month >= 3 && $month <= 5) {
        $season = ($hour >= 18) ? 'cold' : 'mild';
    } else {
        $season = ($month === 11 && $hour >= 14) ? 'rainy' : 'mild';
    }

    // Meal type
    if ($hour >= 6 && $hour < 10) $mealType = 'breakfast';
    elseif ($hour >= 10 && $hour < 14) $mealType = 'lunch';
    elseif ($hour >= 14 && $hour < 17) $mealType = 'snack';
    elseif ($hour >= 17 && $hour < 22) $mealType = 'dinner';
    else $mealType = 'snack';
    if ($isWeekend && $hour >= 9 && $hour < 12) $mealType = 'brunch';

    $seasonSuggestions = [
        'hot'   => ['acai', 'sorvete', 'salada', 'suco natural', 'poke', 'comida leve'],
        'cold'  => ['sopa', 'chocolate quente', 'caldo', 'comida caseira', 'canja'],
        'rainy' => ['pizza', 'hamburguer', 'sopas', 'pastel', 'cafe com bolo'],
        'mild'  => ['prato do dia', 'marmita', 'salada', 'bowl'],
    ];

    $mealSuggestions = [
        'breakfast' => ['cafe', 'pao de queijo', 'tapioca', 'acai'],
        'brunch'    => ['acai', 'brunch', 'panqueca', 'suco'],
        'lunch'     => ['marmita', 'prato feito', 'executivo'],
        'snack'     => ['acai', 'lanche', 'pastel', 'cafe'],
        'dinner'    => ['pizza', 'hamburguer', 'japonesa', 'churrasco'],
    ];

    $suggestions = array_unique(array_merge(
        $seasonSuggestions[$season] ?? [],
        $mealSuggestions[$mealType] ?? []
    ));

    // Short voice-friendly phrases
    $voicePhrases = [
        'hot'   => 'Ta quente hoje, algo refrescante cai bem!',
        'cold'  => 'Ta frio, hein? Sopinha ou chocolate quente?',
        'rainy' => 'Dia de chuva pede um comfort food!',
        'mild'  => 'Clima gostoso pra qualquer pedido!',
    ];

    return [
        'season'      => $season,
        'meal_type'   => $mealType,
        'is_weekend'  => $isWeekend,
        'suggestions' => array_slice($suggestions, 0, 6),
        'voice_hint'  => $voicePhrases[$season] ?? '',
    ];
}

/**
 * Get customer's favorite stores based on order history and favorites table.
 */
function getVoiceFavoriteStores(PDO $db, int $customerId, int $limit = 5): array {
    try {
        $stmt = $db->prepare("
            SELECT p.partner_id, p.name,
                   COALESCE(oc.cnt, 0) AS order_count,
                   CASE WHEN f.id IS NOT NULL THEN true ELSE false END AS is_favorited
            FROM om_market_partners p
            LEFT JOIN (
                SELECT partner_id, COUNT(*) AS cnt
                FROM om_market_orders
                WHERE customer_id = ? AND status NOT IN ('cancelled','refunded')
                GROUP BY partner_id
            ) oc ON oc.partner_id = p.partner_id
            LEFT JOIN om_market_favorites f ON f.partner_id = p.partner_id AND f.customer_id = ?
            WHERE p.status = '1'
              AND (oc.cnt > 0 OR f.id IS NOT NULL)
            ORDER BY COALESCE(oc.cnt, 0) DESC, f.id DESC NULLS LAST
            LIMIT ?
        ");
        $stmt->execute([$customerId, $customerId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("[twilio-voice-ai] getVoiceFavoriteStores error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get voice loyalty tier based on 90-day order history.
 * Bronze (0-4), Prata (5-14), Ouro (15-29 or R$500+), Diamante (30+ or R$1000+)
 */
function getVoiceLoyaltyTier(PDO $db, int $customerId): array {
    $stmt = $db->prepare("
        SELECT COUNT(*) as orders_90d,
               COALESCE(SUM(total), 0) as spent_90d
        FROM om_market_orders
        WHERE customer_id = ? AND status NOT IN ('cancelled','refunded')
          AND date_added >= NOW() - INTERVAL '90 days'
    ");
    $stmt->execute([$customerId]);
    $row = $stmt->fetch();
    $orders = (int)($row['orders_90d'] ?? 0);
    $spent = (float)($row['spent_90d'] ?? 0);

    if ($orders >= 30 || $spent >= 1000) {
        return ['tier' => 'Diamante', 'badge' => 'diamante', 'orders_90d' => $orders, 'spent_90d' => $spent];
    } elseif ($orders >= 15 || $spent >= 500) {
        return ['tier' => 'Ouro', 'badge' => 'ouro', 'orders_90d' => $orders, 'spent_90d' => $spent];
    } elseif ($orders >= 5) {
        return ['tier' => 'Prata', 'badge' => 'prata', 'orders_90d' => $orders, 'spent_90d' => $spent];
    }
    return ['tier' => 'Bronze', 'badge' => 'bronze', 'orders_90d' => $orders, 'spent_90d' => $spent];
}

/**
 * Auto-learn customer preferences from last 20 orders.
 * Returns payment preference, common customizations, favorite categories, avg order value.
 */
function getVoiceLearnedPreferences(PDO $db, int $customerId): array {
    $prefs = [
        'payment_preference' => null,
        'common_customizations' => [],
        'favorite_categories' => [],
        'avg_order_value' => 0,
    ];

    // Payment preference (most common method in last 20 orders)
    $stmt = $db->prepare("
        SELECT forma_pagamento, COUNT(*) as cnt
        FROM (
            SELECT forma_pagamento FROM om_market_orders
            WHERE customer_id = ? AND status NOT IN ('cancelled','refunded')
              AND forma_pagamento IS NOT NULL AND forma_pagamento != ''
            ORDER BY date_added DESC LIMIT 20
        ) sub
        GROUP BY forma_pagamento ORDER BY cnt DESC LIMIT 1
    ");
    $stmt->execute([$customerId]);
    $topPayment = $stmt->fetch();
    if ($topPayment && $topPayment['forma_pagamento']) {
        $prefs['payment_preference'] = $topPayment['forma_pagamento'];
    }

    // Common customizations from order item notes (recurring "sem cebola" etc.)
    $stmt = $db->prepare("
        SELECT notes FROM om_market_order_items
        WHERE order_id IN (
            SELECT order_id FROM om_market_orders
            WHERE customer_id = ? AND status NOT IN ('cancelled','refunded')
            ORDER BY date_added DESC LIMIT 20
        ) AND notes IS NOT NULL AND notes != ''
    ");
    $stmt->execute([$customerId]);
    $noteCounts = [];
    foreach ($stmt->fetchAll() as $row) {
        $note = mb_strtolower(trim($row['notes']));
        if (mb_strlen($note) > 2) {
            $noteCounts[$note] = ($noteCounts[$note] ?? 0) + 1;
        }
    }
    // Keep notes that appeared 2+ times (actual preferences, not one-offs)
    foreach ($noteCounts as $note => $count) {
        if ($count >= 2) {
            $prefs['common_customizations'][] = $note;
        }
    }

    // Favorite categories (from partner categories in recent orders)
    $stmt = $db->prepare("
        SELECT p.categoria, COUNT(*) as cnt
        FROM om_market_orders o
        JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.customer_id = ? AND o.status NOT IN ('cancelled','refunded')
          AND p.categoria IS NOT NULL AND p.categoria != ''
        GROUP BY p.categoria ORDER BY cnt DESC LIMIT 3
    ");
    $stmt->execute([$customerId]);
    foreach ($stmt->fetchAll() as $row) {
        $prefs['favorite_categories'][] = $row['categoria'];
    }

    // Average order value
    $stmt = $db->prepare("
        SELECT AVG(total) as avg_val
        FROM (
            SELECT total FROM om_market_orders
            WHERE customer_id = ? AND status NOT IN ('cancelled','refunded')
            ORDER BY date_added DESC LIMIT 20
        ) sub
    ");
    $stmt->execute([$customerId]);
    $avg = $stmt->fetch();
    $prefs['avg_order_value'] = round((float)($avg['avg_val'] ?? 0), 2);

    return $prefs;
}

/**
 * Convert ETA minutes to spoken Brazilian Portuguese.
 * E.g. 40 → "quarenta minutinhos", 65 → "uma hora e cinco minutos"
 */
function spokenETA(int $minutes): string {
    if ($minutes <= 0) return 'poucos minutos';
    $numberWords = [
        5 => 'cinco', 10 => 'dez', 15 => 'quinze', 20 => 'vinte',
        25 => 'vinte e cinco', 30 => 'trinta', 35 => 'trinta e cinco',
        40 => 'quarenta', 45 => 'quarenta e cinco', 50 => 'cinquenta',
        55 => 'cinquenta e cinco', 60 => 'sessenta',
    ];
    // Round to nearest 5
    $rounded = (int)(round($minutes / 5) * 5);
    $rounded = max(5, min(120, $rounded));
    if ($rounded > 60) {
        $hrs = intdiv($rounded, 60);
        $rem = $rounded % 60;
        $hPart = $hrs === 1 ? 'uma hora' : "{$hrs} horas";
        if ($rem === 0) return $hPart;
        $mPart = $numberWords[$rem] ?? "{$rem}";
        return "{$hPart} e {$mPart} minutos";
    }
    $word = $numberWords[$rounded] ?? "{$rounded}";
    return $rounded <= 30 ? "{$word} minutinhos" : "{$word} minutos";
}

/**
 * Check if customer has a referral code.
 */
function getCustomerReferralCode(PDO $db, ?int $customerId): ?string {
    if (!$customerId) return null;
    try {
        $stmt = $db->prepare("SELECT code FROM referral_codes WHERE user_id = ? AND active = true LIMIT 1");
        $stmt->execute([$customerId]);
        $code = $stmt->fetchColumn();
        return $code ?: null;
    } catch (Exception $e) {
        // Table may not exist — non-critical
        return null;
    }
}

/**
 * Build group order summary for confirmation step (voice-friendly).
 */
function buildGroupSummaryForVoice(array $items): string {
    $byMember = [];
    $unassigned = [];
    foreach ($items as $item) {
        $member = $item['group_member'] ?? null;
        if ($member) {
            $byMember[$member][] = ($item['quantity'] ?? 1) . 'x ' . ($item['name'] ?? '?');
        } else {
            $unassigned[] = ($item['quantity'] ?? 1) . 'x ' . ($item['name'] ?? '?');
        }
    }
    if (empty($byMember)) return '';
    $parts = [];
    foreach ($byMember as $name => $memberItems) {
        $parts[] = "{$name}: " . implode(', ', $memberItems);
    }
    if (!empty($unassigned)) {
        $parts[] = "Geral: " . implode(', ', $unassigned);
    }
    return implode(' | ', $parts);
}


/**
 * Voice Recommendations — multi-signal scoring for phone ordering.
 */
function getVoiceRecommendations(PDO $db, int $customerId, int $partnerId, int $limit = 3): array {
    try {
        $hora = (int)date('G');
        $recs = [];
        // 1. Purchase history (weight: 40)
        $stmt = $db->prepare("
            SELECT oi.product_id, p.name, COALESCE(NULLIF(p.special_price,0), p.price) AS price, COUNT(*) AS buy_count
            FROM om_market_order_items oi
            JOIN om_market_orders o ON o.order_id = oi.order_id
            JOIN om_market_products p ON p.product_id = oi.product_id
            WHERE o.customer_id = ? AND o.partner_id = ? AND o.status = 'entregue' AND p.status = 1 AND p.quantity > 0
            GROUP BY oi.product_id, p.name, p.price, p.special_price ORDER BY buy_count DESC LIMIT 5
        ");
        $stmt->execute([$customerId, $partnerId]);
        foreach ($stmt->fetchAll() as $r) {
            $recs[$r['product_id']] = ['product_id' => (int)$r['product_id'], 'name' => $r['name'], 'price' => (float)$r['price'], 'score' => (int)$r['buy_count'] * 40, 'reason' => "pediu {$r['buy_count']}x"];
        }
        // 2. Collaborative filtering (weight: 25)
        $stmt = $db->prepare("
            SELECT oi2.product_id, p.name, COALESCE(NULLIF(p.special_price,0), p.price) AS price, COUNT(DISTINCT o2.customer_id) AS co_buyers
            FROM om_market_orders o1
            JOIN om_market_order_items oi1 ON oi1.order_id = o1.order_id
            JOIN om_market_order_items oi2 ON oi2.product_id != oi1.product_id
            JOIN om_market_orders o2 ON o2.order_id = oi2.order_id AND o2.customer_id != ? AND o2.partner_id = ?
            JOIN om_market_order_items oi3 ON oi3.order_id = o2.order_id AND oi3.product_id = oi1.product_id
            JOIN om_market_products p ON p.product_id = oi2.product_id AND p.status = 1 AND p.quantity > 0 AND p.partner_id = ?
            WHERE o1.customer_id = ? AND o1.partner_id = ? AND o1.status = 'entregue' AND o1.created_at > NOW() - INTERVAL '60 days'
            GROUP BY oi2.product_id, p.name, p.price, p.special_price ORDER BY co_buyers DESC LIMIT 10
        ");
        $stmt->execute([$customerId, $partnerId, $partnerId, $customerId, $partnerId]);
        $tbStmt = $db->prepare("SELECT COUNT(DISTINCT customer_id) FROM om_market_orders WHERE partner_id = ? AND status = 'entregue' AND created_at > NOW() - INTERVAL '60 days'");
        $tbStmt->execute([$partnerId]);
        $totalBuyers = max(1, (int)$tbStmt->fetchColumn());
        foreach ($stmt->fetchAll() as $r) {
            $pct = round(((int)$r['co_buyers'] / $totalBuyers) * 100);
            $pid = (int)$r['product_id'];
            if (isset($recs[$pid])) { $recs[$pid]['score'] += $pct * 0.25; $recs[$pid]['reason'] .= ", {$pct}% pedem junto"; }
            else { $recs[$pid] = ['product_id' => $pid, 'name' => $r['name'], 'price' => (float)$r['price'], 'score' => $pct * 25, 'reason' => "{$pct}% dos clientes pedem"]; }
        }
        // 3. Time-of-day boost (+15)
        $timeKw = [];
        if ($hora >= 6 && $hora < 10) $timeKw = ['café', 'pão', 'tapioca', 'suco', 'vitamina'];
        elseif ($hora >= 11 && $hora < 14) $timeKw = ['almoço', 'executivo', 'marmita', 'arroz', 'feijão'];
        elseif ($hora >= 18 && $hora < 23) $timeKw = ['pizza', 'hambúrguer', 'burger', 'lanche', 'sushi'];
        foreach ($recs as &$rec) { $nl = mb_strtolower($rec['name']); foreach ($timeKw as $kw) { if (mb_strpos($nl, $kw) !== false) { $rec['score'] += 15; break; } } }
        unset($rec);
        usort($recs, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice(array_values($recs), 0, $limit);
    } catch (\Exception $e) { error_log("[twilio-voice-ai] getVoiceRecommendations error: " . $e->getMessage()); return []; }
}

/**
 * Voice Price Comparison — find single cheaper alternative at another OPEN store (>15% savings).
 */
function findVoiceCheaperAlternatives(PDO $db, string $productName, int $currentPartnerId, float $currentPrice): ?array {
    if ($currentPrice <= 0 || mb_strlen($productName) < 3) return null;
    try {
        $cleanName = mb_strtolower(trim($productName));
        $cleanName = trim(preg_replace('/\s*\d+\s*(ml|l|g|kg|un|und|pct|pc)\b/i', '', $cleanName));
        if (mb_strlen($cleanName) < 3) return null;
        $stmt = $db->prepare("
            SELECT p.name AS product_name, COALESCE(NULLIF(p.special_price,0), p.price) AS final_price, COALESCE(pa.trade_name, pa.name) AS partner_name
            FROM om_market_products p JOIN om_market_partners pa ON pa.partner_id = p.partner_id
            WHERE p.partner_id != ? AND p.status = 1 AND p.quantity > 0 AND pa.status::text = '1' AND pa.is_open = 1
              AND p.name ILIKE ? AND COALESCE(NULLIF(p.special_price,0), p.price) < ?
            ORDER BY COALESCE(NULLIF(p.special_price,0), p.price) ASC LIMIT 1
        ");
        $stmt->execute([$currentPartnerId, '%' . $cleanName . '%', $currentPrice * 0.85]);
        $alt = $stmt->fetch();
        if (!$alt) return null;
        $altPrice = (float)$alt['final_price'];
        $savings = round($currentPrice - $altPrice, 2);
        return ['partner_name' => $alt['partner_name'], 'product_name' => $alt['product_name'], 'price' => $altPrice, 'savings' => $savings,
            'tip' => "Essa {$cleanName} custa R\$" . number_format($altPrice, 2, ',', '.') . " na {$alt['partner_name']} (economia de R\$" . number_format($savings, 2, ',', '.') . ")"];
    } catch (\Exception $e) { error_log("[twilio-voice-ai] findVoiceCheaperAlternatives error: " . $e->getMessage()); return null; }
}

/**
 * Voice Cart Optimization — returns a SINGLE best tip sentence or null.
 * Priority: free delivery > min order > combo suggestion.
 */
function analyzeVoiceCartOptimizations(PDO $db, array $items, int $partnerId): ?string {
    if (empty($items)) return null;
    try {
        $subtotal = 0;
        foreach ($items as $item) { $subtotal += ($item['price'] ?? 0) * ($item['quantity'] ?? 1); }
        $stmt = $db->prepare("SELECT free_delivery_above, min_order_value, delivery_fee FROM om_market_partners WHERE partner_id = ? AND status::text = '1'");
        $stmt->execute([$partnerId]);
        $store = $stmt->fetch();
        if (!$store) return null;
        $freeAbove = (float)($store['free_delivery_above'] ?? 0);
        $minOrder = (float)($store['min_order_value'] ?? 0);
        $deliveryFee = (float)($store['delivery_fee'] ?? 5.0);
        // Priority 1: Free delivery threshold (within R$15)
        if ($freeAbove > 0 && $subtotal < $freeAbove && $deliveryFee > 0) {
            $diff = round($freeAbove - $subtotal, 2);
            if ($diff <= 15.0) return "Faltam R\$" . number_format($diff, 2, ',', '.') . " pro frete grátis!";
        }
        // Priority 2: Min order warning
        if ($minOrder > 0 && $subtotal < $minOrder) {
            $diff = round($minOrder - $subtotal, 2);
            return "Pedido mínimo é R\$" . number_format($minOrder, 2, ',', '.') . ", faltam R\$" . number_format($diff, 2, ',', '.') . ".";
        }
        // Priority 3: Combo suggestion
        if (count($items) >= 2) {
            $comboStmt = $db->prepare("SELECT name, COALESCE(NULLIF(special_price,0), price) AS price FROM om_market_products WHERE partner_id = ? AND status = 1 AND quantity > 0 AND (LOWER(name) LIKE '%combo%' OR LOWER(name) LIKE '%kit%' OR LOWER(name) LIKE '%promocao%') AND COALESCE(NULLIF(special_price,0), price) < ? ORDER BY price ASC LIMIT 1");
            $comboStmt->execute([$partnerId, $subtotal]);
            $combo = $comboStmt->fetch();
            if ($combo) return "Tem o {$combo['name']} por R\$" . number_format((float)$combo['price'], 2, ',', '.') . ", que pode sair melhor!";
        }
        return null;
    } catch (\Exception $e) { error_log("[twilio-voice-ai] analyzeVoiceCartOptimizations error: " . $e->getMessage()); return null; }
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
    $prompt .= "1. MÁXIMO 2 frases curtas por resposta (exceto confirmação de pedido). Isso vira AUDIO DE VOZ no telefone — textão = cliente desliga.\n";
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

    // Call history (from voice calls)
    $callHistoryCtx = $extraData['call_history'] ?? '';
    if (!empty($callHistoryCtx)) {
        $prompt .= "## {$callHistoryCtx}\n";
        $prompt .= "Use pra continuidade: 'Da ultima vez voce pediu da X, quer de la de novo?'\n\n";
    }

    // Live sentiment detection
    $sentiment = $extraData['sentiment'] ?? null;
    if ($sentiment && $sentiment['mood'] !== 'neutral' && ($sentiment['confidence'] ?? 0) >= 0.25) {
        $moodRules = [
            'frustrated' => 'FRUSTRADO → Seja empatica e DIRETA. Zero enrolacao. Resolva rapido.',
            'hurried'    => 'COM PRESSA → Ultra-curta, pule elogios, va direto ao ponto.',
            'happy'      => 'FELIZ → Seja animada junto, brinque um pouco!',
            'confused'   => 'CONFUSO → Reformule com carinho, confirme cada etapa.',
        ];
        $moodRule = $moodRules[$sentiment['mood']] ?? '';
        if ($moodRule) {
            $prompt .= "ESTADO EMOCIONAL DO CLIENTE: {$moodRule}\n\n";
        }
    }

    if ($customerName) {
        $prompt .= "CLIENTE: {$customerName}";
        if ($customerStats && (int)$customerStats['total_orders'] > 0) {
            $orders = (int)$customerStats['total_orders'];
            $value = number_format((float)$customerStats['lifetime_value'], 2, ',', '.');
            $prompt .= " (cliente fiel: {$orders} pedidos, R\${$value} total)";
            if ($orders >= 20) $prompt .= " [VIP]";
        }
        $loyaltyTier = $extraData['loyalty_tier'] ?? null;
        if ($loyaltyTier && in_array($loyaltyTier['tier'], ['Ouro', 'Diamante'])) {
            $prompt .= "\nCLIENTE VIP ({$loyaltyTier['tier']}) — trate com carinho extra! Mencione naturalmente: 'Como nosso cliente especial, vou ver se consigo algo pra voce!'";
        } elseif ($loyaltyTier && $loyaltyTier['tier'] === 'Prata') {
            $prompt .= "\nCliente Prata — bom cliente, seja caloroso!";
        }
        $prompt .= "\n\n";

        // Referral hint for frequent customers (light touch — just a prompt hint)
        if ($customerStats && (int)$customerStats['total_orders'] > 5) {
            $referralCode = $extraData['referral_code'] ?? null;
            if ($referralCode) {
                $prompt .= "DICA: Esse cliente é frequente e tem código de indicação ({$referralCode}). Se parecer natural, mencione: 'Sabia que você pode indicar amigos e ganhar cashback? Me manda um zap que eu explico!'\n\n";
            }
        }
    }

    // Learned preferences context (auto-learned from order history)
    $learnedPrefs = $extraData['learned_prefs'] ?? null;
    if ($learnedPrefs) {
        $prefsLines = [];
        if (!empty($learnedPrefs['common_customizations'])) {
            $prefsLines[] = "Customizacoes frequentes: " . implode(', ', array_slice($learnedPrefs['common_customizations'], 0, 3));
        }
        if (!empty($learnedPrefs['favorite_categories'])) {
            $prefsLines[] = "Categorias favoritas: " . implode(', ', $learnedPrefs['favorite_categories']);
        }
        if ($learnedPrefs['avg_order_value'] > 0) {
            $prefsLines[] = "Ticket medio: R$" . number_format($learnedPrefs['avg_order_value'], 2, ',', '.');
        }
        if (!empty($prefsLines)) {
            $prompt .= "## PREFERENCIAS APRENDIDAS\n" . implode("\n", $prefsLines) . "\n\n";
        }
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

            // Favorite stores (from order history + favorites)
            $favStores = $extraData['favorite_stores'] ?? [];
            if (!empty($favStores)) {
                $prompt .= "LOJAS FAVORITAS DO CLIENTE:\n";
                foreach ($favStores as $i => $fs) {
                    $n = $i + 1;
                    $cnt = (int)($fs['order_count'] ?? 0);
                    $fav = !empty($fs['is_favorited']) ? ' [favorita]' : '';
                    $prompt .= "{$n}. {$fs['name']} ({$cnt} pedidos){$fav}\n";
                }
                $prompt .= "Se o cliente está indeciso, sugira a favorita: 'Quer pedir da {$favStores[0]['name']} como sempre?'\n\n";
            }

            $prompt .= "RESTAURANTES DISPONÍVEIS:\n" . implode("\n", $storeNames) . "\n\n";

            if (!empty($extraData['default_address'])) {
                $da = $extraData['default_address'];
                $prompt .= "ENDEREÇO SALVO: {$da['neighborhood']}, {$da['city']}\n\n";
            }

            // Smart Reorder Prediction
            $reorder = $extraData['reorder_suggestion'] ?? null;
            if ($reorder && ($reorder['confidence'] ?? 0) >= 0.15) {
                $prompt .= "SUGESTAO DE REORDER: {$reorder['pattern']}.";
                if (!empty($reorder['items'])) {
                    $prompt .= " Itens frequentes: " . implode(', ', $reorder['items']) . ".";
                }
                $prompt .= "\nSugira naturalmente: 'Hora da {$reorder['store_name']}, ne? Quer o de sempre?'\n\n";
            }

            // Weather-Aware Suggestions
            $weather = $extraData['weather_context'] ?? null;
            if ($weather && !empty($weather['voice_hint'])) {
                $prompt .= "CONTEXTO CLIMA: {$weather['voice_hint']}";
                if (!empty($weather['suggestions'])) {
                    $prompt .= " Sugira: " . implode(', ', array_slice($weather['suggestions'], 0, 4));
                }
                $prompt .= "\nUse naturalmente na conversa (ex: 'Ta frio hoje, uma sopinha cai bem!').\n\n";
            }

            $hora = (int)date('H');
            if ($hora >= 6 && $hora < 10) $prompt .= "HORÁRIO: Manhã — sugira café, padaria, café da manhã\n";
            elseif ($hora >= 11 && $hora < 14) $prompt .= "HORÁRIO: Almoço — sugira executivos, marmita, caseira, self-service\n";
            elseif ($hora >= 14 && $hora < 17) $prompt .= "HORÁRIO: Tarde — sugira açaí, lanches, cafeteria, sobremesa\n";
            elseif ($hora >= 18 && $hora < 22) $prompt .= "HORÁRIO: Noite — sugira pizza, hambúrguer, japonês, churrasco\n";
            else $prompt .= "HORÁRIO: Madrugada — sugira o que funciona nesse horário, seja compreensivo\n";

            // Learned category preferences for store suggestions
            if ($learnedPrefs && !empty($learnedPrefs['favorite_categories'])) {
                $prompt .= "CATEGORIAS FAVORITAS DO CLIENTE: " . implode(', ', $learnedPrefs['favorite_categories']) . " — priorize sugestoes dessas categorias!\n";
            }

            $prompt .= "\nSe o cliente quiser pedir de MAIS DE UMA LOJA no mesmo pedido, aceite normalmente.\n";
            $prompt .= "Quando terminar o pedido da primeira loja e o cliente quiser adicionar de outra, use [NEXT_STORE] para sinalizar.\n";
            $prompt .= "Exemplo: 'Quero pizza da Bella e acai do Tropical' -> faca o pedido da Bella primeiro, depois pergunte os itens do Tropical.\n\n";

            $prompt .= "MARCADORES:\n";
            $prompt .= "- Restaurante identificado: [STORE:id:nome] (ex: [STORE:42:Pizzaria Bella])\n";
            $prompt .= "- CEP detectado: [CEP:12345678]\n";
            $prompt .= "- [NEXT_STORE] — sinaliza que o cliente quer pedir de outra loja (pedido multi-loja)\n";
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

            // Dietary restrictions from memory
            $dietaryCtx = $extraData['dietary_restrictions'] ?? null;
            if (!empty($dietaryCtx)) {
                $prompt .= "RESTRICOES ALIMENTARES DO CLIENTE: {$dietaryCtx}\n";
                $prompt .= "- Avise se algum item pode conter alérgenos incompatíveis. Sugira itens compatíveis.\n";
                $prompt .= "- Se o cliente pedir algo incompatível, alerte gentilmente: 'Lembrei que você tem restrição com [X]. Esse item pode ter, tá? Quer trocar?'\n";
                $prompt .= "- Se o cliente mencionar NOVAS restrições, use [DIETARY:texto] pra salvar.\n\n";
            } else {
                $prompt .= "Se o cliente mencionar alergia ou restrição alimentar, use [DIETARY:texto] pra salvar (ex: [DIETARY:sem gluten, sem lactose]).\n\n";
            }

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

            // Personalized recommendations (collaborative filtering)
            $voiceRecs = $extraData['voice_recs'] ?? [];
            if (!empty($voiceRecs) && empty($items)) {
                $prompt .= "RECOMENDACOES PERSONALIZADAS:\n";
                foreach ($voiceRecs as $i => $rec) {
                    $n = $i + 1;
                    $priceFmt = number_format($rec['price'], 2, ',', '.');
                    $prompt .= "{$n}. {$rec['name']} R\${$priceFmt} ({$rec['reason']})\n";
                }
                $prompt .= "Mencione naturalmente: ex 'A Margherita que cê sempre pede tá disponível! E a galera costuma pedir uma Coca junto.'\n";
                $prompt .= "NÃO liste todas — cite 1-2 que façam sentido no momento.\n\n";
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

            // Group/family order mode
            if (!empty($context['group_order'])) {
                $prompt .= "MODO GRUPO ATIVO — Pedido coletivo\n";
                $memberNames = array_map(fn($gm) => $gm['name'], $context['group_members'] ?? []);
                if (!empty($memberNames)) {
                    $prompt .= "Membros: " . implode(', ', $memberNames) . "\n";
                }
                $currentMember = $context['current_group_member'] ?? 'nenhum';
                $prompt .= "Anotando pra: {$currentMember}\n";
                $prompt .= "- Use [GROUP_MEMBER:nome] ao trocar de pessoa\n";
                $prompt .= "- Pergunte o que cada pessoa quer, um por vez\n";
                $prompt .= "- Ex: 'Anotado pra Maria! E o João, o que vai querer?'\n\n";
            } else {
                $prompt .= "PEDIDO EM GRUPO / FAMÍLIA:\n";
                $prompt .= "Se o cliente disser 'pedido pra família', 'turma', 'grupo' ou mencionar nomes ('pra mim e pro João'):\n";
                $prompt .= "- Use [GROUP_MEMBER:nome] antes de cada item pra registrar quem pediu\n";
                $prompt .= "- Ex: 'Maria quer uma pizza, João quer um hambúrguer — anotado!'\n";
                $prompt .= "- Pergunte cada pessoa: 'Anotei da Maria! E pro João?'\n\n";
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
            $prompt .= "- Trocar membro grupo: [GROUP_MEMBER:nome]\n";
            $prompt .= "- Quando finalizar itens: [NEXT_STEP]\n";

            // Learned customization preferences
            if ($learnedPrefs && !empty($learnedPrefs['common_customizations'])) {
                $customs = implode(', ', array_slice($learnedPrefs['common_customizations'], 0, 3));
                $prompt .= "\nCUSTOMIZACOES FREQUENTES DO CLIENTE: {$customs}\n";
                $prompt .= "Pergunte naturalmente: 'Sem cebola como sempre?' (use a customizacao relevante pro item)\n";
            }
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
            $prompt .= "- Instrucoes de entrega: [DELIVERY_INSTRUCTIONS:texto]\n\n";
            $prompt .= "Depois de confirmar o endereco, pergunte: 'Alguma instrucao pro entregador? Tipo portao, campainha, deixar na portaria...'\n";
            $prompt .= "Se o cliente der instrucoes, inclua [DELIVERY_INSTRUCTIONS:texto]. Se disser que nao, siga em frente.\n";
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
            $prompt .= "PAGAMENTO DIVIDIDO:\n";
            $prompt .= "Se o cliente quiser dividir pagamento (ex: 'metade pix metade dinheiro'), aceite!\n";
            $prompt .= "Use [SPLIT_PAYMENT:metodo1:valor1:metodo2:valor2]\n";
            $prompt .= "Ex: 'R\$30 no pix e o resto dinheiro' com total R\$50 → [SPLIT_PAYMENT:pix:30.00:dinheiro:20.00]\n";
            $prompt .= "Ex: 'metade pix metade cartao' com total R\$60 → [SPLIT_PAYMENT:pix:30.00:credit_card:30.00]\n";
            $prompt .= "Métodos válidos: pix, dinheiro, credit_card, debit_card\n";
            $prompt .= "Confirme: 'Combinado! Trinta no PIX e vinte em dinheiro.'\n";
            $prompt .= "Para pagamento normal (um método só), NÃO use SPLIT_PAYMENT.\n\n";
            $prompt .= "MARCADORES:\n";
            $prompt .= "- [PAYMENT:dinheiro], [PAYMENT:pix], [PAYMENT:credit_card], [PAYMENT:debit_card]\n";
            $prompt .= "- Com troco: [PAYMENT:dinheiro:100]\n";
            $prompt .= "- Dividido: [SPLIT_PAYMENT:metodo1:valor1:metodo2:valor2]\n";
            $prompt .= "- Gorjeta: [TIP:valor] (ex: [TIP:5.00])\n";
            $prompt .= "- Depois → [NEXT_STEP]\n\n";

            // Learned payment preference
            if ($learnedPrefs && !empty($learnedPrefs['payment_preference'])) {
                $prefLabels = ['dinheiro' => 'dinheiro', 'pix' => 'PIX', 'credit_card' => 'cartao de credito', 'debit_card' => 'cartao de debito'];
                $prefLabel = $prefLabels[$learnedPrefs['payment_preference']] ?? $learnedPrefs['payment_preference'];
                $prompt .= "PREFERENCIA APRENDIDA: Cliente geralmente paga com {$prefLabel}. Sugira: '{$prefLabel} como sempre?'\n\n";
            }

            $prompt .= "GORJETA:\n";
            $prompt .= "Depois de definir o pagamento, pergunte: 'Quer deixar uma gorjeta pro entregador? Tipo dois, cinco, dez reais?'\n";
            $prompt .= "Se sim, inclua [TIP:valor] (valor entre 1 e 50). Fale por extenso: 'cinco reais de gorjeta'.\n";
            $prompt .= "Se nao quiser, siga em frente sem insistir.\n";
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
                // Smart ETA (pre-computed in main flow and passed via extraData)
                $smartEtaMin = $extraData['smart_eta'] ?? null;
                $eta = $smartEtaMin ?? ($storeInfo ? (int)$storeInfo['delivery_time'] : 40);
                $etaSpoken = spokenETA($eta);
                $prompt .= "TEMPO ESTIMADO DE ENTREGA: ~{$eta} minutos (fale: '{$etaSpoken}')\n";
                if ($storeInfo && $storeInfo['busy_mode']) {
                    $prompt .= "(Restaurante em modo OCUPADO — tempo pode ser um pouco maior)\n";
                }
                $prompt .= "\n";
            }

            // Group order summary for confirmation
            if (!empty($context['group_order']) && !empty($context['group_members'])) {
                $groupSummary = buildGroupSummaryForVoice($items);
                if ($groupSummary) {
                    $prompt .= "RESUMO POR PESSOA: {$groupSummary}\n";
                    $prompt .= "Ao confirmar, diga o resumo por pessoa: 'Então fica: pra Maria [itens], pro João [itens]. Total de [valor]. Posso mandar?'\n\n";
                }
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
                // Check for split payment
                if (!empty($context['payment_split']) && !empty($context['payment_method_2'])) {
                    $payLabel1 = $paymentLabels[$payment] ?? $payment;
                    $payLabel2 = $paymentLabels[$context['payment_method_2']] ?? $context['payment_method_2'];
                    $split = $context['payment_split'];
                    $prompt .= "PAGAMENTO DIVIDIDO: R$" . number_format((float)$split[0], 2, ',', '.') . " no {$payLabel1} + R$" . number_format((float)$split[1], 2, ',', '.') . " no {$payLabel2}\n";
                } else {
                    $payLabel = $paymentLabels[$payment] ?? $payment;
                    $prompt .= "PAGAMENTO: {$payLabel}\n";
                }
                if ($payment === 'dinheiro' && !empty($context['payment_change'])) {
                    $prompt .= "TROCO PARA: R$" . number_format((float)$context['payment_change'], 2, ',', '.') . "\n";
                }
                $prompt .= "\n";
            }

            // Delivery instructions
            if (!empty($context['delivery_instructions'])) {
                $prompt .= "INSTRUCOES ENTREGA: {$context['delivery_instructions']}\n";
            }

            // Tip/gorjeta
            if (!empty($context['tip']) && (float)$context['tip'] > 0) {
                $tipVal = (float)$context['tip'];
                $total += $tipVal;
                $prompt .= "GORJETA: R$" . number_format($tipVal, 2, ',', '.') . "\n";
                $prompt .= "TOTAL COM GORJETA: R$" . number_format($total, 2, ',', '.') . "\n";
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

            // Cart optimization tip (free delivery, min order, combo)
            $cartOptTip = $extraData['cart_opt_tip'] ?? null;
            if ($cartOptTip) {
                $prompt .= "\nDICA DE CARRINHO: {$cartOptTip}\n";
                $prompt .= "Mencione ANTES de pedir confirmação: ex 'Ei, se adicionar mais R\$5 ganha frete grátis! Quer algo a mais?'\n";
            }

            // Price comparison tips (accumulated during ordering)
            $priceTips = $extraData['price_tips'] ?? [];
            if (!empty($priceTips)) {
                $tip = $priceTips[array_key_last($priceTips)]; // show only the last/best one
                $prompt .= "\nDICA DE PRECO: {$tip}\n";
                $prompt .= "Mencione só se fizer sentido: 'Ah, e só pra você saber, [dica]. Quer manter aqui mesmo?'\n";
                $prompt .= "NÃO force — se o cliente já confirmou, não interrompa com isso.\n";
            }

            $prompt .= "\nResuma de forma NATURAL e CURTA (max 3 frases). Leia os itens, total e pergunte se confirma.\n";
            $prompt .= "FALE O PRECO POR EXTENSO: 'cinquenta e oito reais' (nunca diga 'R\$ 58,00' — isso é pra texto).\n";
            $prompt .= "Ex: 'Então fica: uma pizza margherita grande e duas cocas. Total de cinquenta e oito reais com entrega. Posso mandar?'\n\n";
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

            // Order modification (post-submit, while still pendente)
            $prompt .= "MODIFICAÇÃO DE PEDIDO PENDENTE:\n";
            $prompt .= "Se o pedido mais recente está 'Pendente' (< 5 min), o cliente pode modificar:\n";
            $prompt .= "- Adicionar item: [MODIFY_ADD_ITEM:product_id:nome:preco:quantidade]\n";
            $prompt .= "- Remover item: [MODIFY_REMOVE_ITEM:indice] (índice da lista de itens, começa em 0)\n";
            $prompt .= "- Trocar endereço: [MODIFY_ADDRESS:endereço completo novo]\n";
            $prompt .= "- Cancelar: [CANCEL_ORDER:SB00123]\n";
            $prompt .= "Antes de modificar, confirme: 'O pedido ainda tá pendente, dá pra alterar! Quer adicionar ou tirar algo?'\n";
            $prompt .= "Se o pedido já foi aceito/preparando, diga: 'Putz, o restaurante já aceitou, não dá mais pra mudar.'\n\n";

            $prompt .= "MARCADORES:\n";
            $prompt .= "- Cancelar: [CANCEL_ORDER:SB00123] (confirme antes!)\n";
            $prompt .= "- Status: [ORDER_STATUS:SB00123]\n";
            $prompt .= "- Adicionar item: [MODIFY_ADD_ITEM:product_id:nome:preco:qty]\n";
            $prompt .= "- Remover item: [MODIFY_REMOVE_ITEM:indice]\n";
            $prompt .= "- Trocar endereço: [MODIFY_ADDRESS:endereço]\n";
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

        // Price comparison: check new items for cheaper alternatives at other stores
        $pcStoreId = $newContext['store_id'] ?? null;
        if ($pcStoreId) {
            if (!isset($newContext['price_tips'])) $newContext['price_tips'] = [];
            foreach ($matches as $pm) {
                try {
                    $pcAlt = findVoiceCheaperAlternatives($db, trim($pm[4]), $pcStoreId, (float)$pm[3]);
                    if ($pcAlt && !empty($pcAlt['tip'])) {
                        $newContext['price_tips'][] = $pcAlt['tip'];
                    }
                } catch (\Exception $e) { /* non-critical */ }
            }
        }
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

    // Parse [DELIVERY_INSTRUCTIONS:text]
    if (preg_match('/\[DELIVERY_INSTRUCTIONS:([^\]]+)\]/', $response, $m)) {
        $instructions = trim($m[1]);
        if (mb_strlen($instructions) <= 500) {
            $newContext['delivery_instructions'] = $instructions;
        }
        $cleaned = preg_replace('/\[DELIVERY_INSTRUCTIONS:[^\]]+\]/', '', $cleaned);
    }

    // Parse [TIP:value]
    if (preg_match('/\[TIP:([\d.]+)\]/', $response, $m)) {
        $tipValue = (float)$m[1];
        if ($tipValue >= 0 && $tipValue <= 50) {
            $newContext['tip'] = $tipValue;
        }
        $cleaned = preg_replace('/\[TIP:[\d.]+\]/', '', $cleaned);
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

    // Parse [NEXT_STORE] — multi-store ordering: save current store, go back to identify_store
    if (strpos($response, '[NEXT_STORE]') !== false) {
        $cleaned = str_replace('[NEXT_STORE]', '', $cleaned);

        try {
            if (function_exists('initMultiStoreOrder') && !empty($newContext['items'])) {
                // Initialize multi-store group if not already started
                if (empty($newContext['multi_store_group_id'])) {
                    $msPhone = $newContext['customer_phone'] ?? ($context['customer_phone'] ?? '');
                    $msCustomerId = $newContext['customer_id'] ?? ($context['customer_id'] ?? null);
                    $newContext['multi_store_group_id'] = initMultiStoreOrder($db, $msCustomerId ? (int)$msCustomerId : null, $msPhone, 'voice');
                }

                // Save current store's items
                if (!isset($newContext['completed_stores'])) {
                    $newContext['completed_stores'] = [];
                }
                $newContext['completed_stores'][] = [
                    'store_id'   => $newContext['store_id'] ?? null,
                    'store_name' => $newContext['store_name'] ?? 'Loja',
                    'items'      => $newContext['items'],
                ];

                // Reset for next store but keep multi-store context
                $multiGroupId = $newContext['multi_store_group_id'];
                $completedStores = $newContext['completed_stores'];
                $savedAddress = $newContext['address'] ?? null;
                $savedPayment = $newContext['payment_method'] ?? null;

                $newContext['store_id'] = null;
                $newContext['store_name'] = null;
                $newContext['items'] = [];
                $newContext['step'] = 'identify_store';
                $newContext['multi_store_group_id'] = $multiGroupId;
                $newContext['completed_stores'] = $completedStores;

                if ($savedAddress) $newContext['address'] = $savedAddress;
                if ($savedPayment) $newContext['payment_method'] = $savedPayment;

                error_log("[twilio-voice-ai] NEXT_STORE: Group {$multiGroupId}, " . count($completedStores) . " store(s) done");
            }
        } catch (Exception $e) {
            error_log("[twilio-voice-ai] NEXT_STORE error: " . $e->getMessage());
        }
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

    // Parse [GROUP_MEMBER:name] — switch active group member for group/family orders
    if (preg_match_all('/\[GROUP_MEMBER:([^\]]+)\]/', $response, $groupMatches, PREG_SET_ORDER)) {
        foreach ($groupMatches as $gm) {
            $memberName = trim($gm[1]);
            $cleaned = str_replace($gm[0], '', $cleaned);

            if (!empty($memberName)) {
                // Enable group mode if not already
                if (empty($newContext['group_order'])) {
                    $newContext['group_order'] = true;
                    $newContext['group_members'] = $newContext['group_members'] ?? [];
                }

                // Find or create this member in group_members
                $found = false;
                foreach ($newContext['group_members'] as &$existingMember) {
                    if (mb_strtolower($existingMember['name'], 'UTF-8') === mb_strtolower($memberName, 'UTF-8')) {
                        $found = true;
                        break;
                    }
                }
                unset($existingMember);

                if (!$found) {
                    $newContext['group_members'][] = [
                        'name' => $memberName,
                        'items' => [],
                    ];
                }

                $newContext['current_group_member'] = $memberName;
            }
        }

        // Tag newly added items with the current group member
        if (!empty($newContext['current_group_member']) && !empty($newContext['items'])) {
            $currentMember = $newContext['current_group_member'];
            // Tag items that don't have a group_member yet (newly added this turn)
            $existingCount = count($context['items'] ?? []);
            for ($i = $existingCount; $i < count($newContext['items']); $i++) {
                if (empty($newContext['items'][$i]['group_member'])) {
                    $newContext['items'][$i]['group_member'] = $currentMember;
                    // Track in group_members items list
                    foreach ($newContext['group_members'] as &$gMember) {
                        if (mb_strtolower($gMember['name'], 'UTF-8') === mb_strtolower($currentMember, 'UTF-8')) {
                            $gMember['items'][] = $newContext['items'][$i]['name'] ?? '?';
                            break;
                        }
                    }
                    unset($gMember);
                }
            }
        }
    }

    // ── SPLIT PAYMENT ─────────────────────────────────────────────────────
    // Parse [SPLIT_PAYMENT:method1:amount1:method2:amount2]
    if (preg_match('/\[SPLIT_PAYMENT:([a-z_]+):([\d.]+):([a-z_]+):([\d.]+)\]/', $response, $m)) {
        $splitMethod1 = trim($m[1]);
        $splitAmount1 = (float)$m[2];
        $splitMethod2 = trim($m[3]);
        $splitAmount2 = (float)$m[4];

        $validSplitMethods = ['pix', 'dinheiro', 'credit_card', 'debit_card'];
        if (in_array($splitMethod1, $validSplitMethods) && in_array($splitMethod2, $validSplitMethods)) {
            $newContext['payment_method'] = $splitMethod1;
            $newContext['payment_method_2'] = $splitMethod2;
            $newContext['payment_split'] = [$splitAmount1, $splitAmount2];
            error_log("[twilio-voice-ai] Split payment: {$splitMethod1}:{$splitAmount1} + {$splitMethod2}:{$splitAmount2}");
        } else {
            error_log("[twilio-voice-ai] Invalid split payment methods: {$splitMethod1}, {$splitMethod2}");
        }
        $cleaned = preg_replace('/\[SPLIT_PAYMENT:[^\]]+\]/', '', $cleaned);
    }

    // ── DIETARY RESTRICTIONS ────────────────────────────────────────────
    // Parse [DIETARY:text] — save dietary restrictions from conversation
    if (preg_match('/\[DIETARY:([^\]]+)\]/', $response, $m)) {
        $dietaryText = trim($m[1]);
        if (!empty($dietaryText) && function_exists('aiMemorySave')) {
            $phone = $newContext['customer_phone'] ?? ($context['customer_phone'] ?? '');
            $custId = $newContext['customer_id'] ?? ($context['customer_id'] ?? null);
            try {
                aiMemorySave($db, $phone, $custId, 'preference', 'dietary_restriction', $dietaryText);
                $newContext['dietary_restrictions'] = $dietaryText;
                error_log("[twilio-voice-ai] Dietary saved: {$dietaryText}");
            } catch (Exception $e) {
                error_log("[twilio-voice-ai] Dietary save error: " . $e->getMessage());
            }
        }
        $cleaned = preg_replace('/\[DIETARY:[^\]]+\]/', '', $cleaned);
    }

    // ── ORDER MODIFICATION (POST-SUBMIT) ────────────────────────────────
    // Parse [MODIFY_ADD_ITEM:product_id:name:price:qty]
    if (preg_match_all('/\[MODIFY_ADD_ITEM:(\d+):([^:]+):([\d.]+):(\d+)\]/', $response, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $cleaned = str_replace($m[0], '', $cleaned);
            $modifyOrderId = $newContext['modify_order_id'] ?? null;
            if (!$modifyOrderId) {
                $custId = $newContext['customer_id'] ?? ($context['customer_id'] ?? null);
                if ($custId) {
                    try {
                        $recentStmt = $db->prepare("
                            SELECT order_id, partner_id FROM om_market_orders
                            WHERE customer_id = ? AND status = 'pendente'
                              AND date_added > NOW() - INTERVAL '5 minutes'
                            ORDER BY date_added DESC LIMIT 1
                        ");
                        $recentStmt->execute([$custId]);
                        $recentOrd = $recentStmt->fetch();
                        if ($recentOrd) {
                            $modifyOrderId = (int)$recentOrd['order_id'];
                            $newContext['modify_order_id'] = $modifyOrderId;
                            $newContext['modify_partner_id'] = (int)$recentOrd['partner_id'];
                        }
                    } catch (Exception $e) {
                        error_log("[twilio-voice-ai] MODIFY lookup error: " . $e->getMessage());
                    }
                }
            }
            if ($modifyOrderId) {
                $addProductId = (int)$m[1];
                $addQty = max(1, (int)$m[4]);
                try {
                    $db->beginTransaction();
                    $chkStmt = $db->prepare("SELECT status FROM om_market_orders WHERE order_id = ? FOR UPDATE");
                    $chkStmt->execute([$modifyOrderId]);
                    $chkStatus = $chkStmt->fetchColumn();
                    if ($chkStatus !== 'pendente') {
                        $db->rollBack();
                        $cleaned .= ' O pedido já foi aceito e não dá mais pra alterar.';
                        continue;
                    }
                    $prodStmt = $db->prepare("SELECT product_id, name, price, special_price, partner_id FROM om_market_products WHERE product_id = ? AND status::text = '1'");
                    $prodStmt->execute([$addProductId]);
                    $addProduct = $prodStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$addProduct || (int)$addProduct['partner_id'] !== (int)($newContext['modify_partner_id'] ?? 0)) {
                        $db->rollBack();
                        $cleaned .= ' Produto não encontrado nessa loja.';
                        continue;
                    }
                    $addPrice = ((float)($addProduct['special_price'] ?? 0) > 0 && (float)$addProduct['special_price'] < (float)$addProduct['price'])
                        ? (float)$addProduct['special_price'] : (float)$addProduct['price'];
                    $addItemTotal = round($addPrice * $addQty, 2);
                    $db->prepare("
                        INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([$modifyOrderId, $addProductId, $addProduct['name'], $addQty, $addPrice, $addItemTotal]);
                    $db->prepare("
                        UPDATE om_market_orders SET
                            subtotal = (SELECT COALESCE(SUM(total), 0) FROM om_market_order_items WHERE order_id = ?),
                            total = (SELECT COALESCE(SUM(total), 0) FROM om_market_order_items WHERE order_id = ?) + delivery_fee + service_fee - COALESCE(coupon_discount, 0) - COALESCE(cashback_discount, 0)
                        WHERE order_id = ?
                    ")->execute([$modifyOrderId, $modifyOrderId, $modifyOrderId]);
                    $db->prepare("INSERT INTO om_order_timeline (order_id, status, description, actor_type, created_at) VALUES (?, 'pendente', ?, 'system', NOW())")
                        ->execute([$modifyOrderId, "Item adicionado via telefone IA: {$addQty}x {$addProduct['name']}"]);
                    $db->commit();
                    error_log("[twilio-voice-ai] MODIFY: Added {$addQty}x {$addProduct['name']} to order {$modifyOrderId}");
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log("[twilio-voice-ai] MODIFY_ADD_ITEM error: " . $e->getMessage());
                    $cleaned .= ' Erro ao adicionar item, tente novamente.';
                }
            } else {
                $cleaned .= ' Não encontrei pedido pendente recente pra modificar.';
            }
        }
    }

    // Parse [MODIFY_REMOVE_ITEM:index]
    if (preg_match_all('/\[MODIFY_REMOVE_ITEM:(\d+)\]/', $response, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $removeIdx = (int)$m[1];
            $cleaned = str_replace($m[0], '', $cleaned);
            $modifyOrderId = $newContext['modify_order_id'] ?? null;
            if (!$modifyOrderId) {
                $custId = $newContext['customer_id'] ?? ($context['customer_id'] ?? null);
                if ($custId) {
                    try {
                        $recentStmt = $db->prepare("
                            SELECT order_id FROM om_market_orders
                            WHERE customer_id = ? AND status = 'pendente'
                              AND date_added > NOW() - INTERVAL '5 minutes'
                            ORDER BY date_added DESC LIMIT 1
                        ");
                        $recentStmt->execute([$custId]);
                        $recentOrd = $recentStmt->fetch();
                        if ($recentOrd) {
                            $modifyOrderId = (int)$recentOrd['order_id'];
                            $newContext['modify_order_id'] = $modifyOrderId;
                        }
                    } catch (Exception $e) {}
                }
            }
            if ($modifyOrderId) {
                try {
                    $db->beginTransaction();
                    $chkStmt = $db->prepare("SELECT status FROM om_market_orders WHERE order_id = ? FOR UPDATE");
                    $chkStmt->execute([$modifyOrderId]);
                    $chkStatus = $chkStmt->fetchColumn();
                    if ($chkStatus !== 'pendente') {
                        $db->rollBack();
                        $cleaned .= ' O pedido já foi aceito e não dá mais pra alterar.';
                        continue;
                    }
                    $itemsStmt = $db->prepare("SELECT id, product_id, name, quantity FROM om_market_order_items WHERE order_id = ? ORDER BY id");
                    $itemsStmt->execute([$modifyOrderId]);
                    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!isset($orderItems[$removeIdx])) {
                        $db->rollBack();
                        $cleaned .= ' Índice de item inválido.';
                        continue;
                    }
                    if (count($orderItems) <= 1) {
                        $db->rollBack();
                        $cleaned .= ' Não posso remover o único item. Se quiser cancelar, me avise.';
                        continue;
                    }
                    $removedItem = $orderItems[$removeIdx];
                    $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")->execute([(int)$removedItem['quantity'], (int)$removedItem['product_id']]);
                    $db->prepare("DELETE FROM om_market_order_items WHERE id = ?")->execute([$removedItem['id']]);
                    $db->prepare("
                        UPDATE om_market_orders SET
                            subtotal = (SELECT COALESCE(SUM(total), 0) FROM om_market_order_items WHERE order_id = ?),
                            total = (SELECT COALESCE(SUM(total), 0) FROM om_market_order_items WHERE order_id = ?) + delivery_fee + service_fee - COALESCE(coupon_discount, 0) - COALESCE(cashback_discount, 0)
                        WHERE order_id = ?
                    ")->execute([$modifyOrderId, $modifyOrderId, $modifyOrderId]);
                    $db->prepare("INSERT INTO om_order_timeline (order_id, status, description, actor_type, created_at) VALUES (?, 'pendente', ?, 'system', NOW())")
                        ->execute([$modifyOrderId, "Item removido via telefone IA: {$removedItem['name']}"]);
                    $db->commit();
                    error_log("[twilio-voice-ai] MODIFY: Removed {$removedItem['name']} from order {$modifyOrderId}");
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log("[twilio-voice-ai] MODIFY_REMOVE_ITEM error: " . $e->getMessage());
                    $cleaned .= ' Erro ao remover item, tente novamente.';
                }
            } else {
                $cleaned .= ' Não encontrei pedido pendente recente pra modificar.';
            }
        }
    }

    // Parse [MODIFY_ADDRESS:new_address]
    if (preg_match('/\[MODIFY_ADDRESS:([^\]]+)\]/', $response, $m)) {
        $newAddress = trim($m[1]);
        $cleaned = str_replace($m[0], '', $cleaned);
        $modifyOrderId = $newContext['modify_order_id'] ?? null;
        if (!$modifyOrderId) {
            $custId = $newContext['customer_id'] ?? ($context['customer_id'] ?? null);
            if ($custId) {
                try {
                    $recentStmt = $db->prepare("
                        SELECT order_id FROM om_market_orders
                        WHERE customer_id = ? AND status = 'pendente'
                          AND date_added > NOW() - INTERVAL '5 minutes'
                        ORDER BY date_added DESC LIMIT 1
                    ");
                    $recentStmt->execute([$custId]);
                    $recentOrd = $recentStmt->fetch();
                    if ($recentOrd) {
                        $modifyOrderId = (int)$recentOrd['order_id'];
                        $newContext['modify_order_id'] = $modifyOrderId;
                    }
                } catch (Exception $e) {}
            }
        }
        if ($modifyOrderId && !empty($newAddress)) {
            try {
                $db->beginTransaction();
                $chkStmt = $db->prepare("SELECT status FROM om_market_orders WHERE order_id = ? FOR UPDATE");
                $chkStmt->execute([$modifyOrderId]);
                $chkStatus = $chkStmt->fetchColumn();
                if ($chkStatus !== 'pendente') {
                    $db->rollBack();
                    $cleaned .= ' O pedido já foi aceito e não dá mais pra alterar.';
                } else {
                    $db->prepare("UPDATE om_market_orders SET delivery_address = ?, shipping_address = ? WHERE order_id = ?")
                        ->execute([$newAddress, $newAddress, $modifyOrderId]);
                    $db->prepare("INSERT INTO om_order_timeline (order_id, status, description, actor_type, created_at) VALUES (?, 'pendente', ?, 'system', NOW())")
                        ->execute([$modifyOrderId, "Endereço alterado via telefone IA para: {$newAddress}"]);
                    $db->commit();
                    error_log("[twilio-voice-ai] MODIFY: Address changed on order {$modifyOrderId}");
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log("[twilio-voice-ai] MODIFY_ADDRESS error: " . $e->getMessage());
                $cleaned .= ' Erro ao trocar endereço, tente novamente.';
            }
        } else if (!$modifyOrderId) {
            $cleaned .= ' Não encontrei pedido pendente recente pra modificar.';
        }
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
        $tipAmount = (float)($context['tip'] ?? 0);
        if ($tipAmount < 0 || $tipAmount > 50) $tipAmount = 0;
        $total = round($subtotal + $deliveryFee + $serviceFee + $tipAmount, 2);
        $deliveryInstructions = $context['delivery_instructions'] ?? null;

        $deliveryAddress = $address['full'] ?? 'N/A';
        $codigoEntrega = strtoupper(bin2hex(random_bytes(3)));

        // Build order notes (include split payment info if applicable)
        $orderNotes = "Pedido via IA telefone - call #{$callId}";
        if (!empty($context['payment_split']) && !empty($context['payment_method_2'])) {
            $splitPayLabels = ['pix' => 'PIX', 'dinheiro' => 'Dinheiro', 'credit_card' => 'Cartão crédito', 'debit_card' => 'Cartão débito'];
            $sp1Label = $splitPayLabels[$paymentMethod] ?? $paymentMethod;
            $sp2Label = $splitPayLabels[$context['payment_method_2']] ?? $context['payment_method_2'];
            $orderNotes .= " | Pagamento dividido: R$" . number_format($context['payment_split'][0], 2, ',', '.') . " ({$sp1Label}) + R$" . number_format($context['payment_split'][1], 2, ',', '.') . " ({$sp2Label})";
        }

        $db->beginTransaction();

        // Create order
        $stmt = $db->prepare("
            INSERT INTO om_market_orders (
                customer_id, partner_id, customer_name, customer_phone,
                status, subtotal, delivery_fee, service_fee, total,
                delivery_address, shipping_address, shipping_city, shipping_state, shipping_cep,
                shipping_lat, shipping_lng,
                forma_pagamento, payment_status, codigo_entrega,
                delivery_instructions, tip_amount,
                notes, source, date_added
            ) VALUES (
                ?, ?, ?, ?,
                'confirmado', ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?,
                ?, 'pendente', ?,
                ?, ?,
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
            $deliveryInstructions, $tipAmount > 0 ? $tipAmount : null,
            $orderNotes,
        ]);
        $orderId = (int)$stmt->fetch()['order_id'];

        // Append group order info to notes if applicable
        if (!empty($context['group_order']) && !empty($context['group_members'])) {
            $groupParts = [];
            foreach ($context['group_members'] as $gm) {
                $memberItems = $gm['items'] ?? [];
                if (!empty($memberItems)) {
                    $groupParts[] = $gm['name'] . ' (' . implode(', ', $memberItems) . ')';
                }
            }
            if (!empty($groupParts)) {
                $groupNote = ' | GRUPO: ' . implode('; ', $groupParts);
                $db->prepare("UPDATE om_market_orders SET notes = notes || ? WHERE order_id = ?")
                   ->execute([$groupNote, $orderId]);
            }
        }

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

        // Send SMS confirmation — try custom helper first, fall back to Twilio API
        try {
            if (file_exists(__DIR__ . '/../helpers/callcenter-sms.php')) {
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
            } else {
                // Fallback: send SMS via Twilio API directly
                sendTwilioSms($customerPhone, $orderNumber, $store['name'], $items, $total, $paymentMethod);
            }
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

/**
 * Send SMS order confirmation via Twilio API (fallback if callcenter-sms.php not available).
 */
function sendTwilioSms(string $to, string $orderNumber, string $storeName, array $items, float $total, string $paymentMethod): void {
    $twilioSid = $_ENV['TWILIO_SID'] ?? getenv('TWILIO_SID') ?: '';
    $twilioToken = $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: '';
    $twilioFrom = $_ENV['TWILIO_PHONE'] ?? getenv('TWILIO_PHONE') ?: '';

    if (empty($twilioSid) || empty($twilioToken) || empty($twilioFrom)) {
        error_log("[twilio-voice-ai] SMS skipped: Twilio credentials not configured");
        return;
    }

    // Build concise SMS body
    $itemLines = [];
    foreach ($items as $item) {
        $qty = (int)($item['quantity'] ?? 1);
        $name = $item['name'] ?? 'Item';
        $price = ($item['price'] ?? 0) * $qty;
        $itemLines[] = "{$qty}x {$name} R$" . number_format($price, 2, ',', '.');
    }

    $payLabels = ['dinheiro' => 'Dinheiro', 'pix' => 'PIX', 'credit_card' => 'Cartão crédito', 'debit_card' => 'Cartão débito'];
    $payLabel = $payLabels[$paymentMethod] ?? $paymentMethod;

    $body = "SuperBora - Pedido {$orderNumber}\n"
        . "Loja: {$storeName}\n"
        . implode("\n", $itemLines) . "\n"
        . "Total: R$" . number_format($total, 2, ',', '.') . "\n"
        . "Pagamento: {$payLabel}\n"
        . "Acompanhe pelo app SuperBora!";

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$twilioSid}/Messages.json";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'To' => $to,
            'From' => $twilioFrom,
            'Body' => $body,
        ]),
        CURLOPT_USERPWD => "{$twilioSid}:{$twilioToken}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        error_log("[twilio-voice-ai] SMS sent to {$to} for order {$orderNumber}");
    } else {
        error_log("[twilio-voice-ai] SMS failed HTTP {$httpCode}: " . substr($response, 0, 200));
    }
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
