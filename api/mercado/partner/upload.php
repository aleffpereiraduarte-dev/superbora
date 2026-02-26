<?php
/**
 * POST /api/mercado/partner/upload.php
 * Upload de logo ou banner do parceiro
 * Multipart form-data: file + type (logo|banner)
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

    $partnerId = $payload['uid'];
    $type = $_POST['type'] ?? '';

    if (!in_array($type, ['logo', 'banner'])) {
        response(false, null, "Tipo invalido. Use: logo ou banner", 400);
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

    // Validar tamanho (2MB)
    $maxSize = 2 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        response(false, null, "Arquivo muito grande. Maximo: 2MB", 400);
    }

    // Validar tipo MIME
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedMimes)) {
        response(false, null, "Tipo de arquivo nao permitido. Use: JPG, PNG ou WebP", 400);
    }

    // Extensao
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext = $extensions[$mime];

    // Diretorio destino
    $dir = $type === 'logo' ? '/var/www/html/uploads/logos' : '/var/www/html/uploads/banners';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Nome do arquivo
    $filename = "partner_{$partnerId}_" . time() . ".{$ext}";
    $filepath = "$dir/$filename";

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        response(false, null, "Erro ao salvar arquivo", 500);
    }

    // URL relativa
    $urlPath = $type === 'logo'
        ? "/uploads/logos/$filename"
        : "/uploads/banners/$filename";

    // Atualizar campo no banco
    $col = $type === 'logo' ? 'logo' : 'banner';
    $stmt = $db->prepare("UPDATE om_market_partners SET $col = ?, updated_at = NOW() WHERE partner_id = ?");
    $stmt->execute([$urlPath, $partnerId]);

    response(true, [
        "type" => $type,
        "url" => $urlPath,
        "filename" => $filename
    ]);

} catch (Exception $e) {
    error_log("[partner/upload] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar upload", 500);
}
