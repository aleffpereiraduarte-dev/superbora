<?php
/**
 * GET /api/mercado/customer/meu-paladar.php
 * "Meu Paladar" (My Taste Profile)
 *
 * Aggregates order history, favorite products/categories/stores,
 * time-of-day patterns, dietary preferences, and generates fun facts via Claude AI.
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Nao autorizado", 401);

    $customerId = (int)$payload['uid'];

    $completedStatuses = "('entregue', 'retirado')";

    // ── 1. Overall stats ──────────────────────────────────────────────
    $stmt = $db->prepare("
        SELECT
            COUNT(*)                         AS total_orders,
            COALESCE(SUM(total), 0)          AS total_spent,
            COALESCE(AVG(total), 0)          AS avg_ticket,
            MIN(date_added)                  AS first_order,
            MAX(date_added)                  AS last_order,
            COALESCE(MAX(total), 0)          AS biggest_order
        FROM om_market_orders
        WHERE customer_id = ? AND status IN {$completedStatuses}
    ");
    $stmt->execute([$customerId]);
    $row = $stmt->fetch();

    $totalOrders  = (int)$row['total_orders'];
    $totalSpent   = round((float)$row['total_spent'], 2);
    $avgTicket    = round((float)$row['avg_ticket'], 2);
    $biggestOrder = round((float)$row['biggest_order'], 2);

    $firstOrder = $row['first_order'];
    $lastOrder  = $row['last_order'];

    $daysAsCustomer = $firstOrder
        ? (int)((time() - strtotime($firstOrder)) / 86400)
        : 0;
    $daysSinceLast = $lastOrder
        ? (int)((time() - strtotime($lastOrder)) / 86400)
        : null;

    $stats = [
        'total_orders'     => $totalOrders,
        'total_spent'      => $totalSpent,
        'avg_ticket'       => $avgTicket,
        'days_as_customer' => $daysAsCustomer,
        'days_since_last'  => $daysSinceLast,
        'biggest_order'    => $biggestOrder,
    ];

    // If few orders, return profile with generic fun facts
    if ($totalOrders < 3) {
        $genericFacts = [
            ['emoji' => '🍕', 'text' => 'Pizza é o prato mais pedido no Brasil por delivery!'],
            ['emoji' => '🚀', 'text' => 'Pedidos pelo SuperBora chegam em média 35 minutos'],
            ['emoji' => '🎉', 'text' => 'Faça mais pedidos pra descobrir seu perfil alimentar!'],
            ['emoji' => '💰', 'text' => 'Use cashback e economize em cada pedido'],
            ['emoji' => '⭐', 'text' => 'Avalie seus pedidos e ajude outros clientes'],
        ];
        if ($totalOrders === 0) {
            $genericFacts[2] = ['emoji' => '👋', 'text' => 'Bem-vindo! Faça seu primeiro pedido e descubra seu paladar'];
        }
        response(true, [
            'stats'           => $stats,
            'top_categories'  => [],
            'top_products'    => [],
            'favorite_stores' => [],
            'time_patterns'   => ['morning' => 0, 'lunch' => 0, 'afternoon' => 0, 'dinner' => 0, 'night' => 0],
            'dietary'         => [],
            'fun_facts'       => $genericFacts,
            'needs_more_orders' => true,
            'min_orders_for_profile' => 3,
        ]);
    }

    // ── 2. Top 5 categories ───────────────────────────────────────────
    $stmt = $db->prepare("
        SELECT c.name, COUNT(*) AS cnt
        FROM om_market_order_items oi
        JOIN om_market_orders o ON o.order_id = oi.order_id
        JOIN om_market_products p ON p.product_id = oi.product_id
        JOIN om_market_categories c ON c.category_id = p.category_id
        WHERE o.customer_id = ? AND o.status IN {$completedStatuses}
        GROUP BY c.name
        ORDER BY cnt DESC
        LIMIT 5
    ");
    $stmt->execute([$customerId]);
    $catRows = $stmt->fetchAll();

    $catTotal = array_sum(array_column($catRows, 'cnt'));
    $topCategories = array_map(function ($r) use ($catTotal) {
        return [
            'name'    => $r['name'],
            'count'   => (int)$r['cnt'],
            'percent' => $catTotal > 0 ? (int)round(((int)$r['cnt'] / $catTotal) * 100) : 0,
        ];
    }, $catRows);

    // ── 3. Top 5 most ordered products ────────────────────────────────
    $stmt = $db->prepare("
        SELECT
            COALESCE(pb.name, oi.name) AS name,
            SUM(oi.quantity)           AS times_ordered,
            pb.image
        FROM om_market_order_items oi
        JOIN om_market_orders o ON o.order_id = oi.order_id
        LEFT JOIN om_market_products_base pb ON pb.product_id = oi.product_id
        WHERE o.customer_id = ? AND o.status IN {$completedStatuses}
        GROUP BY COALESCE(pb.name, oi.name), pb.image
        ORDER BY times_ordered DESC
        LIMIT 5
    ");
    $stmt->execute([$customerId]);
    $topProducts = array_map(function ($r) {
        return [
            'name'  => $r['name'],
            'times' => (int)$r['times_ordered'],
            'image' => $r['image'] ?? null,
        ];
    }, $stmt->fetchAll());

    // ── 4. Top 3 favorite stores ──────────────────────────────────────
    $stmt = $db->prepare("
        SELECT
            COALESCE(p.trade_name, p.name) AS name,
            p.logo,
            COUNT(*) AS order_count
        FROM om_market_orders o
        JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.customer_id = ? AND o.status IN {$completedStatuses}
        GROUP BY p.partner_id, p.trade_name, p.name, p.logo
        ORDER BY order_count DESC
        LIMIT 3
    ");
    $stmt->execute([$customerId]);
    $favoriteStores = array_map(function ($r) {
        return [
            'name'   => $r['name'],
            'logo'   => $r['logo'] ?? null,
            'orders' => (int)$r['order_count'],
        ];
    }, $stmt->fetchAll());

    // ── 5. Ordering time patterns ─────────────────────────────────────
    // morning: 6-11, lunch: 11-14, afternoon: 14-17, dinner: 17-22, night: 22-6
    $stmt = $db->prepare("
        SELECT
            SUM(CASE WHEN EXTRACT(HOUR FROM date_added) >= 6  AND EXTRACT(HOUR FROM date_added) < 11 THEN 1 ELSE 0 END) AS morning,
            SUM(CASE WHEN EXTRACT(HOUR FROM date_added) >= 11 AND EXTRACT(HOUR FROM date_added) < 14 THEN 1 ELSE 0 END) AS lunch,
            SUM(CASE WHEN EXTRACT(HOUR FROM date_added) >= 14 AND EXTRACT(HOUR FROM date_added) < 17 THEN 1 ELSE 0 END) AS afternoon,
            SUM(CASE WHEN EXTRACT(HOUR FROM date_added) >= 17 AND EXTRACT(HOUR FROM date_added) < 22 THEN 1 ELSE 0 END) AS dinner,
            SUM(CASE WHEN EXTRACT(HOUR FROM date_added) >= 22 OR  EXTRACT(HOUR FROM date_added) < 6  THEN 1 ELSE 0 END) AS night
        FROM om_market_orders
        WHERE customer_id = ? AND status IN {$completedStatuses}
    ");
    $stmt->execute([$customerId]);
    $tp = $stmt->fetch();

    $timePatterns = [
        'morning'   => (int)($tp['morning'] ?? 0),
        'lunch'     => (int)($tp['lunch'] ?? 0),
        'afternoon' => (int)($tp['afternoon'] ?? 0),
        'dinner'    => (int)($tp['dinner'] ?? 0),
        'night'     => (int)($tp['night'] ?? 0),
    ];

    // ── 6. Dietary preferences ────────────────────────────────────────
    $dietary = [];
    try {
        $stmt = $db->prepare("SELECT dietary_preferences FROM om_customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $dietCol = $stmt->fetchColumn();
        if ($dietCol) {
            $decoded = json_decode($dietCol, true);
            if (is_array($decoded)) {
                $dietary = $decoded;
            }
        }
    } catch (Exception $e) {
        // Column may not exist — silently skip
        error_log("[meu-paladar] dietary column missing: " . $e->getMessage());
    }

    // ── 7. Fun facts via Claude AI (cached 24h in Redis) ──────────────
    $funFacts = [];
    $cacheKey = "meu_paladar_funfacts_{$customerId}";
    $redis = null;

    try {
        $redis = new Redis();
        $redis->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', (int)($_ENV['REDIS_PORT'] ?? 6379));
        if ($_ENV['REDIS_PASSWORD'] ?? null) {
            $redis->auth($_ENV['REDIS_PASSWORD']);
        }
        $cached = $redis->get($cacheKey);
        if ($cached) {
            $funFacts = json_decode($cached, true) ?: [];
        }
    } catch (Exception $e) {
        error_log("[meu-paladar] Redis connect error: " . $e->getMessage());
        $redis = null;
    }

    if (empty($funFacts) && $totalOrders >= 3) {
        try {
            require_once __DIR__ . "/../helpers/claude-client.php";

            $topProductName = !empty($topProducts) ? $topProducts[0]['name'] : 'N/A';
            $topCatName     = !empty($topCategories) ? $topCategories[0]['name'] : 'N/A';
            $topStoreName   = !empty($favoriteStores) ? $favoriteStores[0]['name'] : 'N/A';
            $peakPeriod     = array_keys($timePatterns, max($timePatterns))[0] ?? 'lunch';

            $periodLabels = [
                'morning'   => 'de manha (6h-11h)',
                'lunch'     => 'no almoco (11h-14h)',
                'afternoon' => 'a tarde (14h-17h)',
                'dinner'    => 'no jantar (17h-22h)',
                'night'     => 'de madrugada (22h-6h)',
            ];
            $peakLabel = $periodLabels[$peakPeriod] ?? $peakPeriod;

            $systemPrompt = "Voce e um assistente divertido e criativo do SuperBora, um app de delivery de comida brasileiro. "
                . "Gere exatamente 5 curiosidades (fun facts) sobre o perfil alimentar do cliente, em portugues brasileiro. "
                . "Cada fato deve ser curto (maximo 120 caracteres), bem-humorado, e comecar com um emoji relevante. "
                . "Use os dados fornecidos para criar fatos personalizados, criativos e engracados. "
                . "Responda APENAS com um JSON array de objetos {\"emoji\": \"...\", \"text\": \"...\"}, sem markdown.";

            $userMsg = "Dados do cliente:\n"
                . "- Total de pedidos: {$totalOrders}\n"
                . "- Total gasto: R\${$totalSpent}\n"
                . "- Ticket medio: R\${$avgTicket}\n"
                . "- Maior pedido: R\${$biggestOrder}\n"
                . "- Cliente ha {$daysAsCustomer} dias\n"
                . "- Produto mais pedido: {$topProductName}\n"
                . "- Categoria favorita: {$topCatName}\n"
                . "- Loja favorita: {$topStoreName}\n"
                . "- Horario preferido: {$peakLabel}\n"
                . "- Top 5 produtos: " . implode(', ', array_column($topProducts, 'name')) . "\n"
                . "- Top categorias: " . implode(', ', array_column($topCategories, 'name')) . "\n"
                . "- Preferencias alimentares: " . (empty($dietary) ? 'nenhuma' : implode(', ', $dietary)) . "\n"
                . "\nGere 5 fun facts criativos e engracados em JSON.";

            $claude = new ClaudeClient();
            $result = $claude->send($systemPrompt, [
                ['role' => 'user', 'content' => $userMsg]
            ], 1024);

            if ($result['success'] && !empty($result['text'])) {
                $parsed = ClaudeClient::parseJson($result['text']);
                if (is_array($parsed) && count($parsed) >= 1) {
                    // Validate structure
                    $funFacts = [];
                    foreach ($parsed as $fact) {
                        if (isset($fact['emoji'], $fact['text'])) {
                            $funFacts[] = [
                                'emoji' => mb_substr($fact['emoji'], 0, 4),
                                'text'  => mb_substr($fact['text'], 0, 200),
                            ];
                        }
                    }
                    $funFacts = array_slice($funFacts, 0, 5);

                    // Cache in Redis for 24 hours
                    if ($redis && !empty($funFacts)) {
                        try {
                            $redis->setex($cacheKey, 86400, json_encode($funFacts, JSON_UNESCAPED_UNICODE));
                        } catch (Exception $e) {
                            error_log("[meu-paladar] Redis set error: " . $e->getMessage());
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("[meu-paladar] Claude error: " . $e->getMessage());
            // Fun facts are optional — continue without them
        }
    }

    // ── Response ──────────────────────────────────────────────────────
    response(true, [
        'stats'           => $stats,
        'top_categories'  => $topCategories,
        'top_products'    => $topProducts,
        'favorite_stores' => $favoriteStores,
        'time_patterns'   => $timePatterns,
        'dietary'         => $dietary,
        'fun_facts'       => $funFacts,
    ]);

} catch (Exception $e) {
    error_log("[meu-paladar] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar perfil de paladar", 500);
}
