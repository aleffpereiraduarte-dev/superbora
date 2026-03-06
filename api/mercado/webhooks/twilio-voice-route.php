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

header('Content-Type: text/xml; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/ws-callcenter-broadcast.php';
require_once __DIR__ . '/../helpers/voice-tts.php';

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
$callerPhone = $_POST['From'] ?? $_GET['From'] ?? '';
$callSid = $_POST['CallSid'] ?? $_GET['CallSid'] ?? '';
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

// Handle DTMF shortcuts: 1=order, 2=status
if ($digits === '1' && empty($speechResult)) {
    $speechResult = 'quero fazer um pedido';
    $wantsAgent = false;
} elseif ($digits === '2' && empty($speechResult)) {
    $speechResult = 'quero ver meu pedido';
    $wantsAgent = false;
}

// If digits look like a CEP (8 digits), treat as speech for CEP detection
if (strlen($digits) >= 5 && strlen($digits) <= 8 && ctype_digit($digits) && $digits !== '0') {
    // User typed a CEP or partial CEP on keypad — inject as speech
    if (empty($speechResult)) {
        $speechResult = $digits;
    }
    $wantsAgent = false; // definitely not wanting an agent
}
if (!empty($speechResult)) {
    $speechLower = mb_strtolower($speechResult, 'UTF-8');
    $agentKeywords = ['atendente', 'agente', 'pessoa', 'humano', 'operador', 'falar com alguem', 'falar com gente'];
    foreach ($agentKeywords as $keyword) {
        if (mb_strpos($speechLower, $keyword) !== false) { $wantsAgent = true; break; }
    }
}
// Only transfer to agent on explicit noInput if there was truly no interaction at all
// Don't auto-transfer — let AI handle the re-prompt
if ($noInput === '1' && empty($digits) && empty($speechResult)) {
    // Instead of transferring to agent, redirect to AI which will re-prompt naturally
    $wantsAgent = false;
}

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
            ? "Você vai ser atendido rapidinho."
            : "Tem " . ($position - 1) . " pessoa na sua frente. Tempo estimado: uns " . ($position * 2) . " minutinhos.";

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<Response>';
        echo ttsSayOrPlay('Tá bom! Vou te passar pra um atendente agora. ' . $waitMsg);
        echo '<Play loop="0">http://com.twilio.music.pop-rock.s3.amazonaws.com/Ab0-3.mp3</Play>';
        echo '</Response>';
        exit;
    }

    // -- Check for active orders early (needed for intent routing) --
    $hasActiveOrders = false;
    $activeOrderInfo = null;
    if ($customerId) {
        try {
            $activeStmt = $db->prepare("
                SELECT o.order_number, o.status, p.name as partner_name
                FROM om_market_orders o
                JOIN om_market_partners p ON p.partner_id = o.partner_id
                WHERE o.customer_id = ? AND o.status IN ('pending','accepted','preparing','ready','delivering','em_preparo','saiu_entrega')
                ORDER BY o.date_added DESC LIMIT 1
            ");
            $activeStmt->execute([$customerId]);
            $activeOrder = $activeStmt->fetch();
            if ($activeOrder) {
                $hasActiveOrders = true;
                $activeOrderInfo = $activeOrder;
            }
        } catch (Exception $e) {}
    }

    // -- Analyze speech for intent (structured keyword detection BEFORE Claude) --
    $speechLower = mb_strtolower($speechResult ?? '', 'UTF-8');

    // Detect intents via keyword matching — fast path, no AI needed
    $wantsSupport = false;
    $wantsQuestion = false;
    $wantsOrder = false;
    $wantsComplaint = false;

    if (!empty($speechResult)) {
        // 1. Order status / tracking — "status"/"pedido"/"onde está"
        $statusKeywords = ['status', 'rastrear', 'rastreio', 'cadê meu pedido', 'cade meu pedido',
                           'onde ta meu', 'onde está meu', 'onde esta meu', 'meu pedido', 'acompanhar'];
        foreach ($statusKeywords as $sk) {
            if (mb_strpos($speechLower, $sk) !== false) { $wantsSupport = true; break; }
        }

        // 2. Cancellation — "cancelar"/"cancela"
        if (!$wantsSupport) {
            $cancelKeywords = ['cancelar', 'cancela', 'cancelamento', 'cancele'];
            foreach ($cancelKeywords as $ck) {
                if (mb_strpos($speechLower, $ck) !== false) { $wantsSupport = true; break; }
            }
        }

        // 3. Complaint — "reclamar"/"problema"/"errado" → complaint flow → transfer hint
        if (!$wantsSupport) {
            $complaintKeywords = ['reclamar', 'reclamação', 'reclamacao', 'problema', 'errado',
                                   'veio errado', 'faltou', 'cobraram errado', 'estorno', 'reembolso',
                                   'devolver', 'nao chegou', 'não chegou', 'comida fria', 'atrasado',
                                   'pedido atrasado', 'nunca chegou'];
            foreach ($complaintKeywords as $ck) {
                if (mb_strpos($speechLower, $ck) !== false) { $wantsComplaint = true; $wantsSupport = true; break; }
            }
        }

        // 4. Questions / info
        if (!$wantsSupport) {
            $questionKeywords = ['duvida', 'dúvida', 'pergunta', 'saber', 'informação', 'informacao',
                                  'horario', 'horário', 'funciona', 'entrega', 'taxa', 'preco', 'preço',
                                  'quanto custa', 'como funciona', 'ajuda', 'como faz'];
            foreach ($questionKeywords as $qk) {
                if (mb_strpos($speechLower, $qk) !== false) { $wantsQuestion = true; break; }
            }
        }

        // 5. Explicit ordering intent
        $orderKeywords = ['quero pedir', 'fazer pedido', 'fazer um pedido', 'pedir comida',
                           'quero comer', 'to com fome', 'tô com fome', 'faz um pedido',
                           'quero encomendar', 'bora pedir'];
        foreach ($orderKeywords as $ok) {
            if (mb_strpos($speechLower, $ok) !== false) { $wantsOrder = true; break; }
        }
    }

    // Detect CEP (8 digits, possibly with hyphen)
    $detectedCep = null;
    $cepData = null;
    $nearbyStores = [];

    // Check if this is a CEP processing redirect (we already said "um minutinho")
    $cepProcessing = $_GET['cep_processing'] ?? $_POST['cep_processing'] ?? '';
    $cepFromRedirect = $_GET['cep'] ?? $_POST['cep'] ?? '';
    if ($cepProcessing === '1' && !empty($cepFromRedirect)) {
        $detectedCep = preg_replace('/\D/', '', $cepFromRedirect);
    }

    if (!$detectedCep && !empty($speechResult)) {
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

        // If CEP detected from speech (not from redirect), say "um minutinho" and redirect
        if ($detectedCep && $cepProcessing !== '1') {
            $redirectUrl = $selfUrl . '?cep_processing=1&cep=' . urlencode($detectedCep);
            // Carry forward POST params via query string
            if ($callSid) $redirectUrl .= '&CallSid=' . urlencode($callSid);
            if ($callerPhone) $redirectUrl .= '&From=' . urlencode($callerPhone);

            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Response>';
            echo ttsSayOrPlay('Um minutinho, tô buscando os restaurantes perto de você!');
            echo '<Redirect method="POST">' . htmlspecialchars($redirectUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Redirect>';
            echo '</Response>';
            exit;
        }
    }

    // Look up CEP if detected (either from speech processing or redirect)
    if ($detectedCep) {
        // Try ViaCEP with short timeout
        $ctx = stream_context_create(['http' => ['timeout' => 3], 'ssl' => ['verify_peer' => false]]);
        $json = @file_get_contents("https://viacep.com.br/ws/{$detectedCep}/json/", false, $ctx);
        if ($json) {
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

        // If ViaCEP failed or CEP not found, try with 000 suffix (user may have said 5 digits)
        if (!$cepData && strlen($detectedCep) === 8) {
            $cepBase = substr($detectedCep, 0, 5) . '000';
            if ($cepBase !== $detectedCep) {
                $json2 = @file_get_contents("https://viacep.com.br/ws/{$cepBase}/json/", false, $ctx);
                if ($json2) {
                    $data2 = json_decode($json2, true);
                    if ($data2 && empty($data2['erro'])) {
                        $cepData = [
                            'street' => '',
                            'neighborhood' => $data2['bairro'] ?? '',
                            'city' => $data2['localidade'] ?? '',
                            'state' => $data2['uf'] ?? '',
                            'cep' => $detectedCep,
                        ];
                    }
                }
            }
        }

        // Find stores that deliver to this CEP
        $cep3 = substr($detectedCep, 0, 3);
        $cep5 = substr($detectedCep, 0, 5);
        $allStores = $db->query("SELECT partner_id, name, cep, cep_inicio, cep_fim, city, rating FROM om_market_partners WHERE status = '1' AND name != '' ORDER BY rating DESC NULLS LAST LIMIT 50")->fetchAll();

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
            // Match by CEP prefix (first 3 digits = same city area)
            $partnerCep = preg_replace('/\D/', '', $p['cep'] ?? '');
            if ($partnerCep && substr($partnerCep, 0, 3) === $cep3) {
                $nearbyStores[] = ['id' => (int)$p['partner_id'], 'name' => $p['name']];
                continue;
            }
            // Match by city name if ViaCEP resolved
            if ($cepData && !empty($cepData['city']) && !empty($p['city'])) {
                $cepCity = mb_strtolower(trim($cepData['city']), 'UTF-8');
                $storeCity = mb_strtolower(trim($p['city']), 'UTF-8');
                if ($cepCity === $storeCity || mb_strpos($cepCity, $storeCity) !== false || mb_strpos($storeCity, $cepCity) !== false) {
                    $nearbyStores[] = ['id' => (int)$p['partner_id'], 'name' => $p['name']];
                }
            }
        }

        // If still no match, show all active stores as options
        if (empty($nearbyStores)) {
            foreach ($allStores as $p) {
                if (!empty(trim($p['name']))) {
                    $nearbyStores[] = ['id' => (int)$p['partner_id'], 'name' => $p['name']];
                }
            }
        }

        error_log("[twilio-voice-route] CEP={$detectedCep} cepData=" . ($cepData ? $cepData['city'] : 'null') . " nearbyStores=" . count($nearbyStores));
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

    // Try to match store by name — multi-strategy fuzzy matching
    $storeIdentified = null;
    $storeId = null;
    if (!$wantsSupport && !$detectedCep && empty($detectedCuisine) && !empty($speechResult)) {
        // Strategy 1: Exact substring match (fastest)
        $storeStmt = $db->prepare("
            SELECT partner_id, name FROM om_market_partners
            WHERE status = '1' AND LOWER(name) LIKE ?
            ORDER BY LENGTH(name) ASC LIMIT 1
        ");
        $storeStmt->execute(['%' . $speechLower . '%']);
        $store = $storeStmt->fetch();

        // Strategy 2: Word-by-word fuzzy match (for misspellings like "pizaria bela" → "Pizzaria Bella")
        if (!$store) {
            $allStoresForMatch = $db->query("SELECT partner_id, name FROM om_market_partners WHERE status = '1' AND name != '' LIMIT 50")->fetchAll();
            $speechWords = preg_split('/\s+/', $speechLower);
            $bestMatch = null;
            $bestScore = 0;

            foreach ($allStoresForMatch as $s) {
                $storeLower = mb_strtolower($s['name'], 'UTF-8');
                $storeWords = preg_split('/\s+/', $storeLower);
                $score = 0;

                foreach ($speechWords as $sw) {
                    if (mb_strlen($sw) < 3) continue; // Skip short words
                    foreach ($storeWords as $stw) {
                        if (mb_strlen($stw) < 3) continue;
                        // Exact word match
                        if ($sw === $stw) { $score += 3; continue 2; }
                        // Word starts with same 3+ chars
                        $prefix = mb_substr($sw, 0, 3);
                        if (mb_strpos($stw, $prefix) === 0 || mb_strpos($sw, mb_substr($stw, 0, 3)) === 0) {
                            $score += 2;
                            continue 2;
                        }
                        // Levenshtein-like: similar enough (within 2 edits for words > 4 chars)
                        if (mb_strlen($sw) > 4 && mb_strlen($stw) > 4) {
                            $lev = levenshtein($sw, $stw);
                            if ($lev <= 2) { $score += 2; continue 2; }
                        }
                    }
                }

                // Need at least 2 points to be a match (1 good word match)
                if ($score > $bestScore && $score >= 2) {
                    $bestScore = $score;
                    $bestMatch = $s;
                }
            }

            if ($bestMatch) {
                $store = $bestMatch;
            }
        }

        if ($store) {
            $storeIdentified = $store['name'];
            $storeId = (int)$store['partner_id'];
            $db->prepare("UPDATE om_callcenter_calls SET store_identified = ? WHERE id = ?")->execute([$storeIdentified, $callId]);
        }
    }

    // -- Build AI Context --
    $initialStep = 'identify_store';
    if ($wantsComplaint) {
        $initialStep = 'support'; // complaint → support mode with transfer hint
    } elseif ($wantsSupport) {
        $initialStep = 'support';
    } elseif ($wantsQuestion) {
        $initialStep = 'question';
    } elseif ($storeId) {
        $initialStep = 'take_order';
    } elseif ($detectedCep && !empty($nearbyStores)) {
        $initialStep = 'identify_store'; // with CEP context
    } elseif (!empty($wantsOrder)) {
        $initialStep = 'identify_store'; // wants to order, need store/CEP
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
            'wants_order' => !empty($wantsOrder),
            'wants_question' => $wantsQuestion,
            'wants_complaint' => $wantsComplaint,
            'has_active_orders' => $hasActiveOrders,
            'active_order' => $activeOrderInfo ? [
                'order_number' => $activeOrderInfo['order_number'],
                'status' => $activeOrderInfo['status'],
                'partner_name' => $activeOrderInfo['partner_name'],
            ] : null,
        ]
    ];

    // Populate history with context
    if ($storeIdentified) {
        $aiContext['_ai_context']['history'][] = ['role' => 'user', 'content' => $speechResult];
        $aiContext['_ai_context']['history'][] = ['role' => 'assistant', 'content' => "Show! Encontrei a {$storeIdentified}. O que você vai querer?"];
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

    error_log("[twilio-voice-route] AI: call_id={$callId} store={$storeIdentified} cep={$detectedCep} cuisine={$detectedCuisine} support={$wantsSupport} complaint={$wantsComplaint}");

    // -- Build Response --
    $firstName = $customerName ? explode(' ', trim($customerName))[0] : null;
    $ssml = '';

    if ($wantsComplaint) {
        // Complaint flow — empathetic response + offer transfer
        $ssml .= "Putz" . ($firstName ? ", {$firstName}" : "") . ", sinto muito por isso. ";
        $ssml .= "Vou resolver pra você. Me conta o que aconteceu, ou se preferir, posso te passar pra um atendente agora.";
    } elseif ($wantsSupport) {
        $ssml .= "Entendi" . ($firstName ? ", {$firstName}" : "") . ". ";
        $ssml .= "Vou dar uma olhada nos seus pedidos. Me conta o que precisa: ver o status, cancelar, ou outra coisa?";
    } elseif ($wantsQuestion) {
        $ssml .= "Claro" . ($firstName ? ", {$firstName}" : "") . "! ";
        $ssml .= '<break time="200ms"/>';
        $ssml .= "Tô aqui pra tirar sua dúvida. Pode perguntar, que eu te ajudo!";
    } elseif (!empty($wantsOrder) && !$storeId && !$detectedCep) {
        // Wants to order but hasn't said store or CEP yet
        $ssml .= "Perfeito" . ($firstName ? ", {$firstName}" : "") . "! ";
        if ($hasActiveOrders && $activeOrderInfo) {
            $statusLabels = ['pending' => 'esperando confirmação', 'accepted' => 'foi aceito', 'preparing' => 'tá sendo preparado', 'em_preparo' => 'tá sendo preparado', 'ready' => 'tá pronto', 'delivering' => 'tá a caminho', 'saiu_entrega' => 'tá a caminho'];
            $statusText = $statusLabels[$activeOrderInfo['status']] ?? 'em andamento';
            $ssml .= "Vi que você tem um pedido da {$activeOrderInfo['partner_name']} que {$statusText}. ";
            $ssml .= '<break time="200ms"/>';
            $ssml .= "Quer saber desse pedido, ou fazer um novo?";
        } else {
            $ssml .= "Vamos montar seu pedido. ";
            $ssml .= '<break time="200ms"/>';
            $ssml .= "Me fala o nome do restaurante que você quer, ou se preferir, me diz seu CEP que eu mostro as opções perto de você.";
        }
    } elseif ($storeIdentified) {
        $ssml .= "Show" . ($firstName ? ", {$firstName}" : "") . "! ";
        $ssml .= "Encontrei a {$storeIdentified} pra você. ";
        $ssml .= '<break time="300ms"/>';
        $ssml .= "O que você vai querer?";
    } elseif ($detectedCep && !empty($nearbyStores)) {
        // CEP detected with stores found (cepData may or may not be available)
        if ($cepData && $cepData['neighborhood']) {
            $bairro = $cepData['neighborhood'];
            $ssml .= "Achei! Você tá na região de {$bairro}";
            $ssml .= ($cepData['city'] ? ", {$cepData['city']}" : "") . ". ";
        } elseif ($cepData && $cepData['city']) {
            $ssml .= "Achei! Você tá em {$cepData['city']}. ";
        } else {
            $ssml .= "Anotei seu CEP! ";
        }
        $ssml .= '<break time="300ms"/>';
        $count = count($nearbyStores);
        if ($count <= 3) {
            $names = array_map(fn($s) => $s['name'], $nearbyStores);
            if (count($names) > 1) {
                $ssml .= "Tenho " . implode(', ', array_slice($names, 0, -1)) . " e " . end($names);
            } else {
                $ssml .= "Tenho " . $names[0];
            }
            $ssml .= " entregando aí. Qual você quer?";
        } else {
            $top3 = array_slice($nearbyStores, 0, 3);
            $names = array_map(fn($s) => $s['name'], $top3);
            $ssml .= "Tenho várias opções, como " . implode(', ', $names) . " e mais " . ($count - 3) . ". ";
            $ssml .= '<break time="200ms"/>';
            $ssml .= "Qual te interessa, ou me fala o que você tá com vontade?";
        }
    } elseif ($detectedCep && empty($nearbyStores)) {
        // CEP detected but no stores matched at all — shouldn't happen due to fallback
        $ssml .= "Anotei seu CEP. ";
        $ssml .= '<break time="200ms"/>';
        $ssml .= "Me fala o nome do restaurante ou o tipo de comida que você quer.";
    } elseif ($detectedCuisine) {
        $cuisineLabels = [
            'pizza' => 'pizza', 'hamburguer' => 'um lanche', 'japonesa' => 'comida japonesa',
            'acai' => 'açaí', 'brasileira' => 'comida caseira', 'sobremesa' => 'sobremesa',
            'saudavel' => 'algo saudável', 'mexicana' => 'comida mexicana', 'chinesa' => 'comida chinesa',
            'arabe' => 'comida árabe', 'italiana' => 'comida italiana', 'cafe' => 'café',
            'bebida' => 'bebida',
        ];
        $label = $cuisineLabels[$detectedCuisine] ?? $detectedCuisine;
        $ssml .= "Hmm, {$label}! Ótima escolha";
        $ssml .= ($firstName ? ", {$firstName}" : "") . "! ";
        $ssml .= '<break time="200ms"/>';
        if ($hasActiveOrders && $activeOrderInfo) {
            $statusLabels = ['pending' => 'esperando confirmação', 'accepted' => 'foi aceito', 'preparing' => 'tá sendo preparado', 'em_preparo' => 'tá sendo preparado', 'ready' => 'tá pronto', 'delivering' => 'tá a caminho', 'saiu_entrega' => 'tá a caminho'];
            $statusText = $statusLabels[$activeOrderInfo['status']] ?? 'em andamento';
            $ssml .= "Ah, e vi que você tem um pedido da {$activeOrderInfo['partner_name']} que {$statusText}. ";
            $ssml .= '<break time="200ms"/>';
            $ssml .= "Quer saber sobre esse pedido, ou fazer um pedido novo de {$label}?";
        } else {
            $ssml .= "Vou procurar as melhores opções de {$label} pra você. Me fala seu CEP ou o nome do restaurante que você gosta!";
        }
    } else {
        // No clear match — smart retry with more specific prompts
        $ssml .= ($firstName ? "{$firstName}, " : "");
        if ($hasActiveOrders && $activeOrderInfo && !empty($speechResult)) {
            // Has active order — maybe they're asking about it
            $statusLabels = ['pending' => 'esperando confirmação', 'accepted' => 'foi aceito', 'preparing' => 'tá sendo preparado', 'em_preparo' => 'tá sendo preparado', 'ready' => 'tá pronto', 'delivering' => 'tá a caminho', 'saiu_entrega' => 'tá a caminho'];
            $statusText = $statusLabels[$activeOrderInfo['status']] ?? 'em andamento';
            $ssml .= "vi que você tem um pedido da {$activeOrderInfo['partner_name']} que {$statusText}. ";
            $ssml .= "É sobre esse pedido, ou quer fazer um novo?";
        } elseif (!empty($speechResult) && mb_strlen($speechResult) > 3) {
            // Speech wasn't understood as a known intent — ask again more specifically
            $ssml .= "Hmm, não encontrei um restaurante com esse nome. ";
            $ssml .= "Pode repetir o nome do restaurante, ou me diz o tipo de comida?";
        } elseif (empty($speechResult) && !empty($noInput)) {
            // Timeout — customer didn't speak, offer DTMF options
            $ssml .= "Pode falar ou digitar, tô te escutando! Aperta 1 pra fazer um pedido, 2 pra ver seu pedido, ou zero pra falar com uma pessoa.";
        } else {
            // Very short/empty speech — retry with clarity
            $ssml .= "Não peguei direito. Pode falar o nome do restaurante ou o que você quer comer?";
        }
    }

    // end ssml

    // Strip SSML tags for TTS
    $plainSsml = preg_replace('/<[^>]+>/', ' ', $ssml);
    $plainSsml = preg_replace('/\s+/', ' ', trim($plainSsml));

    $aiUrlEsc = htmlspecialchars($aiUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $gatherAttrs = 'input="speech dtmf" language="pt-BR" speechModel="experimental_utterances" speechTimeout="auto" profanityFilter="false" hints="sim, não, pedido, atendente, cancelar, status, pizza, lanche, um, dois, três, zero"';

    // Respond
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Gather ' . $gatherAttrs . ' timeout="6" action="' . $aiUrlEsc . '" method="POST">';
    echo ttsSayOrPlay($plainSsml);
    echo '</Gather>';
    // Fallback re-prompt with DTMF option for timeout
    echo '<Gather ' . $gatherAttrs . ' timeout="5" action="' . $aiUrlEsc . '" method="POST">';
    echo ttsSayOrPlay("Pode falar ou digitar, tô te escutando! Aperta 1 pra pedir, 2 pra ver pedido, ou zero pra falar com uma pessoa.");
    echo '</Gather>';
    // If both Gathers time out, go to AI anyway (it will re-prompt naturally)
    echo '<Redirect method="POST">' . $aiUrlEsc . '</Redirect>';
    echo '</Response>';

} catch (\Throwable $e) {
    error_log("[twilio-voice-route] FATAL: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine());
    // On error, redirect to AI handler which will re-prompt — don't hang up
    $aiUrlFallback = str_replace('twilio-voice-route.php', 'twilio-voice-ai.php', ($scheme ?? 'https') . '://' . ($host ?? 'superbora.com.br') . strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
    $aiUrlFbEsc = htmlspecialchars($aiUrlFallback, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say language="pt-BR" voice="Polly.Camila">Desculpa, deu um probleminha. Me fala de novo, o que você precisa?</Say>';
    echo '<Gather input="speech dtmf" timeout="6" language="pt-BR" speechModel="experimental_utterances" speechTimeout="auto" action="' . $aiUrlFbEsc . '" method="POST">';
    echo '<Say language="pt-BR" voice="Polly.Camila">Pode falar ou digitar. Aperta zero pra falar com uma pessoa.</Say>';
    echo '</Gather>';
    echo '<Redirect method="POST">' . $aiUrlFbEsc . '</Redirect>';
    echo '</Response>';
}
