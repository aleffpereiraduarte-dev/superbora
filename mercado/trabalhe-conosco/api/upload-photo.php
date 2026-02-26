<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['worker_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$workerId = $_SESSION['worker_id'];
$type = $_POST['type'] ?? 'delivery'; // delivery, profile, chat
$orderId = $_POST['order_id'] ?? 0;

// Verificar se tem arquivo ou base64
$imageData = null;
$filename = null;

if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $imageData = file_get_contents($_FILES['photo']['tmp_name']);
    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION) ?: 'jpg';
} elseif (isset($_POST['photo_base64'])) {
    $base64 = $_POST['photo_base64'];
    if (preg_match('/^data:image\/(\w+);base64,/', $base64, $matches)) {
        $ext = $matches[1];
        $imageData = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $base64));
    }
}

if (!$imageData) {
    echo json_encode(['success' => false, 'error' => 'Nenhuma imagem enviada']);
    exit;
}

try {
    $pdo = getDB();
    
    // Criar diretório se não existir
    $uploadDir = __DIR__ . '/../uploads/' . $type . '/' . date('Y/m');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Nome único
    $filename = $workerId . '_' . time() . '_' . uniqid() . '.' . $ext;
    $filepath = $uploadDir . '/' . $filename;
    $relativePath = '/uploads/' . $type . '/' . date('Y/m') . '/' . $filename;
    
    // Salvar arquivo
    if (!file_put_contents($filepath, $imageData)) {
        throw new Exception('Erro ao salvar arquivo');
    }
    
    // Ações conforme tipo
    switch ($type) {
        case 'delivery':
            if ($orderId) {
                $stmt = $pdo->prepare("UPDATE om_market_orders SET delivery_photo = ? WHERE order_id = ? AND (shopper_id = ? OR delivery_id = ?)");
                $stmt->execute([$relativePath, $orderId, $workerId, $workerId]);
            }
            break;
            
        case 'profile':
            $stmt = $pdo->prepare("UPDATE om_market_workers SET photo = ? WHERE worker_id = ?");
            $stmt->execute([$relativePath, $workerId]);
            break;
            
        case 'chat':
            if ($orderId) {
                $stmt = $pdo->prepare("INSERT INTO om_order_chat (order_id, sender_type, sender_id, message, image_url, created_at) VALUES (?, 'shopper', ?, '[Foto]', ?, NOW())");
                $stmt->execute([$orderId, $workerId, $relativePath]);
            }
            break;
    }
    
    echo json_encode([
        'success' => true,
        'url' => $relativePath,
        'type' => $type
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}