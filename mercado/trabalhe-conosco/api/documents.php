<?php
/**
 * API: Documentos do Trabalhador
 * GET /api/documents.php - Listar documentos
 * POST /api/documents.php - Upload de documento
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Listar documentos
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $db->prepare("
            SELECT id, type, status, file_url, expires_at, rejection_reason, 
                   created_at, updated_at
            FROM " . table('documents') . "
            WHERE worker_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$workerId]);
        $documents = $stmt->fetchAll();

        // Tipos obrigatórios
        $required = [
            'rg_frente' => 'RG - Frente',
            'rg_verso' => 'RG - Verso',
            'cpf' => 'CPF',
            'selfie' => 'Selfie com documento',
            'comprovante_residencia' => 'Comprovante de Residência'
        ];

        // Status geral
        $allApproved = true;
        $pending = 0;
        $rejected = 0;

        foreach ($documents as $doc) {
            if ($doc['status'] === 'pending') $pending++;
            if ($doc['status'] === 'rejected') $rejected++;
            if ($doc['status'] !== 'approved') $allApproved = false;
        }

        jsonSuccess([
            'documents' => $documents,
            'required_types' => $required,
            'status' => [
                'all_approved' => $allApproved,
                'pending' => $pending,
                'rejected' => $rejected
            ]
        ]);

    } catch (Exception $e) {
        error_log("Documents GET error: " . $e->getMessage());
        jsonError('Erro ao buscar documentos', 500);
    }
}

// POST - Upload de documento
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    
    $validTypes = ['rg_frente', 'rg_verso', 'cpf', 'cnh_frente', 'cnh_verso', 
                   'selfie', 'comprovante_residencia', 'crlv'];

    if (!in_array($type, $validTypes)) {
        jsonError('Tipo de documento inválido');
    }

    // Verificar upload
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonError('Erro no upload do arquivo');
    }

    $file = $_FILES['file'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if ($file['size'] > $maxSize) {
        jsonError('Arquivo muito grande (máximo 5MB)');
    }

    // Validar tipo de arquivo
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedMimes)) {
        jsonError('Tipo de arquivo não permitido');
    }

    try {
        // Gerar nome único
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $workerId . '_' . $type . '_' . time() . '.' . $ext;
        $uploadDir = '../uploads/documents/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filepath = $uploadDir . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            jsonError('Erro ao salvar arquivo');
        }

        $fileUrl = 'uploads/documents/' . $filename;

        // Verificar se já existe documento desse tipo
        $stmt = $db->prepare("
            SELECT id FROM " . table('documents') . " 
            WHERE worker_id = ? AND type = ?
        ");
        $stmt->execute([$workerId, $type]);
        $exists = $stmt->fetch();

        if ($exists) {
            // Atualizar
            $stmt = $db->prepare("
                UPDATE " . table('documents') . "
                SET file_url = ?, status = 'pending', rejection_reason = NULL, updated_at = NOW()
                WHERE worker_id = ? AND type = ?
            ");
            $stmt->execute([$fileUrl, $workerId, $type]);
        } else {
            // Inserir
            $stmt = $db->prepare("
                INSERT INTO " . table('documents') . "
                (worker_id, type, file_url, status, created_at)
                VALUES (?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([$workerId, $type, $fileUrl]);
        }

        jsonSuccess([
            'file_url' => $fileUrl,
            'type' => $type
        ], 'Documento enviado com sucesso');

    } catch (Exception $e) {
        error_log("Documents POST error: " . $e->getMessage());
        jsonError('Erro ao enviar documento', 500);
    }
}

jsonError('Método não permitido', 405);
