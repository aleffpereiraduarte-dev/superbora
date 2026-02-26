<?php
/**
 * Wishlist Items API - Manage items within a wishlist
 *
 * GET /api/mercado/vitrine/wishlist-items.php?wishlist_id=X - List items in a wishlist
 * GET /api/mercado/vitrine/wishlist-items.php?product_id=X - Check if product is in any wishlist
 * POST /api/mercado/vitrine/wishlist-items.php - Add item to wishlist
 * DELETE /api/mercado/vitrine/wishlist-items.php?wishlist_id=X&product_id=Y - Remove item
 *
 * Requires authentication via Bearer token
 */

require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();

    // Tables om_customer_wishlists and om_wishlist_items already exist in PostgreSQL

    // Get authenticated customer
    $customerId = getAuthenticatedCustomerId();

    if (!$customerId) {
        response(false, null, "Autenticacao necessaria", 401);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            handleGetItems($db, $customerId);
            break;
        case 'POST':
            handleAddItem($db, $customerId);
            break;
        case 'DELETE':
            handleRemoveItem($db, $customerId);
            break;
        default:
            response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[vitrine/wishlist-items] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar wishlist items", 500);
}

/**
 * GET - List items in a wishlist or check if product is in any wishlist
 */
function handleGetItems(PDO $db, int $customerId): void {
    $wishlistId = (int)($_GET['wishlist_id'] ?? 0);
    $productId = (int)($_GET['product_id'] ?? 0);

    // Check if product is in any wishlist
    if ($productId && !$wishlistId) {
        $stmt = $db->prepare("
            SELECT
                w.id as wishlist_id,
                w.name as wishlist_name
            FROM om_wishlist_items wi
            JOIN om_customer_wishlists w ON wi.wishlist_id = w.id
            WHERE w.customer_id = ? AND wi.product_id = ?
        ");
        $stmt->execute([$customerId, $productId]);
        $wishlists = $stmt->fetchAll();

        response(true, [
            'in_wishlists' => array_map(fn($w) => [
                'id' => (int)$w['wishlist_id'],
                'name' => $w['wishlist_name'],
            ], $wishlists),
            'is_wishlisted' => count($wishlists) > 0,
        ]);
        return;
    }

    if (!$wishlistId) {
        response(false, null, "wishlist_id ou product_id e obrigatorio", 400);
    }

    // Verify wishlist ownership
    $stmtCheck = $db->prepare("
        SELECT id FROM om_customer_wishlists
        WHERE id = ? AND customer_id = ?
    ");
    $stmtCheck->execute([$wishlistId, $customerId]);

    if (!$stmtCheck->fetch()) {
        response(false, null, "Lista nao encontrada", 404);
    }

    // Get items with product details
    $stmt = $db->prepare("
        SELECT
            wi.id,
            wi.product_id,
            wi.partner_id,
            wi.added_at,
            p.name as product_name,
            p.image as product_image,
            p.price,
            p.special_price,
            p.unit,
            p.in_stock,
            p.stock,
            par.trade_name as partner_name,
            par.logo as partner_logo
        FROM om_wishlist_items wi
        JOIN om_market_products p ON wi.product_id = p.product_id
        LEFT JOIN om_market_partners par ON wi.partner_id = par.partner_id
        WHERE wi.wishlist_id = ?
        ORDER BY wi.added_at DESC
    ");
    $stmt->execute([$wishlistId]);
    $items = $stmt->fetchAll();

    $result = [];
    foreach ($items as $item) {
        $hasPromo = $item['special_price'] && $item['special_price'] < $item['price'];
        $result[] = [
            'id' => (int)$item['id'],
            'product_id' => (int)$item['product_id'],
            'partner_id' => (int)$item['partner_id'],
            'product' => [
                'id' => (int)$item['product_id'],
                'nome' => $item['product_name'],
                'imagem' => $item['product_image'],
                'preco' => (float)$item['price'],
                'preco_promo' => $item['special_price'] ? (float)$item['special_price'] : null,
                'unidade' => $item['unit'],
                'disponivel' => (bool)$item['in_stock'],
                'estoque' => (int)$item['stock'],
                'has_promo' => $hasPromo,
            ],
            'partner' => [
                'id' => (int)$item['partner_id'],
                'name' => $item['partner_name'],
                'logo' => $item['partner_logo'],
            ],
            'added_at' => $item['added_at'],
        ];
    }

    response(true, [
        'items' => $result,
        'total' => count($result),
    ]);
}

/**
 * POST - Add item to wishlist
 */
function handleAddItem(PDO $db, int $customerId): void {
    $input = getInput();
    $wishlistId = (int)($input['wishlist_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? 0);
    $partnerId = (int)($input['partner_id'] ?? 0);

    // If no wishlist specified, use default
    if (!$wishlistId) {
        $wishlistId = getOrCreateDefaultWishlist($db, $customerId);
    }

    if (!$productId) {
        response(false, null, "product_id e obrigatorio", 400);
    }

    // Verify wishlist ownership
    $stmtCheck = $db->prepare("
        SELECT id FROM om_customer_wishlists
        WHERE id = ? AND customer_id = ?
    ");
    $stmtCheck->execute([$wishlistId, $customerId]);

    if (!$stmtCheck->fetch()) {
        response(false, null, "Lista nao encontrada", 404);
    }

    // Verify product exists and get partner_id if not provided
    $stmtProduct = $db->prepare("
        SELECT product_id, partner_id FROM om_market_products WHERE product_id = ?
    ");
    $stmtProduct->execute([$productId]);
    $product = $stmtProduct->fetch();

    if (!$product) {
        response(false, null, "Produto nao encontrado", 404);
    }

    if (!$partnerId) {
        $partnerId = (int)$product['partner_id'];
    }

    // Limit items per wishlist (100 max)
    $stmtCount = $db->prepare("
        SELECT COUNT(*) FROM om_wishlist_items WHERE wishlist_id = ?
    ");
    $stmtCount->execute([$wishlistId]);

    if ((int)$stmtCount->fetchColumn() >= 100) {
        response(false, null, "Limite maximo de itens na lista atingido (100)", 400);
    }

    // Add item using ON CONFLICT to prevent race condition duplicates
    $stmt = $db->prepare("
        INSERT INTO om_wishlist_items (wishlist_id, product_id, partner_id)
        VALUES (?, ?, ?)
        ON CONFLICT (wishlist_id, product_id) DO NOTHING
    ");
    $stmt->execute([$wishlistId, $productId, $partnerId]);

    if ($stmt->rowCount() === 0) {
        response(true, ['already_exists' => true], "Produto ja esta na lista");
        return;
    }

    response(true, [
        'item_id' => (int)$db->lastInsertId(),
        'wishlist_id' => $wishlistId,
        'product_id' => $productId,
    ], "Adicionado a lista");
}

/**
 * DELETE - Remove item from wishlist
 */
function handleRemoveItem(PDO $db, int $customerId): void {
    $wishlistId = (int)($_GET['wishlist_id'] ?? 0);
    $productId = (int)($_GET['product_id'] ?? 0);

    // If no wishlist specified, remove from all wishlists
    if (!$wishlistId && $productId) {
        $stmt = $db->prepare("
            DELETE FROM om_wishlist_items
            USING om_customer_wishlists w
            WHERE om_wishlist_items.wishlist_id = w.id
              AND w.customer_id = ? AND om_wishlist_items.product_id = ?
        ");
        $stmt->execute([$customerId, $productId]);

        response(true, [
            'removed_count' => $stmt->rowCount(),
        ], "Removido das listas");
        return;
    }

    if (!$wishlistId || !$productId) {
        response(false, null, "wishlist_id e product_id sao obrigatorios", 400);
    }

    // Verify wishlist ownership
    $stmtCheck = $db->prepare("
        SELECT id FROM om_customer_wishlists
        WHERE id = ? AND customer_id = ?
    ");
    $stmtCheck->execute([$wishlistId, $customerId]);

    if (!$stmtCheck->fetch()) {
        response(false, null, "Lista nao encontrada", 404);
    }

    // Delete item
    $stmt = $db->prepare("
        DELETE FROM om_wishlist_items
        WHERE wishlist_id = ? AND product_id = ?
    ");
    $stmt->execute([$wishlistId, $productId]);

    if ($stmt->rowCount() === 0) {
        response(false, null, "Item nao encontrado na lista", 404);
    }

    response(true, null, "Removido da lista");
}

/**
 * Get or create default wishlist for customer
 */
function getOrCreateDefaultWishlist(PDO $db, int $customerId): int {
    $stmt = $db->prepare("
        SELECT id FROM om_customer_wishlists
        WHERE customer_id = ? AND is_default = 1
    ");
    $stmt->execute([$customerId]);
    $row = $stmt->fetch();

    if ($row) {
        return (int)$row['id'];
    }

    $db->prepare("
        INSERT INTO om_customer_wishlists (customer_id, name, is_default)
        VALUES (?, 'Favoritos', 1)
    ")->execute([$customerId]);

    return (int)$db->lastInsertId();
}

/**
 * Get authenticated customer ID from Bearer token
 * Validates token signature using HMAC-SHA256
 */
function getAuthenticatedCustomerId(): ?int {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        return null;
    }

    $token = $matches[1];

    // Decode token (base64.signature format)
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }

    $payloadBase64 = $parts[0];
    $receivedSignature = $parts[1];

    // Get JWT secret from environment
    $jwtSecret = $_ENV['JWT_SECRET'] ?? '';
    if (empty($jwtSecret)) {
        error_log("[wishlist-items] JWT_SECRET not configured");
        return null;
    }

    // Verify signature using HMAC-SHA256 with timing-safe comparison
    $expectedSignature = hash_hmac('sha256', $payloadBase64, $jwtSecret);
    if (!hash_equals($expectedSignature, $receivedSignature)) {
        error_log("[wishlist-items] Invalid token signature");
        return null;
    }

    $payload = json_decode(base64_decode($payloadBase64), true);

    if (!$payload || ($payload['type'] ?? '') !== 'customer') {
        return null;
    }

    // Check expiration
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null;
    }

    return (int)($payload['uid'] ?? 0) ?: null;
}
