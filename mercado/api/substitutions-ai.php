<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SUPERBORA - SUBSTITUIÇÕES INTELIGENTES COM CLAUDE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Sugere produtos alternativos quando um item está indisponível
 * Usa Claude para entender contexto e preferências do cliente
 *
 * Endpoints:
 * - POST /api/substitutions-ai.php
 *   action: suggest | batch | accept | reject
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/includes/env_loader.php';

// Configuração
$ANTHROPIC_API_KEY = env('ANTHROPIC_API_KEY', '');
$USE_CLAUDE = !empty($ANTHROPIC_API_KEY) && strlen($ANTHROPIC_API_KEY) > 20;

// Conexão
$pdo = null;
try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Erro de conexão']);
}

// Input
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'suggest':
        // Sugerir substitutos para um produto
        $productId = (int)($input['product_id'] ?? 0);
        $partnerId = (int)($input['partner_id'] ?? 100);
        $customerId = (int)($input['customer_id'] ?? 0);
        $context = $input['context'] ?? []; // itens do carrinho, preferências

        jsonResponse(suggestSubstitutes($pdo, $productId, $partnerId, $customerId, $context, $USE_CLAUDE, $ANTHROPIC_API_KEY));
        break;

    case 'batch':
        // Sugerir substitutos para múltiplos produtos indisponíveis
        $products = $input['products'] ?? [];
        $partnerId = (int)($input['partner_id'] ?? 100);
        $customerId = (int)($input['customer_id'] ?? 0);

        jsonResponse(batchSubstitutes($pdo, $products, $partnerId, $customerId, $USE_CLAUDE, $ANTHROPIC_API_KEY));
        break;

    case 'accept':
        // Cliente aceitou substituição (para aprendizado)
        $originalId = (int)($input['original_id'] ?? 0);
        $substituteId = (int)($input['substitute_id'] ?? 0);
        $customerId = (int)($input['customer_id'] ?? 0);

        jsonResponse(recordSubstitution($pdo, $originalId, $substituteId, $customerId, 'accepted'));
        break;

    case 'reject':
        // Cliente rejeitou substituição (para aprendizado)
        $originalId = (int)($input['original_id'] ?? 0);
        $substituteId = (int)($input['substitute_id'] ?? 0);
        $customerId = (int)($input['customer_id'] ?? 0);

        jsonResponse(recordSubstitution($pdo, $originalId, $substituteId, $customerId, 'rejected'));
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Ação inválida']);
}

/**
 * Sugere substitutos para um produto
 */
function suggestSubstitutes($pdo, $productId, $partnerId, $customerId, $context, $useClaude, $apiKey) {
    if ($productId < 1) {
        return ['success' => false, 'error' => 'ID do produto inválido'];
    }

    // 1. Buscar produto original
    $original = getProduct($pdo, $productId, $partnerId);
    if (!$original) {
        return ['success' => false, 'error' => 'Produto não encontrado'];
    }

    // 2. Buscar candidatos a substitutos (mesma categoria, marca similar, etc)
    $candidates = getCandidates($pdo, $original, $partnerId, 20);

    if (empty($candidates)) {
        return [
            'success' => true,
            'product' => $original,
            'substitutes' => [],
            'message' => 'Nenhum substituto disponível'
        ];
    }

    // 3. Rankear candidatos
    if ($useClaude) {
        $ranked = rankWithClaude($original, $candidates, $context, $apiKey);
    } else {
        $ranked = rankWithAlgorithm($original, $candidates, $customerId, $pdo);
    }

    // 4. Retornar top 3
    $substitutes = array_slice($ranked, 0, 3);

    return [
        'success' => true,
        'product' => $original,
        'substitutes' => $substitutes,
        'ai_powered' => $useClaude
    ];
}

/**
 * Buscar produto por ID
 */
