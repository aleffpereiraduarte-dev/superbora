<?php
/**
 * POST /api/mercado/partner/ai-assistant.php
 * Assistente IA Claude para parceiros
 *
 * Body: { "message": "Como posso melhorar minhas vendas?", "context": "analytics|menu|reviews|general" }
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

// No-op: tables created via migration
function ensureAIConversationsTable($db) {
    return;
}

try {
    $db = getDB();
    ensureAIConversationsTable($db);
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // Rate limiting: 20 AI calls per hour per partner (expensive API calls)
    if ($method === 'POST') {
        if (!checkRateLimit("ai_assistant_{$partnerId}", 20, 60)) {
            response(false, null, "Muitas requisicoes de IA. Tente novamente em 1 hora.", 429);
        }
    }

    if ($method === 'GET') {
        // Get conversation history
        $stmt = $db->prepare("
            SELECT role, message, created_at FROM om_ai_conversations
            WHERE partner_id = ? ORDER BY created_at DESC LIMIT 50
        ");
        $stmt->execute([$partnerId]);
        $history = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

        response(true, ['history' => $history]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $userMessage = trim($input['message'] ?? '');
        $context = $input['context'] ?? 'general';

        if (empty($userMessage)) {
            response(false, null, "Mensagem obrigatoria", 400);
        }

        // Get partner data for context
        $partnerData = getPartnerContext($db, $partnerId);

        // Save user message
        $stmt = $db->prepare("INSERT INTO om_ai_conversations (partner_id, role, message, context_data) VALUES (?, 'user', ?, ?)");
        $stmt->execute([$partnerId, $userMessage, json_encode(['context' => $context])]);

        // Get conversation history for context (last 10 messages)
        $stmt = $db->prepare("
            SELECT role, message FROM om_ai_conversations
            WHERE partner_id = ? ORDER BY created_at DESC LIMIT 10
        ");
        $stmt->execute([$partnerId]);
        $recentMessages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

        // Call Claude API
        $claudeResult = callClaudeAPI($userMessage, $context, $partnerData, $recentMessages);

        if (!$claudeResult['success']) {
            // Log error but return a friendly message
            error_log("[partner/ai-assistant] Claude API error: " . $claudeResult['error']);
            response(false, null, "Erro ao processar sua mensagem. Tente novamente.", 500);
        }

        $aiResponse = $claudeResult['response'];
        $tokensUsed = $claudeResult['tokens_used'] ?? null;
        $model = $claudeResult['model'] ?? 'claude-sonnet-4-20250514';

        // Save AI response
        $stmt = $db->prepare("INSERT INTO om_ai_conversations (partner_id, role, message, tokens_used, model) VALUES (?, 'assistant', ?, ?, ?)");
        $stmt->execute([$partnerId, $aiResponse, $tokensUsed, $model]);

        response(true, [
            'response' => $aiResponse,
            'context_used' => $context,
            'suggestions' => getSuggestions($context, $partnerData),
        ]);
    }

    if ($method === 'DELETE') {
        // Clear conversation history
        $stmt = $db->prepare("DELETE FROM om_ai_conversations WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        response(true, null, "Historico limpo!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/ai-assistant] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

/**
 * Call Claude API to generate AI response
 */
function callClaudeAPI($userMessage, $context, $partnerData, $recentMessages) {
    $apiKey = $_ENV['CLAUDE_API_KEY'] ?? '';

    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'CLAUDE_API_KEY not configured'];
    }

    // Build system prompt with partner context
    $systemPrompt = buildSystemPrompt($partnerData, $context);

    // Build messages array from conversation history
    $messages = [];
    foreach ($recentMessages as $msg) {
        // Skip the last user message as we'll add the current one
        if ($msg['role'] === 'user' && $msg['message'] === $userMessage) {
            continue;
        }
        $messages[] = [
            'role' => $msg['role'],
            'content' => $msg['message']
        ];
    }

    // Add current user message
    $messages[] = [
        'role' => 'user',
        'content' => $userMessage
    ];

    $data = [
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 1024,
        'system' => $systemPrompt,
        'messages' => $messages
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'cURL error: ' . $curlError];
    }

    if ($httpCode !== 200) {
        $errorBody = json_decode($response, true);
        $errorMsg = $errorBody['error']['message'] ?? "HTTP {$httpCode}";
        return ['success' => false, 'error' => "API error: {$errorMsg}"];
    }

    $result = json_decode($response, true);

    if (!isset($result['content'][0]['text'])) {
        return ['success' => false, 'error' => 'Invalid API response structure'];
    }

    $tokensUsed = ($result['usage']['input_tokens'] ?? 0) + ($result['usage']['output_tokens'] ?? 0);

    return [
        'success' => true,
        'response' => $result['content'][0]['text'],
        'tokens_used' => $tokensUsed,
        'model' => $result['model'] ?? 'claude-sonnet-4-20250514'
    ];
}

/**
 * Build system prompt with partner context
 */
