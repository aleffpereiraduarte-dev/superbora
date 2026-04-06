<?php
/**
 * GET /api/mercado/orders/review-photos.php
 *
 * Public endpoint to list approved review photos for social proof.
 *
 * Query params:
 *   - partner_id: (required) partner/store ID
 *   - product_id: (optional) filter by product ID
 *   - page: (optional, default 1)
 *   - limit: (optional, default 20, max 50)
 *
 * Returns:
 *   { photos: [{photo_url, thumbnail_url, rating, customer_name, created_at, ...}], pagination: {...} }
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    $productId = (int)($_GET['product_id'] ?? 0);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    if (!$partnerId && !$productId) {
        response(false, null, "partner_id ou product_id obrigatorio", 400);
    }

    // Cache-friendly header for public endpoint
    header('Cache-Control: public, max-age=120');

    // Build query based on filters
    $whereClauses = ["rp.status = 'approved'"];
    $params = [];

    if ($partnerId) {
        $whereClauses[] = "o.partner_id = ?";
        $params[] = $partnerId;
    }

    if ($productId) {
        // Filter photos where the order contains this product
        $whereClauses[] = "EXISTS (
            SELECT 1 FROM om_market_order_items oi
            WHERE oi.order_id = rp.order_id AND oi.product_id = ?
        )";
        $params[] = $productId;
    }

    $whereSQL = implode(' AND ', $whereClauses);

    // Count total
    $countParams = $params;
    $stmtCount = $db->prepare("
        SELECT COUNT(*)
        FROM om_review_photos rp
        INNER JOIN om_market_orders o ON rp.order_id = o.order_id
        WHERE {$whereSQL}
    ");
    $stmtCount->execute($countParams);
    $total = (int)$stmtCount->fetchColumn();

    // Fetch photos with review context
    $queryParams = array_merge($params, [$limit, $offset]);
    $stmt = $db->prepare("
        SELECT
            rp.id,
            rp.photo_url,
            rp.thumbnail_url,
            rp.caption,
            rp.created_at,
            rp.order_id,
            r.rating,
            r.comment AS review_comment,
            COALESCE(
                SUBSTR(COALESCE(c.firstname, cm.name, 'Cliente'), 1, 1) || '***',
                'C***'
            ) AS customer_name
        FROM om_review_photos rp
        INNER JOIN om_market_orders o ON rp.order_id = o.order_id
        LEFT JOIN om_market_ratings r ON rp.review_id = r.rating_id
        LEFT JOIN oc_customer c ON rp.customer_id = c.customer_id
        LEFT JOIN om_customers cm ON rp.customer_id = cm.customer_id
        WHERE {$whereSQL}
        ORDER BY rp.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($queryParams);

    $photos = [];
    foreach ($stmt->fetchAll() as $row) {
        $photos[] = [
            'id' => (int)$row['id'],
            'photo_url' => $row['photo_url'],
            'thumbnail_url' => $row['thumbnail_url'],
            'caption' => $row['caption'],
            'rating' => $row['rating'] ? (int)$row['rating'] : null,
            'review_comment' => $row['review_comment'],
            'customer_name' => $row['customer_name'],
            'created_at' => $row['created_at'],
            'order_id' => (int)$row['order_id'],
        ];
    }

    $totalPages = $total > 0 ? (int)ceil($total / $limit) : 1;

    response(true, [
        'photos' => $photos,
        'total_photos' => $total,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_more' => $page < $totalPages,
        ],
    ]);

} catch (Exception $e) {
    error_log("[orders/review-photos] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar fotos de avaliacoes", 500);
}
