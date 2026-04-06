<?php
/**
 * Smart Push Notifications — AI-Powered Personalization
 * Cron every 30 min
 *
 * Push types:
 *   1. abandoned_cart   — Items in cart >2h without order
 *   2. meal_time        — AI-personalized meal suggestions based on time of day
 *   3. streak           — Win-back for customers who haven't ordered recently
 *   4. promo            — Active promotions matching customer preferences
 *   5. new_item         — New products at favorite stores
 *   6. personalized     — AI-generated based on full customer profile
 *
 * Rate limit: max 3 pushes per customer per day
 * Batch: 50 customers per run per push type
 * AI: Uses Claude to generate personalized messages in Portuguese
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/NotificationSender.php';
require_once __DIR__ . '/../helpers/claude-client.php';

// SECURITY: Cron auth guard — header only, no GET param
if (php_sapi_name() !== 'cli') {
    $cronKey = $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    $expectedKey = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
    if (empty($expectedKey) || empty($cronKey) || !hash_equals($expectedKey, $cronKey)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$db = getDB();
$sender = NotificationSender::getInstance($db);
$sent = 0;
$errors = 0;
$startTime = microtime(true);
$currentHour = (int)date('G');
$currentDay = (int)date('N'); // 1=Monday, 7=Sunday

// ─── Rate Limit Helpers ──────────────────────────────────────────────

/**
 * Check if we can send another push to this customer today (max 3/day)
 */
function canSendPush(PDO $db, int $customerId, int $maxPerDay = 3): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM om_smart_push_log
        WHERE customer_id = ? AND sent_at > NOW() - INTERVAL '24 hours'
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetchColumn() < $maxPerDay;
}

/**
 * Check if a specific push type was already sent today
 */
function wasPushTypeSentToday(PDO $db, int $customerId, string $pushType): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM om_smart_push_log
        WHERE customer_id = ? AND push_type = ? AND sent_at > NOW() - INTERVAL '20 hours'
        LIMIT 1
    ");
    $stmt->execute([$customerId, $pushType]);
    return (bool)$stmt->fetch();
}

/**
 * Atomically log push and claim the slot. Returns the push ID if inserted, 0 if duplicate.
 */
