<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * WHATSAPP AI ORDERING BOT — SuperBora
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Z-API webhook handler that receives incoming WhatsApp messages and uses
 * Claude AI to handle ordering conversations automatically. Same AI brain
 * as the phone system but adapted for text-based WhatsApp.
 *
 * Webhook URL: POST /api/mercado/webhooks/whatsapp-ai.php
 * Z-API format: { "phone": "5519...", "text": { "message": "..." }, ... }
 *
 * Order flow steps:
 *   greeting -> identify_store -> take_order -> get_address -> get_payment -> confirm_order -> submit_order
 *
 * AI Markers parsed from Claude responses:
 *   [STORE:id:name]          — store identified
 *   [ITEM:product_id:name:price:qty]  — item added
 *   [REMOVE_ITEM:index]      — item removed
 *   [NEXT_STEP]              — move to next step
 *   [CONFIRMED]              — order confirmed
 *   [CANCEL_ORDER:order_id]  — cancel order
 *   [TRANSFER_HUMAN]         — transfer to human agent
 *   [SWITCH_TO_ORDER]        — switch from support to ordering mode
 *   [REORDER:order_number]   — repeat a previous order (smart reorder)
 *   [SCHEDULE:datetime]      — schedule order for future delivery
 *   [SAVE_ADDRESS]           — save new address to customer's saved addresses
 *   [COUPON:code]            — apply coupon code
 *   [USE_CASHBACK:value]     — use cashback balance (value or "all")
 */

// ═══════════════════════════════════════════════════════════════════════════
// BOOTSTRAP
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
require_once __DIR__ . '/../helpers/ai-memory.php';
require_once __DIR__ . '/../helpers/ai-safeguards.php';
require_once __DIR__ . '/../helpers/callcenter-sms.php';
require_once __DIR__ . '/../helpers/ws-callcenter-broadcast.php';
require_once __DIR__ . '/../helpers/whatsapp-rating.php';
require_once __DIR__ . '/../helpers/eta-calculator.php';
require_once __DIR__ . '/../helpers/cashback.php';

// ═══════════════════════════════════════════════════════════════════════════
// CONSTANTS
// ═══════════════════════════════════════════════════════════════════════════

define('WABOT_SESSION_TIMEOUT_MINUTES', 30);
define('WABOT_RATE_LIMIT_PER_MINUTE', 20);
define('WABOT_MAX_MESSAGE_LENGTH', 1500);
define('WABOT_CLAUDE_MODEL', 'claude-sonnet-4-20250514');
define('WABOT_CLAUDE_TIMEOUT', 45);
define('WABOT_SERVICE_FEE', 2.99);
define('WABOT_DEFAULT_DELIVERY_FEE', 7.99);
define('WABOT_LOG_PREFIX', '[whatsapp-ai]');

// Order flow steps
define('STEP_GREETING', 'greeting');
define('STEP_IDENTIFY_STORE', 'identify_store');
define('STEP_TAKE_ORDER', 'take_order');
define('STEP_GET_ADDRESS', 'get_address');
define('STEP_GET_PAYMENT', 'get_payment');
define('STEP_CONFIRM_ORDER', 'confirm_order');
define('STEP_SUBMIT_ORDER', 'submit_order');
define('STEP_SUPPORT', 'support');
define('STEP_COMPLETED', 'completed');

// ═══════════════════════════════════════════════════════════════════════════
// RATE LIMITING (in-memory via APCu or file-based fallback)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Check rate limit for a phone number.
 * Returns true if within limit, false if exceeded.
 */
