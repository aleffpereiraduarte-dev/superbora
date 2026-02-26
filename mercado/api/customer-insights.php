<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SUPERBORA - ANÁLISE DE PADRÕES DE COMPRA COM IA
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Analisa comportamento do cliente e gera insights para:
 * - Recomendações personalizadas
 * - Previsão de recompra
 * - Identificação de preferências
 * - Detecção de mudanças de hábito
 *
 * Endpoints:
 * - GET ?action=profile&customer_id=X - Perfil completo do cliente
 * - GET ?action=predict&customer_id=X - Previsão de próximas compras
 * - GET ?action=suggestions&customer_id=X - Sugestões personalizadas
 * - POST action=batch - Análise em lote para marketing
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

$pdo = null;
try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Erro de conexão']);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$partnerId = (int)($_GET['partner_id'] ?? $_POST['partner_id'] ?? 100);

switch ($action) {
    case 'profile':
        jsonResponse(getCustomerProfile($pdo, $customerId, $partnerId));
        break;

    case 'predict':
        jsonResponse(predictNextPurchases($pdo, $customerId, $partnerId));
        break;

    case 'suggestions':
        jsonResponse(getPersonalizedSuggestions($pdo, $customerId, $partnerId));
        break;

    case 'segments':
        jsonResponse(getCustomerSegments($pdo, $partnerId));
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Ação inválida']);
}

/**
 * Perfil completo do cliente
 */
