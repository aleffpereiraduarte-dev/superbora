<?php
/**
 * Wishlists API - Customer Wishlists/Collections Management
 *
 * GET /api/mercado/vitrine/wishlists.php - List user's wishlists
 * POST /api/mercado/vitrine/wishlists.php - Create a new wishlist
 * PUT /api/mercado/vitrine/wishlists.php - Update wishlist (rename)
 * DELETE /api/mercado/vitrine/wishlists.php?id=X - Delete a wishlist
 *
 * Requires authentication via Bearer token
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Get authenticated customer (signature-verified)
    $customerId = getAuthenticatedCustomerId();

    if (!$customerId) {
        response(false, null, "Autenticacao necessaria", 401);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            handleGetWishlists($db, $customerId);
            break;
        case 'POST':
            handleCreateWishlist($db, $customerId);
            break;
        case 'PUT':
            handleUpdateWishlist($db, $customerId);
            break;
        case 'DELETE':
            handleDeleteWishlist($db, $customerId);
            break;
        default:
            response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[vitrine/wishlists] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar wishlists", 500);
}

/**
 * GET - List all wishlists for a customer with item counts
 */
function handleGetWishlists(PDO $db, int $customerId): void {
    // Ensure customer has at least one default wishlist
    ensureDefaultWishlist($db, $customerId);

    $stmt = $db->prepare("
        SELECT
            w.id,
            w.name,
            w.is_default,
            w.created_at,
            COUNT(wi.id) as item_count,
            (
                SELECT p.image
                FROM om_wishlist_items wi2
                JOIN om_market_products p ON wi2.product_id = p.product_id
                WHERE wi2.wishlist_id = w.id
                ORDER BY wi2.added_at DESC
                LIMIT 1
            ) as preview_image
        FROM om_customer_wishlists w
        LEFT JOIN om_wishlist_items wi ON w.id = wi.wishlist_id
        WHERE w.customer_id = ?
        GROUP BY w.id, w.name, w.is_default, w.created_at
        ORDER BY w.is_default DESC, w.created_at DESC
    ");
    $stmt->execute([$customerId]);
    $wishlists = $stmt->fetchAll();

    $result = [];
    foreach ($wishlists as $row) {
        $result[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'is_default' => (bool)$row['is_default'],
            'item_count' => (int)$row['item_count'],
            'preview_image' => $row['preview_image'],
            'created_at' => $row['created_at'],
        ];
    }

    response(true, [
        'wishlists' => $result,
        'total' => count($result),
    ]);
}

/**
 * POST - Create a new wishlist
 */
function handleCreateWishlist(PDO $db, int $customerId): void {
    $input = getInput();
    $name = trim($input['name'] ?? '');

    if (empty($name)) {
        response(false, null, "Nome da lista e obrigatorio", 400);
    }

    if (mb_strlen($name) > 100) {
        response(false, null, "Nome muito longo (max 100 caracteres)", 400);
    }

    // Check for duplicate names
    $stmtCheck = $db->prepare("
        SELECT id FROM om_customer_wishlists
        WHERE customer_id = ? AND name = ?
    ");
    $stmtCheck->execute([$customerId, $name]);

    if ($stmtCheck->fetch()) {
        response(false, null, "Ja existe uma lista com esse nome", 400);
    }

    // Limit to 20 wishlists per customer
    $stmtCount = $db->prepare("
        SELECT COUNT(*) as total FROM om_customer_wishlists WHERE customer_id = ?
    ");
    $stmtCount->execute([$customerId]);
    $count = (int)$stmtCount->fetchColumn();

    if ($count >= 20) {
        response(false, null, "Limite maximo de listas atingido (20)", 400);
    }

    $stmt = $db->prepare("
        INSERT INTO om_customer_wishlists (customer_id, name, is_default)
        VALUES (?, ?, 0)
    ");
    $stmt->execute([$customerId, $name]);

    $wishlistId = (int)$db->lastInsertId();

    response(true, [
        'wishlist' => [
            'id' => $wishlistId,
            'name' => $name,
            'is_default' => false,
            'item_count' => 0,
            'preview_image' => null,
        ],
    ], "Lista criada com sucesso");
}

/**
 * PUT - Update wishlist (rename)
 */
function handleUpdateWishlist(PDO $db, int $customerId): void {
    $input = getInput();
    $wishlistId = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');

    if (!$wishlistId) {
        response(false, null, "ID da lista e obrigatorio", 400);
    }

    if (empty($name)) {
        response(false, null, "Nome da lista e obrigatorio", 400);
    }

    if (mb_strlen($name) > 100) {
        response(false, null, "Nome muito longo (max 100 caracteres)", 400);
    }

    // Verify ownership
    $stmtCheck = $db->prepare("
        SELECT id, is_default FROM om_customer_wishlists
        WHERE id = ? AND customer_id = ?
    ");
    $stmtCheck->execute([$wishlistId, $customerId]);
    $wishlist = $stmtCheck->fetch();

    if (!$wishlist) {
        response(false, null, "Lista nao encontrada", 404);
    }

    // Check for duplicate names (excluding current)
    $stmtDup = $db->prepare("
        SELECT id FROM om_customer_wishlists
        WHERE customer_id = ? AND name = ? AND id != ?
    ");
    $stmtDup->execute([$customerId, $name, $wishlistId]);

    if ($stmtDup->fetch()) {
        response(false, null, "Ja existe uma lista com esse nome", 400);
    }

    $stmt = $db->prepare("
        UPDATE om_customer_wishlists SET name = ? WHERE id = ?
    ");
    $stmt->execute([$name, $wishlistId]);

    response(true, [
        'wishlist' => [
            'id' => $wishlistId,
            'name' => $name,
        ],
    ], "Lista atualizada");
}

/**
 * DELETE - Delete a wishlist (cannot delete default)
 */
function handleDeleteWishlist(PDO $db, int $customerId): void {
    $wishlistId = (int)($_GET['id'] ?? 0);

    if (!$wishlistId) {
        response(false, null, "ID da lista e obrigatorio", 400);
    }

    // Verify ownership and check if default
    $stmtCheck = $db->prepare("
        SELECT id, is_default FROM om_customer_wishlists
        WHERE id = ? AND customer_id = ?
    ");
    $stmtCheck->execute([$wishlistId, $customerId]);
    $wishlist = $stmtCheck->fetch();

    if (!$wishlist) {
        response(false, null, "Lista nao encontrada", 404);
    }

    if ($wishlist['is_default']) {
        response(false, null, "Nao e possivel excluir a lista padrao", 400);
    }

    // Delete wishlist (items will be deleted by cascade if FK exists, or manually)
    $db->prepare("DELETE FROM om_wishlist_items WHERE wishlist_id = ?")->execute([$wishlistId]);
    $db->prepare("DELETE FROM om_customer_wishlists WHERE id = ?")->execute([$wishlistId]);

    response(true, null, "Lista excluida com sucesso");
}

/**
 * Ensure customer has a default wishlist
 */
function ensureDefaultWishlist(PDO $db, int $customerId): void {
    $stmt = $db->prepare("
        SELECT id FROM om_customer_wishlists
        WHERE customer_id = ? AND is_default = 1
    ");
    $stmt->execute([$customerId]);

    if (!$stmt->fetch()) {
        $db->prepare("
            INSERT INTO om_customer_wishlists (customer_id, name, is_default)
            VALUES (?, 'Favoritos', 1)
        ")->execute([$customerId]);
    }
}

/**
 * Get authenticated customer ID from Bearer token (signature-verified)
 */
function getAuthenticatedCustomerId(): ?int {
    $token = om_auth()->getTokenFromRequest();
    if (!$token) return null;

    $payload = om_auth()->validateToken($token);
    if (!$payload || ($payload['type'] ?? '') !== 'customer') {
        return null;
    }

    return (int)($payload['uid'] ?? 0) ?: null;
}
