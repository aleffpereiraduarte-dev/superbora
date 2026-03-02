<?php
/**
 * ONE - Assistente IA SuperBora
 *
 * GET:  Returns personalized greeting + proactive suggestions (no Claude call)
 * POST: {message, conversation_id?} — Enriched chat with full customer context
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
setCorsHeaders();

header('Content-Type: application/json');

$customerId = requireCustomerAuth();
$db = getDB();

// ============================================================================
// SHARED: Customer Context Builder
// ============================================================================

function getMealPeriod(): array {
    $tz = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTime('now', $tz);
    $hour = (int)$now->format('H');
    $time = $now->format('H:i');

    if ($hour >= 5 && $hour < 10) {
        return ['period' => 'breakfast', 'greeting' => 'Bom dia', 'time' => $time,
            'suggestions' => ['Cafe da manha', 'Paes frescos', 'Frutas', 'Leite e derivados']];
    } elseif ($hour >= 10 && $hour < 14) {
        return ['period' => 'lunch', 'greeting' => 'Bom dia', 'time' => $time,
            'suggestions' => ['Almoco rapido', 'Salada pronta', 'Marmita', 'Prato feito']];
    } elseif ($hour >= 14 && $hour < 17) {
        return ['period' => 'snack', 'greeting' => 'Boa tarde', 'time' => $time,
            'suggestions' => ['Acai', 'Suco natural', 'Lanche da tarde', 'Snacks']];
    } elseif ($hour >= 17 && $hour < 21) {
        return ['period' => 'dinner', 'greeting' => 'Boa noite', 'time' => $time,
            'suggestions' => ['Jantar', 'Pizza', 'Massa', 'Churrasco']];
    } else {
        return ['period' => 'night', 'greeting' => 'Boa noite', 'time' => $time,
            'suggestions' => ['Sorvete', 'Snacks noturnos', 'Bebidas', 'Doces']];
    }
}

function buildCustomerContext(PDO $db, int $customerId): array {
    $ctx = [];

    // A) Profile
    $stmt = $db->prepare("SELECT name, email, created_at FROM om_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    $ctx['name'] = $profile['name'] ?? 'Cliente';
    $ctx['firstName'] = explode(' ', trim($ctx['name']))[0];
    $ctx['accountAge'] = $profile ? (int)((time() - strtotime($profile['created_at'])) / 86400) : 0;

    // B) Default address
    $stmt = $db->prepare("SELECT neighborhood, city, state, zipcode, lat, lng FROM om_customer_addresses WHERE customer_id = ? AND is_default = 1 LIMIT 1");
    $stmt->execute([$customerId]);
    $addr = $stmt->fetch(PDO::FETCH_ASSOC);
    $ctx['neighborhood'] = $addr['neighborhood'] ?? '';
    $ctx['city'] = $addr['city'] ?? '';
    $ctx['cep'] = $addr['zipcode'] ?? '';
    $ctx['lat'] = $addr['lat'] ?? null;
    $ctx['lng'] = $addr['lng'] ?? null;

    // B2) Nearby partners (stores that serve this area)
    $nearbyPartners = [];
    $addrCep = preg_replace('/\D/', '', $ctx['cep']);
    if (strlen($addrCep) === 8) {
        $stmt = $db->prepare("
            SELECT partner_id, name, trade_name, logo, categoria as category,
                   rating, delivery_fee, delivery_time_min, is_open
            FROM om_market_partners
            WHERE status::text = '1'
              AND cep_inicio IS NOT NULL AND cep_fim IS NOT NULL
              AND CAST(? AS BIGINT) BETWEEN CAST(cep_inicio AS BIGINT) AND CAST(cep_fim AS BIGINT)
            ORDER BY is_open DESC, rating DESC
            LIMIT 100
        ");
        $stmt->execute([$addrCep]);
        $nearbyPartners = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: prefix match
        if (empty($nearbyPartners)) {
            $prefixo = substr($addrCep, 0, 3);
            $stmt = $db->prepare("
                SELECT partner_id, name, trade_name, logo, categoria as category,
                       rating, delivery_fee, delivery_time_min, is_open
                FROM om_market_partners
                WHERE status::text = '1' AND LEFT(REPLACE(cep, '-', ''), 3) = ?
                ORDER BY is_open DESC, rating DESC
                LIMIT 100
            ");
            $stmt->execute([$prefixo]);
            $nearbyPartners = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    $ctx['nearbyPartners'] = $nearbyPartners;
    $ctx['nearbyPartnerIds'] = array_column($nearbyPartners, 'partner_id');

    // C) Order history stats (last 90 days)
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as order_count,
            COALESCE(AVG(total), 0) as avg_total,
            MAX(date_added) as last_order_date,
            MODE() WITHIN GROUP (ORDER BY EXTRACT(DOW FROM date_added)) as freq_day,
            MODE() WITHIN GROUP (ORDER BY EXTRACT(HOUR FROM date_added)) as freq_hour
        FROM om_market_orders
        WHERE customer_id = ? AND status IN ('entregue', 'retirado')
        AND date_added > NOW() - INTERVAL '90 days'
    ");
    $stmt->execute([$customerId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $ctx['orderCount'] = (int)($stats['order_count'] ?? 0);
    $ctx['avgTotal'] = round((float)($stats['avg_total'] ?? 0), 2);
    $ctx['lastOrderDate'] = $stats['last_order_date'] ?? null;
    $ctx['daysSinceOrder'] = $ctx['lastOrderDate'] ? (int)((time() - strtotime($ctx['lastOrderDate'])) / 86400) : null;

    $dowNames = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];
    $ctx['freqDay'] = $dowNames[(int)($stats['freq_day'] ?? 0)] ?? '';
    $ctx['freqHour'] = (int)($stats['freq_hour'] ?? 12);

    // D) Top 20 most ordered items (price from order_items, not products_base)
    $stmt = $db->prepare("
        SELECT pb.product_id, pb.name, ROUND(AVG(oi.price)::numeric, 2) as price, pb.image,
               c.name as category_name, p.trade_name as partner_name, o.partner_id,
               COUNT(*) as times_ordered
        FROM om_market_order_items oi
        JOIN om_market_products_base pb ON pb.product_id = oi.product_id
        LEFT JOIN om_market_categories c ON c.category_id = pb.category_id
        JOIN om_market_orders o ON o.order_id = oi.order_id
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.customer_id = ? AND o.status IN ('entregue', 'retirado')
        GROUP BY pb.product_id, pb.name, pb.image, c.name, p.trade_name, o.partner_id
        ORDER BY times_ordered DESC LIMIT 20
    ");
    $stmt->execute([$customerId]);
    $ctx['topItems'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // E) Favorites (price from om_market_products, partner from same)
    $stmt = $db->prepare("
        SELECT pb.product_id, pb.name,
               COALESCE(MIN(mp.price), pb.suggested_price, 0) as price, pb.image,
               c.name as category_name,
               (SELECT p2.trade_name FROM om_market_partners p2 WHERE p2.partner_id = MIN(mp.partner_id)) as partner_name,
               MIN(mp.partner_id) as partner_id
        FROM om_customer_favorites f
        JOIN om_market_products_base pb ON pb.product_id = f.product_id
        LEFT JOIN om_market_products mp ON mp.product_id = f.product_id
        LEFT JOIN om_market_categories c ON c.category_id = pb.category_id
        WHERE f.customer_id = ?
        GROUP BY pb.product_id, pb.name, pb.suggested_price, pb.image, c.name, f.created_at
        ORDER BY f.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$customerId]);
    $ctx['favorites'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // F) Current cart
    $stmt = $db->prepare("
        SELECT mc.product_id, pb.name, mc.quantity, mc.price, mc.partner_id,
               p.trade_name as partner_name
        FROM om_market_cart mc
        JOIN om_market_products_base pb ON pb.product_id = mc.product_id
        LEFT JOIN om_market_partners p ON p.partner_id = mc.partner_id
        WHERE mc.customer_id = ?
    ");
    $stmt->execute([$customerId]);
    $ctx['cart'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ctx['cartTotal'] = array_sum(array_map(fn($c) => (float)$c['price'] * (int)$c['quantity'], $ctx['cart']));
    $ctx['cartItems'] = count($ctx['cart']);

    // G) Active orders (pending, aceito, preparando, pronto, em_entrega, out_for_delivery)
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.status, o.total, o.payment_method,
               o.date_added, o.partner_name, o.items_count,
               e.driver_name, e.driver_phone
        FROM om_market_orders o
        LEFT JOIN om_entregas e ON e.order_id = o.order_id
        WHERE o.customer_id = ?
          AND o.status NOT IN ('entregue', 'retirado', 'cancelado', 'recusado')
        ORDER BY o.date_added DESC
        LIMIT 5
    ");
    $stmt->execute([$customerId]);
    $ctx['activeOrders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // H) Recent completed orders (last 7 days)
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.status, o.total, o.payment_method,
               o.date_added, o.partner_name, o.items_count
        FROM om_market_orders o
        WHERE o.customer_id = ?
          AND o.status IN ('entregue', 'retirado')
          AND o.date_added > NOW() - INTERVAL '7 days'
        ORDER BY o.date_added DESC
        LIMIT 5
    ");
    $stmt->execute([$customerId]);
    $ctx['recentOrders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top categories
    $categories = [];
    foreach ($ctx['topItems'] as $item) {
        $cat = $item['category_name'] ?? '';
        if ($cat && !in_array($cat, $categories)) $categories[] = $cat;
    }
    $ctx['topCategories'] = array_slice($categories, 0, 5);

    return $ctx;
}

// ============================================================================
// GET: Initial Context (no Claude call - fast & free)
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $ctx = buildCustomerContext($db, $customerId);
        $meal = getMealPeriod();

        // Resume existing conversation if provided and recent (< 1 hour)
        $resumeConversationId = trim($_GET['conversation_id'] ?? '');
        $conversationMessages = [];
        $activeConversationId = null;

        if ($resumeConversationId) {
            // Verify ownership + check if recent (last message within 1 hour)
            $stmt = $db->prepare("
                SELECT c.conversation_id, MAX(m.created_at) as last_msg_at
                FROM om_ai_customer_conversations c
                LEFT JOIN om_ai_customer_messages m ON m.conversation_id = c.conversation_id
                WHERE c.conversation_id = ? AND c.customer_id = ?
                GROUP BY c.conversation_id
                HAVING MAX(m.created_at) > NOW() - INTERVAL '1 hour'
            ");
            $stmt->execute([$resumeConversationId, $customerId]);
            $conv = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($conv) {
                $activeConversationId = $conv['conversation_id'];
                // Load last 30 messages for display
                $stmt = $db->prepare("
                    SELECT role, content, created_at
                    FROM om_ai_customer_messages
                    WHERE conversation_id = ?
                    ORDER BY created_at ASC
                    LIMIT 30
                ");
                $stmt->execute([$activeConversationId]);
                $conversationMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Last delivered order for "reorder" card
        $lastOrder = null;
        $stmt = $db->prepare("
            SELECT order_id, order_number, partner_name, total, items_count, date_added
            FROM om_market_orders
            WHERE customer_id = ? AND status IN ('entregue', 'retirado')
            ORDER BY date_added DESC LIMIT 1
        ");
        $stmt->execute([$customerId]);
        $lo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($lo) {
            $lastOrder = [
                'type' => 'reorder',
                'label' => 'Repetir pedido',
                'order_id' => (int)$lo['order_id'],
                'order_number' => $lo['order_number'] ?? '',
                'partner_name' => $lo['partner_name'] ?? '',
                'items_count' => (int)($lo['items_count'] ?? 0),
                'total' => round((float)$lo['total'], 2),
            ];
        }

        // Build proactive suggestions
        $suggestions = [];

        // 1. Reorder last order
        if ($lastOrder) $suggestions[] = $lastOrder;

        // 2. Top favorites as product suggestions
        foreach (array_slice($ctx['favorites'], 0, 5) as $fav) {
            $suggestions[] = [
                'type' => 'favorite',
                'product_id' => (int)$fav['product_id'],
                'name' => $fav['name'],
                'price' => round((float)$fav['price'], 2),
                'image' => $fav['image'] ?? '',
                'partner_id' => (int)($fav['partner_id'] ?? 0),
                'partner_name' => $fav['partner_name'] ?? '',
            ];
        }

        // 3. If not enough favorites, add top ordered items
        if (count($suggestions) < 6) {
            $favIds = array_column($ctx['favorites'], 'product_id');
            foreach ($ctx['topItems'] as $item) {
                if (in_array($item['product_id'], $favIds)) continue;
                $suggestions[] = [
                    'type' => 'frequent',
                    'product_id' => (int)$item['product_id'],
                    'name' => $item['name'],
                    'price' => round((float)$item['price'], 2),
                    'image' => $item['image'] ?? '',
                    'partner_id' => (int)($item['partner_id'] ?? 0),
                    'partner_name' => $item['partner_name'] ?? '',
                    'times_ordered' => (int)$item['times_ordered'],
                ];
                if (count($suggestions) >= 8) break;
            }
        }

        // 4. Meal-period search suggestions
        $mealSuggestions = array_map(fn($s) => [
            'type' => 'search',
            'label' => $s,
            'query' => $s,
        ], $meal['suggestions']);

        // Cart summary
        $cartSummary = null;
        if ($ctx['cartItems'] > 0) {
            $cartSummary = [
                'items' => $ctx['cartItems'],
                'total' => round($ctx['cartTotal'], 2),
            ];
        }

        // Nearby partners summary for frontend chips
        $nearbyStoresSummary = array_map(fn($p) => [
            'id' => (int)$p['partner_id'],
            'name' => $p['trade_name'] ?: $p['name'],
            'logo' => $p['logo'] ?? '',
            'category' => $p['category'] ?? '',
            'rating' => round((float)($p['rating'] ?? 5), 1),
            'is_open' => (int)($p['is_open'] ?? 0) === 1,
        ], array_slice($ctx['nearbyPartners'], 0, 15));

        // Active orders summary for frontend display
        $activeOrdersSummary = array_map(fn($o) => [
            'order_id' => (int)$o['order_id'],
            'order_number' => $o['order_number'] ?? '',
            'status' => $o['status'],
            'partner_name' => $o['partner_name'] ?? '',
            'total' => round((float)$o['total'], 2),
            'items_count' => (int)($o['items_count'] ?? 0),
            'date_added' => $o['date_added'],
            'driver_name' => $o['driver_name'] ?? null,
        ], $ctx['activeOrders'] ?? []);

        response(true, [
            'customer_name' => $ctx['firstName'],
            'greeting' => $meal['greeting'] . ', ' . $ctx['firstName'] . '!',
            'meal_period' => $meal['period'],
            'time' => $meal['time'],
            'proactive_suggestions' => $suggestions,
            'meal_suggestions' => $mealSuggestions,
            'cart_summary' => $cartSummary,
            'top_categories' => $ctx['topCategories'],
            'nearby_stores' => $nearbyStoresSummary,
            'active_orders' => $activeOrdersSummary,
            'order_stats' => [
                'total_orders' => $ctx['orderCount'],
                'avg_total' => $ctx['avgTotal'],
                'days_since_last' => $ctx['daysSinceOrder'],
            ],
            'conversation_id' => $activeConversationId,
            'conversation_messages' => $conversationMessages,
        ]);

    } catch (Exception $e) {
        error_log("[ai-assistant GET] Error: " . $e->getMessage());
        // Graceful fallback
        $meal = getMealPeriod();
        response(true, [
            'customer_name' => 'voce',
            'greeting' => $meal['greeting'] . '!',
            'meal_period' => $meal['period'],
            'time' => $meal['time'],
            'proactive_suggestions' => [],
            'meal_suggestions' => array_map(fn($s) => ['type' => 'search', 'label' => $s, 'query' => $s], $meal['suggestions']),
            'cart_summary' => null,
            'top_categories' => [],
            'order_stats' => null,
        ]);
    }
}

// ============================================================================
// POST: Chat with enriched context
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Method not allowed', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $message = trim($input['message'] ?? '');
    $conversationId = $input['conversation_id'] ?? null;

    if (empty($message)) { response(false, null, 'Message required', 400); }
    if (strlen($message) > 500) { response(false, null, 'Message too long', 400); }

    // Rate limit: 20 msg/min
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM om_ai_customer_messages
        WHERE conversation_id IN (SELECT conversation_id FROM om_ai_customer_conversations WHERE customer_id = ?)
        AND created_at > NOW() - INTERVAL '1 minute'
    ");
    $stmt->execute([$customerId]);
    if ($stmt->fetchColumn() >= 20) { response(false, null, 'Rate limit exceeded', 429); }

    // Get or create conversation
    if (!$conversationId) {
        $conversationId = md5(uniqid('', true) . bin2hex(random_bytes(8)));
        $stmt = $db->prepare("INSERT INTO om_ai_customer_conversations (conversation_id, customer_id, channel) VALUES (?, ?, 'app')");
        $stmt->execute([$conversationId, $customerId]);
    } else {
        // SECURITY: Verify conversation belongs to authenticated customer
        $stmt = $db->prepare("SELECT customer_id FROM om_ai_customer_conversations WHERE conversation_id = ?");
        $stmt->execute([$conversationId]);
        $conv = $stmt->fetch();
        if (!$conv || (int)$conv['customer_id'] !== $customerId) {
            response(false, null, 'Conversation not found', 404);
        }
    }

    // Load conversation history (last 20 messages)
    $stmt = $db->prepare("SELECT role, content FROM om_ai_customer_messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$conversationId]);
    $history = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    // ── Build rich customer context ──
    $ctx = buildCustomerContext($db, $customerId);
    $meal = getMealPeriod();

    // ── Search Meilisearch for products (filtered by nearby stores) ──
    $keywords = implode(' ', array_filter(explode(' ', $message), fn($w) => mb_strlen($w) > 2));
    $searchResults = [];
    $meiliKey = $_ENV['MEILI_ADMIN_KEY'] ?? getenv('MEILI_ADMIN_KEY') ?: '';
    try {
        $meiliPayload = [
            'q' => $keywords,
            'limit' => 25,
            'attributesToRetrieve' => ['id', 'name', 'nome', 'price', 'preco', 'image', 'partner_name', 'partner_id', 'category_name'],
        ];

        // Filter by nearby partner IDs so ONE only suggests reachable products
        if (!empty($ctx['nearbyPartnerIds'])) {
            $meiliPayload['filter'] = 'partner_id IN [' . implode(',', $ctx['nearbyPartnerIds']) . ']';
        }

        $ch = curl_init('http://localhost:7700/indexes/products/search');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($meiliPayload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $meiliKey],
            CURLOPT_TIMEOUT => 5,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($res, true);
        $searchResults = $data['hits'] ?? [];
    } catch (Exception $e) { /* fallback: no search results */ }

    // ── Build product context for prompt ──
    $productContext = json_encode(array_map(fn($h) => [
        'id' => $h['id'] ?? 0,
        'name' => $h['name'] ?? $h['nome'] ?? '',
        'price' => $h['price'] ?? $h['preco'] ?? 0,
        'image' => $h['image'] ?? '',
        'partner_name' => $h['partner_name'] ?? '',
        'partner_id' => $h['partner_id'] ?? 0,
    ], $searchResults), JSON_UNESCAPED_UNICODE);

    // ── Nearby stores summary for prompt ──
    $nearbyStoresJson = json_encode(array_map(fn($p) => [
        'id' => (int)$p['partner_id'],
        'name' => $p['trade_name'] ?: $p['name'],
        'category' => $p['category'] ?? '',
        'rating' => round((float)($p['rating'] ?? 5), 1),
        'is_open' => (int)($p['is_open'] ?? 0) === 1,
        'delivery_fee' => round((float)($p['delivery_fee'] ?? 0), 2),
        'delivery_time' => (int)($p['delivery_time_min'] ?? 60),
    ], array_slice($ctx['nearbyPartners'], 0, 20)), JSON_UNESCAPED_UNICODE);

    // ── Top items summary ──
    $topItemsJson = json_encode(array_map(fn($i) => [
        'name' => $i['name'],
        'price' => round((float)$i['price'], 2),
        'times' => (int)$i['times_ordered'],
        'category' => $i['category_name'] ?? '',
    ], array_slice($ctx['topItems'], 0, 15)), JSON_UNESCAPED_UNICODE);

    // ── Favorites summary ──
    $favoritesJson = json_encode(array_map(fn($f) => [
        'name' => $f['name'],
        'price' => round((float)$f['price'], 2),
    ], $ctx['favorites']), JSON_UNESCAPED_UNICODE);

    // ── Cart summary ──
    $cartJson = $ctx['cartItems'] > 0
        ? json_encode(array_map(fn($c) => $c['name'] . ' x' . $c['quantity'], $ctx['cart']), JSON_UNESCAPED_UNICODE)
        : 'vazio';

    // ── Build active orders context ──
    $activeOrdersJson = 'nenhum pedido ativo';
    if (!empty($ctx['activeOrders'])) {
        $statusMap = [
            'pendente' => 'Aguardando aceite da loja',
            'aceito' => 'Aceito pela loja',
            'preparando' => 'Sendo preparado',
            'pronto' => 'Pronto para entrega/retirada',
            'em_entrega' => 'Saiu para entrega',
            'out_for_delivery' => 'A caminho do cliente',
        ];
        $orderLines = [];
        foreach ($ctx['activeOrders'] as $ao) {
            $statusLabel = $statusMap[$ao['status']] ?? $ao['status'];
            $line = "Pedido #{$ao['order_number']} - {$ao['partner_name']} - R$ " . number_format((float)$ao['total'], 2, ',', '.') . " - Status: {$statusLabel}";
            if (!empty($ao['driver_name'])) {
                $line .= " - Entregador: {$ao['driver_name']}";
            }
            $orderLines[] = $line;
        }
        $activeOrdersJson = implode("\n", $orderLines);
    }

    $recentOrdersJson = 'nenhum pedido recente';
    if (!empty($ctx['recentOrders'])) {
        $recentLines = [];
        foreach ($ctx['recentOrders'] as $ro) {
            $recentLines[] = "Pedido #{$ro['order_number']} - {$ro['partner_name']} - R$ " . number_format((float)$ro['total'], 2, ',', '.') . " - {$ro['status']} em {$ro['date_added']}";
        }
        $recentOrdersJson = implode("\n", $recentLines);
    }

    // ── ONE System Prompt ──
    $systemPrompt = "Voce e a ONE, assistente de compras do SuperBora. Como uma amiga que entende tudo de mercado e sabe exatamente o que o cliente precisa.