function buildSystemPrompt($partnerData, $context) {
    $partnerName = $partnerData['name'];
    $category = $partnerData['category'];

    $prompt = "Voce e um assistente de IA especializado em ajudar parceiros de delivery a melhorar seus negocios. ";
    $prompt .= "Voce trabalha para o SuperBora, uma plataforma de delivery similar ao iFood.\n\n";

    $prompt .= "## Parceiro Atual\n";
    $prompt .= "- Nome: {$partnerName}\n";
    $prompt .= "- Categoria: {$category}\n";
    $prompt .= "- Status: " . ($partnerData['is_open'] ? 'Aberto' : 'Fechado') . "\n\n";

    $prompt .= "## Metricas dos Ultimos 30 Dias\n";
    $prompt .= "- Total de pedidos: {$partnerData['total_orders_30d']}\n";
    $prompt .= "- Faturamento: R$ " . number_format($partnerData['total_revenue_30d'], 2, ',', '.') . "\n";
    $prompt .= "- Ticket medio: R$ " . number_format($partnerData['avg_order_value'], 2, ',', '.') . "\n";
    $prompt .= "- Taxa de cancelamento: {$partnerData['cancellation_rate']}%\n";
    $prompt .= "- Avaliacao media: {$partnerData['avg_rating']} estrelas\n";

    if (!empty($partnerData['top_products'])) {
        $prompt .= "- Produtos mais vendidos: " . implode(', ', $partnerData['top_products']) . "\n";
    }

    if (!empty($partnerData['peak_hours'])) {
        $prompt .= "- Horarios de pico: " . implode(', ', $partnerData['peak_hours']) . "\n";
    }

    $prompt .= "\n## Avaliacoes Recentes\n";
    if (!empty($partnerData['recent_reviews'])) {
        foreach (array_slice($partnerData['recent_reviews'], 0, 5) as $review) {
            $rating = $review['rating'] ?? 5;
            $comment = $review['comment'] ?? '';
            if ($comment) {
                $prompt .= "- {$rating} estrelas: \"{$comment}\"\n";
            }
        }
    } else {
        $prompt .= "- Nenhuma avaliacao recente\n";
    }

    $prompt .= "\n## Contexto da Conversa: {$context}\n";

    $prompt .= "\n## Instrucoes\n";
    $prompt .= "1. Responda sempre em portugues brasileiro\n";
    $prompt .= "2. Seja conciso e pratico, focando em acoes que o parceiro pode tomar\n";
    $prompt .= "3. Use os dados reais do parceiro para personalizar suas recomendacoes\n";
    $prompt .= "4. Quando apropriado, use formatacao markdown (negrito, listas, etc)\n";
    $prompt .= "5. Se o parceiro tiver problemas (alta taxa de cancelamento, baixa avaliacao), ofereca solucoes especificas\n";
    $prompt .= "6. Seja encorajador mas honesto sobre areas que precisam de melhoria\n";
    $prompt .= "7. Sugira recursos da plataforma SuperBora que podem ajudar (combos, promocoes, programa de fidelidade)\n";

    return $prompt;
}

function getPartnerContext($db, $partnerId) {
    // Partner info (explicit columns — no password_hash, bank details, etc.)
    $stmt = $db->prepare("SELECT partner_id, name, trade_name, category, is_open FROM om_market_partners WHERE partner_id = ?");
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);

    // Last 30 days stats
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total_orders,
            SUM(total) as total_revenue,
            AVG(total) as avg_order_value,
            SUM(CASE WHEN status IN ('cancelado','cancelled') THEN 1 ELSE 0 END) as cancelled
        FROM om_market_orders
        WHERE partner_id = ? AND created_at >= NOW() - INTERVAL '30 days'
    ");
    $stmt->execute([$partnerId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Top products
    $stmt = $db->prepare("
        SELECT p.name, COUNT(oi.id) as orders
        FROM om_market_order_items oi
        JOIN om_market_products p ON p.product_id = oi.product_id
        JOIN om_market_orders o ON o.order_id = oi.order_id
        WHERE o.partner_id = ? AND o.created_at >= NOW() - INTERVAL '30 days'
        GROUP BY p.product_id, p.name ORDER BY orders DESC LIMIT 5
    ");
    $stmt->execute([$partnerId]);
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent reviews
    $stmt = $db->prepare("
        SELECT rating, comment, created_at
        FROM om_market_reviews WHERE partner_id = ?
        ORDER BY created_at DESC LIMIT 10
    ");
    $stmt->execute([$partnerId]);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Average rating
    $stmt = $db->prepare("SELECT AVG(rating) as avg FROM om_market_reviews WHERE partner_id = ?");
    $stmt->execute([$partnerId]);
    $avgRating = $stmt->fetch()['avg'] ?? 0;

    // Peak hours
    $stmt = $db->prepare("
        SELECT EXTRACT(HOUR FROM created_at)::int as h, COUNT(*) as c
        FROM om_market_orders WHERE partner_id = ? AND created_at >= NOW() - INTERVAL '7 days'
        GROUP BY h ORDER BY c DESC LIMIT 3
    ");
    $stmt->execute([$partnerId]);
    $peakHours = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'name' => $partner['trade_name'] ?? $partner['name'] ?? 'Loja',
        'category' => $partner['category'] ?? 'Restaurante',
        'total_orders_30d' => (int)$stats['total_orders'],
        'total_revenue_30d' => round((float)$stats['total_revenue'], 2),
        'avg_order_value' => round((float)$stats['avg_order_value'], 2),
        'cancellation_rate' => $stats['total_orders'] > 0 ? round(($stats['cancelled'] / $stats['total_orders']) * 100, 1) : 0,
        'avg_rating' => round((float)$avgRating, 1),
        'top_products' => array_map(fn($p) => $p['name'], $topProducts),
        'peak_hours' => array_map(fn($h) => $h['h'] . 'h', $peakHours),
        'recent_reviews' => $reviews,
        'is_open' => (bool)$partner['is_open'],
    ];
}

function getSuggestions($context, $data) {
    $suggestions = [
        'Como estao minhas vendas?',
        'Quais meus produtos mais vendidos?',
        'Como posso melhorar?',
        'Como estao minhas avaliacoes?',
        'Que promocoes devo criar?',
    ];

    return array_slice($suggestions, 0, 3);
}
