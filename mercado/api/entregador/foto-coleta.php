<?php
/**
 * API: Upload de foto da coleta
 * POST /mercado/api/entregador/foto-coleta.php
 */
require_once __DIR__ . '/config.php';

$input = getInput();
$order_id = (int)($input['order_id'] ?? 0);
$driver_id = (int)($input['driver_id'] ?? 0);
$foto = $input['foto'] ?? '';

if (!$order_id || !$driver_id) {
    jsonResponse(['success' => false, 'error' => 'order_id e driver_id obrigatorios'], 400);
}

$pdo = getDB();

// Verificar driver
$driver = validateDriver($driver_id);
if (!$driver) {
    jsonResponse(['success' => false, 'error' => 'Entregador nao encontrado'], 404);
}

// Verificar pedido
$stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND delivery_driver_id = ?");
$stmt->execute([$order_id, $driver_id]);
$order = $stmt->fetch();

if (!$order) {
    jsonResponse(['success' => false, 'error' => 'Pedido nao encontrado ou nao atribuido a voce'], 404);
}

$foto_path = null;

// Processar foto base64 se fornecida
if ($foto && strpos($foto, 'data:image') === 0) {
    // Extrair dados da imagem
    if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $foto, $matches)) {
        $ext = $matches[1];
        $data = base64_decode($matches[2]);

        // Criar diretorio se nao existir
        $upload_dir = dirname(dirname(dirname(__DIR__))) . '/uploads/delivery_photos/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $filename = 'coleta_' . $order_id . '_' . time() . '.' . $ext;
        $filepath = $upload_dir . $filename;

        if (file_put_contents($filepath, $data)) {
            $foto_path = '/uploads/delivery_photos/' . $filename;
        }
    }
}

// Salvar referencia da foto
if ($foto_path) {
    $pdo->prepare("UPDATE om_market_orders SET photo_coleta = ? WHERE order_id = ?")->execute([$foto_path, $order_id]);
}

// Registrar no historico
$pdo->prepare("INSERT INTO om_market_order_history (order_id, status, comment, created_at) VALUES (?, 'photo_coleta', 'Foto da coleta enviada', NOW())")
    ->execute([$order_id]);

jsonResponse([
    'success' => true,
    'message' => 'Foto da coleta salva com sucesso',
    'photo_url' => $foto_path
]);
