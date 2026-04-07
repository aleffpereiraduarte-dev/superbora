<?php
/**
 * POST /api/mercado/avaliacoes/upload-fotos.php
 *
 * Upload photos for an order review (multipart/form-data).
 * Accepts up to 3 photos (photo_0, photo_1, photo_2).
 * Awards R$2 cashback when at least 1 photo is uploaded with a review.
 *
 * Form Data:
 *   - order_id: (required) order ID
 *   - rating_id: (optional) rating ID from salvar-completa response
 *   - photo_0, photo_1, photo_2: image files (max 5MB each, jpg/png/webp)
 *
 * Returns:
 *   { photos: [{photo_id, photo_url, thumbnail_url}], cashback_credited: bool, cashback_amount: "R$ 2,00" }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once __DIR__ . "/../helpers/cashback.php";

setCorsHeaders();

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    $orderId = (int)($_POST['order_id'] ?? 0);
    $ratingId = (int)($_POST['rating_id'] ?? 0);

    if (!$orderId) {
        response(false, null, "order_id obrigatorio", 400);
    }

    // Verify order belongs to customer and is delivered
    $stmt = $db->prepare("
        SELECT order_id, partner_id, status, customer_id
        FROM om_market_orders
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    if (!in_array($order['status'], ['entregue', 'delivered', 'finalizado', 'retirado'])) {
        response(false, null, "Apenas pedidos entregues podem receber fotos", 400);
    }

    // Check existing photo count for this order
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM om_review_photos
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $existingCount = (int)$stmt->fetchColumn();

    if ($existingCount >= 3) {
        response(false, null, "Limite de 3 fotos por pedido atingido", 400);
    }

    $maxPhotos = 3 - $existingCount;

    // Allowed MIME types
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $validExtensions = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    // Create upload directory
    $uploadDir = dirname(__DIR__, 3) . '/uploads/reviews/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $thumbDir = $uploadDir . 'thumbs/';
    if (!is_dir($thumbDir)) {
        mkdir($thumbDir, 0755, true);
    }

    $uploadedPhotos = [];
    $photoKeys = ['photo_0', 'photo_1', 'photo_2'];
    $processedCount = 0;

    foreach ($photoKeys as $key) {
        if ($processedCount >= $maxPhotos) break;
        if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) continue;

        $file = $_FILES[$key];
        $tmpPath = $file['tmp_name'];
        $originalName = $file['name'];
        $fileSize = $file['size'];

        // Validate file size (max 5MB)
        if ($fileSize > 5 * 1024 * 1024) {
            error_log("[upload-fotos] File too large: {$originalName} ({$fileSize} bytes)");
            continue;
        }

        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);

        if (!isset($allowedMimes[$mimeType])) {
            error_log("[upload-fotos] Invalid MIME type: {$mimeType} for {$originalName}");
            continue;
        }

        $ext = $allowedMimes[$mimeType];

        // Validate extension matches MIME
        $originalExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($originalExt, $validExtensions[$mimeType])) {
            error_log("[upload-fotos] Extension mismatch: ext={$originalExt}, mime={$mimeType}");
            continue;
        }

        // Generate unique filename
        $timestamp = time();
        $randomStr = bin2hex(random_bytes(4));
        $filename = "review_{$orderId}_{$timestamp}_{$randomStr}.{$ext}";
        $filepath = $uploadDir . $filename;

        // Process and optimize image
        $img = null;
        switch ($mimeType) {
            case 'image/jpeg': $img = @imagecreatefromjpeg($tmpPath); break;
            case 'image/png':  $img = @imagecreatefrompng($tmpPath); break;
            case 'image/webp': $img = @imagecreatefromwebp($tmpPath); break;
        }

        if (!$img) {
            error_log("[upload-fotos] Failed to create image from: {$originalName}");
            continue;
        }

        // Resize if too large (max 1920px on longest side)
        $origWidth = imagesx($img);
        $origHeight = imagesy($img);
        $maxDimension = 1920;

        if ($origWidth > $maxDimension || $origHeight > $maxDimension) {
            if ($origWidth > $origHeight) {
                $newWidth = $maxDimension;
                $newHeight = (int)($origHeight * ($maxDimension / $origWidth));
            } else {
                $newHeight = $maxDimension;
                $newWidth = (int)($origWidth * ($maxDimension / $origHeight));
            }

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($mimeType === 'image/png') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefill($resized, 0, 0, $transparent);
            }
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
            imagedestroy($img);
            $img = $resized;
        }

        // Save optimized image
        $saved = false;
        switch ($ext) {
            case 'jpg':  $saved = imagejpeg($img, $filepath, 85); break;
            case 'png':  $saved = imagepng($img, $filepath, 6); break;
            case 'webp': $saved = imagewebp($img, $filepath, 85); break;
        }
        imagedestroy($img);

        if (!$saved) {
            error_log("[upload-fotos] Failed to save image: {$filepath}");
            continue;
        }

        // Generate thumbnail (200x200 square crop)
        $thumbFilename = "thumb_{$filename}";
        $thumbPath = $thumbDir . $thumbFilename;

        // Verify resolved filepath is within expected upload directory
        $resolvedFilepath = realpath($filepath);
        $resolvedUploadDir = realpath($uploadDir);
        if (!$resolvedFilepath || !$resolvedUploadDir || strpos($resolvedFilepath, $resolvedUploadDir) !== 0) {
            @unlink($filepath);
            continue;
        }

        $thumbSize = 200;
        $thumb = imagecreatetruecolor($thumbSize, $thumbSize);

        switch ($ext) {
            case 'jpg':  $srcImg = imagecreatefromjpeg($resolvedFilepath); break;
            case 'png':
                $srcImg = imagecreatefrompng($resolvedFilepath);
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
                imagefill($thumb, 0, 0, $transparent);
                break;
            case 'webp': $srcImg = imagecreatefromwebp($resolvedFilepath); break;
        }

        $srcWidth = imagesx($srcImg);
        $srcHeight = imagesy($srcImg);
        $minDim = min($srcWidth, $srcHeight);
        $srcX = ($srcWidth - $minDim) / 2;
        $srcY = ($srcHeight - $minDim) / 2;

        imagecopyresampled($thumb, $srcImg, 0, 0, $srcX, $srcY, $thumbSize, $thumbSize, $minDim, $minDim);
        imagedestroy($srcImg);

        switch ($ext) {
            case 'jpg':  imagejpeg($thumb, $thumbPath, 80); break;
            case 'png':  imagepng($thumb, $thumbPath, 7); break;
            case 'webp': imagewebp($thumb, $thumbPath, 80); break;
        }
        imagedestroy($thumb);

        // Generate URLs (local fallback)
        $photoUrl = '/uploads/reviews/' . $filename;
        $thumbnailUrl = '/uploads/reviews/thumbs/' . $thumbFilename;

        // ============ R2 mirror upload (zero-latency global CDN) ============
        if (function_exists('r2IsEnabled') === false && file_exists(__DIR__ . '/../helpers/r2-storage.php')) {
            require_once __DIR__ . '/../helpers/r2-storage.php';
        }
        if (function_exists('r2IsEnabled') && r2IsEnabled()) {
            $r2Url = r2Upload($filepath, r2KeyForUpload('reviews', $filename), 'image/' . $ext);
            if ($r2Url) $photoUrl = $r2Url;
            $r2ThumbUrl = r2Upload($thumbPath, r2KeyForUpload('reviews/thumbs', $thumbFilename), 'image/' . $ext);
            if ($r2ThumbUrl) $thumbnailUrl = $r2ThumbUrl;
        }

        // Insert photo record
        $sortOrder = $existingCount + $processedCount;
        $stmt = $db->prepare("
            INSERT INTO om_review_photos
            (review_id, order_id, customer_id, photo_url, photo_path, thumbnail_url, sort_order, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            RETURNING id
        ");
        $stmt->execute([
            $ratingId ?: null,
            $orderId,
            $customerId,
            $photoUrl,
            $filepath,
            $thumbnailUrl,
            $sortOrder
        ]);

        $photoId = (int)$stmt->fetchColumn();

        $uploadedPhotos[] = [
            'photo_id' => $photoId,
            'photo_url' => $photoUrl,
            'thumbnail_url' => $thumbnailUrl,
        ];

        $processedCount++;
    }

    if (empty($uploadedPhotos)) {
        response(false, null, "Nenhuma foto valida enviada", 400);
    }

    // Award R$2 cashback for photo review (once per order)
    $cashbackCredited = false;
    $cashbackAmount = 2.00;
    $cashbackMessage = null;

    // Check if cashback already given for photos on this order
    $stmt = $db->prepare("
        SELECT credit_given FROM om_review_photos
        WHERE order_id = ? AND customer_id = ? AND credit_given = true
        LIMIT 1
    ");
    $stmt->execute([$orderId, $customerId]);
    $alreadyCredited = $stmt->fetch();

    if (!$alreadyCredited) {
        try {
            $db->beginTransaction();

            // Credit R$2 to cashback wallet
            $expiresAt = date('Y-m-d', strtotime('+90 days'));
            $description = "Cashback por fotos na avaliacao do pedido #{$orderId}";

            // Ensure wallet exists
            $db->prepare("
                INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
                VALUES (?, ?, ?)
                ON CONFLICT (customer_id) DO UPDATE SET
                    balance = om_cashback_wallet.balance + EXCLUDED.balance,
                    total_earned = om_cashback_wallet.total_earned + EXCLUDED.total_earned
            ")->execute([$customerId, $cashbackAmount, $cashbackAmount]);

            // Record transaction
            $db->prepare("
                INSERT INTO om_cashback_transactions
                (customer_id, order_id, partner_id, type, amount, balance_after, description, expires_at)
                VALUES (?, ?, ?, 'credit', ?, 0, ?, ?)
            ")->execute([
                $customerId,
                $orderId,
                (int)$order['partner_id'],
                $cashbackAmount,
                $description,
                $expiresAt
            ]);

            // Get updated balance
            $stmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            $newBalance = (float)$stmt->fetchColumn();

            // Update balance_after in transaction
            $db->prepare("
                UPDATE om_cashback_transactions
                SET balance_after = ?
                WHERE customer_id = ? AND order_id = ? AND type = 'credit'
                  AND description LIKE 'Cashback por fotos%'
            ")->execute([$newBalance, $customerId, $orderId]);

            // Mark photos as credit_given
            $photoIds = array_column($uploadedPhotos, 'photo_id');
            $placeholders = implode(',', array_fill(0, count($photoIds), '?'));
            $db->prepare("UPDATE om_review_photos SET credit_given = true WHERE id IN ({$placeholders})")
                ->execute($photoIds);

            $db->commit();

            $cashbackCredited = true;
            $cashbackMessage = "Voce ganhou R$ 2,00 de cashback por enviar fotos!";

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("[upload-fotos] Cashback credit failed: " . $e->getMessage());
            // Photos were uploaded successfully, cashback failure is non-blocking
        }
    }

    $totalPhotos = $existingCount + $processedCount;

    response(true, [
        'photos' => $uploadedPhotos,
        'total_photos' => $totalPhotos,
        'cashback_credited' => $cashbackCredited,
        'cashback_amount' => $cashbackCredited ? 'R$ ' . number_format($cashbackAmount, 2, ',', '.') : null,
        'cashback_message' => $cashbackMessage,
        'message' => $processedCount . ' foto(s) enviada(s) com sucesso!'
    ]);

} catch (Exception $e) {
    error_log("[avaliacoes/upload-fotos] Erro: " . $e->getMessage());
    response(false, null, "Erro ao enviar fotos", 500);
}
