<?php
/**
 * POST /api/mercado/upload.php
 * Upload de imagens (produto, banner, logo)
 * Multipart form-data: file + type (product|banner|logo) + entity_id (opcional)
 *
 * Valida imagem, redimensiona 800px max, salva WebP, retorna URL
 */
require_once __DIR__ . "/config/database.php";
require_once __DIR__ . "/helpers/csrf.php";

try {
    session_start();
    verifyCsrf();
    $db = getDB();

    $mercado_id = $_SESSION['mercado_id'] ?? 0;
    if (!$mercado_id) {
        response(false, null, "Nao autorizado", 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    // Validar arquivo
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $erros = [
            UPLOAD_ERR_INI_SIZE => "Arquivo excede o tamanho maximo do servidor",
            UPLOAD_ERR_FORM_SIZE => "Arquivo excede o tamanho maximo do formulario",
            UPLOAD_ERR_PARTIAL => "Upload incompleto",
            UPLOAD_ERR_NO_FILE => "Nenhum arquivo enviado",
            UPLOAD_ERR_NO_TMP_DIR => "Diretorio temporario nao encontrado",
            UPLOAD_ERR_CANT_WRITE => "Falha ao gravar arquivo",
        ];
        $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        response(false, null, $erros[$code] ?? "Erro no upload", 400);
    }

    $file = $_FILES['file'];
    $type = $_POST['type'] ?? 'product';
    $entity_id = (int)($_POST['entity_id'] ?? 0) ?: null;

    // Validar tipo
    $tipos_validos = ['product', 'banner', 'logo'];
    if (!in_array($type, $tipos_validos)) {
        response(false, null, "Tipo invalido. Use: " . implode(', ', $tipos_validos), 400);
    }

    // Validar tamanho (max 5MB)
    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        response(false, null, "Arquivo muito grande. Maximo: 5MB", 400);
    }

    // Validar MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $mimes_validos = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $mimes_validos)) {
        response(false, null, "Formato invalido. Aceitos: JPG, PNG, GIF, WebP", 400);
    }

    // Validar que e realmente uma imagem
    $imageInfo = getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        response(false, null, "Arquivo nao e uma imagem valida", 400);
    }

    // Diretorio de destino
    $dirs = [
        'product' => '/var/www/html/uploads/products/',
        'banner' => '/var/www/html/uploads/banners/',
        'logo' => '/var/www/html/uploads/logos/',
    ];
    $destDir = $dirs[$type];

    // SECURITY: Verify destination is not a symlink (prevent symlink attacks)
    if (is_link($destDir)) {
        response(false, null, "Erro de configuracao do servidor", 500);
    }
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    // Gerar nome unico
    $filename = $mercado_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.webp';
    $destPath = $destDir . $filename;

    // Carregar e redimensionar imagem
    $maxWidth = 800;
    $maxHeight = 800;

    switch ($mime) {
        case 'image/jpeg':
            $srcImage = imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $srcImage = imagecreatefrompng($file['tmp_name']);
            break;
        case 'image/gif':
            $srcImage = imagecreatefromgif($file['tmp_name']);
            break;
        case 'image/webp':
            $srcImage = imagecreatefromwebp($file['tmp_name']);
            break;
        default:
            response(false, null, "Formato nao suportado", 400);
    }

    if (!$srcImage) {
        response(false, null, "Erro ao processar imagem", 500);
    }

    $srcWidth = imagesx($srcImage);
    $srcHeight = imagesy($srcImage);

    // Calcular novo tamanho mantendo proporcao
    $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
    if ($ratio < 1) {
        $newWidth = (int)($srcWidth * $ratio);
        $newHeight = (int)($srcHeight * $ratio);

        $dstImage = imagecreatetruecolor($newWidth, $newHeight);

        // Preservar transparencia
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);

        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
        imagedestroy($srcImage);
    } else {
        $dstImage = $srcImage;
    }

    // Salvar como WebP (qualidade 85)
    $saved = imagewebp($dstImage, $destPath, 85);
    imagedestroy($dstImage);

    if (!$saved) {
        response(false, null, "Erro ao salvar imagem", 500);
    }

    // URL relativa
    $relativePaths = [
        'product' => '/uploads/products/',
        'banner' => '/uploads/banners/',
        'logo' => '/uploads/logos/',
    ];
    $url = $relativePaths[$type] . $filename;

    // Registrar no banco
    $stmt = $db->prepare("INSERT INTO om_uploads (partner_id, type, entity_id, filename, path, original_name, file_size) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $mercado_id,
        $type,
        $entity_id,
        $filename,
        $url,
        $file['name'],
        filesize($destPath)
    ]);

    response(true, [
        "url" => $url,
        "filename" => $filename,
        "type" => $type,
        "size" => filesize($destPath)
    ], "Imagem enviada com sucesso");

} catch (Exception $e) {
    error_log("[upload] Erro: " . $e->getMessage());
    response(false, null, "Erro ao enviar imagem", 500);
}
