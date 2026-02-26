<?php
/**
 * GET /api/mercado/partner/featured-items.php - Listar itens destacados
 * POST /api/mercado/partner/featured-items.php - Gerenciar destaques
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Get featured items
        $stmt = $db->prepare("
            SELECT fi.*, p.name, p.price, p.image
            FROM om_featured_items fi
            JOIN om_market_products p ON p.product_id = fi.product_id
            WHERE fi.partner_id = ?
            ORDER BY fi.feature_type, fi.sort_order ASC
        ");
        $stmt->execute([$partnerId]);
        $featured = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by type
        $grouped = [
            'bestseller' => [],
            'new' => [],
            'recommended' => [],
            'chef_special' => [],
        ];

        foreach ($featured as $item) {
            $type = $item['feature_type'] ?? 'recommended';
            if (isset($grouped[$type])) {
                $grouped[$type][] = $item;
            }
        }

        // Get available products not yet featured
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price
            FROM om_market_products p
            WHERE p.partner_id = ? AND p.status = '1'
            AND p.product_id NOT IN (SELECT product_id FROM om_featured_items WHERE partner_id = ?)
            ORDER BY p.name
            LIMIT 50
        ");
        $stmt->execute([$partnerId, $partnerId]);
        $available = $stmt->fetchAll(PDO::FETCH_ASSOC);

        response(true, [
            'featured' => $grouped,
            'available_products' => $available,
            'feature_types' => [
                'bestseller' => 'Mais Vendido',
                'new' => 'Novidade',
                'recommended' => 'Recomendado',
                'chef_special' => 'Especial do Chef',
            ],
        ]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'add';

        if ($action === 'add') {
            $productId = intval($input['product_id'] ?? 0);
            $featureType = $input['feature_type'] ?? 'recommended';

            if (!$productId) response(false, null, "ID do produto obrigatorio", 400);

            // Verify product belongs to partner
            $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE product_id = ? AND partner_id = ?");
            $stmt->execute([$productId, $partnerId]);
            if (!$stmt->fetch()) response(false, null, "Produto nao encontrado", 404);

            $db->beginTransaction();
            try {
                // Check max featured per type (lock rows to prevent race condition)
                $stmt = $db->prepare("
                    SELECT COUNT(*) as cnt FROM om_featured_items
                    WHERE partner_id = ? AND feature_type = ?
                    FOR UPDATE
                ");
                $stmt->execute([$partnerId, $featureType]);
                if ($stmt->fetch()['cnt'] >= 5) {
                    $db->rollBack();
                    response(false, null, "Maximo de 5 itens por categoria", 400);
                }

                // Check if already featured
                $stmt = $db->prepare("
                    SELECT id FROM om_featured_items WHERE partner_id = ? AND product_id = ?
                ");
                $stmt->execute([$partnerId, $productId]);
                if ($stmt->fetch()) {
                    $db->rollBack();
                    response(false, null, "Produto ja esta em destaque", 400);
                }

                $stmt = $db->prepare("
                    INSERT INTO om_featured_items (partner_id, product_id, feature_type)
                    VALUES (?, ?, ?)
                    RETURNING id
                ");
                $stmt->execute([$partnerId, $productId, $featureType]);
                $newId = $stmt->fetchColumn();
                $db->commit();

                response(true, ['id' => $newId], "Item destacado!");
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }

        if ($action === 'remove') {
            $featuredId = intval($input['id'] ?? 0);
            if (!$featuredId) response(false, null, "ID obrigatorio", 400);

            $stmt = $db->prepare("
                DELETE FROM om_featured_items WHERE id = ? AND partner_id = ?
            ");
            $stmt->execute([$featuredId, $partnerId]);

            response(true, null, "Destaque removido!");
        }

        if ($action === 'reorder') {
            $items = $input['items'] ?? [];
            if (empty($items)) response(false, null, "Itens obrigatorios", 400);

            foreach ($items as $index => $itemId) {
                $stmt = $db->prepare("
                    UPDATE om_featured_items SET sort_order = ? WHERE id = ? AND partner_id = ?
                ");
                $stmt->execute([$index, $itemId, $partnerId]);
            }

            response(true, null, "Ordem atualizada!");
        }

        response(false, null, "Acao invalida", 400);
    }

    if ($method === 'DELETE') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) response(false, null, "ID obrigatorio", 400);

        $stmt = $db->prepare("DELETE FROM om_featured_items WHERE id = ? AND partner_id = ?");
        $stmt->execute([$id, $partnerId]);

        response(true, null, "Destaque removido!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/featured-items] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
