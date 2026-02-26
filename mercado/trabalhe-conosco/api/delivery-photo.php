<?php
/**
 * API: Foto de Entrega
 * POST /api/delivery-photo.php - Upload da foto
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) { jsonError('Erro de conexão', 500); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonError('Método não permitido', 405); }

$orderId = $_POST['order_id'] ?? null;

if (!$orderId) { jsonError('ID do pedido é obrigatório'); }

// Verificar se pedido pertence ao trabalhador
$stmt = $db->prepare("SELECT id, status FROM " . table('orders') . " WHERE id = ? AND worker_id = ?");
$stmt->execute([$orderId, $workerId]);
$order = $stmt->fetch();

if (!$order) { jsonError('Pedido não encontrado', 404); }

// Aceitar base64 ou upload
$imageData = null;
$filename = null;

if (isset($_POST['photo_base64'])) {
    // Base64
    $base64 = $_POST['photo_base64'];
    if (preg_match('/^data:image\/(\w+);base64,/', $base64, $matches)) {
        $ext = $matches[1];
        $imageData = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $base64));
    }
} elseif (isset($_FILES['photo'])) {
    // Upload tradicional
    $file = $_FILES['photo'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $imageData = file_get_contents($file['tmp_name']);
    }
}

if (!$imageData) { jsonError('Foto inválida'); }

try {
    $filename = 'delivery_' . $orderId . '_' . time() . '.' . ($ext ?? 'jpg');
    $uploadDir = '../uploads/deliveries/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    
    $filepath = $uploadDir . $filename;
    file_put_contents($filepath, $imageData);
    
    $photoUrl = 'uploads/deliveries/' . $filename;
    
    // Salvar no banco
    $stmt = $db->prepare("UPDATE " . table('orders') . " SET delivery_photo = ?, photo_at = NOW() WHERE id = ?");
    $stmt->execute([$photoUrl, $orderId]);
    
    // Timeline
    $stmt = $db->prepare("INSERT INTO " . table('order_timeline') . " (order_id, status, notes, created_at) VALUES (?, 'photo_taken', 'Foto da entrega registrada', NOW())");
    $stmt->execute([$orderId]);
    
    jsonSuccess(['photo_url' => $photoUrl], 'Foto salva com sucesso');
} catch (Exception $e) {
    error_log("Delivery photo error: " . $e->getMessage());
    jsonError('Erro ao salvar foto', 500);
}
