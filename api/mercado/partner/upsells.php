<?php
/**
 * GET /api/mercado/partner/upsells.php - Listar upsells/cross-sells
 * POST /api/mercado/partner/upsells.php - Gerenciar upsells
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
        $productId = intval($_GET['product_id'] ?? 0);

        if ($productId > 0) {
            // Get upsells for specific product
            $stmt = $db->prepare("
                SELECT u.*, p.name, p.price, p.image
                FROM om_product_upsells u
                JOIN om_market_products p ON p.product_id = u.upsell_product_id
                JOIN om_market_products pp ON pp.product_id = u.product_id AND pp.partner_id = ?
                WHERE u.product_id = ?
                ORDER BY u.sort_order ASC
            ");
            $stmt->execute([$partnerId, $productId]);
            $upsells = $stmt->fetchAll(PDO::FETCH_ASSOC);

            response(true, ['upsells' => $upsells]);
        }

        // Get all upsell configurations
        $stmt = $db->prepare("
            SELECT
                u.*,
                p1.name as product_name,
                p2.name as upsell_name, p2.price as upsell_price
            FROM om_product_upsells u
            JOIN om_market_products p1 ON p1.product_id = u.product_id AND p1.partner_id = ?
            JOIN om_market_products p2 ON p2.product_id = u.upsell_product_id
            ORDER BY u.product_id, u.sort_order
        ");
        $stmt->execute([$partnerId]);
        $upsells = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by product
        $grouped = [];
        foreach ($upsells as $u) {
            $pid = $u['product_id'];
            if (!isset($grouped[$pid])) {
                $grouped[$pid] = [
                    'product_id' => $pid,
                    'product_name' => $u['product_name'],
                    'upsells' => [],
                ];
            }
            $grouped[$pid]['upsells'][] = [
                'id' => $u['id'],
                'upsell_product_id' => $u['upsell_product_id'],
                'upsell_name' => $u['upsell_name'],
                'upsell_price' => $u['upsell_price'],
                'message' => $u['message'] ?? '',
                'discount_percent' => $u['discount_percent'],
            ];
        }

        // Get products for selection
        $stmt = $db->prepare("
            SELECT product_id, name, price FROM om_market_products
            WHERE partner_id = ? AND status = '1' ORDER BY name
        ");
        $stmt->execute([$partnerId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        response(true, [
            'upsell_configs' => array_values($grouped),
            'products' => $products,
            'upsell_types' => [
                'complement' => 'Complemento (ex: bebida com lanche)',
                'upgrade' => 'Upgrade (ex: tamanho maior)',
                'combo' => 'Combo (ex: adicionar sobremesa)',
            ],
        ]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'add';

        if ($action === 'add') {
            $productId = intval($input['product_id'] ?? 0);
            $upsellProductId = intval($input['upsell_product_id'] ?? 0);
            $upsellType = $input['upsell_type'] ?? 'complement';
            $discount = floatval($input['discount_percent'] ?? 0);

            if (!$productId || !$upsellProductId) {
                response(false, null, "Produtos obrigatorios", 400);
            }

            if ($productId === $upsellProductId) {
                response(false, null, "Produto nao pode ser upsell de si mesmo", 400);
            }

            // Verify both products belong to partner
            $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE product_id IN (?, ?) AND partner_id = ?");
            $stmt->execute([$productId, $upsellProductId, $partnerId]);
            if ($stmt->rowCount() < 2) {
                response(false, null, "Produto nao encontrado", 404);
            }

            // Check if already exists
            $stmt = $db->prepare("
                SELECT id FROM om_product_upsells
                WHERE product_id = ? AND upsell_product_id = ?
            ");
            $stmt->execute([$productId, $upsellProductId]);
            if ($stmt->fetch()) {
                response(false, null, "Upsell ja configurado", 400);
            }

            // Max 5 upsells per product
            $stmt = $db->prepare("
                SELECT COUNT(*) as cnt FROM om_product_upsells
                WHERE product_id = ?
            ");
            $stmt->execute([$productId]);
            if ($stmt->fetch()['cnt'] >= 5) {
                response(false, null, "Maximo de 5 upsells por produto", 400);
            }

            $message = $input['message'] ?? '';
            $stmt = $db->prepare("
                INSERT INTO om_product_upsells (product_id, upsell_product_id, message, discount_percent)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$productId, $upsellProductId, $message, $discount]);

            response(true, ['id' => $db->lastInsertId()], "Upsell adicionado!");
        }

        if ($action === 'remove') {
            $upsellId = intval($input['id'] ?? 0);
            if (!$upsellId) response(false, null, "ID obrigatorio", 400);

            // Verify it belongs to a product of this partner
            $stmt = $db->prepare("
                SELECT u.id FROM om_product_upsells u
                JOIN om_market_products p ON p.product_id = u.product_id AND p.partner_id = ?
                WHERE u.id = ?
            ");
            $stmt->execute([$partnerId, $upsellId]);
            if (!$stmt->fetch()) {
                response(false, null, "Upsell nao encontrado", 404);
            }

            $stmt = $db->prepare("DELETE FROM om_product_upsells WHERE id = ?");
            $stmt->execute([$upsellId]);

            response(true, null, "Upsell removido!");
        }

        if ($action === 'bulk_add') {
            // Add same upsell to multiple products
            $productIds = $input['product_ids'] ?? [];
            $upsellProductId = intval($input['upsell_product_id'] ?? 0);
            $message = $input['message'] ?? '';

            if (empty($productIds) || !$upsellProductId) {
                response(false, null, "Produtos obrigatorios", 400);
            }

            // Verify upsell product belongs to this partner
            $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE product_id = ? AND partner_id = ?");
            $stmt->execute([$upsellProductId, $partnerId]);
            if (!$stmt->fetch()) {
                response(false, null, "Produto upsell nao encontrado", 404);
            }

            // Get all valid product IDs owned by this partner
            $allProductIds = array_map('intval', $productIds);
            $placeholders = implode(',', array_fill(0, count($allProductIds), '?'));
            $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE product_id IN ($placeholders) AND partner_id = ?");
            $stmt->execute([...$allProductIds, $partnerId]);
            $ownedIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'product_id');

            $added = 0;
            foreach ($ownedIds as $productId) {
                $productId = intval($productId);
                if ($productId === $upsellProductId) continue;

                // Check if exists
                $stmt = $db->prepare("
                    SELECT id FROM om_product_upsells
                    WHERE product_id = ? AND upsell_product_id = ?
                ");
                $stmt->execute([$productId, $upsellProductId]);
                if ($stmt->fetch()) continue;

                $stmt = $db->prepare("
                    INSERT INTO om_product_upsells (product_id, upsell_product_id, message)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$productId, $upsellProductId, $message]);
                $added++;
            }

            response(true, ['added' => $added], "Upsells adicionados!");
        }

        response(false, null, "Acao invalida", 400);
    }

    if ($method === 'DELETE') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) response(false, null, "ID obrigatorio", 400);

        // Verify it belongs to a product of this partner
        $stmt = $db->prepare("
            SELECT u.id FROM om_product_upsells u
            JOIN om_market_products p ON p.product_id = u.product_id AND p.partner_id = ?
            WHERE u.id = ?
        ");
        $stmt->execute([$partnerId, $id]);
        if (!$stmt->fetch()) {
            response(false, null, "Upsell nao encontrado", 404);
        }

        $stmt = $db->prepare("DELETE FROM om_product_upsells WHERE id = ?");
        $stmt->execute([$id]);

        response(true, null, "Upsell removido!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/upsells] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
