<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SUPERBORA - RERANKING DE BUSCA COM IA
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Melhora os resultados de busca considerando:
 * - Histórico de compras do cliente
 * - Itens no carrinho atual
 * - Horário do dia (manhã = café da manhã, noite = jantar)
 * - Padrões de compra anteriores
 * - Intenção de busca (marca, categoria, receita)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/includes/env_loader.php';

$ANTHROPIC_API_KEY = env('ANTHROPIC_API_KEY', '');
$USE_CLAUDE = !empty($ANTHROPIC_API_KEY) && strlen($ANTHROPIC_API_KEY) > 20;

$pdo = null;
try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Erro de conexão']);
}

// Parâmetros
$query = trim($_GET['q'] ?? $_POST['q'] ?? '');
$partnerId = (int)($_GET['partner_id'] ?? $_POST['partner_id'] ?? 100);
$customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$cartItems = json_decode($_GET['cart'] ?? $_POST['cart'] ?? '[]', true) ?: [];
$limit = min(50, max(10, (int)($_GET['limit'] ?? 24)));

if (strlen($query) < 2) {
    jsonResponse(['success' => false, 'error' => 'Query muito curta']);
}

// 1. Buscar produtos (query base)
$products = searchProducts($pdo, $query, $partnerId, $limit * 2);

if (empty($products)) {
    jsonResponse([
        'success' => true,
        'products' => [],
        'total' => 0,
        'query' => $query,
        'reranked' => false
    ]);
}

// 2. Obter contexto do usuário
$context = buildUserContext($pdo, $customerId, $cartItems, $partnerId);

// 3. Detectar intenção de busca
$intent = detectSearchIntent($query);

// 4. Reranquear produtos
$reranked = rerankProducts($products, $context, $intent, $USE_CLAUDE, $ANTHROPIC_API_KEY);

// 5. Aplicar boost de horário
$reranked = applyTimeBoost($reranked);

// 6. Limitar resultados
$reranked = array_slice($reranked, 0, $limit);

jsonResponse([
    'success' => true,
    'products' => $reranked,
    'total' => count($products),
    'query' => $query,
    'intent' => $intent,
    'reranked' => true,
    'ai_powered' => $USE_CLAUDE
]);

/**
 * Busca produtos no banco
 */
