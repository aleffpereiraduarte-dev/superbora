<?php
/**
 * GET /api/mercado/vitrine/store-reviews.php
 *
 * Parameters:
 *   partner_id (required) - Store ID
 *   stats=1 - Return only stats (avg rating, count by stars)
 *   page - Page number (default 1)
 *   limit - Items per page (default 10, max 20)
 *   sort - Sort order: recent (default), highest, lowest
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

header('Cache-Control: public, max-age=120');

try {
    $db = getDB();

    $partnerId = (int)($_GET['partner_id'] ?? 0);
    $statsOnly = isset($_GET['stats']) && $_GET['stats'] === '1';
    $page = max(1, (int)($_GET['page'] ?? 1));
    // SECURITY: Enforce pagination limits to prevent excessive data retrieval
    // Max 50 items per page, default 10
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
    // Max 1000 pages to prevent offset-based DoS attacks
    $page = min(1000, $page);
    $sort = $_GET['sort'] ?? 'recent';
    // Validate sort parameter to prevent SQL injection
    if (!in_array($sort, ['recent', 'highest', 'lowest'])) {
        $sort = 'recent';
    }
    $offset = ($page - 1) * $limit;
    // Max offset of 50000 records
    if ($offset > 50000) {
        response(false, null, "Pagina muito alta. Maximo 1000 paginas.", 400);
    }

    if (!$partnerId) {
        response(false, null, "partner_id obrigatorio", 400);
    }

    // Tables should already exist in PostgreSQL - skip CREATE TABLE for performance
    // PostgreSQL equivalent tables created via migration

    // Get stats (always needed)
    $stmtStats = $db->prepare("
        SELECT
            COUNT(*) as total_count,
            COALESCE(AVG(rating), 0) as avg_rating,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as stars_5,
            SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as stars_4,
            SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as stars_3,
            SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as stars_2,
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as stars_1
        FROM om_market_order_reviews
        WHERE partner_id = ?
    ");
    $stmtStats->execute([$partnerId]);
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

    // If only stats requested
    if ($statsOnly) {
        response(true, ['stats' => $stats]);
    }

    // Determine sort order
    switch ($sort) {
        case 'highest':
            $orderBy = 'r.rating DESC, r.created_at DESC';
            break;
        case 'lowest':
            $orderBy = 'r.rating ASC, r.created_at DESC';
            break;
        default:
            $orderBy = 'r.created_at DESC';
    }

    // Get reviews with customer info
    $stmtReviews = $db->prepare("
        SELECT
            r.id,
            r.order_id,
            r.rating,
            r.comment,
            r.created_at,
            COALESCE(c.firstname, om.name, 'Cliente') as customer_name,
            r.customer_id
        FROM om_market_order_reviews r
        LEFT JOIN oc_customer c ON r.customer_id = c.customer_id
        LEFT JOIN om_customers om ON r.customer_id = om.customer_id
        WHERE r.partner_id = ?
        ORDER BY {$orderBy}
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
    ");
    $stmtReviews->execute([$partnerId]);
    $reviewsRaw = $stmtReviews->fetchAll();

    // Get review IDs and order IDs for photo lookup
    $reviewIds = array_column($reviewsRaw, 'id');
    $orderIds = array_column($reviewsRaw, 'order_id');
    $photosMap = [];
    $photosByOrder = [];

    if (!empty($reviewIds)) {
        // Fetch photos by review_id
        try {
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
        } catch (Exception $e) {
            // Table may not exist yet — skip photo loading
            error_log("[store-reviews] Photo query failed: " . $e->getMessage());
        }
    }

    // Format reviews
    $reviews = [];
    foreach ($reviewsRaw as $row) {
        $reviewId = (int)$row['id'];

        // Anonymize customer name: first 3 chars + ***
        $name = $row['customer_name'] ?? 'Cliente';
        $anonName = mb_substr($name, 0, 3) . '***';

        // Collect photos from om_review_photos table (by review_id or order_id)
        $orderId = (int)$row['order_id'];
        $photos = [];
        if (isset($photosMap[$reviewId])) {
            $photos = $photosMap[$reviewId];
        } elseif (isset($photosByOrder[$orderId])) {
            $photos = $photosByOrder[$orderId];
        }

        // Generate avatar URL from customer name
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

    // Pagination info
    $totalPages = $stats['total_count'] > 0 ? ceil($stats['total_count'] / $limit) : 1;
    $hasMore = $page < $totalPages;

    response(true, [
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
    error_log("[vitrine/store-reviews] Erro: " . $e->getMessage());
    response(false, null, "Erro ao carregar avaliacoes", 500);
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
