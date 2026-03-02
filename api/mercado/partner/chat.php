<?php
/**
 * GET/POST /api/mercado/partner/chat.php
 * GET  ?mode=list              - Lista pedidos com mensagens
 * GET  ?order_id=123&since=... - Mensagens de um pedido (polling)
 * POST { order_id, message }   - Enviar mensagem como partner (JSON)
 * POST FormData { order_id, message, message_type=image, photo } - Enviar imagem
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
                    SELECT id, order_id, sender_type, sender_name, message,
                           attachment_url, attachment_type, created_at
                    FROM om_market_order_chat
                    WHERE order_id = ? AND created_at > ?
                    ORDER BY created_at ASC
                ");
                $stmt->execute([$orderId, $since]);
            } else {
                $stmt = $db->prepare("
                    SELECT id, order_id, sender_type, sender_name, message,
                           attachment_url, attachment_type, created_at
                    FROM om_market_order_chat
                    WHERE order_id = ?
                    ORDER BY created_at ASC
                    LIMIT 100
                ");
                $stmt->execute([$orderId]);
            }

            $messages = $stmt->fetchAll();

            // Build full URLs for attachments
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'superbora.com.br';
            foreach ($messages as &$msg) {
                if (!empty($msg['attachment_url']) && !str_starts_with($msg['attachment_url'], 'http')) {
                    $msg['attachment_url'] = $scheme . '://' . $host . $msg['attachment_url'];
                }
            }
            unset($msg);

            response(true, ["messages" => $messages, "order_id" => $orderId]);
        }

        // Se nenhum modo especificado, retornar lista
        response(false, null, "Informe mode=list ou order_id", 400);
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        // Support both JSON and multipart FormData (for image uploads)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isMultipart = str_contains($contentType, 'multipart/form-data');

        if ($isMultipart) {
            $input = $_POST;
        } else {
            $input = getInput();
        }

        $orderId = (int)($input['order_id'] ?? 0);
        $messageType = $input['message_type'] ?? 'text';
        $attachmentUrl = null;
        $attachmentType = null;

        if (!in_array($messageType, ['text', 'image'])) {
            $messageType = 'text';
        }

        if (!$orderId) {
            response(false, null, "order_id obrigatorio", 400);
        }

        // Handle image upload
        if ($messageType === 'image') {
            if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                response(false, null, "Foto obrigatoria para mensagem de imagem", 400);
            }

            $file = $_FILES['photo'];

            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedType = $finfo->file($file['tmp_name']);
            if (!in_array($detectedType, $allowedTypes)) {
                response(false, null, "Tipo de arquivo nao permitido. Use JPEG, PNG, WebP ou GIF.", 400);
            }

            // Validate file size (5MB max)
            if ($file['size'] > 5 * 1024 * 1024) {
                response(false, null, "Imagem excede o limite de 5MB", 400);
            }

            // Create upload directory
            $uploadDir = dirname(__DIR__, 3) . '/uploads/chat/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $ext = match($detectedType) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
                default      => 'jpg',
            };
            $filename = sprintf('chat_%d_p%d_%s.%s', $orderId, $partnerId, bin2hex(random_bytes(8)), $ext);
            $destPath = $uploadDir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                response(false, null, "Erro ao salvar imagem", 500);
            }

            $attachmentUrl = '/uploads/chat/' . $filename;
            $attachmentType = 'image';
            $message = strip_tags(trim($input['message'] ?? ''));
            if (empty($message)) {
                $message = '[Imagem]';
            }
        } else {
            // Text message
            $message = strip_tags(trim($input['message'] ?? ''));

            if (!$message) {
                response(false, null, "order_id e message sao obrigatorios", 400);
            }

            if (strlen($message) > 1000) {
                response(false, null, "Mensagem muito longa (max 1000 caracteres)", 400);
            }
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
            INSERT INTO om_market_order_chat (order_id, sender_type, sender_id, sender_name, recipient_type, message, attachment_url, attachment_type, created_at)
            VALUES (?, 'partner', ?, ?, 'customer', ?, ?, ?, NOW())
            RETURNING id
        ");
        $stmt->execute([$orderId, $partnerId, $senderName, $message, $attachmentUrl, $attachmentType]);

        $msgId = $stmt->fetchColumn();

        // Build full URL for response
        $fullAttachmentUrl = $attachmentUrl;
        if ($attachmentUrl && !str_starts_with($attachmentUrl, 'http')) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'superbora.com.br';
            $fullAttachmentUrl = $scheme . '://' . $host . $attachmentUrl;
        }

        // Gravar tambem em om_order_chat (tabela unificada usada pelo app BoraUm)
        $omMessageType = $messageType === 'image' ? 'image' : 'text';
        $omImageUrl = $attachmentUrl; // relative path
        try {
            $db->prepare("
                INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, message_type, image_url, chat_type, is_read, created_at)
                VALUES (?, 'partner', ?, ?, ?, ?, ?, 'customer', 0, NOW())
            ")->execute([$orderId, $partnerId, $senderName, $message, $omMessageType, $omImageUrl]);
        } catch (Exception $e) {
            error_log("[partner/chat] Erro ao gravar em om_order_chat: " . $e->getMessage());
        }

        $chatData = [
            "id" => (int)$msgId,
            "order_id" => $orderId,
            "sender_type" => "partner",
            "sender_name" => $senderName,
            "message" => $message,
            "attachment_url" => $fullAttachmentUrl,
            "attachment_type" => $attachmentType,
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
