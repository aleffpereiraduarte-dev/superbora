<?php
/**
 * POST /api/mercado/webhooks/twilio-voice-outbound.php
 *
 * Outbound AI Call Handler — Claude-driven multi-turn voice conversations.
 *
 * When the admin API initiates a Twilio call via the helpers/outbound-calls.php
 * helper, Twilio connects to the customer and POSTs to this webhook. This file
 * drives a multi-turn conversation based on the call type passed via query param.
 *
 * Call types (?call_type=X):
 *   - order_confirmation  : Confirm order details after placement
 *   - delivery_update     : Notify about delivery status change
 *   - reengagement        : Win back inactive customers (30+ days)
 *   - survey              : Post-delivery satisfaction survey (1-5)
 *   - promo               : Announce a promotion or special offer
 *
 * State is tracked in om_callcenter_calls.notes JSONB (same pattern as
 * twilio-voice-ai.php). Query params carry initial context from the initiator.
 *
 * Also handles ?status_callback=1 for Twilio status updates on these calls.
 */

// ── Load Env ─────────────────────────────────────────────────────────────────
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
require_once __DIR__ . '/../helpers/voice-tts.php';
if (file_exists(__DIR__ . '/../helpers/ws-callcenter-broadcast.php')) {
    require_once __DIR__ . '/../helpers/ws-callcenter-broadcast.php';
}
if (file_exists(__DIR__ . '/../helpers/ai-memory.php')) {
    require_once __DIR__ . '/../helpers/ai-memory.php';
}
if (file_exists(__DIR__ . '/../helpers/outbound-calls.php')) {
    require_once __DIR__ . '/../helpers/outbound-calls.php';
}

// ── Status Callback Handler (reused URL with ?status_callback=1) ─────────────
if (!empty($_GET['status_callback']) || !empty($_POST['status_callback'])) {
    handleStatusCallback();
    exit;
}

header('Content-Type: text/xml; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Method not allowed</Say></Response>';
    exit;
}

// ── Validate Twilio Signature ────────────────────────────────────────────────
$authToken = $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: '';
$twilioSignature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

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
        error_log("[twilio-outbound] Signature mismatch — allowing (proxy may alter URL)");
    }
} elseif (empty($twilioSignature) && !isset($_POST['CallSid'])) {
    error_log("[twilio-outbound] Rejected: no signature and no CallSid");
    http_response_code(403);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Unauthorized</Say></Response>';
    exit;
}

// ── Parse Input ──────────────────────────────────────────────────────────────
$callSid      = $_POST['CallSid'] ?? '';
$speechResult = $_POST['SpeechResult'] ?? '';
$digits       = $_POST['Digits'] ?? '';
$calledPhone  = $_POST['To'] ?? '';
$callerPhone  = $_POST['From'] ?? '';
$callStatus   = $_POST['CallStatus'] ?? '';
$answeredBy   = $_POST['AnsweredBy'] ?? '';

// Query params from the initiating call (helpers/outbound-calls.php)
$callType      = $_GET['call_type'] ?? $_POST['call_type'] ?? 'promo';
$callDataB64   = $_GET['call_data_b64'] ?? $_POST['call_data_b64'] ?? '';
$qCustomerName = $_GET['customer_name'] ?? $_POST['customer_name'] ?? '';
$qCustomerId   = $_GET['customer_id'] ?? $_POST['customer_id'] ?? '';
$qCustomerId   = $qCustomerId !== '' ? (int)$qCustomerId : null;

// Decode call data payload
$callData = [];
if (!empty($callDataB64)) {
    $decoded = base64_decode($callDataB64);
    if ($decoded) {
        $callData = json_decode($decoded, true) ?: [];
    }
}

// Build self URL preserving query params for Gather action loops
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$selfPath = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$selfParams = http_build_query([
    'call_type'     => $callType,
    'call_data_b64' => $callDataB64,
    'customer_name' => $qCustomerName,
    'customer_id'   => $qCustomerId ?? '',
]);
$selfUrl = $scheme . '://' . $host . $selfPath . '?' . $selfParams;

// AI handler URL for redirect when customer wants to order
$aiHandlerUrl = $scheme . '://' . $host . str_replace('twilio-voice-outbound.php', 'twilio-voice-ai.php', $selfPath);

$userInput = $speechResult ?: ($digits ?: '');

error_log("[twilio-outbound] CallSid={$callSid} Type={$callType} Speech=\"{$speechResult}\" Digits={$digits} AnsweredBy={$answeredBy}");

