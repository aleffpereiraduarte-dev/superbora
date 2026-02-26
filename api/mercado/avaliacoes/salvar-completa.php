<?php
/**
 * POST /api/mercado/avaliacoes/salvar-completa.php
 * Salvar avaliacao completa com ratings separados (comida, entrega, embalagem) + fotos
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    $customerId = getCustomerIdFromToken();
    if (!$customerId) response(false, null, 'Nao autorizado', 401);

    $input = json_decode(file_get_contents('php://input'), true);
    $orderId = (int)($input['order_id'] ?? 0);
    $ratingOverall = min(5, max(1, (int)($input['rating'] ?? 0)));
    $ratingFood = min(5, max(0, (int)($input['rating_food'] ?? 0)));
    $ratingDelivery = min(5, max(0, (int)($input['rating_delivery'] ?? 0)));
    $ratingPackaging = min(5, max(0, (int)($input['rating_packaging'] ?? 0)));
    $comment = strip_tags(trim(mb_substr($input['comment'] ?? '', 0, 1000)));
    $photos = $input['photos'] ?? []; // array of photo URLs
    // SECURITY: Validate photo URLs — only allow HTTPS or /uploads/ paths, max 5
    if (!is_array($photos)) $photos = [];
    $photos = array_slice($photos, 0, 5);
    $photos = array_filter($photos, function($url) {
        if (!is_string($url)) return false;
        if (str_starts_with($url, '/uploads/')) return true;
        if (preg_match('#^https?://#', $url) && !preg_match('#^(javascript|data|file):#i', $url)) {
            // Block internal/private IPs
            $host = parse_url($url, PHP_URL_HOST);
            if ($host && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP)) return false;
            return true;
        }
        return false;
    });
    $photos = array_values($photos);

    $tags = array_map('strip_tags', $input['tags'] ?? []); // ['rapido', 'boa_embalagem', 'saboroso']
    // SECURITY: Limit tags — max 10, max 50 chars each
    if (!is_array($tags)) $tags = [];
    $tags = array_slice($tags, 0, 10);
    $tags = array_map(function($t) { return mb_substr(trim($t), 0, 50); }, $tags);
    $tags = array_filter($tags, function($t) { return strlen($t) > 0; });
    $tags = array_values($tags);

    if (!$orderId || !$ratingOverall) {
        response(false, null, 'order_id e rating obrigatorios', 400);
    }

    // Verificar pedido
    $stmt = $db->prepare("SELECT order_id, partner_id, shopper_id, status FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) response(false, null, 'Pedido nao encontrado', 404);

    $delivered = ['entregue'];
    if (!in_array($order['status'], $delivered)) {
        response(false, null, 'Avaliacao so e permitida apos a entrega', 400);
    }

    // Verificar duplicata
    $dupStmt = $db->prepare("SELECT rating_id FROM om_market_ratings WHERE order_id = ? AND rater_id = ? AND rater_type = 'customer'");
    $dupStmt->execute([$orderId, $customerId]);
    if ($dupStmt->fetch()) {
        response(false, null, 'Voce ja avaliou este pedido', 409);
    }

    $db->beginTransaction();

    // Inserir avaliacao do parceiro
    $stmt = $db->prepare("INSERT INTO om_market_ratings
        (order_id, rated_type, rated_id, rater_type, rater_id, rating, rating_food, rating_delivery, rating_packaging, comment, tags, photos, is_public, created_at)
        VALUES (?, 'partner', ?, 'customer', ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW()) RETURNING rating_id");
    $stmt->execute([
        $orderId, $order['partner_id'], $customerId,
        $ratingOverall, $ratingFood ?: null, $ratingDelivery ?: null, $ratingPackaging ?: null,
        $comment ?: null, $tags ? implode(',', $tags) : null,
        $photos ? json_encode($photos) : null
    ]);
    $ratingId = (int)$stmt->fetchColumn();

    // Atualizar media do parceiro
    $avgStmt = $db->prepare("SELECT ROUND(AVG(rating), 2) FROM om_market_ratings WHERE rated_type = 'partner' AND rated_id = ? AND rating > 0");
    $avgStmt->execute([$order['partner_id']]);
    $avgRating = $avgStmt->fetchColumn();

    $db->prepare("UPDATE om_market_partners SET rating = ? WHERE partner_id = ?")
        ->execute([$avgRating, $order['partner_id']]);

    // Avaliar shopper se teve
    if ($order['shopper_id'] && $ratingDelivery) {
        $db->prepare("INSERT INTO om_market_ratings (order_id, rated_type, rated_id, rater_type, rater_id, rating, comment, is_public, created_at)
            VALUES (?, 'shopper', ?, 'customer', ?, ?, ?, 1, NOW())")
            ->execute([$orderId, $order['shopper_id'], $customerId, $ratingDelivery, null]);
    }

    // Atualizar rating no pedido
    $db->prepare("UPDATE om_market_orders SET rating = ?, customer_rating = ? WHERE order_id = ?")
        ->execute([$ratingOverall, $ratingOverall, $orderId]);

    $db->commit();

    response(true, [
        'rating_id' => $ratingId,
        'message' => 'Avaliacao salva com sucesso! Obrigado pelo feedback.',
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[AvaliacaoCompleta] " . $e->getMessage());
    response(false, null, 'Erro ao salvar avaliacao', 500);
}
