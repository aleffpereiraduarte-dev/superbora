<?php
/**
 * GET/POST /api/mercado/partner/chat.php
 * GET  ?mode=list              - Lista pedidos com mensagens
 * GET  ?order_id=123&since=... - Mensagens de um pedido (polling)
 * POST { order_id, message }   - Enviar mensagem como partner
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Token ausente", 401);

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== OmAuth::USER_TYPE_PARTNER) {
        response(false, null, "Nao autorizado", 401);
    }

    $partnerId = $payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $mode = $_GET['mode'] ?? '';
        $orderId = (int)($_GET['order_id'] ?? 0);

        if ($mode === 'list') {
            // Lista pedidos que possuem mensagens de chat
            $stmt = $db->prepare("
                SELECT o.order_id, o.customer_name, o.status, o.created_at as order_date,
                       c.message as last_message, c.sender_type as last_sender, c.created_at as last_message_at,
                       (SELECT COUNT(*) FROM om_market_order_chat WHERE order_id = o.order_id) as msg_count
                FROM om_market_orders o
                INNER JOIN om_market_order_chat c ON c.order_id = o.order_id
                    AND c.id = (SELECT MAX(id) FROM om_market_order_chat WHERE order_id = o.order_id)
                WHERE o.partner_id = ?
                ORDER BY c.created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$partnerId]);
            $chats = $stmt->fetchAll();

            response(true, ["chats" => $chats]);
        }

        if ($orderId) {
            // Validar que pedido pertence ao parceiro
            $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND partner_id = ?");
            $stmt->execute([$orderId, $partnerId]);
            if (!$stmt->fetch()) {
                response(false, null, "Pedido nao encontrado", 404);
            }

            $since = $_GET['since'] ?? null;

            if ($since) {
                $stmt = $db->prepare("
                    SELECT id, order_id, sender_type, sender_name, message, created_at
                    FROM om_market_order_chat
                    WHERE order_id = ? AND created_at > ?
                    ORDER BY created_at ASC
                ");
                $stmt->execute([$orderId, $since]);
            } else {
                $stmt = $db->prepare("
                    SELECT id, order_id, sender_type, sender_name, message, created_at
                    FROM om_market_order_chat
                    WHERE order_id = ?
                    ORDER BY created_at ASC
                    LIMIT 100
                ");
                $stmt->execute([$orderId]);
            }

            $messages = $stmt->fetchAll();
            response(true, ["messages" => $messages, "order_id" => $orderId]);
        }

        // Se nenhum modo especificado, retornar lista
        response(false, null, "Informe mode=list ou order_id", 400);
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $orderId = (int)($input['order_id'] ?? 0);
        $message = strip_tags(trim($input['message'] ?? ''));

        if (!$orderId || !$message) {
            response(false, null, "order_id e message sao obrigatorios", 400);
        }

        if (strlen($message) > 1000) {
            response(false, null, "Mensagem muito longa (max 1000 caracteres)", 400);
        }

        // Validar que pedido pertence ao parceiro
        $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND partner_id = ?");
        $stmt->execute([$orderId, $partnerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Pedido nao encontrado", 404);
        }

        // Buscar nome do parceiro
        $stmt = $db->prepare("SELECT trade_name, name FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $partnerData = $stmt->fetch();
        $senderName = $partnerData['trade_name'] ?: $partnerData['name'];

        $stmt = $db->prepare("
            INSERT INTO om_market_order_chat (order_id, sender_type, sender_id, sender_name, recipient_type, message, created_at)
            VALUES (?, 'partner', ?, ?, 'customer', ?, NOW())
            RETURNING id
        ");
        $stmt->execute([$orderId, $partnerId, $senderName, $message]);

        $msgId = $stmt->fetchColumn();

        // Gravar tambem em om_order_chat (tabela unificada usada pelo app BoraUm)
        try {
            $db->prepare("
                INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, message_type, chat_type, is_read, created_at)
                VALUES (?, 'partner', ?, ?, ?, 'text', 'customer', 0, NOW())
            ")->execute([$orderId, $partnerId, $senderName, $message]);
        } catch (Exception $e) {
            error_log("[partner/chat] Erro ao gravar em om_order_chat: " . $e->getMessage());
        }

        $chatData = [
            "id" => (int)$msgId,
            "order_id" => $orderId,
            "sender_type" => "partner",
            "sender_name" => $senderName,
            "message" => $message
        ];

        // Pusher: notificar parceiro sobre nova mensagem em tempo real
        try {
            PusherService::chatMessage($partnerId, $chatData);
        } catch (Exception $pusherErr) {
            error_log("[partner/chat] Pusher erro: " . $pusherErr->getMessage());
        }

        response(true, $chatData);
    }

} catch (Exception $e) {
    error_log("[partner/chat] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar chat", 500);
}
