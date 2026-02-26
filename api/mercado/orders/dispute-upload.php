<?php
/**
 * /api/mercado/orders/dispute-upload.php
 *
 * Upload photo evidence for a dispute.
 *
 * POST multipart/form-data:
 *   - dispute_id (optional, can attach later)
 *   - order_id
 *   - photo (file)
 *
 * Returns the photo URL for use in dispute creation.
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    // Rate limiting: 10 uploads per hour per customer
    if (!checkRateLimit("dispute_upload_c{$customerId}", 10, 60)) {
        response(false, null, "Muitos uploads. Tente novamente em 1 hora.", 429);
    }

    $orderId = (int)($_POST['order_id'] ?? 0);
    $disputeId = (int)($_POST['dispute_id'] ?? 0);

    if (!$orderId) response(false, null, "order_id obrigatorio", 400);

    // Verify order belongs to customer
    $stmtOrder = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
    $stmtOrder->execute([$orderId, $customerId]);
    if (!$stmtOrder->fetch()) response(false, null, "Pedido nao encontrado", 404);

    // Check file upload
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Erro no upload da foto';
        if (isset($_FILES['photo'])) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'Arquivo muito grande',
                UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande',
                UPLOAD_ERR_PARTIAL => 'Upload incompleto',
                UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado',
                UPLOAD_ERR_NO_TMP_DIR => 'Erro no servidor',
                UPLOAD_ERR_CANT_WRITE => 'Erro no servidor',
            ];
            $errorMsg = $uploadErrors[$_FILES['photo']['error']] ?? $errorMsg;
        }
        response(false, null, $errorMsg, 400);
    }

    $file = $_FILES['photo'];

    // Validate file type
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMimes)) {
        response(false, null, "Tipo de arquivo nao permitido. Use JPEG, PNG ou WebP.", 400);
    }

    // Max 10MB
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        response(false, null, "Arquivo muito grande. Maximo 10MB.", 400);
    }

    // Create upload directory
    $uploadDir = '/var/www/html/uploads/disputes/' . date('Y/m');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $ext = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => 'jpg',
    };
    $filename = 'd_' . $orderId . '_' . $customerId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $filepath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        response(false, null, "Erro ao salvar foto", 500);
    }

    // Generate relative URL
    $photoUrl = '/uploads/disputes/' . date('Y/m') . '/' . $filename;

    // If dispute_id provided, insert into evidence table
    if ($disputeId > 0) {
        // Verify dispute belongs to customer
        $stmtDispute = $db->prepare("SELECT dispute_id FROM om_order_disputes WHERE dispute_id = ? AND customer_id = ?");
        $stmtDispute->execute([$disputeId, $customerId]);
        if ($stmtDispute->fetch()) {
            $db->prepare("
                INSERT INTO om_dispute_evidence (dispute_id, order_id, customer_id, photo_url, file_size, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ")->execute([$disputeId, $orderId, $customerId, $photoUrl, $file['size']]);

            // Update dispute photo_urls
            $stmtPhotos = $db->prepare("SELECT photo_urls FROM om_order_disputes WHERE dispute_id = ?");
            $stmtPhotos->execute([$disputeId]);
            $row = $stmtPhotos->fetch();
            $urls = json_decode($row['photo_urls'] ?? '[]', true) ?: [];
            $urls[] = $photoUrl;
            $db->prepare("UPDATE om_order_disputes SET photo_urls = ?, updated_at = NOW() WHERE dispute_id = ?")->execute([
                json_encode($urls, JSON_UNESCAPED_UNICODE), $disputeId
            ]);

            // If dispute was awaiting evidence, check if we can auto-resolve now
            $stmtStatus = $db->prepare("SELECT status, category, subcategory FROM om_order_disputes WHERE dispute_id = ?");
            $stmtStatus->execute([$disputeId]);
            $dispRow = $stmtStatus->fetch();
            if ($dispRow && $dispRow['status'] === 'awaiting_evidence') {
                $db->prepare("
                    INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                    VALUES (?, 'evidence_uploaded', 'customer', ?, 'Foto enviada como evidencia', NOW())
                ")->execute([$disputeId, $customerId]);
            }
        }
    }

    response(true, [
        'photo_url' => $photoUrl,
        'file_size' => $file['size'],
    ], "Foto enviada com sucesso");

} catch (Exception $e) {
    error_log("[dispute-upload] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar upload", 500);
}
