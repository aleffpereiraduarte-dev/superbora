<?php
/**
 * GET /api/mercado/vitrine/product-reviews.php
 *
 * Parameters:
 *   product_id (required) - Product ID
 *   page - Page number (default 1)
 *   limit - Items per page (default 10, max 20)
 *   sort - Sort order: recent (default), highest, lowest
 *
 * Returns reviews that mention this product in their order
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

header('Cache-Control: public, max-age=120');

try {
    $db = getDB();

    $productId = (int)($_GET['product_id'] ?? 0);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));
    $sort = $_GET['sort'] ?? 'recent';
    $offset = ($page - 1) * $limit;

    if (!$productId) {
        response(false, null, "product_id obrigatorio", 400);
    }

    // Tables om_market_order_reviews, om_review_photos created via migration
    $db->exec("CREATE INDEX IF NOT EXISTS idx_review_photos_review_id ON om_review_photos (review_id)");

    // Get product info
    $stmtProduct = $db->prepare("
        SELECT product_id, name, image, partner_id
        FROM om_market_products
        WHERE product_id = ?
    ");
    $stmtProduct->execute([$productId]);
    $product = $stmtProduct->fetch();

    if (!$product) {
        response(false, null, "Produto nao encontrado", 404);
    }

    // Determine sort order
    $orderBy = match($sort) {
        'highest' => 'r.rating DESC, r.created_at DESC',
        'lowest' => 'r.rating ASC, r.created_at DESC',
        default => 'r.created_at DESC',
    };

    // Get reviews for orders that contain this product
    // We join with om_market_order_items to find orders that had this product
    $stmtStats = $db->prepare("
        SELECT
            COUNT(DISTINCT r.id) as total_count,
            COALESCE(AVG(r.rating), 0) as avg_rating,
            SUM(CASE WHEN r.rating = 5 THEN 1 ELSE 0 END) as stars_5,
            SUM(CASE WHEN r.rating = 4 THEN 1 ELSE 0 END) as stars_4,
            SUM(CASE WHEN r.rating = 3 THEN 1 ELSE 0 END) as stars_3,
            SUM(CASE WHEN r.rating = 2 THEN 1 ELSE 0 END) as stars_2,
            SUM(CASE WHEN r.rating = 1 THEN 1 ELSE 0 END) as stars_1
        FROM om_market_order_reviews r
        INNER JOIN om_market_order_items oi ON r.order_id = oi.order_id
        WHERE oi.product_id = ?
    ");
    $stmtStats->execute([$productId]);
    $statsRow = $stmtStats->fetch();

    $stats = [
        'total_count' => (int)($statsRow['total_count'] ?? 0),
        'avg_rating' => round((float)($statsRow['avg_rating'] ?? 0), 1),
        'distribution' => [
            5 => (int)($statsRow['stars_5'] ?? 0),
            4 => (int)($statsRow['stars_4'] ?? 0),
            3 => (int)($statsRow['stars_3'] ?? 0),
            2 => (int)($statsRow['stars_2'] ?? 0),
            1 => (int)($statsRow['stars_1'] ?? 0),
        ]
    ];

    // Get reviews
    $stmtReviews = $db->prepare("
        SELECT DISTINCT
            r.id,
            r.order_id,
            r.rating,
            r.comment,
            r.photo as main_photo,
            r.created_at,
            COALESCE(c.firstname, 'Cliente') as customer_name,
            c.customer_id
        FROM om_market_order_reviews r
        INNER JOIN om_market_order_items oi ON r.order_id = oi.order_id
        LEFT JOIN oc_customer c ON r.customer_id = c.customer_id
        WHERE oi.product_id = ?
        ORDER BY {$orderBy}
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
    ");
    $stmtReviews->execute([$productId]);
    $reviewsRaw = $stmtReviews->fetchAll();

    // Get review IDs for photo lookup
    $reviewIds = array_column($reviewsRaw, 'id');
    $photosMap = [];

    if (!empty($reviewIds)) {
        $placeholders = implode(',', array_fill(0, count($reviewIds), '?'));
        $stmtPhotos = $db->prepare("
            SELECT review_id, photo_url
            FROM om_review_photos
            WHERE review_id IN ({$placeholders})
            ORDER BY sort_order ASC
        ");
        $stmtPhotos->execute($reviewIds);

        foreach ($stmtPhotos->fetchAll() as $photo) {
            $rid = (int)$photo['review_id'];
            if (!isset($photosMap[$rid])) {
                $photosMap[$rid] = [];
            }
            $photosMap[$rid][] = $photo['photo_url'];
        }
    }

    // Format reviews
    $reviews = [];
    foreach ($reviewsRaw as $row) {
        $reviewId = (int)$row['id'];

        // Anonymize customer name
        $name = $row['customer_name'] ?? 'Cliente';
        $anonName = mb_substr($name, 0, 3) . '***';

        // Collect all photos
        $photos = [];
        if (!empty($row['main_photo'])) {
            $photos[] = $row['main_photo'];
        }
        if (isset($photosMap[$reviewId])) {
            $photos = array_merge($photos, $photosMap[$reviewId]);
        }
        $photos = array_unique($photos);

        // Generate avatar URL
        $avatar = 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=43A047&color=fff&size=80';

        $reviews[] = [
            'id' => $reviewId,
            'rating' => (int)$row['rating'],
            'comment' => $row['comment'] ?: null,
            'customer_name' => $anonName,
            'customer_avatar' => $avatar,
            'photos' => array_values($photos),
            'created_at' => $row['created_at'],
            'date_formatted' => formatDateRelative($row['created_at']),
        ];
    }

    // Pagination
    $totalPages = $stats['total_count'] > 0 ? ceil($stats['total_count'] / $limit) : 1;
    $hasMore = $page < $totalPages;

    response(true, [
        'product' => [
            'id' => (int)$product['product_id'],
            'name' => $product['name'],
            'image' => $product['image'],
        ],
        'stats' => $stats,
        'reviews' => $reviews,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $stats['total_count'],
            'total_pages' => $totalPages,
            'has_more' => $hasMore,
        ],
        'sort' => $sort,
    ]);

} catch (Exception $e) {
    error_log("[vitrine/product-reviews] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar avaliacoes do produto", 500);
}

/**
 * Format date relative to now (Portuguese)
 */
function formatDateRelative(string $date): string {
    $timestamp = strtotime($date);
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'agora';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins == 1 ? 'ha 1 minuto' : "ha {$mins} minutos";
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours == 1 ? 'ha 1 hora' : "ha {$hours} horas";
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days == 1 ? 'ha 1 dia' : "ha {$days} dias";
    } elseif ($diff < 2592000) {
        $weeks = floor($diff / 604800);
        return $weeks == 1 ? 'ha 1 semana' : "ha {$weeks} semanas";
    } elseif ($diff < 31536000) {
        $months = floor($diff / 2592000);
        return $months == 1 ? 'ha 1 mes' : "ha {$months} meses";
    } else {
        return date('d/m/Y', $timestamp);
    }
}
