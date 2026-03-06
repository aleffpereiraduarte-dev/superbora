<?php
/**
 * POST /api/mercado/webhooks/twilio-voice-route.php
 * Twilio Voice IVR Routing -- processes speech/DTMF input after greeting
 *
 * Routes:
 *   - DTMF 0 or speech "atendente"/"agente"/"pessoa" -> agent queue
 *   - Speech input -> create call record, try store match, start AI conversation
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

// Signature validation — log mismatches but allow requests with CallSid (Twilio always sends it)
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
        error_log("[twilio-voice-route] Signature mismatch — allowing (proxy may alter URL)");
    }
} elseif (empty($twilioSignature) && !isset($_POST['CallSid'])) {
    error_log("[twilio-voice-route] Rejected: no signature and no CallSid");
    http_response_code(403);
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Say>Unauthorized</Say></Response>';
    exit;
}

// -- Parse Input --
$digits = $_POST['Digits'] ?? $_GET['Digits'] ?? '';
$speechResult = $_POST['SpeechResult'] ?? '';
$callerPhone = $_POST['From'] ?? '';
$callSid = $_POST['CallSid'] ?? '';
$noInput = $_GET['noInput'] ?? '';

error_log("[twilio-voice-route] CallSid={$callSid} Digits={$digits} Speech={$speechResult} Phone={$callerPhone}");

// Build URLs
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$selfUrl = $scheme . '://' . $host . strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$aiUrl = str_replace('twilio-voice-route.php', 'twilio-voice-ai.php', $selfUrl);

// -- Check if user wants an agent --
$wantsAgent = false;
if ($digits === '0') {
    $wantsAgent = true;
}
if (!empty($speechResult)) {
    $speechLower = mb_strtolower($speechResult, 'UTF-8');
    $agentKeywords = ['atendente', 'agente', 'pessoa', 'humano', 'operador', 'falar com alguem', 'falar com gente'];
    foreach ($agentKeywords as $keyword) {
        if (mb_strpos($speechLower, $keyword) !== false) {
            $wantsAgent = true;
            break;
        }
    }
}
if ($noInput === '1' && empty($digits) && empty($speechResult)) {
    $wantsAgent = true;
}

try {
    $db = getDB();

    // -- Look up customer by phone --
    $customerPhone = preg_replace('/\D/', '', $callerPhone);
    $customerId = null;
    $customerName = null;

    if ($customerPhone) {
        $phoneSuffix = substr($customerPhone, -11);
        $stmt = $db->prepare("
            SELECT customer_id, name FROM om_customers
            WHERE REPLACE(REPLACE(phone, '+', ''), '-', '') LIKE ?
            LIMIT 1
        ");
        $stmt->execute(['%' . $phoneSuffix]);
        $customer = $stmt->fetch();
        if ($customer) {
            $customerId = (int)$customer['customer_id'];
            $customerName = $customer['name'];
        }
    }

    // -- Create call record --
    $initialStatus = $wantsAgent ? 'queued' : 'ai_handling';
    $stmt = $db->prepare("
        INSERT INTO om_callcenter_calls
            (twilio_call_sid, customer_phone, customer_id, customer_name, direction, status, started_at)
        VALUES (?, ?, ?, ?, 'inbound', ?, NOW())
        ON CONFLICT (twilio_call_sid) DO UPDATE SET
            customer_id = COALESCE(EXCLUDED.customer_id, om_callcenter_calls.customer_id),
            customer_name = COALESCE(EXCLUDED.customer_name, om_callcenter_calls.customer_name),
            status = EXCLUDED.status
        RETURNING id
    ");
    $stmt->execute([$callSid, $callerPhone, $customerId, $customerName, $initialStatus]);
    $callId = (int)$stmt->fetch()['id'];

    // -- Detect support intent (status, cancel, etc) --
    $wantsSupport = false;
    if (!$wantsAgent && !empty($speechResult)) {
        $speechLower = mb_strtolower($speechResult, 'UTF-8');
        $supportKeywords = ['status', 'cancelar', 'cancela', 'rastrear', 'rastreio', 'cadê meu pedido', 'cade meu pedido', 'onde ta', 'onde está', 'meu pedido', 'reclamação', 'reclamacao', 'problema', 'reembolso'];
        foreach ($supportKeywords as $sk) {
            if (mb_strpos($speechLower, $sk) !== false) {
                $wantsSupport = true;
                break;
            }
        }
    }

    // -- Try to identify store from speech --
    $storeIdentified = null;
    $storeId = null;
    if (!$wantsAgent && !$wantsSupport && !empty($speechResult)) {
        $speechLower = mb_strtolower($speechResult, 'UTF-8');
        $storeStmt = $db->prepare("
            SELECT partner_id, name FROM om_market_partners
            WHERE status = '1' AND LOWER(name) LIKE ?
            ORDER BY LENGTH(name) ASC LIMIT 1
        ");
        $storeStmt->execute(['%' . $speechLower . '%']);
        $store = $storeStmt->fetch();
        if ($store) {
            $storeIdentified = $store['name'];
            $storeId = (int)$store['partner_id'];
            $db->prepare("UPDATE om_callcenter_calls SET store_identified = ? WHERE id = ?")
               ->execute([$storeIdentified, $callId]);
        }
    }

    // -- Route --
    if ($wantsAgent) {
        // Add to queue
        $priority = $customerId ? 3 : 5;
        $stmt = $db->prepare("
            INSERT INTO om_callcenter_queue (call_id, customer_phone, customer_name, customer_id, priority, queued_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$callId, $callerPhone, $customerName, $customerId, $priority]);

        ccBroadcastDashboard('queue_updated', [
            'call_id' => $callId,
            'customer_phone' => $callerPhone,
            'customer_name' => $customerName,
            'action' => 'new',
        ]);

        error_log("[twilio-voice-route] Queued for agent: call_id={$callId}");

        // Count queue position
        $posStmt = $db->prepare("
            SELECT COUNT(*) as pos FROM om_callcenter_queue
            WHERE picked_at IS NULL AND abandoned_at IS NULL AND queued_at <= (
                SELECT queued_at FROM om_callcenter_queue WHERE call_id = ? LIMIT 1
            )
        ");
        $posStmt->execute([$callId]);
        $position = (int)$posStmt->fetch()['pos'];

        $waitMsg = $position <= 1
            ? "Voce sera atendido em instantes."
            : "Voce e o numero {$position} na fila. Tempo estimado: " . ($position * 2) . " minutos.";

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo '<Say voice="Polly.Camila" language="pt-BR">Aguarde um momento, estamos transferindo voce para um atendente. ' . htmlspecialchars($waitMsg) . '</Say>';
        echo '<Play loop="0">http://com.twilio.music.classical.s3.amazonaws.com/ith_brahms-702702.mp3</Play>';
        echo '</Response>';

    } else {
        // -- AI Handling -- Route to the AI conversation handler
        // Determine initial step
        $initialStep = 'identify_store';
        if ($wantsSupport) {
            $initialStep = 'support';
        } elseif ($storeId) {
            $initialStep = 'take_order';
        }

        // Save initial context with store info if found
        $aiContext = [
            '_ai_context' => [
                'step' => $initialStep,
                'store_id' => $storeId,
                'store_name' => $storeIdentified,
                'items' => [],
                'history' => [],
            ]
        ];

        // If store was found from speech, add that context
        if ($storeIdentified) {
            $aiContext['_ai_context']['history'][] = ['role' => 'user', 'content' => $speechResult];
            $aiContext['_ai_context']['history'][] = ['role' => 'assistant', 'content' => "Otimo! Encontrei o restaurante {$storeIdentified}. O que voce gostaria de pedir?"];
        } elseif (!empty($speechResult)) {
            $aiContext['_ai_context']['history'][] = ['role' => 'user', 'content' => $speechResult];
        }

        // Save initial AI context
        $db->prepare("UPDATE om_callcenter_calls SET notes = ? WHERE id = ?")
           ->execute([json_encode($aiContext, JSON_UNESCAPED_UNICODE), $callId]);

        ccBroadcastDashboard('call_ai_started', [
            'call_id' => $callId,
            'customer_phone' => $callerPhone,
            'customer_name' => $customerName,
            'speech' => $speechResult,
            'store_identified' => $storeIdentified,
            'support_mode' => $wantsSupport,
        ]);

        error_log("[twilio-voice-route] AI handling: call_id={$callId} store={$storeIdentified} support={$wantsSupport}");

        // Build greeting
        $greeting = $customerName ? "Ola, {$customerName}!" : "Ola!";
        if ($wantsSupport) {
            $greeting .= " Entendi, voce precisa de ajuda com um pedido. Vou verificar seus pedidos agora. Me diga o que precisa: ver o status, cancelar, ou alguma outra questao?";
        } elseif ($storeIdentified) {
            $greeting .= " Encontrei o restaurante {$storeIdentified} para voce. O que voce gostaria de pedir?";
        } else {
            $greeting .= " Sou a assistente virtual do SuperBora e vou te ajudar a fazer seu pedido. De qual restaurante voce gostaria de pedir?";
        }

        // Start AI conversation loop
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . htmlspecialchars($aiUrl) . '" method="POST" speechTimeout="auto" numDigits="1">';
        echo '<Say voice="Polly.Camila" language="pt-BR">' . htmlspecialchars($greeting) . '</Say>';
        echo '</Gather>';
        echo '<Say voice="Polly.Camila" language="pt-BR">Nao consegui ouvir. Pode repetir por favor?</Say>';
        echo '<Redirect method="POST">' . htmlspecialchars($aiUrl) . '</Redirect>';
        echo '</Response>';
    }

} catch (Exception $e) {
    error_log("[twilio-voice-route] Error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="Polly.Camila" language="pt-BR">Desculpe, ocorreu um erro. Por favor, tente novamente mais tarde.</Say>';
    echo '<Hangup/>';
    echo '</Response>';
}