try {
    $db = getDB();

    // ── Voicemail / Machine Detection ────────────────────────────────────────
    if (in_array($answeredBy, ['machine_start', 'machine_end_beep', 'machine_end_other', 'machine_end_silence', 'fax'], true)) {
        error_log("[twilio-outbound] Machine detected ({$answeredBy}) for CallSid={$callSid}");
        if (function_exists('updateOutboundCallOutcome')) {
            updateOutboundCallOutcome($db, $callSid, 'voicemail', 'voicemail');
        }

        if ($answeredBy === 'machine_end_beep') {
            // Leave a brief voicemail after the beep
            $vmMsg = buildVoicemailMessage($callType, $qCustomerName, $callData);
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo ttsWithEmotion($vmMsg, 'happy');
            echo '<Hangup/>';
            echo '</Response>';
        } else {
            echo '<?xml version="1.0" encoding="UTF-8"?><Response><Hangup/></Response>';
        }
        exit;
    }

    // ── Load or create call record ───────────────────────────────────────────
    $stmt = $db->prepare("
        SELECT id, customer_id, customer_name, customer_phone, notes
        FROM om_callcenter_calls
        WHERE twilio_call_sid = ?
        LIMIT 1
    ");
    $stmt->execute([$callSid]);
    $call = $stmt->fetch();

    if (!$call) {
        error_log("[twilio-outbound] No call record for CallSid={$callSid} — creating one");
        try {
            $db->prepare("
                INSERT INTO om_callcenter_calls
                    (twilio_call_sid, customer_phone, customer_id, customer_name, direction, status, started_at)
                VALUES (?, ?, ?, ?, 'outbound', 'ai_handling', NOW())
                ON CONFLICT (twilio_call_sid) DO NOTHING
            ")->execute([$callSid, $calledPhone, $qCustomerId, $qCustomerName ?: null]);

            $stmt->execute([$callSid]);
            $call = $stmt->fetch();
        } catch (Exception $e) {
            error_log("[twilio-outbound] Failed to create call record: " . $e->getMessage());
        }
    }

    if (!$call) {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo ttsSayOrPlay("Oi! Aqui é a Bora, do SuperBora. Desculpe o transtorno. Tchau!");
        echo '<Hangup/>';
        echo '</Response>';
        exit;
    }

    $callId        = (int)$call['id'];
    $customerId    = $call['customer_id'] ? (int)$call['customer_id'] : $qCustomerId;
    $customerName  = $call['customer_name'] ?: $qCustomerName ?: null;
    $customerPhone = $call['customer_phone'] ?: $calledPhone;

    // ── Load AI context ──────────────────────────────────────────────────────
    $aiContext = loadOutboundContext($db, $callId);
    $conversationHistory = $aiContext['history'] ?? [];
    $turn = $aiContext['turn'] ?? 0;
    $scriptDelivered = $aiContext['script_delivered'] ?? false;

    // Persist call type and data in context on first hit
    if (empty($aiContext['call_type'])) {
        $aiContext['call_type'] = $callType;
        $aiContext['call_data'] = $callData;
    } else {
        $callType = $aiContext['call_type'];
        $callData = $aiContext['call_data'] ?? [];
    }

    // ── Handle silence (Gather timeout) ──────────────────────────────────────
    if (empty($userInput) && $scriptDelivered) {
        $silenceCount = ($aiContext['silence_count'] ?? 0) + 1;
        $aiContext['silence_count'] = $silenceCount;
        saveOutboundContext($db, $callId, $aiContext);

        if ($silenceCount >= 2) {
            if (function_exists('updateOutboundCallOutcome')) {
                updateOutboundCallOutcome($db, $callSid, 'completed', 'no_interaction');
            }
            $db->prepare("UPDATE om_callcenter_calls SET status = 'completed', ended_at = NOW() WHERE id = ?")
               ->execute([$callId]);

            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo ttsWithEmotion("Parece que voce ta ocupado. Se precisar, é só abrir o app! Tchau!", 'neutral');
            echo '<Hangup/>';
            echo '</Response>';
            exit;
        }

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
        echo ttsWithEmotion("Oi, ta ouvindo? Pode falar que eu to aqui!", 'neutral');
        echo '</Gather>';
        echo ttsWithEmotion("Tudo bem, fica pra proxima. Tchau!", 'neutral');
        echo '<Hangup/>';
        echo '</Response>';
        exit;
    }

    // Reset silence counter on input
    if (!empty($userInput)) {
        $aiContext['silence_count'] = 0;
    }

    // ── First contact: deliver scripted greeting ─────────────────────────────
    if (!$scriptDelivered) {
        if (function_exists('updateOutboundCallOutcome')) {
            updateOutboundCallOutcome($db, $callSid, 'answered');
        }

        $aiContext['script_delivered'] = true;
        $aiContext['turn'] = 0;

        $script = buildCallScript($callType, $customerName, $callData, $db);

        // Store greeting in conversation history for Claude context
        $conversationHistory[] = ['role' => 'assistant', 'content' => $script['message'] . (!empty($script['follow_up']) ? ' ' . $script['follow_up'] : '')];
        $aiContext['history'] = $conversationHistory;
        saveOutboundContext($db, $callId, $aiContext);

        // Broadcast to dashboard
        if (function_exists('ccBroadcastDashboard')) {
            ccBroadcastDashboard('outbound_call_connected', [
                'call_id'       => $callId,
                'call_type'     => $callType,
                'customer_name' => $customerName,
                'phone'         => substr($calledPhone, 0, 6) . '***',
            ]);
        }

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';

        if ($callType === 'survey') {
            // Survey: accept DTMF (number keys) and speech
            echo '<Gather input="dtmf speech" timeout="10" numDigits="1" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
            echo ttsWithEmotion($script['message'], $script['emotion']);
            echo '</Gather>';
            echo ttsWithEmotion("Tudo bem! Se quiser avaliar depois, pode fazer pelo app. Tchau!", 'neutral');
            echo '<Hangup/>';
        } else {
            echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
            echo ttsWithEmotion($script['message'], $script['emotion']);
            if (!empty($script['follow_up'])) {
                echo ttsWithEmotion($script['follow_up'], 'neutral');
            }
            echo '</Gather>';
            echo ttsWithEmotion("Oi, ta ai?", 'neutral');
            echo '<Redirect method="POST">' . escXml($selfUrl) . '</Redirect>';
        }

        echo '</Response>';
        exit;
    }

    // ── Process user input — multi-turn conversation ─────────────────────────
    $lowerInput = mb_strtolower(trim($userInput), 'UTF-8');

    // -- Opt-out detection (always checked first) --
    $optOutPhrases = [
        'nao quero', 'para de ligar', 'nao me liga', 'nao liga mais',
        'remove meu numero', 'tira meu numero', 'nao quero receber',
        'sai da lista', 'descadastrar', 'nao tenho interesse',
        'chega', 'para com isso', 'para por favor',
    ];
    foreach ($optOutPhrases as $phrase) {
        if (mb_strpos($lowerInput, $phrase) !== false) {
            if (function_exists('recordOptOut')) {
                recordOptOut($db, $customerPhone, $customerId, 'Solicitado durante ligacao outbound: ' . $callType);
            }
            if (function_exists('updateOutboundCallOutcome')) {
                updateOutboundCallOutcome($db, $callSid, 'opt_out', 'opt_out', ['user_said' => $userInput]);
            }
            $db->prepare("UPDATE om_callcenter_calls SET status = 'completed', ended_at = NOW() WHERE id = ?")
               ->execute([$callId]);

            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo ttsWithEmotion("Desculpa pelo incomodo! Ja tirei seu numero da lista, nao vou ligar mais. Se mudar de ideia, é só acessar o app. Tchau!", 'empathetic');
            echo '<Hangup/>';
            echo '</Response>';
            exit;
        }
    }

    // -- Survey DTMF shortcut (1-5 rating via keypad) --
    if ($callType === 'survey' && !empty($digits) && in_array($digits, ['1', '2', '3', '4', '5'], true)) {
        $rating = (int)$digits;
        handleSurveyRating($db, $callSid, $callId, $customerId, $rating, $callData);
        exit;
    }

    // -- "Want to order" detection for reengagement/promo --
    $orderPhrases = [
        'quero pedir', 'quero sim', 'quero', 'pode ser', 'bora', 'vamos',
        'manda', 'me ajuda a pedir', 'quero fazer um pedido', 'quero aproveitar',
        'sim', 'isso', 'claro', 'com certeza', 'beleza', 'ta bom',
        'aceito', 'gostei', 'manda ver', 'pode pedir', 'faz o pedido',
    ];
    if (in_array($callType, ['reengagement', 'promo', 'custom'], true)) {
        $wantsOrder = false;
        foreach ($orderPhrases as $phrase) {
            if (mb_strpos($lowerInput, $phrase) !== false || trim($lowerInput) === $phrase) {
                $wantsOrder = true;
                break;
            }
        }
        if ($digits === '1') {
            $wantsOrder = true;
        }

        if ($wantsOrder) {
            if (function_exists('updateOutboundCallOutcome')) {
                updateOutboundCallOutcome($db, $callSid, 'answered', 'ordered', ['user_said' => $userInput]);
            }

            // Ensure callcenter record is ready for the AI handler
            try {
                $db->prepare("
                    UPDATE om_callcenter_calls SET status = 'ai_handling' WHERE id = ?
                ")->execute([$callId]);
            } catch (Exception $e) {}

            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo ttsWithEmotion("Boa! Vou te passar pro nosso atendimento pra montar seu pedido.", 'excited');
            echo '<Redirect method="POST">' . escXml($aiHandlerUrl) . '</Redirect>';
            echo '</Response>';
            exit;
        }
    }

    // -- Quick decline detection --
    $declinePhrases = [
        'nao', 'agora nao', 'depois', 'to ocupado', 'to ocupada',
        'nao posso', 'mais tarde', 'outro dia', 'deixa pra la',
    ];
    $isDecline = false;
    foreach ($declinePhrases as $phrase) {
        if (trim($lowerInput) === $phrase) {
            $isDecline = true;
            break;
        }
    }
    if ($digits === '2') {
        $isDecline = true;
    }

    // For simple decline on non-conversational types, end immediately
    if ($isDecline && in_array($callType, ['promo', 'reengagement'], true) && $turn === 0) {
        if (function_exists('updateOutboundCallOutcome')) {
            updateOutboundCallOutcome($db, $callSid, 'completed', 'declined', ['user_said' => $userInput]);
        }
        $db->prepare("UPDATE om_callcenter_calls SET status = 'completed', ended_at = NOW() WHERE id = ?")
           ->execute([$callId]);

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo ttsWithEmotion("Tranquilo! Quando quiser pedir, é só abrir o app ou ligar pra gente. Tchau!", 'neutral');
        echo '<Hangup/>';
        echo '</Response>';
        exit;
    }

    // -- Order confirmation: direct yes/no handling --
    if ($callType === 'order_confirmation' || $callType === 'order_confirm') {
        $yesPhrases = ['sim', 'isso', 'ta certo', 'certinho', 'pode confirmar', 'confirma', 'ta bom', 'perfeito', 'certo'];
        $isYes = false;
        foreach ($yesPhrases as $yp) {
            if (mb_strpos($lowerInput, $yp) !== false || trim($lowerInput) === $yp) {
                $isYes = true;
                break;
            }
        }

        if ($isYes && $turn === 0) {
            $orderId = $callData['order_id'] ?? null;
            if ($orderId) {
                try {
                    $db->prepare("
                        UPDATE om_market_orders
                        SET notes = COALESCE(notes, '') || ' [Confirmado por telefone ' || NOW()::text || ']'
                        WHERE order_id = ?
                    ")->execute([(int)$orderId]);
                } catch (Exception $e) {
                    error_log("[twilio-outbound] Failed to confirm order {$orderId}: " . $e->getMessage());
                }
            }
            if (function_exists('updateOutboundCallOutcome')) {
                updateOutboundCallOutcome($db, $callSid, 'completed', 'confirmed', ['order_id' => $orderId]);
            }
            if (function_exists('ccBroadcastDashboard')) {
                ccBroadcastDashboard('outbound_order_confirmed', [
                    'call_id' => $callId, 'order_id' => $orderId, 'customer_name' => $customerName,
                ]);
            }
            $db->prepare("UPDATE om_callcenter_calls SET status = 'completed', ended_at = NOW() WHERE id = ?")
               ->execute([$callId]);

            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo ttsWithEmotion("Perfeito, seu pedido ta confirmado! Bom apetite! Tchau!", 'happy');
            echo '<Hangup/>';
            echo '</Response>';
            exit;
        }
    }

    // -- Survey: parse speech as rating --
    if ($callType === 'survey') {
        $rating = parseSurveyRating($userInput);
        if ($rating !== null) {
            handleSurveyRating($db, $callSid, $callId, $customerId, $rating, $callData);
            exit;
        }
    }

    // ── Claude-driven multi-turn conversation ────────────────────────────────
    $conversationHistory[] = ['role' => 'user', 'content' => $userInput];
    $aiContext['turn'] = $turn + 1;

    // Max turns before wrap-up
    $maxTurns = in_array($callType, ['survey', 'delivery_update', 'promo'], true) ? 4 : 6;
    if ($turn + 1 >= $maxTurns) {
        saveOutboundContext($db, $callId, array_merge($aiContext, ['history' => $conversationHistory]));
        if (function_exists('updateOutboundCallOutcome')) {
            $outcome = $aiContext['outcome'] ?? 'confirmed';
            updateOutboundCallOutcome($db, $callSid, 'completed', $outcome, $aiContext['outcome_data'] ?? []);
        }
        $db->prepare("UPDATE om_callcenter_calls SET status = 'completed', ended_at = NOW() WHERE id = ?")
           ->execute([$callId]);

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo ttsWithEmotion("Beleza! Se precisar de algo, é só abrir o app ou ligar pra gente. Tchau!", 'happy');
        echo '<Hangup/>';
        echo '</Response>';
        exit;
    }

    // Load AI memory for context
    $memoryContext = '';
    if (function_exists('aiMemoryBuildContext')) {
        try {
            $memoryContext = aiMemoryBuildContext($db, $customerPhone, $customerId);
        } catch (Exception $e) {
            error_log("[twilio-outbound] Memory load error (non-fatal): " . $e->getMessage());
        }
    }

    // Build Claude prompt
    $systemPrompt = buildOutboundSystemPrompt($callType, $customerName, $callData, $db, $memoryContext, $turn + 1);

    $claude = new ClaudeClient('claude-sonnet-4-20250514', 25, 0);
    $recentHistory = array_slice($conversationHistory, -8);
    $cleanHistory = cleanOutboundHistory($recentHistory);

    $result = $claude->send($systemPrompt, $cleanHistory, 250);

    if (!$result['success'] || empty($result['text'])) {
        error_log("[twilio-outbound] Claude error: " . ($result['error'] ?? 'empty response'));
        $aiContext['history'] = $conversationHistory;
        saveOutboundContext($db, $callId, $aiContext);

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo ttsWithEmotion("Desculpe, tive um probleminha. Se precisar, é só chamar no app. Tchau!", 'empathetic');
        echo '<Hangup/>';
        echo '</Response>';
        exit;
    }

    $aiResponse = trim($result['text']);
    // Clean markdown/formatting
    $aiResponse = preg_replace('/\*+/', '', $aiResponse);

    // ── Parse Claude directives ──────────────────────────────────────────────
    $shouldHangup = false;

    // [CONFIRMED] — order confirmed by customer
    if (strpos($aiResponse, '[CONFIRMED]') !== false) {
        $aiResponse = str_replace('[CONFIRMED]', '', $aiResponse);
        $orderId = $callData['order_id'] ?? null;
        if ($orderId) {
            try {
                $db->prepare("
                    UPDATE om_market_orders
                    SET notes = COALESCE(notes, '') || ' [Confirmado por telefone ' || NOW()::text || ']'
                    WHERE order_id = ?
                ")->execute([(int)$orderId]);
            } catch (Exception $e) {}
        }
        $aiContext['outcome'] = 'confirmed';
        $aiContext['outcome_data'] = ['order_id' => $orderId];
        if (function_exists('updateOutboundCallOutcome')) {
            updateOutboundCallOutcome($db, $callSid, 'answered', 'confirmed', ['order_id' => $orderId]);
        }
        if (function_exists('ccBroadcastDashboard')) {
            ccBroadcastDashboard('outbound_order_confirmed', [
                'call_id' => $callId, 'order_id' => $orderId, 'customer_name' => $customerName,
            ]);
        }
    }

    // [CANCEL_ORDER] — customer wants to cancel
    if (strpos($aiResponse, '[CANCEL_ORDER]') !== false) {
        $aiResponse = str_replace('[CANCEL_ORDER]', '', $aiResponse);
        $orderId = $callData['order_id'] ?? null;
        $aiContext['outcome'] = 'declined';
        $aiContext['outcome_data'] = ['order_id' => $orderId, 'action' => 'cancel_requested'];
        if (function_exists('updateOutboundCallOutcome')) {
            updateOutboundCallOutcome($db, $callSid, 'answered', 'declined', ['order_id' => $orderId]);
        }
        error_log("[twilio-outbound] Customer requested cancellation for order {$orderId}");
    }

    // [NEEDS_CHANGE:desc] — modification requested
    if (preg_match('/\[NEEDS_CHANGE:([^\]]+)\]/', $aiResponse, $m)) {
        $aiResponse = str_replace($m[0], '', $aiResponse);
        $aiContext['outcome'] = 'callback';
        $aiContext['outcome_data'] = ['order_id' => $callData['order_id'] ?? null, 'change' => trim($m[1])];
        if (function_exists('updateOutboundCallOutcome')) {
            updateOutboundCallOutcome($db, $callSid, 'answered', 'callback', $aiContext['outcome_data']);
        }
    }

    // [RATING:N] — survey rating
    if (preg_match('/\[RATING:(\d)\]/', $aiResponse, $m)) {
        $aiResponse = str_replace($m[0], '', $aiResponse);
        $rating = max(1, min(5, (int)$m[1]));
        saveSurveyRating($db, $callSid, $callId, $customerId, $rating, $callData);
        $aiContext['outcome'] = 'survey_completed';
        $aiContext['outcome_data'] = ['rating' => $rating, 'order_id' => $callData['order_id'] ?? null];
    }

    // [FEEDBACK:text] — negative feedback detail
    if (preg_match('/\[FEEDBACK:([^\]]+)\]/', $aiResponse, $m)) {
        $aiResponse = str_replace($m[0], '', $aiResponse);
        $feedback = trim($m[1]);
        if (function_exists('aiMemoryRecordComplaint') && !empty($customerPhone)) {
            try {
                aiMemoryRecordComplaint($db, $customerPhone, $customerId, $feedback, isset($callData['order_id']) ? (int)$callData['order_id'] : null);
            } catch (Exception $e) {}
        }
        $aiContext['outcome_data']['feedback'] = $feedback;
    }

    // [TRANSFER_ORDER] — redirect to AI ordering
    if (strpos($aiResponse, '[TRANSFER_ORDER]') !== false) {
        $aiResponse = trim(str_replace('[TRANSFER_ORDER]', '', $aiResponse));
        $aiContext['outcome'] = 'ordered';
        if (function_exists('updateOutboundCallOutcome')) {
            updateOutboundCallOutcome($db, $callSid, 'answered', 'ordered');
        }
        $aiContext['history'] = $conversationHistory;
        saveOutboundContext($db, $callId, $aiContext);

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        if (!empty($aiResponse)) {
            echo ttsWithEmotion(trim($aiResponse), 'excited');
        }
        echo '<Redirect method="POST">' . escXml($aiHandlerUrl) . '</Redirect>';
        echo '</Response>';
        exit;
    }

    // [HANGUP] or [encerrar] — end call
    if (strpos($aiResponse, '[HANGUP]') !== false || strpos($aiResponse, '[encerrar]') !== false) {
        $aiResponse = trim(str_replace(['[HANGUP]', '[encerrar]'], '', $aiResponse));
        $shouldHangup = true;
    }

    // Natural hangup detection
    if (!$shouldHangup) {
        $lowerResponse = mb_strtolower($aiResponse, 'UTF-8');
        $endPhrases = ['tchau', 'tenha um otimo', 'tenha um bom', 'ate logo', 'bom apetite'];
        foreach ($endPhrases as $ep) {
            if (mb_strpos($lowerResponse, $ep) !== false) {
                $shouldHangup = true;
                break;
            }
        }
    }

    $aiResponse = trim($aiResponse);
    $conversationHistory[] = ['role' => 'assistant', 'content' => $aiResponse];
    $aiContext['history'] = $conversationHistory;
    saveOutboundContext($db, $callId, $aiContext);

    // ── Respond with TwiML ───────────────────────────────────────────────────
    if ($shouldHangup) {
        $db->prepare("UPDATE om_callcenter_calls SET status = 'completed', ended_at = NOW() WHERE id = ?")
           ->execute([$callId]);
        if (function_exists('updateOutboundCallOutcome')) {
            $outcome = $aiContext['outcome'] ?? 'confirmed';
            updateOutboundCallOutcome($db, $callSid, 'completed', $outcome, $aiContext['outcome_data'] ?? []);
        }
        if (function_exists('ccBroadcastDashboard')) {
            ccBroadcastDashboard('outbound_call_completed', [
                'call_id' => $callId, 'call_type' => $callType,
                'outcome' => $aiContext['outcome'] ?? 'no_interaction', 'customer_name' => $customerName,
            ]);
        }

        $emotion = ($aiContext['outcome'] ?? '') === 'confirmed' ? 'happy' : 'neutral';
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo ttsWithEmotion($aiResponse, $emotion);
        echo '<Hangup/>';
        echo '</Response>';
    } else {
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . escXml($selfUrl) . '" method="POST" speechTimeout="auto" enhanced="true" speechModel="phone_call">';
        echo ttsWithEmotion($aiResponse, 'neutral');
        echo '</Gather>';
        echo ttsWithEmotion("Oi, ta ai? Se nao puder falar, pode desligar. Tchau!", 'neutral');
        echo '<Hangup/>';
        echo '</Response>';
    }

} catch (Exception $e) {
    error_log("[twilio-outbound] Error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo ttsWithEmotion("Desculpa, deu um probleminha aqui. Tenta de novo pelo app. Tchau!", 'empathetic');
    echo '<Hangup/>';
    echo '</Response>';
}


// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

function escXml(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Load AI context from om_callcenter_calls.notes JSONB.
 */
function loadOutboundContext(PDO $db, int $callId): array {
    $stmt = $db->prepare("SELECT notes FROM om_callcenter_calls WHERE id = ?");
    $stmt->execute([$callId]);
    $row = $stmt->fetch();
    $notes = $row['notes'] ?? '';
    if (!empty($notes) && $notes[0] === '{') {
        $ctx = json_decode($notes, true);
        if (is_array($ctx) && isset($ctx['_ai_context'])) {
            return $ctx['_ai_context'];
        }
        // Legacy key from older version
        if (is_array($ctx) && isset($ctx['_outbound_state'])) {
            return $ctx['_outbound_state'];
        }
    }
    return ['step' => 'greeting', 'script_delivered' => false, 'turn' => 0, 'history' => [], 'silence_count' => 0];
}

/**
 * Save AI context into om_callcenter_calls.notes JSONB.
 */
function saveOutboundContext(PDO $db, int $callId, array $context): void {
    if (isset($context['history']) && count($context['history']) > 14) {
        $context['history'] = array_slice($context['history'], -14);
    }
    $json = json_encode(['_ai_context' => $context], JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare("UPDATE om_callcenter_calls SET notes = ? WHERE id = ?");
    $stmt->execute([$json, $callId]);
}

/**
 * Ensure alternating user/assistant roles for Claude.
 */
function cleanOutboundHistory(array $history): array {
    $cleaned  = [];
    $lastRole = null;
    foreach ($history as $msg) {
        if (!isset($msg['role'], $msg['content'])) continue;
        if (empty(trim($msg['content']))) continue;
        if ($msg['role'] === $lastRole) {
            if (!empty($cleaned)) {
                $cleaned[count($cleaned) - 1]['content'] .= ' ' . $msg['content'];
            }
            continue;
        }
        $cleaned[] = $msg;
        $lastRole  = $msg['role'];
    }
    if (!empty($cleaned) && $cleaned[0]['role'] === 'assistant') {
        array_unshift($cleaned, ['role' => 'user', 'content' => '[cliente atendeu a ligacao]']);
    }
    return $cleaned;
}

// ─────────────────────────────────────────────────────────────────────────────
// Call Script Builder (initial greeting per type)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @return array{message: string, emotion: string, follow_up?: string}
 */
function buildCallScript(string $type, ?string $name, array $data, PDO $db): array {
    $nome = !empty($name) ? ' ' . explode(' ', trim($name))[0] : '';

    switch ($type) {
        case 'order_confirm':
        case 'order_confirmation':
            $orderId = $data['order_id'] ?? null;
            $storeName = $data['store_name'] ?? $data['partner_name'] ?? '';
            $total = isset($data['total']) ? number_format((float)$data['total'], 2, ',', '.') : null;
            $items = $data['items_summary'] ?? '';

            // Fetch from DB if we have an order_id and missing data
            if ($orderId && (empty($storeName) || empty($items))) {
                $order = _fetchOrderSummary($db, (int)$orderId);
                if ($order) {
                    $storeName = $storeName ?: $order['partner_name'];
                    $items = $items ?: $order['items_summary'];
                    $total = $total ?: number_format((float)$order['total'], 2, ',', '.');
                }
            }

            $message = "Oi{$nome}! Aqui é a Bora, do SuperBora. To ligando pra confirmar seu pedido";
            if ($storeName) $message .= " da {$storeName}";
            $message .= ".";
            if ($items) $message .= " Voce pediu: {$items}.";
            if ($total) $message .= " Total de {$total} reais.";

            return [
                'message'   => $message,
                'emotion'   => 'happy',
                'follow_up' => "Ta tudo certinho?",
            ];

        case 'delivery_update':
            $storeName = $data['store_name'] ?? $data['partner_name'] ?? '';
            $status    = $data['status'] ?? '';
            $statusMessages = [
                'aceito'     => 'foi aceito pela loja e ja esta sendo preparado',
                'preparando' => 'ja esta sendo preparado',
                'pronto'     => 'ja esta pronto e saindo pra entrega',
                'a_caminho'  => 'esta a caminho do seu endereco',
                'entregue'   => 'foi entregue',
            ];
            $statusMsg = $statusMessages[$status] ?? 'teve uma atualizacao';

            $orderId = $data['order_id'] ?? null;
            if ($orderId && empty($storeName)) {
                $order = _fetchOrderSummary($db, (int)$orderId);
                if ($order) $storeName = $order['partner_name'];
            }

            $storeRef = $storeName ? " da {$storeName}" : '';
            $message = "Oi{$nome}! Aqui é a Bora, do SuperBora. So pra te avisar que seu pedido{$storeRef} {$statusMsg}.";

            return [
                'message'   => $message,
                'emotion'   => 'happy',
                'follow_up' => "Precisa de mais alguma coisa?",
            ];

        case 'reengagement':
            $promo    = $data['promo'] ?? $data['promo_text'] ?? null;
            $reason   = $data['reason'] ?? 'win_back';
            $favStore = $data['favorite_store'] ?? null;

            $message = "Oi{$nome}! Aqui é a Bora, do SuperBora.";

            if ($reason === 'abandoned_cart') {
                $cartTotal = isset($data['cart_total']) ? number_format((float)$data['cart_total'], 2, ',', '.') : null;
                $message .= " Vi que voce montou um carrinho";
                if ($cartTotal) $message .= " de {$cartTotal} reais";
                $message .= " mas nao finalizou. Quer que eu te ajude a completar?";
            } elseif ($reason === 'birthday') {
                $message .= " Parabens pelo seu dia! A gente preparou um presente especial pra voce.";
                $message .= $promo ? " {$promo}!" : " Um desconto de aniversario!";
            } elseif ($reason === 'habit_match') {
                $message .= " Toda semana por volta desse horario voce costuma pedir, né? To ligando pra facilitar!";
            } else {
                $message .= " Faz um tempinho que a gente nao se fala!";
                if ($promo) {
                    $message .= " To ligando porque tem {$promo} rolando!";
                } elseif ($favStore) {
                    $message .= " A {$favStore} ta com novidades!";
                } else {
                    $message .= " Temos umas promocoes legais rolando!";
                }
            }

            return [
                'message'   => $message,
                'emotion'   => 'excited',
                'follow_up' => "Quer que eu te ajude a fazer um pedido?",
            ];

        case 'survey':
            $orderId   = $data['order_id'] ?? null;
            $storeName = $data['store_name'] ?? $data['partner_name'] ?? '';

            if ($orderId && empty($storeName)) {
                $order = _fetchOrderSummary($db, (int)$orderId);
                if ($order) $storeName = $order['partner_name'];
            }

            $storeRef = $storeName ? " da {$storeName}" : '';
            $message = "Oi{$nome}! Aqui é a Bora, do SuperBora. Queria saber como foi seu pedido{$storeRef}. De 1 a 5, como voce avalia a experiencia? Pode falar o numero ou dizer com suas palavras.";

            return [
                'message' => $message,
                'emotion' => 'neutral',
            ];

        case 'promo':
            $promoText = $data['message'] ?? $data['promo'] ?? $data['promo_text'] ?? 'promocoes incriveis';
            $storeName = $data['store_name'] ?? null;

            $message = "Oi{$nome}! Aqui é a Bora, do SuperBora. Tenho uma novidade pra voce: {$promoText}.";
            if ($storeName) $message .= " La na {$storeName}.";

            return [
                'message'   => $message,
                'emotion'   => 'excited',
                'follow_up' => "Quer aproveitar e fazer um pedido?",
            ];

        default:
            $customMsg = $data['message'] ?? $data['script'] ?? '';
            if (!empty($customMsg)) {
                return [
                    'message'   => "Oi{$nome}! Aqui é a Bora, do SuperBora. {$customMsg}",
                    'emotion'   => $data['emotion'] ?? 'neutral',
                    'follow_up' => $data['follow_up'] ?? "Posso te ajudar com alguma coisa?",
                ];
            }
            return [
                'message'   => "Oi{$nome}! Aqui é a Bora, do SuperBora. Posso te ajudar com algum pedido hoje?",
                'emotion'   => 'happy',
                'follow_up' => null,
            ];
    }
}

/**
 * Build a brief voicemail message when machine is detected.
 */
function buildVoicemailMessage(string $type, ?string $name, array $data): string {
    $nome = !empty($name) ? ' ' . explode(' ', trim($name))[0] : '';

    switch ($type) {
        case 'order_confirm':
        case 'order_confirmation':
            $num = $data['order_number'] ?? $data['order_id'] ?? '';
            return "Oi{$nome}! Bora do SuperBora. Seu pedido {$num} ta confirmado! Acompanha pelo app. Tchau!";
        case 'delivery_update':
            return "Oi{$nome}! Bora do SuperBora. Seu pedido saiu pra entrega! Acompanha pelo app. Tchau!";
        case 'reengagement':
            return "Oi{$nome}! Bora do SuperBora. Tem promocoes legais te esperando no app! Da uma olhada. Tchau!";
        case 'survey':
            return "Oi{$nome}! Bora do SuperBora. Queria saber como foi seu pedido. Avalia pelo app quando puder! Tchau!";
        case 'promo':
            $promo = $data['promo'] ?? $data['message'] ?? 'promocoes especiais';
            return "Oi{$nome}! Bora do SuperBora. Tem {$promo} te esperando no app! Confere la. Tchau!";
        default:
            return "Oi{$nome}! Bora do SuperBora. Da uma olhada no app quando puder. Tchau!";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Claude System Prompt Builder
// ─────────────────────────────────────────────────────────────────────────────

function buildOutboundSystemPrompt(
    string $callType,
    ?string $customerName,
    array $callData,
    PDO $db,
    string $memoryContext,
    int $turn
): string {
    $nome = $customerName ?: 'cliente';

    $prompt = "Voce é a Bora, atendente virtual do SuperBora (marketplace delivery de mercado e restaurante). "
        . "Voce esta numa ligacao OUTBOUND (voce ligou pro cliente).\n\n"
        . "## REGRAS\n"
        . "1. MAXIMO 2-3 frases curtas. Isso vira audio via TTS.\n"
        . "2. Use linguagem informal brasileira: to, ta, pra, pro, né.\n"
        . "3. Tom amigavel e caloroso. Voce é gente, nao robo.\n"
        . "4. NUNCA use markdown, asteriscos, emojis ou formatacao.\n"
        . "5. Nao repita informacoes ja ditas na saudacao inicial.\n"
        . "6. Respeite o cliente — se nao quiser conversar, encerre.\n\n"
        . "## DIRETIVAS (inclua na resposta quando aplicavel)\n"
        . "- [CONFIRMED] = pedido confirmado pelo cliente\n"
        . "- [CANCEL_ORDER] = cliente quer cancelar\n"
        . "- [NEEDS_CHANGE:descricao] = cliente quer alterar algo\n"
        . "- [RATING:N] = nota de 1 a 5 da pesquisa\n"
        . "- [FEEDBACK:resumo] = feedback negativo detalhado\n"
        . "- [TRANSFER_ORDER] = cliente quer fazer um pedido (redireciona)\n"
        . "- [HANGUP] = conversa encerrada naturalmente\n\n";

    switch ($callType) {
        case 'order_confirm':
        case 'order_confirmation':
            $orderId = $callData['order_id'] ?? null;
            $orderDetails = '';
            if ($orderId) {
                $order = _fetchOrderSummary($db, (int)$orderId);
                if ($order) {
                    $orderDetails = "Pedido #{$order['order_number']} da {$order['partner_name']}: {$order['items_summary']}. Total: R$ " . number_format($order['total'], 2, ',', '.');
                }
            }
            $prompt .= "## CONTEXTO: Confirmacao de Pedido\n{$orderDetails}\n"
                . "Se confirmar: responda positivamente + [CONFIRMED] + [HANGUP]\n"
                . "Se quiser mudar: anote + [NEEDS_CHANGE:desc] + [HANGUP]\n"
                . "Se quiser cancelar: confirme + [CANCEL_ORDER] + [HANGUP]\n";
            break;

        case 'delivery_update':
            $status = $callData['status'] ?? '';
            $prompt .= "## CONTEXTO: Atualizacao de Entrega\nStatus: {$status}\n"
                . "Conversa breve. Se tiver duvida, responda. Encerre com [HANGUP].\n";
            break;

        case 'reengagement':
            $prompt .= "## CONTEXTO: Reconquista\n"
                . "Se interessado em pedir: [TRANSFER_ORDER]\n"
                . "Se nao interessado: agradeca + [HANGUP]\n"
                . "NAO insista mais que 1 vez.\n";
            break;

        case 'survey':
            $prompt .= "## CONTEXTO: Pesquisa de Satisfacao\n"
                . "Pedido: #" . ($callData['order_id'] ?? '?') . "\n"
                . "Interprete resposta como nota 1-5:\n"
                . "  otimo/excelente/adorei = 5, bom/gostei = 4, ok/razoavel = 3, ruim = 2, pessimo = 1\n"
                . "Inclua [RATING:N]. Se nota < 3: empatia + pergunte o que houve + [FEEDBACK:resumo].\n"
                . "Se nota >= 4: agradeca com entusiasmo.\n"
                . "Apos coletar: [HANGUP].\n";
            break;

        case 'promo':
            $promoText = $callData['message'] ?? $callData['promo'] ?? 'promocoes especiais';
            $prompt .= "## CONTEXTO: Promocao\nPromocao: {$promoText}\n"
                . "Se interessado: [TRANSFER_ORDER]\n"
                . "Se nao: agradeca + [HANGUP]\n"
                . "NAO insista.\n";
            break;

        default:
            $prompt .= "## CONTEXTO: Ligacao geral\nResponda de forma util e breve.\n";
            break;
    }

    if (!empty($memoryContext)) {
        $prompt .= "\n" . $memoryContext . "\n";
    }

    $prompt .= "\nCliente: {$nome} | Turno: {$turn}\n";

    return $prompt;
}

// ─────────────────────────────────────────────────────────────────────────────
// Survey Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Parse speech input into a survey rating (1-5). Returns null if unrecognized.
 */
function parseSurveyRating(string $input): ?int {
    $lower = mb_strtolower(trim($input), 'UTF-8');

    // Direct number
    if (preg_match('/^[1-5]$/', trim($input))) {
        return (int)trim($input);
    }
    // Number words
    $wordMap = ['um' => 1, 'uma' => 1, 'dois' => 2, 'duas' => 2, 'tres' => 3, 'quatro' => 4, 'cinco' => 5];
    foreach ($wordMap as $word => $val) {
        if (trim($lower) === $word || mb_strpos($lower, "nota {$word}") !== false) {
            return $val;
        }
    }
    // Sentiment
    $five  = ['otimo', 'excelente', 'perfeito', 'adorei', 'maravilhoso', 'incrivel', 'nota 5', 'nota cinco', 'cinco estrelas'];
    $four  = ['bom', 'gostei', 'legal', 'bacana', 'nota 4', 'nota quatro', 'quatro estrelas'];
    $three = ['ok', 'razoavel', 'mais ou menos', 'mediano', 'nota 3', 'nota tres', 'regular'];
    $two   = ['ruim', 'nao gostei', 'fraco', 'nota 2', 'nota dois'];
    $one   = ['pessimo', 'horrivel', 'terrivel', 'nota 1', 'nota um'];

    foreach ($five as $w) { if (mb_strpos($lower, $w) !== false) return 5; }
    foreach ($four as $w) { if (mb_strpos($lower, $w) !== false) return 4; }
    foreach ($three as $w) { if (mb_strpos($lower, $w) !== false) return 3; }
    foreach ($two as $w) { if (mb_strpos($lower, $w) !== false) return 2; }
    foreach ($one as $w) { if (mb_strpos($lower, $w) !== false) return 1; }

    return null;
}

/**
 * Handle survey rating: save, respond, and hang up.
 */
function handleSurveyRating(PDO $db, string $callSid, int $callId, ?int $customerId, int $rating, array $callData): void {
    saveSurveyRating($db, $callSid, $callId, $customerId, $rating, $callData);

    $ratingResponses = [
        1 => "Poxa, nota 1? Sinto muito pela experiencia ruim. Vou repassar pro time pra melhorar, ta? Obrigada pela sinceridade!",
        2 => "Nota 2, entendi. Desculpa que nao foi tao bom. A gente vai trabalhar pra melhorar! Obrigada!",
        3 => "Nota 3, ok! Vamos caprichar mais na proxima. Obrigada!",
        4 => "Nota 4, que bom! Quase perfeito! Obrigada pelo retorno!",
        5 => "Nota 5! Que maravilha! Fico muito feliz! Obrigada demais!",
    ];

    $emotion = $rating <= 2 ? 'empathetic' : ($rating >= 4 ? 'happy' : 'neutral');
    $responseMsg = $ratingResponses[$rating] ?? "Obrigada pela avaliacao!";

    $db->prepare("UPDATE om_callcenter_calls SET status = 'completed', ended_at = NOW() WHERE id = ?")
       ->execute([$callId]);

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo ttsWithEmotion($responseMsg, $emotion);

    // If bad rating, ask what went wrong before hanging up
    if ($rating < 3) {
        global $selfUrl;
        // Note: for simplicity, just acknowledge. Claude will handle follow-up if they respond.
        echo ttsWithEmotion("O que aconteceu? Me conta rapidinho que eu anoto.", 'empathetic');
    }

    echo ttsWithEmotion("Valeu por atender! Tchau!", 'happy');
    echo '<Hangup/>';
    echo '</Response>';
}

/**
 * Persist survey rating to DB.
 */
function saveSurveyRating(PDO $db, string $callSid, int $callId, ?int $customerId, int $rating, array $callData): void {
    $orderId = $callData['order_id'] ?? null;

    if ($orderId) {
        try {
            $db->prepare("
                INSERT INTO om_market_reviews (order_id, customer_id, rating, comment, source, created_at)
                VALUES (?, ?, ?, 'Avaliacao por telefone', 'phone_survey', NOW())
                ON CONFLICT DO NOTHING
            ")->execute([(int)$orderId, $customerId, $rating]);
        } catch (Exception $e) {
            // Fallback: save to order notes
            try {
                $db->prepare("
                    UPDATE om_market_orders
                    SET notes = COALESCE(notes, '') || ' [Avaliacao telefone: ' || ?::text || '/5 ' || NOW()::text || ']'
                    WHERE order_id = ?
                ")->execute([$rating, (int)$orderId]);
            } catch (Exception $e2) {
                error_log("[twilio-outbound] Failed to save rating: " . $e2->getMessage());
            }
        }
    }

    if (function_exists('updateOutboundCallOutcome')) {
        updateOutboundCallOutcome($db, $callSid, 'completed', 'survey_completed', [
            'rating' => $rating, 'order_id' => $orderId,
        ]);
    }

    if (function_exists('ccBroadcastDashboard')) {
        ccBroadcastDashboard('outbound_survey_completed', [
            'call_id' => $callId, 'rating' => $rating, 'order_id' => $orderId,
        ]);
    }

    error_log("[twilio-outbound] Survey rating: {$rating}/5 for order {$orderId}");
}

// ─────────────────────────────────────────────────────────────────────────────
// DB Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch order summary for greeting/prompt.
 */
function _fetchOrderSummary(PDO $db, int $orderId): ?array {
    static $cache = [];
    if (isset($cache[$orderId])) return $cache[$orderId];

    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.total, o.status, o.delivery_address,
               p.name AS partner_name
        FROM om_market_orders o
        JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.order_id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        $cache[$orderId] = null;
        return null;
    }

    $itemsStmt = $db->prepare("
        SELECT product_name AS name, quantity FROM om_market_order_items WHERE order_id = ? LIMIT 8
    ");
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll();

    $itemsSummary = implode(', ', array_map(
        fn($i) => ((int)$i['quantity'] > 1 ? $i['quantity'] . 'x ' : '') . $i['name'],
        $items
    ));

    $result = [
        'order_id'         => (int)$order['order_id'],
        'order_number'     => $order['order_number'],
        'total'            => (float)$order['total'],
        'status'           => $order['status'],
        'delivery_address' => $order['delivery_address'] ?? '',
        'partner_name'     => $order['partner_name'],
        'items_summary'    => $itemsSummary,
        'items'            => $items,
    ];

    $cache[$orderId] = $result;
    return $result;
}

// ─────────────────────────────────────────────────────────────────────────────
// Status Callback Handler
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Handle Twilio status callback events for outbound calls.
 * Maps Twilio statuses to our om_outbound_calls + om_callcenter_calls statuses.
 */
function handleStatusCallback(): void {
    header('Content-Type: text/plain');

    $callSid    = $_POST['CallSid'] ?? '';
    $callStatus = $_POST['CallStatus'] ?? '';
    $duration   = isset($_POST['CallDuration']) ? (int)$_POST['CallDuration'] : null;

    if (empty($callSid)) {
        http_response_code(200);
        echo 'OK';
        return;
    }

    error_log("[twilio-outbound-status] CallSid={$callSid} Status={$callStatus} Duration={$duration}");

    try {
        $db = getDB();

        $statusMap = [
            'initiated'    => 'initiated',
            'ringing'      => 'ringing',
            'in-progress'  => 'answered',
            'completed'    => 'completed',
            'no-answer'    => 'no_answer',
            'busy'         => 'busy',
            'failed'       => 'failed',
            'canceled'     => 'failed',
        ];

        $ourStatus = $statusMap[$callStatus] ?? null;
        if (!$ourStatus) {
            http_response_code(200);
            echo 'OK';
            return;
        }

        // ── Update om_outbound_calls ─────────────────────────────────────────
        $sets   = ['status = ?', 'updated_at = NOW()'];
        $params = [$ourStatus];

        if ($duration !== null) {
            $sets[]   = 'duration_seconds = ?';
            $params[] = $duration;
        }
        if ($ourStatus === 'answered') {
            $sets[] = 'last_attempt_at = NOW()';
        }
        if (in_array($ourStatus, ['no_answer', 'busy', 'failed'], true)) {
            $sets[]   = "outcome = COALESCE(outcome, ?)";
            $params[] = 'no_interaction';
        }

        $params[] = $callSid;
        $db->prepare("UPDATE om_outbound_calls SET " . implode(', ', $sets) . " WHERE twilio_call_sid = ?")
           ->execute($params);

        // ── Update om_callcenter_calls ───────────────────────────────────────
        $ccSets   = [];
        $ccParams = [];

        // Map to callcenter status enum
        $ccStatusMap = [
            'answered'   => 'in_progress',
            'completed'  => 'completed',
            'no_answer'  => 'missed',
            'busy'       => 'missed',
            'failed'     => 'missed',
            'ringing'    => 'ringing',
        ];
        $ccStatus = $ccStatusMap[$ourStatus] ?? null;
        if ($ccStatus) {
            $ccSets[]   = 'status = ?';
            $ccParams[] = $ccStatus;
        }
        if ($duration !== null) {
            $ccSets[]   = 'duration_seconds = ?';
            $ccParams[] = $duration;
        }
        if (in_array($ourStatus, ['completed', 'no_answer', 'busy', 'failed'], true)) {
            $ccSets[] = 'ended_at = COALESCE(ended_at, NOW())';
        }
        if ($ourStatus === 'answered') {
            $ccSets[] = 'answered_at = COALESCE(answered_at, NOW())';
        }
        if (!empty($ccSets)) {
            $ccParams[] = $callSid;
            $db->prepare("UPDATE om_callcenter_calls SET " . implode(', ', $ccSets) . " WHERE twilio_call_sid = ?")
               ->execute($ccParams);
        }

        // Broadcast
        if (function_exists('ccBroadcastDashboard')) {
            ccBroadcastDashboard('outbound_call_status', [
                'call_sid' => $callSid,
                'status'   => $ourStatus,
                'duration' => $duration,
            ]);
        }
    } catch (Exception $e) {
        error_log("[twilio-outbound-status] Error: " . $e->getMessage());
    }

    http_response_code(200);
    echo 'OK';
}
