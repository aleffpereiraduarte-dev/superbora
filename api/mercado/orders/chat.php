<?php
/**
 * /api/mercado/orders/chat.php
 *
 * In-order chat between customer, partner/shopper, and driver.
 *
 * GET  ?order_id=X&chat_type=customer|delivery&since=datetime
 *   - Returns chat messages for an order
 *   - Requires customer OR partner OR motorista auth
 *
 * POST { order_id, message, chat_type: "customer"|"delivery" }
 *   - Send a message
 *   - Determines sender_type from token type
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Authenticate - accept customer, partner (shopper/parceiro), or motorista
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload) response(false, null, "Token invalido", 401);

    $userType = $payload['type'] ?? '';
    $userId = (int)($payload['uid'] ?? 0);

    // Map token type to sender_type
    $senderTypeMap = [
        'customer' => 'customer',
        'shopper'  => 'shopper',
        'parceiro' => 'shopper',
        'partner'  => 'shopper',
        'motorista' => 'delivery',
        'driver'   => 'delivery',
    ];

    $senderType = $senderTypeMap[$userType] ?? null;
    if (!$senderType) {
        response(false, null, "Tipo de usuario nao autorizado para chat", 403);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // Rate limiting: 30 chat messages per 5 minutes per user (POST only)
    if ($method === 'POST') {
        if (!checkRateLimit("chat_{$userType}_{$userId}", 30, 5)) {
            response(false, null, "Muitas mensagens. Aguarde alguns minutos.", 429);
        }
    }

    // =================== GET: List messages ===================
    if ($method === 'GET') {
        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$orderId) response(false, null, "order_id obrigatorio", 400);

        $chatType = $_GET['chat_type'] ?? 'customer';
        if (!in_array($chatType, ['customer', 'delivery', 'support'])) {
            $chatType = 'customer';
        }

        // Verify the user has access to this order
        verifyOrderAccess($db, $orderId, $senderType, $userId, $userType);

        // Fetch messages
        $since = $_GET['since'] ?? null;
        $params = [$orderId, $chatType];
        $sinceClause = '';

        if ($since) {
            $sinceClause = ' AND c.created_at > ?';
            $params[] = $since;
        }

        $stmt = $db->prepare("
            SELECT c.message_id,
                   c.order_id,
                   c.sender_type,
                   c.sender_id,
                   c.sender_name,
                   c.message,
                   c.message_type,
                   c.image_url,
                   c.chat_type,
                   c.is_read,
                   c.created_at
            FROM om_order_chat c
            WHERE c.order_id = ? AND c.chat_type = ? {$sinceClause}
            ORDER BY c.created_at ASC
            LIMIT 200
        ");
        $stmt->execute($params);
        $messages = $stmt->fetchAll();

        // Format messages
        $formatted = [];
        foreach ($messages as $msg) {
            $item = [
                'id' => (int)$msg['message_id'],
                'order_id' => (int)$msg['order_id'],
                'sender_type' => $msg['sender_type'],
                'sender_id' => (int)$msg['sender_id'],
                'sender_name' => $msg['sender_name'] ?: getSenderLabel($msg['sender_type']),
                'message' => $msg['message'],
                'message_type' => $msg['message_type'] ?? 'text',
                'chat_type' => $msg['chat_type'],
                'is_read' => (bool)$msg['is_read'],
                'created_at' => $msg['created_at'],
            ];
            if (!empty($msg['image_url'])) {
                $item['image_url'] = $msg['image_url'];
            }
            $formatted[] = $item;
        }

        // Mark messages from others as read
        $markStmt = $db->prepare("
            UPDATE om_order_chat
            SET is_read = 1
            WHERE order_id = ? AND chat_type = ? AND sender_type != ? AND is_read = 0
        ");
        $markStmt->execute([$orderId, $chatType, $senderType]);

        // Count unread across both chat types for this user
        $unreadStmt = $db->prepare("
            SELECT chat_type, COUNT(*) as cnt
            FROM om_order_chat
            WHERE order_id = ? AND sender_type != ? AND is_read = 0
            GROUP BY chat_type
        ");
        $unreadStmt->execute([$orderId, $senderType]);
        $unreadRows = $unreadStmt->fetchAll();
        $unread = ['customer' => 0, 'delivery' => 0, 'support' => 0];
        foreach ($unreadRows as $row) {
            $unread[$row['chat_type']] = (int)$row['cnt'];
        }

        response(true, [
            'messages' => $formatted,
            'total' => count($formatted),
            'unread' => $unread,
        ]);
    }

    // =================== POST: Send message ===================
    if ($method === 'POST') {
        $input = getInput();
        $orderId = (int)($input['order_id'] ?? 0);
        $messageType = $input['message_type'] ?? 'text';
        $chatType = $input['chat_type'] ?? 'customer';
        $imageUrl = null;

        if (!in_array($messageType, ['text', 'image'])) {
            $messageType = 'text';
        }
        if (!in_array($chatType, ['customer', 'delivery', 'support'])) {
            $chatType = 'customer';
        }
        if (!$orderId) response(false, null, "order_id obrigatorio", 400);

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
            $filename = sprintf('chat_%d_%d_%s.%s', $orderId, $userId, bin2hex(random_bytes(8)), $ext);
            $destPath = $uploadDir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                response(false, null, "Erro ao salvar imagem", 500);
            }

            $imageUrl = '/uploads/chat/' . $filename;
            $message = $input['message'] ?? '';
            $message = strip_tags(trim(substr($message, 0, 500)));
            if (empty($message)) {
                $message = '[Imagem]';
            }
        } else {
            // Text message
            $rawMessage = $input['message'] ?? '';
            if (mb_strlen($rawMessage) > 2000) {
                response(false, null, "Mensagem excede o limite de 2000 caracteres", 400);
            }
            $message = strip_tags(trim(substr($rawMessage, 0, 2000)));
            if (empty($message)) response(false, null, "Mensagem obrigatoria", 400);
        }

        // Verify order access
        verifyOrderAccess($db, $orderId, $senderType, $userId, $userType);

        // Verify order is active (not delivered/cancelled)
        $orderStmt = $db->prepare("SELECT status FROM om_market_orders WHERE order_id = ?");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        if (!$order) response(false, null, "Pedido nao encontrado", 404);

        $finishedStatuses = ['entregue', 'cancelado', 'cancelled', 'recusado'];
        if (in_array($order['status'], $finishedStatuses) && $chatType !== 'support') {
            response(false, null, "Nao e possivel enviar mensagens para este pedido", 400);
        }

        // Get sender name
        $senderName = getSenderName($db, $senderType, $userId);

        // Sanitize message
        $message = strip_tags($message);

        // Insert message
        $stmt = $db->prepare("
            INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, message_type, image_url, chat_type, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$orderId, $senderType, $userId, $senderName, $message, $messageType, $imageUrl, $chatType]);

        $messageId = (int)$db->lastInsertId();

        // Build full image URL for response
        $fullImageUrl = $imageUrl;
        if ($imageUrl && !str_starts_with($imageUrl, 'http')) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'superbora.com.br';
            $fullImageUrl = $scheme . '://' . $host . $imageUrl;
        }

        // WebSocket broadcast (never breaks the flow)
        try {
            $wsData = [
                'order_id' => $orderId,
                'message_id' => $messageId,
                'sender_type' => $senderType,
                'sender_id' => $userId,
                'sender_name' => $senderName,
                'message' => $message,
                'message_type' => $messageType,
                'chat_type' => $chatType,
                'created_at' => date('c'),
            ];
            if ($fullImageUrl) {
                $wsData['image_url'] = $fullImageUrl;
            }
            wsBroadcastToOrder($orderId, 'chat_message', $wsData);
        } catch (\Throwable $e) {}

        // Fetch inserted message
        $fetchStmt = $db->prepare("SELECT * FROM om_order_chat WHERE message_id = ?");
        $fetchStmt->execute([$messageId]);
        $newMsg = $fetchStmt->fetch();

        $responseData = [
            'id' => $messageId,
            'order_id' => $orderId,
            'sender_type' => $senderType,
            'sender_id' => $userId,
            'sender_name' => $senderName,
            'message' => $message,
            'message_type' => $messageType,
            'chat_type' => $chatType,
            'is_read' => false,
            'created_at' => $newMsg['created_at'] ?? date('Y-m-d H:i:s'),
        ];
        if ($fullImageUrl) {
            $responseData['image_url'] = $fullImageUrl;
        }

        response(true, $responseData, "Mensagem enviada");
    }

    // OPTIONS for CORS
    if ($method === 'OPTIONS') {
        response(true, null, "OK");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[API Order Chat] Erro: " . $e->getMessage());
    response(false, null, "Erro no chat", 500);
}

// =================== Helper functions ===================

function verifyOrderAccess(PDO $db, int $orderId, string $senderType, int $userId, string $tokenType = ''): void {
    if ($senderType === 'customer') {
        $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmt->execute([$orderId, $userId]);
        if (!$stmt->fetch()) {
            response(false, null, "Acesso negado a este pedido", 403);
        }
    } elseif ($senderType === 'shopper') {
        // Validate based on token type to prevent IDOR by ID collision
        // If token is partner/parceiro, only check partner_id; if shopper, only check shopper_id
        if (in_array($tokenType, ['partner', 'parceiro'])) {
            $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND partner_id = ?");
            $stmt->execute([$orderId, $userId]);
        } elseif ($tokenType === 'shopper') {
            $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
            $stmt->execute([$orderId, $userId]);
        } else {
            // Fallback for unknown sub-types: check both (legacy behavior)
            $stmt = $db->prepare("
                SELECT order_id FROM om_market_orders
                WHERE order_id = ? AND (partner_id = ? OR shopper_id = ?)
            ");
            $stmt->execute([$orderId, $userId, $userId]);
        }
        if (!$stmt->fetch()) {
            response(false, null, "Acesso negado a este pedido", 403);
        }
    } elseif ($senderType === 'delivery') {
        $stmt = $db->prepare("
            SELECT order_id FROM om_market_orders
            WHERE order_id = ? AND (driver_id = ? OR motorista_id = ?)
        ");
        $stmt->execute([$orderId, $userId, $userId]);
        if (!$stmt->fetch()) {
            response(false, null, "Acesso negado a este pedido", 403);
        }
    }
}

function getSenderName(PDO $db, string $senderType, int $userId): string {
    try {
        if ($senderType === 'customer') {
            $stmt = $db->prepare("SELECT name FROM om_market_customers WHERE customer_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            return $row ? ($row['name'] ?: 'Cliente') : 'Cliente';
        } elseif ($senderType === 'shopper') {
            // Try partners first, then shoppers
            $stmt = $db->prepare("SELECT trade_name as name FROM om_market_partners WHERE partner_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row && $row['name']) return $row['name'];

            $stmt2 = $db->prepare("SELECT name FROM om_market_shoppers WHERE shopper_id = ? LIMIT 1");
            $stmt2->execute([$userId]);
            $row2 = $stmt2->fetch();
            return $row2 ? ($row2['name'] ?: 'Loja') : 'Loja';
        } elseif ($senderType === 'delivery') {
            $stmt = $db->prepare("SELECT name FROM om_market_motoristas WHERE motorista_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            return $row ? ($row['name'] ?: 'Motorista') : 'Motorista';
        }
    } catch (Exception $e) {
        // Fallback
    }
    return getSenderLabel($senderType);
}

function getSenderLabel(string $senderType): string {
    $labels = [
        'customer' => 'Cliente',
        'shopper'  => 'Loja',
        'delivery' => 'Motorista',
        'system'   => 'Sistema',
    ];
    return $labels[$senderType] ?? 'Desconhecido';
}
