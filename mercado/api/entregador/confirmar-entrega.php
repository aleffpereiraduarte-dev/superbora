<?php
/**
 * API: Confirmar entrega com codigo
 * POST /mercado/api/entregador/confirmar-entrega.php
 */
require_once __DIR__ . '/config.php';

$input = getInput();
$order_id = (int)($input['order_id'] ?? 0);
$driver_id = (int)($input['driver_id'] ?? 0);
$codigo = trim($input['codigo'] ?? '');
$foto_entrega = $input['foto_entrega'] ?? '';

if (!$order_id || !$driver_id) {
    jsonResponse(['success' => false, 'error' => 'order_id e driver_id obrigatorios'], 400);
}

$pdo = getDB();

// Verificar driver
$driver = validateDriver($driver_id);
if (!$driver) {
    jsonResponse(['success' => false, 'error' => 'Motorista nao encontrado'], 404);
}

// Verificar pedido
$stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ? AND delivery_driver_id = ?");
$stmt->execute([$order_id, $driver_id]);
$order = $stmt->fetch();

if (!$order) {
    jsonResponse(['success' => false, 'error' => 'Pedido nao encontrado ou nao atribuido a voce'], 404);
}

// Validar codigo de entrega
if ($codigo && strtoupper($codigo) !== strtoupper($order['delivery_code'] ?? '')) {
    jsonResponse(['success' => false, 'error' => 'Codigo de entrega invalido'], 400);
}

$pdo->beginTransaction();
try {
    // Salvar foto se fornecida
    $foto_path = null;
    if ($foto_entrega && strpos($foto_entrega, 'data:image') === 0) {
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $foto_entrega, $matches)) {
            $ext = $matches[1];
            $data = base64_decode($matches[2]);

            $upload_dir = dirname(dirname(dirname(__DIR__))) . '/uploads/delivery_photos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $filename = 'entrega_' . $order_id . '_' . time() . '.' . $ext;
            if (file_put_contents($upload_dir . $filename, $data)) {
                $foto_path = '/uploads/delivery_photos/' . $filename;
            }
        }
    }

    // Calcular ganho do entregador
    $delivery_fee = (float)($order['delivery_fee'] ?? 8.00);

    // Atualizar pedido
    $stmt = $pdo->prepare("UPDATE om_market_orders SET
        status = 'delivered',
        delivered_at = NOW(),
        delivery_photo = COALESCE(?, delivery_photo),
        delivery_photo_at = CASE WHEN ? IS NOT NULL THEN NOW() ELSE delivery_photo_at END
        WHERE order_id = ?
    ");
    $stmt->execute([$foto_path, $foto_path, $order_id]);

    // Creditar ganho ao motorista
    $pdo->prepare("UPDATE om_boraum_drivers SET
        balance = balance + ?,
        total_earnings = total_earnings + ?,
        total_rides = total_rides + 1,
        is_available = 1
        WHERE driver_id = ?
    ")->execute([$delivery_fee, $delivery_fee, $driver_id]);

    // Registrar transacao
    try {
        $pdo->prepare("INSERT INTO om_boraum_transactions (user_type, user_id, type, amount, description, created_at) VALUES ('driver', ?, 'delivery_earning', ?, ?, NOW())")
            ->execute([$driver_id, $delivery_fee, 'Entrega pedido #' . $order_id]);
    } catch (Exception $e) {}

    // Registrar no historico
    try {
        $pdo->prepare("INSERT INTO om_market_order_history (order_id, status, comment, created_at) VALUES (?, 'delivered', ?, NOW())")
            ->execute([$order_id, 'Entrega confirmada pelo entregador ' . $driver['name']]);
    } catch (Exception $e) {}

    $pdo->commit();

    jsonResponse([
        'success' => true,
        'message' => 'Entrega confirmada com sucesso!',
        'order_id' => $order_id,
        'earnings' => $delivery_fee,
        'driver' => [
            'id' => $driver_id,
            'name' => $driver['name'],
            'new_balance' => (float)$driver['balance'] + $delivery_fee
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Erro ao confirmar entrega: ' . $e->getMessage()], 500);
}
