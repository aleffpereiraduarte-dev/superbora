<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
/**
 * API: Upload de Documentos
 * POST /api/upload-doc.php
 */
header("Content-Type: application/json");

session_start();

$workerId = $_SESSION["worker_id"] ?? $_SESSION["pending_worker_id"] ?? 0;
if (!$workerId) {
    echo json_encode(["success" => false, "error" => "Não autenticado"]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "error" => "Método não permitido"]);
    exit;
}

try {
    $pdo = getPDO();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $docType = $_POST["doc_type"] ?? "";
    $validTypes = ["rg_front", "rg_back", "cpf", "selfie", "proof_address", "mei", "cnh_front", "cnh_back", "crlv"];
    
    if (!in_array($docType, $validTypes)) {
        throw new Exception("Tipo de documento inválido");
    }
    
    if (!isset($_FILES["file"]) || $_FILES["file"]["error"] !== UPLOAD_ERR_OK) {
        throw new Exception("Erro no upload do arquivo");
    }
    
    $file = $_FILES["file"];
    $allowedMimes = ["image/jpeg", "image/png", "image/webp", "application/pdf"];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file["tmp_name"]);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedMimes)) {
        throw new Exception("Tipo de arquivo não permitido");
    }
    
    // Criar pasta de uploads
    $uploadDir = dirname(__DIR__) . "/uploads/workers/$workerId/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Gerar nome único
    $ext = pathinfo($file["name"], PATHINFO_EXTENSION);
    $filename = $docType . "_" . time() . "." . $ext;
    $filepath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file["tmp_name"], $filepath)) {
        throw new Exception("Erro ao salvar arquivo");
    }
    
    // URL pública
    $publicUrl = "/mercado/trabalhe-conosco/uploads/workers/$workerId/$filename";
    
    // Atualizar coluna no worker
    $colName = "doc_" . $docType;
    $stmt = $pdo->prepare("UPDATE om_market_workers SET $colName = ? WHERE worker_id = ?");
    $stmt->execute([$publicUrl, $workerId]);
    
    // Registrar na tabela de documentos
    $stmt = $pdo->prepare("INSERT INTO om_market_worker_documents 
        (worker_id, doc_type, file_path, uploaded_at) 
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE file_path = ?, uploaded_at = NOW()");
    $stmt->execute([$workerId, $docType, $publicUrl, $publicUrl]);
    
    echo json_encode([
        "success" => true,
        "message" => "Documento enviado com sucesso",
        "url" => $publicUrl
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}