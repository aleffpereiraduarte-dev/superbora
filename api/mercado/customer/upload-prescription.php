<?php
/**
 * POST /api/mercado/customer/upload-prescription.php
 * Upload de foto de receita médica (farmácia).
 *
 * Multipart form:
 *   - file field: "prescription" (image/jpeg|png|webp, max 5MB)
 *
 * Auth: Bearer token (customer)
 *
 * Retorna:
 *   { url: "/uploads/prescriptions/17_1714002030_ab12cd34.jpg" }
 *
 * A URL retornada deve ser enviada no payload do checkout quando o cliente
 * comprar itens com requires_prescription = 1.
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        response(false, null, "Método não permitido", 405);
    }

    // Aceita tanto "prescription" quanto "file" (flexibilidade pra mobile)
    $fileField = isset($_FILES['prescription']) ? 'prescription' : 'file';

    if (!isset($_FILES[$fileField]) || $_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE;
        $errMsg = match($errCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "Arquivo muito grande",
            UPLOAD_ERR_NO_FILE => "Envie a foto da receita no campo 'prescription'",
            default => "Erro no upload"
        };
        response(false, null, $errMsg, 400);
    }

    $file = $_FILES[$fileField];

    // Max 5MB
    if ($file['size'] > 5 * 1024 * 1024) {
        response(false, null, "Arquivo muito grande. Máximo 5MB.", 400);
    }

    // Valida MIME real (via finfo, não trust user-supplied type)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

    if (!in_array($mimeType, $allowedMimes, true)) {
        response(false, null, "Formato inválido. Use JPEG, PNG ou WebP.", 400);
    }

    $ext = match($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    // Cria dir se não existir (padrão igual avatar.php/upload-doc.php)
    $uploadsDir = dirname(__DIR__, 3) . '/uploads/prescriptions';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }

    // Filename: {customer_id}_{timestamp}_{random}.{ext}
    // Random hex evita colisão quando cliente sobe 2 receitas em <1s.
    $filename = sprintf(
        '%d_%d_%s.%s',
        $customerId,
        time(),
        bin2hex(random_bytes(4)),
        $ext
    );
    $filepath = $uploadsDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        error_log("[upload-prescription] move_uploaded_file falhou: customer={$customerId}");
        response(false, null, "Erro ao salvar arquivo", 500);
    }

    // Re-encode pra stripar EXIF + payload embutido (polyglot protection)
    if (function_exists('imagecreatefromstring')) {
        $resolvedPath = realpath($filepath);
        $resolvedDir  = realpath($uploadsDir);
        if (!$resolvedPath || !$resolvedDir || strpos($resolvedPath, $resolvedDir . '/') !== 0) {
            @unlink($filepath);
            response(false, null, "Erro de segurança ao processar arquivo", 500);
        }
        $imgData = @file_get_contents($resolvedPath);
        $img = @imagecreatefromstring($imgData);
        if ($img) {
            switch ($mimeType) {
                case 'image/jpeg':
                    @imagejpeg($img, $filepath, 88);
                    break;
                case 'image/png':
                    @imagepng($img, $filepath, 6);
                    break;
                case 'image/webp':
                    @imagewebp($img, $filepath, 88);
                    break;
            }
            @imagedestroy($img);
        }
    }

    $url = "/uploads/prescriptions/{$filename}";

    response(true, [
        "url" => $url,
    ], "Receita enviada");

} catch (Exception $e) {
    error_log("[upload-prescription] Erro: " . $e->getMessage());
    response(false, null, "Erro ao enviar receita", 500);
}
