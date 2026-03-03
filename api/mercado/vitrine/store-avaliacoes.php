<?php
/**
 * Store Reviews & Ratings API - Public endpoint for viewing store reviews
 * GET /vitrine/store-avaliacoes.php?store_id=X - Reviews for a store with stats
 * GET /vitrine/store-avaliacoes.php?store_id=X&product_id=Y - Product-specific reviews
 */

require_once __DIR__ . '/../config/database.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Método não permitido', 405);
}

$db = getDB();
$storeId = (int)($_GET['store_id'] ?? 0);
$productId = (int)($_GET['product_id'] ?? 0);
$rating = (int)($_GET['rating'] ?? 0); // filter by specific star rating
$sort = $_GET['sort'] ?? 'recent'; // recent, highest, lowest, helpful
$limit = min((int)($_GET['limit'] ?? 20), 50);
$offset = (int)($_GET['offset'] ?? 0);

if (!$storeId) {
    response(false, null, 'store_id obrigatório', 400);
}

// Get aggregated stats
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total_reviews,
        COALESCE(AVG(rating), 0) as avg_rating,
        COUNT(CASE WHEN rating = 5 THEN 1 END) as stars_5,
        COUNT(CASE WHEN rating = 4 THEN 1 END) as stars_4,
        COUNT(CASE WHEN rating = 3 THEN 1 END) as stars_3,
        COUNT(CASE WHEN rating = 2 THEN 1 END) as stars_2,
        COUNT(CASE WHEN rating = 1 THEN 1 END) as stars_1,
        COUNT(CASE WHEN comment IS NOT NULL AND comment != '' THEN 1 END) as with_comments,
        COUNT(CASE WHEN photo_url IS NOT NULL THEN 1 END) as with_photos
    FROM om_market_reviews
    WHERE store_id = ?
");
$stmt->execute([$storeId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['avg_rating'] = round((float)$stats['avg_rating'], 1);

// Calculate distribution percentages
$total = max((int)$stats['total_reviews'], 1);
$stats['distribution'] = [
    ['stars' => 5, 'count' => (int)$stats['stars_5'], 'pct' => round($stats['stars_5'] / $total * 100)],
    ['stars' => 4, 'count' => (int)$stats['stars_4'], 'pct' => round($stats['stars_4'] / $total * 100)],
    ['stars' => 3, 'count' => (int)$stats['stars_3'], 'pct' => round($stats['stars_3'] / $total * 100)],
    ['stars' => 2, 'count' => (int)$stats['stars_2'], 'pct' => round($stats['stars_2'] / $total * 100)],
    ['stars' => 1, 'count' => (int)$stats['stars_1'], 'pct' => round($stats['stars_1'] / $total * 100)],
];

// Build review query
$conditions = ["r.store_id = ?"];
$params = [$storeId];

if ($productId) {
    $conditions[] = "r.product_id = ?";
    $params[] = $productId;
}

if ($rating >= 1 && $rating <= 5) {
    $conditions[] = "r.rating = ?";
    $params[] = $rating;
}

$where = implode(' AND ', $conditions);

$orderBy = match($sort) {
    'highest' => 'r.rating DESC, r.created_at DESC',
    'lowest' => 'r.rating ASC, r.created_at DESC',
    'helpful' => 'r.helpful_count DESC NULLS LAST, r.created_at DESC',
    default => 'r.created_at DESC',
};

$stmt = $db->prepare("
    SELECT r.id, r.order_id, r.rating, r.comment, r.photo_url,
           r.partner_response, r.partner_response_at,
           r.helpful_count, r.created_at,
           c.name as customer_name,
           CASE WHEN r.is_anonymous THEN 'Anônimo' ELSE
               CONCAT(SPLIT_PART(c.name, ' ', 1), ' ', LEFT(SPLIT_PART(c.name, ' ', 2), 1), '.')
           END as display_name,
           p.name as product_name
    FROM om_market_reviews r
    LEFT JOIN om_market_customers c ON c.id = r.customer_id
    LEFT JOIN om_market_products p ON p.id = r.product_id
    WHERE {$where}
    ORDER BY {$orderBy}
    LIMIT ? OFFSET ?
");
$params[] = $limit;
$params[] = $offset;
$stmt->execute($params);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get review highlights (most common positive/negative phrases)
$highlights = [];
if ((int)$stats['total_reviews'] >= 5) {
    // Positive highlights from 4-5 star reviews
    $stmt = $db->prepare("
        SELECT comment FROM om_market_reviews
        WHERE store_id = ? AND rating >= 4 AND comment IS NOT NULL AND comment != ''
        ORDER BY created_at DESC LIMIT 20
    ");
    $stmt->execute([$storeId]);
    $positiveComments = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $commonPositive = ['rápido', 'ótimo', 'excelente', 'fresco', 'qualidade', 'bom atendimento', 'recomendo', 'delicioso'];
    $positiveHighlights = [];
    foreach ($commonPositive as $word) {
        $count = 0;
        foreach ($positiveComments as $c) {
            if (stripos($c, $word) !== false) $count++;
        }
        if ($count >= 2) {
            $positiveHighlights[] = ['text' => ucfirst($word), 'count' => $count, 'type' => 'positive'];
        }
    }
    $highlights = array_slice($positiveHighlights, 0, 5);
}

response(true, [
    'stats' => $stats,
    'reviews' => $reviews,
    'highlights' => $highlights,
    'total' => (int)$stats['total_reviews'],
    'limit' => $limit,
    'offset' => $offset
]);
