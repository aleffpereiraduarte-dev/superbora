<?php
/**
 * GET /api/mercado/intelligence/cart-suggestions.php?cart_items=1,2,3&partner_id=X
 * AI-powered complementary product suggestions for cart.
 *
 * Analyzes cart contents and suggests items the customer might be missing:
 *   - Missing accompaniments (burger → fries, drink)
 *   - Popular pairings from the same store
 *   - Dessert if no dessert in cart
 *   - Drink if no drink in cart
 *
 * Returns 4-6 suggestions with per-item reasoning in Portuguese.
 * Redis cached 30 minutes keyed on sorted cart product IDs + partner.
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/claude-client.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { response(false, null, 'Method not allowed', 405); }

try {
    $cartItemsRaw = trim($_GET['cart_items'] ?? '');
    $partnerId = (int)($_GET['partner_id'] ?? 0);

    if (empty($cartItemsRaw) || $partnerId <= 0) {
        response(false, null, 'cart_items and partner_id required', 400);
    }

    // Parse and validate cart product IDs
    $cartProductIds = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $cartItemsRaw)),
        fn($id) => $id > 0
    )));

    if (empty($cartProductIds)) {
        response(false, null, 'No valid cart_items provided', 400);
    }

    $db = getDB();

    // Cache key: sorted IDs + partner for deterministic key
    $sortedIds = $cartProductIds;
    sort($sortedIds);
    $cacheKey = "cart_complement:" . md5($partnerId . ':' . implode(',', $sortedIds));

    // Try Redis cache (30 min TTL)
    $redis = null;
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
        $redisPw = $_ENV['REDIS_PASSWORD'] ?? '';
        if ($redisPw) $redis->auth($redisPw);
        $cached = $redis->get($cacheKey);
        if ($cached) {
            $data = json_decode($cached, true);
            if ($data && !empty($data['suggestions'])) {
                response(true, array_merge($data, ['source' => 'cache']));
            }
        }
    } catch (Exception $e) {
        $redis = null;
    }

    // 1. Fetch cart items with their names + categories
    $placeholders = implode(',', array_fill(0, count($cartProductIds), '?'));
    $stmt = $db->prepare("
        SELECT p.product_id, p.name, p.price, p.image,
               c.name as category_name, c.category_id
        FROM om_market_products p
        LEFT JOIN om_market_categories c ON c.category_id = p.category_id
        WHERE p.product_id IN ($placeholders)
    ");
    $stmt->execute($cartProductIds);
    $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cartItems)) {
        response(true, ['suggestions' => [], 'section_title' => null]);
    }

    // 2. Fetch candidate products from same partner (not in cart), with popularity
    $stmt = $db->prepare("
        SELECT p.product_id, p.name, p.price, p.special_price as sale_price,
               p.image, p.unit, p.description,
               c.name as category_name, c.category_id,
               COALESCE(stats.order_count, 0) as sales_count
        FROM om_market_products p
        LEFT JOIN om_market_categories c ON c.category_id = p.category_id
        LEFT JOIN (
            SELECT oi.product_id, COUNT(DISTINCT oi.order_id) as order_count
            FROM om_market_order_items oi
            JOIN om_market_orders o ON o.order_id = oi.order_id
            WHERE o.date_added > NOW() - INTERVAL '60 days'
              AND o.status NOT IN ('cancelado', 'recusado')
            GROUP BY oi.product_id
        ) stats ON stats.product_id = p.product_id
        WHERE p.partner_id = ?
          AND p.status = 1
          AND p.product_id NOT IN ($placeholders)
          AND p.name IS NOT NULL AND TRIM(p.name) != ''
          AND p.price > 0
          AND p.image IS NOT NULL AND TRIM(p.image) != ''
        ORDER BY stats.order_count DESC NULLS LAST, p.product_id DESC
        LIMIT 30
    ");
    $params = array_merge([$partnerId], $cartProductIds);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($candidates)) {
        $result = ['suggestions' => [], 'section_title' => null];
        cacheCartSuggestions($redis, $cacheKey, $result);
        response(true, $result);
    }

    // 3. Check co-purchase frequency (items frequently bought together with cart items)
    $coPurchaseBoost = [];
    try {
        $stmt = $db->prepare("
            SELECT oi2.product_id, COUNT(DISTINCT oi2.order_id) as co_count
            FROM om_market_order_items oi1
            JOIN om_market_order_items oi2 ON oi2.order_id = oi1.order_id AND oi2.product_id != oi1.product_id
            JOIN om_market_orders o ON o.order_id = oi1.order_id
            WHERE oi1.product_id IN ($placeholders)
              AND o.date_added > NOW() - INTERVAL '90 days'
              AND o.status NOT IN ('cancelado', 'recusado')
              AND oi2.product_id NOT IN ($placeholders)
            GROUP BY oi2.product_id
            ORDER BY co_count DESC
            LIMIT 15
        ");
        $coParams = array_merge($cartProductIds, $cartProductIds);
        $stmt->execute($coParams);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $coPurchaseBoost[(int)$row['product_id']] = (int)$row['co_count'];
        }
    } catch (Exception $e) {
        error_log("[cart-suggestions] Co-purchase query error: " . $e->getMessage());
    }

    // 4. Ask Claude to select + provide reasoning
    $suggestions = claudeComplementSuggest($cartItems, $candidates, $coPurchaseBoost);

    // 5. Fallback: top 5 by popularity if Claude fails
    if (empty($suggestions)) {
        $suggestions = array_slice(array_map(fn($p) => [
            'product_id' => (int)$p['product_id'],
            'name' => $p['name'],
            'image' => $p['image'],
            'price' => round(floatval($p['price']), 2),
            'sale_price' => $p['sale_price'] ? round(floatval($p['sale_price']), 2) : null,
            'reason' => 'Popular nesta loja',
        ], $candidates), 0, 5);
    }

    // 6. Determine section title based on what's missing
    $sectionTitle = determineSectionTitle($cartItems, $suggestions);

    $result = [
        'suggestions' => $suggestions,
        'section_title' => $sectionTitle,
    ];

    cacheCartSuggestions($redis, $cacheKey, $result);
    response(true, $result);

} catch (Exception $e) {
    error_log("[cart-suggestions] Error: " . $e->getMessage());
    // Graceful degradation: return empty suggestions, never fail the page
    response(true, ['suggestions' => [], 'section_title' => null]);
}

// ──────────────────────────────────────────────────────────────────────────────

function claudeComplementSuggest(array $cartItems, array $candidates, array $coPurchaseBoost): array {
    try {
        $claude = new ClaudeClient('claude-haiku-4-5-20251001', 15, 0);

        $cartDesc = [];
        foreach ($cartItems as $item) {
            $desc = $item['name'];
            if (!empty($item['category_name'])) {
                $desc .= " ({$item['category_name']})";
            }
            $cartDesc[] = $desc;
        }

        // Build candidate list with co-purchase signal
        $candidateList = [];
        foreach ($candidates as $i => $p) {
            $entry = [
                'i' => $i,
                'n' => $p['name'],
                'p' => round(floatval($p['price']), 2),
                'cat' => $p['category_name'] ?? '',
            ];
            $pid = (int)$p['product_id'];
            if (isset($coPurchaseBoost[$pid]) && $coPurchaseBoost[$pid] > 2) {
                $entry['co'] = $coPurchaseBoost[$pid]; // frequently bought together signal
            }
            $candidateList[] = $entry;
        }

        $system = "Voce e assistente de compras do SuperBora, um app de delivery de mercado brasileiro. "
            . "Analise o carrinho do cliente e sugira itens complementares que ele pode ter esquecido. "
            . "Responda APENAS com JSON valido, sem explicacoes extras.";

        $userMsg = "O carrinho do cliente tem:\n"
            . implode("\n", array_map(fn($d) => "- {$d}", $cartDesc))
            . "\n\nSugira 4 a 6 itens complementares da lista abaixo. Priorize:\n"
            . "1) Acompanhamentos que faltam (ex: carne sem tempero, massa sem molho, cafe da manha sem pao)\n"
            . "2) Bebida se nao tem bebida no carrinho\n"
            . "3) Sobremesa se nao tem sobremesa\n"
            . "4) Itens com campo 'co' alto (comprados juntos frequentemente)\n"
            . "5) Variedade de categorias\n\n"
            . "Retorne JSON array de objetos com:\n"
            . "- \"i\": indice do produto na lista\n"
            . "- \"reason\": frase curta em PT-BR explicando por que adicionar (max 40 chars). "
            . "Use frases como 'Que tal uma bebida?', 'Combina com o churrasco', 'Para o cafe da manha', etc.\n\n"
            . "Exemplo: [{\"i\":0,\"reason\":\"Perfeito para acompanhar\"},{\"i\":5,\"reason\":\"Que tal uma sobremesa?\"}]\n\n"
            . "Produtos disponiveis:\n" . json_encode($candidateList, JSON_UNESCAPED_UNICODE);

        $result = $claude->send($system, [['role' => 'user', 'content' => $userMsg]], 512);

        if ($result['success'] && !empty($result['text'])) {
            $parsed = ClaudeClient::parseJson($result['text']);
            if (is_array($parsed) && count($parsed) >= 2) {
                $suggestions = [];
                foreach ($parsed as $item) {
                    if (!is_array($item) || !isset($item['i'])) continue;
                    $idx = intval($item['i']);
                    if (!isset($candidates[$idx])) continue;

                    $p = $candidates[$idx];
                    $reason = trim($item['reason'] ?? '');
                    if (empty($reason)) $reason = 'Combina com seu pedido';
                    // Truncate to 50 chars for safety
                    if (mb_strlen($reason) > 50) {
                        $reason = mb_substr($reason, 0, 47) . '...';
                    }

                    $suggestions[] = [
                        'product_id' => (int)$p['product_id'],
                        'name' => $p['name'],
                        'image' => $p['image'],
                        'price' => round(floatval($p['price']), 2),
                        'sale_price' => $p['sale_price'] ? round(floatval($p['sale_price']), 2) : null,
                        'reason' => $reason,
                    ];
                }
                if (count($suggestions) >= 2) return $suggestions;
            }
        }
    } catch (Exception $e) {
        error_log("[cart-suggestions] Claude error: " . $e->getMessage());
    }
    return [];
}

function determineSectionTitle(array $cartItems, array $suggestions): string {
    // Check categories in cart
    $cartCategories = array_filter(array_map(fn($i) => strtolower($i['category_name'] ?? ''), $cartItems));

    $hasDrink = false;
    $hasDessert = false;
    foreach ($cartCategories as $cat) {
        if (preg_match('/bebida|refrigerante|suco|agua|cerveja|vinho|drink/i', $cat)) $hasDrink = true;
        if (preg_match('/doce|sobremesa|chocolate|sorvete|bolo/i', $cat)) $hasDessert = true;
    }

    if (!$hasDrink && !$hasDessert) return 'Complementar pedido';
    if (!$hasDrink) return 'Falta uma bebida?';
    if (!$hasDessert) return 'Uma sobremesa para fechar?';

    return 'Complementar pedido';
}

function cacheCartSuggestions($redis, string $key, array $data): void {
    try {
        if ($redis) {
            $redis->setex($key, 1800, json_encode($data, JSON_UNESCAPED_UNICODE)); // 30 min
        }
    } catch (Exception $e) {
        error_log("[cart-suggestions] Cache write error: " . $e->getMessage());
    }
}