function logPushAtomic(PDO $db, int $customerId, string $type, string $title, string $body, bool $aiGenerated = false, array $metadata = []): int {
    $stmt = $db->prepare("
        INSERT INTO om_smart_push_log (customer_id, push_type, title, body, ai_generated, metadata)
        SELECT ?, ?, ?, ?, ?, ?::jsonb
        WHERE (SELECT COUNT(*) FROM om_smart_push_log
               WHERE customer_id = ? AND push_type = ? AND sent_at > NOW() - INTERVAL '20 hours') = 0
        RETURNING id
    ");
    $stmt->execute([
        $customerId, $type, $title, $body, $aiGenerated ? 'true' : 'false',
        json_encode($metadata, JSON_UNESCAPED_UNICODE),
        $customerId, $type,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : 0;
}

/**
 * Send push and track it. Returns true on success.
 */
function sendTrackedPush(
    NotificationSender $sender,
    PDO $db,
    int $customerId,
    string $type,
    string $title,
    string $body,
    array $data = [],
    bool $aiGenerated = false,
    array $metadata = []
): bool {
    if (!canSendPush($db, $customerId)) return false;
    if (wasPushTypeSentToday($db, $customerId, $type)) return false;

    try {
        $pushId = logPushAtomic($db, $customerId, $type, $title, $body, $aiGenerated, $metadata);
        if (!$pushId) return false;

        $data['push_id'] = $pushId;
        $data['type'] = $type;
        $sender->notifyCustomer($customerId, $title, $body, $data);
        return true;
    } catch (Exception $e) {
        error_log("[SmartPush] Error sending $type to customer $customerId: " . $e->getMessage());
        return false;
    }
}

// ─── Customer Profile Helper ─────────────────────────────────────────

/**
 * Fetch customer order profile for AI personalization.
 * Returns array with order history, favorite stores, products, patterns.
 */
function getCustomerProfile(PDO $db, int $customerId): ?array {
    // Basic customer info
    $stmt = $db->prepare("SELECT customer_id, name, city FROM om_market_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) return null;

    $firstName = explode(' ', trim($customer['name'] ?? ''))[0];

    // Order stats (last 60 days)
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total_orders,
            COALESCE(AVG(total), 0) as avg_ticket,
            MAX(created_at) as last_order_at,
            EXTRACT(EPOCH FROM (NOW() - MAX(created_at))) / 86400 as days_since_last
        FROM om_market_orders
        WHERE customer_id = ? AND status = 'entregue' AND created_at > NOW() - INTERVAL '60 days'
    ");
    $stmt->execute([$customerId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Favorite stores (top 5 by order count)
    $stmt = $db->prepare("
        SELECT p.partner_id, p.name as store_name, p.store_type,
               COUNT(*) as order_count
        FROM om_market_orders o
        JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.customer_id = ? AND o.status = 'entregue' AND o.created_at > NOW() - INTERVAL '90 days'
        GROUP BY p.partner_id, p.name, p.store_type
        ORDER BY order_count DESC
        LIMIT 5
    ");
    $stmt->execute([$customerId]);
    $favoriteStores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top ordered products (last 90 days)
    $stmt = $db->prepare("
        SELECT oi.product_name, oi.category_name, COUNT(*) as times_ordered
        FROM om_market_order_items oi
        JOIN om_market_orders o ON o.order_id = oi.order_id
        WHERE o.customer_id = ? AND o.status = 'entregue' AND o.created_at > NOW() - INTERVAL '90 days'
        GROUP BY oi.product_name, oi.category_name
        ORDER BY times_ordered DESC
        LIMIT 10
    ");
    $stmt->execute([$customerId]);
    $favoriteProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Order time patterns (hour distribution)
    $stmt = $db->prepare("
        SELECT
            EXTRACT(HOUR FROM created_at)::int as hour,
            EXTRACT(DOW FROM created_at)::int as dow,
            COUNT(*) as count
        FROM om_market_orders
        WHERE customer_id = ? AND status = 'entregue' AND created_at > NOW() - INTERVAL '90 days'
        GROUP BY hour, dow
        ORDER BY count DESC
        LIMIT 10
    ");
    $stmt->execute([$customerId]);
    $timePatterns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Active coupons
    $stmt = $db->prepare("
        SELECT code, type, value, expires_at
        FROM om_market_customer_coupons
        WHERE customer_id = ? AND is_used = 0 AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 3
    ");
    $stmt->execute([$customerId]);
    $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'customer_id' => $customerId,
        'first_name' => $firstName,
        'city' => $customer['city'] ?? '',
        'total_orders' => (int)($stats['total_orders'] ?? 0),
        'avg_ticket' => round((float)($stats['avg_ticket'] ?? 0), 2),
        'days_since_last' => round((float)($stats['days_since_last'] ?? 999), 1),
        'last_order_at' => $stats['last_order_at'] ?? null,
        'favorite_stores' => $favoriteStores,
        'favorite_products' => $favoriteProducts,
        'time_patterns' => $timePatterns,
        'coupons' => $coupons,
    ];
}

// ─── AI Message Generator ────────────────────────────────────────────

/**
 * Use Claude AI to generate a personalized push notification.
 * Returns ['title' => string, 'body' => string] or null on failure.
 */
function generateAIMessage(array $profile, string $pushType, array $context = []): ?array {
    static $claude = null;
    static $callCount = 0;

    // Safety: max 15 AI calls per cron run to control costs
    if ($callCount >= 15) return null;

    if ($claude === null) {
        $claude = new ClaudeClient(ClaudeClient::DEFAULT_MODEL, 30, 0); // 30s timeout, no retries for cron
    }

    $dayNames = ['', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado', 'Domingo'];
    $currentDay = $dayNames[(int)date('N')] ?? '';
    $currentHour = (int)date('G');

    $timeOfDay = 'dia';
    if ($currentHour >= 6 && $currentHour < 11) $timeOfDay = 'manha';
    elseif ($currentHour >= 11 && $currentHour < 14) $timeOfDay = 'almoco';
    elseif ($currentHour >= 14 && $currentHour < 17) $timeOfDay = 'tarde';
    elseif ($currentHour >= 17 && $currentHour < 21) $timeOfDay = 'jantar';
    elseif ($currentHour >= 21 || $currentHour < 6) $timeOfDay = 'noite';

    $systemPrompt = <<<PROMPT
Voce e um especialista em marketing de delivery de alimentos e compras de mercado.
Gere UMA notificacao push personalizada para o app SuperBora (delivery e mercado online).

REGRAS OBRIGATORIAS:
- Titulo: maximo 60 caracteres, direto e atrativo
- Corpo: maximo 150 caracteres, personalizado e com call-to-action
- Use o nome do cliente quando disponivel
- Inclua 1-2 emojis relevantes
- Tom casual e amigavel, em portugues brasileiro
- Seja especifico (mencione loja, produto ou hora quando relevante)
- NAO use acentos especiais que possam quebrar em push (use "voce" nao "você")
- Responda SOMENTE em JSON: {"title": "...", "body": "..."}
PROMPT;

    // Build context message
    $contextParts = [];
    $contextParts[] = "Cliente: " . ($profile['first_name'] ?: 'Cliente');
    $contextParts[] = "Cidade: " . ($profile['city'] ?: 'desconhecida');
    $contextParts[] = "Pedidos nos ultimos 60 dias: " . $profile['total_orders'];
    $contextParts[] = "Ticket medio: R$ " . number_format($profile['avg_ticket'], 2, ',', '.');
    $contextParts[] = "Dias desde ultimo pedido: " . $profile['days_since_last'];
    $contextParts[] = "Horario atual: {$currentHour}h ({$timeOfDay})";
    $contextParts[] = "Dia: {$currentDay}";

    if (!empty($profile['favorite_stores'])) {
        $stores = array_map(fn($s) => $s['store_name'] . " (" . $s['store_type'] . ", " . $s['order_count'] . "x)", $profile['favorite_stores']);
        $contextParts[] = "Lojas favoritas: " . implode(', ', array_slice($stores, 0, 3));
    }

    if (!empty($profile['favorite_products'])) {
        $products = array_map(fn($p) => $p['product_name'] . " (" . $p['times_ordered'] . "x)", $profile['favorite_products']);
        $contextParts[] = "Produtos favoritos: " . implode(', ', array_slice($products, 0, 5));
    }

    if (!empty($profile['coupons'])) {
        $coupons = array_map(fn($c) => $c['code'] . " (R$ " . number_format((float)$c['value'], 2, ',', '.') . ")", $profile['coupons']);
        $contextParts[] = "Cupons ativos: " . implode(', ', $coupons);
    }

    if (!empty($context['promo_title'])) {
        $contextParts[] = "Promocao ativa: " . $context['promo_title'];
    }
    if (!empty($context['new_products'])) {
        $contextParts[] = "Produtos novos na loja favorita: " . implode(', ', array_slice($context['new_products'], 0, 5));
    }
    if (!empty($context['store_name'])) {
        $contextParts[] = "Loja em destaque: " . $context['store_name'];
    }

    $pushTypeDescriptions = [
        'meal_time' => "Sugestao para a refeicao ({$timeOfDay}). Sugira algo baseado nos habitos do cliente e no horario.",
        'streak' => "Win-back: cliente nao pede ha " . round($profile['days_since_last']) . " dias. Crie urgencia ou nostalgia.",
        'promo' => "Promocao disponivel que combina com os gostos do cliente. Destaque o desconto.",
        'new_item' => "Novidades em loja favorita do cliente. Crie curiosidade.",
        'personalized' => "Mensagem 100% personalizada baseada no perfil completo. Seja criativo e relevante.",
        'habitual_time' => "O cliente costuma pedir nesse horario. Lembre-o de forma amigavel.",
    ];

    $contextParts[] = "Tipo de push: " . ($pushTypeDescriptions[$pushType] ?? $pushType);

    $userMessage = implode("\n", $contextParts);

    $result = $claude->send($systemPrompt, [
        ['role' => 'user', 'content' => $userMessage],
    ], 256);

    $callCount++;

    if (!$result['success']) {
        error_log("[SmartPush AI] Claude error: " . ($result['error'] ?? 'unknown'));
        return null;
    }

    $parsed = ClaudeClient::parseJson($result['text']);
    if (!$parsed || empty($parsed['title']) || empty($parsed['body'])) {
        error_log("[SmartPush AI] Failed to parse response: " . substr($result['text'] ?? '', 0, 200));
        return null;
    }

    // Enforce length limits
    $parsed['title'] = mb_substr(strip_tags($parsed['title']), 0, 80);
    $parsed['body'] = mb_substr(strip_tags($parsed['body']), 0, 200);

    return $parsed;
}

// ─── Fallback Messages (when AI is unavailable) ─────────────────────

function getFallbackMessage(string $pushType, array $profile): array {
    $name = $profile['first_name'] ?: 'Ola';

    $messages = [
        'meal_time' => [
            ['title' => "Hora do pedido, {$name}! 🍽️", 'body' => "Seus pratos favoritos estao esperando por voce. Que tal pedir agora?"],
            ['title' => "{$name}, bora almocar? 🥗", 'body' => "Seus restaurantes favoritos estao abertos e prontos pra entregar!"],
            ['title' => "Lanche da tarde, {$name}? 🍰", 'body' => "Aquele docinho ou cafe que voce adora esta a poucos toques!"],
        ],
        'streak' => [
            ['title' => "Sentimos sua falta, {$name}! 💙", 'body' => "Faz tempo que voce nao pede. Seus favoritos continuam aqui!"],
            ['title' => "{$name}, tudo bem? 🤗", 'body' => "Seus restaurantes favoritos tem novidades! Volte e confira."],
        ],
        'promo' => [
            ['title' => "Oferta especial pra voce! 🏷️", 'body' => "{$name}, tem desconto nos seus favoritos. Aproveite antes que acabe!"],
        ],
        'new_item' => [
            ['title' => "Novidades pra voce, {$name}! ✨", 'body' => "Sua loja favorita adicionou novos produtos. Confira agora!"],
        ],
        'personalized' => [
            ['title' => "{$name}, tem algo especial! 🎁", 'body' => "Preparamos sugestoes baseadas no que voce mais gosta. Confira!"],
        ],
        'habitual_time' => [
            ['title' => "Hora do pedido, {$name}! ⏰", 'body' => "Voce costuma pedir agora. Que tal repetir a dose?"],
        ],
        'abandoned_cart' => [
            ['title' => "Esqueceu algo, {$name}? 🛒", 'body' => "Seus itens estao esperando no carrinho. Finalize antes que acabe!"],
        ],
    ];

    $options = $messages[$pushType] ?? $messages['personalized'];
    return $options[array_rand($options)];
}

// ─── Determine Time Window ──────────────────────────────────────────

/**
 * Only send pushes during reasonable hours (8:00 - 22:00 Sao Paulo time)
 */
function isWithinSendWindow(): bool {
    $hour = (int)date('G');
    return $hour >= 8 && $hour <= 22;
}

if (!isWithinSendWindow()) {
    echo date('Y-m-d H:i:s') . " — Smart push: outside send window (8h-22h), skipping\n";
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// 1. ABANDONED CARTS — Items in cart >2h without order
// ═══════════════════════════════════════════════════════════════════════

$stmt = $db->query("
    SELECT DISTINCT c.customer_id
    FROM om_market_cart c
    WHERE c.updated_at < NOW() - INTERVAL '2 hours'
    AND c.updated_at > NOW() - INTERVAL '24 hours'
    AND c.customer_id IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM om_market_orders o
        WHERE o.customer_id = c.customer_id
        AND o.created_at > c.updated_at
    )
    AND NOT EXISTS (
        SELECT 1 FROM om_smart_push_log sp
        WHERE sp.customer_id = c.customer_id
        AND sp.push_type = 'abandoned_cart'
        AND sp.sent_at > NOW() - INTERVAL '24 hours'
    )
    LIMIT 50
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $profile = getCustomerProfile($db, $row['customer_id']);
    if (!$profile) continue;

    $msg = generateAIMessage($profile, 'abandoned_cart');
    $isAI = !empty($msg);
    if (!$msg) $msg = getFallbackMessage('abandoned_cart', $profile);

    if (sendTrackedPush($sender, $db, $row['customer_id'], 'abandoned_cart', $msg['title'], $msg['body'],
        ['route' => '/carrinho'], $isAI)) {
        $sent++;
    }
}

// ═══════════════════════════════════════════════════════════════════════
// 2. MEAL TIME — AI-personalized suggestions based on time of day
// ═══════════════════════════════════════════════════════════════════════

// Only run at meal-relevant times: 11:00-12:30 (lunch), 14:30-15:30 (snack), 17:30-19:00 (dinner)
$isMealTime = ($currentHour >= 11 && $currentHour <= 12)
           || ($currentHour >= 14 && $currentHour <= 15)
           || ($currentHour >= 17 && $currentHour <= 19);

if ($isMealTime) {
    // Find customers who have ordered at this time before (at least 2 orders)
    $stmt = $db->prepare("
        SELECT customer_id, COUNT(*) as order_count
        FROM om_market_orders
        WHERE EXTRACT(HOUR FROM created_at) BETWEEN ? AND ?
        AND status = 'entregue'
        AND created_at > NOW() - INTERVAL '60 days'
        GROUP BY customer_id
        HAVING COUNT(*) >= 2
        ORDER BY COUNT(*) DESC
        LIMIT 50
    ");
    $stmt->execute([$currentHour, $currentHour + 1]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $profile = getCustomerProfile($db, $row['customer_id']);
        if (!$profile) continue;

        $msg = generateAIMessage($profile, 'meal_time');
        $isAI = !empty($msg);
        if (!$msg) $msg = getFallbackMessage('meal_time', $profile);

        if (sendTrackedPush($sender, $db, $row['customer_id'], 'meal_time', $msg['title'], $msg['body'],
            ['route' => '/'], $isAI)) {
            $sent++;
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════
// 3. STREAK / WIN-BACK — Haven't ordered in 3-14 days
// ═══════════════════════════════════════════════════════════════════════

$stmt = $db->query("
    SELECT o.customer_id,
           MAX(o.created_at) as last_order,
           EXTRACT(EPOCH FROM (NOW() - MAX(o.created_at))) / 86400 as days_inactive,
           COUNT(*) as total_orders
    FROM om_market_orders o
    WHERE o.status = 'entregue'
    AND o.created_at > NOW() - INTERVAL '90 days'
    AND NOT EXISTS (
        SELECT 1 FROM om_smart_push_log sp
        WHERE sp.customer_id = o.customer_id
        AND sp.push_type = 'streak'
        AND sp.sent_at > NOW() - INTERVAL '3 days'
    )
    GROUP BY o.customer_id
    HAVING EXTRACT(EPOCH FROM (NOW() - MAX(o.created_at))) / 86400 BETWEEN 3 AND 14
    AND COUNT(*) >= 2
    ORDER BY COUNT(*) DESC
    LIMIT 50
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $profile = getCustomerProfile($db, $row['customer_id']);
    if (!$profile) continue;

    $msg = generateAIMessage($profile, 'streak');
    $isAI = !empty($msg);
    if (!$msg) $msg = getFallbackMessage('streak', $profile);

    if (sendTrackedPush($sender, $db, $row['customer_id'], 'streak', $msg['title'], $msg['body'],
        ['route' => '/'], $isAI)) {
        $sent++;
    }
}

// ═══════════════════════════════════════════════════════════════════════
// 4. PROMO — Active promotions matching customer preferences
// ═══════════════════════════════════════════════════════════════════════

// Find active promotions
$promos = $db->query("
    SELECT promotion_id, title, description, type, bonus_amount
    FROM om_market_promotions
    WHERE status = 'active'
    AND (start_date IS NULL OR start_date <= NOW())
    AND (end_date IS NULL OR end_date > NOW())
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Also find products currently on sale at popular stores
$dealsStmt = $db->query("
    SELECT pp.partner_id, p.name as store_name, COUNT(*) as promo_count
    FROM om_market_partner_products pp
    JOIN om_market_partners p ON p.partner_id = pp.partner_id AND p.is_open = 1
    WHERE pp.price_promo IS NOT NULL
    AND pp.price_promo > 0
    AND pp.price_promo < pp.price
    AND pp.active = 1
    AND (pp.promo_end IS NULL OR pp.promo_end >= CURRENT_DATE)
    GROUP BY pp.partner_id, p.name
    HAVING COUNT(*) >= 3
    ORDER BY COUNT(*) DESC
    LIMIT 10
");
$storesWithDeals = $dealsStmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($promos) || !empty($storesWithDeals)) {
    // Find customers who ordered from stores with active promos
    $storeIds = array_column($storesWithDeals, 'partner_id');
    $storeNames = array_column($storesWithDeals, 'name', 'partner_id');

    if (!empty($storeIds)) {
        $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
        $stmt = $db->prepare("
            SELECT DISTINCT o.customer_id, o.partner_id
            FROM om_market_orders o
            WHERE o.partner_id IN ({$placeholders})
            AND o.status = 'entregue'
            AND o.created_at > NOW() - INTERVAL '60 days'
            AND NOT EXISTS (
                SELECT 1 FROM om_smart_push_log sp
                WHERE sp.customer_id = o.customer_id
                AND sp.push_type = 'promo'
                AND sp.sent_at > NOW() - INTERVAL '2 days'
            )
            ORDER BY o.customer_id
            LIMIT 50
        ");
        $stmt->execute($storeIds);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $profile = getCustomerProfile($db, $row['customer_id']);
            if (!$profile) continue;

            $storeName = $storeNames[$row['partner_id']] ?? '';
            $promoTitle = !empty($promos) ? $promos[0]['title'] : '';

            $msg = generateAIMessage($profile, 'promo', [
                'store_name' => $storeName,
                'promo_title' => $promoTitle,
            ]);
            $isAI = !empty($msg);
            if (!$msg) $msg = getFallbackMessage('promo', $profile);

            if (sendTrackedPush($sender, $db, $row['customer_id'], 'promo', $msg['title'], $msg['body'],
                ['route' => '/ofertas'], $isAI, ['partner_id' => $row['partner_id']])) {
                $sent++;
            }
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════
// 5. NEW ITEMS — New products at favorite stores (added in last 3 days)
// ═══════════════════════════════════════════════════════════════════════

// Only run once a day (at 10:00 or 16:00)
if ($currentHour === 10 || $currentHour === 16) {
    $stmt = $db->query("
        SELECT DISTINCT o.customer_id, o.partner_id, p.name as store_name,
            (SELECT array_agg(sub.name) FROM (
                SELECT pr.name FROM om_market_products pr
                WHERE pr.partner_id = o.partner_id
                AND pr.created_at > NOW() - INTERVAL '3 days'
                ORDER BY pr.created_at DESC
                LIMIT 5
            ) sub) as new_product_names
        FROM om_market_orders o
        JOIN om_market_partners p ON p.partner_id = o.partner_id AND p.is_open = 1
        WHERE o.status = 'entregue'
        AND o.created_at > NOW() - INTERVAL '60 days'
        AND EXISTS (
            SELECT 1 FROM om_market_products pr
            WHERE pr.partner_id = o.partner_id
            AND pr.created_at > NOW() - INTERVAL '3 days'
        )
        AND NOT EXISTS (
            SELECT 1 FROM om_smart_push_log sp
            WHERE sp.customer_id = o.customer_id
            AND sp.push_type = 'new_item'
            AND sp.sent_at > NOW() - INTERVAL '3 days'
        )
        ORDER BY o.customer_id
        LIMIT 50
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $profile = getCustomerProfile($db, $row['customer_id']);
        if (!$profile) continue;

        // Parse PostgreSQL array to PHP array
        $newProducts = [];
        if (!empty($row['new_product_names'])) {
            $raw = trim($row['new_product_names'], '{}');
            if ($raw) {
                $newProducts = array_map(fn($s) => trim($s, '"'), explode(',', $raw));
            }
        }

        $msg = generateAIMessage($profile, 'new_item', [
            'store_name' => $row['store_name'],
            'new_products' => $newProducts,
        ]);
        $isAI = !empty($msg);
        if (!$msg) $msg = getFallbackMessage('new_item', $profile);

        if (sendTrackedPush($sender, $db, $row['customer_id'], 'new_item', $msg['title'], $msg['body'],
            ['route' => '/loja/' . $row['partner_id']], $isAI, ['partner_id' => $row['partner_id']])) {
            $sent++;
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════
// 6. PERSONALIZED — AI-generated for high-value customers (daily at 12:00 or 18:00)
// ═══════════════════════════════════════════════════════════════════════

if ($currentHour === 12 || $currentHour === 18) {
    // Target high-value customers who haven't received a personalized push today
    $stmt = $db->query("
        SELECT o.customer_id,
               COUNT(*) as total_orders,
               SUM(o.total) as total_spent
        FROM om_market_orders o
        WHERE o.status = 'entregue'
        AND o.created_at > NOW() - INTERVAL '90 days'
        AND NOT EXISTS (
            SELECT 1 FROM om_smart_push_log sp
            WHERE sp.customer_id = o.customer_id
            AND sp.push_type = 'personalized'
            AND sp.sent_at > NOW() - INTERVAL '2 days'
        )
        GROUP BY o.customer_id
        HAVING COUNT(*) >= 5
        ORDER BY SUM(o.total) DESC
        LIMIT 20
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $profile = getCustomerProfile($db, $row['customer_id']);
        if (!$profile) continue;

        $msg = generateAIMessage($profile, 'personalized');
        $isAI = !empty($msg);
        if (!$msg) $msg = getFallbackMessage('personalized', $profile);

        if (sendTrackedPush($sender, $db, $row['customer_id'], 'personalized', $msg['title'], $msg['body'],
            ['route' => '/'], $isAI)) {
            $sent++;
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════
// 7. HABITUAL TIME — Customers who always order on this day/hour
// ═══════════════════════════════════════════════════════════════════════

$stmt = $db->prepare("
    SELECT customer_id, COUNT(*) as pattern_count
    FROM om_market_orders
    WHERE EXTRACT(HOUR FROM created_at) BETWEEN ? AND ?
    AND EXTRACT(DOW FROM created_at) = EXTRACT(DOW FROM NOW())
    AND status = 'entregue'
    AND created_at > NOW() - INTERVAL '60 days'
    GROUP BY customer_id
    HAVING COUNT(*) >= 2
    ORDER BY COUNT(*) DESC
    LIMIT 50
");
$stmt->execute([$currentHour, $currentHour + 1]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $profile = getCustomerProfile($db, $row['customer_id']);
    if (!$profile) continue;

    $msg = generateAIMessage($profile, 'habitual_time');
    $isAI = !empty($msg);
    if (!$msg) $msg = getFallbackMessage('habitual_time', $profile);

    if (sendTrackedPush($sender, $db, $row['customer_id'], 'habitual_time', $msg['title'], $msg['body'],
        ['route' => '/'], $isAI)) {
        $sent++;
    }
}

// ─── Summary ─────────────────────────────────────────────────────────

$elapsed = round((microtime(true) - $startTime) * 1000);
$summary = date('Y-m-d H:i:s') . " — Smart push: {$sent} notifications sent in {$elapsed}ms";
echo $summary . "\n";

// Log to cron_log table if it exists
try {
    $db->prepare("
        INSERT INTO om_cron_log (job_name, status, message, duration_ms, created_at)
        VALUES ('smart-push', 'success', ?, ?, NOW())
    ")->execute([$summary, $elapsed]);
} catch (Exception $e) {
    // Table may not exist — non-critical
}
