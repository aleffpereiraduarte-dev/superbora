<?php
/**
 * POST /api/mercado/parceiro/upload-doc.php
 * Upload partner documents (selfie, CNPJ card, alvara)
 *
 * Multipart form: doc_type (selfie_id|cnpj_card|alvara|comprovante_endereco), file
 * Auth: Bearer token
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    $doc_type = trim($_POST['doc_type'] ?? '');
    $validTypes = ['selfie_id', 'cnpj_card', 'alvara', 'comprovante_endereco'];

    if (!in_array($doc_type, $validTypes)) {
        response(false, null, "Tipo de documento invalido. Use: " . implode(', ', $validTypes), 400);
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        response(false, null, "Arquivo nao enviado ou erro no upload", 400);
    }

    $file = $_FILES['file'];
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) {
        response(false, null, "Arquivo muito grande. Maximo: 10MB", 400);
    }

    // Validate mime type
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedMimes)) {
        response(false, null, "Tipo de arquivo nao permitido. Use: JPG, PNG, WebP ou PDF", 400);
    }

    // Create upload directory
    $uploadDir = dirname(__DIR__, 3) . "/storage/partner-docs/{$partner_id}";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // BUG #25: Deny direct access to uploads via .htaccess
    $htaccessPath = dirname(__DIR__, 3) . "/storage/partner-docs/.htaccess";
    if (!file_exists($htaccessPath)) {
        @file_put_contents($htaccessPath, "Deny from all\n");
    }

    // Generate safe filename
    $ext = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        default => 'bin'
    };
    $filename = $doc_type . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $filepath = $uploadDir . '/' . $filename;
    $relativePath = "storage/partner-docs/{$partner_id}/{$filename}";

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        response(false, null, "Erro ao salvar arquivo", 500);
    }

    // BUG #26: Re-encode images with GD to strip EXIF and embedded payloads (polyglot protection)
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp']) && function_exists('imagecreatefromstring')) {
        $imgData = @file_get_contents($filepath);
        $img = @imagecreatefromstring($imgData);
        if ($img) {
            switch ($mime) {
                case 'image/jpeg':
                    @imagejpeg($img, $filepath, 85);
                    break;
                case 'image/png':
                    @imagepng($img, $filepath, 6);
                    break;
                case 'image/webp':
                    @imagewebp($img, $filepath, 85);
                    break;
            }
            @imagedestroy($img);
        }
    }

    // Delete previous document of same type (keep only latest)
    $stmt = $db->prepare("SELECT id, path FROM om_partner_documents WHERE partner_id = ? AND doc_type = ?");
    $stmt->execute([$partner_id, $doc_type]);
    $existing = $stmt->fetch();
    if ($existing) {
        $oldFile = dirname(__DIR__, 3) . '/' . $existing['path'];
        if (file_exists($oldFile)) {
            @unlink($oldFile);
        }
        $stmt = $db->prepare("DELETE FROM om_partner_documents WHERE id = ?");
        $stmt->execute([$existing['id']]);
    }

    // Insert document record
    $stmt = $db->prepare("
        INSERT INTO om_partner_documents (partner_id, doc_type, filename, path, status, created_at)
        VALUES (?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$partner_id, $doc_type, $filename, $relativePath]);
    $doc_id = (int)$db->lastInsertId();

    response(true, [
        "doc_id" => $doc_id,
        "doc_type" => $doc_type,
        "filename" => $filename,
        "status" => "pending"
    ], "Documento enviado com sucesso");

} catch (Exception $e) {
    error_log("[upload-doc] Erro: " . $e->getMessage());
    response(false, null, "Erro ao enviar documento", 500);
}
