<?php
/**
 * GET/POST /api/mercado/customer/store-chat.php
 * Chat pré-pedido com a loja
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Nao autorizado", 401);
    }

    $customerId = (int)$payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // GET - Listar mensagens
    if ($method === 'GET') {
        $partnerId = (int)($_GET['partner_id'] ?? 0);

        if (!$partnerId) {
            response(false, null, "partner_id obrigatorio", 400);
        }

        // Buscar ou criar conversa (atomic INSERT ON CONFLICT to avoid race condition)
        $db->prepare("
            INSERT INTO om_store_chats (customer_id, partner_id)
            VALUES (?, ?)
            ON CONFLICT (customer_id, partner_id) DO NOTHING
        ")->execute([$customerId, $partnerId]);

        $stmt = $db->prepare("
            SELECT id FROM om_store_chats
            WHERE customer_id = ? AND partner_id = ?
        ");
        $stmt->execute([$customerId, $partnerId]);
        $chatId = (int)$stmt->fetchColumn();

        // Buscar mensagens
        $stmt = $db->prepare("
            SELECT id, sender, message, created_at
            FROM om_store_chat_messages
            WHERE chat_id = ?
            ORDER BY created_at ASC
            LIMIT 100
        ");
        $stmt->execute([$chatId]);
        $messages = $stmt->fetchAll();

        // Marcar como lidas
        $db->prepare("
            UPDATE om_store_chat_messages
            SET is_read = 1
            WHERE chat_id = ? AND sender = 'partner' AND is_read = 0
        ")->execute([$chatId]);

        response(true, [
            'chat_id' => $chatId,
            'messages' => array_map(function($m) {
                return [
                    'id' => (int)$m['id'],
                    'sender' => $m['sender'],
                    'message' => $m['message'],
                    'created_at' => $m['created_at'],
                ];
            }, $messages)
        ]);
    }

    // POST - Enviar mensagem
    if ($method === 'POST') {
        $input = getInput();
        $partnerId = (int)($input['partner_id'] ?? 0);
        $message = strip_tags(trim($input['message'] ?? ''));

        if (!$partnerId) {
            response(false, null, "partner_id obrigatorio", 400);
        }

        if (empty($message)) {
            response(false, null, "Mensagem obrigatoria", 400);
        }

        if (strlen($message) > 1000) {
            response(false, null, "Mensagem muito longa (max 1000 caracteres)", 400);
        }

        // Buscar ou criar conversa (atomic INSERT ON CONFLICT to avoid race condition)
        $db->prepare("
            INSERT INTO om_store_chats (customer_id, partner_id)
            VALUES (?, ?)
            ON CONFLICT (customer_id, partner_id) DO NOTHING
        ")->execute([$customerId, $partnerId]);

        $stmt = $db->prepare("
            SELECT id FROM om_store_chats
            WHERE customer_id = ? AND partner_id = ?
        ");
        $stmt->execute([$customerId, $partnerId]);
        $chatId = (int)$stmt->fetchColumn();

        // Inserir mensagem
        $stmt = $db->prepare("
            INSERT INTO om_store_chat_messages (chat_id, sender, message)
            VALUES (?, 'customer', ?)
        ");
        $stmt->execute([$chatId, $message]);
        $messageId = (int)$db->lastInsertId();

        // Atualizar last_message_at
        $db->prepare("
            UPDATE om_store_chats SET last_message_at = NOW()
            WHERE id = ?
        ")->execute([$chatId]);

        // TODO: Notificar parceiro via Pusher

        response(true, [
            'message_id' => $messageId,
            'message' => 'Mensagem enviada!'
        ]);
    }

} catch (Exception $e) {
    error_log("[customer/store-chat] Erro: " . $e->getMessage());
    response(false, null, "Erro no chat", 500);
}
