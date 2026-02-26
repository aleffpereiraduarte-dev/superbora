<?php
/**
 * API: Chat/Mensagens
 * GET /api/chat.php - Listar mensagens
 * POST /api/chat.php - Enviar mensagem
 */
require_once 'db.php';

$workerId = requireAuth();
$db = getDB();

if (!$db) {
    jsonError('Erro de conexão', 500);
}

// GET - Listar mensagens
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $orderId = $_GET['order_id'] ?? null;
    $chatType = $_GET['type'] ?? 'support'; // support, order, shopper

    try {
        if ($chatType === 'support') {
            // Chat com suporte
            $stmt = $db->prepare("
                SELECT id, sender_type, message, created_at, is_read
                FROM " . table('support_chats') . "
                WHERE worker_id = ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$workerId]);
            $messages = $stmt->fetchAll();

            // Marcar como lidas
            $stmt = $db->prepare("
                UPDATE " . table('support_chats') . "
                SET is_read = 1
                WHERE worker_id = ? AND sender_type = 'support' AND is_read = 0
            ");
            $stmt->execute([$workerId]);

        } else {
            // Chat do pedido
            if (!$orderId) {
                jsonError('ID do pedido é obrigatório');
            }

            $stmt = $db->prepare("
                SELECT m.id, m.sender_type, m.sender_id, m.message, m.created_at,
                       w.name as worker_name, c.firstname as customer_name
                FROM " . table('order_messages') . " m
                LEFT JOIN " . table('workers') . " w ON w.id = m.sender_id AND m.sender_type = 'worker'
                LEFT JOIN oc_customer c ON c.customer_id = m.sender_id AND m.sender_type = 'customer'
                WHERE m.order_id = ?
                ORDER BY m.created_at ASC
            ");
            $stmt->execute([$orderId]);
            $messages = $stmt->fetchAll();
        }

        jsonSuccess(['messages' => $messages]);

    } catch (Exception $e) {
        error_log("Chat GET error: " . $e->getMessage());
        jsonError('Erro ao buscar mensagens', 500);
    }
}

// POST - Enviar mensagem
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();

    $message = trim($input['message'] ?? '');
    $orderId = $input['order_id'] ?? null;
    $chatType = $input['type'] ?? 'support';

    if (empty($message)) {
        jsonError('Mensagem não pode estar vazia');
    }

    if (strlen($message) > 1000) {
        jsonError('Mensagem muito longa (máximo 1000 caracteres)');
    }

    try {
        if ($chatType === 'support') {
            // Chat com suporte
            $stmt = $db->prepare("
                INSERT INTO " . table('support_chats') . "
                (worker_id, sender_type, message, created_at)
                VALUES (?, 'worker', ?, NOW())
            ");
            $stmt->execute([$workerId, $message]);

            // Notificar suporte (em produção: webhook, email, etc)

        } else {
            // Chat do pedido
            if (!$orderId) {
                jsonError('ID do pedido é obrigatório');
            }

            // Verificar se pedido pertence ao trabalhador
            $stmt = $db->prepare("
                SELECT id, customer_id FROM " . table('orders') . "
                WHERE id = ? AND worker_id = ?
            ");
            $stmt->execute([$orderId, $workerId]);
            $order = $stmt->fetch();

            if (!$order) {
                jsonError('Pedido não encontrado', 404);
            }

            $stmt = $db->prepare("
                INSERT INTO " . table('order_messages') . "
                (order_id, sender_type, sender_id, message, created_at)
                VALUES (?, 'worker', ?, ?, NOW())
            ");
            $stmt->execute([$orderId, $workerId, $message]);

            // Notificar cliente (push notification)
            // Em produção: enviar via Firebase, OneSignal, etc
        }

        $messageId = $db->lastInsertId();

        jsonSuccess([
            'message_id' => $messageId,
            'sent_at' => date('Y-m-d H:i:s')
        ], 'Mensagem enviada');

    } catch (Exception $e) {
        error_log("Chat POST error: " . $e->getMessage());
        jsonError('Erro ao enviar mensagem', 500);
    }
}

jsonError('Método não permitido', 405);
