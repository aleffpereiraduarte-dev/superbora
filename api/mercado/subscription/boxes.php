<?php
/**
 * GET /api/mercado/subscription/boxes.php
 * List available subscription boxes (no auth required).
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $db = getDB();
    $stmt = $db->query(
        "SELECT id, name, description, category, base_price, full_price, image, sample_items
         FROM om_subscription_boxes
         WHERE is_active = true
         ORDER BY base_price ASC"
    );
    $boxes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($boxes as &$b) {
        $b['base_price'] = (float)$b['base_price'];
        // full_price is optional — used by the frontend to show
        // "Economize X%" savings badges when higher than base_price.
        $b['full_price'] = $b['full_price'] !== null ? (float)$b['full_price'] : null;
        if (is_string($b['sample_items'])) {
            $b['sample_items'] = json_decode($b['sample_items'], true) ?: [];
        }
    }
    response(true, ['boxes' => $boxes]);
} catch (Exception $e) {
    error_log('[boxes] ' . $e->getMessage());
    response(false, null, 'Erro ao listar cestas', 500);
}
