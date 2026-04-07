<?php
/**
 * POST /api/mercado/customer/voice-order.php
 *
 * Voice ordering: receive audio, transcribe with OpenAI Whisper, then parse intent
 * with Claude. Returns a structured order suggestion the app can preview before
 * confirming.
 *
 * Body:
 *   - audio: multipart file (mp3/m4a/wav/webm, max 25MB)
 *   - latitude, longitude (optional, for store filtering)
 *
 * Response:
 *   {
 *     transcript: "Quero uma pizza margherita grande",
 *     intent: "order",
 *     confidence: 0.92,
 *     suggested_items: [{ product_id, name, partner_id, partner_name, qty, price, image }],
 *     suggested_partners: [{ partner_id, name, distance }],
 *     follow_up: "Confirma o pedido?"
 *   }
 *
 * Requires: OPENAI_API_KEY env var.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $customerId = requireCustomerAuth();
    $db = getDB();

    if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        response(false, null, 'Arquivo de audio obrigatorio', 400);
    }
    $audio = $_FILES['audio'];
    if ($audio['size'] > 25 * 1024 * 1024) {
        response(false, null, 'Arquivo muito grande (max 25MB)', 413);
    }

    $lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;

    // ============= 1) Transcribe with Groq Whisper Large V3 Turbo (5x faster than OpenAI) =============
    $groqKey = $_ENV['GROQ_API_KEY'] ?? getenv('GROQ_API_KEY') ?: '';
    $openaiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';
    if (!$groqKey && !$openaiKey) {
        response(false, null, 'Servico de voz nao configurado', 503);
    }

    $useGroq = (bool)$groqKey;
    $endpoint = $useGroq
        ? 'https://api.groq.com/openai/v1/audio/transcriptions'
        : 'https://api.openai.com/v1/audio/transcriptions';
    $authKey = $useGroq ? $groqKey : $openaiKey;
    $modelName = $useGroq ? 'whisper-large-v3-turbo' : 'whisper-1';

    $cfile = new CURLFile($audio['tmp_name'], $audio['type'] ?: 'audio/mpeg', $audio['name'] ?: 'audio.mp3');
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $authKey],
        CURLOPT_POSTFIELDS => [
            'file' => $cfile,
            'model' => $modelName,
            'language' => 'pt',
            'response_format' => 'json',
            'temperature' => '0',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        error_log('[voice-order] transcribe failed: provider=' . ($useGroq ? 'groq' : 'openai') . ' code=' . $code . ' / ' . substr($resp ?: '', 0, 200));
        // Fall back to OpenAI if Groq failed
        if ($useGroq && $openaiKey) {
            $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $openaiKey],
                CURLOPT_POSTFIELDS => [
                    'file' => new CURLFile($audio['tmp_name'], $audio['type'] ?: 'audio/mpeg', $audio['name'] ?: 'audio.mp3'),
                    'model' => 'whisper-1',
                    'language' => 'pt',
                    'response_format' => 'json',
                ],
                CURLOPT_TIMEOUT => 30,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        if ($code !== 200 || !$resp) {
            response(false, null, 'Falha ao transcrever audio', 502);
        }
    }
    $whisper = json_decode($resp, true);
    $transcript = trim($whisper['text'] ?? '');
    if ($transcript === '') {
        response(false, null, 'Nao consegui entender o audio', 422);
    }

    // ============= 2) Parse intent with Claude =============
    $candidatePartners = [];
    if ($lat !== null && $lng !== null) {
        $stmt = $db->prepare(
            "SELECT partner_id, name, categoria,
                    (6371 * acos(cos(radians(:lat)) * cos(radians(latitude)) *
                     cos(radians(longitude) - radians(:lng)) +
                     sin(radians(:lat)) * sin(radians(latitude)))) AS distance_km
             FROM om_market_partners
             WHERE status::text = '1' AND latitude IS NOT NULL AND longitude IS NOT NULL
             ORDER BY distance_km ASC LIMIT 15"
        );
        $stmt->execute([':lat' => $lat, ':lng' => $lng]);
        $candidatePartners = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $partnersJson = json_encode(array_map(fn($p) => [
        'id' => (int)$p['partner_id'],
        'name' => $p['name'],
        'category' => $p['categoria'] ?? null,
    ], $candidatePartners));

    $systemPrompt = "Voce e o assistente de pedidos por voz do SuperBora delivery. " .
                    "O usuario falou algo, voce decide a intencao e propoe um pedido. " .
                    "Responda APENAS JSON valido com este shape: " .
                    '{"intent":"order|search|question|other","confidence":0.0-1.0,' .
                    '"keywords":["palavras","chave"],"suggested_partner_ids":[1,2],' .
                    '"items":[{"name":"...","qty":1,"notes":"opcional"}],"follow_up":"pergunta de confirmacao"}';

    $userPrompt = "Transcricao: \"$transcript\"\n\nLojas proximas (top 15): $partnersJson\n\n" .
                  "Decida se isso eh um pedido. Se sim, escolha 1-2 lojas mais relevantes pela categoria " .
                  "e sugira ate 5 itens. Se for so uma duvida ou busca, marque intent diferente.";

    try {
        $claude = ClaudeClient::getInstance();
        $aiResponse = $claude->send([
            ['role' => 'user', 'content' => $userPrompt],
        ], [
            'system' => $systemPrompt,
            'max_tokens' => 800,
            'model' => 'claude-haiku-4-5-20251001',
        ]);
        $parsed = $claude->parseJson($aiResponse) ?: [];
    } catch (Exception $e) {
        error_log('[voice-order] claude failed: ' . $e->getMessage());
        $parsed = [
            'intent' => 'order',
            'confidence' => 0.4,
            'keywords' => array_slice(array_filter(explode(' ', strtolower($transcript))), 0, 5),
            'items' => [],
            'follow_up' => 'Posso ajudar com o que voce disse?',
        ];
    }

    // ============= 3) Look up real product matches for the suggested items =============
    $suggestedItems = [];
    $items = $parsed['items'] ?? [];
    $partnerIds = array_filter(array_map('intval', $parsed['suggested_partner_ids'] ?? []));

    if (!empty($items)) {
        foreach (array_slice($items, 0, 5) as $it) {
            $name = trim($it['name'] ?? '');
            $qty = max(1, (int)($it['qty'] ?? 1));
            if ($name === '') continue;

            $like = '%' . strtolower($name) . '%';
            $partnerFilter = !empty($partnerIds) ? 'AND p.partner_id = ANY(:pids)' : '';
            $sql = "SELECT p.product_id, p.name, p.price, p.image, p.partner_id,
                           pa.name AS partner_name, pa.logo AS partner_logo
                    FROM om_market_products p
                    JOIN om_market_partners pa ON p.partner_id = pa.partner_id
                    WHERE p.status::text = '1' AND pa.status::text = '1'
                      AND LOWER(p.name) LIKE :q $partnerFilter
                    ORDER BY p.price ASC LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':q', $like, PDO::PARAM_STR);
            if (!empty($partnerIds)) {
                $stmt->bindValue(':pids', '{' . implode(',', $partnerIds) . '}', PDO::PARAM_STR);
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $suggestedItems[] = [
                    'product_id' => (int)$row['product_id'],
                    'name' => $row['name'],
                    'price' => (float)$row['price'],
                    'qty' => $qty,
                    'image' => $row['image'],
                    'partner_id' => (int)$row['partner_id'],
                    'partner_name' => $row['partner_name'],
                    'partner_logo' => $row['partner_logo'],
                    'notes' => $it['notes'] ?? null,
                    'matched_query' => $name,
                ];
            }
        }
    }

    // Suggested partners
    $suggestedPartners = [];
    foreach ($candidatePartners as $p) {
        if (empty($partnerIds) || in_array((int)$p['partner_id'], $partnerIds, true)) {
            $suggestedPartners[] = [
                'partner_id' => (int)$p['partner_id'],
                'name' => $p['name'],
                'distance_km' => round((float)$p['distance_km'], 2),
            ];
            if (count($suggestedPartners) >= 3) break;
        }
    }

    // Log for analytics
    try {
        $db->prepare("INSERT INTO om_voice_order_log (customer_id, transcript, intent, confidence, items_count, created_at) VALUES (:cid, :t, :i, :conf, :ic, NOW())")
            ->execute([
                ':cid' => $customerId,
                ':t' => $transcript,
                ':i' => $parsed['intent'] ?? 'unknown',
                ':conf' => (float)($parsed['confidence'] ?? 0),
                ':ic' => count($suggestedItems),
            ]);
    } catch (Exception $e) { /* table may not exist yet */ }

    response(true, [
        'transcript' => $transcript,
        'intent' => $parsed['intent'] ?? 'order',
        'confidence' => (float)($parsed['confidence'] ?? 0),
        'keywords' => $parsed['keywords'] ?? [],
        'suggested_items' => $suggestedItems,
        'suggested_partners' => $suggestedPartners,
        'follow_up' => $parsed['follow_up'] ?? 'Confirma o pedido?',
    ]);
} catch (Exception $e) {
    error_log('[voice-order] ' . $e->getMessage());
    response(false, null, 'Erro ao processar voz', 500);
}