function wabotCheckRateLimit(string $phone): bool
{
    $key = 'wabot_rate_' . preg_replace('/\D/', '', $phone);
    $now = time();
    $windowStart = $now - 60;

    // Try APCu first (fastest)
    if (function_exists('apcu_fetch')) {
        $timestamps = apcu_fetch($key) ?: [];
        $timestamps = array_filter($timestamps, fn($ts) => $ts > $windowStart);
        if (count($timestamps) >= WABOT_RATE_LIMIT_PER_MINUTE) {
            error_log(WABOT_LOG_PREFIX . " Rate limit exceeded for phone ending " . substr($phone, -4));
            return false;
        }
        $timestamps[] = $now;
        apcu_store($key, $timestamps, 120);
        return true;
    }

    // Fallback: file-based rate limiting
    $rateDir = sys_get_temp_dir() . '/wabot_rates';
    if (!is_dir($rateDir)) {
        @mkdir($rateDir, 0755, true);
    }
    $rateFile = $rateDir . '/' . $key . '.json';

    $timestamps = [];
    if (file_exists($rateFile)) {
        $data = @json_decode(@file_get_contents($rateFile), true);
        if (is_array($data)) {
            $timestamps = array_filter($data, fn($ts) => $ts > $windowStart);
        }
    }

    if (count($timestamps) >= WABOT_RATE_LIMIT_PER_MINUTE) {
        error_log(WABOT_LOG_PREFIX . " Rate limit exceeded for phone ending " . substr($phone, -4));
        return false;
    }

    $timestamps[] = $now;
    @file_put_contents($rateFile, json_encode(array_values($timestamps)), LOCK_EX);
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════
// CONVERSATION STATE MANAGEMENT
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Load or create a conversation for the given phone number.
 * If the last message was more than WABOT_SESSION_TIMEOUT_MINUTES ago, reset context.
 *
 * @return array The conversation row
 */
function loadOrCreateConversation(PDO $db, string $phone): array
{
    $normalizedPhone = preg_replace('/\D/', '', $phone);

    // Try to load existing conversation
    $stmt = $db->prepare("
        SELECT * FROM om_callcenter_whatsapp
        WHERE phone = ?
        ORDER BY last_message_at DESC
        LIMIT 1
    ");
    $stmt->execute([$normalizedPhone]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($conversation) {
        // Check if session has expired
        $lastMessage = strtotime($conversation['last_message_at'] ?? $conversation['created_at']);
        $elapsed = time() - $lastMessage;
        $expired = $elapsed > (WABOT_SESSION_TIMEOUT_MINUTES * 60);

        if ($expired) {
            // Reset context for new session but keep the conversation record
            $newContext = json_encode(getDefaultContext());
            $db->prepare("
                UPDATE om_callcenter_whatsapp
                SET ai_context = ?,
                    status = 'active',
                    unread_count = 0,
                    last_message_at = NOW()
                WHERE id = ?
            ")->execute([$newContext, $conversation['id']]);

            $conversation['ai_context'] = $newContext;
            $conversation['status'] = 'active';
            error_log(WABOT_LOG_PREFIX . " Session expired, reset context for phone ending " . substr($normalizedPhone, -4));
        }

        return $conversation;
    }

    // Look up customer by phone
    $customerInfo = lookupCustomerByPhone($db, $normalizedPhone);
    $customerId = $customerInfo['customer_id'] ?? null;
    $customerName = $customerInfo['name'] ?? null;

    // Create new conversation
    $defaultContext = json_encode(getDefaultContext());
    $stmt = $db->prepare("
        INSERT INTO om_callcenter_whatsapp
            (phone, customer_id, customer_name, status, ai_context, unread_count, last_message_at, created_at)
        VALUES
            (?, ?, ?, 'active', ?, 0, NOW(), NOW())
        RETURNING *
    ");
    $stmt->execute([$normalizedPhone, $customerId, $customerName, $defaultContext]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log(WABOT_LOG_PREFIX . " New conversation created for phone ending " . substr($normalizedPhone, -4));
    return $conversation;
}

/**
 * Get default AI context structure.
 */
function getDefaultContext(): array
{
    return [
        'step'           => STEP_GREETING,
        'mode'           => 'ordering', // ordering | support
        'partner_id'     => null,
        'partner_name'   => null,
        'items'          => [],
        'subtotal'       => 0,
        'delivery_fee'   => WABOT_DEFAULT_DELIVERY_FEE,
        'service_fee'    => WABOT_SERVICE_FEE,
        'total'          => 0,
        'address'        => null,
        'address_lat'    => null,
        'address_lng'    => null,
        'payment_method' => null,
        'payment_change' => null,
        'coupon_code'    => null,
        'coupon_id'      => null,
        'coupon_discount'=> 0,
        'coupon_description' => null,
        'coupon_free_delivery' => false,
        'use_cashback'   => 0,
        'notes'          => null,
        'scheduled_for'  => null,
        'is_new_address' => false,
        'message_count'  => 0,
        'session_start'  => date('c'),
        'rating_requested_for_order' => null,
        'rating_pending_value'       => null,
        'audio_count'        => 0,
        'sent_audio'         => false,
        'price_sensitive'    => false,
        'last_mentioned_store' => null,
    ];
}

/**
 * Update conversation context in DB.
 */
function updateConversationContext(PDO $db, int $conversationId, array $context): void
{
    $db->prepare("
        UPDATE om_callcenter_whatsapp
        SET ai_context = ?,
            last_message_at = NOW()
        WHERE id = ?
    ")->execute([json_encode($context, JSON_UNESCAPED_UNICODE), $conversationId]);
}

/**
 * Update conversation status.
 */
function updateConversationStatus(PDO $db, int $conversationId, string $status): void
{
    $db->prepare("
        UPDATE om_callcenter_whatsapp
        SET status = ?,
            last_message_at = NOW()
        WHERE id = ?
    ")->execute([$status, $conversationId]);
}

/**
 * Increment unread count.
 */
function incrementUnread(PDO $db, int $conversationId): void
{
    $db->prepare("
        UPDATE om_callcenter_whatsapp
        SET unread_count = unread_count + 1,
            last_message_at = NOW()
        WHERE id = ?
    ")->execute([$conversationId]);
}

// ═══════════════════════════════════════════════════════════════════════════
// CUSTOMER LOOKUP
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Look up a customer by phone number.
 * Tries multiple phone formats (with/without country code).
 */
function lookupCustomerByPhone(PDO $db, string $phone): ?array
{
    $digits = preg_replace('/\D/', '', $phone);
    $phonesSearch = [$digits];

    // If starts with 55, also try without
    if (strlen($digits) === 13 && str_starts_with($digits, '55')) {
        $phonesSearch[] = substr($digits, 2);
    }
    // If 11 digits, also try with 55
    if (strlen($digits) === 11) {
        $phonesSearch[] = '55' . $digits;
    }
    // If 10 digits (landline), try with 55
    if (strlen($digits) === 10) {
        $phonesSearch[] = '55' . $digits;
    }

    $placeholders = implode(',', array_fill(0, count($phonesSearch), '?'));

    $stmt = $db->prepare("
        SELECT customer_id, name, email, phone
        FROM om_market_customers
        WHERE REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), ' ', '') ILIKE ANY(ARRAY[" .
            implode(',', array_fill(0, count($phonesSearch), "'%' || ? || '%'")) .
        "])
        ORDER BY customer_id DESC
        LIMIT 1
    ");

    // For ILIKE pattern, just use the digits
    $params = $phonesSearch;
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return $row;
    }

    // Simpler fallback: direct match
    $stmt2 = $db->prepare("
        SELECT customer_id, name, email, phone
        FROM om_market_customers
        WHERE phone LIKE ? OR phone LIKE ? OR phone LIKE ?
        ORDER BY customer_id DESC
        LIMIT 1
    ");
    $likePatterns = [];
    foreach (array_slice($phonesSearch, 0, 3) as $p) {
        $likePatterns[] = '%' . substr($p, -9) . '%'; // Last 9 digits
    }
    while (count($likePatterns) < 3) {
        $likePatterns[] = '%' . substr($digits, -9) . '%';
    }
    $stmt2->execute($likePatterns);

    return $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Get customer's saved addresses.
 */
function getCustomerAddresses(PDO $db, int $customerId): array
{
    $stmt = $db->prepare("
        SELECT address_id, label, street, number, complement, neighborhood, city, state, zipcode, lat, lng, is_default
        FROM om_customer_addresses
        WHERE customer_id = ? AND is_active = '1'
        ORDER BY is_default DESC, created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get customer's recent orders.
 */
function getCustomerRecentOrders(PDO $db, int $customerId, int $limit = 5): array
{
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.partner_id, o.partner_name,
               o.total, o.status, o.forma_pagamento, o.delivery_address,
               o.date_added, o.created_at
        FROM om_market_orders o
        WHERE o.customer_id = ?
        ORDER BY o.date_added DESC
        LIMIT ?
    ");
    $stmt->execute([$customerId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get customer's active (in-progress) orders.
 */
function getCustomerActiveOrders(PDO $db, int $customerId): array
{
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.partner_id, o.partner_name,
               o.total, o.status, o.forma_pagamento, o.delivery_address,
               o.date_added, o.created_at, o.distancia_km, o.is_pickup,
               EXTRACT(EPOCH FROM (NOW() - o.date_added::timestamp)) / 60 AS minutes_ago
        FROM om_market_orders o
        WHERE o.customer_id = ?
          AND o.status NOT IN ('entregue', 'cancelado', 'recusado', 'retirado')
        ORDER BY o.date_added DESC
        LIMIT 5
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get a specific order by order number (for a given customer).
 */
function getCustomerOrderByNumber(PDO $db, int $customerId, string $orderNumber): ?array
{
    $stmt = $db->prepare("
        SELECT o.order_id, o.order_number, o.partner_id, o.partner_name,
               o.total, o.status, o.forma_pagamento, o.delivery_address,
               o.date_added, o.created_at
        FROM om_market_orders o
        WHERE o.customer_id = ? AND o.order_number = ?
        LIMIT 1
    ");
    $stmt->execute([$customerId, $orderNumber]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Get items from a specific order (for reorder).
 * Joins with om_market_products to get current name, price, and status.
 */
function getOrderItems(PDO $db, int $orderId): array
{
    $stmt = $db->prepare("
        SELECT oi.product_id, oi.name, oi.price, oi.quantity, oi.total,
               oi.options,
               p.name AS current_name, p.price AS current_price,
               p.special_price AS current_special_price,
               p.status AS product_status, p.quantity AS product_stock
        FROM om_market_order_items oi
        LEFT JOIN om_market_products p ON p.product_id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.id
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ═══════════════════════════════════════════════════════════════════════════
// STORE & MENU
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Get list of active stores/partners.
 */
function getActiveStores(PDO $db, int $limit = 20): array
{
    $stmt = $db->prepare("
        SELECT p.partner_id, p.name, p.trade_name, p.categoria, p.is_open,
               p.delivery_fee, p.min_order_value, p.delivery_time_min,
               p.rating, p.total_orders
        FROM om_market_partners p
        WHERE p.status::text = '1'
        ORDER BY p.is_open DESC, p.rating DESC NULLS LAST, p.total_orders DESC NULLS LAST
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Search for a store by name (fuzzy match).
 */
function searchStoreByName(PDO $db, string $query): ?array
{
    $query = trim($query);
    if (empty($query)) return null;

    // Exact match first
    $stmt = $db->prepare("
        SELECT partner_id, name, trade_name, categoria, is_open,
               delivery_fee, min_order_value, delivery_time_min, rating
        FROM om_market_partners
        WHERE status::text = '1'
        AND (LOWER(name) = LOWER(?) OR LOWER(trade_name) = LOWER(?))
        LIMIT 1
    ");
    $stmt->execute([$query, $query]);
    $exact = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($exact) return $exact;

    // Fuzzy match (ILIKE)
    $stmt2 = $db->prepare("
        SELECT partner_id, name, trade_name, categoria, is_open,
               delivery_fee, min_order_value, delivery_time_min, rating
        FROM om_market_partners
        WHERE status::text = '1'
        AND (name ILIKE ? OR trade_name ILIKE ?)
        ORDER BY is_open DESC, rating DESC NULLS LAST
        LIMIT 1
    ");
    $pattern = '%' . $query . '%';
    $stmt2->execute([$pattern, $pattern]);
    $fuzzy = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($fuzzy) return $fuzzy;

    // Try trigram / similarity if available
    $stmt3 = $db->prepare("
        SELECT partner_id, name, trade_name, categoria, is_open,
               delivery_fee, min_order_value, delivery_time_min, rating
        FROM om_market_partners
        WHERE status::text = '1'
        AND (
            LOWER(name) LIKE ? OR LOWER(trade_name) LIKE ?
            OR LOWER(name) LIKE ? OR LOWER(trade_name) LIKE ?
        )
        ORDER BY is_open DESC, rating DESC NULLS LAST
        LIMIT 3
    ");
    $words = explode(' ', $query);
    $firstWord = '%' . strtolower($words[0]) . '%';
    $lastWord = '%' . strtolower(end($words)) . '%';
    $stmt3->execute([$firstWord, $firstWord, $lastWord, $lastWord]);

    return $stmt3->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Fetch the full menu for a store: categories -> products -> options.
 * Formats the menu as a text block for Claude's context.
 */
function fetchStoreMenu(PDO $db, int $partnerId): string
{
    // Get store info
    $storeStmt = $db->prepare("
        SELECT name, trade_name, categoria, is_open, min_order_value,
               delivery_fee, delivery_time_min
        FROM om_market_partners
        WHERE partner_id = ?
    ");
    $storeStmt->execute([$partnerId]);
    $store = $storeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$store) return 'Loja nao encontrada.';

    $storeName = $store['trade_name'] ?: $store['name'];

    // Get categories
    $catStmt = $db->prepare("
        SELECT DISTINCT c.category_id, c.name as category_name, c.sort_order
        FROM om_market_categories c
        INNER JOIN om_market_products p ON p.category_id = c.category_id
        WHERE p.partner_id = ? AND p.status = 1
        ORDER BY c.sort_order ASC, c.name ASC
    ");
    $catStmt->execute([$partnerId]);
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all products for this store
    $prodStmt = $db->prepare("
        SELECT p.product_id, p.name, p.description, p.price, p.special_price,
               p.category_id, p.quantity as stock, p.status
        FROM om_market_products p
        WHERE p.partner_id = ? AND p.status = 1 AND p.quantity > 0
        ORDER BY p.sort_order ASC, p.name ASC
    ");
    $prodStmt->execute([$partnerId]);
    $products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get product options
    $optStmt = $db->prepare("
        SELECT po.id as option_id, po.product_id, po.name as option_name,
               po.price as option_price, po.required, po.group_name
        FROM om_market_product_options po
        INNER JOIN om_market_products p ON p.product_id = po.product_id
        WHERE p.partner_id = ? AND p.status = 1
        ORDER BY po.group_name, po.sort_order ASC, po.name ASC
    ");
    $optStmt->execute([$partnerId]);
    $allOptions = $optStmt->fetchAll(PDO::FETCH_ASSOC);

    // Index options by product_id
    $optionsByProduct = [];
    foreach ($allOptions as $opt) {
        $pid = $opt['product_id'];
        if (!isset($optionsByProduct[$pid])) {
            $optionsByProduct[$pid] = [];
        }
        $optionsByProduct[$pid][] = $opt;
    }

    // Index products by category
    $productsByCat = [];
    $uncategorized = [];
    foreach ($products as $prod) {
        $catId = $prod['category_id'];
        if ($catId) {
            $productsByCat[$catId][] = $prod;
        } else {
            $uncategorized[] = $prod;
        }
    }

    // Build menu text
    $lines = [];
    $lines[] = "=== CARDAPIO: {$storeName} ===";
    $lines[] = "Pedido minimo: R$ " . number_format((float)($store['min_order_value'] ?? 0), 2, ',', '.');
    $lines[] = "Tempo de entrega: " . ($store['delivery_time_min'] ?? '30-50') . " min";
    $lines[] = "Status: " . ((int)($store['is_open'] ?? 0) === 1 ? 'ABERTA' : 'FECHADA');
    $lines[] = "";

    foreach ($categories as $cat) {
        $catId = $cat['category_id'];
        $catProducts = $productsByCat[$catId] ?? [];
        if (empty($catProducts)) continue;

        $lines[] = "--- {$cat['category_name']} ---";

        foreach ($catProducts as $prod) {
            $price = ((float)($prod['special_price'] ?? 0) > 0 && (float)$prod['special_price'] < (float)$prod['price'])
                ? (float)$prod['special_price']
                : (float)$prod['price'];

            $priceFmt = number_format($price, 2, ',', '.');
            $desc = $prod['description'] ? ' - ' . mb_substr($prod['description'], 0, 80) : '';
            $lines[] = "  [{$prod['product_id']}] {$prod['name']} ... R\$ {$priceFmt}{$desc}";

            // Show options
            $opts = $optionsByProduct[$prod['product_id']] ?? [];
            if (!empty($opts)) {
                $groups = [];
                foreach ($opts as $o) {
                    $gn = $o['group_name'] ?: 'Opcoes';
                    $groups[$gn][] = $o;
                }
                foreach ($groups as $groupName => $groupOpts) {
                    $req = ($groupOpts[0]['required'] ?? false) ? ' (obrigatorio)' : '';
                    $lines[] = "    > {$groupName}{$req}:";
                    foreach ($groupOpts as $o) {
                        $optPrice = (float)($o['option_price'] ?? 0);
                        $optPriceFmt = $optPrice > 0 ? ' +R$ ' . number_format($optPrice, 2, ',', '.') : '';
                        $lines[] = "      - [{$o['option_id']}] {$o['option_name']}{$optPriceFmt}";
                    }
                }
            }
        }
        $lines[] = "";
    }

    // Uncategorized products
    if (!empty($uncategorized)) {
        $lines[] = "--- Outros ---";
        foreach ($uncategorized as $prod) {
            $price = ((float)($prod['special_price'] ?? 0) > 0 && (float)$prod['special_price'] < (float)$prod['price'])
                ? (float)$prod['special_price']
                : (float)$prod['price'];
            $priceFmt = number_format($price, 2, ',', '.');
            $lines[] = "  [{$prod['product_id']}] {$prod['name']} ... R\$ {$priceFmt}";
        }
    }

    $lines[] = "=== FIM DO CARDAPIO ===";

    return implode("\n", $lines);
}

/**
 * Get a specific product with its price (for validation).
 */
function getProduct(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare("
        SELECT product_id, name, price, special_price, quantity as stock, partner_id, status
        FROM om_market_products
        WHERE product_id = ?
    ");
    $stmt->execute([$productId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Get most ordered items from a store (top sellers / "mais pedidos").
 * Queries actual order history to find what people order most.
 *
 * @return array [['product_id'=>int, 'name'=>string, 'price'=>float, 'order_count'=>int], ...]
 */
function getPopularItems(PDO $db, int $partnerId, int $limit = 5): array
{
    try {
        $stmt = $db->prepare("
            SELECT p.product_id, p.name,
                   CASE WHEN p.special_price IS NOT NULL AND p.special_price > 0 AND p.special_price < p.price
                        THEN p.special_price ELSE p.price END AS price,
                   COUNT(DISTINCT oi.order_id) AS order_count
            FROM om_market_order_items oi
            INNER JOIN om_market_products p ON p.product_id = oi.product_id
            INNER JOIN om_market_orders o ON o.order_id = oi.order_id
            WHERE o.partner_id = ?
              AND p.status::text = '1'
              AND p.quantity > 0
              AND o.status NOT IN ('cancelado', 'cancelled', 'recusado')
            GROUP BY p.product_id, p.name, p.price, p.special_price
            ORDER BY order_count DESC
            LIMIT ?
        ");
        $stmt->execute([$partnerId, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return [
                'product_id'  => (int)$r['product_id'],
                'name'        => $r['name'],
                'price'       => (float)$r['price'],
                'order_count' => (int)$r['order_count'],
            ];
        }, $rows);
    } catch (\Exception $e) {
        error_log(WABOT_LOG_PREFIX . " getPopularItems error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get complementary item suggestions based on what's already in the cart.
 * Uses category-based heuristics:
 *   - Cart has food -> suggest drinks
 *   - Cart has pizza/burger -> suggest sides (acompanhamentos)
 *   - Cart has acai/ice cream -> suggest toppings/extras
 *   - General: suggest items from complementary categories not yet in cart
 *
 * @param array $cartItems Items currently in cart [['product_id'=>int, 'name'=>string, ...], ...]
 * @return array [['product_id'=>int, 'name'=>string, 'price'=>float, 'category'=>string], ...]
 */
function getComplementaryItems(PDO $db, int $partnerId, array $cartItems, int $limit = 5): array
{
    if (empty($cartItems)) return [];

    try {
        // Collect product_ids already in cart to exclude
        $cartProductIds = [];
        foreach ($cartItems as $item) {
            if (!empty($item['product_id'])) {
                $cartProductIds[] = (int)$item['product_id'];
            }
        }
        if (empty($cartProductIds)) return [];

        // Detect what's in cart by analyzing item names and their categories
        $cartNames = implode(' ', array_map(function ($it) {
            return mb_strtolower($it['name'] ?? '');
        }, $cartItems));

        // Build category keywords to search for complementary items
        $complementaryCategories = [];

        // If cart has main dishes / food -> suggest drinks
        $hasFoodKeywords = preg_match('/pizza|hambur|lanche|sanduich|prato|refeic|arroz|feij|carne|frango|x-|x burger|hot dog|cachorro|coxinha|pastel|esfiha|salgad/i', $cartNames);
        if ($hasFoodKeywords) {
            $complementaryCategories = array_merge($complementaryCategories, [
                'bebida', 'drink', 'refri', 'suco', 'refrigerante', 'agua',
                'sobremesa', 'doce', 'sorvete',
            ]);
        }

        // If cart has pizza/burger -> suggest sides
        $hasPizzaBurger = preg_match('/pizza|hambur|lanche|sanduich|x-|x burger|hot dog/i', $cartNames);
        if ($hasPizzaBurger) {
            $complementaryCategories = array_merge($complementaryCategories, [
                'acompanhamento', 'porcao', 'entrada', 'batata', 'onion', 'molho',
            ]);
        }

        // If cart has acai/ice cream/dessert -> suggest toppings
        $hasAcaiIceCream = preg_match('/acai|açaí|sorvete|milk ?shake|sundae|frozen/i', $cartNames);
        if ($hasAcaiIceCream) {
            $complementaryCategories = array_merge($complementaryCategories, [
                'adicional', 'topping', 'extra', 'complemento', 'cobertura', 'calda',
            ]);
        }

        // If cart has drinks only -> suggest food
        $hasDrinksOnly = preg_match('/suco|refri|agua|cerveja|drink|bebida|refrigerante/i', $cartNames)
                      && !$hasFoodKeywords && !$hasAcaiIceCream;
        if ($hasDrinksOnly) {
            $complementaryCategories = array_merge($complementaryCategories, [
                'lanche', 'porcao', 'entrada', 'salgado', 'petisco',
            ]);
        }

        // Default: always include drinks and acompanhamentos if nothing specific matched
        if (empty($complementaryCategories)) {
            $complementaryCategories = ['bebida', 'drink', 'acompanhamento', 'sobremesa', 'porcao'];
        }

        $complementaryCategories = array_unique($complementaryCategories);

        // Build LIKE clauses for category name matching
        $likeClauses = [];
        $params = [$partnerId];
        foreach ($complementaryCategories as $kw) {
            $likeClauses[] = "LOWER(c.name) LIKE ?";
            $params[] = '%' . mb_strtolower($kw) . '%';
        }
        $likeWhere = '(' . implode(' OR ', $likeClauses) . ')';

        // Exclude cart product_ids
        $excludePlaceholders = implode(',', array_fill(0, count($cartProductIds), '?'));
        $params = array_merge($params, $cartProductIds);
        $params[] = $limit;

        $stmt = $db->prepare("
            SELECT p.product_id, p.name,
                   CASE WHEN p.special_price IS NOT NULL AND p.special_price > 0 AND p.special_price < p.price
                        THEN p.special_price ELSE p.price END AS price,
                   c.name AS category
            FROM om_market_products p
            INNER JOIN om_market_categories c ON c.category_id = p.category_id
            WHERE p.partner_id = ?
              AND p.status::text = '1'
              AND p.quantity > 0
              AND {$likeWhere}
              AND p.product_id NOT IN ({$excludePlaceholders})
            ORDER BY p.sort_order ASC, p.name ASC
            LIMIT ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return [
                'product_id' => (int)$r['product_id'],
                'name'       => $r['name'],
                'price'      => (float)$r['price'],
                'category'   => $r['category'],
            ];
        }, $rows);
    } catch (\Exception $e) {
        error_log(WABOT_LOG_PREFIX . " getComplementaryItems error: " . $e->getMessage());
        return [];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SMART SEARCH (products & stores)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Search products by name/description across all stores or within a specific store.
 * Returns matching products with their store info for Claude context.
 */
function searchProducts(PDO $db, string $query, ?int $partnerId = null, int $limit = 10): array
{
    $query = trim($query);
    if (empty($query) || mb_strlen($query) < 2) return [];

    $pattern = '%' . $query . '%';

    if ($partnerId) {
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price, p.special_price,
                   COALESCE(pa.trade_name, pa.name) AS partner_name,
                   pa.partner_id, pa.is_open
            FROM om_market_products p
            JOIN om_market_partners pa ON pa.partner_id = p.partner_id
            WHERE p.partner_id = ?
              AND p.status::text = '1'
              AND p.quantity > 0
              AND pa.status::text = '1'
              AND (p.name ILIKE ? OR p.description ILIKE ?)
            ORDER BY pa.is_open DESC, p.name ASC
            LIMIT ?
        ");
        $stmt->execute([$partnerId, $pattern, $pattern, $limit]);
    } else {
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price, p.special_price,
                   COALESCE(pa.trade_name, pa.name) AS partner_name,
                   pa.partner_id, pa.is_open
            FROM om_market_products p
            JOIN om_market_partners pa ON pa.partner_id = p.partner_id
            WHERE p.status::text = '1'
              AND p.quantity > 0
              AND pa.status::text = '1'
              AND (p.name ILIKE ? OR p.description ILIKE ?)
            ORDER BY pa.is_open DESC, pa.rating DESC NULLS LAST, p.name ASC
            LIMIT ?
        ");
        $stmt->execute([$pattern, $pattern, $limit]);
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Search stores by name, trade_name, or categoria.
 * Returns matching stores for Claude context.
 */
function searchStores(PDO $db, string $query, int $limit = 10): array
{
    $query = trim($query);
    if (empty($query) || mb_strlen($query) < 2) return [];

    $pattern = '%' . $query . '%';

    $stmt = $db->prepare("
        SELECT partner_id, name, trade_name, categoria, is_open,
               rating, delivery_time_min
        FROM om_market_partners
        WHERE status::text = '1'
          AND (name ILIKE ? OR trade_name ILIKE ? OR categoria ILIKE ?)
        ORDER BY is_open DESC, rating DESC NULLS LAST
        LIMIT ?
    ");
    $stmt->execute([$pattern, $pattern, $pattern, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Format search results as context text for Claude.
 */
function formatSearchResultsForPrompt(array $productResults, array $storeResults): string
{
    $lines = [];

    if (!empty($storeResults)) {
        $lines[] = "LOJAS ENCONTRADAS PELA BUSCA:";
        foreach ($storeResults as $s) {
            $name = $s['trade_name'] ?: $s['name'];
            $status = ((int)($s['is_open'] ?? 0) === 1) ? 'Aberta' : 'Fechada';
            $cat = $s['categoria'] ?? '';
            $rating = !empty($s['rating']) ? number_format((float)$s['rating'], 1) : '-';
            $lines[] = "- [{$s['partner_id']}] {$name} ({$cat}) -- {$status} -- nota {$rating}";
        }
        $lines[] = "";
    }

    if (!empty($productResults)) {
        $lines[] = "PRODUTOS ENCONTRADOS PELA BUSCA:";
        // Group by store
        $byStore = [];
        foreach ($productResults as $p) {
            $byStore[$p['partner_name']][] = $p;
        }
        foreach ($byStore as $storeName => $products) {
            $storeOpen = ((int)($products[0]['is_open'] ?? 0) === 1) ? 'Aberta' : 'Fechada';
            $lines[] = "  *{$storeName}* ({$storeOpen}):";
            foreach ($products as $p) {
                $price = ((float)($p['special_price'] ?? 0) > 0 && (float)$p['special_price'] < (float)$p['price'])
                    ? (float)$p['special_price']
                    : (float)$p['price'];
                $priceFmt = number_format($price, 2, ',', '.');
                $lines[] = "    - [{$p['product_id']}] {$p['name']} — R\$ {$priceFmt} (loja_id:{$p['partner_id']})";
            }
        }
        $lines[] = "";
    }

    return implode("\n", $lines);
}

// ═══════════════════════════════════════════════════════════════════════════
// TIME-OF-DAY HELPERS (America/Sao_Paulo)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Get time-of-day greeting and context for Sao Paulo timezone.
 * Returns associative array with greeting, period, hour, day_of_week, is_weekend.
 */
function getTimeContext(): array
{
    $tz = new \DateTimeZone('America/Sao_Paulo');
    $now = new \DateTime('now', $tz);
    $hour = (int)$now->format('G');
    $dayOfWeek = (int)$now->format('N'); // 1=Mon, 7=Sun
    $isWeekend = ($dayOfWeek >= 6);
    $timeStr = $now->format('H:i');
    $dayName = [
        1 => 'segunda-feira', 2 => 'terca-feira', 3 => 'quarta-feira',
        4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sabado', 7 => 'domingo',
    ][$dayOfWeek] ?? '';

    if ($hour >= 6 && $hour < 11) {
        $period = 'manha';
        $greeting = 'Bom dia! Que tal um cafe da manha?';
    } elseif ($hour >= 11 && $hour < 14) {
        $period = 'almoco';
        $greeting = 'E ai! Hora do almoco, bora pedir?';
    } elseif ($hour >= 14 && $hour < 17) {
        $period = 'tarde';
        $greeting = 'Boa tarde! Um lanchinho cairia bem, ne?';
    } elseif ($hour >= 17 && $hour < 22) {
        $period = 'noite';
        $greeting = 'Boa noite! Ja pensou no jantar?';
    } else {
        $period = 'madrugada';
        $greeting = 'E ai, bate aquela fome de madrugada?';
    }

    return [
        'greeting'    => $greeting,
        'period'      => $period,
        'hour'        => $hour,
        'time_str'    => $timeStr,
        'day_of_week' => $dayName,
        'is_weekend'  => $isWeekend,
    ];
}

/**
 * Handle audio messages with friendly, varied responses.
 * Tracks how many audios the customer has sent in context.
 */
function handleAudioMessage(array &$context): string
{
    $audioCount = ($context['audio_count'] ?? 0) + 1;
    $context['audio_count'] = $audioCount;
    $context['sent_audio'] = true;

    $responses = [
        "Opa, recebi seu audio! Infelizmente ainda nao consigo ouvir audios, mas manda por texto que eu te ajudo rapidinho!",
        "Puxa, adoraria ouvir, mas ainda nao consigo processar audios. Me conta por texto o que voce precisa!",
        "Recebi o audio, mas por enquanto so consigo ler texto. Me escreve aqui que eu resolvo pra voce!",
        "Eita, audio ainda nao e minha praia! Digita pra mim que eu te ajudo na hora!",
        "Audio recebido, mas infelizmente nao consigo ouvir ainda. Manda por escrito que a gente resolve!",
    ];

    // Use modulo to cycle through responses for repeat audio senders
    $idx = ($audioCount - 1) % count($responses);
    return $responses[$idx];
}



// ═══════════════════════════════════════════════════════════════════════════
// MESSAGE STORAGE
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Save a message to om_callcenter_wa_messages.
 *
 * @param string $direction 'inbound' or 'outbound'
 * @param string $senderType 'customer', 'ai', 'agent'
 * @param string $messageType 'text', 'button', 'list', 'location', 'image'
 */
function saveMessage(
    PDO    $db,
    int    $conversationId,
    string $direction,
    string $senderType,
    string $message,
    string $messageType = 'text',
    ?string $mediaUrl = null,
    bool   $aiSuggested = false
): int {
    $stmt = $db->prepare("
        INSERT INTO om_callcenter_wa_messages
            (conversation_id, direction, sender_type, message, message_type, media_url, ai_suggested, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, NOW())
        RETURNING id
    ");
    $stmt->execute([
        $conversationId,
        $direction,
        $senderType,
        mb_substr($message, 0, 10000), // Cap at 10k chars
        $messageType,
        $mediaUrl,
        $aiSuggested ? 't' : 'f',
    ]);

    return (int)$stmt->fetchColumn();
}

// ═══════════════════════════════════════════════════════════════════════════
// WHATSAPP RESPONSE SENDER
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Send a WhatsApp response, splitting long messages.
 */
function sendWhatsAppResponse(string $phone, string $text): void
{
    if (empty(trim($text))) return;

    // Split very long messages into chunks
    if (mb_strlen($text) <= WABOT_MAX_MESSAGE_LENGTH) {
        sendWhatsAppWithRetry($phone, $text);
        return;
    }

    // Split on double newlines (paragraph breaks) first
    $paragraphs = preg_split('/\n\n+/', $text);
    $chunks = [];
    $current = '';

    foreach ($paragraphs as $para) {
        $para = trim($para);
        if (empty($para)) continue;

        if (mb_strlen($current . "\n\n" . $para) > WABOT_MAX_MESSAGE_LENGTH) {
            if (!empty($current)) {
                $chunks[] = trim($current);
            }
            // If single paragraph is too long, split by sentences
            if (mb_strlen($para) > WABOT_MAX_MESSAGE_LENGTH) {
                $sentences = preg_split('/(?<=[.!?])\s+/', $para);
                $subCurrent = '';
                foreach ($sentences as $sentence) {
                    if (mb_strlen($subCurrent . ' ' . $sentence) > WABOT_MAX_MESSAGE_LENGTH) {
                        if (!empty($subCurrent)) $chunks[] = trim($subCurrent);
                        $subCurrent = $sentence;
                    } else {
                        $subCurrent .= ($subCurrent ? ' ' : '') . $sentence;
                    }
                }
                $current = $subCurrent;
            } else {
                $current = $para;
            }
        } else {
            $current .= ($current ? "\n\n" : '') . $para;
        }
    }
    if (!empty($current)) {
        $chunks[] = trim($current);
    }

    // Send each chunk with a small delay
    foreach ($chunks as $i => $chunk) {
        if ($i > 0) {
            usleep(500000); // 500ms between chunks
        }
        sendWhatsAppWithRetry($phone, $chunk);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// CLAUDE AI SYSTEM PROMPTS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Build the Claude system prompt for the current conversation step.
 */
function buildSystemPrompt(
    PDO    $db,
    array  $conversation,
    string $step,
    ?array $customerInfo,
    string $memoryContext,
    ?string $menuText = null,
    ?array $stores = null,
    ?array $addresses = null,
    ?array $activeOrders = null,
    ?array $recentOrders = null,
    ?array $popularItems = null,
    ?array $complementaryItems = null,
    string $searchContext = '',
    ?array $timeCtx = null
): string {
    $phone = $conversation['phone'] ?? '';
    $context = json_decode($conversation['ai_context'] ?? '{}', true) ?: [];
    $items = $context['items'] ?? [];

    // ── Customer profile summary ───────────────────────────────────────
    $customerName = $customerInfo['name'] ?? null;
    $isReturning = !empty($customerName);
    $customerProfileBlock = '';
    if ($customerInfo) {
        $customerProfileBlock = "\nCLIENTE IDENTIFICADO:";
        $customerProfileBlock .= "\n- Nome: {$customerInfo['name']}";
        if (!empty($customerInfo['email'])) {
            $customerProfileBlock .= "\n- Email: {$customerInfo['email']}";
        }
        $customerProfileBlock .= "\n- Telefone: {$customerInfo['phone']}";
    }

    // ── Active orders summary with ETA ─────────────────────────────────
    $activeOrdersBlock = '';
    if ($activeOrders && count($activeOrders) > 0) {
        $statusLabels = [
            'pendente'   => 'Pendente (aguardando loja)',
            'confirmado' => 'Confirmado pela loja',
            'aceito'     => 'Aceito pela loja',
            'preparando' => 'Sendo preparado agora',
            'pronto'     => 'Pronto, aguardando entregador',
            'em_entrega' => 'A caminho! Entregador saiu',
        ];
        $activeOrdersBlock = "\nPEDIDOS ATIVOS DO CLIENTE:";
        foreach ($activeOrders as $ao) {
            $statusLabel = $statusLabels[$ao['status']] ?? $ao['status'];
            $total = number_format((float)$ao['total'], 2, ',', '.');
            $mins = round((float)($ao['minutes_ago'] ?? 0));

            // Calculate ETA for each active order
            $etaInfo = '';
            try {
                $distKm = isset($ao['distancia_km']) ? (float)$ao['distancia_km'] : 5.0;
                $etaMinutes = calculateSmartETA($db, (int)$ao['partner_id'], $distKm, $ao['status']);
                if ($etaMinutes > 0) {
                    $etaArrival = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))
                        ->modify("+{$etaMinutes} minutes")
                        ->format('H:i');
                    $etaInfo = " | ETA: ~{$etaMinutes} min (chega ~{$etaArrival})";
                }
            } catch (\Throwable $e) {
                // Non-critical — skip ETA if calculation fails
            }

            $activeOrdersBlock .= "\n- Pedido #{$ao['order_number']} | {$ao['partner_name']} | R\$ {$total} | {$statusLabel} | ha {$mins} min{$etaInfo}";
        }
        $activeOrdersBlock .= "\n\nIMPORTANTE SOBRE ETA: Quando o cliente perguntar 'quanto tempo', 'quando chega', 'demora muito', 'cadê', use o ETA acima para dar uma estimativa. Diga algo como 'Deve chegar em ~X minutos, por volta das HH:MM!' de forma natural.";
    }

    // ── Recent orders summary (with items for reorder) ─────────────────
    $recentOrdersBlock = '';
    if ($recentOrders && count($recentOrders) > 0) {
        $recentOrdersBlock = "\nHISTORICO DE PEDIDOS RECENTES:";
        foreach (array_slice($recentOrders, 0, 5) as $ro) {
            $total = number_format((float)$ro['total'], 2, ',', '.');
            $date = date('d/m H:i', strtotime($ro['date_added'] ?? $ro['created_at']));
            $recentOrdersBlock .= "\n- #{$ro['order_number']} | {$ro['partner_name']} | R\$ {$total} | {$ro['status']} | {$date}";

            // Fetch order items for reorder context
            try {
                $roItems = getOrderItems($db, (int)$ro['order_id']);
                if (!empty($roItems)) {
                    $itemNames = [];
                    foreach ($roItems as $roi) {
                        $qty = (int)($roi['quantity'] ?? 1);
                        $itemNames[] = "{$qty}x {$roi['name']}";
                    }
                    $recentOrdersBlock .= "\n  Itens: " . implode(', ', $itemNames);
                }
            } catch (\Exception $e) {
                // Non-critical — skip items if query fails
            }
        }
    }

    // ── Base personality ───────────────────────────────────────────────
    $personality = <<<PROMPT
Voce e a *Bora*, assistente da SuperBora pelo WhatsApp. Voce e como uma amiga brasileira — informal, calorosa, esperta e eficiente. Voce ajuda com pedidos, status, duvidas e tudo sobre delivery.

IDENTIDADE E TOM:
- Voce fala como uma amiga no WhatsApp — informal, leve, com girias brasileiras naturais
- Cumprimentos: "E ai!", "Opa!", "Oi, tudo bem?", "Fala!", "Beleza?" — NUNCA "Como posso ajuda-lo?" ou linguagem robotica
- Use girias com naturalidade: "show", "beleza", "massa", "firmeza", "tranquilo", "top", "bora", "dahora"
- Responda em portugues brasileiro, sempre
- Use formatacao WhatsApp: *negrito* para destaques, _italico_ para enfase sutil
- Use emojis com naturalidade mas sem exagero (1-3 por mensagem, variando)
- Mensagens curtas e diretas — ninguem quer ler textao no WhatsApp
- Seja proativa: sugira coisas baseado no historico do cliente
- Tenha personalidade: faca comentarios leves tipo "Boa escolha!" ou "Esse e top!"
- Quando a conversa parece ter chegado ao fim (status informado, pedido feito, duvida respondida), SEMPRE encerre com uma frase acolhedora tipo "Se precisar, e so mandar msg!", "Qualquer coisa to aqui!", "Me chama quando quiser!" — faz o cliente sentir que pode voltar SEMPRE

INTELIGENCIA CONTEXTUAL:
- Detecte a intencao do cliente automaticamente pela mensagem:
  * Menciona loja/comida -> quer pedir (modo ordering)
  * "meu pedido"/"status"/"onde esta" -> quer saber status
  * Reclamacao/problema -> modo suporte com empatia
  * "cancelar"/"cancela" -> ajudar a cancelar pedido ativo
  * "repetir"/"de novo"/"mesmo" -> repetir ultimo pedido
  * Saudacao simples -> cumprimentar e perguntar o que precisa
  * "cupom"/"desconto"/"cashback"/"promocao" -> perguntar se quer fazer pedido com desconto
  * "atendente"/"humano"/"pessoa" -> transferir para humano
- Se o cliente muda de assunto no meio, acompanhe naturalmente
- Entenda abreviacoes comuns: "blz"=beleza, "td"=tudo, "vlw"=valeu, "pdc"=pode crer, "qro"=quero, "n"=nao, "s"=sim, "mt"=muito, "tb"=tambem, "obg"=obrigado, "msg"=mensagem, "qnts"=quantos, "qnt"=quanto, "dps"=depois, "hj"=hoje, "agr"=agora, "vdd"=verdade, "msm"=mesmo, "pq"=porque, "cmg"=comigo, "fds"=fim de semana, "pfv"=por favor
- Entenda erros de digitacao e corrija mentalmente sem apontar

REGRAS CRITICAS:
- NUNCA invente um product_id — use APENAS IDs do cardapio fornecido
- NUNCA crie precos — use APENAS precos do cardapio fornecido
- Se nao tem certeza do item, PERGUNTE ao cliente
- Para itens com opcoes obrigatorias, SEMPRE pergunte antes de adicionar
- Use os marcadores entre colchetes EXATAMENTE no formato especificado
- Os marcadores devem aparecer NO FINAL da sua resposta, DEPOIS do texto para o cliente
- Nunca mostre os marcadores como texto visivel para o cliente
- NUNCA diga "Eu sou uma IA" ou "Sou um robo" — voce e a Bora, ponto final
- Se o cliente perguntar se voce e robo, diga algo como "Sou a Bora, sua assistente da SuperBora! Como posso te ajudar?"
{$customerProfileBlock}
{$activeOrdersBlock}
{$recentOrdersBlock}
PROMPT;

    // Step-specific instructions
    $stepInstructions = '';

    // Check if we know the customer's location (saved address or context)
    $customerCity = null;
    $hasLocation = false;
    if ($customerInfo) {
        // Check saved addresses for city
        $savedAddresses = $addresses;
        if (!$savedAddresses && !empty($customerInfo['customer_id'])) {
            try {
                $addrStmt = $db->prepare("SELECT city FROM om_customer_addresses WHERE customer_id = ? AND is_active = '1' AND city IS NOT NULL AND city != '' ORDER BY is_default DESC LIMIT 1");
                $addrStmt->execute([$customerInfo['customer_id']]);
                $addrRow = $addrStmt->fetch(PDO::FETCH_ASSOC);
                if ($addrRow) {
                    $customerCity = $addrRow['city'];
                    $hasLocation = true;
                }
            } catch (\Exception $e) { /* ignore */ }
        } elseif ($savedAddresses) {
            foreach ($savedAddresses as $sa) {
                if (!empty($sa['city'])) {
                    $customerCity = $sa['city'];
                    $hasLocation = true;
                    break;
                }
            }
        }
    }
    // Also check context for location
    if (!$hasLocation && !empty($context['address_lat'])) {
        $hasLocation = true;
    }
    if (!$hasLocation && !empty($context['customer_city'])) {
        $customerCity = $context['customer_city'];
        $hasLocation = true;
    }

    // ── Fetch active promotions at customer's favorite stores ────────
    $promoBlock = '';
    if ($customerInfo && !empty($customerInfo['customer_id'])) {
        try {
            $promoStmt = $db->prepare("
                SELECT
                    p.name as product_name,
                    p.price,
                    p.special_price,
                    COALESCE(pa.trade_name, pa.name) as store_name
                FROM om_market_products p
                JOIN om_market_partners pa ON pa.partner_id = p.partner_id
                JOIN om_market_orders o ON o.partner_id = pa.partner_id AND o.customer_id = ?
                WHERE p.special_price IS NOT NULL
                  AND p.special_price > 0
                  AND p.special_price < p.price
                  AND p.status::text = '1'
                  AND pa.status::text = '1'
                  AND pa.is_open = 1
                GROUP BY p.product_id, p.name, p.price, p.special_price, pa.trade_name, pa.name
                ORDER BY (p.price - p.special_price) DESC
                LIMIT 5
            ");
            $promoStmt->execute([$customerInfo['customer_id']]);
            $promos = $promoStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($promos)) {
                $promoLines = [];
                foreach ($promos as $promo) {
                    $priceFmt = number_format((float)$promo['price'], 2, ',', '.');
                    $specialFmt = number_format((float)$promo['special_price'], 2, ',', '.');
                    $promoLines[] = "- {$promo['product_name']} na {$promo['store_name']}: de R\$ {$priceFmt} por R\$ {$specialFmt}";
                }
                $promoBlock = "\n\nPROMOCOES ATIVAS NAS LOJAS FAVORITAS DO CLIENTE:\n" . implode("\n", $promoLines);
                $promoBlock .= "\nDICA: Mencione essas promos de forma natural se fizer sentido na conversa. Ex: \"A [loja] ta com [produto] em promo!\"";
            }
        } catch (\Exception $e) {
            // Non-critical — skip promos if query fails
        }
    }

    switch ($step) {
        case STEP_GREETING:
            // Only show stores if we know the customer's location
            $storeList = '';
            $locationHint = '';
            if ($hasLocation && $stores) {
                $storeLines = [];
                foreach ($stores as $s) {
                    $name = $s['trade_name'] ?: $s['name'];
                    $status = ((int)($s['is_open'] ?? 0) === 1) ? 'Aberta' : 'Fechada';
                    $cat = $s['categoria'] ?? '';
                    $rating = $s['rating'] ? number_format((float)$s['rating'], 1) : '-';
                    $storeLines[] = "- [{$s['partner_id']}] {$name} ({$cat}) — {$status} — nota {$rating}";
                }
                $storeList = "\n\nLOJAS DISPONIVEIS NA REGIAO DO CLIENTE ({$customerCity}):\n" . implode("\n", $storeLines);
            } elseif (!$hasLocation) {
                $locationHint = "\n\n⚠️ VOCE NAO SABE ONDE O CLIENTE ESTA!";
                $locationHint .= "\n- NAO sugira lojas sem saber a localizacao do cliente";
                $locationHint .= "\n- Se o cliente quiser fazer um pedido, PRIMEIRO pergunte a cidade/bairro dele de forma natural";
                $locationHint .= "\n- Ex: \"Pra eu te mostrar as melhores opcoes, me fala sua cidade ou bairro!\"";
                $locationHint .= "\n- Ou peca pra ele compartilhar a localizacao do WhatsApp (icone de clipe > Localizacao)";
                $locationHint .= "\n- So depois de saber a localizacao, mostre as lojas";
                $locationHint .= "\n- Se ele ja disser o nome de uma loja especifica, tudo bem — nao precisa perguntar localizacao";
            }

            // Build returning customer hint
            $returningHint = '';
            if ($isReturning && $recentOrders && count($recentOrders) > 0) {
                $lastStore = $recentOrders[0]['partner_name'] ?? '';
                $returningHint = "\n\nDICA: O cliente ja pediu antes (ultimo pedido: {$lastStore}). Mencione isso! Ex: \"Opa {$customerName}! Vai querer pedir da {$lastStore} de novo?\"";
            } elseif ($isReturning) {
                $returningHint = "\n\nDICA: Voce conhece esse cliente! Chame pelo nome: \"{$customerName}\".";
            }

            // Build active orders proactive hint with ETA
            $proactiveOrderHint = '';
            if ($activeOrders && count($activeOrders) > 0) {
                $proactiveOrderHint = "\n\n⚡ REGRA PRIORITARIA — PEDIDOS ATIVOS DETECTADOS:";
                $proactiveOrderHint .= "\nO cliente tem pedido(s) em andamento! Quando ele mandar QUALQUER saudacao simples (oi, ola, e ai, fala, etc.), voce DEVE:";
                $proactiveOrderHint .= "\n1. Cumprimentar BREVEMENTE (1 linha so)";
                $proactiveOrderHint .= "\n2. IMEDIATAMENTE informar o status do pedido ativo COM PREVISAO DE ENTREGA, de forma natural e detalhada:";
                $firstEtaExample = '';
                foreach ($activeOrders as $ao) {
                    $statusLabels2 = [
                        'pendente'   => 'ta aguardando a loja aceitar',
                        'confirmado' => 'a loja ja confirmou e vai comecar a preparar',
                        'aceito'     => 'a loja ja aceitou',
                        'preparando' => 'ta sendo preparado agora na cozinha',
                        'pronto'     => 'ja ta pronto! Estamos buscando um entregador',
                        'em_entrega' => 'ta a caminho! O entregador ja saiu com ele',
                    ];
                    $label = $statusLabels2[$ao['status']] ?? $ao['status'];
                    $mins = round((float)($ao['minutes_ago'] ?? 0));
                    $total = number_format((float)$ao['total'], 2, ',', '.');

                    // Calculate ETA for proactive hint
                    $etaHint = '';
                    try {
                        $distKm = isset($ao['distancia_km']) ? (float)$ao['distancia_km'] : 5.0;
                        $etaMins = calculateSmartETA($db, (int)$ao['partner_id'], $distKm, $ao['status']);
                        if ($etaMins > 0) {
                            $etaArrival = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))
                                ->modify("+{$etaMins} minutes")
                                ->format('H:i');
                            $etaHint = " | Previsao: ~{$etaMins} min (chega ~{$etaArrival})";
                            if (empty($firstEtaExample)) {
                                $firstEtaExample = ", deve chegar em ~{$etaMins} min (por volta das {$etaArrival})";
                            }
                        }
                    } catch (\Throwable $e) {}

                    $proactiveOrderHint .= "\n   - Pedido #{$ao['order_number']} da {$ao['partner_name']} (R\$ {$total}) — {$label} (ha {$mins} min){$etaHint}";
                }
                $proactiveOrderHint .= "\n3. Pergunte se precisa de algo mais com o pedido ou se quer fazer outro";
                $exFirstStatus = $statusLabels2[$activeOrders[0]['status']] ?? $activeOrders[0]['status'];
                $exFirstNumber = $activeOrders[0]['order_number'];
                $exFirstStore = $activeOrders[0]['partner_name'];
                $proactiveOrderHint .= "\nExemplo: \"Opa {$customerName}! Seu pedido #{$exFirstNumber} da {$exFirstStore} {$exFirstStatus}{$firstEtaExample}! Precisa de mais alguma coisa?\"";
                $proactiveOrderHint .= "\nISTO E OBRIGATORIO — nao pergunte 'o que voce quer' se ele tem pedido ativo. INFORME o status E o tempo estimado primeiro!";
                $proactiveOrderHint .= "\nSe o cliente perguntar 'quanto tempo', 'quando chega', 'demora?', 'cade meu pedido' -> Use a previsao ETA acima e responda de forma amigavel e especifica.";
            }

            // Build recently delivered hint
            $deliveredHint = '';
            if ($recentOrders && count($recentOrders) > 0) {
                $lastOrder = $recentOrders[0];
                $lastStatus = $lastOrder['status'] ?? '';
                $lastDate = date('d/m H:i', strtotime($lastOrder['date_added'] ?? $lastOrder['created_at'] ?? 'now'));
                if (in_array($lastStatus, ['entregue', 'delivered'])) {
                    $deliveredHint = "\n\nULTIMO PEDIDO FOI ENTREGUE:";
                    $deliveredHint .= "\n- Pedido #{$lastOrder['order_number']} da {$lastOrder['partner_name']} (R\$ " . number_format((float)$lastOrder['total'], 2, ',', '.') . ") entregue em {$lastDate}";
                    $deliveredHint .= "\nSe o cliente mandar saudacao simples e NAO tiver pedido ativo, pergunte se deu tudo certo com a entrega!";
                    $deliveredHint .= "\nExemplo: \"E ai {$customerName}! Vi que seu pedido da {$lastOrder['partner_name']} foi entregue! Chegou tudo certinho? Precisa de ajuda com alguma coisa?\"";
                }
            }

            $stepInstructions = <<<STEP
ETAPA ATUAL: Saudacao e deteccao de intencao

OBJETIVO: Cumprimentar e PROATIVAMENTE informar o que e relevante para o cliente. Voce esta NA FRENTE — antecipe o que ele quer saber.
{$proactiveOrderHint}
{$deliveredHint}

DETECCAO AUTOMATICA DE INTENCAO:
1. Se mencionar nome de loja/restaurante -> identifique a loja e use [STORE:id:nome]. Verifique os RESULTADOS DE BUSCA (se houver) para match mais preciso
1b. Se mencionar tipo de comida ("pizza", "hamburguer", "acai", "sushi") -> verifique os RESULTADOS DE BUSCA para encontrar lojas/produtos que combinam e sugira opcoes
2. Se perguntar sobre pedido/status/rastreio -> fale sobre os pedidos ativos (se houver) e NUNCA use marcador nesse caso
3. Se reclamar de algo -> entre em modo empatico, use [SWITCH_TO_ORDER] para suporte
4. Se disser "repetir"/"mesmo pedido"/"pedir de novo"/"o de sempre"/"quero o mesmo" -> mostre os ultimos 3 pedidos com os itens e pergunte qual quer repetir. Quando o cliente escolher, use [REORDER:order_number] com o numero do pedido escolhido
5. Se disser "cancelar" -> mostre pedidos ativos e ajude a cancelar, use [SWITCH_TO_ORDER]
6. Se saudacao simples ("oi", "ola") E tem pedido ativo -> INFORMAR STATUS DO PEDIDO (regra prioritaria acima)
7. Se saudacao simples E ultimo pedido foi entregue -> perguntar se a entrega foi ok
8. Se saudacao simples sem pedidos -> cumprimentar e perguntar o que precisa
9. Se pedir "atendente"/"humano" -> use [TRANSFER_HUMAN]
10. Se o cliente informar cidade/bairro/localizacao -> use [CITY:nome_da_cidade] para salvar e mostre lojas da regiao
11. Se o cliente disser "agendar"/"marcar"/"pra amanha"/"pra depois"/"pra sabado"/"daqui a X horas" -> detectar intencao de agendamento e confirmar: "Pode agendar sim! Vamos montar o pedido e depois definimos o horario certinho"
{$locationHint}
{$storeList}
{$returningHint}
{$promoBlock}

INSTRUCOES:
- SEMPRE esteja a frente: o cliente mandou "oi"? Voce ja sabe se ele tem pedido ativo. Fale sobre isso PRIMEIRO.
- Se detectar intencao de agendamento, confirme que pode agendar e siga o fluxo normal de pedido — o horario sera definido na etapa de confirmacao
- Cumprimente de forma calorosa e BREVE (max 1 linha de saudacao)
- Se o cliente e recorrente, use o nome dele e seja pessoal
- Se detectar intencao de pedido direto, pule para o fluxo certo
- Se nao tiver pedido ativo nem recente, pergunte de forma natural: "Quer fazer um pedido ou precisa de uma ajuda?"
- Se tiver promocoes ativas nas lojas favoritas, mencione naturalmente: "A [loja] ta com [produto] em promo, viu!"
- NUNCA responda com um menu de opcoes numerado roboticamente
- Seja a assistente mais atenciosa do mundo — antecipe tudo!

MARCADORES DISPONIVEIS:
- [STORE:id:nome] — quando identificar a loja (vai para proximo passo)
- [REORDER:order_number] — quando o cliente quiser repetir um pedido anterior (ex: [REORDER:WA00123]). Use o order_number exato do HISTORICO DE PEDIDOS RECENTES
- [CITY:nome_da_cidade] — quando o cliente informar cidade/bairro/localizacao
- [SWITCH_TO_ORDER] — mudar para modo suporte (status, reclamacao, cancelamento)
- [TRANSFER_HUMAN] — se pedir atendente humano
STEP;
            break;

        case STEP_IDENTIFY_STORE:
            $storeList = '';
            $identifyLocationHint = '';
            if ($hasLocation && $stores) {
                $storeLines = [];
                foreach ($stores as $s) {
                    $name = $s['trade_name'] ?: $s['name'];
                    $status = ((int)($s['is_open'] ?? 0) === 1) ? 'Aberta' : 'Fechada';
                    $cat = $s['categoria'] ?? '';
                    $rating = $s['rating'] ? number_format((float)$s['rating'], 1) : '-';
                    $delivTime = $s['delivery_time_min'] ?? '';
                    $delivFee = $s['delivery_fee'] ? 'R$ ' . number_format((float)$s['delivery_fee'], 2, ',', '.') : '';
                    $extra = $delivTime ? " ~{$delivTime}min" : '';
                    $extra .= $delivFee ? " | entrega {$delivFee}" : '';
                    $storeLines[] = "- [{$s['partner_id']}] {$name} ({$cat}) — {$status} — nota {$rating}{$extra}";
                }
                $storeList = "\n\nLOJAS DISPONIVEIS NA REGIAO:\n" . implode("\n", $storeLines);
            } elseif (!$hasLocation) {
                $identifyLocationHint = "\n\n⚠️ VOCE AINDA NAO SABE ONDE O CLIENTE ESTA! Pergunte a cidade/bairro antes de sugerir lojas. Use [CITY:nome] quando ele informar.";
            }

            $stepInstructions = <<<STEP
ETAPA ATUAL: Identificar loja

OBJETIVO: Ajudar o cliente a escolher uma loja.
{$identifyLocationHint}
{$storeList}
{$promoBlock}

INSTRUCOES:
- Se nao sabe a localizacao do cliente, PERGUNTE PRIMEIRO antes de listar lojas
- Quando o cliente informar cidade/bairro, use [CITY:nome_da_cidade]
- Tente fazer match entre o que o cliente disse e uma loja da lista (match parcial e por categoria tb)
- Se houver RESULTADOS DE BUSCA no contexto, USE-OS! Eles contem lojas e produtos que combinam com o que o cliente pediu
- Se encontrar, confirme o nome e use o marcador [STORE:id:nome]
- Se a loja estiver FECHADA, avise com empatia e sugira alternativas abertas da mesma categoria
- Se nao encontrar, peca mais detalhes ou sugira opcoes populares
- Se o cliente disser uma categoria ("pizza", "hamburguer", "acai"), filtre e mostre opcoes
- Se mencionar "qualquer uma" ou "tanto faz", sugira as melhores avaliadas que estao abertas
- Se tiver promocoes ativas, mencione naturalmente pra ajudar na decisao

MARCADORES:
- [STORE:id:nome] — loja identificada
- [REORDER:order_number] — repetir pedido anterior (ex: [REORDER:WA00123])
- [CITY:nome_da_cidade] — salvar localizacao do cliente
- [TRANSFER_HUMAN] — transferir para humano
STEP;
            break;

        case STEP_TAKE_ORDER:
            $partnerName = $context['partner_name'] ?? 'a loja';
            $cartSummary = buildCartSummary($items);
            $cartItemCount = count($items);

            // Build popular items block
            $popularBlock = '';
            if ($popularItems && count($popularItems) > 0) {
                $popLines = [];
                foreach ($popularItems as $pi) {
                    $priceFmt = number_format($pi['price'], 2, ',', '.');
                    $popLines[] = "- [{$pi['product_id']}] {$pi['name']} (R\$ {$priceFmt}) — pedido {$pi['order_count']}x";
                }
                $popularBlock = "\n\nMAIS PEDIDOS DESTA LOJA (top sellers):\n" . implode("\n", $popLines);
            }

            // Build complementary items block
            $complementaryBlock = '';
            if ($complementaryItems && count($complementaryItems) > 0) {
                $compLines = [];
                foreach ($complementaryItems as $ci) {
                    $priceFmt = number_format($ci['price'], 2, ',', '.');
                    $compLines[] = "- [{$ci['product_id']}] {$ci['name']} (R\$ {$priceFmt}) [{$ci['category']}]";
                }
                $complementaryBlock = "\n\nSUGESTOES PRA ACOMPANHAR (baseado no carrinho):\n" . implode("\n", $compLines);
            }

            // Detect combos in menu
            $comboHint = '';
            if ($menuText && preg_match_all('/\[(\d+)\]\s+([^\n]*combo[^\n]*)\.\.\.\s*R\$\s*([\d.,]+)/i', $menuText, $comboMatches, PREG_SET_ORDER)) {
                $comboLines = [];
                foreach (array_slice($comboMatches, 0, 3) as $cm) {
                    $comboLines[] = "- [{$cm[1]}] {$cm[2]} — R\$ {$cm[3]}";
                }
                if (!empty($comboLines)) {
                    $comboHint = "\n\nCOMBOS DISPONIVEIS:\n" . implode("\n", $comboLines);
                    $comboHint .= "\nDICA: Se o cliente pedir itens separados que existem como combo, mencione: \"Tem um combo que sai melhor!\"";
                }
            }

            // Build upsell behavior instructions based on cart state
            $upsellInstructions = '';
            if ($cartItemCount === 0) {
                // Empty cart — just show popular items as initial suggestion
                if (!empty($popularBlock)) {
                    $upsellInstructions = "\n\nDICA DE VENDA: Se o cliente nao souber o que pedir, sugira os mais pedidos de forma natural: \"O pessoal aqui pede muito [item], quer experimentar?\"";
                }
            } elseif ($cartItemCount === 1) {
                // First item added — suggest complementary
                $upsellInstructions = "\n\nDICA DE VENDA (1 ITEM NO CARRINHO): Depois de confirmar o item, sugira algo complementar de forma natural e BREVE. Exemplos:";
                $upsellInstructions .= "\n- Se adicionou comida: \"Vai querer uma bebida pra acompanhar?\"";
                $upsellInstructions .= "\n- Se adicionou pizza: \"E uma batata ou porcao pra completar?\"";
                $upsellInstructions .= "\n- Se adicionou acai: \"Quer adicionar algum extra tipo granola ou leite condensado?\"";
                if (!empty($complementaryBlock)) {
                    $upsellInstructions .= "\n- Use as SUGESTOES PRA ACOMPANHAR listadas abaixo — sao itens reais do cardapio";
                }
                $upsellInstructions .= "\n- Sugira UMA VEZ so. Se o cliente disser nao, respeite e siga em frente.";
            } else {
                // 2+ items — lighter touch
                $upsellInstructions = "\n\nDICA DE VENDA (2+ ITENS NO CARRINHO): O cliente ja tem itens. Pergunte de forma leve: \"Quer mais alguma coisa ou posso fechar?\"";
                $upsellInstructions .= "\n- NAO fique insistindo em sugestoes — o cliente ja escolheu bastante";
                $upsellInstructions .= "\n- Se ele disser que acabou, use [NEXT_STEP] sem hesitar";
            }

            // Fetch available coupons for this store
            $storeCouponsBlock = '';
            $storeCoupons = getAvailableCoupons($db, $context['partner_id'] ?? null, $conversation['customer_id'] ?? null);
            if (!empty($storeCoupons)) {
                $couponLines = [];
                foreach ($storeCoupons as $sc) {
                    $cond = $sc['conditions'] ? " ({$sc['conditions']})" : '';
                    $couponLines[] = "- *{$sc['code']}*: {$sc['discount_text']}{$cond}";
                }
                $storeCouponsBlock = "\n\nCUPONS DISPONIVEIS NESTA LOJA:\n" . implode("\n", $couponLines);
                $storeCouponsBlock .= "\nDICA: Mencione cupons naturalmente se fizer sentido. Ex: \"Ah, a loja ta com cupom {$storeCoupons[0]['code']} com {$storeCoupons[0]['discount_text']}!\"";
            }

            // Check if coupon already applied
            $appliedCouponBlock = '';
            if (!empty($context['coupon_code'])) {
                $appliedCouponBlock = "\n\nCUPOM APLICADO: *{$context['coupon_code']}* — " . ($context['coupon_description'] ?? '');
                if (($context['coupon_discount'] ?? 0) > 0) {
                    $appliedCouponBlock .= " (desconto: R\$ " . number_format($context['coupon_discount'], 2, ',', '.') . ")";
                }
            }

            $stepInstructions = <<<STEP
ETAPA ATUAL: Montar pedido

LOJA: *{$partnerName}*
{$menuText}
{$popularBlock}
{$complementaryBlock}
{$comboHint}
{$storeCouponsBlock}

CARRINHO ATUAL:
{$cartSummary}{$appliedCouponBlock}
{$upsellInstructions}

INSTRUCOES:
- Ajude o cliente a escolher itens do cardapio de forma natural e amigavel
- Quando o cliente pedir um item, identifique no cardapio pelo nome (aceite nomes parciais e aproximados)
- Adicione com [ITEM:product_id:nome:preco:quantidade]
- Se o produto tem opcoes OBRIGATORIAS, pergunte quais opcoes antes de adicionar
- Se o cliente quer remover um item, use [REMOVE_ITEM:indice] (indice comeca em 0)
- Quando o cliente disser que terminou ("so isso", "e isso", "fecha", "bora", "pode fechar"), use [NEXT_STEP]
- Mostre o subtotal atualizado quando adicionar/remover itens
- Se o cliente pedir algo que nao existe, diga de forma leve: "Esse nao tem, mas olha essas opcoes..."
- IMPORTANTE: Use APENAS product_ids que existem no cardapio acima. NUNCA invente itens ou precos.
- IMPORTANTE: Sugestoes devem vir APENAS dos dados de MAIS PEDIDOS, SUGESTOES PRA ACOMPANHAR ou COMBOS acima. NUNCA invente sugestoes.
- Se o cliente pedir "o de sempre" ou "o mesmo", verifique no historico de pedidos e sugira
- Seja natural nas sugestoes — como um garcom amigo, nao como um vendedor insistente

CUPONS E DESCONTOS:
- Se o cliente mencionar "cupom", "desconto", "codigo promocional" ou similar, peca o codigo e use [COUPON:CODIGO]
- Se a loja tem cupons disponiveis (listados abaixo), mencione de forma natural: "A loja ta com cupom [CODE] com [desconto]!"
- Nunca invente cupons — use apenas os listados ou o que o cliente informar

MARCADORES:
- [ITEM:product_id:nome:preco:qty] — adicionar item (preco unitario como float, ex: 25.90)
- [REMOVE_ITEM:indice] — remover item pelo indice no carrinho (0-based)
- [COUPON:CODIGO] — aplicar cupom de desconto (ex: [COUPON:PROMO10])
- [NEXT_STEP] — finalizar montagem do pedido
- [TRANSFER_HUMAN] — transferir para humano
STEP;
            break;

        case STEP_GET_ADDRESS:
            $addressList = '';
            $defaultAddr = null;
            $currentDateTime = date('Y-m-d\\TH:i');
            if ($addresses && count($addresses) > 0) {
                $addrLines = [];
                foreach ($addresses as $i => $addr) {
                    $label = $addr['label'] ?? ('Endereco ' . ($i + 1));
                    $fullAddr = implode(', ', array_filter([
                        $addr['street'] ?? '',
                        $addr['number'] ?? '',
                        $addr['complement'] ?? '',
                        $addr['neighborhood'] ?? '',
                        $addr['city'] ?? '',
                    ]));
                    $isDefault = ((int)($addr['is_default'] ?? 0) === 1);
                    $defaultTag = $isDefault ? ' (padrao)' : '';
                    $addrLines[] = ($i + 1) . ". *{$label}*: {$fullAddr}{$defaultTag}";
                    if ($isDefault) {
                        $defaultAddr = ['label' => $label, 'full' => $fullAddr, 'index' => $i];
                    }
                }
                $newIdx = count($addresses) + 1;
                $addrLines[] = "{$newIdx}. *Novo endereco*";
                $addressList = "\n\nENDERECOS SALVOS DO CLIENTE:\n" . implode("\n", $addrLines);
            }

            $hasAddresses = $addresses && count($addresses) > 0;

            // Build smart address hint based on context
            if ($hasAddresses && $defaultAddr) {
                $addressHint = "O cliente e recorrente e tem um endereco PADRAO salvo! Sugira direto de forma natural:";
                $addressHint .= "\nExemplo: \"Entrego no mesmo lugar? *{$defaultAddr['label']}* — {$defaultAddr['full']}\"";
                $addressHint .= "\nSe confirmar ('sim', 'isso', 'la mesmo', 'pode ser'), aceite e use [NEXT_STEP]";
                $addressHint .= "\nSe quiser outro, mostre a lista numerada acima";
            } elseif ($hasAddresses) {
                $addressHint = "O cliente tem enderecos salvos! Pergunte de forma natural: \"Onde entrego?\" e mostre as opcoes numeradas.";
                $addressHint .= "\nSe disser o numero (1, 2...) ou o label ('casa', 'trabalho'), aceite e use [NEXT_STEP]";
            } else {
                $addressHint = "O cliente nao tem enderecos salvos. Peca o endereco de forma amigavel.";
                $addressHint .= "\nLembre que pode compartilhar a localizacao do WhatsApp!";
            }

            // Check if scheduling context already set
            $schedulingHint = '';
            if (!empty($context['scheduled_for'])) {
                $schedFmt = date('d/m \\a\\s H:i', strtotime($context['scheduled_for']));
                $schedulingHint = "\n\nPEDIDO AGENDADO PARA: *{$schedFmt}*. Continue normalmente com o endereco.";
            }

            $stepInstructions = <<<STEP
ETAPA ATUAL: Endereco de entrega

OBJETIVO: Obter o endereco de entrega do cliente.
{$addressList}
{$schedulingHint}

{$addressHint}

INSTRUCOES:
- Se o cliente tem endereco padrao, sugira direto: "Entrego no mesmo lugar de sempre? [endereco padrao]"
- Se o cliente confirmar o padrao (sim, isso, la mesmo, pode ser, esse mesmo), aceite e use [NEXT_STEP]
- Se o cliente tem enderecos salvos, mostre a lista numerada com opcao de novo endereco no final
- Se o cliente disser o numero (1, 2, etc) ou o label ("casa", "trabalho"), aceite e use [NEXT_STEP]
- Se escolher "novo endereco" ou nao tem salvos, peca o endereco: "Me manda o endereco de entrega"
- Aceite localizacao do WhatsApp tambem: "Ou pode mandar sua localizacao aqui no WhatsApp que eu uso ela!"
- Quando o cliente digitar um endereco novo, aceite de forma natural:
  * "Rua Campos Sales, 1650 - Centro" -> aceitar como endereco
  * Pode receber em partes: rua, depois numero, depois bairro
  * Se faltar informacao importante (numero, bairro), pergunte: "Qual o numero?"
  * NAO exija endereco perfeito — se tem rua e numero, ja da pra entregar
- Se o endereco e NOVO (digitado pelo cliente, nao era dos salvos), use [SAVE_ADDRESS] junto com [NEXT_STEP]
- Quando tiver o endereco confirmado, use [NEXT_STEP]
- Tambem pergunte sobre agendamento se ainda nao foi definido: "Quer pra agora ou pra depois?"
  * Se o cliente quiser agendar, entenda a data/hora natural e use [SCHEDULE:YYYY-MM-DDTHH:MM]
  * Entenda linguagem natural: "amanha ao meio dia", "sabado as 19h", "daqui a 2 horas", "pra agora"
  * Converta para formato ISO: [SCHEDULE:2026-03-07T12:00]
  * "pra agora"/"agora mesmo" -> NAO use SCHEDULE (entrega imediata)
  * Minimo 30 min no futuro, maximo 7 dias
  * Se horario invalido: "Precisa ser pelo menos daqui 30 min e no maximo 7 dias"

DATA/HORA ATUAL: {$currentDateTime}

MARCADORES:
- [NEXT_STEP] — endereco obtido, avancar para pagamento
- [SCHEDULE:YYYY-MM-DDTHH:MM] — agendar pedido (ex: [SCHEDULE:2026-03-07T12:00])
- [SAVE_ADDRESS] — salvar endereco novo (use quando endereco foi digitado, nao dos salvos)
- [TRANSFER_HUMAN] — transferir para humano
STEP;
            break;

        case STEP_GET_PAYMENT:
            // Fetch cashback balance for the customer
            $cashbackBlock = '';
            $paymentCustomerId = $conversation['customer_id'] ?? null;
            if ($paymentCustomerId) {
                $cbBal = getCustomerCashback($db, (int)$paymentCustomerId);
                if ($cbBal > 0) {
                    $cbBalFmt = number_format($cbBal, 2, ',', '.');
                    $cashbackBlock = "\n\nSALDO DE CASHBACK DO CLIENTE: R\$ {$cbBalFmt}\nMencione isso de forma natural: 'Voce tem R\$ {$cbBalFmt} de cashback! Quer usar no pedido?'";
                }
            }

            // Check if coupon already applied
            $paymentCouponBlock = '';
            if (!empty($context['coupon_code'])) {
                $paymentCouponBlock = "\n\nCUPOM JA APLICADO: *{$context['coupon_code']}* — " . ($context['coupon_description'] ?? '');
            }

            $stepInstructions = <<<STEP
ETAPA ATUAL: Forma de pagamento{$cashbackBlock}{$paymentCouponBlock}

OBJETIVO: Descobrir como o cliente quer pagar e se quer usar cashback.

OPCOES DISPONIVEIS:
- *PIX* — pagamento instantaneo, link gerado na hora
- *Cartao* — link seguro de pagamento
- *Dinheiro* — pagamento na entrega

INSTRUCOES:
- Pergunte de forma natural: "Como quer pagar? PIX, cartao ou dinheiro?"
- Se o cliente escolher dinheiro, pergunte "Precisa de troco? Se sim, pra quanto?"
- Se o cliente tiver saldo de cashback (informado abaixo), mencione de forma natural: "Ah, e voce tem R$ X,XX de cashback! Quer usar no pedido?"
- Se o cliente quiser usar cashback, use [USE_CASHBACK:valor] com o valor que ele quer usar (ou o total do saldo)
- Quando tiver a forma de pagamento, use [NEXT_STEP]
- Aceite variacoes: "pix"/"transferencia", "cartao"/"credito"/"debito", "dinheiro"/"na entrega"/"cash"
- Se o cliente perguntar sobre o PIX, explique: "O link do PIX e gerado automaticamente depois que o pedido for confirmado"
- Se o cliente mencionar "cupom" ou "desconto" aqui, tambem aceite com [COUPON:CODIGO]

MARCADORES:
- [COUPON:CODIGO] — aplicar cupom de desconto
- [USE_CASHBACK:valor] — usar cashback (ex: [USE_CASHBACK:5.50] ou [USE_CASHBACK:all] para usar tudo)
- [NEXT_STEP] — pagamento definido, ir para confirmacao
- [TRANSFER_HUMAN] — transferir para humano
STEP;
            break;

        case STEP_CONFIRM_ORDER:
            $cartSummary = buildCartSummary($items);
            $subtotal = calculateSubtotal($items);
            $deliveryFee = (float)($context['delivery_fee'] ?? WABOT_DEFAULT_DELIVERY_FEE);
            $serviceFee = WABOT_SERVICE_FEE;

            // Coupon discount
            $couponDiscount = (float)($context['coupon_discount'] ?? 0);
            $couponDesc = $context['coupon_description'] ?? '';
            $couponCode = $context['coupon_code'] ?? '';
            $couponFreeDelivery = !empty($context['coupon_free_delivery']);
            if ($couponFreeDelivery) {
                $deliveryFee = 0;
            }

            // Cashback usage
            $useCashback = (float)($context['use_cashback'] ?? 0);

            $total = max(0, $subtotal - $couponDiscount - $useCashback + $deliveryFee + $serviceFee);

            $address = $context['address'] ?? 'Nao informado';
            $payment = formatPaymentLabel($context['payment_method'] ?? '');
            $partnerName = $context['partner_name'] ?? 'a loja';

            $subtotalFmt = number_format($subtotal, 2, ',', '.');
            $deliveryFeeFmt = number_format($deliveryFee, 2, ',', '.');
            $serviceFeeFmt = number_format($serviceFee, 2, ',', '.');
            $totalFmt = number_format($total, 2, ',', '.');

            // Build discount lines for the summary
            $discountLines = '';
            if ($couponDiscount > 0) {
                $discountLines .= "\nDesconto (cupom {$couponCode}): -R\$ " . number_format($couponDiscount, 2, ',', '.');
            }
            if ($couponFreeDelivery && $couponDiscount <= 0) {
                $discountLines .= "\nCupom {$couponCode}: *Entrega gratis!*";
            }
            if ($useCashback > 0) {
                $discountLines .= "\nCashback usado: -R\$ " . number_format($useCashback, 2, ',', '.');
            }

            $changeInfo = '';
            if (($context['payment_method'] ?? '') === 'dinheiro' && ($context['payment_change'] ?? 0) > 0) {
                $changeInfo = "\nTroco para: R\$ " . number_format((float)$context['payment_change'], 2, ',', '.');
            }

            // Cashback balance hint
            $cashbackHint = '';
            $customerId_confirm = $conversation['customer_id'] ?? null;
            if ($customerId_confirm && $useCashback <= 0) {
                $cbBalance = getCustomerCashback($db, (int)$customerId_confirm);
                if ($cbBalance > 0) {
                    $cbFmt = number_format($cbBalance, 2, ',', '.');
                    $cashbackHint = "\n\nINFO CASHBACK: O cliente tem R\$ {$cbFmt} de cashback disponivel. Se ele quiser usar, use [USE_CASHBACK:valor].";
                }
            }

            // Build last-chance upsell hint for small orders (below R$50)
            $lastChanceHint = '';
            if ($total < 50.00 && $popularItems && count($popularItems) > 0) {
                $cartProductIds = array_map(function ($it) { return (int)($it['product_id'] ?? 0); }, $items);
                $suggestion = null;
                foreach ($popularItems as $pi) {
                    if (!in_array((int)$pi['product_id'], $cartProductIds)) {
                        $suggestion = $pi;
                        break;
                    }
                }
                if ($suggestion) {
                    $sugPriceFmt = number_format($suggestion['price'], 2, ',', '.');
                    $lastChanceHint = "\n\nULTIMA SUGESTAO (pedido abaixo de R\$50):";
                    $lastChanceHint .= "\n- Item popular: [{$suggestion['product_id']}] {$suggestion['name']} (R\$ {$sugPriceFmt})";
                    $lastChanceHint .= "\n- Mencione de forma leve ANTES de confirmar: \"Ah, e a {$partnerName} tem {$suggestion['name']} que o pessoal adora! Quer adicionar? Se nao, confirmo seu pedido!\"";
                    $lastChanceHint .= "\n- Se o cliente disser sim, adicione com [ITEM:{$suggestion['product_id']}:{$suggestion['name']}:{$suggestion['price']}:1] e mostre resumo atualizado";
                    $lastChanceHint .= "\n- Se disser nao ou confirmar direto, siga com [CONFIRMED] normalmente";
                    $lastChanceHint .= "\n- Faca essa sugestao UMA VEZ so — nao insista";
                }
            }

            // Build scheduling display for confirmation
            $schedulingDisplay = '';
            if (!empty($context['scheduled_for'])) {
                $schedDt = strtotime($context['scheduled_for']);
                $schedFmt = date('d/m/Y \a\s H:i', $schedDt);
                $schedulingDisplay = "\nAgendado para: *{$schedFmt}*";
            }

            $stepInstructions = <<<STEP
ETAPA ATUAL: Confirmacao do pedido

RESUMO DO PEDIDO:
Loja: *{$partnerName}*

{$cartSummary}

Subtotal: R\$ {$subtotalFmt}{$discountLines}
Taxa de entrega: R\$ {$deliveryFeeFmt}
Taxa de servico: R\$ {$serviceFeeFmt}
*Total: R\$ {$totalFmt}*

Endereco: {$address}
Pagamento: {$payment}{$changeInfo}{$schedulingDisplay}
{$lastChanceHint}
{$cashbackHint}

INSTRUCOES:
- Mostre o resumo completo do pedido formatado bonito com WhatsApp formatting
- Inclua todas as linhas de desconto (cupom e/ou cashback) se houver
- Se o pedido e agendado, destaque o horario: "Pedido agendado pra *[data/hora]*"
- Termine com algo tipo "Ta tudo certo? Confirma pra mim!"
- Se o cliente confirmar (sim, ok, confirma, isso, bora, beleza, manda, fecha, s, etc), use [CONFIRMED]
- Se quiser alterar algo, ajude a alterar de forma natural
- Se quiser cancelar, confirme: "Certeza que quer cancelar?"
- Se o cliente mencionar cupom ou cashback agora, aceite: [COUPON:CODIGO] ou [USE_CASHBACK:valor]
- NUNCA pressione o cliente — se ele hesitar, ajude
- IMPORTANTE: Sugestoes de ultimo momento devem vir APENAS dos dados acima. NUNCA invente itens.

MARCADORES:
- [ITEM:product_id:nome:preco:qty] — adicionar item sugerido de ultimo momento
- [CONFIRMED] — pedido confirmado, submeter
- [COUPON:CODIGO] — aplicar cupom de desconto
- [USE_CASHBACK:valor] — usar cashback (ex: [USE_CASHBACK:5.50] ou [USE_CASHBACK:all])
- [TRANSFER_HUMAN] — transferir para humano
STEP;
            break;

        case STEP_SUPPORT:
            $activeOrdersHint = '';
            if ($activeOrders && count($activeOrders) > 0) {
                $activeOrdersHint = "\n\nPEDIDOS ATIVOS para referencia rapida:";
                $statusEmojis = [
                    'pendente'   => 'Aguardando confirmacao da loja',
                    'confirmado' => 'Loja confirmou! Vai comecar a preparar',
                    'aceito'     => 'Loja aceitou! Preparando em breve',
                    'preparando' => 'Sendo preparado agora!',
                    'pronto'     => 'Pronto! Aguardando entregador',
                    'em_entrega' => 'Saiu pra entrega! Ta a caminho',
                ];
                foreach ($activeOrders as $ao) {
                    $statusHuman = $statusEmojis[$ao['status']] ?? $ao['status'];
                    $total = number_format((float)$ao['total'], 2, ',', '.');
                    $mins = round((float)($ao['minutes_ago'] ?? 0));

                    // Calculate ETA for support context
                    $supportEtaHint = '';
                    try {
                        $distKm = isset($ao['distancia_km']) ? (float)$ao['distancia_km'] : 5.0;
                        $etaMins = calculateSmartETA($db, (int)$ao['partner_id'], $distKm, $ao['status']);
                        if ($etaMins > 0) {
                            $etaArrival = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))
                                ->modify("+{$etaMins} minutes")
                                ->format('H:i');
                            $supportEtaHint = " | ETA: ~{$etaMins} min (chega ~{$etaArrival})";
                        }
                    } catch (\Throwable $e) {}

                    $activeOrdersHint .= "\n- Pedido #{$ao['order_number']} (order_id={$ao['order_id']}) | {$ao['partner_name']} | R\$ {$total} | {$statusHuman} | ha {$mins} min{$supportEtaHint}";
                }
            }

            $stepInstructions = <<<STEP
ETAPA ATUAL: Modo suporte

OBJETIVO: Ajudar o cliente com duvidas, status de pedidos, reclamacoes ou cancelamentos.
{$activeOrdersHint}

INSTRUCOES:
- Para STATUS: Se tiver pedidos ativos, informe o status de forma amigavel e detalhada, SEMPRE incluindo a previsao (ETA) quando disponivel
  * "pendente" -> "Seu pedido ta aguardando a loja confirmar, deve chegar em ~X minutos!"
  * "confirmado/aceito" -> "A loja ja aceitou! Previsao de entrega por volta das HH:MM"
  * "preparando" -> "Ta sendo preparado agora! Deve ficar pronto em uns X minutos e chegar por volta das HH:MM"
  * "pronto" -> "Seu pedido ta pronto! Deve chegar em ~X minutos"
  * "em_entrega" -> "Ta a caminho! Deve chegar em ~X minutinhos, por volta das HH:MM"
- Para perguntas de TEMPO ("quanto tempo", "quando chega", "demora muito", "cade", "tempo estimado"):
  * Use o ETA da lista de pedidos ativos acima para dar uma estimativa especifica
  * Responda de forma amigavel: "Deve chegar em ~X minutos, por volta das HH:MM!"
  * Se estiver demorando mais que o esperado, valide: "Ta demorando um pouquinho mais que o normal, mas ja ta a caminho!"
- Para RECLAMACOES: Seja MUITO empatica. Valide o sentimento do cliente primeiro.
  * "Poxa, sinto muito que isso aconteceu..."
  * "Entendo sua frustracao, vou resolver isso"
  * Ofereca solucoes: refazer o pedido, cupom de desconto, transferir para atendente
- Para CANCELAMENTO: Encontre o pedido ativo e use [CANCEL_ORDER:order_id]
  * Confirme qual pedido antes de cancelar
  * Se tem mais de um ativo, pergunte qual
  * "Cancelado! Se precisar de mais alguma coisa, to aqui"
- Se o cliente quiser fazer um NOVO pedido, use [SWITCH_TO_ORDER]
- Para problemas que voce nao consegue resolver, oferte transferir para atendente

MARCADORES:
- [SWITCH_TO_ORDER] — voltar para modo pedido
- [CANCEL_ORDER:order_id] — cancelar pedido (use o order_id real, nao o order_number)
- [TRANSFER_HUMAN] — transferir para humano
STEP;
            break;

        default:
            $stepInstructions = <<<STEP
ETAPA ATUAL: Conversa encerrada / aguardando

O cliente ja foi atendido recentemente (pedido feito ou suporte prestado).

INSTRUCOES:
- Se ele mandar saudacao ("oi", "e ai"), cumprimente de forma calorosa e pergunte como pode ajudar
- Se ele tiver pedido ativo, informe o status proativamente (veja PEDIDOS ATIVOS acima)
- Se o ultimo pedido foi entregue, pergunte se deu tudo certo
- SEMPRE encerre suas respostas com algo acolhedor tipo "Se precisar de qualquer coisa, e so mandar msg que eu to aqui!" ou "Qualquer coisa, me chama!" — faca o cliente sentir que pode voltar a qualquer momento
- Varie as frases de encerramento pra nao ficar repetitivo
STEP;
    }

    // Build full prompt
    $prompt = $personality . "\n\n" . $stepInstructions;

    // Add time-of-day context
    if ($timeCtx) {
        $weekendNote = $timeCtx['is_weekend'] ? ' (fim de semana)' : '';
        $prompt .= "\n\nCONTEXTO DE HORARIO:";
        $prompt .= "\n- Agora em SP: {$timeCtx['time_str']} ({$timeCtx['day_of_week']}{$weekendNote})";
        $prompt .= "\n- Periodo: {$timeCtx['period']}";
        $prompt .= "\n- Sugestao de saudacao: {$timeCtx['greeting']}";
        $prompt .= "\n- Use esse contexto para saudacoes e sugestoes naturais (ex: de manha sugira cafe, na hora do almoco sugira pratos, etc.)";
    }

    // Add search results if available
    if (!empty($searchContext)) {
        $prompt .= "\n\nRESULTADOS DE BUSCA (o cliente mencionou algo que encontrou match no banco):\n" . $searchContext;
        $prompt .= "\nDICA: Use esses resultados para sugerir opcoes ao cliente. Se encontrou lojas, apresente-as. Se encontrou produtos, mencione em quais lojas estao disponiveis.";
    }

    // Add memory context if available
    if (!empty($memoryContext)) {
        $prompt .= "\n\nMEMORIA DE CONVERSAS ANTERIORES (use para personalizar):\n" . $memoryContext;
    }

    // Add note if customer previously sent audio
    $context = json_decode($conversation['ai_context'] ?? '{}', true) ?: [];
    if (!empty($context['sent_audio'])) {
        $prompt .= "\n\nNOTA: O cliente ja tentou enviar audio anteriormente. Se ele parecer frustrado, seja extra amigavel.";
    }

    // Multi-turn memory: remember price sensitivity
    if (!empty($context['price_sensitive'])) {
        $prompt .= "\n\nNOTA: O cliente demonstrou interesse em precos/promocoes. Destaque opcoes mais em conta e promocoes.";
    }

    return $prompt;
}

/**
 * Build a text summary of the cart items.
 */
function buildCartSummary(array $items): string
{
    if (empty($items)) {
        return "(carrinho vazio)";
    }

    $lines = [];
    $subtotal = 0;
    foreach ($items as $i => $item) {
        $qty = (int)($item['qty'] ?? $item['quantity'] ?? 1);
        $price = (float)($item['price'] ?? 0);
        $itemTotal = $price * $qty;
        $subtotal += $itemTotal;
        $priceFmt = number_format($price, 2, ',', '.');
        $totalFmt = number_format($itemTotal, 2, ',', '.');
        $name = $item['name'] ?? 'Item';
        $lines[] = "{$i}. {$qty}x {$name} — R\$ {$priceFmt} = R\$ {$totalFmt}";
    }
    $lines[] = "";
    $lines[] = "Subtotal: R\$ " . number_format($subtotal, 2, ',', '.');

    return implode("\n", $lines);
}

/**
 * Calculate subtotal from items array.
 */
function calculateSubtotal(array $items): float
{
    $subtotal = 0;
    foreach ($items as $item) {
        $qty = (int)($item['qty'] ?? $item['quantity'] ?? 1);
        $price = (float)($item['price'] ?? 0);
        $subtotal += $price * $qty;
    }
    return round($subtotal, 2);
}

/**
 * Format payment method label.
 */
function formatPaymentLabel(string $method): string
{
    $labels = [
        'pix'      => 'PIX',
        'cartao'   => 'Cartao (link de pagamento)',
        'credito'  => 'Cartao de Credito',
        'debito'   => 'Cartao de Debito',
        'dinheiro' => 'Dinheiro na entrega',
    ];
    return $labels[$method] ?? ($method ?: 'Nao definido');
}

// ═══════════════════════════════════════════════════════════════════════════
// COUPON & CASHBACK HELPERS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Get a customer's cashback balance.
 * Uses getCashbackBalance() from helpers/cashback.php.
 */
function getCustomerCashback(PDO $db, ?int $customerId): float
{
    if (!$customerId || $customerId <= 0) {
        return 0.0;
    }
    try {
        return getCashbackBalance($db, $customerId);
    } catch (\Exception $e) {
        error_log(WABOT_LOG_PREFIX . " Error fetching cashback for customer {$customerId}: " . $e->getMessage());
        return 0.0;
    }
}

/**
 * Validate a coupon code for a given partner and cart subtotal.
 * Server-side validation — mirrors carrinho/cupom.php logic.
 *
 * @return array{valid: bool, coupon_id: int, discount: float, discount_type: string, description: string, message: string, free_delivery: bool}
 */
function wabotValidateCoupon(PDO $db, string $code, ?int $partnerId, float $subtotal, ?int $customerId): array
{
    $code = strtoupper(trim(substr($code, 0, 50)));
    $invalid = fn(string $msg) => [
        'valid' => false, 'coupon_id' => 0, 'discount' => 0,
        'discount_type' => '', 'description' => '', 'message' => $msg, 'free_delivery' => false,
    ];

    if (empty($code)) {
        return $invalid('Codigo do cupom vazio');
    }

    try {
        // Fetch coupon
        $stmt = $db->prepare("SELECT * FROM om_market_coupons WHERE code = ? AND status = 'active'");
        $stmt->execute([$code]);
        $cupom = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cupom) {
            return $invalid('Cupom invalido ou expirado');
        }

        $now = date('Y-m-d H:i:s');

        // Date validation
        if (!empty($cupom['valid_from']) && $now < $cupom['valid_from']) {
            return $invalid('Cupom ainda nao esta ativo');
        }
        if (!empty($cupom['valid_until']) && $now > $cupom['valid_until']) {
            return $invalid('Cupom expirado');
        }

        // Global max uses
        if (!empty($cupom['max_uses']) && (int)$cupom['max_uses'] > 0) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = ?");
            $stmt->execute([$cupom['id']]);
            if ((int)$stmt->fetchColumn() >= (int)$cupom['max_uses']) {
                return $invalid('Cupom esgotado');
            }
        }

        // Per-user max uses
        if ($customerId && !empty($cupom['max_uses_per_user']) && (int)$cupom['max_uses_per_user'] > 0) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ?");
            $stmt->execute([$cupom['id'], $customerId]);
            if ((int)$stmt->fetchColumn() >= (int)$cupom['max_uses_per_user']) {
                return $invalid('Voce ja usou este cupom o maximo de vezes permitido');
            }
        }

        // Min order value
        if (!empty($cupom['min_order_value']) && $subtotal < (float)$cupom['min_order_value']) {
            $minVal = number_format((float)$cupom['min_order_value'], 2, ',', '.');
            return $invalid("Pedido minimo para este cupom: R\$ {$minVal}");
        }

        // First order only
        if (!empty($cupom['first_order_only']) && (int)$cupom['first_order_only'] === 1 && $customerId) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND status NOT IN ('cancelado')");
            $stmt->execute([$customerId]);
            if ((int)$stmt->fetchColumn() > 0) {
                return $invalid('Cupom valido apenas para primeiro pedido');
            }
        }

        // Partner restriction
        if (!empty($cupom['specific_partners'])) {
            $partners = json_decode($cupom['specific_partners'], true);
            if (is_array($partners) && !empty($partners) && $partnerId && !in_array($partnerId, $partners)) {
                return $invalid('Cupom nao valido para esta loja');
            }
        }

        // Calculate discount
        $discountType = $cupom['discount_type'] ?? 'percentage';
        $discountValue = (float)($cupom['discount_value'] ?? 0);
        $maxDiscount = !empty($cupom['max_discount']) ? (float)$cupom['max_discount'] : null;
        $desconto = 0;
        $descricao = '';

        switch ($discountType) {
            case 'percentage':
                $desconto = round($subtotal * ($discountValue / 100), 2);
                if ($maxDiscount && $desconto > $maxDiscount) {
                    $desconto = $maxDiscount;
                }
                $descricao = $discountValue . '% OFF' . ($maxDiscount ? ' (max R$ ' . number_format($maxDiscount, 2, ',', '.') . ')' : '');
                break;
            case 'fixed':
                $desconto = min($discountValue, $subtotal);
                $descricao = 'R$ ' . number_format($discountValue, 2, ',', '.') . ' OFF';
                break;
            case 'free_delivery':
                $desconto = 0;
                $descricao = 'Entrega gratis';
                break;
            case 'cashback':
                $desconto = round($subtotal * ($discountValue / 100), 2);
                if ($maxDiscount && $desconto > $maxDiscount) {
                    $desconto = $maxDiscount;
                }
                $descricao = $discountValue . '% cashback';
                break;
            default:
                $descricao = 'Desconto aplicado';
        }

        return [
            'valid'         => true,
            'coupon_id'     => (int)$cupom['id'],
            'discount'      => round($desconto, 2),
            'discount_type' => $discountType,
            'description'   => $descricao,
            'message'       => "Cupom *{$code}* aplicado! {$descricao}",
            'free_delivery' => $discountType === 'free_delivery',
        ];

    } catch (\Exception $e) {
        error_log(WABOT_LOG_PREFIX . " Error validating coupon '{$code}': " . $e->getMessage());
        return $invalid('Erro ao validar cupom');
    }
}

/**
 * Get available coupons for a store (or global coupons).
 * Returns formatted list suitable for AI prompt context.
 *
 * @return array List of available coupons with code, description, discount_text
 */
function getAvailableCoupons(PDO $db, ?int $partnerId, ?int $customerId = null): array
{
    try {
        $now = date('Y-m-d H:i:s');

        $stmt = $db->prepare("
            SELECT
                c.id, c.code, c.description, c.discount_type, c.discount_value,
                c.max_discount, c.min_order_value, c.valid_until, c.first_order_only,
                c.max_uses, c.max_uses_per_user, c.specific_customers,
                (SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = c.id) as uses_count
            FROM om_market_coupons c
            WHERE c.status = 'active'
              AND (c.valid_from IS NULL OR c.valid_from <= ?)
              AND (c.valid_until IS NULL OR c.valid_until >= ?)
              AND (
                  c.specific_partners IS NULL
                  OR c.specific_partners = ''
                  OR c.specific_partners = '[]'
                  OR c.specific_partners::jsonb @> ?::jsonb
              )
            ORDER BY c.discount_value DESC
            LIMIT 5
        ");
        $stmt->execute([$now, $now, json_encode([$partnerId ?: 0])]);
        $coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($coupons as $c) {
            // Skip exhausted coupons
            if ($c['max_uses'] && (int)$c['uses_count'] >= (int)$c['max_uses']) {
                continue;
            }

            // Skip customer-specific coupons not for this customer
            if (!empty($c['specific_customers']) && $customerId) {
                $specCustomers = json_decode($c['specific_customers'], true);
                if (is_array($specCustomers) && !empty($specCustomers) && !in_array($customerId, $specCustomers)) {
                    continue;
                }
            }

            // Skip if customer already used max times
            if ($customerId && $c['max_uses_per_user'] && (int)$c['max_uses_per_user'] > 0) {
                $stmtUsage = $db->prepare("SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ?");
                $stmtUsage->execute([$c['id'], $customerId]);
                if ((int)$stmtUsage->fetchColumn() >= (int)$c['max_uses_per_user']) {
                    continue;
                }
            }

            // Format discount text
            $discountText = '';
            switch ($c['discount_type']) {
                case 'percentage':
                    $discountText = (int)$c['discount_value'] . '% de desconto';
                    if ($c['max_discount']) {
                        $discountText .= ' (max R$ ' . number_format((float)$c['max_discount'], 2, ',', '.') . ')';
                    }
                    break;
                case 'fixed':
                    $discountText = 'R$ ' . number_format((float)$c['discount_value'], 2, ',', '.') . ' de desconto';
                    break;
                case 'free_delivery':
                    $discountText = 'Frete gratis';
                    break;
                case 'cashback':
                    $discountText = (int)$c['discount_value'] . '% de cashback';
                    break;
                default:
                    $discountText = 'Desconto especial';
            }

            $conditions = [];
            if ((float)$c['min_order_value'] > 0) {
                $conditions[] = 'pedido min. R$ ' . number_format((float)$c['min_order_value'], 2, ',', '.');
            }
            if ($c['first_order_only']) {
                $conditions[] = 'so 1a compra';
            }

            $result[] = [
                'code'            => $c['code'],
                'description'     => $c['description'] ?: $discountText,
                'discount_text'   => $discountText,
                'discount_type'   => $c['discount_type'],
                'discount_value'  => (float)$c['discount_value'],
                'min_order_value' => (float)$c['min_order_value'],
                'conditions'      => implode(', ', $conditions),
            ];
        }

        return $result;

    } catch (\Exception $e) {
        error_log(WABOT_LOG_PREFIX . " Error fetching available coupons: " . $e->getMessage());
        return [];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// QUICK COMMANDS (pre-Claude shortcuts)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Check if the message matches a quick command.
 * Returns the response text, or null if no match.
 */
function handleQuickCommand(PDO $db, string $message, array $conversation, array $context): ?array
{
    $msg = mb_strtolower(trim($message));
    $phone = $conversation['phone'];
    $customerId = $conversation['customer_id'] ?? null;
    $conversationId = $conversation['id'];

    // Help / greeting
    if (in_array($msg, ['oi', 'ola', 'olá', 'hi', 'hello', 'bom dia', 'boa tarde', 'boa noite', 'ajuda', 'help', '?'])) {
        // Don't shortcut — let Claude handle with personality
        return null;
    }

    // Menu / cardapio — show store suggestions
    if (in_array($msg, ['cardapio', 'cardápio', 'menu', 'lojas', 'restaurantes'])) {
        $stores = getActiveStores($db, 10);
        if (empty($stores)) {
            return [
                'response' => "Nenhuma loja disponivel no momento. Tente novamente mais tarde.",
                'context'  => $context,
            ];
        }

        $lines = ["Aqui estao nossas lojas disponiveis:\n"];
        foreach ($stores as $s) {
            $name = $s['trade_name'] ?: $s['name'];
            $status = ((int)($s['is_open'] ?? 0) === 1) ? '🟢 Aberta' : '🔴 Fechada';
            $cat = $s['categoria'] ?? '';
            $lines[] = "• *{$name}* ({$cat}) — {$status}";
        }
        $lines[] = "\nDigite o nome da loja para ver o cardapio.";

        return [
            'response' => implode("\n", $lines),
            'context'  => $context,
        ];
    }

    // Status — check order status
    if (in_array($msg, ['status', 'meu pedido', 'pedido', 'rastrear', 'tracking', 'onde esta meu pedido'])) {
        if (!$customerId) {
            return [
                'response' => "Para consultar seus pedidos, preciso identificar sua conta. Qual seu nome ou email cadastrado?",
                'context'  => array_merge($context, ['mode' => 'support', 'step' => STEP_SUPPORT]),
            ];
        }

        $orders = getCustomerRecentOrders($db, $customerId, 3);
        if (empty($orders)) {
            return [
                'response' => "Voce nao tem pedidos recentes. Quer fazer um novo pedido?",
                'context'  => $context,
            ];
        }

        $statusLabels = [
            'pendente'    => '⏳ Pendente',
            'confirmado'  => '✅ Confirmado',
            'aceito'      => '✅ Aceito',
            'preparando'  => '👨‍🍳 Preparando',
            'pronto'      => '📦 Pronto',
            'em_entrega'  => '🏍️ A caminho',
            'entregue'    => '✅ Entregue',
            'cancelado'   => '❌ Cancelado',
            'recusado'    => '❌ Recusado',
        ];

        $lines = ["Seus pedidos recentes:\n"];
        foreach ($orders as $o) {
            $statusLabel = $statusLabels[$o['status']] ?? $o['status'];
            $total = number_format((float)$o['total'], 2, ',', '.');
            $date = date('d/m H:i', strtotime($o['date_added'] ?? $o['created_at']));
            $lines[] = "• *#{$o['order_number']}* — {$o['partner_name']}";
            $lines[] = "  {$statusLabel} | R\$ {$total} | {$date}";
        }
        $lines[] = "\nPrecisa de ajuda com algum pedido?";

        $newContext = array_merge($context, ['mode' => 'support', 'step' => STEP_SUPPORT]);
        return [
            'response' => implode("\n", $lines),
            'context'  => $newContext,
        ];
    }

    // Repeat last order — with product availability check and current prices
    if (in_array($msg, ['repetir', 'repetir pedido', 'mesmo pedido', 'quero o mesmo', 'pedir de novo', 'o de sempre'])) {
        if (!$customerId) {
            return [
                'response' => "Preciso identificar sua conta para repetir o pedido. Qual seu nome ou email cadastrado?",
                'context'  => $context,
            ];
        }

        $orders = getCustomerRecentOrders($db, $customerId, 1);
        if (empty($orders)) {
            return [
                'response' => "Nao encontrei pedidos anteriores. Vamos fazer um novo?",
                'context'  => $context,
            ];
        }

        $lastOrder = $orders[0];
        $orderItems = getOrderItems($db, $lastOrder['order_id']);

        if (empty($orderItems)) {
            return [
                'response' => "Nao consegui encontrar os itens do seu ultimo pedido. Vamos montar um novo?",
                'context'  => $context,
            ];
        }

        // Load items into cart using CURRENT prices and checking availability
        $cartItems = [];
        $skippedItems = [];

        foreach ($orderItems as $oi) {
            $productId = (int)($oi['product_id'] ?? 0);
            if ($productId <= 0) continue;

            $productStatus = $oi['product_status'] ?? null;
            $productStock = (int)($oi['product_stock'] ?? 0);

            // Skip unavailable products
            if ($productStatus === null || (int)$productStatus !== 1 || $productStock <= 0) {
                $skippedItems[] = $oi['name'];
                continue;
            }

            // Use CURRENT price from DB
            $currentPrice = (float)($oi['current_price'] ?? 0);
            $currentSpecial = (float)($oi['current_special_price'] ?? 0);
            $realPrice = ($currentSpecial > 0 && $currentSpecial < $currentPrice)
                ? $currentSpecial
                : $currentPrice;

            if ($realPrice <= 0) {
                $skippedItems[] = $oi['name'];
                continue;
            }

            $qty = min((int)($oi['quantity'] ?? 1), $productStock);
            $cartItems[] = [
                'product_id' => $productId,
                'name'       => $oi['current_name'] ?? $oi['name'],
                'price'      => $realPrice,
                'qty'        => $qty,
            ];
        }

        if (empty($cartItems)) {
            return [
                'response' => "Poxa, nenhum dos itens do seu ultimo pedido esta disponivel no momento. Vamos montar um pedido novo?",
                'context'  => $context,
            ];
        }

        // Load delivery fee from partner
        $deliveryFee = WABOT_DEFAULT_DELIVERY_FEE;
        try {
            $pStmt = $db->prepare("SELECT delivery_fee FROM om_market_partners WHERE partner_id = ?");
            $pStmt->execute([(int)$lastOrder['partner_id']]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($pRow) {
                $deliveryFee = (float)($pRow['delivery_fee'] ?? WABOT_DEFAULT_DELIVERY_FEE);
            }
        } catch (\Exception $e) { /* use default */ }

        $newContext = array_merge($context, [
            'step'         => STEP_GET_ADDRESS,
            'mode'         => 'ordering',
            'partner_id'   => (int)$lastOrder['partner_id'],
            'partner_name' => $lastOrder['partner_name'],
            'delivery_fee' => $deliveryFee,
            'items'        => $cartItems,
        ]);

        $summary = buildCartSummary($cartItems);
        $response = "Encontrei seu ultimo pedido da *{$lastOrder['partner_name']}*:\n\n{$summary}";

        if (!empty($skippedItems)) {
            $response .= "\n\n_Alguns itens nao estao mais disponiveis: " . implode(', ', $skippedItems) . "_";
        }

        $response .= "\n\nQuer pedir exatamente isso? Pra onde entrego?";

        return [
            'response' => $response,
            'context'  => $newContext,
        ];
    }

    // Transfer to human
    if (in_array($msg, ['atendente', 'falar com atendente', 'humano', 'pessoa', 'falar com pessoa', 'suporte', 'reclamacao', 'reclamar'])) {
        $newContext = array_merge($context, ['step' => STEP_SUPPORT]);
        updateConversationStatus($db, $conversationId, 'waiting_agent');

        // Broadcast to call center dashboard
        ccBroadcastDashboard('wa_transfer_request', [
            'conversation_id' => $conversationId,
            'phone'           => $phone,
            'customer_name'   => $conversation['customer_name'] ?? 'Cliente',
            'reason'          => 'Solicitou atendente humano',
        ]);

        return [
            'response' => "Entendi! Estou transferindo voce para um atendente humano. Aguarde um momento, por favor. 🙏\n\nEnquanto isso, se quiser, pode me descrever o que precisa que ja passo a informacao.",
            'context'  => $newContext,
        ];
    }

    // Cancel
    if (in_array($msg, ['cancelar', 'cancela', 'nao quero mais', 'desistir'])) {
        // If in ordering flow, cancel current order assembly
        if (in_array($context['step'], [STEP_TAKE_ORDER, STEP_GET_ADDRESS, STEP_GET_PAYMENT, STEP_CONFIRM_ORDER])) {
            $newContext = getDefaultContext();
            return [
                'response' => "Pedido cancelado. Se precisar de algo, e so chamar!",
                'context'  => $newContext,
            ];
        }
        // Otherwise let Claude handle
        return null;
    }

    // Opt-out from proactive WhatsApp messages
    if (in_array($msg, ['parar', 'sair', 'cancelar notificacoes', 'cancelar notificações', 'parar notificacoes', 'parar notificações', 'nao quero receber', 'não quero receber', 'parar mensagens', 'stop'])) {
        if ($customerId) {
            try {
                $db->prepare("
                    INSERT INTO om_whatsapp_proactive_optout (customer_id, opted_out_at, reason)
                    VALUES (?, NOW(), 'user_request')
                    ON CONFLICT (customer_id) DO NOTHING
                ")->execute([$customerId]);
            } catch (Exception $e) {
                error_log(WABOT_LOG_PREFIX . " Opt-out DB error: " . $e->getMessage());
            }
        }

        return [
            'response' => "Pronto! Voce nao vai mais receber mensagens promocionais da SuperBora. Se mudar de ideia, e so mandar *voltar notificacoes* que a gente reativa. \xE2\x9C\x85\n\nIsso nao afeta as notificacoes dos seus pedidos — essas continuam normalmente!",
            'context'  => $context,
        ];
    }

    // Opt back in to proactive WhatsApp messages
    if (in_array($msg, ['voltar notificacoes', 'voltar notificações', 'quero receber', 'ativar notificacoes', 'ativar notificações'])) {
        if ($customerId) {
            try {
                $db->prepare("
                    DELETE FROM om_whatsapp_proactive_optout WHERE customer_id = ?
                ")->execute([$customerId]);
            } catch (Exception $e) {
                error_log(WABOT_LOG_PREFIX . " Opt-in DB error: " . $e->getMessage());
            }
        }

        return [
            'response' => "Show! Reativei suas notificacoes. Voce vai receber novidades e promos das suas lojas favoritas! \xF0\x9F\x8E\x89",
            'context'  => $context,
        ];
    }

    return null;
}

// ═══════════════════════════════════════════════════════════════════════════
// AI RESPONSE PROCESSING — Parse markers from Claude
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Process the AI response: parse markers, update context, return cleaned text.
 */
function processAiResponse(PDO $db, array $conversation, string $aiResponse, string $phone): array
{
    $context = json_decode($conversation['ai_context'] ?? '{}', true) ?: getDefaultContext();
    $conversationId = $conversation['id'];
    $customerId = $conversation['customer_id'] ?? null;

    // Track markers found
    $markersFound = [];
    $cleanedResponse = $aiResponse;

    // Parse [STORE:id:name]
    if (preg_match('/\[STORE:(\d+):([^\]]+)\]/', $aiResponse, $m)) {
        $storeId = (int)$m[1];
        $storeName = trim($m[2]);
        $markersFound[] = "STORE:{$storeId}:{$storeName}";

        // Verify store exists and is active
        $storeStmt = $db->prepare("
            SELECT partner_id, name, trade_name, is_open, delivery_fee, min_order_value
            FROM om_market_partners
            WHERE partner_id = ? AND status::text = '1'
        ");
        $storeStmt->execute([$storeId]);
        $store = $storeStmt->fetch(PDO::FETCH_ASSOC);

        if ($store) {
            $context['partner_id'] = (int)$store['partner_id'];
            $context['partner_name'] = $store['trade_name'] ?: $store['name'];
            $context['delivery_fee'] = (float)($store['delivery_fee'] ?? WABOT_DEFAULT_DELIVERY_FEE);
            $context['step'] = STEP_TAKE_ORDER;

            // Save to memory
            if ($customerId) {
                aiMemorySave($db, $phone, $customerId, 'preference', 'favorite_store',
                    $context['partner_name'] . ' (ID:' . $storeId . ')');
            }
        } else {
            // Store not found — remove marker from cleaned response, add error
            $cleanedResponse .= "\n\n_Desculpe, nao encontrei essa loja no sistema. Pode tentar novamente?_";
        }

        $cleanedResponse = str_replace($m[0], '', $cleanedResponse);
    }

    // Parse [ITEM:product_id:name:price:qty] — can be multiple
    if (preg_match_all('/\[ITEM:(\d+):([^:]+):([0-9.]+):(\d+)\]/', $aiResponse, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $productId = (int)$m[1];
            $itemName = trim($m[2]);
            $itemPrice = (float)$m[3];
            $itemQty = max(1, (int)$m[4]);
            $markersFound[] = "ITEM:{$productId}:{$itemName}:{$itemPrice}:{$itemQty}";

            // Validate product exists and price matches
            $product = getProduct($db, $productId);
            if ($product) {
                $realPrice = ((float)($product['special_price'] ?? 0) > 0 && (float)$product['special_price'] < (float)$product['price'])
                    ? (float)$product['special_price']
                    : (float)$product['price'];

                // Ensure partner matches
                if ($product['partner_id'] == $context['partner_id']) {
                    $context['items'][] = [
                        'product_id' => $productId,
                        'name'       => $product['name'], // Use DB name, not AI name
                        'price'      => $realPrice,       // Use DB price, not AI price
                        'qty'        => $itemQty,
                    ];
                } else {
                    error_log(WABOT_LOG_PREFIX . " Product {$productId} belongs to partner {$product['partner_id']}, not {$context['partner_id']}");
                }
            } else {
                error_log(WABOT_LOG_PREFIX . " Product {$productId} not found in DB");
            }

            $cleanedResponse = str_replace($m[0], '', $cleanedResponse);
        }
    }

    // Parse [REMOVE_ITEM:index]
    if (preg_match_all('/\[REMOVE_ITEM:(\d+)\]/', $aiResponse, $matches, PREG_SET_ORDER)) {
        // Sort by index descending to avoid shifting issues
        $indices = array_map(fn($m) => (int)$m[1], $matches);
        rsort($indices);

        foreach ($indices as $idx) {
            if (isset($context['items'][$idx])) {
                $removedName = $context['items'][$idx]['name'] ?? 'Item';
                array_splice($context['items'], $idx, 1);
                $markersFound[] = "REMOVE_ITEM:{$idx} ({$removedName})";
            }
        }
        // Re-index array
        $context['items'] = array_values($context['items']);

        foreach ($matches as $m) {
            $cleanedResponse = str_replace($m[0], '', $cleanedResponse);
        }
    }

    // Parse [NEXT_STEP]
    if (strpos($aiResponse, '[NEXT_STEP]') !== false) {
        $markersFound[] = 'NEXT_STEP';
        $currentStep = $context['step'];
        $nextStep = getNextStep($currentStep, $context);
        $context['step'] = $nextStep;
        $cleanedResponse = str_replace('[NEXT_STEP]', '', $cleanedResponse);
    }

    // Parse [CONFIRMED]
    if (strpos($aiResponse, '[CONFIRMED]') !== false) {
        $markersFound[] = 'CONFIRMED';
        $context['step'] = STEP_SUBMIT_ORDER;
        $cleanedResponse = str_replace('[CONFIRMED]', '', $cleanedResponse);
    }

    // Parse [CANCEL_ORDER:order_id]
    if (preg_match('/\[CANCEL_ORDER:(\d+)\]/', $aiResponse, $m)) {
        $cancelOrderId = (int)$m[1];
        $markersFound[] = "CANCEL_ORDER:{$cancelOrderId}";
        handleOrderCancellation($db, $cancelOrderId, $customerId);
        $cleanedResponse = str_replace($m[0], '', $cleanedResponse);
    }

    // Parse [TRANSFER_HUMAN]
    if (strpos($aiResponse, '[TRANSFER_HUMAN]') !== false) {
        $markersFound[] = 'TRANSFER_HUMAN';
        updateConversationStatus($db, $conversationId, 'waiting_agent');

        ccBroadcastDashboard('wa_transfer_request', [
            'conversation_id' => $conversationId,
            'phone'           => $phone,
            'customer_name'   => $conversation['customer_name'] ?? 'Cliente',
            'reason'          => 'AI transferiu para atendente',
        ]);

        $cleanedResponse = str_replace('[TRANSFER_HUMAN]', '', $cleanedResponse);
    }

    // Parse [SWITCH_TO_ORDER]
    if (strpos($aiResponse, '[SWITCH_TO_ORDER]') !== false) {
        $markersFound[] = 'SWITCH_TO_ORDER';
        $context['mode'] = 'ordering';
        $context['step'] = STEP_GREETING;
        $cleanedResponse = str_replace('[SWITCH_TO_ORDER]', '', $cleanedResponse);
    }

    // Parse [REORDER:order_number] — smart reorder from previous orders
    if (preg_match('/\[REORDER:([^\]]+)\]/', $aiResponse, $m)) {
        $reorderNumber = trim($m[1]);
        $markersFound[] = "REORDER:{$reorderNumber}";
        $cleanedResponse = str_replace($m[0], '', $cleanedResponse);

        if ($customerId) {
            $reorderOrder = getCustomerOrderByNumber($db, $customerId, $reorderNumber);

            if ($reorderOrder) {
                $reorderItems = getOrderItems($db, (int)$reorderOrder['order_id']);

                if (!empty($reorderItems)) {
                    // Set store from the original order
                    $context['partner_id'] = (int)$reorderOrder['partner_id'];
                    $context['partner_name'] = $reorderOrder['partner_name'];

                    // Load delivery fee from partner
                    try {
                        $pStmt = $db->prepare("SELECT delivery_fee FROM om_market_partners WHERE partner_id = ?");
                        $pStmt->execute([(int)$reorderOrder['partner_id']]);
                        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
                        if ($pRow) {
                            $context['delivery_fee'] = (float)($pRow['delivery_fee'] ?? WABOT_DEFAULT_DELIVERY_FEE);
                        }
                    } catch (\Exception $e) { /* use default */ }

                    // Build cart with current prices, skip unavailable products
                    $cartItems = [];
                    $skippedItems = [];

                    foreach ($reorderItems as $ri) {
                        $productId = (int)($ri['product_id'] ?? 0);
                        if ($productId <= 0) continue;

                        // Check if product still exists, is active, and has stock
                        $productStatus = $ri['product_status'] ?? null;
                        $productStock = (int)($ri['product_stock'] ?? 0);

                        if ($productStatus === null || (int)$productStatus !== 1 || $productStock <= 0) {
                            // Product no longer available
                            $skippedItems[] = $ri['name'];
                            continue;
                        }

                        // Use CURRENT price from DB, not old order price
                        $currentPrice = (float)($ri['current_price'] ?? 0);
                        $currentSpecial = (float)($ri['current_special_price'] ?? 0);
                        $realPrice = ($currentSpecial > 0 && $currentSpecial < $currentPrice)
                            ? $currentSpecial
                            : $currentPrice;

                        if ($realPrice <= 0) {
                            $skippedItems[] = $ri['name'];
                            continue;
                        }

                        $qty = min((int)($ri['quantity'] ?? 1), $productStock);
                        $cartItems[] = [
                            'product_id' => $productId,
                            'name'       => $ri['current_name'] ?? $ri['name'],
                            'price'      => $realPrice,
                            'qty'        => $qty,
                        ];
                    }

                    if (!empty($cartItems)) {
                        $context['items'] = $cartItems;
                        $context['mode'] = 'ordering';
                        // Skip directly to address step since items are loaded
                        $context['step'] = STEP_GET_ADDRESS;

                        // Append info about skipped items to the response
                        if (!empty($skippedItems)) {
                            $skippedList = implode(', ', $skippedItems);
                            $cleanedResponse .= "\n\n_Obs: alguns itens nao estao mais disponiveis e foram removidos: {$skippedList}_";
                        }

                        // Append reorder summary
                        $cartSummary = buildCartSummary($cartItems);
                        $cleanedResponse .= "\n\n" . $cartSummary;

                        // Save to memory
                        if ($customerId) {
                            aiMemorySave($db, $phone, $customerId, 'preference', 'favorite_store',
                                $context['partner_name'] . ' (ID:' . $context['partner_id'] . ')');
                        }
                    } else {
                        // All items unavailable
                        $cleanedResponse .= "\n\n_Poxa, nenhum dos itens desse pedido esta disponivel no momento. Vamos montar um pedido novo?_";
                        $context['step'] = STEP_GREETING;
                    }
                } else {
                    $cleanedResponse .= "\n\n_Nao encontrei os itens desse pedido. Vamos montar um novo?_";
                    $context['step'] = STEP_GREETING;
                }
            } else {
                $cleanedResponse .= "\n\n_Nao encontrei o pedido #{$reorderNumber}. Pode verificar o numero?_";
            }
        } else {
            $cleanedResponse .= "\n\n_Preciso identificar sua conta para repetir o pedido. Qual seu nome ou email cadastrado?_";
        }
    }

    // Parse [CITY:nome_da_cidade]
    if (preg_match('/\[CITY:([^\]]+)\]/', $aiResponse, $m)) {
        $cityName = trim($m[1]);
        $markersFound[] = "CITY:{$cityName}";
        $context['customer_city'] = $cityName;

        // Save to memory if customer is identified
        if ($customerId) {
            aiMemorySave($db, $phone, $customerId, 'preference', 'city', $cityName);
        }

        $cleanedResponse = str_replace($m[0], '', $cleanedResponse);
    }

    // Parse [SCHEDULE:datetime] — schedule order for future delivery
    if (preg_match('/\[SCHEDULE:([^\]]+)\]/', $aiResponse, $m)) {
        $rawSchedule = trim($m[1]);
        $markersFound[] = "SCHEDULE:{$rawSchedule}";
        $cleanedResponse = str_replace($m[0], '', $cleanedResponse);

        // Parse the datetime
        $scheduledTs = strtotime($rawSchedule);
        if ($scheduledTs !== false) {
            $now = time();
            $minTime = $now + (30 * 60);    // 30 minutes from now
            $maxTime = $now + (7 * 24 * 3600); // 7 days from now

            if ($scheduledTs < $minTime) {
                $cleanedResponse .= "\n\n_O horario precisa ser pelo menos 30 minutos no futuro. Pode escolher outro horario?_";
                error_log(WABOT_LOG_PREFIX . " Schedule rejected: {$rawSchedule} is too soon (min: " . date('c', $minTime) . ")");
            } elseif ($scheduledTs > $maxTime) {
                $cleanedResponse .= "\n\n_O agendamento pode ser pra no maximo 7 dias. Pode escolher uma data mais proxima?_";
                error_log(WABOT_LOG_PREFIX . " Schedule rejected: {$rawSchedule} is too far (max: " . date('c', $maxTime) . ")");
            } else {
                $context['scheduled_for'] = date('Y-m-d\TH:i:s', $scheduledTs);
                $schedFmt = date('d/m \a\s H:i', $scheduledTs);
                error_log(WABOT_LOG_PREFIX . " Order scheduled for: {$context['scheduled_for']}");
            }
        } else {
            $cleanedResponse .= "\n\n_Nao entendi o horario. Pode falar de outro jeito? Ex: 'amanha as 12h' ou 'sabado as 19:00'_";
            error_log(WABOT_LOG_PREFIX . " Schedule parse failed: {$rawSchedule}");
        }
    }

    // Parse [SAVE_ADDRESS] — flag to save new address after order
    if (strpos($aiResponse, '[SAVE_ADDRESS]') !== false) {
        $markersFound[] = 'SAVE_ADDRESS';
        $context['is_new_address'] = true;
        $cleanedResponse = str_replace('[SAVE_ADDRESS]', '', $cleanedResponse);
    }

    // Parse [COUPON:code]
    if (preg_match('/\[COUPON:([^\]]+)\]/', $aiResponse, $m)) {
        $couponCode = strtoupper(trim($m[1]));
        $markersFound[] = "COUPON:{$couponCode}";
        $cleanedResponse = str_replace($m[0], '', $cleanedResponse);

        $subtotalForCoupon = calculateSubtotal($context['items'] ?? []);
        $couponResult = wabotValidateCoupon(
            $db,
            $couponCode,
            $context['partner_id'] ?? null,
            $subtotalForCoupon,
            $customerId
        );

        if ($couponResult['valid']) {
            $context['coupon_code'] = $couponCode;
            $context['coupon_id'] = $couponResult['coupon_id'];
            $context['coupon_discount'] = $couponResult['discount'];
            $context['coupon_description'] = $couponResult['description'];
            $context['coupon_free_delivery'] = $couponResult['free_delivery'];

            // If free delivery coupon, zero out delivery fee
            if ($couponResult['free_delivery']) {
                $context['delivery_fee'] = 0;
            }

            $cleanedResponse .= "\n\n" . $couponResult['message'];
            error_log(WABOT_LOG_PREFIX . " Coupon {$couponCode} applied: discount={$couponResult['discount']}, type={$couponResult['discount_type']}");
        } else {
            // Invalid coupon — inform customer
            $cleanedResponse .= "\n\n_" . $couponResult['message'] . "_";
            error_log(WABOT_LOG_PREFIX . " Coupon {$couponCode} rejected: {$couponResult['message']}");
        }
    }

    // Parse [USE_CASHBACK:value]
    if (preg_match('/\[USE_CASHBACK:([^\]]+)\]/', $aiResponse, $m)) {
        $cashbackInput = trim($m[1]);
        $markersFound[] = "USE_CASHBACK:{$cashbackInput}";
        $cleanedResponse = str_replace($m[0], '', $cleanedResponse);

        if ($customerId) {
            $cbBalance = getCustomerCashback($db, $customerId);

            if ($cbBalance <= 0) {
                $cleanedResponse .= "\n\n_Voce nao tem saldo de cashback disponivel._";
            } else {
                // Parse amount: "all" means full balance, otherwise parse as float
                if (strtolower($cashbackInput) === 'all' || strtolower($cashbackInput) === 'tudo' || strtolower($cashbackInput) === 'todo') {
                    $cashbackAmount = $cbBalance;
                } else {
                    $cashbackAmount = (float)str_replace(',', '.', $cashbackInput);
                }

                // Cap at balance and at subtotal
                $subtotalForCb = calculateSubtotal($context['items'] ?? []);
                $couponDiscountForCb = (float)($context['coupon_discount'] ?? 0);
                $maxCashback = max(0, $subtotalForCb - $couponDiscountForCb);
                $cashbackAmount = min($cashbackAmount, $cbBalance, $maxCashback);

                if ($cashbackAmount > 0) {
                    $context['use_cashback'] = round($cashbackAmount, 2);
                    $cbFmt = number_format($cashbackAmount, 2, ',', '.');
                    $cleanedResponse .= "\n\nCashback de *R\$ {$cbFmt}* sera aplicado no pedido!";
                    error_log(WABOT_LOG_PREFIX . " Cashback {$cashbackAmount} will be used (balance: {$cbBalance})");
                } else {
                    $cleanedResponse .= "\n\n_Nao foi possivel aplicar cashback neste pedido._";
                }
            }
        } else {
            $cleanedResponse .= "\n\n_Preciso identificar sua conta para usar cashback._";
        }
    }

    // Update subtotal and total with discounts
    $context['subtotal'] = calculateSubtotal($context['items'] ?? []);
    $couponDisc = (float)($context['coupon_discount'] ?? 0);
    $cashbackUse = (float)($context['use_cashback'] ?? 0);
    $delivFee = !empty($context['coupon_free_delivery']) ? 0 : (float)($context['delivery_fee'] ?? WABOT_DEFAULT_DELIVERY_FEE);
    $context['total'] = max(0, $context['subtotal'] - $couponDisc - $cashbackUse + $delivFee + WABOT_SERVICE_FEE);

    // Clean up response text
    $cleanedResponse = trim(preg_replace('/\n{3,}/', "\n\n", $cleanedResponse));

    // Log markers
    if (!empty($markersFound)) {
        error_log(WABOT_LOG_PREFIX . " Markers: " . implode(', ', $markersFound));
    }

    return [
        'response' => $cleanedResponse,
        'context'  => $context,
        'markers'  => $markersFound,
    ];
}

/**
 * Determine next step based on current step.
 */
function getNextStep(string $currentStep, array $context): string
{
    $flow = [
        STEP_GREETING        => STEP_IDENTIFY_STORE,
        STEP_IDENTIFY_STORE  => STEP_TAKE_ORDER,
        STEP_TAKE_ORDER      => STEP_GET_ADDRESS,
        STEP_GET_ADDRESS     => STEP_GET_PAYMENT,
        STEP_GET_PAYMENT     => STEP_CONFIRM_ORDER,
        STEP_CONFIRM_ORDER   => STEP_SUBMIT_ORDER,
    ];

    // If cart is empty, don't advance past take_order
    if ($currentStep === STEP_TAKE_ORDER && empty($context['items'])) {
        return STEP_TAKE_ORDER;
    }

    return $flow[$currentStep] ?? STEP_GREETING;
}

/**
 * Handle order cancellation via AI marker.
 */
function handleOrderCancellation(PDO $db, int $orderId, ?int $customerId): bool
{
    if (!$customerId || !$orderId) return false;

    try {
        // Verify order belongs to customer and is cancellable
        $stmt = $db->prepare("
            SELECT order_id, status, customer_id
            FROM om_market_orders
            WHERE order_id = ? AND customer_id = ?
            FOR UPDATE
        ");
        $db->beginTransaction();
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $db->rollBack();
            return false;
        }

        $cancellableStatuses = ['pendente', 'confirmado', 'aceito'];
        if (!in_array($order['status'], $cancellableStatuses)) {
            $db->rollBack();
            return false;
        }

        $db->prepare("
            UPDATE om_market_orders
            SET status = 'cancelado',
                cancel_reason = 'Cancelado pelo cliente via WhatsApp AI',
                cancelled_at = NOW()
            WHERE order_id = ?
        ")->execute([$orderId]);

        // Add timeline entry
        $db->prepare("
            INSERT INTO om_market_order_timeline (order_id, status, description, created_at)
            VALUES (?, 'cancelado', 'Cancelado pelo cliente via WhatsApp AI', NOW())
        ")->execute([$orderId]);

        $db->commit();

        error_log(WABOT_LOG_PREFIX . " Order {$orderId} cancelled via WhatsApp AI");
        return true;

    } catch (\Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log(WABOT_LOG_PREFIX . " Error cancelling order {$orderId}: " . $e->getMessage());
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ORDER SUBMISSION
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Submit a WhatsApp order to the database.
 * Similar to checkout/processar.php but simplified for WhatsApp flow.
 */
function submitWhatsAppOrder(PDO $db, array $conversation): array
{
    $context = json_decode($conversation['ai_context'] ?? '{}', true) ?: [];
    $phone = $conversation['phone'];
    $conversationId = $conversation['id'];
    $customerId = $conversation['customer_id'] ?? null;

    // ── Validation ──────────────────────────────────────────────────────

    if (empty($context['partner_id'])) {
        return ['success' => false, 'message' => 'Loja nao selecionada.'];
    }
    if (empty($context['items'])) {
        return ['success' => false, 'message' => 'Nenhum item no carrinho.'];
    }
    if (empty($context['address'])) {
        return ['success' => false, 'message' => 'Endereco de entrega nao informado.'];
    }
    if (empty($context['payment_method'])) {
        return ['success' => false, 'message' => 'Forma de pagamento nao definida.'];
    }

    $partnerId = (int)$context['partner_id'];
    $items = $context['items'];
    $address = $context['address'];
    $paymentMethod = $context['payment_method'];
    $paymentChange = (float)($context['payment_change'] ?? 0);
    $notes = $context['notes'] ?? 'Pedido via WhatsApp AI';

    // ── Verify partner is active and open ───────────────────────────────

    $partnerStmt = $db->prepare("
        SELECT partner_id, name, trade_name, is_open, min_order_value, delivery_fee,
               phone as partner_phone, categoria, entrega_propria
        FROM om_market_partners
        WHERE partner_id = ? AND status::text = '1'
    ");
    $partnerStmt->execute([$partnerId]);
    $partner = $partnerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$partner) {
        return ['success' => false, 'message' => 'Loja nao esta disponivel no momento.'];
    }

    if ((int)($partner['is_open'] ?? 0) !== 1) {
        return ['success' => false, 'message' => 'A loja esta fechada no momento. Tente novamente mais tarde.'];
    }

    // ── Validate items and calculate totals ─────────────────────────────

    $subtotal = 0;
    $validatedItems = [];

    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = max(1, (int)($item['qty'] ?? $item['quantity'] ?? 1));

        if ($productId <= 0) continue;

        $product = getProduct($db, $productId);
        if (!$product || (int)$product['partner_id'] !== $partnerId) {
            error_log(WABOT_LOG_PREFIX . " Skipping invalid product {$productId} for partner {$partnerId}");
            continue;
        }

        // Check stock
        if ((int)$product['stock'] < $qty) {
            return [
                'success' => false,
                'message' => "'{$product['name']}' nao tem estoque suficiente (disponivel: {$product['stock']}).",
            ];
        }

        $price = ((float)($product['special_price'] ?? 0) > 0 && (float)$product['special_price'] < (float)$product['price'])
            ? (float)$product['special_price']
            : (float)$product['price'];

        if ($price <= 0) continue;

        $validatedItems[] = [
            'product_id' => $productId,
            'name'       => $product['name'],
            'price'      => $price,
            'qty'        => $qty,
            'total'      => round($price * $qty, 2),
        ];
        $subtotal += round($price * $qty, 2);
    }

    if (empty($validatedItems)) {
        return ['success' => false, 'message' => 'Nenhum item valido no carrinho.'];
    }

    // Check minimum order
    $minOrder = (float)($partner['min_order_value'] ?? 0);
    if ($minOrder > 0 && $subtotal < $minOrder) {
        return [
            'success' => false,
            'message' => "Pedido minimo: R\$ " . number_format($minOrder, 2, ',', '.'),
        ];
    }

    // Calculate fees and discounts
    $deliveryFee = (float)($context['delivery_fee'] ?? (float)($partner['delivery_fee'] ?? WABOT_DEFAULT_DELIVERY_FEE));
    $serviceFee = WABOT_SERVICE_FEE;

    // Coupon discount (re-validate server-side at submission time)
    $couponId = null;
    $couponDiscount = 0;
    $couponCode = $context['coupon_code'] ?? null;
    if ($couponCode && $customerId) {
        $couponResult = wabotValidateCoupon($db, $couponCode, $partnerId, $subtotal, $customerId);
        if ($couponResult['valid']) {
            $couponId = $couponResult['coupon_id'];
            $couponDiscount = $couponResult['discount'];
            if ($couponResult['free_delivery']) {
                $deliveryFee = 0;
            }
        } else {
            error_log(WABOT_LOG_PREFIX . " Coupon {$couponCode} re-validation failed at submit: {$couponResult['message']}");
        }
    }

    // Cashback (validate balance server-side)
    $cashbackDiscount = 0;
    $useCashback = (float)($context['use_cashback'] ?? 0);
    if ($useCashback > 0 && $customerId) {
        $cbBalance = getCustomerCashback($db, $customerId);
        $maxCb = max(0, $subtotal - $couponDiscount);
        $cashbackDiscount = min($useCashback, $cbBalance, $maxCb);
    }

    $total = round(max(0, $subtotal - $couponDiscount - $cashbackDiscount + $deliveryFee + $serviceFee), 2);

    // Validate payment method
    $validPayments = ['pix', 'cartao', 'dinheiro', 'credito', 'debito'];
    if (!in_array($paymentMethod, $validPayments)) {
        $paymentMethod = 'pix'; // Default fallback
    }

    // Map simplified payment names to DB values
    $paymentMethodMap = [
        'pix'      => 'pix',
        'cartao'   => 'credito',
        'credito'  => 'credito',
        'debito'   => 'debito',
        'dinheiro' => 'dinheiro',
    ];
    $dbPaymentMethod = $paymentMethodMap[$paymentMethod] ?? 'pix';

    // ── Customer info ───────────────────────────────────────────────────

    $customerName = $conversation['customer_name'] ?? 'Cliente WhatsApp';
    $customerPhone = $phone;
    $customerEmail = '';

    if ($customerId) {
        $custStmt = $db->prepare("SELECT name, email, phone FROM om_market_customers WHERE customer_id = ?");
        $custStmt->execute([$customerId]);
        $custData = $custStmt->fetch(PDO::FETCH_ASSOC);
        if ($custData) {
            $customerName = $custData['name'] ?: $customerName;
            $customerEmail = $custData['email'] ?? '';
            $customerPhone = $custData['phone'] ?: $phone;
        }
    }

    // ── Create order in transaction ─────────────────────────────────────

    $db->beginTransaction();

    try {
        // Lock stock
        foreach ($validatedItems as $item) {
            $lockStmt = $db->prepare("SELECT quantity FROM om_market_products WHERE product_id = ? FOR UPDATE");
            $lockStmt->execute([$item['product_id']]);
            $currentStock = (int)$lockStmt->fetchColumn();
            if ($currentStock < $item['qty']) {
                $db->rollBack();
                return [
                    'success' => false,
                    'message' => "'{$item['name']}' ficou sem estoque. Tente novamente.",
                ];
            }
        }

        // Generate order number
        $orderNumberTemp = 'WA' . strtoupper(substr(md5(microtime(true) . $phone), 0, 8));
        $codigoEntrega = strtoupper(bin2hex(random_bytes(3)));
        $partnerName = $partner['trade_name'] ?: $partner['name'];
        $deliveryType = $partner['entrega_propria'] ? 'proprio' : 'boraum';

        // Determine timer
        $timerStarted = date('Y-m-d H:i:s');
        $timerMinutes = ($dbPaymentMethod === 'pix') ? 5 : 5;
        $timerExpires = date('Y-m-d H:i:s', strtotime("+{$timerMinutes} minutes"));

        // Determine scheduling
        $isScheduled = !empty($context['scheduled_for']) ? 1 : 0;
        $scheduledDate = null;
        $scheduledTime = null;
        if ($isScheduled) {
            $schedTs = strtotime($context['scheduled_for']);
            if ($schedTs) {
                $scheduledDate = date('Y-m-d', $schedTs);
                $scheduledTime = date('H:i:s', $schedTs);
            } else {
                $isScheduled = 0;
            }
        }

        // Insert order (with coupon, cashback & scheduling columns)
        $orderStmt = $db->prepare("
            INSERT INTO om_market_orders (
                order_number, partner_id, partner_name, customer_id,
                customer_name, customer_phone, customer_email,
                status, subtotal, delivery_fee, service_fee, total,
                delivery_address, shipping_address,
                notes, codigo_entrega, forma_pagamento,
                coupon_id, coupon_discount, cashback_discount,
                is_pickup, delivery_type, partner_categoria,
                timer_started, timer_expires,
                is_scheduled, scheduled_date, scheduled_time,
                source, date_added
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?,
                'pendente', ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                0, ?, ?,
                ?, ?,
                ?, ?, ?,
                'whatsapp_ai', NOW()
            )
            RETURNING order_id
        ");

        $orderStmt->execute([
            $orderNumberTemp, $partnerId, $partnerName, $customerId,
            $customerName, $customerPhone, $customerEmail,
            $subtotal, $deliveryFee, $serviceFee, $total,
            $address, $address,
            $notes, $codigoEntrega, $dbPaymentMethod,
            $couponId ?: null, $couponDiscount, $cashbackDiscount,
            $deliveryType, $partner['categoria'] ?? 'mercado',
            $timerStarted, $timerExpires,
            $isScheduled, $scheduledDate, $scheduledTime,
        ]);

        $orderId = (int)$orderStmt->fetchColumn();

        // Generate pretty order number with ID
        $orderNumber = 'WA' . str_pad($orderId, 5, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE om_market_orders SET order_number = ? WHERE order_id = ?")
            ->execute([$orderNumber, $orderId]);

        // Insert order items
        $itemStmt = $db->prepare("
            INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($validatedItems as $item) {
            $itemStmt->execute([
                $orderId,
                $item['product_id'],
                $item['name'],
                $item['qty'],
                $item['price'],
                $item['total'],
            ]);

            // Decrement stock
            $db->prepare("
                UPDATE om_market_products
                SET quantity = quantity - ?
                WHERE product_id = ? AND quantity >= ?
            ")->execute([$item['qty'], $item['product_id'], $item['qty']]);
        }

        // Add timeline entry
        $timelineDesc = 'Pedido criado via WhatsApp AI';
        if ($isScheduled && $scheduledDate) {
            $timelineDesc .= ' (agendado para ' . date('d/m/Y H:i', strtotime($scheduledDate . ' ' . ($scheduledTime ?? '00:00'))) . ')';
        }
        $db->prepare("
            INSERT INTO om_market_order_timeline (order_id, status, description, created_at)
            VALUES (?, 'pendente', ?, NOW())
        ")->execute([$orderId, $timelineDesc]);

        // Record change_for if paying with cash
        if ($dbPaymentMethod === 'dinheiro' && $paymentChange > 0) {
            $db->prepare("
                UPDATE om_market_orders SET change_for = ? WHERE order_id = ?
            ")->execute([$paymentChange, $orderId]);
        }

        $db->commit();

        // ── Post-commit: coupon usage, cashback debit, notifications & memory ──

        // Record coupon usage (after commit to avoid nested transaction issues)
        if ($couponId && $customerId) {
            try {
                $db->prepare("INSERT INTO om_market_coupon_usage (coupon_id, customer_id, order_id) VALUES (?, ?, ?)")
                    ->execute([$couponId, $customerId, $orderId]);
                $db->prepare("UPDATE om_market_coupons SET uses_count = COALESCE(uses_count, 0) + 1 WHERE id = ?")
                    ->execute([$couponId]);
            } catch (\Exception $e) {
                error_log(WABOT_LOG_PREFIX . " Coupon usage recording error: " . $e->getMessage());
            }
        }

        // Debit cashback from wallet (uses its own transaction internally)
        if ($cashbackDiscount > 0 && $customerId) {
            try {
                debitCashback($db, $customerId, $orderId, $partnerId, $cashbackDiscount);
            } catch (\Exception $e) {
                error_log(WABOT_LOG_PREFIX . " Cashback debit error: " . $e->getMessage());
            }
        }

        // Save new address if flagged
        if ($customerId && !empty($context['is_new_address'])) {
            $addrLat = !empty($context['address_lat']) ? (float)$context['address_lat'] : null;
            $addrLng = !empty($context['address_lng']) ? (float)$context['address_lng'] : null;
            saveCustomerAddress($db, $customerId, $address, $addrLat, $addrLng);
        }

        // Send confirmation WhatsApp to customer
        $totalFmt = number_format($total, 2, ',', '.');
        $confirmMsg = "\xE2\x9C\x85 *Pedido Confirmado!*\n\n"
            . "Numero: *#{$orderNumber}*\n"
            . "Loja: {$partnerName}\n"
            . "Total: *R\$ {$totalFmt}*\n"
            . "Pagamento: " . formatPaymentLabel($paymentMethod) . "\n";

        // Add scheduling info to confirmation
        if ($isScheduled && $scheduledDate) {
            $schedDisplayFmt = date('d/m/Y \\a\\s H:i', strtotime($scheduledDate . ' ' . ($scheduledTime ?? '00:00')));
            $confirmMsg .= "Agendado para: *{$schedDisplayFmt}*\n";
        }

        $confirmMsg .= "\n";

        if ($dbPaymentMethod === 'pix') {
            $confirmMsg .= "Aguarde o link PIX para pagamento.\n\n";
        }
        $confirmMsg .= "Acompanhe pelo app SuperBora.";

        // Delay slightly so AI response sends first
        usleep(300000);
        sendWhatsAppWithRetry($phone, $confirmMsg);

        // Notify partner via WhatsApp
        $partnerPhone = $partner['partner_phone'] ?? '';
        if ($partnerPhone) {
            whatsappNewOrderPartner($partnerPhone, $orderNumber, $total, $customerName);
        }

        // Update conversation status
        $doneContext = $context;
        $doneContext['step'] = STEP_COMPLETED;
        $doneContext['submitted_order_id'] = $orderId;
        $doneContext['submitted_order_number'] = $orderNumber;
        updateConversationContext($db, $conversationId, $doneContext);
        updateConversationStatus($db, $conversationId, 'closed');

        // Broadcast to dashboard
        ccBroadcastDashboard('wa_order_submitted', [
            'conversation_id' => $conversationId,
            'order_id'        => $orderId,
            'order_number'    => $orderNumber,
            'phone'           => $phone,
            'customer_name'   => $customerName,
            'partner_name'    => $partnerName,
            'total'           => $total,
        ]);

        // WebSocket broadcast for order tracking
        callcenterBroadcast("user_{$customerId}", 'new_order', [
            'order_id'     => $orderId,
            'order_number' => $orderNumber,
            'status'       => 'pendente',
        ]);

        // Learn from this order (AI memory)
        try {
            $orderItemsForMemory = array_map(fn($it) => [
                'product_name' => $it['name'],
                'quantity'     => $it['qty'],
            ], $validatedItems);

            aiMemoryLearn($db, $phone, $customerId, [
                'order_id'         => $orderId,
                'partner_id'       => $partnerId,
                'partner_name'     => $partnerName,
                'forma_pagamento'  => $dbPaymentMethod,
                'total'            => $total,
                'delivery_address' => $address,
                'items'            => $orderItemsForMemory,
            ]);
        } catch (\Exception $e) {
            error_log(WABOT_LOG_PREFIX . " Memory learn error: " . $e->getMessage());
        }

        error_log(WABOT_LOG_PREFIX . " Order #{$orderNumber} (ID:{$orderId}) submitted successfully. Total: R\$ {$totalFmt}");

        return [
            'success'      => true,
            'order_id'     => $orderId,
            'order_number' => $orderNumber,
            'total'        => $total,
            'message'      => "Pedido #{$orderNumber} criado com sucesso!",
        ];

    } catch (\Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log(WABOT_LOG_PREFIX . " Order submission error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Erro ao processar o pedido. Tente novamente.',
        ];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// CONTEXT EXTRACTION HELPERS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Try to extract address from the customer message during get_address step.
 * Handles: numeric selection, label-based selection ("casa", "trabalho"),
 * and confirmation of default address ("sim", "isso", "la mesmo").
 */
function tryExtractAddress(string $message, array $context, ?array $addresses): ?string
{
    $msg = trim($message);
    $msgLower = mb_strtolower($msg);

    // Check if user selected a saved address by number
    if (preg_match('/^(\d)$/', $msg, $m) && $addresses) {
        $idx = (int)$m[1] - 1;
        if (isset($addresses[$idx])) {
            $addr = $addresses[$idx];
            return implode(', ', array_filter([
                $addr['street'] ?? '',
                $addr['number'] ?? '',
                $addr['complement'] ?? '',
                $addr['neighborhood'] ?? '',
                $addr['city'] ?? '',
                $addr['state'] ?? '',
            ]));
        }
    }

    // Check if user selected by label name ("casa", "trabalho", etc.)
    if ($addresses) {
        foreach ($addresses as $addr) {
            $label = mb_strtolower($addr['label'] ?? '');
            if (!empty($label) && ($msgLower === $label || strpos($msgLower, $label) !== false)) {
                return implode(', ', array_filter([
                    $addr['street'] ?? '',
                    $addr['number'] ?? '',
                    $addr['complement'] ?? '',
                    $addr['neighborhood'] ?? '',
                    $addr['city'] ?? '',
                    $addr['state'] ?? '',
                ]));
            }
        }
    }

    // Check if user confirmed default address ("sim", "isso", "la mesmo", "pode ser", "esse mesmo")
    if ($addresses) {
        $confirmWords = ['sim', 'isso', 'la mesmo', 'pode ser', 'esse mesmo', 'esse', 'la', 'mesmo lugar', 'mesmo endereco', 's', 'ok', 'beleza'];
        foreach ($confirmWords as $word) {
            if ($msgLower === $word || $msgLower === $word . '!') {
                // Find default address
                foreach ($addresses as $addr) {
                    if ((int)($addr['is_default'] ?? 0) === 1) {
                        return implode(', ', array_filter([
                            $addr['street'] ?? '',
                            $addr['number'] ?? '',
                            $addr['complement'] ?? '',
                            $addr['neighborhood'] ?? '',
                            $addr['city'] ?? '',
                            $addr['state'] ?? '',
                        ]));
                    }
                }
                // If no default, use first address
                $addr = $addresses[0];
                return implode(', ', array_filter([
                    $addr['street'] ?? '',
                    $addr['number'] ?? '',
                    $addr['complement'] ?? '',
                    $addr['neighborhood'] ?? '',
                    $addr['city'] ?? '',
                    $addr['state'] ?? '',
                ]));
            }
        }
    }

    return null;
}

/**
 * Save a new customer address parsed from conversation.
 * Parses the full address string into components using simple regex patterns.
 * Only saves if address doesn't already exist for this customer (by street+number match).
 */
function saveCustomerAddress(PDO $db, int $customerId, string $fullAddress, ?float $lat = null, ?float $lng = null): bool
{
    if (empty(trim($fullAddress)) || $customerId <= 0) {
        return false;
    }

    $address = trim($fullAddress);

    // Parse address components from the full string
    $street = '';
    $number = '';
    $complement = '';
    $neighborhood = '';
    $city = '';
    $state = '';

    // Try to parse: "Rua X, 123, Complemento, Bairro, Cidade - UF"
    // or "Rua X, 123 - Bairro, Cidade"
    // or "Rua X 123 Bairro Cidade"

    // Extract number: look for digits after comma or space that look like a street number
    if (preg_match('/^(.+?)[,\s]+(?:n[uoº.]?\s*)?(\d{1,5}\w?)(.*)$/iu', $address, $m)) {
        $street = trim($m[1]);
        $number = trim($m[2]);
        $rest = trim($m[3], " ,.-");

        // Try to parse the rest: complement, neighborhood, city
        $parts = preg_split('/[,\-]+/', $rest);
        $parts = array_map('trim', array_filter($parts));

        if (count($parts) >= 3) {
            $complement = $parts[0];
            $neighborhood = $parts[1];
            $city = $parts[2];
        } elseif (count($parts) === 2) {
            $neighborhood = $parts[0];
            $city = $parts[1];
        } elseif (count($parts) === 1) {
            $neighborhood = $parts[0];
        }
    } else {
        // Couldn't parse — store as-is in street
        $street = $address;
    }

    // Extract state from city if present (e.g., "Campinas SP" or "Campinas - SP")
    if ($city && preg_match('/^(.+?)\s*[-\/]?\s*([A-Z]{2})$/i', $city, $stm)) {
        $city = trim($stm[1]);
        $state = strtoupper(trim($stm[2]));
    }

    // Clean up: remove common prefixes from street for comparison
    $streetClean = preg_replace('/^(rua|r\.|av\.?|avenida|alameda|al\.|travessa|tv\.)\s*/iu', '', mb_strtolower(trim($street)));

    // Check if this address already exists for this customer (by street match)
    if (!empty($street) && !empty($number)) {
        try {
            $checkStmt = $db->prepare("
                SELECT address_id FROM om_customer_addresses
                WHERE customer_id = ? AND is_active = '1'
                AND LOWER(street) LIKE ? AND number = ?
                LIMIT 1
            ");
            $streetPattern = '%' . mb_strtolower(trim($street)) . '%';
            $checkStmt->execute([$customerId, $streetPattern, $number]);
            if ($checkStmt->fetch()) {
                // Address already exists
                error_log(WABOT_LOG_PREFIX . " Address already exists for customer {$customerId}: {$street}, {$number}");
                return false;
            }
        } catch (\Exception $e) {
            error_log(WABOT_LOG_PREFIX . " Error checking existing address: " . $e->getMessage());
        }
    }

    // Determine if this should be default (only if customer has no addresses)
    $isDefault = 0;
    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM om_customer_addresses WHERE customer_id = ? AND is_active = '1'");
        $countStmt->execute([$customerId]);
        if ((int)$countStmt->fetchColumn() === 0) {
            $isDefault = 1;
        }
    } catch (\Exception $e) {
        // Non-critical
    }

    // Save the address
    try {
        $insertStmt = $db->prepare("
            INSERT INTO om_customer_addresses
                (customer_id, label, zipcode, street, number, complement, neighborhood, city, state, lat, lng, reference, is_default, is_active, created_at)
            VALUES (?, 'WhatsApp', '', ?, ?, ?, ?, ?, ?, ?, ?, '', ?, 1, NOW())
        ");
        $insertStmt->execute([
            $customerId,
            $street ?: $address,
            $number,
            $complement,
            $neighborhood,
            $city,
            $state,
            $lat,
            $lng,
            $isDefault,
        ]);

        error_log(WABOT_LOG_PREFIX . " New address saved for customer {$customerId}: {$street} {$number}, {$neighborhood}, {$city}");
        return true;
    } catch (\Exception $e) {
        error_log(WABOT_LOG_PREFIX . " Error saving address: " . $e->getMessage());
        return false;
    }
}

/**
 * Try to extract payment method from customer message during get_payment step.
 */
function tryExtractPayment(string $message): ?array
{
    $msg = mb_strtolower(trim($message));

    // PIX
    if (preg_match('/\bpix\b/', $msg)) {
        return ['method' => 'pix', 'change' => null];
    }

    // Card
    if (preg_match('/\bcart[aã]o\b|\bcredito\b|\bcrédito\b|\bdebito\b|\bdébito\b|\bcard\b/', $msg)) {
        return ['method' => 'cartao', 'change' => null];
    }

    // Cash
    if (preg_match('/\bdinheiro\b|\bcash\b|\bespecie\b|\bespécie\b/', $msg)) {
        $change = null;
        // Try to extract change amount
        if (preg_match('/troco\s*(?:para|pra|de|:)?\s*(?:R\$?\s*)?(\d+[.,]?\d*)/', $msg, $cm)) {
            $change = (float)str_replace(',', '.', $cm[1]);
        }
        return ['method' => 'dinheiro', 'change' => $change];
    }

    // Numbered selection
    if (preg_match('/^[1]$/', $msg)) return ['method' => 'pix', 'change' => null];
    if (preg_match('/^[2]$/', $msg)) return ['method' => 'cartao', 'change' => null];
    if (preg_match('/^[3]$/', $msg)) return ['method' => 'dinheiro', 'change' => null];

    return null;
}

// ═══════════════════════════════════════════════════════════════════════════
// CORE MESSAGE HANDLER
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Main handler for incoming WhatsApp messages.
 */
function handleWhatsAppMessage(
    PDO    $db,
    string $phone,
    string $message,
    string $messageType = 'text',
    array  $extraData = []
): void {
    $logPhone = substr($phone, -4);

    // ── Load or create conversation ──────────────────────────────────────
    $conversation = loadOrCreateConversation($db, $phone);
    $conversationId = $conversation['id'];
    $customerId = $conversation['customer_id'] ?? null;
    $context = json_decode($conversation['ai_context'] ?? '{}', true) ?: getDefaultContext();

    // Increment message count
    $context['message_count'] = ($context['message_count'] ?? 0) + 1;

    // ── Rate limit check ────────────────────────────────────────────────
    if (!wabotCheckRateLimit($phone)) {
        saveMessage($db, $conversationId, 'inbound', 'customer', $message, $messageType);
        sendWhatsAppResponse($phone, "Voce esta enviando muitas mensagens. Aguarde um momento e tente novamente.");
        return;
    }

    // ── Safeguards: abuse check + context sanitization ──────────────────
    if (function_exists('runSafeguards') && $messageType === 'text') {
        $safeguards = runSafeguards($db, $phone, 'wa_' . $conversationId, $message, $context);
        if (!$safeguards['allowed']) {
            saveMessage($db, $conversationId, 'inbound', 'customer', $message, $messageType);
            $blockMsg = "Desculpe, nao consigo processar essa mensagem. Posso te ajudar com pedidos!";
            saveMessage($db, $conversationId, 'outbound', 'ai', $blockMsg);
            sendWhatsAppResponse($phone, $blockMsg);
            return;
        }
        $context = $safeguards['context'] ?? $context;
    }

    // ── Save inbound message ────────────────────────────────────────────
    $mediaUrl = $extraData['media_url'] ?? null;
    saveMessage($db, $conversationId, 'inbound', 'customer', $message, $messageType, $mediaUrl);
    incrementUnread($db, $conversationId);

    // Broadcast incoming message to dashboard
    ccBroadcastDashboard('wa_message_received', [
        'conversation_id' => $conversationId,
        'phone'           => $phone,
        'customer_name'   => $conversation['customer_name'] ?? 'Cliente',
        'message'         => mb_substr($message, 0, 200),
        'message_type'    => $messageType,
    ]);

    // ── Handle location messages ────────────────────────────────────────
    if ($messageType === 'location') {
        $lat = $extraData['latitude'] ?? null;
        $lng = $extraData['longitude'] ?? null;
        if ($lat && $lng) {
            $context['address_lat'] = (float)$lat;
            $context['address_lng'] = (float)$lng;
            // If in address step, this confirms the location
            if ($context['step'] === STEP_GET_ADDRESS) {
                $context['address'] = $extraData['address'] ?? "Lat: {$lat}, Lng: {$lng}";
                $context['step'] = STEP_GET_PAYMENT;
                updateConversationContext($db, $conversationId, $context);

                $response = "Localizacao recebida! Vou usar esse endereco para a entrega.\n\nAgora, como voce quer pagar?\n\n1. *PIX* — pagamento instantaneo\n2. *Cartao* — link de pagamento\n3. *Dinheiro* — na entrega";
                saveMessage($db, $conversationId, 'outbound', 'ai', $response);
                sendWhatsAppResponse($phone, $response);
                return;
            }
        }
    }

    // ── Handle audio messages ──────────────────────────────────────────
    if ($messageType === 'audio') {
        $audioResponse = handleAudioMessage($context);
        updateConversationContext($db, $conversationId, $context);
        saveMessage($db, $conversationId, 'outbound', 'ai', $audioResponse);
        sendWhatsAppResponse($phone, $audioResponse);
        return;
    }

    // ── Check if conversation is assigned to human agent ─────────────────
    if (($conversation['status'] ?? '') === 'with_agent' && !empty($conversation['agent_id'])) {
        // Don't process with AI — human agent is handling
        ccBroadcastAgent((int)$conversation['agent_id'], 'wa_customer_message', [
            'conversation_id' => $conversationId,
            'phone'           => $phone,
            'message'         => $message,
        ]);
        return;
    }

    // ── Quick commands (pre-Claude shortcuts) ───────────────────────────
    $quickResult = handleQuickCommand($db, $message, $conversation, $context);
    if ($quickResult !== null) {
        $response = $quickResult['response'];
        $context = $quickResult['context'];
        updateConversationContext($db, $conversationId, $context);
        saveMessage($db, $conversationId, 'outbound', 'ai', $response);
        sendWhatsAppResponse($phone, $response);
        return;
    }

    // ── Rating response handling (before Claude) ────────────────────────
    // If there's a pending rating request, try to process the response
    if (!empty($context['rating_requested_for_order']) && $customerId) {
        $ratingResult = processRatingResponse($db, $phone, $customerId, $message, $context);
        if ($ratingResult !== null) {
            $response = $ratingResult['response'];
            if (!empty($ratingResult['clear_rating_context'])) {
                $context['rating_requested_for_order'] = null;
                $context['rating_pending_value'] = null;
            }
            updateConversationContext($db, $conversationId, $context);
            saveMessage($db, $conversationId, 'outbound', 'ai', $response);
            sendWhatsAppResponse($phone, $response);
            return;
        }
    }

    // ── Proactive rating request for recently delivered orders ───────────
    // On first message in a new/reset session, check if customer has unrated orders
    if ($customerId && ($context['message_count'] ?? 0) <= 1
        && empty($context['rating_requested_for_order'])
        && in_array($context['step'], [STEP_GREETING, STEP_COMPLETED])) {
        $unratedOrder = checkAndSendRatingRequest($db, $phone, $customerId);
        if ($unratedOrder) {
            $context['rating_requested_for_order'] = (int)$unratedOrder['order_id'];
            $context['rating_pending_value'] = null;
            updateConversationContext($db, $conversationId, $context);
            // Don't return — let the greeting also proceed to Claude
            // The rating request was sent as a separate message
        }
    }

    // ── Pre-Claude context extraction ───────────────────────────────────

    // Try to extract address if in address step
    if ($context['step'] === STEP_GET_ADDRESS) {
        $addresses = $customerId ? getCustomerAddresses($db, $customerId) : [];
        $extracted = tryExtractAddress($message, $context, $addresses);
        if ($extracted) {
            $context['address'] = $extracted;
        } else {
            // If message looks like an address (long text with numbers), save it
            if (mb_strlen($message) > 10 && preg_match('/\d/', $message)) {
                $context['address'] = trim($message);
            }
        }
    }

    // Try to extract payment if in payment step
    if ($context['step'] === STEP_GET_PAYMENT) {
        $paymentData = tryExtractPayment($message);
        if ($paymentData) {
            $context['payment_method'] = $paymentData['method'];
            if ($paymentData['change'] !== null) {
                $context['payment_change'] = $paymentData['change'];
            }
        }
    }

    // ── Track price sensitivity and store memory ──────────────────────
    $msgLower = mb_strtolower($message);
    if (preg_match('/preco|pre[cç]o|barato|promo[cç]|desconto|quanto custa|mais em conta|oferta/ui', $msgLower)) {
        $context['price_sensitive'] = true;
    }
    // Remember last mentioned store even if customer changes subject
    if (!empty($context['partner_name']) && empty($context['last_mentioned_store'])) {
        $context['last_mentioned_store'] = $context['partner_name'];
    }

    // ── Build Claude context ────────────────────────────────────────────

    $step = $context['step'] ?? STEP_GREETING;
    $customerInfo = $customerId ? lookupCustomerByPhone($db, $phone) : null;
    $memoryContext = aiMemoryBuildContext($db, $phone, $customerId);

    // Get menu if in ordering steps
    $menuText = null;
    if (in_array($step, [STEP_TAKE_ORDER]) && $context['partner_id']) {
        $menuText = fetchStoreMenu($db, (int)$context['partner_id']);
    }

    // Get stores list for greeting/identification steps
    $stores = null;
    if (in_array($step, [STEP_GREETING, STEP_IDENTIFY_STORE])) {
        $stores = getActiveStores($db, 15);
    }

    // Get saved addresses for address step
    $addresses = null;
    if ($step === STEP_GET_ADDRESS && $customerId) {
        $addresses = getCustomerAddresses($db, $customerId);
    }

    // Get active orders (for status queries, cancellations, and context)
    $activeOrders = null;
    if ($customerId) {
        $activeOrders = getCustomerActiveOrders($db, $customerId);
    }

    // Get recent orders (for reorder suggestions and context)
    $recentOrders = null;
    if ($customerId) {
        $recentOrders = getCustomerRecentOrders($db, $customerId, 5);
    }

    // Get popular items and complementary suggestions for upselling
    $popularItems = null;
    $complementaryItems = null;
    if (!empty($context['partner_id'])) {
        $partnerId = (int)$context['partner_id'];

        // Load popular items for STEP_TAKE_ORDER and STEP_CONFIRM_ORDER
        if (in_array($step, [STEP_TAKE_ORDER, STEP_CONFIRM_ORDER])) {
            $popularItems = getPopularItems($db, $partnerId);
        }

        // Load complementary suggestions when cart has items in STEP_TAKE_ORDER
        if ($step === STEP_TAKE_ORDER && !empty($context['items'])) {
            $complementaryItems = getComplementaryItems($db, $partnerId, $context['items']);
        }
    }

    // ── Smart search: find products/stores matching the customer's message ──
    $searchContext = '';
    if (in_array($step, [STEP_GREETING, STEP_IDENTIFY_STORE]) && mb_strlen($message) >= 3) {
        try {
            $storeSearchResults = searchStores($db, $message, 5);
            $productSearchResults = searchProducts($db, $message, null, 8);
            $searchContext = formatSearchResultsForPrompt($productSearchResults, $storeSearchResults);
        } catch (\Exception $e) {
            error_log(WABOT_LOG_PREFIX . " Search error: " . $e->getMessage());
        }
    }

    // ── Time context (Sao Paulo timezone) ────────────────────────────────
    $timeCtx = getTimeContext();

    // Update context in conversation before Claude call
    $conversation['ai_context'] = json_encode($context, JSON_UNESCAPED_UNICODE);

    $systemPrompt = buildSystemPrompt(
        $db,
        $conversation,
        $step,
        $customerInfo,
        $memoryContext,
        $menuText,
        $stores,
        $addresses,
        $activeOrders,
        $recentOrders,
        $popularItems,
        $complementaryItems,
        $searchContext,
        $timeCtx
    );

    // ── Build message history for Claude ────────────────────────────────

    // Load recent messages for context (last 20)
    $historyStmt = $db->prepare("
        SELECT direction, sender_type, message, message_type
        FROM om_callcenter_wa_messages
        WHERE conversation_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $historyStmt->execute([$conversationId]);
    $historyRows = array_reverse($historyStmt->fetchAll(PDO::FETCH_ASSOC));

    $claudeMessages = [];
    foreach ($historyRows as $row) {
        $role = ($row['direction'] === 'inbound') ? 'user' : 'assistant';
        $content = $row['message'] ?? '';
        if (empty(trim($content))) continue;

        // Don't duplicate the current message (already at the end)
        $claudeMessages[] = [
            'role'    => $role,
            'content' => $content,
        ];
    }

    // Ensure last message is from user (it should be — the current message)
    if (empty($claudeMessages) || end($claudeMessages)['role'] !== 'user') {
        $claudeMessages[] = [
            'role'    => 'user',
            'content' => $message,
        ];
    }

    // Merge consecutive same-role messages (Claude API requirement)
    $mergedMessages = [];
    foreach ($claudeMessages as $msg) {
        if (!empty($mergedMessages) && end($mergedMessages)['role'] === $msg['role']) {
            $mergedMessages[count($mergedMessages) - 1]['content'] .= "\n" . $msg['content'];
        } else {
            $mergedMessages[] = $msg;
        }
    }

    // Ensure conversation starts with user message
    if (!empty($mergedMessages) && $mergedMessages[0]['role'] !== 'user') {
        array_shift($mergedMessages);
    }

    // Cap history to avoid token limits
    if (count($mergedMessages) > 16) {
        $mergedMessages = array_slice($mergedMessages, -16);
        // Re-ensure starts with user
        if (!empty($mergedMessages) && $mergedMessages[0]['role'] !== 'user') {
            array_shift($mergedMessages);
        }
    }

    // ── Call Claude AI ──────────────────────────────────────────────────

    $aiResponse = '';

    try {
        if (class_exists('ClaudeClient')) {
            $claude = new \ClaudeClient(WABOT_CLAUDE_MODEL, WABOT_CLAUDE_TIMEOUT, 0);
            $result = $claude->send($systemPrompt, $mergedMessages);

            if (is_array($result)) {
                // ClaudeClient might return structured data
                $aiResponse = $result['content'] ?? $result['text'] ?? $result['message'] ?? '';
                if (is_array($aiResponse)) {
                    // Content blocks
                    $textParts = [];
                    foreach ($aiResponse as $block) {
                        if (is_array($block) && ($block['type'] ?? '') === 'text') {
                            $textParts[] = $block['text'];
                        } elseif (is_string($block)) {
                            $textParts[] = $block;
                        }
                    }
                    $aiResponse = implode("\n", $textParts);
                }
            } elseif (is_string($result)) {
                $aiResponse = $result;
            }
        } else {
            // Fallback: direct Anthropic API call
            $aiResponse = callClaudeDirectly($systemPrompt, $mergedMessages);
        }
    } catch (\Exception $e) {
        error_log(WABOT_LOG_PREFIX . " Claude API error: " . $e->getMessage());

        // Use smart fallback if available
        if (function_exists('handleDegradedMode')) {
            $aiResponse = handleDegradedMode('claude', $e->getMessage(), [
                'step'    => $context['step'] ?? STEP_GREETING,
                'channel' => 'whatsapp',
                'items'   => $context['items'] ?? [],
            ]);
        } else {
            $aiResponse = "Desculpe, estou com dificuldades tecnicas no momento. Tente novamente em instantes.";
        }

        saveMessage($db, $conversationId, 'outbound', 'ai', $aiResponse);
        sendWhatsAppResponse($phone, $aiResponse);
        return;
    }

    if (empty(trim($aiResponse))) {
        $aiResponse = "Desculpe, nao entendi. Pode repetir?";
    }

    // ── Validate AI response via safeguards ─────────────────────────────
    if (function_exists('validateAiResponse')) {
        $validation = validateAiResponse($aiResponse, [
            'step'    => $context['step'] ?? STEP_GREETING,
            'channel' => 'whatsapp',
            'items'   => $context['items'] ?? [],
        ]);
        if (!$validation['valid']) {
            error_log(WABOT_LOG_PREFIX . " AI response validation issues: " . implode(', ', $validation['issues']));
            $aiResponse = $validation['cleaned'] ?? $aiResponse;
        }
    }

    // ── Process AI response (parse markers) ─────────────────────────────

    $processed = processAiResponse($db, $conversation, $aiResponse, $phone);
    $responseText = $processed['response'];
    $context = $processed['context'];
    $markers = $processed['markers'];

    // ── Handle submit_order step ────────────────────────────────────────

    if ($context['step'] === STEP_SUBMIT_ORDER) {
        // Update context before submission
        updateConversationContext($db, $conversationId, $context);

        $submitResult = submitWhatsAppOrder($db, array_merge($conversation, [
            'ai_context' => json_encode($context, JSON_UNESCAPED_UNICODE),
        ]));

        if ($submitResult['success']) {
            $orderNumber = $submitResult['order_number'];
            $total = number_format($submitResult['total'], 2, ',', '.');

            $successMsg = "Pedido *#{$orderNumber}* enviado com sucesso! \xF0\x9F\x8E\x89\n\n"
                . "Total: *R\$ {$total}*\n";

            // Add scheduling info to success message
            if (!empty($context['scheduled_for'])) {
                $schedSuccessFmt = date('d/m/Y \\a\\s H:i', strtotime($context['scheduled_for']));
                $successMsg .= "Agendado para: *{$schedSuccessFmt}*\n";
            }

            $successMsg .= "\nVoce recebera atualizacoes aqui no WhatsApp.\n"
                . "Obrigada por escolher a SuperBora!";

            // Send the AI response first (confirmation text), then the success message
            if (!empty($responseText) && $responseText !== $successMsg) {
                saveMessage($db, $conversationId, 'outbound', 'ai', $responseText);
                sendWhatsAppResponse($phone, $responseText);
                usleep(500000);
            }
            saveMessage($db, $conversationId, 'outbound', 'ai', $successMsg);
            sendWhatsAppResponse($phone, $successMsg);

            // Reset context for next conversation
            $context = getDefaultContext();
            $context['step'] = STEP_COMPLETED;
        } else {
            $errorMsg = $submitResult['message'] ?? 'Erro ao processar pedido.';
            $failMsg = "Nao consegui finalizar o pedido: {$errorMsg}\n\nQuer tentar novamente?";
            $context['step'] = STEP_CONFIRM_ORDER; // Go back to confirmation

            saveMessage($db, $conversationId, 'outbound', 'ai', $failMsg);
            sendWhatsAppResponse($phone, $failMsg);
        }

        updateConversationContext($db, $conversationId, $context);
        return;
    }

    // ── Save and send response ──────────────────────────────────────────

    updateConversationContext($db, $conversationId, $context);
    saveMessage($db, $conversationId, 'outbound', 'ai', $responseText);
    sendWhatsAppResponse($phone, $responseText);

    // Broadcast AI response to dashboard
    ccBroadcastDashboard('wa_ai_response', [
        'conversation_id' => $conversationId,
        'phone'           => $phone,
        'step'            => $context['step'],
        'markers'         => $markers,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
// DIRECT CLAUDE API FALLBACK
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Call Claude API directly if ClaudeClient class is not available.
 */
function callClaudeDirectly(string $systemPrompt, array $messages): string
{
    $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? $_ENV['CLAUDE_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '';
    if (empty($apiKey)) {
        error_log(WABOT_LOG_PREFIX . " No Anthropic API key configured");
        return "Servico de IA temporariamente indisponivel.";
    }

    $payload = json_encode([
        'model'      => WABOT_CLAUDE_MODEL,
        'max_tokens' => 1024,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => WABOT_CLAUDE_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log(WABOT_LOG_PREFIX . " Claude API cURL error: {$error}");
        return "Erro de conexao com o assistente. Tente novamente.";
    }

    if ($httpCode !== 200) {
        error_log(WABOT_LOG_PREFIX . " Claude API HTTP {$httpCode}: " . mb_substr($result, 0, 500));
        return "Erro temporario no assistente. Tente novamente em instantes.";
    }

    $data = json_decode($result, true);
    if (!$data || empty($data['content'])) {
        error_log(WABOT_LOG_PREFIX . " Claude API empty response");
        return "Nao consegui gerar uma resposta. Tente novamente.";
    }

    // Extract text from content blocks
    $textParts = [];
    foreach ($data['content'] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $textParts[] = $block['text'];
        }
    }

    return implode("\n", $textParts);
}

// ═══════════════════════════════════════════════════════════════════════════
// WEBHOOK ENTRY POINT
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Parse and validate the incoming Z-API webhook request.
 */
function parseWebhookRequest(): ?array
{
    // Only accept POST
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return null;
    }

    $rawBody = file_get_contents('php://input');
    if (empty($rawBody)) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty body']);
        return null;
    }

    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return null;
    }

    // Z-API sends various webhook types. We only care about incoming messages.

    // Ignore outgoing messages (fromMe)
    if (!empty($body['fromMe'])) {
        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'reason' => 'outgoing']);
        return null;
    }

    // Ignore group messages
    if (!empty($body['isGroup'])) {
        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'reason' => 'group']);
        return null;
    }

    // Ignore status/delivery receipts
    if (isset($body['status']) && !isset($body['text']) && !isset($body['buttonsResponseMessage']) && !isset($body['listResponseMessage']) && !isset($body['locationMessage'])) {
        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'reason' => 'status_webhook']);
        return null;
    }

    // Extract phone number
    $phone = $body['phone'] ?? $body['from'] ?? '';
    $phone = preg_replace('/\D/', '', $phone);
    if (empty($phone) || strlen($phone) < 10) {
        error_log(WABOT_LOG_PREFIX . " Invalid phone in webhook: " . ($body['phone'] ?? 'none'));
        http_response_code(400);
        echo json_encode(['error' => 'Invalid phone']);
        return null;
    }

    // Determine message type and content
    $message = '';
    $messageType = 'text';
    $extraData = [];

    // Text message
    if (isset($body['text']['message'])) {
        $message = trim($body['text']['message']);
        $messageType = 'text';
    }
    // Button response
    elseif (isset($body['buttonsResponseMessage'])) {
        $btnResponse = $body['buttonsResponseMessage'];
        $message = $btnResponse['selectedButtonId'] ?? $btnResponse['selectedDisplayText'] ?? '';
        $messageType = 'button';
        $extraData['button_id'] = $btnResponse['selectedButtonId'] ?? '';
        $extraData['button_text'] = $btnResponse['selectedDisplayText'] ?? '';
    }
    // List response
    elseif (isset($body['listResponseMessage'])) {
        $listResponse = $body['listResponseMessage'];
        $message = $listResponse['singleSelectReply']['selectedRowId'] ?? $listResponse['title'] ?? '';
        $messageType = 'list';
        $extraData['list_row_id'] = $listResponse['singleSelectReply']['selectedRowId'] ?? '';
        $extraData['list_title'] = $listResponse['title'] ?? '';
        $extraData['list_description'] = $listResponse['description'] ?? '';
    }
    // Location message
    elseif (isset($body['locationMessage'])) {
        $loc = $body['locationMessage'];
        $lat = $loc['degreesLatitude'] ?? $loc['latitude'] ?? null;
        $lng = $loc['degreesLongitude'] ?? $loc['longitude'] ?? null;
        $addr = $loc['address'] ?? $loc['name'] ?? '';
        $message = $addr ?: "Localizacao: {$lat}, {$lng}";
        $messageType = 'location';
        $extraData['latitude'] = $lat;
        $extraData['longitude'] = $lng;
        $extraData['address'] = $addr;
    }
    // Image/media message
    elseif (isset($body['image'])) {
        $message = $body['image']['caption'] ?? 'Imagem recebida';
        $messageType = 'image';
        $extraData['media_url'] = $body['image']['imageUrl'] ?? $body['image']['url'] ?? '';
    }
    // Audio message
    elseif (isset($body['audio'])) {
        $message = 'Audio recebido';
        $messageType = 'audio';
        $extraData['media_url'] = $body['audio']['audioUrl'] ?? $body['audio']['url'] ?? '';
    }
    // Document
    elseif (isset($body['document'])) {
        $message = $body['document']['caption'] ?? 'Documento recebido';
        $messageType = 'document';
        $extraData['media_url'] = $body['document']['documentUrl'] ?? $body['document']['url'] ?? '';
    }
    // Fallback: try to find message in common fields
    else {
        $message = $body['body'] ?? $body['message'] ?? $body['text'] ?? '';
        if (is_array($message)) {
            $message = $message['message'] ?? $message['body'] ?? json_encode($message);
        }
        if (empty($message)) {
            // Some webhook types we don't handle (stickers, reactions, etc.)
            http_response_code(200);
            echo json_encode(['status' => 'ignored', 'reason' => 'unsupported_type']);
            return null;
        }
    }

    if (empty(trim($message))) {
        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'reason' => 'empty_message']);
        return null;
    }

    return [
        'phone'       => $phone,
        'message'     => $message,
        'messageType' => $messageType,
        'extraData'   => $extraData,
        'rawBody'     => $body,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// MAIN EXECUTION
// ═══════════════════════════════════════════════════════════════════════════

// Set response headers
header('Content-Type: application/json; charset=utf-8');

// Parse webhook request
$parsed = parseWebhookRequest();
if ($parsed === null) {
    exit;
}

$phone = $parsed['phone'];
$message = $parsed['message'];
$messageType = $parsed['messageType'];
$extraData = $parsed['extraData'];
$logSuffix = substr($phone, -4);

error_log(WABOT_LOG_PREFIX . " Incoming {$messageType} from ...{$logSuffix}: " . mb_substr($message, 0, 100));

// Get database connection
try {
    $db = getDB();
} catch (\Exception $e) {
    error_log(WABOT_LOG_PREFIX . " Database connection failed: " . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

// Track this interaction in AI memory
try {
    aiMemoryTrackCall($db, $phone);
} catch (\Exception $e) {
    // Non-critical — don't block message handling
    error_log(WABOT_LOG_PREFIX . " Memory tracking error: " . $e->getMessage());
}

// Handle the message
try {
    handleWhatsAppMessage($db, $phone, $message, $messageType, $extraData);
} catch (\Exception $e) {
    error_log(WABOT_LOG_PREFIX . " FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString());

    // Try to send error response to user
    try {
        sendWhatsAppWithRetry($phone, "Desculpe, ocorreu um erro inesperado. Tente novamente em instantes.");
    } catch (\Exception $e2) {
        error_log(WABOT_LOG_PREFIX . " Failed to send error message: " . $e2->getMessage());
    }
}

// Always return 200 to Z-API to prevent retries
http_response_code(200);
echo json_encode([
    'status'  => 'processed',
    'phone'   => substr($phone, 0, 4) . '****' . substr($phone, -2),
    'type'    => $messageType,
]);
