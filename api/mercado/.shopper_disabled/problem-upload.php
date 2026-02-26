<?php
/**
 * /api/mercado/shopper/problem-upload.php
 *
 * Upload photo evidence for shopper problem reports.
 * POST (FormData) photo file
 */
require_once __DIR__ . "/../config/auth.php";

try {
    $db = getDB();
    $auth = requireShopperAuth();
    $shopper_id = $auth["uid"];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        response(false, null, "Foto obrigatoria", 400);
    }

    $file = $_FILES['photo'];
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) response(false, null, "Foto muito grande (max 10MB)", 400);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
        response(false, null, "Formato invalido. Use JPG, PNG ou WebP", 400);
    }

    // Validate actual MIME type using finfo (not just extension)
    $tmpPath = $file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpPath);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mimeType, $allowedMimes)) {
        response(false, null, "Tipo de arquivo nao permitido", 400);
    }

    $year = date('Y');
    $month = date('m');
    $uploadDir = "/var/www/html/uploads/shopper-problems/{$year}/{$month}";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $filename = "shopper_{$shopper_id}_" . time() . "_" . bin2hex(random_bytes(4)) . ".{$ext}";
    $filepath = "{$uploadDir}/{$filename}";
    $publicUrl = "/uploads/shopper-problems/{$year}/{$month}/{$filename}";

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        response(false, null, "Erro ao salvar foto", 500);
    }

    response(true, ['url' => $publicUrl], "Foto enviada com sucesso");

} catch (Exception $e) {
    error_log("[shopper/problem-upload] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar", 500);
}