PERSONALIDADE:
- Calorosa, brasileira, direta — sem ser forcada ou artificial
- Chama o cliente pelo primeiro nome
- Use 1-2 emojis por mensagem (sem exagero)
- Sugira proativamente baseado nos habitos e horario
- Seja concisa: 1-3 frases na resposta, maximo 4
- NUNCA invente produtos que nao existem no catalogo abaixo
- Se nao encontrar o produto, sugira alternativas do catalogo ou pergunte mais detalhes
- Quando sugerir produtos, SEMPRE inclua id, preco, image e partner_id do catalogo
- OBRIGATORIO: copie o campo 'image' EXATAMENTE como aparece no catalogo (URL completa)
- Sempre que possivel, sugira produtos que TENHAM foto (image nao vazio)

CONTEXTO DO CLIENTE:
- Nome: {$ctx['firstName']}, cliente ha {$ctx['accountAge']} dias
- Bairro: {$ctx['neighborhood']}, {$ctx['city']}
- {$ctx['orderCount']} pedidos realizados" . ($ctx['daysSinceOrder'] !== null ? ", ultimo ha {$ctx['daysSinceOrder']} dias" : "") . "
- Valor medio dos pedidos: R\$ {$ctx['avgTotal']}
- Dia favorito pra pedir: {$ctx['freqDay']}
- Carrinho atual: {$cartJson}" . ($ctx['cartItems'] > 0 ? " (R\$ " . number_format($ctx['cartTotal'], 2, ',', '.') . ")" : "") . "
- Favoritos: {$favoritesJson}
- Mais comprados: {$topItemsJson}
- Horario: {$meal['time']} ({$meal['period']})

