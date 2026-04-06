<?php
/**
 * Product Review endpoint
 *
 * POST /api/mercado/customer/product-review.php
 *   Create/update a product review (requires auth, must have ordered the product)
 *   Body: { product_id, order_id (optional), rating (1-5), title, comment, photos[] }
 *
 * GET /api/mercado/customer/product-review.php?product_id=X&page=1&limit=10&sort=recent
 *   List reviews for a product (public, paginated)
 *   sort: recent (default), helpful, highest, lowest
 *   Returns: { reviews: [...], stats: { average, total, distribution } }
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    if ($method === 'POST') {
        handlePost($db);
    } elseif ($method === 'GET') {
        handleGet($db);
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[ProductReview] " . $e->getMessage());
    response(false, null, "Erro ao processar avaliacao", 500);
}

/**
 * POST - Create or update a product review
 */
function handlePost(PDO $db): void {
    $customerId = requireCustomerAuth();

    $input = getInput();
    $productId = (int)($input['product_id'] ?? 0);
    $orderId = !empty($input['order_id']) ? (int)$input['order_id'] : null;
    $rating = (int)($input['rating'] ?? 0);
    $title = strip_tags(trim(mb_substr($input['title'] ?? '', 0, 200)));
    $comment = strip_tags(trim(mb_substr($input['comment'] ?? '', 0, 2000)));
    $photos = $input['photos'] ?? [];

    // Validate required fields
    if (!$productId) {
        response(false, null, "product_id obrigatorio", 400);
    }
    if ($rating < 1 || $rating > 5) {
        response(false, null, "rating deve ser de 1 a 5", 400);
    }

    // Validate photos array
    if (!is_array($photos)) $photos = [];
    $photos = array_slice($photos, 0, 5);
    $photos = array_filter($photos, function($url) {
        if (!is_string($url)) return false;
        if (str_starts_with($url, '/uploads/')) return true;
        if (preg_match('#^https?://#', $url) && !preg_match('#^(javascript|data|file):#i', $url)) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP)) return false;
            return true;
        }
        return false;
    });
    $photos = array_values($photos);

    // Verify product exists
    $stmtProduct = $db->prepare("SELECT product_id, partner_id FROM om_market_products WHERE product_id = ?");
    $stmtProduct->execute([$productId]);
    $product = $stmtProduct->fetch();
    if (!$product) {
        response(false, null, "Produto nao encontrado", 404);
    }

    // Check if customer has ordered this product (verified purchase)
    $verifiedPurchase = false;
    if ($orderId) {
        // Verify specific order
        $stmtOrder = $db->prepare("
            SELECT o.order_id
            FROM om_market_orders o
            INNER JOIN om_market_order_items oi ON o.order_id = oi.order_id
            WHERE o.order_id = ? AND o.customer_id = ? AND oi.product_id = ?
              AND o.status IN ('entregue', 'retirado')
        ");
        $stmtOrder->execute([$orderId, $customerId, $productId]);
        if (!$stmtOrder->fetch()) {
            response(false, null, "Pedido nao encontrado ou produto nao faz parte deste pedido", 400);
        }
        $verifiedPurchase = true;
    } else {
        // Check any delivered order with this product
        $stmtAny = $db->prepare("
            SELECT o.order_id
            FROM om_market_orders o
            INNER JOIN om_market_order_items oi ON o.order_id = oi.order_id
            WHERE o.customer_id = ? AND oi.product_id = ?
              AND o.status IN ('entregue', 'retirado')
            ORDER BY o.created_at DESC
            LIMIT 1
        ");
        $stmtAny->execute([$customerId, $productId]);
        $anyOrder = $stmtAny->fetch();
        if ($anyOrder) {
            $orderId = (int)$anyOrder['order_id'];
            $verifiedPurchase = true;
        }
    }

    if (!$verifiedPurchase) {
        response(false, null, "Voce precisa ter comprado este produto para avaliar", 403);
    }

    // Convert photos to PostgreSQL array format
    $photosArray = null;
    if (!empty($photos)) {
        $photosArray = '{' . implode(',', array_map(function($p) {
            return '"' . str_replace('"', '\\"', $p) . '"';
        }, $photos)) . '}';
    }

    $db->beginTransaction();

    // Check for existing review (upsert)
    $stmtCheck = $db->prepare("
        SELECT id FROM om_product_reviews
        WHERE product_id = ? AND customer_id = ? AND order_id = ?
        FOR UPDATE
    ");
    $stmtCheck->execute([$productId, $customerId, $orderId]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        // Update existing review
        $stmtUpdate = $db->prepare("
            UPDATE om_product_reviews
            SET rating = ?, title = ?, comment = ?, photos = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmtUpdate->execute([
            $rating,
            $title ?: null,
            $comment ?: null,
            $photosArray,
            $existing['id']
        ]);
        $reviewId = (int)$existing['id'];
        $isUpdate = true;
    } else {
        // Insert new review
        $stmtInsert = $db->prepare("
            INSERT INTO om_product_reviews
                (product_id, customer_id, order_id, rating, title, comment, photos, verified_purchase, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            RETURNING id
        ");
        $stmtInsert->execute([
            $productId,
            $customerId,
            $orderId,
            $rating,
            $title ?: null,
            $comment ?: null,
            $photosArray,
            $verifiedPurchase ? 't' : 'f'
        ]);
        $reviewId = (int)$stmtInsert->fetchColumn();
        $isUpdate = false;
    }

    $db->commit();

    response(true, [
        'review_id' => $reviewId,
        'is_update' => $isUpdate,
    ], $isUpdate ? 'Avaliacao atualizada com sucesso!' : 'Avaliacao enviada com sucesso!');
}

/**
 * GET - List reviews for a product (public)
 */
function handleGet(PDO $db): void {
    header('Cache-Control: public, max-age=60');

    $productId = (int)($_GET['product_id'] ?? 0);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));
    $sort = $_GET['sort'] ?? 'recent';
    $offset = ($page - 1) * $limit;

    if (!$productId) {
        response(false, null, "product_id obrigatorio", 400);
    }

    // Check if current user has reviewed this product (if authenticated)
    $customerId = getCustomerIdFromToken();
    $userReview = null;
    $canReview = false;

    if ($customerId) {
        // Check if user has a delivered order with this product
        $stmtCanReview = $db->prepare("
            SELECT o.order_id
            FROM om_market_orders o
            INNER JOIN om_market_order_items oi ON o.order_id = oi.order_id
            WHERE o.customer_id = ? AND oi.product_id = ?
              AND o.status IN ('entregue', 'retirado')
            LIMIT 1
        ");
        $stmtCanReview->execute([$customerId, $productId]);
        $canReview = (bool)$stmtCanReview->fetch();

        // Get user's existing review
        $stmtUserReview = $db->prepare("
            SELECT id, rating, title, comment, photos, created_at
            FROM om_product_reviews
            WHERE product_id = ? AND customer_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmtUserReview->execute([$productId, $customerId]);
        $ur = $stmtUserReview->fetch();
        if ($ur) {
            $userReview = [
                'id' => (int)$ur['id'],
                'rating' => (int)$ur['rating'],
                'title' => $ur['title'],
                'comment' => $ur['comment'],
                'photos' => pgArrayToPhp($ur['photos']),
                'created_at' => $ur['created_at'],
            ];
        }
    }

    // Sort order
    $orderBy = match($sort) {
        'helpful' => 'r.helpful_count DESC, r.created_at DESC',
        'highest' => 'r.rating DESC, r.created_at DESC',
        'lowest' => 'r.rating ASC, r.created_at DESC',
        default => 'r.created_at DESC',
    };

    // Stats
    $stmtStats = $db->prepare("
        SELECT
            COUNT(*) as total,
            COALESCE(AVG(rating), 0) as average,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as r5,
            SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as r4,
            SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as r3,
            SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as r2,
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as r1
        FROM om_product_reviews
        WHERE product_id = ?
    ");
    $stmtStats->execute([$productId]);
    $statsRow = $stmtStats->fetch();

    $stats = [
        'average' => round((float)($statsRow['average'] ?? 0), 1),
        'total' => (int)($statsRow['total'] ?? 0),
        'distribution' => [
            '5' => (int)($statsRow['r5'] ?? 0),
            '4' => (int)($statsRow['r4'] ?? 0),
            '3' => (int)($statsRow['r3'] ?? 0),
            '2' => (int)($statsRow['r2'] ?? 0),
            '1' => (int)($statsRow['r1'] ?? 0),
        ]
    ];

    // Fetch reviews
    $stmtReviews = $db->prepare("
        SELECT
            r.id,
            r.rating,
            r.title,
            r.comment,
            r.photos,
            r.helpful_count,
            r.verified_purchase,
            r.created_at,
            r.customer_id,
            COALESCE(c.firstname, om.name, 'Cliente') as customer_name
        FROM om_product_reviews r
        LEFT JOIN oc_customer c ON r.customer_id = c.customer_id
        LEFT JOIN om_customers om ON r.customer_id = om.customer_id
        WHERE r.product_id = ?
        ORDER BY {$orderBy}
        LIMIT ? OFFSET ?
    ");
    $stmtReviews->execute([$productId, $limit, $offset]);

    $reviews = [];
    foreach ($stmtReviews->fetchAll() as $row) {
        $name = $row['customer_name'] ?? 'Cliente';
        $anonName = mb_substr($name, 0, 1) . '***';

        $photos = pgArrayToPhp($row['photos']);

        $reviews[] = [
            'id' => (int)$row['id'],
            'rating' => (int)$row['rating'],
            'title' => $row['title'],
            'comment' => $row['comment'],
            'customer_name' => $anonName,
            'photos' => $photos,
            'helpful_count' => (int)$row['helpful_count'],
            'verified' => (bool)$row['verified_purchase'],
            'created_at' => $row['created_at'],
            'date_formatted' => formatDateRelative($row['created_at']),
            'is_own' => $customerId && (int)$row['customer_id'] === $customerId,
        ];
    }

    $totalPages = $stats['total'] > 0 ? (int)ceil($stats['total'] / $limit) : 1;

    response(true, [
        'reviews' => $reviews,
        'stats' => $stats,
        'can_review' => $canReview,
        'user_review' => $userReview,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $stats['total'],
            'total_pages' => $totalPages,
            'has_more' => $page < $totalPages,
        ],
    ]);
}

/**
 * Parse PostgreSQL text[] array into PHP array
 */
function pgArrayToPhp(?string $pgArray): array {
    if (!$pgArray || $pgArray === '{}') return [];
    // Remove outer braces
    $inner = substr($pgArray, 1, -1);
    if (empty($inner)) return [];

    $result = [];
    $current = '';
    $inQuote = false;
    $len = strlen($inner);

    for ($i = 0; $i < $len; $i++) {
        $char = $inner[$i];
        if ($char === '"' && ($i === 0 || $inner[$i-1] !== '\\')) {
            $inQuote = !$inQuote;
        } elseif ($char === ',' && !$inQuote) {
            $result[] = trim($current, '"');
            $current = '';
        } else {
            $current .= $char;
        }
    }
    if ($current !== '') {
        $result[] = trim($current, '"');
    }

    return array_filter($result, fn($v) => $v !== '');
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
