<?php
/**
 * POST /api/mercado/partner/product-upload.php
 * Upload de foto de produto
 * Multipart form-data: file + product_id
 * Max 2MB, tipos: jpg/png/webp
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Metodo nao permitido", 405);
}

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
    $productId = (int)($_POST['product_id'] ?? 0);

    if (!$productId) {
        response(false, null, "product_id e obrigatorio", 400);
    }

    // Verify product belongs to this partner
    $stmtCheck = $db->prepare("
        SELECT pp.id
        FROM om_market_products_price pp
        WHERE pp.product_id = ? AND pp.partner_id = ?
    ");
    $stmtCheck->execute([$productId, $partnerId]);
    if (!$stmtCheck->fetch()) {
        response(false, null, "Produto nao encontrado ou nao pertence a este parceiro", 404);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'Nenhum arquivo enviado';
        if (isset($_FILES['file'])) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'Arquivo muito grande (limite do servidor)',
                UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande',
                UPLOAD_ERR_PARTIAL => 'Upload incompleto',
                UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado',
            ];
            $errorMsg = $errors[$_FILES['file']['error']] ?? 'Erro no upload';
        }
        response(false, null, $errorMsg, 400);
    }

    $file = $_FILES['file'];

    // Validate file size (2MB)
    $maxSize = 2 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        response(false, null, "Arquivo muito grande. Maximo: 2MB", 400);
    }

    // Validate MIME type
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedMimes)) {
        response(false, null, "Tipo de arquivo nao permitido. Use: JPG, PNG ou WebP", 400);
    }

    // File extension
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext = $extensions[$mime];

    // Create destination directory with validation
    $baseDir = '/var/www/html/mercado/uploads';
    $dir = $baseDir . '/products';

    // SECURITY: Validate upload directory is within expected base path
    $realBaseDir = realpath($baseDir);
    if ($realBaseDir === false) {
        // Base directory doesn't exist, create it
        if (!mkdir($baseDir, 0755, true)) {
            response(false, null, "Erro ao criar diretorio de upload", 500);
        }
        $realBaseDir = realpath($baseDir);
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $realDir = realpath($dir);
    if ($realDir === false || strpos($realDir, $realBaseDir) !== 0) {
        error_log("[partner/product-upload] Tentativa de path traversal detectada: $dir");
        response(false, null, "Diretorio de upload invalido", 400);
    }

    // Validate directory is writable
    if (!is_writable($realDir)) {
        error_log("[partner/product-upload] Diretorio nao gravavel: $realDir");
        response(false, null, "Erro de permissao no servidor", 500);
    }

    // Generate unique filename with cryptographically secure random
    $filename = "product_{$productId}_" . time() . "_" . bin2hex(random_bytes(4)) . ".{$ext}";
    $filepath = "$dir/$filename";

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        response(false, null, "Erro ao salvar arquivo", 500);
    }

    // Relative URL path
    $urlPath = "/mercado/uploads/products/$filename";

    // Update product image in the base products table
    $stmt = $db->prepare("UPDATE om_market_products_base SET image = ? WHERE product_id = ?");
    $stmt->execute([$urlPath, $productId]);

    response(true, [
        "product_id" => $productId,
        "url" => $urlPath,
        "filename" => $filename
    ]);

} catch (Exception $e) {
    error_log("[partner/product-upload] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar upload", 500);
}
