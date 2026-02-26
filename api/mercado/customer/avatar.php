<?php
/**
 * POST /api/mercado/customer/avatar.php
 * Upload de foto de perfil do cliente
 *
 * Recebe FormData com campo 'avatar' (image/jpeg, image/png, image/webp)
 * Redimensiona para 400x400 e salva em /uploads/avatars/
 * Retorna { avatar_url: "https://..." }
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token nao fornecido", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Nao autorizado", 401);
    }

    $customerId = (int)$payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        response(false, null, "Metodo nao permitido", 405);
    }

    // Validar upload
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errMsg = match($errCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "Arquivo muito grande",
            UPLOAD_ERR_NO_FILE => "Nenhum arquivo enviado",
            default => "Erro no upload"
        };
        response(false, null, $errMsg, 400);
    }

    $file = $_FILES['avatar'];

    // Validar tamanho (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        response(false, null, "Arquivo muito grande. Maximo 5MB.", 400);
    }

    // Validar MIME type real (via finfo, not user-supplied type)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

    if (!in_array($mimeType, $allowedMimes, true)) {
        response(false, null, "Formato invalido. Use JPEG, PNG ou WebP.", 400);
    }

    // Carregar imagem com GD
    $sourceImage = null;
    switch ($mimeType) {
        case 'image/jpeg':
            $sourceImage = @imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $sourceImage = @imagecreatefrompng($file['tmp_name']);
            break;
        case 'image/webp':
            $sourceImage = @imagecreatefromwebp($file['tmp_name']);
            break;
    }

    if (!$sourceImage) {
        response(false, null, "Erro ao processar imagem", 400);
    }

    // Redimensionar para 400x400 (crop quadrado do centro)
    $srcW = imagesx($sourceImage);
    $srcH = imagesy($sourceImage);
    $size = min($srcW, $srcH);
    $srcX = (int)(($srcW - $size) / 2);
    $srcY = (int)(($srcH - $size) / 2);

    $targetSize = 400;
    $destImage = imagecreatetruecolor($targetSize, $targetSize);
    imagecopyresampled($destImage, $sourceImage, 0, 0, $srcX, $srcY, $targetSize, $targetSize, $size, $size);

    // Salvar como JPEG
    $uploadsDir = dirname(__DIR__, 3) . '/uploads/avatars';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }

    $filename = "customer_{$customerId}.jpg";
    $filepath = $uploadsDir . '/' . $filename;

    if (!imagejpeg($destImage, $filepath, 85)) {
        imagedestroy($sourceImage);
        imagedestroy($destImage);
        response(false, null, "Erro ao salvar imagem", 500);
    }

    imagedestroy($sourceImage);
    imagedestroy($destImage);

    // Construir URL publica
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'superbora.com.br';
    $avatarUrl = "{$protocol}://{$host}/uploads/avatars/{$filename}?v=" . time();

    // Atualizar no banco
    $stmt = $db->prepare("UPDATE om_customers SET foto = ?, updated_at = NOW() WHERE customer_id = ?");
    $stmt->execute([$avatarUrl, $customerId]);

    response(true, [
        "avatar_url" => $avatarUrl
    ], "Foto atualizada com sucesso!");

} catch (Exception $e) {
    error_log("[customer/avatar] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar foto", 500);
}
