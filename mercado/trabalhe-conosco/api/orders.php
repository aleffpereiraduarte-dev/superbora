<?php
/**
 * API: Pedidos e Ofertas
 * GET /api/orders.php - Listar pedidos
 * GET /api/orders.php?id=X - Detalhes do pedido
 * POST /api/orders.php - Aceitar pedido
 * PUT /api/orders.php - Atualizar status
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Listar pedidos ou detalhes
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $orderId = $_GET['id'] ?? null;
    $status = $_GET['status'] ?? null;
    $type = $_GET['type'] ?? null; // offers, active, history

    try {
        // Detalhes de um pedido específico
        if ($orderId) {
            $stmt = $db->prepare("
                SELECT 
                    o.*,
                    s.name as store_name, s.address as store_address, s.phone as store_phone,
                    c.name as client_name, c.phone as client_phone, c.address as delivery_address
                FROM " . table('orders') . " o
                LEFT JOIN om_market_stores s ON s.id = o.store_id
                LEFT JOIN oc_customer c ON c.customer_id = o.customer_id
                WHERE o.id = ? AND (o.worker_id = ? OR o.worker_id IS NULL)
            ");
            $stmt->execute([$orderId, $workerId]);
            $order = $stmt->fetch();

            if (!$order) {
                jsonError('Pedido não encontrado', 404);
            }

            // Buscar itens do pedido
            $stmt = $db->prepare("
                SELECT oi.*, p.name as product_name, p.image
                FROM " . table('order_items') . " oi
                LEFT JOIN oc_product p ON p.product_id = oi.product_id
                WHERE oi.order_id = ?
            ");
            $stmt->execute([$orderId]);
            $order['items'] = $stmt->fetchAll();

            // Buscar timeline
            $stmt = $db->prepare("
                SELECT status, notes, created_at
                FROM " . table('order_timeline') . "
                WHERE order_id = ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$orderId]);
            $order['timeline'] = $stmt->fetchAll();

            jsonSuccess(['order' => $order]);
        }

        // Listar ofertas disponíveis
        if ($type === 'offers') {
            $stmt = $db->prepare("
                SELECT 
                    o.id, o.order_number, o.order_type, o.total_items, o.estimated_earnings,
                    o.pickup_distance, o.delivery_distance, o.expires_at,
                    s.name as store_name, s.address as store_address
                FROM " . table('orders') . " o
                LEFT JOIN om_market_stores s ON s.id = o.store_id
                WHERE o.status = 'pending' 
                AND o.worker_id IS NULL
                AND o.expires_at > NOW()
                ORDER BY o.created_at DESC
                LIMIT 10
            ");
            $stmt->execute();
            jsonSuccess(['offers' => $stmt->fetchAll()]);
        }

        // Pedido ativo
        if ($type === 'active') {
            $stmt = $db->prepare("
                SELECT 
                    o.*,
                    s.name as store_name, s.address as store_address,
                    c.name as client_name, c.address as delivery_address
                FROM " . table('orders') . " o
                LEFT JOIN om_market_stores s ON s.id = o.store_id
                LEFT JOIN oc_customer c ON c.customer_id = o.customer_id
                WHERE o.worker_id = ? AND o.status IN ('accepted', 'collecting', 'collected', 'delivering')
                LIMIT 1
            ");
            $stmt->execute([$workerId]);
            $order = $stmt->fetch();
            jsonSuccess(['order' => $order]);
        }

        // Histórico
        $stmt = $db->prepare("
            SELECT 
                o.id, o.order_number, o.order_type, o.status, o.earnings,
                o.completed_at, o.created_at,
                s.name as store_name
            FROM " . table('orders') . " o
            LEFT JOIN om_market_stores s ON s.id = o.store_id
            WHERE o.worker_id = ?
            ORDER BY o.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$workerId]);
        jsonSuccess(['orders' => $stmt->fetchAll()]);

    } catch (Exception $e) {
        error_log("Orders GET error: " . $e->getMessage());
        jsonError('Erro ao buscar pedidos', 500);
    }
}

// POST - Aceitar pedido
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    $orderId = $input['order_id'] ?? null;
    $action = $input['action'] ?? 'accept';

    if (!$orderId) {
        jsonError('ID do pedido é obrigatório');
    }

    try {
        $db->beginTransaction();

        // Verificar se pedido está disponível
        $stmt = $db->prepare("
            SELECT id, status, worker_id FROM " . table('orders') . "
            WHERE id = ? FOR UPDATE
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            $db->rollBack();
            jsonError('Pedido não encontrado', 404);
        }

        if ($action === 'accept') {
            if ($order['status'] !== 'pending' || $order['worker_id']) {
                $db->rollBack();
                jsonError('Pedido não está mais disponível');
            }

            // Aceitar pedido
            $stmt = $db->prepare("
                UPDATE " . table('orders') . "
                SET worker_id = ?, status = 'accepted', accepted_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$workerId, $orderId]);

            // Timeline
            $stmt = $db->prepare("
                INSERT INTO " . table('order_timeline') . " (order_id, status, notes, created_at)
                VALUES (?, 'accepted', 'Pedido aceito pelo trabalhador', NOW())
            ");
            $stmt->execute([$orderId]);

            $db->commit();
            jsonSuccess(['order_id' => $orderId], 'Pedido aceito com sucesso');
        }

        if ($action === 'reject') {
            // Registrar rejeição
            $stmt = $db->prepare("
                INSERT INTO " . table('order_rejections') . " (order_id, worker_id, reason, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$orderId, $workerId, $input['reason'] ?? '']);

            $db->commit();
            jsonSuccess([], 'Pedido rejeitado');
        }

        $db->rollBack();
        jsonError('Ação inválida');

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Orders POST error: " . $e->getMessage());
        jsonError('Erro ao processar pedido', 500);
    }
}

// PUT - Atualizar status do pedido
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = getJsonInput();
    $orderId = $input['order_id'] ?? null;
    $status = $input['status'] ?? null;
    $notes = $input['notes'] ?? '';

    if (!$orderId || !$status) {
        jsonError('ID e status são obrigatórios');
    }

    $validStatuses = ['collecting', 'collected', 'delivering', 'arrived', 'completed', 'cancelled'];
    if (!in_array($status, $validStatuses)) {
        jsonError('Status inválido');
    }

    try {
        $db->beginTransaction();

        // Verificar se pedido pertence ao trabalhador
        $stmt = $db->prepare("
            SELECT id, status FROM " . table('orders') . "
            WHERE id = ? AND worker_id = ?
        ");
        $stmt->execute([$orderId, $workerId]);
        $order = $stmt->fetch();

        if (!$order) {
            $db->rollBack();
            jsonError('Pedido não encontrado', 404);
        }

        // Atualizar status
        $extraFields = '';
        if ($status === 'collected') {
            $extraFields = ', collected_at = NOW()';
        } elseif ($status === 'completed') {
            $extraFields = ', completed_at = NOW()';
        }

        $stmt = $db->prepare("
            UPDATE " . table('orders') . "
            SET status = ? $extraFields
            WHERE id = ?
        ");
        $stmt->execute([$status, $orderId]);

        // Timeline
        $stmt = $db->prepare("
            INSERT INTO " . table('order_timeline') . " (order_id, status, notes, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$orderId, $status, $notes]);

        // Se completou, atualizar estatísticas do trabalhador
        if ($status === 'completed') {
            $stmt = $db->prepare("
                UPDATE " . table('workers') . "
                SET total_orders = total_orders + 1,
                    total_earnings = total_earnings + (SELECT earnings FROM " . table('orders') . " WHERE id = ?)
                WHERE id = ?
            ");
            $stmt->execute([$orderId, $workerId]);
        }

        $db->commit();
        jsonSuccess(['status' => $status], 'Status atualizado');

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Orders PUT error: " . $e->getMessage());
        jsonError('Erro ao atualizar status', 500);
    }
}

jsonError('Método não permitido', 405);
