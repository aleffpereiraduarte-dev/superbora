<?php
/**
 * GET /api/mercado/partner/price-history.php?product_id=X
 * Price change history for a specific product
 * Returns list of changes, most recent first, LIMIT 50
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    // Ensure table exists
    ensurePriceHistoryTable($db);

    $productId = (int)($_GET['product_id'] ?? 0);

    // If no product_id, return recent changes across all products
    if ($productId <= 0) {
        $stmt = dbQuery($db, "
            SELECT
                ph.id, ph.product_id, ph.old_price, ph.new_price,
                ph.changed_by, ph.reason, ph.created_at,
                COALESCE(p.name, pb.name, 'Produto #' || ph.product_id::text) as product_name
            FROM om_price_history ph
            LEFT JOIN om_market_products p ON p.product_id = ph.product_id AND p.partner_id = ?
            LEFT JOIN om_market_products_base pb ON pb.product_id = ph.product_id
            WHERE ph.partner_id = ?
            ORDER BY ph.created_at DESC
            LIMIT 50
        ", [$partnerId, $partnerId]);
        $rows = $stmt->fetchAll();

        $history = [];
        foreach ($rows as $row) {
            $oldPrice = (float)$row['old_price'];
            $newPrice = (float)$row['new_price'];
            $diff = $newPrice - $oldPrice;
            $pctChange = $oldPrice > 0 ? round(($diff / $oldPrice) * 100, 1) : 0;

            $history[] = [
                'id' => (int)$row['id'],
                'product_id' => (int)$row['product_id'],
                'product_name' => $row['product_name'],
                'old_price' => round($oldPrice, 2),
                'new_price' => round($newPrice, 2),
                'difference' => round($diff, 2),
                'change_percent' => $pctChange,
                'direction' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'same'),
                'changed_by' => $row['changed_by'],
                'reason' => $row['reason'],
                'created_at' => $row['created_at'],
            ];
        }

        response(true, [
            'history' => $history,
            'total' => count($history),
        ], "Historico de precos recente");
    }

    // Verify product belongs to this partner
    $stmtCheck = dbQuery($db, "
        SELECT product_id FROM om_market_products WHERE product_id = ? AND partner_id = ?
        UNION
        SELECT product_id FROM om_market_products_price WHERE product_id = ? AND partner_id = ?
    ", [$productId, $partnerId, $productId, $partnerId]);

    if (!$stmtCheck->fetch()) {
        response(false, null, "Produto nao encontrado", 404);
    }

    // Get product name
    $stmtName = dbQuery($db, "
        SELECT COALESCE(p.name, pb.name, 'Produto') as name
        FROM (SELECT 1) dummy
        LEFT JOIN om_market_products p ON p.product_id = ? AND p.partner_id = ?
        LEFT JOIN om_market_products_base pb ON pb.product_id = ?
        LIMIT 1
    ", [$productId, $partnerId, $productId]);
    $productName = $stmtName->fetchColumn() ?: 'Produto';

    // Get price history
    $stmt = dbQuery($db, "
        SELECT id, old_price, new_price, changed_by, reason, created_at
        FROM om_price_history
        WHERE partner_id = ? AND product_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ", [$partnerId, $productId]);
    $rows = $stmt->fetchAll();

    $history = [];
    foreach ($rows as $row) {
        $oldPrice = (float)$row['old_price'];
        $newPrice = (float)$row['new_price'];
        $diff = $newPrice - $oldPrice;
        $pctChange = $oldPrice > 0 ? round(($diff / $oldPrice) * 100, 1) : 0;

        $history[] = [
            'id' => (int)$row['id'],
            'old_price' => round($oldPrice, 2),
            'new_price' => round($newPrice, 2),
            'difference' => round($diff, 2),
            'change_percent' => $pctChange,
            'direction' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'same'),
            'changed_by' => $row['changed_by'],
            'reason' => $row['reason'],
            'created_at' => $row['created_at'],
        ];
    }

    // Current price
    $stmtCurrent = dbQuery($db, "
        SELECT COALESCE(p.price, pp.price, 0) as current_price
        FROM (SELECT 1) dummy
        LEFT JOIN om_market_products p ON p.product_id = ? AND p.partner_id = ?
        LEFT JOIN om_market_products_price pp ON pp.product_id = ? AND pp.partner_id = ?
        LIMIT 1
    ", [$productId, $partnerId, $productId, $partnerId]);
    $currentPrice = round((float)$stmtCurrent->fetchColumn(), 2);

    // Price stats
    $minPrice = null;
    $maxPrice = null;
    if (!empty($history)) {
        $allPrices = array_merge(
            array_column($history, 'old_price'),
            array_column($history, 'new_price')
        );
        $minPrice = min($allPrices);
        $maxPrice = max($allPrices);
    }

    response(true, [
        'product_id' => $productId,
        'product_name' => $productName,
        'current_price' => $currentPrice,
        'history' => $history,
        'total' => count($history),
        'stats' => [
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'total_changes' => count($history),
        ],
    ], "Historico de precos");

} catch (Exception $e) {
    error_log("[partner/price-history] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

/**
 * Create om_price_history table if not exists
 */
function ensurePriceHistoryTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_price_history (
            id SERIAL PRIMARY KEY,
            partner_id INT NOT NULL,
            product_id INT NOT NULL,
            old_price DECIMAL(10,2) NOT NULL,
            new_price DECIMAL(10,2) NOT NULL,
            changed_by VARCHAR(100),
            reason VARCHAR(500),
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_price_history_partner_product ON om_price_history (partner_id, product_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_price_history_created ON om_price_history (created_at DESC)");
}
