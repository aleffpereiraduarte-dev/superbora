<?php
/**
 * API: Substituição de Produto
 * GET /api/substitute.php - Sugestões de substituição
 * POST /api/substitute.php - Confirmar substituição
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) { jsonError('Erro de conexão', 500); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $productId = $_GET['product_id'] ?? null;
    $orderId = $_GET['order_id'] ?? null;
    
    if (!$productId) { jsonError('ID do produto é obrigatório'); }
    
    try {
        // Buscar produto original
        $stmt = $db->prepare("SELECT product_id, name, model, price, image FROM oc_product WHERE product_id = ?");
        $stmt->execute([$productId]);
        $original = $stmt->fetch();
        
        if (!$original) { jsonError('Produto não encontrado', 404); }
        
        // Buscar sugestões (mesma categoria, preço similar)
        $stmt = $db->prepare("
            SELECT p.product_id, pd.name, p.model, p.price, p.image, p.quantity as stock
            FROM oc_product p
            JOIN oc_product_description pd ON pd.product_id = p.product_id
            JOIN oc_product_to_category pc ON pc.product_id = p.product_id
            WHERE pc.category_id IN (SELECT category_id FROM oc_product_to_category WHERE product_id = ?)
            AND p.product_id != ?
            AND p.status = '1' AND p.quantity > 0
            AND p.price BETWEEN ? * 0.7 AND ? * 1.3
            ORDER BY ABS(p.price - ?) ASC
            LIMIT 10
        ");
        $stmt->execute([$productId, $productId, $original['price'], $original['price'], $original['price']]);
        $suggestions = $stmt->fetchAll();
        
        jsonSuccess([
            'original' => $original,
            'suggestions' => $suggestions
        ]);
    } catch (Exception $e) {
        jsonError('Erro ao buscar sugestões', 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    $orderId = $input['order_id'] ?? null;
    $originalProductId = $input['original_product_id'] ?? null;
    $newProductId = $input['new_product_id'] ?? null;
    $reason = $input['reason'] ?? 'out_of_stock';
    
    if (!$orderId || !$originalProductId || !$newProductId) {
        jsonError('Dados incompletos');
    }
    
    try {
        $db->beginTransaction();
        
        // Verificar pedido
        $stmt = $db->prepare("SELECT id FROM " . table('orders') . " WHERE id = ? AND worker_id = ?");
        $stmt->execute([$orderId, $workerId]);
        if (!$stmt->fetch()) {
            $db->rollBack();
            jsonError('Pedido não encontrado', 404);
        }
        
        // Buscar preços
        $stmt = $db->prepare("SELECT price FROM oc_product WHERE product_id = ?");
        $stmt->execute([$originalProductId]);
        $originalPrice = $stmt->fetchColumn();
        
        $stmt->execute([$newProductId]);
        $newPrice = $stmt->fetchColumn();
        
        // Registrar substituição
        $stmt = $db->prepare("
            INSERT INTO " . table('order_substitutions') . "
            (order_id, worker_id, original_product_id, new_product_id, original_price, new_price, reason, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_approval', NOW())
        ");
        $stmt->execute([$orderId, $workerId, $originalProductId, $newProductId, $originalPrice, $newPrice, $reason]);
        
        // Notificar cliente (em produção: push notification)
        
        $db->commit();
        jsonSuccess(['price_diff' => $newPrice - $originalPrice], 'Substituição registrada. Aguardando aprovação do cliente.');
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('Erro ao registrar substituição', 500);
    }
}

jsonError('Método não permitido', 405);
