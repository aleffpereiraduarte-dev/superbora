<?php
/**
 * GET  /api/mercado/partner/reviews.php  - List reviews for partner's store
 * POST /api/mercado/partner/reviews.php  - Respond to a review
 *
 * GET params: page, limit, filter (all|positive|negative), sort (newest|oldest|highest|lowest)
 * POST body: { review_id, response }
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

    $partnerId = (int)$payload['uid'];

    // Table om_market_order_reviews must exist (created via migration)

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        handleGetReviews($db, $partnerId);
    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        handleRespondToReview($db, $partnerId);
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[partner/reviews] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

function handleGetReviews($db, $partnerId) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $filter = trim($_GET['filter'] ?? 'all');
    $sort = trim($_GET['sort'] ?? 'newest');

    // Base where clause
    $where = ["r.partner_id = ?"];
    $params = [$partnerId];

    // Filter by rating
    if ($filter === 'positive') {
        $where[] = "r.rating >= 4";
    } elseif ($filter === 'negative') {
        $where[] = "r.rating <= 3";
    }

    $whereSQL = implode(" AND ", $where);

    // Sort
    $orderBy = "r.created_at DESC";
    switch ($sort) {
        case 'oldest':
            $orderBy = "r.created_at ASC";
            break;
        case 'highest':
            $orderBy = "r.rating DESC, r.created_at DESC";
            break;
        case 'lowest':
            $orderBy = "r.rating ASC, r.created_at DESC";
            break;
    }

    // Count total
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_order_reviews r WHERE {$whereSQL}");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    // Get reviews with order and customer info
    $stmt = $db->prepare("
        SELECT
            r.id as review_id,
            r.order_id,
            r.customer_id,
            r.rating,
            r.comment,
            r.response as partner_response,
            r.responded_at as partner_response_at,
            r.created_at,
            o.order_number,
            o.total as order_total,
            o.customer_name
        FROM om_market_order_reviews r
        LEFT JOIN om_market_orders o ON r.order_id = o.order_id
        WHERE {$whereSQL}
        ORDER BY {$orderBy}
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));
    $reviews = $stmt->fetchAll();

    // Get aggregate stats
    $stmtStats = $db->prepare("
        SELECT
            COUNT(*) as total_reviews,
            COALESCE(AVG(rating), 0) as avg_rating,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as stars_5,
            SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as stars_4,
            SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as stars_3,
            SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as stars_2,
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as stars_1,
            SUM(CASE WHEN response IS NOT NULL THEN 1 ELSE 0 END) as responded
        FROM om_market_order_reviews
        WHERE partner_id = ?
    ");
    $stmtStats->execute([$partnerId]);
    $stats = $stmtStats->fetch();

    $items = [];
    foreach ($reviews as $row) {
        $customerName = $row['customer_name'] ?? 'Cliente';
        // Generate initials
        $nameParts = explode(' ', trim($customerName));
        $initials = strtoupper(substr($nameParts[0], 0, 1));
        if (count($nameParts) > 1) {
            $initials .= strtoupper(substr(end($nameParts), 0, 1));
        }

        $items[] = [
            "review_id" => (int)$row['review_id'],
            "order_id" => (int)$row['order_id'],
            "order_number" => $row['order_number'] ?? ('P-' . $row['order_id']),
            "order_total" => (float)($row['order_total'] ?? 0),
            "customer_id" => (int)$row['customer_id'],
            "customer_name" => $customerName,
            "customer_initials" => $initials,
            "rating" => (int)$row['rating'],
            "comment" => $row['comment'],
            "partner_response" => $row['partner_response'],
            "partner_response_at" => $row['partner_response_at'],
            "created_at" => $row['created_at'],
        ];
    }

    $totalReviews = (int)($stats['total_reviews'] ?? 0);
    $pages = $totalReviews > 0 ? ceil($total / $limit) : 1;

    response(true, [
        "reviews" => $items,
        "stats" => [
            "total_reviews" => $totalReviews,
            "avg_rating" => round((float)($stats['avg_rating'] ?? 0), 1),
            "distribution" => [
                5 => (int)($stats['stars_5'] ?? 0),
                4 => (int)($stats['stars_4'] ?? 0),
                3 => (int)($stats['stars_3'] ?? 0),
                2 => (int)($stats['stars_2'] ?? 0),
                1 => (int)($stats['stars_1'] ?? 0),
            ],
            "responded" => (int)($stats['responded'] ?? 0),
            "pending_response" => $totalReviews - (int)($stats['responded'] ?? 0),
        ],
        "pagination" => [
            "total" => $total,
            "page" => $page,
            "pages" => (int)$pages,
            "limit" => $limit,
        ]
    ]);
}

function handleRespondToReview($db, $partnerId) {
    $input = getInput();
    $reviewId = (int)($input['review_id'] ?? 0);
    $responseText = trim($input['response'] ?? '');

    if (!$reviewId) {
        response(false, null, "review_id obrigatorio", 400);
    }

    if (empty($responseText)) {
        response(false, null, "Resposta nao pode estar vazia", 400);
    }

    if (strlen($responseText) > 1000) {
        response(false, null, "Resposta deve ter no maximo 1000 caracteres", 400);
    }

    // Verify the review belongs to this partner
    $stmt = $db->prepare("SELECT id FROM om_market_order_reviews WHERE id = ? AND partner_id = ?");
    $stmt->execute([$reviewId, $partnerId]);
    if (!$stmt->fetch()) {
        response(false, null, "Avaliacao nao encontrada", 404);
    }

    // Update with partner response
    $stmt = $db->prepare("
        UPDATE om_market_order_reviews
        SET response = ?, responded_at = NOW()
        WHERE id = ? AND partner_id = ?
    ");
    $stmt->execute([$responseText, $reviewId, $partnerId]);

    response(true, [
        "review_id" => $reviewId,
        "partner_response" => $responseText,
        "message" => "Resposta enviada com sucesso"
    ]);
}