function getProduct($pdo, $productId, $partnerId) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                pb.product_id,
                pb.name,
                pb.brand,
                pb.category_id,
                pb.barcode,
                pb.unit,
                pb.weight,
                pp.price,
                pp.stock,
                c.name as category_name
            FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
            WHERE pb.product_id = ? AND pp.partner_id = ?
            LIMIT 1
        ");
        $stmt->execute([$productId, $partnerId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Buscar candidatos a substitutos
 */
function getCandidates($pdo, $original, $partnerId, $limit = 20) {
    $candidates = [];

    try {
        // Prioridade 1: Mesma categoria + marca diferente + em estoque
        $stmt = $pdo->prepare("
            SELECT
                pb.product_id,
                pb.name,
                pb.brand,
                pb.category_id,
                pb.unit,
                pb.weight,
                pp.price,
                pp.stock,
                c.name as category_name,
                'same_category' as match_type,
                ABS(pp.price - ?) as price_diff
            FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
            WHERE pb.category_id = ?
              AND pb.product_id != ?
              AND pp.partner_id = ?
              AND pp.stock > 0
              AND pp.status = '1'
            ORDER BY
                CASE WHEN pb.brand = ? THEN 0 ELSE 1 END,
                ABS(pp.price - ?) ASC
            LIMIT ?
        ");
        $stmt->execute([
            $original['price'],
            $original['category_id'],
            $original['product_id'],
            $partnerId,
            $original['brand'],
            $original['price'],
            $limit
        ]);
        $candidates = $stmt->fetchAll();

        // Prioridade 2: Se poucos resultados, buscar por nome similar
        if (count($candidates) < 5) {
            $words = explode(' ', $original['name']);
            $mainWord = $words[0] ?? '';

            if (strlen($mainWord) > 3) {
                $stmt = $pdo->prepare("
                    SELECT
                        pb.product_id,
                        pb.name,
                        pb.brand,
                        pb.category_id,
                        pb.unit,
                        pb.weight,
                        pp.price,
                        pp.stock,
                        c.name as category_name,
                        'name_match' as match_type,
                        ABS(pp.price - ?) as price_diff
                    FROM om_market_products_base pb
                    JOIN om_market_products_price pp ON pb.product_id = pp.product_id
                    LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
                    WHERE pb.name LIKE ?
                      AND pb.product_id != ?
                      AND pp.partner_id = ?
                      AND pp.stock > 0
                      AND pp.status = '1'
                    ORDER BY ABS(pp.price - ?) ASC
                    LIMIT ?
                ");
                $stmt->execute([
                    $original['price'],
                    "%$mainWord%",
                    $original['product_id'],
                    $partnerId,
                    $original['price'],
                    $limit - count($candidates)
                ]);

                $nameMatches = $stmt->fetchAll();
                $existingIds = array_column($candidates, 'product_id');

                foreach ($nameMatches as $match) {
                    if (!in_array($match['product_id'], $existingIds)) {
                        $candidates[] = $match;
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Log error
    }

    return $candidates;
}

/**
 * Rankear com Claude AI
 */
function rankWithClaude($original, $candidates, $context, $apiKey) {
    $prompt = buildSubstitutionPrompt($original, $candidates, $context);

    try {
        $response = callClaude($prompt, $apiKey);

        if ($response && isset($response['ranking'])) {
            // Reordenar candidatos baseado no ranking do Claude
            $ranked = [];
            foreach ($response['ranking'] as $item) {
                foreach ($candidates as $candidate) {
                    if ($candidate['product_id'] == $item['product_id']) {
                        $candidate['ai_reason'] = $item['reason'] ?? '';
                        $candidate['ai_score'] = $item['score'] ?? 0;
                        $ranked[] = $candidate;
                        break;
                    }
                }
            }
            return $ranked;
        }
    } catch (Exception $e) {
        // Fallback para algoritmo
    }

    return rankWithAlgorithm($original, $candidates, 0, null);
}

/**
 * Rankear com algoritmo (fallback)
 */
function rankWithAlgorithm($original, $candidates, $customerId, $pdo) {
    $scored = [];

    foreach ($candidates as $candidate) {
        $score = 100;

        // Mesma marca = +30 pontos
        if ($candidate['brand'] === $original['brand']) {
            $score += 30;
        }

        // Preço similar (diferença < 20%) = +20 pontos
        $priceDiff = abs($candidate['price'] - $original['price']) / max($original['price'], 1);
        if ($priceDiff < 0.2) {
            $score += 20 * (1 - $priceDiff);
        }

        // Mesmo peso/volume = +15 pontos
        if ($candidate['weight'] == $original['weight']) {
            $score += 15;
        }

        // Mesma categoria = +10 pontos
        if ($candidate['category_id'] == $original['category_id']) {
            $score += 10;
        }

        // Estoque alto = +5 pontos
        if ($candidate['stock'] > 10) {
            $score += 5;
        }

        $candidate['score'] = $score;
        $candidate['reason'] = generateReason($original, $candidate);
        $scored[] = $candidate;
    }

    // Ordenar por score
    usort($scored, fn($a, $b) => $b['score'] - $a['score']);

    return $scored;
}

/**
 * Gerar razão da substituição
 */
function generateReason($original, $substitute) {
    $reasons = [];

    if ($substitute['brand'] === $original['brand']) {
        $reasons[] = "Mesma marca";
    }

    $priceDiff = $substitute['price'] - $original['price'];
    if (abs($priceDiff) < 1) {
        $reasons[] = "Preço similar";
    } elseif ($priceDiff < 0) {
        $reasons[] = "R$ " . number_format(abs($priceDiff), 2, ',', '.') . " mais barato";
    }

    if ($substitute['category_id'] == $original['category_id']) {
        $reasons[] = "Mesma categoria";
    }

    if (empty($reasons)) {
        $reasons[] = "Produto similar";
    }

    return implode(' • ', $reasons);
}

/**
 * Construir prompt para Claude
 */
function buildSubstitutionPrompt($original, $candidates, $context) {
    $candidatesList = array_map(function($c) {
        return [
            'id' => $c['product_id'],
            'name' => $c['name'],
            'brand' => $c['brand'],
            'price' => $c['price'],
            'category' => $c['category_name']
        ];
    }, array_slice($candidates, 0, 10));

    $cartItems = [];
    if (!empty($context['cart_items'])) {
        $cartItems = array_map(fn($i) => $i['name'] ?? '', $context['cart_items']);
    }

    return json_encode([
        'task' => 'rank_substitutes',
        'original' => [
            'name' => $original['name'],
            'brand' => $original['brand'],
            'price' => $original['price'],
            'category' => $original['category_name']
        ],
        'candidates' => $candidatesList,
        'cart_context' => $cartItems,
        'instructions' => 'Rankear os candidatos como substitutos. Considerar: marca similar, preço próximo, qualidade equivalente, contexto do carrinho. Retornar JSON com ranking e razão para cada.'
    ]);
}

/**
 * Chamar API Claude
 */
function callClaude($prompt, $apiKey) {
    $ch = curl_init('https://api.anthropic.com/v1/messages');

    $payload = [
        'model' => 'claude-3-haiku-20240307',
        'max_tokens' => 1024,
        'messages' => [
            [
                'role' => 'user',
                'content' => "Você é um assistente de supermercado. Analise e retorne APENAS JSON válido.\n\n$prompt"
            ]
        ]
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['content'][0]['text'])) {
            $text = $data['content'][0]['text'];
            // Extrair JSON da resposta
            if (preg_match('/\{.*\}/s', $text, $matches)) {
                return json_decode($matches[0], true);
            }
        }
    }

    return null;
}

/**
 * Substituições em lote
 */
function batchSubstitutes($pdo, $products, $partnerId, $customerId, $useClaude, $apiKey) {
    $results = [];

    foreach ($products as $product) {
        $productId = (int)($product['product_id'] ?? 0);
        if ($productId > 0) {
            $result = suggestSubstitutes($pdo, $productId, $partnerId, $customerId, [], $useClaude, $apiKey);
            if ($result['success']) {
                $results[] = [
                    'original_id' => $productId,
                    'substitutes' => $result['substitutes']
                ];
            }
        }
    }

    return [
        'success' => true,
        'results' => $results,
        'ai_powered' => $useClaude
    ];
}

/**
 * Registrar aceitação/rejeição de substituição (para aprendizado)
 */
function recordSubstitution($pdo, $originalId, $substituteId, $customerId, $status) {
    if ($originalId < 1 || $substituteId < 1) {
        return ['success' => false, 'error' => 'IDs inválidos'];
    }

    try {
        // Verificar se tabela existe, senão criar
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS om_substitution_feedback (
                id INT AUTO_INCREMENT PRIMARY KEY,
                original_product_id INT NOT NULL,
                substitute_product_id INT NOT NULL,
                customer_id INT DEFAULT 0,
                status ENUM('accepted', 'rejected') NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_original (original_product_id),
                INDEX idx_substitute (substitute_product_id),
                INDEX idx_customer (customer_id)
            )
        ");

        $stmt = $pdo->prepare("
            INSERT INTO om_substitution_feedback
            (original_product_id, substitute_product_id, customer_id, status)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$originalId, $substituteId, $customerId, $status]);

        return ['success' => true, 'message' => 'Feedback registrado'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Erro ao registrar feedback'];
    }
}

/**
 * Resposta JSON
 */
function jsonResponse($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
