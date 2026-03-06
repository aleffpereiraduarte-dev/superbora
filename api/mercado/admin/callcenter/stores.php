<?php
/**
 * /api/mercado/admin/callcenter/stores.php
 *
 * Store & product lookup for call center agents.
 *
 * GET ?q=name: Search stores by name.
 * GET ?partner_id=X: Full product catalog for a store (structured by categories).
 * GET ?partner_id=X&q=search: Search products within a store.
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    $q = trim($_GET['q'] ?? '');

    // ── Search stores by name ──
    if (!$partnerId) {
        if (!$q) {
            response(false, null, "Informe 'q' para buscar lojas ou 'partner_id' para catalogo", 400);
        }

        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $q);
        $like = '%' . $escaped . '%';
        $city = trim($_GET['city'] ?? '');

        $sql = "
            SELECT
                p.partner_id AS id,
                p.name,
                p.address,
                p.city,
                COALESCE(p.rating, 0) AS rating,
                COALESCE(p.delivery_fee, 0) AS delivery_fee,
                p.logo AS logo_url,
                p.phone,
                p.status,
                p.horario_funcionamento,
                p.opens_at,
                p.closes_at,
                p.delivery_time_min,
                p.delivery_time_max
            FROM om_market_partners p
            WHERE (p.name ILIKE ? OR p.city ILIKE ?)
        ";
        $params = [$like, $like];

        if ($city) {
            $cityEscaped = str_replace(['%', '_'], ['\\%', '\\_'], $city);
            $sql .= " AND p.city ILIKE ?";
            $params[] = '%' . $cityEscaped . '%';
        }

        $sql .= "
            ORDER BY
                CASE WHEN p.status = '1' THEN 0 ELSE 1 END,
                p.rating DESC NULLS LAST,
                p.name ASC
            LIMIT 20
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $stores = $stmt->fetchAll();

        // Determine open/closed status based on horarios
        $now = date('H:i');
        $dayOfWeek = strtolower(date('l'));
        $dayMap = [
            'monday' => 'seg', 'tuesday' => 'ter', 'wednesday' => 'qua',
            'thursday' => 'qui', 'friday' => 'sex', 'saturday' => 'sab', 'sunday' => 'dom'
        ];
        $currentDay = $dayMap[$dayOfWeek] ?? '';

        foreach ($stores as &$store) {
            $store['id'] = (int)$store['id'];
            $store['rating'] = round((float)$store['rating'], 1);
            $store['delivery_fee'] = (float)$store['delivery_fee'];

            // Determine open/closed status
            $store['is_open'] = false;
            if ($store['status'] === '1') {
                // Try horario_funcionamento (text/JSON) first
                if (!empty($store['horario_funcionamento'])) {
                    $horarios = json_decode($store['horario_funcionamento'], true);
                    if (is_array($horarios) && isset($horarios[$currentDay])) {
                        $daySchedule = $horarios[$currentDay];
                        if (is_array($daySchedule) && !empty($daySchedule['open']) && !empty($daySchedule['close'])) {
                            $store['is_open'] = ($now >= $daySchedule['open'] && $now <= $daySchedule['close']);
                        }
                        // Handle array of time slots
                        if (is_array($daySchedule) && isset($daySchedule[0])) {
                            foreach ($daySchedule as $slot) {
                                if (!empty($slot['open']) && !empty($slot['close'])) {
                                    if ($now >= $slot['open'] && $now <= $slot['close']) {
                                        $store['is_open'] = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                // Fallback to opens_at / closes_at time columns
                if (!$store['is_open'] && !empty($store['opens_at']) && !empty($store['closes_at'])) {
                    $store['is_open'] = ($now >= $store['opens_at'] && $now <= $store['closes_at']);
                }
            }

            $store['open_status'] = $store['is_open'] ? 'open' : 'closed';
            unset($store['horario_funcionamento'], $store['opens_at'], $store['closes_at']); // Don't expose raw schedule
        }
        unset($store);

        response(true, ['stores' => $stores, 'count' => count($stores)]);
    }

    // ── Product catalog for a specific store ──
    // Validate partner exists
    $stmt = $db->prepare("
        SELECT partner_id, name, address, city, phone, delivery_fee, logo, rating
        FROM om_market_partners
        WHERE partner_id = ?
    ");
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch();

    if (!$partner) {
        response(false, null, "Loja nao encontrada", 404);
    }

    // Fetch categories (partner-specific + global categories used by this partner's products)
    $stmt = $db->prepare("
        SELECT DISTINCT c.category_id AS id, c.name, c.sort_order
        FROM om_market_categories c
        WHERE (c.created_by_partner_id = ? OR c.category_id IN (
            SELECT DISTINCT category_id FROM om_market_products WHERE partner_id = ? AND category_id IS NOT NULL
        ))
        AND c.status::text = '1'
        ORDER BY c.sort_order ASC, c.name ASC
    ");
    $stmt->execute([$partnerId, $partnerId]);
    $categories = $stmt->fetchAll();

    // Fetch products (optionally filtered)
    $productWhere = "p.partner_id = ? AND p.status::text = '1'";
    $productParams = [$partnerId];

    if ($q) {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $q);
        $like = '%' . $escaped . '%';
        $productWhere .= " AND (p.name ILIKE ? OR p.description ILIKE ?)";
        $productParams[] = $like;
        $productParams[] = $like;
    }

    $stmt = $db->prepare("
        SELECT p.product_id AS id, p.category_id, p.name, p.description,
               p.price, p.special_price, p.image AS image_url,
               p.quantity AS stock, p.unit
        FROM om_market_products p
        WHERE {$productWhere}
        ORDER BY p.sort_order ASC, p.name ASC
    ");
    $stmt->execute($productParams);
    $products = $stmt->fetchAll();

    // Fetch product IDs for option lookup
    $productIds = array_map(fn($p) => (int)$p['id'], $products);

    // Fetch option groups and options for all products in one query
    $optionsByProduct = [];
    if (!empty($productIds)) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        // Fetch option groups
        $stmt = $db->prepare("
            SELECT g.id AS group_id, g.product_id, g.name AS group_name,
                   g.required, g.min_select, g.max_select, g.sort_order AS group_sort
            FROM om_product_option_groups g
            WHERE g.product_id IN ({$placeholders}) AND g.active = 1
            ORDER BY g.sort_order ASC, g.name ASC
        ");
        $stmt->execute($productIds);
        $allGroups = $stmt->fetchAll();

        // Collect group IDs to fetch options
        $groupIds = array_map(fn($g) => (int)$g['group_id'], $allGroups);
        $groupsByProduct = [];
        foreach ($allGroups as $g) {
            $pid = (int)$g['product_id'];
            if (!isset($groupsByProduct[$pid])) {
                $groupsByProduct[$pid] = [];
            }
            $groupsByProduct[$pid][] = $g;
        }

        // Fetch individual options within groups
        $optionsByGroup = [];
        if (!empty($groupIds)) {
            $gPlaceholders = implode(',', array_fill(0, count($groupIds), '?'));
            $stmt = $db->prepare("
                SELECT o.id, o.group_id, o.name, o.price_extra, o.available, o.sort_order
                FROM om_product_options o
                WHERE o.group_id IN ({$gPlaceholders}) AND o.available = 1
                ORDER BY o.sort_order ASC, o.name ASC
            ");
            $stmt->execute($groupIds);
            foreach ($stmt->fetchAll() as $opt) {
                $gid = (int)$opt['group_id'];
                if (!isset($optionsByGroup[$gid])) {
                    $optionsByGroup[$gid] = [];
                }
                $optionsByGroup[$gid][] = [
                    'id' => (int)$opt['id'],
                    'name' => $opt['name'],
                    'price' => (float)$opt['price_extra'],
                ];
            }
        }

        // Build structured options by product
        foreach ($groupsByProduct as $pid => $groups) {
            $optionsByProduct[$pid] = [];
            foreach ($groups as $g) {
                $gid = (int)$g['group_id'];
                $optionsByProduct[$pid][] = [
                    'group_id' => $gid,
                    'group_name' => $g['group_name'],
                    'required' => (bool)$g['required'],
                    'min_select' => (int)$g['min_select'],
                    'max_select' => (int)$g['max_select'],
                    'options' => $optionsByGroup[$gid] ?? [],
                ];
            }
        }
    }

    // Build product map by category
    $productsByCategory = [];
    $uncategorized = [];

    foreach ($products as $p) {
        $pid = (int)$p['id'];
        $catId = $p['category_id'] ? (int)$p['category_id'] : null;

        $productData = [
            'id' => $pid,
            'name' => $p['name'],
            'description' => $p['description'],
            'price' => (float)$p['price'],
            'special_price' => $p['special_price'] ? (float)$p['special_price'] : null,
            'image_url' => $p['image_url'],
            'stock' => (int)$p['stock'],
            'unit' => $p['unit'],
            'options' => $optionsByProduct[$pid] ?? [],
        ];

        if ($catId) {
            if (!isset($productsByCategory[$catId])) {
                $productsByCategory[$catId] = [];
            }
            $productsByCategory[$catId][] = $productData;
        } else {
            $uncategorized[] = $productData;
        }
    }

    // Build structured response
    $structuredCategories = [];
    foreach ($categories as $cat) {
        $catId = (int)$cat['id'];
        $catProducts = $productsByCategory[$catId] ?? [];
        // Only include categories with products (or all if no search)
        if (!$q || !empty($catProducts)) {
            $structuredCategories[] = [
                'id' => $catId,
                'name' => $cat['name'],
                'products' => $catProducts,
            ];
        }
    }

    // Add uncategorized products if any
    if (!empty($uncategorized)) {
        $structuredCategories[] = [
            'id' => null,
            'name' => 'Outros',
            'products' => $uncategorized,
        ];
    }

    response(true, [
        'partner' => [
            'id' => (int)$partner['partner_id'],
            'name' => $partner['name'],
            'address' => $partner['address'],
            'city' => $partner['city'],
            'phone' => $partner['phone'],
            'delivery_fee' => (float)($partner['delivery_fee'] ?? 0),
            'logo_url' => $partner['logo'],
            'rating' => round((float)($partner['rating'] ?? 0), 1),
        ],
        'categories' => $structuredCategories,
        'total_products' => count($products),
    ]);

} catch (Exception $e) {
    error_log("[admin/callcenter/stores] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