PEDIDOS ATIVOS DO CLIENTE (em andamento agora):
{$activeOrdersJson}

PEDIDOS RECENTES (ultimos 7 dias, ja finalizados):
{$recentOrdersJson}

REGRAS SOBRE PEDIDOS:
- Quando o cliente perguntar sobre pedido, status, entrega, motorista — RESPONDA com os dados acima
- Se o pedido esta 'preparando', diga que a loja esta preparando e estime o tempo
- Se esta 'em_entrega' ou 'out_for_delivery', informe o nome do motorista se disponivel
- Se nao tem pedido ativo, informe que nao ha pedidos em andamento
- Para reclamacoes ou problemas com pedido, sugira usar o suporte (quick_action navigate para /ajuda)
- Voce pode sugerir que o cliente peca mais coisas enquanto espera o pedido atual

LOJAS PROXIMAS DO CLIENTE (supermercados, restaurantes, pizzarias, padarias, etc — sugira produtos de TODAS estas lojas, incluindo restaurantes):
{$nearbyStoresJson}

PRODUTOS ENCONTRADOS NA BUSCA (ja filtrados por lojas proximas):
{$productContext}

FORMATO DE RESPOSTA (JSON obrigatorio):
{\"response_text\":\"sua resposta aqui\",\"suggestions\":[{\"type\":\"product\",\"id\":0,\"name\":\"\",\"price\":0,\"image\":\"\",\"partner_id\":0,\"partner_name\":\"\"}],\"quick_actions\":[{\"label\":\"\",\"action\":\"search|add_to_cart|reorder|navigate\",\"query\":\"\",\"product_id\":0,\"partner_id\":0}]}

REGRAS DO JSON:
- suggestions: produtos para mostrar como cards (max 5)
- quick_actions: botoes rapidos (max 4). Acoes possiveis:
  - search: abre busca com a query
  - add_to_cart: adiciona product_id ao carrinho
  - reorder: repete o ultimo pedido
  - navigate: navega para loja (partner_id)
- Se a msg nao pede produto, retorne suggestions vazio
- SEMPRE retorne JSON valido, sem texto fora do JSON";

    // ── Build messages array ──
    $messages = [];
    foreach ($history as $h) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    // ── Save user message ──
    $stmt = $db->prepare("INSERT INTO om_ai_customer_messages (conversation_id, role, content) VALUES (?, 'user', ?)");
    $stmt->execute([$conversationId, $message]);

    // ── Call Claude ──
    $claude = new ClaudeClient();
    $result = $claude->send($systemPrompt, $messages, 4096);

    if (!$result['success']) {
        // Fallback: return search results directly
        $fallbackSuggestions = array_map(fn($h) => [
            'type' => 'product',
            'id' => $h['id'] ?? 0,
            'name' => $h['name'] ?? $h['nome'] ?? '',
            'price' => floatval($h['price'] ?? $h['preco'] ?? 0),
            'image' => $h['image'] ?? '',
            'partner_id' => (int)($h['partner_id'] ?? 0),
            'partner_name' => $h['partner_name'] ?? '',
        ], array_slice($searchResults, 0, 5));

        response(true, [
            'conversation_id' => $conversationId,
            'response_text' => "Oi {$ctx['firstName']}! Encontrei estes produtos pra voce:",
            'suggestions' => $fallbackSuggestions,
            'quick_actions' => [],
        ]);
    }

    $parsed = ClaudeClient::parseJson($result['text']);
    $responseText = $parsed['response_text'] ?? $result['text'];
    $suggestions = $parsed['suggestions'] ?? [];
    $quickActions = $parsed['quick_actions'] ?? [];

    // ── Save assistant message ──
    $stmt = $db->prepare("INSERT INTO om_ai_customer_messages (conversation_id, role, content, tokens_used, model) VALUES (?, 'assistant', ?, ?, ?)");
    $stmt->execute([$conversationId, $responseText, $result['total_tokens'] ?? 0, $result['model'] ?? '']);

    response(true, [
        'conversation_id' => $conversationId,
        'response_text' => $responseText,
        'suggestions' => $suggestions,
        'quick_actions' => $quickActions,
    ]);

} catch (Exception $e) {
    error_log("[ai-assistant POST] Error: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