function getCustomerProfile($pdo, $customerId, $partnerId) {
    if ($customerId < 1) {
        return ['success' => false, 'error' => 'ID do cliente inválido'];
    }

    try {
        // Dados básicos do cliente
        $customer = getCustomerBasicInfo($pdo, $customerId);
        if (!$customer) {
            return ['success' => false, 'error' => 'Cliente não encontrado'];
        }

        // Estatísticas de compras
        $stats = getPurchaseStats($pdo, $customerId, $partnerId);

        // Marcas favoritas
        $favoriteBrands = getFavoriteBrands($pdo, $customerId, $partnerId);

        // Categorias favoritas
        $favoriteCategories = getFavoriteCategories($pdo, $customerId, $partnerId);

        // Horários de compra preferidos
        $preferredTimes = getPreferredShoppingTimes($pdo, $customerId, $partnerId);

        // Padrão de frequência
        $frequency = getPurchaseFrequency($pdo, $customerId, $partnerId);

        // Ticket médio e evolução
        $spending = getSpendingPattern($pdo, $customerId, $partnerId);

        // Segmento do cliente
        $segment = determineCustomerSegment($stats, $frequency, $spending);

        return [
            'success' => true,
            'customer' => [
                'id' => $customerId,
                'name' => $customer['name'],
                'email' => $customer['email'],
                'member_since' => $customer['date_added']
            ],
            'stats' => $stats,
            'preferences' => [
                'brands' => $favoriteBrands,
                'categories' => $favoriteCategories,
                'shopping_times' => $preferredTimes
            ],
            'behavior' => [
                'frequency' => $frequency,
                'spending' => $spending
            ],
            'segment' => $segment
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Erro ao analisar perfil'];
    }
}

/**
 * Informações básicas do cliente
 */
function getCustomerBasicInfo($pdo, $customerId) {
    $stmt = $pdo->prepare("
        SELECT
            customer_id,
            CONCAT(firstname, ' ', lastname) as name,
            email,
            telephone,
            date_added
        FROM oc_customer
        WHERE customer_id = ?
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Estatísticas de compras
 */
function getPurchaseStats($pdo, $customerId, $partnerId) {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT o.order_id) as total_orders,
            COALESCE(SUM(o.total), 0) as total_spent,
            COALESCE(AVG(o.total), 0) as avg_order_value,
            COALESCE(MAX(o.total), 0) as max_order_value,
            MIN(o.created_at) as first_order,
            MAX(o.created_at) as last_order,
            COUNT(DISTINCT DATE(o.created_at)) as active_days
        FROM om_market_orders o
        WHERE o.customer_id = ?
          AND o.partner_id = ?
          AND o.status IN ('delivered', 'completed')
    ");
    $stmt->execute([$customerId, $partnerId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Calcular dias desde última compra
    if ($stats['last_order']) {
        $lastOrder = new DateTime($stats['last_order']);
        $now = new DateTime();
        $stats['days_since_last_order'] = $now->diff($lastOrder)->days;
    } else {
        $stats['days_since_last_order'] = null;
    }

    return $stats;
}

/**
 * Marcas favoritas
 */
function getFavoriteBrands($pdo, $customerId, $partnerId, $limit = 10) {
    $stmt = $pdo->prepare("
        SELECT
            pb.brand,
            COUNT(*) as purchase_count,
            SUM(oi.quantity) as total_units,
            SUM(oi.price * oi.quantity) as total_spent
        FROM om_market_order_items oi
        JOIN om_market_orders o ON oi.order_id = o.order_id
        JOIN om_market_products_base pb ON oi.product_id = pb.product_id
        WHERE o.customer_id = ?
          AND o.partner_id = ?
          AND o.status IN ('delivered', 'completed')
          AND pb.brand IS NOT NULL
          AND pb.brand != ''
        GROUP BY pb.brand
        ORDER BY purchase_count DESC, total_spent DESC
        LIMIT ?
    ");
    $stmt->execute([$customerId, $partnerId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Categorias favoritas
 */
function getFavoriteCategories($pdo, $customerId, $partnerId, $limit = 10) {
    $stmt = $pdo->prepare("
        SELECT
            c.category_id,
            c.name as category_name,
            COUNT(*) as purchase_count,
            SUM(oi.quantity) as total_units,
            SUM(oi.price * oi.quantity) as total_spent
        FROM om_market_order_items oi
        JOIN om_market_orders o ON oi.order_id = o.order_id
        JOIN om_market_products_base pb ON oi.product_id = pb.product_id
        JOIN om_market_categories c ON pb.category_id = c.category_id
        WHERE o.customer_id = ?
          AND o.partner_id = ?
          AND o.status IN ('delivered', 'completed')
        GROUP BY c.category_id, c.name
        ORDER BY purchase_count DESC, total_spent DESC
        LIMIT ?
    ");
    $stmt->execute([$customerId, $partnerId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Horários preferidos de compra
 */
function getPreferredShoppingTimes($pdo, $customerId, $partnerId) {
    $stmt = $pdo->prepare("
        SELECT
            HOUR(created_at) as hour,
            DAYOFWEEK(created_at) as day_of_week,
            COUNT(*) as order_count
        FROM om_market_orders
        WHERE customer_id = ?
          AND partner_id = ?
          AND status IN ('delivered', 'completed')
        GROUP BY HOUR(created_at), DAYOFWEEK(created_at)
        ORDER BY order_count DESC
        LIMIT 10
    ");
    $stmt->execute([$customerId, $partnerId]);
    $times = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Determinar período preferido
    $periods = ['morning' => 0, 'afternoon' => 0, 'evening' => 0, 'night' => 0];
    foreach ($times as $t) {
        $hour = (int)$t['hour'];
        if ($hour >= 6 && $hour < 12) $periods['morning'] += $t['order_count'];
        elseif ($hour >= 12 && $hour < 18) $periods['afternoon'] += $t['order_count'];
        elseif ($hour >= 18 && $hour < 22) $periods['evening'] += $t['order_count'];
        else $periods['night'] += $t['order_count'];
    }

    arsort($periods);
    $preferredPeriod = array_key_first($periods);

    // Determinar dia preferido
    $days = ['', 'Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
    $dayCount = [];
    foreach ($times as $t) {
        $day = $days[$t['day_of_week']] ?? '';
        $dayCount[$day] = ($dayCount[$day] ?? 0) + $t['order_count'];
    }
    arsort($dayCount);
    $preferredDay = array_key_first($dayCount);

    return [
        'preferred_period' => $preferredPeriod,
        'preferred_day' => $preferredDay,
        'details' => $times
    ];
}

/**
 * Frequência de compra
 */
function getPurchaseFrequency($pdo, $customerId, $partnerId) {
    $stmt = $pdo->prepare("
        SELECT created_at
        FROM om_market_orders
        WHERE customer_id = ?
          AND partner_id = ?
          AND status IN ('delivered', 'completed')
        ORDER BY created_at ASC
    ");
    $stmt->execute([$customerId, $partnerId]);
    $orders = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($orders) < 2) {
        return [
            'avg_days_between' => null,
            'pattern' => 'insufficient_data',
            'order_count' => count($orders)
        ];
    }

    $intervals = [];
    for ($i = 1; $i < count($orders); $i++) {
        $prev = new DateTime($orders[$i - 1]);
        $curr = new DateTime($orders[$i]);
        $intervals[] = $curr->diff($prev)->days;
    }

    $avgDays = array_sum($intervals) / count($intervals);

    // Determinar padrão
    $pattern = 'irregular';
    if ($avgDays <= 7) $pattern = 'weekly';
    elseif ($avgDays <= 14) $pattern = 'biweekly';
    elseif ($avgDays <= 30) $pattern = 'monthly';
    elseif ($avgDays <= 45) $pattern = 'occasional';

    return [
        'avg_days_between' => round($avgDays, 1),
        'pattern' => $pattern,
        'order_count' => count($orders),
        'std_deviation' => count($intervals) > 1 ? round(stats_standard_deviation($intervals), 1) : 0
    ];
}

/**
 * Padrão de gastos
 */
function getSpendingPattern($pdo, $customerId, $partnerId) {
    // Últimos 6 meses
    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as orders,
            SUM(total) as total_spent,
            AVG(total) as avg_order
        FROM om_market_orders
        WHERE customer_id = ?
          AND partner_id = ?
          AND status IN ('delivered', 'completed')
          AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute([$customerId, $partnerId]);
    $monthly = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcular tendência
    $trend = 'stable';
    if (count($monthly) >= 3) {
        $firstHalf = array_slice($monthly, 0, (int)(count($monthly) / 2));
        $secondHalf = array_slice($monthly, (int)(count($monthly) / 2));

        $avgFirst = array_sum(array_column($firstHalf, 'total_spent')) / count($firstHalf);
        $avgSecond = array_sum(array_column($secondHalf, 'total_spent')) / count($secondHalf);

        if ($avgSecond > $avgFirst * 1.2) $trend = 'increasing';
        elseif ($avgSecond < $avgFirst * 0.8) $trend = 'decreasing';
    }

    return [
        'monthly_data' => $monthly,
        'trend' => $trend
    ];
}

/**
 * Determinar segmento do cliente
 */
function determineCustomerSegment($stats, $frequency, $spending) {
    $totalSpent = (float)($stats['total_spent'] ?? 0);
    $orderCount = (int)($stats['total_orders'] ?? 0);
    $daysSinceLast = $stats['days_since_last_order'] ?? 999;
    $avgDays = $frequency['avg_days_between'] ?? 999;

    // Segmentos baseados em RFM (Recency, Frequency, Monetary)
    if ($totalSpent > 5000 && $orderCount > 20 && $daysSinceLast < 30) {
        return [
            'code' => 'vip',
            'name' => 'Cliente VIP',
            'description' => 'Comprador frequente e de alto valor',
            'color' => '#FFD700'
        ];
    }

    if ($orderCount > 10 && $daysSinceLast < 30) {
        return [
            'code' => 'loyal',
            'name' => 'Cliente Fiel',
            'description' => 'Compra regularmente',
            'color' => '#4CAF50'
        ];
    }

    if ($daysSinceLast > 60 && $orderCount > 5) {
        return [
            'code' => 'at_risk',
            'name' => 'Em Risco',
            'description' => 'Não compra há mais de 60 dias',
            'color' => '#FF9800'
        ];
    }

    if ($daysSinceLast > 90) {
        return [
            'code' => 'churned',
            'name' => 'Inativo',
            'description' => 'Não compra há mais de 90 dias',
            'color' => '#F44336'
        ];
    }

    if ($orderCount <= 2) {
        return [
            'code' => 'new',
            'name' => 'Novo Cliente',
            'description' => 'Ainda conhecendo a plataforma',
            'color' => '#2196F3'
        ];
    }

    return [
        'code' => 'regular',
        'name' => 'Cliente Regular',
        'description' => 'Comprador ocasional',
        'color' => '#9E9E9E'
    ];
}

/**
 * Prever próximas compras
 */
function predictNextPurchases($pdo, $customerId, $partnerId) {
    if ($customerId < 1) {
        return ['success' => false, 'error' => 'ID do cliente inválido'];
    }

    try {
        // Produtos mais comprados com frequência
        $stmt = $pdo->prepare("
            SELECT
                pb.product_id,
                pb.name,
                pb.brand,
                pb.image,
                pp.price,
                COUNT(*) as times_bought,
                MAX(o.created_at) as last_bought,
                AVG(DATEDIFF(
                    o.created_at,
                    (SELECT MAX(o2.created_at) FROM om_market_orders o2
                     JOIN om_market_order_items oi2 ON o2.order_id = oi2.order_id
                     WHERE o2.customer_id = o.customer_id
                     AND oi2.product_id = oi.product_id
                     AND o2.created_at < o.created_at)
                )) as avg_days_between
            FROM om_market_order_items oi
            JOIN om_market_orders o ON oi.order_id = o.order_id
            JOIN om_market_products_base pb ON oi.product_id = pb.product_id
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id AND pp.partner_id = ?
            WHERE o.customer_id = ?
              AND o.partner_id = ?
              AND o.status IN ('delivered', 'completed')
            GROUP BY pb.product_id, pb.name, pb.brand, pb.image, pp.price
            HAVING times_bought >= 2
            ORDER BY times_bought DESC
            LIMIT 20
        ");
        $stmt->execute([$partnerId, $customerId, $partnerId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calcular probabilidade de recompra
        $predictions = [];
        foreach ($products as $product) {
            $avgDays = (float)$product['avg_days_between'];
            $lastBought = new DateTime($product['last_bought']);
            $now = new DateTime();
            $daysSinceLast = $now->diff($lastBought)->days;

            if ($avgDays > 0) {
                // Score baseado em quanto tempo passou desde última compra vs média
                $dueScore = min(100, ($daysSinceLast / $avgDays) * 100);

                // Boost para produtos comprados frequentemente
                $frequencyBoost = min(20, $product['times_bought'] * 2);

                $probability = min(100, $dueScore + $frequencyBoost);

                $predictions[] = [
                    'product_id' => $product['product_id'],
                    'name' => $product['name'],
                    'brand' => $product['brand'],
                    'image' => $product['image'],
                    'price' => $product['price'],
                    'times_bought' => $product['times_bought'],
                    'avg_days_between' => round($avgDays),
                    'days_since_last' => $daysSinceLast,
                    'reorder_probability' => round($probability),
                    'status' => $probability > 80 ? 'due' : ($probability > 50 ? 'soon' : 'not_yet')
                ];
            }
        }

        // Ordenar por probabilidade
        usort($predictions, fn($a, $b) => $b['reorder_probability'] - $a['reorder_probability']);

        return [
            'success' => true,
            'predictions' => array_slice($predictions, 0, 10),
            'total_analyzed' => count($products)
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Erro ao gerar previsões'];
    }
}

/**
 * Sugestões personalizadas
 */
function getPersonalizedSuggestions($pdo, $customerId, $partnerId) {
    if ($customerId < 1) {
        return ['success' => false, 'error' => 'ID do cliente inválido'];
    }

    $suggestions = [];

    try {
        // 1. Produtos das categorias favoritas que nunca comprou
        $stmt = $pdo->prepare("
            SELECT
                pb.product_id,
                pb.name,
                pb.brand,
                pb.image,
                pp.price,
                c.name as category_name,
                'category_match' as reason
            FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            JOIN om_market_categories c ON pb.category_id = c.category_id
            WHERE pp.partner_id = ?
              AND pp.status = '1'
              AND pp.stock > 0
              AND pb.category_id IN (
                  SELECT pb2.category_id
                  FROM om_market_order_items oi
                  JOIN om_market_orders o ON oi.order_id = o.order_id
                  JOIN om_market_products_base pb2 ON oi.product_id = pb2.product_id
                  WHERE o.customer_id = ?
                  GROUP BY pb2.category_id
                  ORDER BY COUNT(*) DESC
                  LIMIT 3
              )
              AND pb.product_id NOT IN (
                  SELECT oi.product_id
                  FROM om_market_order_items oi
                  JOIN om_market_orders o ON oi.order_id = o.order_id
                  WHERE o.customer_id = ?
              )
            ORDER BY RANDOM()
            LIMIT 5
        ");
        $stmt->execute([$partnerId, $customerId, $customerId]);
        $categoryMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $suggestions = array_merge($suggestions, $categoryMatches);

        // 2. Produtos das marcas favoritas
        $stmt = $pdo->prepare("
            SELECT
                pb.product_id,
                pb.name,
                pb.brand,
                pb.image,
                pp.price,
                c.name as category_name,
                'brand_match' as reason
            FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            LEFT JOIN om_market_categories c ON pb.category_id = c.category_id
            WHERE pp.partner_id = ?
              AND pp.status = '1'
              AND pp.stock > 0
              AND pb.brand IN (
                  SELECT pb2.brand
                  FROM om_market_order_items oi
                  JOIN om_market_orders o ON oi.order_id = o.order_id
                  JOIN om_market_products_base pb2 ON oi.product_id = pb2.product_id
                  WHERE o.customer_id = ?
                  AND pb2.brand IS NOT NULL
                  GROUP BY pb2.brand
                  ORDER BY COUNT(*) DESC
                  LIMIT 3
              )
              AND pb.product_id NOT IN (
                  SELECT oi.product_id
                  FROM om_market_order_items oi
                  JOIN om_market_orders o ON oi.order_id = o.order_id
                  WHERE o.customer_id = ?
              )
            ORDER BY RANDOM()
            LIMIT 5
        ");
        $stmt->execute([$partnerId, $customerId, $customerId]);
        $brandMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $suggestions = array_merge($suggestions, $brandMatches);

        // Remover duplicatas
        $seen = [];
        $suggestions = array_filter($suggestions, function($item) use (&$seen) {
            if (in_array($item['product_id'], $seen)) return false;
            $seen[] = $item['product_id'];
            return true;
        });

        return [
            'success' => true,
            'suggestions' => array_values($suggestions)
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Erro ao gerar sugestões'];
    }
}

/**
 * Segmentos de clientes (para marketing)
 */
function getCustomerSegments($pdo, $partnerId) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                o.customer_id,
                c.firstname,
                c.email,
                COUNT(DISTINCT o.order_id) as order_count,
                SUM(o.total) as total_spent,
                MAX(o.created_at) as last_order,
                DATEDIFF(NOW(), MAX(o.created_at)) as days_since_last
            FROM om_market_orders o
            JOIN oc_customer c ON o.customer_id = c.customer_id
            WHERE o.partner_id = ?
              AND o.status IN ('delivered', 'completed')
            GROUP BY o.customer_id, c.firstname, c.email
        ");
        $stmt->execute([$partnerId]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $segments = [
            'vip' => [],
            'loyal' => [],
            'at_risk' => [],
            'churned' => [],
            'new' => [],
            'regular' => []
        ];

        foreach ($customers as $customer) {
            $totalSpent = (float)$customer['total_spent'];
            $orderCount = (int)$customer['order_count'];
            $daysSinceLast = (int)$customer['days_since_last'];

            if ($totalSpent > 5000 && $orderCount > 20 && $daysSinceLast < 30) {
                $segments['vip'][] = $customer;
            } elseif ($orderCount > 10 && $daysSinceLast < 30) {
                $segments['loyal'][] = $customer;
            } elseif ($daysSinceLast > 60 && $orderCount > 5) {
                $segments['at_risk'][] = $customer;
            } elseif ($daysSinceLast > 90) {
                $segments['churned'][] = $customer;
            } elseif ($orderCount <= 2) {
                $segments['new'][] = $customer;
            } else {
                $segments['regular'][] = $customer;
            }
        }

        return [
            'success' => true,
            'segments' => [
                'vip' => ['count' => count($segments['vip']), 'customers' => array_slice($segments['vip'], 0, 10)],
                'loyal' => ['count' => count($segments['loyal']), 'customers' => array_slice($segments['loyal'], 0, 10)],
                'at_risk' => ['count' => count($segments['at_risk']), 'customers' => array_slice($segments['at_risk'], 0, 10)],
                'churned' => ['count' => count($segments['churned']), 'customers' => array_slice($segments['churned'], 0, 10)],
                'new' => ['count' => count($segments['new']), 'customers' => array_slice($segments['new'], 0, 10)],
                'regular' => ['count' => count($segments['regular']), 'customers' => array_slice($segments['regular'], 0, 10)]
            ],
            'total_customers' => count($customers)
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Erro ao segmentar clientes'];
    }
}

/**
 * Calcular desvio padrão
 */
function stats_standard_deviation($arr) {
    $count = count($arr);
    if ($count < 2) return 0;

    $mean = array_sum($arr) / $count;
    $variance = 0;

    foreach ($arr as $val) {
        $variance += pow($val - $mean, 2);
    }

    return sqrt($variance / ($count - 1));
}

/**
 * Resposta JSON
 */
function jsonResponse($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
