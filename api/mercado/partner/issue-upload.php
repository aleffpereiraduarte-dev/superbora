<?php
/**
 * /api/mercado/partner/issue-upload.php
 *
 * Upload photo evidence for partner issues.
 * POST (FormData) photo file
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'partner') response(false, null, "Acesso restrito a parceiros", 403);
    $partnerId = (int)$payload['uid'];

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

    // SECURITY: Validate actual MIME type (not just extension)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
        response(false, null, "Tipo de arquivo nao permitido", 400);
    }

    $year = date('Y');
    $month = date('m');
    $uploadDir = "/var/www/html/uploads/partner-issues/{$year}/{$month}";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $filename = "partner_{$partnerId}_" . time() . "_" . bin2hex(random_bytes(4)) . ".{$ext}";
    $filepath = "{$uploadDir}/{$filename}";
    $publicUrl = "/uploads/partner-issues/{$year}/{$month}/{$filename}";

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        response(false, null, "Erro ao salvar foto", 500);
    }

    response(true, ['url' => $publicUrl], "Foto enviada com sucesso");

} catch (Exception $e) {
    error_log("[partner/issue-upload] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar", 500);
}
