<?php
/**
 * GET /api/mercado/mesa/cardapio.php?table_code=XXX
 *
 * PUBLIC ENDPOINT (no auth required).
 * Returns partner info, table info, full menu grouped by category, and QR config.
 *
 * The table_code can be:
 *   - A table ID (numeric)
 *   - The QR code URL (decoded) that contains partner_id + table number
 *   - A compound code "PARTNER_ID-TABLE_NUMBER" (e.g., "5-3" = partner 5, mesa 3)
 */

require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();

    $tableCode = trim($_GET['table_code'] ?? $_GET['t'] ?? '');
    if (empty($tableCode)) {
        response(false, null, "table_code e obrigatorio", 400);
    }

    // Sanitize: max 200 chars, strip dangerous chars
    $tableCode = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $tableCode), 0, 200);

    $table = null;
    $partnerId = 0;

    // Strategy 1: Compound code "PARTNER_ID-TABLE_NUMBER"
    if (preg_match('/^(\d+)-(\d+)$/', $tableCode, $matches)) {
        $partnerId = (int)$matches[1];
        $tableNumero = (int)$matches[2];

        $stmt = dbQuery($db,
            "SELECT * FROM om_market_qr_tables WHERE partner_id = ? AND numero = ? AND ativo = true LIMIT 1",
            [$partnerId, $tableNumero]
        );
        $table = $stmt->fetch();
    }

    // Strategy 2: Numeric table ID
    if (!$table && ctype_digit($tableCode)) {
        $stmt = dbQuery($db,
            "SELECT * FROM om_market_qr_tables WHERE id = ? AND ativo = true LIMIT 1",
            [(int)$tableCode]
        );
        $table = $stmt->fetch();
        if ($table) {
            $partnerId = (int)$table['partner_id'];
        }
    }

    if (!$table) {
        response(false, null, "Mesa nao encontrada ou inativa", 404);
    }

    $partnerId = (int)$table['partner_id'];

    // Validate QR ordering is enabled for this partner
    $stmtConfig = dbQuery($db,
        "SELECT * FROM om_market_qr_config WHERE partner_id = ?",
        [$partnerId]
    );
    $config = $stmtConfig->fetch();

    if (!$config || !$config['enabled']) {
        response(false, null, "Pedido por QR Code nao esta habilitado neste estabelecimento", 403);
    }

    // Fetch partner info
    $stmtPartner = dbQuery($db,
        "SELECT partner_id, name, trade_name, logo, banner, address, city, state, phone,
                is_open, open_time, close_time, categoria, description,
                busy_mode, pause_until
         FROM om_market_partners
         WHERE partner_id = ? AND status::text = '1'",
        [$partnerId]
    );
    $partner = $stmtPartner->fetch();

    if (!$partner) {
        response(false, null, "Estabelecimento nao encontrado", 404);
    }

    // Check if store is open
    $isOpen = (int)($partner['is_open'] ?? 0) === 1;
    $isPaused = !empty($partner['pause_until']) && strtotime($partner['pause_until']) > time();

    // Fetch categories that have active products for this partner
    $stmtCats = dbQuery($db,
        "SELECT DISTINCT c.category_id, c.name
         FROM om_market_categories c
         INNER JOIN om_market_products p ON p.category_id = c.category_id
         WHERE p.partner_id = ? AND p.status::text = '1' AND (p.available::text = '1' OR p.available IS NULL)
         ORDER BY c.name",
        [$partnerId]
    );
    $categories = $stmtCats->fetchAll();

    // Fetch all active products for this partner
    $stmtProducts = dbQuery($db,
        "SELECT p.product_id, p.name, p.description, p.price, p.special_price,
                p.image, p.unit, p.quantity, p.category_id,
                c.name as category_name
         FROM om_market_products p
         LEFT JOIN om_market_categories c ON p.category_id = c.category_id
         WHERE p.partner_id = ? AND p.status::text = '1' AND (p.available::text = '1' OR p.available IS NULL)
         ORDER BY c.name, p.name",
        [$partnerId]
    );
    $products = $stmtProducts->fetchAll();

    // Fetch option groups for all products
    $productIds = array_map(fn($p) => $p['product_id'], $products);
    $optionGroups = [];

    if (!empty($productIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmtGroups = dbQuery($db,
            "SELECT g.*, o.id as option_id, o.name as option_name, o.price_extra,
                    o.available as option_available, o.sort_order as option_sort
             FROM om_product_option_groups g
             LEFT JOIN om_product_options o ON g.id = o.group_id
             WHERE g.product_id IN ({$inPlaceholders}) AND g.active = 1
             ORDER BY g.sort_order, g.id, o.sort_order, o.id",
            array_values($productIds)
        );
        $rows = $stmtGroups->fetchAll();

        foreach ($rows as $row) {
            $pid = $row['product_id'];
            $gid = $row['id'];
            if (!isset($optionGroups[$pid][$gid])) {
                $optionGroups[$pid][$gid] = [
                    'id' => (int)$gid,
                    'name' => $row['name'],
                    'required' => (bool)$row['required'],
                    'min_select' => (int)$row['min_select'],
                    'max_select' => (int)$row['max_select'],
                    'options' => []
                ];
            }
            if ($row['option_id'] && $row['option_available']) {
                $optionGroups[$pid][$gid]['options'][] = [
                    'id' => (int)$row['option_id'],
                    'name' => $row['option_name'],
                    'price_extra' => (float)$row['price_extra']
                ];
            }
        }
    }

    // Group products by category
    $menu = [];
    foreach ($categories as $cat) {
        $catId = $cat['category_id'];
        $catProducts = array_filter($products, fn($p) => (int)$p['category_id'] === (int)$catId);

        $formattedProducts = [];
        foreach ($catProducts as $p) {
            $pid = $p['product_id'];
            $groups = isset($optionGroups[$pid]) ? array_values($optionGroups[$pid]) : [];

            $formattedProducts[] = [
                'id' => (int)$pid,
                'name' => $p['name'],
                'description' => $p['description'],
                'price' => (float)($p['special_price'] && (float)$p['special_price'] > 0 && (float)$p['special_price'] < (float)$p['price']
                    ? $p['special_price'] : $p['price']),
                'original_price' => (float)$p['price'],
                'has_discount' => ($p['special_price'] && (float)$p['special_price'] > 0 && (float)$p['special_price'] < (float)$p['price']),
                'image' => $p['image'],
                'unit' => $p['unit'] ?? 'un',
                'in_stock' => ((int)($p['quantity'] ?? 999)) > 0,
                'option_groups' => $groups
            ];
        }

        if (!empty($formattedProducts)) {
            $menu[] = [
                'category_id' => (int)$catId,
                'category_name' => $cat['name'],
                'products' => array_values($formattedProducts)
            ];
        }
    }

    // Also include uncategorized products
    $uncategorized = array_filter($products, fn($p) => empty($p['category_id']));
    if (!empty($uncategorized)) {
        $uncatProducts = [];
        foreach ($uncategorized as $p) {
            $pid = $p['product_id'];
            $groups = isset($optionGroups[$pid]) ? array_values($optionGroups[$pid]) : [];

            $uncatProducts[] = [
                'id' => (int)$pid,
                'name' => $p['name'],
                'description' => $p['description'],
                'price' => (float)($p['special_price'] && (float)$p['special_price'] > 0 && (float)$p['special_price'] < (float)$p['price']
                    ? $p['special_price'] : $p['price']),
                'original_price' => (float)$p['price'],
                'has_discount' => ($p['special_price'] && (float)$p['special_price'] > 0 && (float)$p['special_price'] < (float)$p['price']),
                'image' => $p['image'],
                'unit' => $p['unit'] ?? 'un',
                'in_stock' => ((int)($p['quantity'] ?? 999)) > 0,
                'option_groups' => $groups
            ];
        }
        if (!empty($uncatProducts)) {
            array_unshift($menu, [
                'category_id' => 0,
                'category_name' => 'Outros',
                'products' => $uncatProducts
            ]);
        }
    }

    response(true, [
        'partner' => [
            'id' => (int)$partner['partner_id'],
            'name' => $partner['name'] ?? $partner['trade_name'],
            'logo' => $partner['logo'],
            'banner' => $partner['banner'],
            'address' => $partner['address'],
            'city' => $partner['city'],
            'category' => $partner['categoria'],
            'description' => $partner['description'],
            'is_open' => $isOpen && !$isPaused,
            'busy_mode' => (bool)($partner['busy_mode'] ?? false)
        ],
        'table' => [
            'id' => (int)$table['id'],
            'number' => (int)$table['numero'],
            'name' => $table['nome']
        ],
        'menu' => $menu,
        'config' => [
            'allow_payment_at_table' => (bool)$config['allow_payment_at_table'],
            'auto_accept' => (bool)$config['auto_accept']
        ],
        'total_products' => count($products)
    ], "Cardapio carregado");

} catch (Exception $e) {
    error_log("[mesa/cardapio] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar cardapio", 500);
}