function searchProducts($pdo, $query, $partnerId, $limit) {
    $like = "%" . $query . "%";

    try {
        $stmt = $pdo->prepare("
            SELECT
                pb.product_id,
                pb.name,
                pb.brand,
                pb.image,
                pb.category_id,
                pb.unit,
                pb.weight,
                pp.price,
                pp.price_promo,
                pp.stock,
                c.name as category_name,
                0 as sales_30d
            FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
            WHERE pp.partner_id = ?
              AND pp.status = '1'
              AND pp.stock > 0
              AND (pb.name LIKE ? OR pb.brand LIKE ?)
            ORDER BY
                CASE WHEN pb.name LIKE ? THEN 100 ELSE 0 END DESC,
                pp.price ASC
            LIMIT ?
        ");

        $stmt->execute([$partnerId, $like, $like, $query, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Construir contexto do usuário
 */
function buildUserContext($pdo, $customerId, $cartItems, $partnerId) {
    $context = [
        'purchase_history' => [],
        'favorite_brands' => [],
        'favorite_categories' => [],
        'cart_categories' => [],
        'hour' => (int)date('H'),
        'day_of_week' => date('N'), // 1=Monday, 7=Sunday
        'is_weekend' => in_array(date('N'), [6, 7])
    ];

    if ($customerId > 0) {
        try {
            // Histórico de compras (últimos 90 dias)
            $stmt = $pdo->prepare("
                SELECT
                    pb.product_id,
                    pb.brand,
                    pb.category_id,
                    COUNT(*) as purchase_count
                FROM om_market_order_items oi
                JOIN om_market_orders o ON oi.order_id = o.order_id
                JOIN om_market_products_base pb ON oi.product_id = pb.product_id
                WHERE o.customer_id = ?
                  AND o.created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)
                  AND o.status IN ('delivered', 'completed')
                GROUP BY pb.product_id, pb.brand, pb.category_id
                ORDER BY purchase_count DESC
                LIMIT 50
            ");
            $stmt->execute([$customerId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $context['purchase_history'] = array_column($history, 'product_id');

            // Marcas favoritas
            $brands = [];
            foreach ($history as $item) {
                if (!empty($item['brand'])) {
                    $brands[$item['brand']] = ($brands[$item['brand']] ?? 0) + $item['purchase_count'];
                }
            }
            arsort($brands);
            $context['favorite_brands'] = array_slice(array_keys($brands), 0, 10);

            // Categorias favoritas
            $categories = [];
            foreach ($history as $item) {
                if (!empty($item['category_id'])) {
                    $categories[$item['category_id']] = ($categories[$item['category_id']] ?? 0) + $item['purchase_count'];
                }
            }
            arsort($categories);
            $context['favorite_categories'] = array_slice(array_keys($categories), 0, 10);

        } catch (Exception $e) {
            // Ignorar erros
        }
    }

    // Categorias do carrinho atual
    if (!empty($cartItems)) {
        $cartCategories = [];
        foreach ($cartItems as $item) {
            if (!empty($item['category_id'])) {
                $cartCategories[] = $item['category_id'];
            }
        }
        $context['cart_categories'] = array_unique($cartCategories);
    }

    return $context;
}

/**
 * Detectar intenção de busca
 */
function detectSearchIntent($query) {
    $query = mb_strtolower($query);

    $intents = [
        'type' => 'generic',
        'specific_brand' => null,
        'meal_context' => null,
        'dietary' => null
    ];

    // Detectar marcas conhecidas
    $brands = ['nestlé', 'nestle', 'sadia', 'perdigão', 'seara', 'friboi', 'coca', 'pepsi',
               'omo', 'ariel', 'downy', 'comfort', 'danone', 'activia', 'itambé', 'piracanjuba'];

    foreach ($brands as $brand) {
        if (strpos($query, $brand) !== false) {
            $intents['type'] = 'brand_search';
            $intents['specific_brand'] = $brand;
            break;
        }
    }

    // Detectar contexto de refeição
    $meals = [
        'cafe' => 'breakfast', 'café' => 'breakfast', 'manha' => 'breakfast',
        'almoço' => 'lunch', 'almoco' => 'lunch',
        'jantar' => 'dinner', 'janta' => 'dinner',
        'lanche' => 'snack', 'petisco' => 'snack'
    ];

    foreach ($meals as $keyword => $meal) {
        if (strpos($query, $keyword) !== false) {
            $intents['meal_context'] = $meal;
            break;
        }
    }

    // Detectar dieta/restrição
    $dietary = [
        'integral' => 'whole_grain', 'light' => 'light', 'diet' => 'diet',
        'zero' => 'zero_sugar', 'sem açúcar' => 'zero_sugar', 'sem acucar' => 'zero_sugar',
        'sem lactose' => 'lactose_free', 'sem gluten' => 'gluten_free',
        'vegano' => 'vegan', 'vegetariano' => 'vegetarian', 'organico' => 'organic'
    ];

    foreach ($dietary as $keyword => $diet) {
        if (strpos($query, $keyword) !== false) {
            $intents['dietary'] = $diet;
            break;
        }
    }

    return $intents;
}

/**
 * Reranquear produtos
 */
function rerankProducts($products, $context, $intent, $useClaude, $apiKey) {
    // Calcular scores personalizados
    foreach ($products as &$product) {
        $score = 0;

        // Boost para marcas favoritas
        if (in_array($product['brand'], $context['favorite_brands'])) {
            $score += 30;
            $product['boost_reason'][] = 'Marca favorita';
        }

        // Boost para categorias favoritas
        if (in_array($product['category_id'], $context['favorite_categories'])) {
            $score += 20;
            $product['boost_reason'][] = 'Categoria frequente';
        }

        // Boost para produtos já comprados
        if (in_array($product['product_id'], $context['purchase_history'])) {
            $score += 40;
            $product['boost_reason'][] = 'Comprado antes';
        }

        // Boost para mesma categoria do carrinho (complementar)
        if (in_array($product['category_id'], $context['cart_categories'])) {
            $score += 15;
            $product['boost_reason'][] = 'Combina com carrinho';
        }

        // Boost para promoções (price_promo deve ser > 0 e menor que price)
        if (!empty($product['price_promo']) && $product['price_promo'] > 0 && $product['price_promo'] < $product['price']) {
            $score += 25;
            $product['boost_reason'][] = 'Em promoção';
            $product['discount_percent'] = round((1 - $product['price_promo'] / $product['price']) * 100);
        }

        // Boost baseado em vendas (popularidade)
        $salesScore = min(20, ($product['sales_30d'] ?? 0) / 10);
        $score += $salesScore;

        // Boost para busca de marca específica
        if ($intent['specific_brand'] && stripos($product['brand'], $intent['specific_brand']) !== false) {
            $score += 50;
            $product['boost_reason'][] = 'Marca buscada';
        }

        $product['personalization_score'] = $score;
        $product['boost_reason'] = $product['boost_reason'] ?? [];
    }

    // Ordenar por score personalizado + relevância original
    usort($products, function($a, $b) {
        $scoreA = ($a['personalization_score'] ?? 0) + ($a['sales_30d'] ?? 0) / 10;
        $scoreB = ($b['personalization_score'] ?? 0) + ($b['sales_30d'] ?? 0) / 10;
        return $scoreB <=> $scoreA;
    });

    return $products;
}

/**
 * Aplicar boost baseado no horário
 */
function applyTimeBoost($products) {
    $hour = (int)date('H');

    // Categorias por horário
    $timeCategories = [];

    if ($hour >= 6 && $hour < 10) {
        // Café da manhã
        $timeCategories = ['Padaria', 'Laticínios', 'Frios', 'Cereais', 'Café'];
    } elseif ($hour >= 11 && $hour < 14) {
        // Almoço
        $timeCategories = ['Carnes', 'Arroz e Feijão', 'Hortifruti', 'Massas'];
    } elseif ($hour >= 18 && $hour < 21) {
        // Jantar
        $timeCategories = ['Carnes', 'Congelados', 'Massas', 'Molhos'];
    } elseif ($hour >= 21 || $hour < 6) {
        // Noite/madrugada
        $timeCategories = ['Bebidas', 'Snacks', 'Doces'];
    }

    if (!empty($timeCategories)) {
        foreach ($products as &$product) {
            $catName = $product['category_name'] ?? '';
            foreach ($timeCategories as $timeCat) {
                if (stripos($catName, $timeCat) !== false) {
                    $product['personalization_score'] = ($product['personalization_score'] ?? 0) + 10;
                    $product['boost_reason'][] = 'Relevante agora';
                    break;
                }
            }
        }

        // Re-ordenar
        usort($products, function($a, $b) {
            return ($b['personalization_score'] ?? 0) <=> ($a['personalization_score'] ?? 0);
        });
    }

    return $products;
}

/**
 * Resposta JSON
 */
function jsonResponse($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
