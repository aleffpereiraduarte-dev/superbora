<?php
/**
 * POST /api/mercado/reviews/upload-photo.php
 * Upload de foto para avaliacao
 * Body: { review_id, order_id, photo (base64) }
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
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Nao autorizado", 401);
    }

    $customerId = (int)$payload['uid'];
    $input = getInput();

    $reviewId = (int)($input['review_id'] ?? 0);
    $orderId = (int)($input['order_id'] ?? 0);
    $photoData = $input['photo'] ?? '';
    $caption = strip_tags(trim($input['caption'] ?? ''));

    if (!$orderId) {
        response(false, null, "ID do pedido obrigatorio", 400);
    }

    // Validate review_id belongs to the authenticated customer
    if ($reviewId) {
        $stmt = $db->prepare("
            SELECT id FROM om_market_reviews
            WHERE id = ? AND customer_id = ?
        ");
        $stmt->execute([$reviewId, $customerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Review nao encontrada", 404);
        }
    }

    if (empty($photoData)) {
        response(false, null, "Foto obrigatoria", 400);
    }

    // Verificar se pedido pertence ao cliente
    $stmt = $db->prepare("
        SELECT order_id FROM om_market_orders
        WHERE order_id = ? AND customer_id = ? AND status = 'entregue'
    ");
    $stmt->execute([$orderId, $customerId]);
    if (!$stmt->fetch()) {
        response(false, null, "Pedido nao encontrado ou nao entregue", 404);
    }

    // Verificar limite de fotos por review (max 5)
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM om_review_photos
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    if ($stmt->fetchColumn() >= 5) {
        response(false, null, "Limite de 5 fotos por pedido atingido", 400);
    }

    // Processar imagem base64
    if (preg_match('/^data:image\/(\w+);base64,/', $photoData, $matches)) {
        $imageType = $matches[1];
        $photoData = substr($photoData, strpos($photoData, ',') + 1);
    } else {
        $imageType = 'jpeg';
    }

    // SECURITY: Reject dangerous file types that can contain executable code (stored XSS)
    $dangerousTypes = ['svg', 'svg+xml', 'html', 'xhtml', 'xml'];
    if (in_array(strtolower($imageType), $dangerousTypes)) {
        response(false, null, "Tipo de arquivo nao permitido. Use JPEG, PNG ou WebP.", 400);
    }

    // Whitelist allowed image types
    $allowedTypes = ['jpeg', 'jpg', 'png', 'webp', 'gif'];
    if (!in_array(strtolower($imageType), $allowedTypes)) {
        response(false, null, "Formato de imagem nao suportado", 400);
    }

    $imageData = base64_decode($photoData);
    if ($imageData === false) {
        response(false, null, "Imagem invalida", 400);
    }

    // Limitar tamanho (5MB)
    if (strlen($imageData) > 5 * 1024 * 1024) {
        response(false, null, "Imagem muito grande (max 5MB)", 400);
    }

    // SECURITY: Validate actual MIME type via finfo (not client-reported type)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $actualMime = $finfo->buffer($imageData);
    $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($mimeMap[$actualMime])) {
        response(false, null, "Tipo de arquivo invalido", 400);
    }
    $imageType = $mimeMap[$actualMime];

    // SECURITY: Re-encode image via GD to strip malicious payloads (polyglot attacks, EXIF injection)
    $img = @imagecreatefromstring($imageData);
    if (!$img) {
        response(false, null, "Imagem corrompida ou invalida", 400);
    }

    // Resize if too large (max 1200px width)
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w > 1200) {
        $newW = 1200;
        $newH = (int)round($h * (1200 / $w));
        $resized = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($img);
        $img = $resized;
    }

    // Salvar arquivo
    $uploadDir = dirname(__DIR__, 3) . '/uploads/reviews/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0750, true);
    }

    $filename = 'review_' . $orderId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $imageType;
    $filepath = $uploadDir . $filename;

    $saved = false;
    switch ($imageType) {
        case 'jpg': $saved = imagejpeg($img, $filepath, 85); break;
        case 'png': $saved = imagepng($img, $filepath, 8); break;
        case 'webp': $saved = imagewebp($img, $filepath, 85); break;
        case 'gif': $saved = imagegif($img, $filepath); break;
    }
    imagedestroy($img);

    if (!$saved) {
        response(false, null, "Erro ao salvar imagem", 500);
    }

    $photoUrl = '/uploads/reviews/' . $filename;

    // Salvar no banco
    $stmt = $db->prepare("
        INSERT INTO om_review_photos
        (review_id, order_id, customer_id, photo_url, caption, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([$reviewId ?: null, $orderId, $customerId, $photoUrl, $caption ?: null]);

    // Atualizar contador na review se existir
    if ($reviewId) {
        $db->prepare("
            UPDATE om_market_reviews
            SET photo_count = photo_count + 1
            WHERE id = ?
        ")->execute([$reviewId]);
    }

    response(true, [
        'photo_id' => (int)$db->lastInsertId(),
        'photo_url' => $photoUrl,
        'message' => 'Foto enviada! Sera analisada antes de ser publicada.'
    ]);

} catch (Exception $e) {
    error_log("[reviews/upload-photo] Erro: " . $e->getMessage());
    response(false, null, "Erro ao enviar foto", 500);
}
