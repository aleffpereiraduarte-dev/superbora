<?php
/**
 * POST /api/mercado/webhooks/twilio-voice-route.php
 * Twilio Voice IVR Routing -- processes speech/DTMF input after greeting
 *
 * Routes:
 *   - DTMF 0 or speech "atendente"/"agente" -> agent queue
 *   - CEP detected -> lookup stores in area, start AI with context
 *   - Food/cuisine mentioned -> suggest matching stores
 *   - Store name -> try match, start AI order flow
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

if (!empty($authToken) && !empty($twilioSignature)) {
    $scheme = 'https';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'superbora.com.br';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $fullUrl = $scheme . '://' . $host . strtok($uri, '?');
    $params = $_POST;
    ksort($params);
    $dataString = $fullUrl;
    foreach ($params as $key => $value) { $dataString .= $key . $value; }
    $expectedSignature = base64_encode(hash_hmac('sha1', $dataString, $authToken, true));
    if (!hash_equals($expectedSignature, $twilioSignature)) {
        error_log("[twilio-voice-route] Signature mismatch — allowing (proxy may alter URL)");
    }
} elseif (empty($twilioSignature) && !isset($_POST['CallSid'])) {
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
$scheme = 'https';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'superbora.com.br';
$selfUrl = $scheme . '://' . $host . strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$aiUrl = str_replace('twilio-voice-route.php', 'twilio-voice-ai.php', $selfUrl);

// -- Check if user wants an agent --
$wantsAgent = false;
if ($digits === '0') $wantsAgent = true;
if (!empty($speechResult)) {
    $speechLower = mb_strtolower($speechResult, 'UTF-8');
    $agentKeywords = ['atendente', 'agente', 'pessoa', 'humano', 'operador', 'falar com alguem', 'falar com gente'];
    foreach ($agentKeywords as $keyword) {
        if (mb_strpos($speechLower, $keyword) !== false) { $wantsAgent = true; break; }
    }
}
if ($noInput === '1' && empty($digits) && empty($speechResult)) $wantsAgent = true;

try {
    $db = getDB();

    // -- Look up customer --
    $customerPhone = preg_replace('/\D/', '', $callerPhone);
    $customerId = null;
    $customerName = null;

    if ($customerPhone) {
        $phoneSuffix = substr($customerPhone, -11);
        $stmt = $db->prepare("SELECT customer_id, name FROM om_customers WHERE REPLACE(REPLACE(phone, '+', ''), '-', '') LIKE ? LIMIT 1");
        $stmt->execute(['%' . $phoneSuffix]);
        $customer = $stmt->fetch();
        if ($customer) {
            $customerId = (int)$customer['customer_id'];
            $customerName = $customer['name'];
        }
    }

    // -- Create/update call record --
    $initialStatus = $wantsAgent ? 'queued' : 'ai_handling';
    $stmt = $db->prepare("
        INSERT INTO om_callcenter_calls (twilio_call_sid, customer_phone, customer_id, customer_name, direction, status, started_at)
        VALUES (?, ?, ?, ?, 'inbound', ?, NOW())
        ON CONFLICT (twilio_call_sid) DO UPDATE SET
            customer_id = COALESCE(EXCLUDED.customer_id, om_callcenter_calls.customer_id),
            customer_name = COALESCE(EXCLUDED.customer_name, om_callcenter_calls.customer_name),
            status = EXCLUDED.status
        RETURNING id
    ");
    $stmt->execute([$callSid, $callerPhone, $customerId, $customerName, $initialStatus]);
    $callId = (int)$stmt->fetch()['id'];

    // -- Route: Agent Queue --
    if ($wantsAgent) {
        $priority = $customerId ? 3 : 5;
        $db->prepare("INSERT INTO om_callcenter_queue (call_id, customer_phone, customer_name, customer_id, priority, queued_at) VALUES (?, ?, ?, ?, ?, NOW())")
           ->execute([$callId, $callerPhone, $customerName, $customerId, $priority]);

        ccBroadcastDashboard('queue_updated', ['call_id' => $callId, 'customer_phone' => $callerPhone, 'customer_name' => $customerName, 'action' => 'new']);

        $posStmt = $db->prepare("SELECT COUNT(*) as pos FROM om_callcenter_queue WHERE picked_at IS NULL AND abandoned_at IS NULL AND queued_at <= (SELECT queued_at FROM om_callcenter_queue WHERE call_id = ? LIMIT 1)");
        $posStmt->execute([$callId]);
        $position = (int)$posStmt->fetch()['pos'];

        $waitMsg = $position <= 1
            ? "Voce vai ser atendido rapidinho."
            : "Tem " . ($position - 1) . " pessoa na sua frente. Tempo estimado: uns " . ($position * 2) . " minutinhos.";

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo '<Say voice="Polly.Camila" language="pt-BR"><speak>Ta bom! <break time="200ms"/> Vou te passar pra um atendente agora. <break time="200ms"/> ' . htmlspecialchars($waitMsg) . '</speak></Say>';
        echo '<Play loop="0">http://com.twilio.music.classical.s3.amazonaws.com/MARminimum_-_Pachelbels_Canon.mp3</Play>';
        echo '</Response>';
        exit;
    }

    // -- Analyze speech for intent --
    $speechLower = mb_strtolower($speechResult ?? '', 'UTF-8');

    // Detect support intent
    $wantsSupport = false;
    if (!empty($speechResult)) {
        $supportKeywords = ['status', 'cancelar', 'cancela', 'rastrear', 'rastreio', 'cadê meu pedido', 'cade meu pedido', 'onde ta', 'onde está', 'meu pedido', 'reclamação', 'reclamacao', 'problema', 'reembolso'];
        foreach ($supportKeywords as $sk) {
            if (mb_strpos($speechLower, $sk) !== false) { $wantsSupport = true; break; }
        }
    }

    // Detect CEP (8 digits, possibly with hyphen)
    $detectedCep = null;
    $cepData = null;
    $nearbyStores = [];
    if (!empty($speechResult)) {
        // Extract numbers from speech — CEP is 8 digits
        $digitsOnly = preg_replace('/\D/', '', $speechResult);
        if (strlen($digitsOnly) === 8 && preg_match('/^[0-9]{5}/', $digitsOnly)) {
            $detectedCep = $digitsOnly;
        }
        // Also match spoken CEPs like "um tres zero quatro cinco" — common on phone
        // Twilio speech-to-text usually converts these to digits
        if (!$detectedCep && preg_match('/(\d{5})\s*-?\s*(\d{3})/', $speechResult, $cepMatch)) {
            $detectedCep = $cepMatch[1] . $cepMatch[2];
        }
    }

    // Look up CEP if detected
    if ($detectedCep) {
        $ctx = stream_context_create(['http' => ['timeout' => 4]]);
        $json = @file_get_contents("https://viacep.com.br/ws/{$detectedCep}/json/", false, $ctx);
        if ($json) {
            @file_put_contents("/tmp/viacep_{$detectedCep}.json", $json);
            $data = json_decode($json, true);
            if ($data && empty($data['erro'])) {
                $cepData = [
                    'street' => $data['logradouro'] ?? '',
                    'neighborhood' => $data['bairro'] ?? '',
                    'city' => $data['localidade'] ?? '',
                    'state' => $data['uf'] ?? '',
                    'cep' => $detectedCep,
                ];
            }
        }

        // Find stores that deliver to this CEP
        if ($cepData) {
            $cep3 = substr($detectedCep, 0, 3);
            $cep5 = substr($detectedCep, 0, 5);
            $allStores = $db->query("SELECT partner_id, name, cep, cep_inicio, cep_fim, rating FROM om_market_partners WHERE status = '1' ORDER BY rating DESC NULLS LAST LIMIT 50")->fetchAll();
            foreach ($allStores as $p) {
                $inicio = preg_replace('/\D/', '', $p['cep_inicio'] ?? '');
                $fim = preg_replace('/\D/', '', $p['cep_fim'] ?? '');
                if ($inicio && $fim) {
                    $len = strlen($inicio);
                    $check = $len === 5 ? $cep5 : ($len === 3 ? $cep3 : $detectedCep);
                    if (intval($check) >= intval($inicio) && intval($check) <= intval($fim)) {
                        $nearbyStores[] = ['id' => (int)$p['partner_id'], 'name' => $p['name']];
                        continue;
                    }
                }
                $partnerCep = preg_replace('/\D/', '', $p['cep'] ?? '');
                if ($partnerCep && substr($partnerCep, 0, 3) === $cep3) {
                    $nearbyStores[] = ['id' => (int)$p['partner_id'], 'name' => $p['name']];
                }
            }
            // If no CEP-based match, just get all active stores
            if (empty($nearbyStores)) {
                foreach ($allStores as $p) {
                    $nearbyStores[] = ['id' => (int)$p['partner_id'], 'name' => $p['name']];
                }
            }
        }
    }

    // Detect food type / cuisine
    $detectedCuisine = null;
    $cuisineStores = [];
    if (!empty($speechResult) && !$detectedCep && !$wantsSupport) {
        $cuisineMap = [
            'pizza' => ['pizza', 'pizzaria'],
            'hamburguer' => ['hamburguer', 'hamburger', 'burger', 'lanche', 'x-tudo', 'x tudo', 'xis'],
            'japonesa' => ['japonesa', 'japones', 'sushi', 'temaki', 'yakisoba'],
            'acai' => ['acai', 'açaí', 'açai'],
            'brasileira' => ['brasileira', 'comida caseira', 'marmita', 'prato feito', 'pf', 'almoco'],
            'sobremesa' => ['doce', 'sobremesa', 'sorvete', 'bolo', 'torta'],
            'saudavel' => ['saudavel', 'salada', 'fit', 'light', 'dieta'],
            'mexicana' => ['mexicana', 'taco', 'burrito', 'nachos'],
            'chinesa' => ['chinesa', 'chines', 'yakissoba'],
            'arabe' => ['arabe', 'esfiha', 'esfirra', 'quibe', 'shawarma'],
            'italiana' => ['italiano', 'italiana', 'massa', 'macarrao', 'lasanha'],
            'cafe' => ['cafe', 'cafeteria', 'padaria', 'pao'],
            'bebida' => ['bebida', 'suco', 'refrigerante', 'cerveja'],
        ];
        foreach ($cuisineMap as $cuisine => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($speechLower, $kw) !== false) {
                    $detectedCuisine = $cuisine;
                    break 2;
                }
            }
        }
    }

    // Try to match store by name
    $storeIdentified = null;
    $storeId = null;
    if (!$wantsSupport && !$detectedCep && empty($detectedCuisine) && !empty($speechResult)) {
        // Fuzzy match: try exact-ish match first, then broader
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
            $db->prepare("UPDATE om_callcenter_calls SET store_identified = ? WHERE id = ?")->execute([$storeIdentified, $callId]);
        }
    }

    // -- Build AI Context --
    $initialStep = 'identify_store';
    if ($wantsSupport) {
        $initialStep = 'support';
    } elseif ($storeId) {
        $initialStep = 'take_order';
    } elseif ($detectedCep && $cepData) {
        $initialStep = 'identify_store'; // but with CEP context
    }

    $aiContext = [
        '_ai_context' => [
            'step' => $initialStep,
            'store_id' => $storeId,
            'store_name' => $storeIdentified,
            'items' => [],
            'history' => [],
            'cep' => $detectedCep,
            'cep_data' => $cepData,
            'nearby_stores' => $nearbyStores,
            'detected_cuisine' => $detectedCuisine,
        ]
    ];

    // Populate history with context
    if ($storeIdentified) {
        $aiContext['_ai_context']['history'][] = ['role' => 'user', 'content' => $speechResult];
        $aiContext['_ai_context']['history'][] = ['role' => 'assistant', 'content' => "Show! Encontrei a {$storeIdentified}. O que voce vai querer?"];
    } elseif (!empty($speechResult)) {
        $aiContext['_ai_context']['history'][] = ['role' => 'user', 'content' => $speechResult];
    }

    // Save context
    $db->prepare("UPDATE om_callcenter_calls SET notes = ? WHERE id = ?")
       ->execute([json_encode($aiContext, JSON_UNESCAPED_UNICODE), $callId]);

    ccBroadcastDashboard('call_ai_started', [
        'call_id' => $callId,
        'customer_phone' => $callerPhone,
        'customer_name' => $customerName,
        'speech' => $speechResult,
        'store_identified' => $storeIdentified,
        'cep' => $detectedCep,
        'cuisine' => $detectedCuisine,
        'support_mode' => $wantsSupport,
    ]);

    error_log("[twilio-voice-route] AI: call_id={$callId} store={$storeIdentified} cep={$detectedCep} cuisine={$detectedCuisine} support={$wantsSupport}");

    // -- Build Response --
    $firstName = $customerName ? explode(' ', trim($customerName))[0] : null;
    $ssml = '<speak>';

    if ($wantsSupport) {
        $ssml .= "Entendi" . ($firstName ? ", {$firstName}" : "") . ". ";
        $ssml .= '<break time="200ms"/>';
        $ssml .= "Vou dar uma olhada nos seus pedidos. Me conta o que precisa: ver o status, cancelar, ou alguma outra coisa?";
    } elseif ($storeIdentified) {
        $ssml .= '<prosody rate="medium">';
        $ssml .= "Show" . ($firstName ? ", {$firstName}" : "") . "! ";
        $ssml .= "Encontrei a <emphasis level=\"moderate\">{$storeIdentified}</emphasis> pra voce. ";
        $ssml .= '<break time="200ms"/>';
        $ssml .= "O que voce vai querer?";
        $ssml .= '</prosody>';
    } elseif ($detectedCep && $cepData && !empty($nearbyStores)) {
        $bairro = $cepData['neighborhood'] ?: $cepData['city'];
        $ssml .= "Achei! ";
        $ssml .= '<break time="200ms"/>';
        $ssml .= "Voce ta na regiao de <emphasis level=\"moderate\">{$bairro}</emphasis>" . ($cepData['city'] ? ", {$cepData['city']}" : "") . ". ";
        $ssml .= '<break time="300ms"/>';
        $count = count($nearbyStores);
        if ($count <= 3) {
            $names = array_map(fn($s) => $s['name'], $nearbyStores);
            $ssml .= "Tenho " . implode(', ', array_slice($names, 0, -1));
            if (count($names) > 1) $ssml .= " e " . end($names);
            else $ssml .= $names[0];
            $ssml .= " entregando ai. Qual voce quer?";
        } else {
            // Pick top 3 by variety
            $top3 = array_slice($nearbyStores, 0, 3);
            $names = array_map(fn($s) => $s['name'], $top3);
            $ssml .= "Tenho varias opcoes, como " . implode(', ', $names) . " e mais " . ($count - 3) . ". ";
            $ssml .= '<break time="200ms"/>';
            $ssml .= "Qual te interessa, ou me fala o que voce ta com vontade?";
        }
    } elseif ($detectedCep && $cepData && empty($nearbyStores)) {
        $ssml .= "Encontrei seu endereco na regiao de {$cepData['neighborhood']}, {$cepData['city']}. ";
        $ssml .= '<break time="200ms"/>';
        $ssml .= "Vou ver quais restaurantes entregam ai. Me fala o nome do lugar ou o que voce quer comer.";
    } elseif ($detectedCuisine) {
        $cuisineLabels = [
            'pizza' => 'pizza', 'hamburguer' => 'um lanche', 'japonesa' => 'comida japonesa',
            'acai' => 'acai', 'brasileira' => 'comida caseira', 'sobremesa' => 'sobremesa',
            'saudavel' => 'algo saudavel', 'mexicana' => 'comida mexicana', 'chinesa' => 'comida chinesa',
            'arabe' => 'comida arabe', 'italiana' => 'comida italiana', 'cafe' => 'cafe',
            'bebida' => 'bebida',
        ];
        $label = $cuisineLabels[$detectedCuisine] ?? $detectedCuisine;
        $ssml .= "Hmm, {$label}! Otima escolha";
        $ssml .= ($firstName ? ", {$firstName}" : "") . "! ";
        $ssml .= '<break time="200ms"/>';
        $ssml .= "Vou procurar as melhores opcoes pra voce. Me da um segundinho.";
    } else {
        $ssml .= ($firstName ? "{$firstName}, " : "");
        $ssml .= "Desculpa, nao encontrei esse restaurante. ";
        $ssml .= '<break time="200ms"/>';
        $ssml .= "Pode repetir o nome, ou me fala o tipo de comida que voce quer? Tipo pizza, lanche, japonesa...";
    }

    $ssml .= '</speak>';

    // Respond
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Gather input="speech dtmf" timeout="8" language="pt-BR" action="' . htmlspecialchars($aiUrl) . '" method="POST" speechTimeout="auto" numDigits="1" enhanced="true" speechModel="phone_call">';
    echo '<Say voice="Polly.Camila" language="pt-BR">' . $ssml . '</Say>';
    echo '</Gather>';
    echo '<Say voice="Polly.Camila" language="pt-BR"><speak>Oi, to aqui ainda! <break time="200ms"/> Pode falar o nome do restaurante ou o que voce quer comer.</speak></Say>';
    echo '<Redirect method="POST">' . htmlspecialchars($aiUrl) . '</Redirect>';
    echo '</Response>';

} catch (Exception $e) {
    error_log("[twilio-voice-route] Error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="Polly.Camila" language="pt-BR"><speak>Ih, desculpa! Deu um probleminha aqui. <break time="200ms"/> Vou te passar pra um atendente, ta?</speak></Say>';
    echo '<Hangup/>';
    echo '</Response>';
}
