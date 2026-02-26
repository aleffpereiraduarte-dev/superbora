<?php
/**
 * POST /api/mercado/vitrine/review-photo-upload.php
 *
 * Upload de foto para avaliacao via multipart/form-data
 * Suporta jpg, png, webp ate 5MB
 *
 * Form Data:
 *   - image: arquivo da imagem (multipart)
 *   - order_id: ID do pedido (obrigatorio)
 *   - review_id: ID da review (opcional, se ja existir)
 *   - caption: legenda da foto (opcional)
 *
 * Retorna:
 *   { success: true, data: { photo_id, photo_url, thumbnail_url } }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Auth
    $token = om_auth()->getTokenFromRequest();
    if (!$token) {
        response(false, null, "Token ausente", 401);
    }

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Nao autorizado", 401);
    }

    $customerId = (int)$payload['uid'];

    // Validate required fields
    $orderId = (int)($_POST['order_id'] ?? 0);
    $reviewId = (int)($_POST['review_id'] ?? 0);
    $caption = trim(substr($_POST['caption'] ?? '', 0, 255));

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

    // Verify order belongs to customer and is delivered
    $stmt = $db->prepare("
        SELECT order_id, partner_id, status
        FROM om_market_orders
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    if ($order['status'] !== 'entregue') {
        response(false, null, "Apenas pedidos entregues podem receber fotos", 400);
    }

    // Check file upload
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'Arquivo muito grande (limite do servidor)',
            UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande',
            UPLOAD_ERR_PARTIAL => 'Upload incompleto',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretorio temporario nao encontrado',
            UPLOAD_ERR_CANT_WRITE => 'Erro ao salvar arquivo',
        ];
        $errCode = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errMsg = $errorMessages[$errCode] ?? 'Erro no upload';
        response(false, null, $errMsg, 400);
    }

    $file = $_FILES['image'];
    $tmpPath = $file['tmp_name'];
    $originalName = $file['name'];
    $fileSize = $file['size'];

    // Validate file size (max 5MB)
    $maxSize = 5 * 1024 * 1024;
    if ($fileSize > $maxSize) {
        response(false, null, "Imagem muito grande. Maximo 5MB.", 400);
    }

    // Validate file type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpPath);
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedMimes[$mimeType])) {
        response(false, null, "Formato invalido. Use JPG, PNG ou WebP.", 400);
    }

    $ext = $allowedMimes[$mimeType];

    // SECURITY: Validate file extension matches MIME type
    // This prevents bypassing MIME checks by renaming files
    $originalExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $validExtensions = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    if (!in_array($originalExt, $validExtensions[$mimeType])) {
        error_log("[review-photo-upload] Extension mismatch: file=$originalName, ext=$originalExt, mime=$mimeType");
        response(false, null, "Extensao do arquivo nao corresponde ao tipo. Renomeie o arquivo corretamente.", 400);
    }

    // Check photo limit per order (max 3 photos)
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt FROM om_review_photos
        WHERE order_id = ? AND customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $photoCount = (int)$stmt->fetchColumn();

    if ($photoCount >= 3) {
        response(false, null, "Limite de 3 fotos por pedido atingido", 400);
    }

    // Create upload directory
    $uploadDir = dirname(__DIR__, 3) . '/uploads/reviews/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $timestamp = time();
    $randomStr = bin2hex(random_bytes(4));
    $filename = "review_{$orderId}_{$timestamp}_{$randomStr}.{$ext}";
    $filepath = $uploadDir . $filename;

    // Process and optimize image
    $img = null;
    switch ($mimeType) {
        case 'image/jpeg':
            $img = imagecreatefromjpeg($tmpPath);
            break;
        case 'image/png':
            $img = imagecreatefrompng($tmpPath);
            break;
        case 'image/webp':
            $img = imagecreatefromwebp($tmpPath);
            break;
    }

    if (!$img) {
        response(false, null, "Erro ao processar imagem", 500);
    }

    // Get original dimensions
    $origWidth = imagesx($img);
    $origHeight = imagesy($img);

    // Resize if too large (max 1920px on longest side)
    $maxDimension = 1920;
    $newWidth = $origWidth;
    $newHeight = $origHeight;

    if ($origWidth > $maxDimension || $origHeight > $maxDimension) {
        if ($origWidth > $origHeight) {
            $newWidth = $maxDimension;
            $newHeight = (int)($origHeight * ($maxDimension / $origWidth));
        } else {
            $newHeight = $maxDimension;
            $newWidth = (int)($origWidth * ($maxDimension / $origHeight));
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG
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
        case 'jpg':
            $saved = imagejpeg($img, $filepath, 85);
            break;
        case 'png':
            $saved = imagepng($img, $filepath, 6);
            break;
        case 'webp':
            $saved = imagewebp($img, $filepath, 85);
            break;
    }

    imagedestroy($img);

    if (!$saved) {
        response(false, null, "Erro ao salvar imagem", 500);
    }

    // Generate thumbnail (200x200)
    $thumbDir = $uploadDir . 'thumbs/';
    if (!is_dir($thumbDir)) {
        mkdir($thumbDir, 0755, true);
    }

    $thumbFilename = "thumb_{$filename}";
    $thumbPath = $thumbDir . $thumbFilename;

    $thumbSize = 200;
    $thumb = imagecreatetruecolor($thumbSize, $thumbSize);

    // Load saved image for thumbnail
    switch ($ext) {
        case 'jpg':
            $img = imagecreatefromjpeg($filepath);
            break;
        case 'png':
            $img = imagecreatefrompng($filepath);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefill($thumb, 0, 0, $transparent);
            break;
        case 'webp':
            $img = imagecreatefromwebp($filepath);
            break;
    }

    // Calculate crop dimensions for square thumbnail
    $srcWidth = imagesx($img);
    $srcHeight = imagesy($img);
    $minDim = min($srcWidth, $srcHeight);
    $srcX = ($srcWidth - $minDim) / 2;
    $srcY = ($srcHeight - $minDim) / 2;

    imagecopyresampled($thumb, $img, 0, 0, $srcX, $srcY, $thumbSize, $thumbSize, $minDim, $minDim);
    imagedestroy($img);

    switch ($ext) {
        case 'jpg':
            imagejpeg($thumb, $thumbPath, 80);
            break;
        case 'png':
            imagepng($thumb, $thumbPath, 7);
            break;
        case 'webp':
            imagewebp($thumb, $thumbPath, 80);
            break;
    }
    imagedestroy($thumb);

    // Generate random token for URL to prevent enumeration attacks
    $urlToken = bin2hex(random_bytes(8));
    $photoUrl = '/uploads/reviews/' . $filename . '?t=' . $urlToken;
    $thumbnailUrl = '/uploads/reviews/thumbs/' . $thumbFilename . '?t=' . $urlToken;

    // Table om_review_photos created via migration (with thumbnail_url, status columns)

    // Insert photo record
    $sortOrder = $photoCount; // Next in sequence
    $stmt = $db->prepare("
        INSERT INTO om_review_photos
        (review_id, order_id, customer_id, photo_url, thumbnail_url, caption, sort_order, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([
        $reviewId ?: null,
        $orderId,
        $customerId,
        $photoUrl,
        $thumbnailUrl,
        $caption ?: null,
        $sortOrder
    ]);

    $photoId = (int)$db->lastInsertId();

    response(true, [
        'photo_id' => $photoId,
        'photo_url' => $photoUrl,
        'thumbnail_url' => $thumbnailUrl,
        'sort_order' => $sortOrder,
        'message' => 'Foto enviada com sucesso!'
    ]);

} catch (Exception $e) {
    error_log("[vitrine/review-photo-upload] Erro: " . $e->getMessage());
    response(false, null, "Erro ao enviar foto", 500);
}
