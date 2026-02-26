<?php
/**
 * POST /api/mercado/orders/rate.php
 * Customer rates a delivered order
 * Body: { order_id, rating (1-5), comment }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Require customer auth
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $orderId = (int)($input['order_id'] ?? 0);
    $rating = (int)($input['rating'] ?? 0);
    $comment = strip_tags(trim(substr($input['comment'] ?? '', 0, 1000)));
    $photoBase64 = $input['photo'] ?? null;

    if (!$orderId) response(false, null, "ID do pedido obrigatorio", 400);
    if ($rating < 1 || $rating > 5) response(false, null, "Nota deve ser de 1 a 5", 400);

    // Verify order belongs to customer and is delivered
    $stmtOrder = $db->prepare("
        SELECT order_id, partner_id, status
        FROM om_market_orders
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmtOrder->execute([$orderId, $customerId]);
    $order = $stmtOrder->fetch();

    if (!$order) response(false, null, "Pedido nao encontrado", 404);
    if ($order['status'] !== 'entregue') {
        response(false, null, "Apenas pedidos entregues podem ser avaliados", 400);
    }

    $partnerId = (int)$order['partner_id'];

    // Process photo upload (base64)
    $photoUrl = null;
    if ($photoBase64 && is_string($photoBase64)) {
        // Remove data URI prefix if present
        $photoData = $photoBase64;
        if (preg_match('/^data:image\/(\w+);base64,/', $photoBase64, $matches)) {
            $ext = strtolower($matches[1]);
            if ($ext === 'jpeg') $ext = 'jpg';
            $photoData = substr($photoBase64, strpos($photoBase64, ',') + 1);
        } else {
            $ext = 'jpg';
        }

        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $ext = 'jpg';
        }

        $decoded = base64_decode($photoData, true);
        if ($decoded && strlen($decoded) <= 5 * 1024 * 1024) { // Max 5MB
            // Validate MIME type of decoded content
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($decoded);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array($mimeType, $allowedMimes, true)) {
                // Not a valid image — skip upload silently
                $decoded = null;
            }
        }
        if ($decoded) {
            $uploadDir = dirname(__DIR__, 3) . '/uploads/reviews/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $filename = 'review_' . $orderId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $filePath = $uploadDir . $filename;

            if (file_put_contents($filePath, $decoded)) {
                $photoUrl = '/uploads/reviews/' . $filename;
            }
        }
    }

    // Check if already rated
    $stmtCheck = $db->prepare("SELECT id FROM om_market_order_reviews WHERE order_id = ? AND customer_id = ?");
    $stmtCheck->execute([$orderId, $customerId]);
    if ($stmtCheck->fetch()) {
        response(false, null, "Voce ja avaliou este pedido", 400);
    }

    // Insert review
    $stmtInsert = $db->prepare("
        INSERT INTO om_market_order_reviews (order_id, customer_id, partner_id, rating, comment, photo, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmtInsert->execute([$orderId, $customerId, $partnerId, $rating, $comment ?: null, $photoUrl]);
    $reviewId = (int)$db->lastInsertId();

    // Update partner average rating
    try {
        $stmtAvg = $db->prepare("
            SELECT AVG(rating) as avg_rating, COUNT(*) as review_count
            FROM om_market_order_reviews
            WHERE partner_id = ?
        ");
        $stmtAvg->execute([$partnerId]);
        $avgData = $stmtAvg->fetch();

        if ($avgData) {
            $avgRating = round((float)$avgData['avg_rating'], 2);
            $reviewCount = (int)$avgData['review_count'];

            // Try to update avg_rating column if it exists
            try {
                $db->prepare("UPDATE om_market_partners SET avg_rating = ?, review_count = ? WHERE partner_id = ?")
                    ->execute([$avgRating, $reviewCount, $partnerId]);
            } catch (Exception $e) {
                // Column may not exist, try with just rating
                try {
                    $db->prepare("UPDATE om_market_partners SET avg_rating = ? WHERE partner_id = ?")
                        ->execute([$avgRating, $partnerId]);
                } catch (Exception $e2) {
                    // Column doesn't exist at all, skip silently
                }
            }
        }
    } catch (Exception $e) {
        // Non-critical, skip
    }

    response(true, [
        "review_id" => $reviewId,
        "rating" => $rating,
        "comment" => $comment,
        "photo" => $photoUrl,
    ], "Avaliacao enviada com sucesso!");

} catch (Exception $e) {
    error_log("[API Order Rate] Erro: " . $e->getMessage());
    response(false, null, "Erro ao enviar avaliacao", 500);
}
