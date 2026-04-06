<?php
/**
 * /api/mercado/orders/chat-loja.php
 *
 * Store <-> Customer chat for active orders.
 * Uses the existing om_order_chat table with chat_type='customer'.
 *
 * GET  ?order_id=X&since=datetime
 *   - Returns chat messages between customer and store for an order
 *   - Also returns store info (name, logo) for the chat header
 *   - Requires customer OR partner auth
 *
 * POST { order_id, message, message_type? }
 *   - Send a message in the store chat
 *   - Sends push notification to the other party
 *   - Broadcasts via WebSocket
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
require_once __DIR__ . '/../helpers/ws-customer-broadcast.php';
require_once __DIR__ . '/../helpers/notify.php';
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Authenticate - accept customer or partner
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
    ];

    $senderType = $senderTypeMap[$userType] ?? null;
    if (!$senderType) {
        response(false, null, "Tipo de usuario nao autorizado para chat com loja", 403);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // Rate limiting: 30 messages per 5 minutes per user (POST only)
    if ($method === 'POST') {
        if (!checkRateLimit("chat_loja_{$userType}_{$userId}", 30, 5)) {
            response(false, null, "Muitas mensagens. Aguarde alguns minutos.", 429);
        }
    }

    // =================== GET: List messages + store info ===================
    if ($method === 'GET') {
        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$orderId) response(false, null, "order_id obrigatorio", 400);

        // Verify user has access to this order
        verifyStoreChattAccess($db, $orderId, $senderType, $userId, $userType);

        // Get order info with store details
        $orderStmt = $db->prepare("
            SELECT o.order_id, o.status, o.partner_id, o.customer_id,
                   p.trade_name AS store_name,
                   p.logo AS store_logo,
                   p.phone AS store_phone
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
            WHERE o.order_id = ?
        ");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        if (!$order) response(false, null, "Pedido nao encontrado", 404);

        // Fetch messages (chat_type = 'customer' for store<->customer)
        $since = $_GET['since'] ?? null;
        $params = [$orderId, 'customer'];
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
                'sender_name' => $msg['sender_name'] ?: ($msg['sender_type'] === 'customer' ? 'Cliente' : 'Loja'),
                'message' => $msg['message'],
                'message_type' => $msg['message_type'] ?? 'text',
                'is_read' => (bool)$msg['is_read'],
                'created_at' => $msg['created_at'],
            ];
            if (!empty($msg['image_url'])) {
                $item['image_url'] = $msg['image_url'];
            }
            $formatted[] = $item;
        }

        // Mark messages from the other party as read
        $markStmt = $db->prepare("
            UPDATE om_order_chat
            SET is_read = 1
            WHERE order_id = ? AND chat_type = 'customer' AND sender_type != ? AND is_read = 0
        ");
        $markStmt->execute([$orderId, $senderType]);

        // Count unread for this user
        $unreadStmt = $db->prepare("
            SELECT COUNT(*) as cnt
            FROM om_order_chat
            WHERE order_id = ? AND chat_type = 'customer' AND sender_type != ? AND is_read = 0
        ");
        $unreadStmt->execute([$orderId, $senderType]);
        $unreadCount = (int)$unreadStmt->fetchColumn();

        response(true, [
            'messages' => $formatted,
            'total' => count($formatted),
            'unread' => $unreadCount,
            'store' => [
                'name' => $order['store_name'] ?: 'Loja',
                'logo' => $order['store_logo'] ?: null,
                'phone' => $order['store_phone'] ?: null,
            ],
            'order' => [
                'order_id' => (int)$order['order_id'],
                'status' => $order['status'],
                'partner_id' => (int)$order['partner_id'],
                'customer_id' => (int)$order['customer_id'],
            ],
        ]);
    }

    // =================== POST: Send message ===================
    if ($method === 'POST') {
        $input = getInput();
        $orderId = (int)($input['order_id'] ?? 0);
        $messageType = $input['message_type'] ?? 'text';

        if (!in_array($messageType, ['text', 'image', 'quick_action'])) {
            $messageType = 'text';
        }
        // quick_action stored as 'text' in DB
        $dbMessageType = $messageType === 'quick_action' ? 'text' : $messageType;

        if (!$orderId) response(false, null, "order_id obrigatorio", 400);

        // Validate message
        $rawMessage = $input['message'] ?? '';
        if (mb_strlen($rawMessage) > 2000) {
            response(false, null, "Mensagem excede o limite de 2000 caracteres", 400);
        }
        $message = strip_tags(trim(substr($rawMessage, 0, 2000)));
        if (empty($message)) response(false, null, "Mensagem obrigatoria", 400);

        // Verify access
        verifyStoreChattAccess($db, $orderId, $senderType, $userId, $userType);

        // Verify order is active (not delivered/cancelled)
        $orderStmt = $db->prepare("
            SELECT o.status, o.partner_id, o.customer_id,
                   p.trade_name AS store_name
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
            WHERE o.order_id = ?
        ");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        if (!$order) response(false, null, "Pedido nao encontrado", 404);

        $finishedStatuses = ['entregue', 'cancelado', 'cancelled', 'recusado'];
        if (in_array($order['status'], $finishedStatuses)) {
            response(false, null, "Nao e possivel enviar mensagens para este pedido", 400);
        }

        // Get sender name
        $senderName = getSenderNameForChat($db, $senderType, $userId);

        // Insert message
        $stmt = $db->prepare("
            INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, message_type, chat_type, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'customer', 0, NOW())
        ");
        $stmt->execute([$orderId, $senderType, $userId, $senderName, $message, $dbMessageType]);

        $messageId = (int)$db->lastInsertId();

        // WebSocket broadcast (never breaks the flow)
        try {
            $wsData = [
                'order_id' => $orderId,
                'message_id' => $messageId,
                'sender_type' => $senderType,
                'sender_id' => $userId,
                'sender_name' => $senderName,
                'message' => $message,
                'message_type' => $dbMessageType,
                'chat_type' => 'customer',
                'created_at' => date('c'),
            ];
            wsBroadcastToOrder($orderId, 'chat_message', $wsData);
        } catch (\Throwable $e) {}

        // Push notification to the other party
        try {
            if ($senderType === 'customer') {
                // Customer sent message -> notify store/partner
                $partnerId = (int)$order['partner_id'];
                if ($partnerId) {
                    notifyPartner($db, $partnerId,
                        "Mensagem do cliente",
                        mb_substr($message, 0, 100),
                        '/painel/mercado/pedidos.php',
                        ['order_id' => $orderId, 'type' => 'store_chat', 'screen' => 'chat']
                    );
                }
            } else {
                // Store/shopper sent message -> notify customer
                $customerId = (int)$order['customer_id'];
                if ($customerId) {
                    $storeName = $order['store_name'] ?: 'Loja';
                    notifyCustomer($db, $customerId,
                        "Mensagem de {$storeName}",
                        mb_substr($message, 0, 100),
                        '/mercado/',
                        ['order_id' => $orderId, 'type' => 'store_chat', 'screen' => 'chat-loja']
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log("[chat-loja] Push notification error: " . $e->getMessage());
        }

        // Fetch inserted message
        $fetchStmt = $db->prepare("SELECT * FROM om_order_chat WHERE message_id = ?");
        $fetchStmt->execute([$messageId]);
        $newMsg = $fetchStmt->fetch();

        response(true, [
            'id' => $messageId,
            'order_id' => $orderId,
            'sender_type' => $senderType,
            'sender_id' => $userId,
            'sender_name' => $senderName,
            'message' => $message,
            'message_type' => $dbMessageType,
            'chat_type' => 'customer',
            'is_read' => false,
            'created_at' => $newMsg['created_at'] ?? date('Y-m-d H:i:s'),
        ], "Mensagem enviada");
    }

    // OPTIONS for CORS
    if ($method === 'OPTIONS') {
        response(true, null, "OK");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[API chat-loja] Erro: " . $e->getMessage());
    response(false, null, "Erro no chat", 500);
}

// =================== Helper functions ===================

function verifyStoreChattAccess(PDO $db, int $orderId, string $senderType, int $userId, string $tokenType = ''): void {
    if ($senderType === 'customer') {
        $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmt->execute([$orderId, $userId]);
        if (!$stmt->fetch()) {
            response(false, null, "Acesso negado a este pedido", 403);
        }
    } elseif ($senderType === 'shopper') {
        if (in_array($tokenType, ['partner', 'parceiro'])) {
            $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND partner_id = ?");
            $stmt->execute([$orderId, $userId]);
        } elseif ($tokenType === 'shopper') {
            $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND shopper_id = ?");
            $stmt->execute([$orderId, $userId]);
        } else {
            $stmt = $db->prepare("
                SELECT order_id FROM om_market_orders
                WHERE order_id = ? AND (partner_id = ? OR shopper_id = ?)
            ");
            $stmt->execute([$orderId, $userId, $userId]);
        }
        if (!$stmt->fetch()) {
            response(false, null, "Acesso negado a este pedido", 403);
        }
    } else {
        response(false, null, "Tipo de usuario nao autorizado", 403);
    }
}

function getSenderNameForChat(PDO $db, string $senderType, int $userId): string {
    try {
        if ($senderType === 'customer') {
            $stmt = $db->prepare("
                SELECT COALESCE(mc.name, oc.name) as name
                FROM (SELECT ?::int as cid) q
                LEFT JOIN om_market_customers mc ON mc.customer_id = q.cid
                LEFT JOIN om_customers oc ON oc.customer_id = q.cid
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            return $row ? ($row['name'] ?: 'Cliente') : 'Cliente';
        } elseif ($senderType === 'shopper') {
            $stmt = $db->prepare("SELECT trade_name as name FROM om_market_partners WHERE partner_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row && $row['name']) return $row['name'];

            $stmt2 = $db->prepare("SELECT name FROM om_market_shoppers WHERE shopper_id = ? LIMIT 1");
            $stmt2->execute([$userId]);
            $row2 = $stmt2->fetch();
            return $row2 ? ($row2['name'] ?: 'Loja') : 'Loja';
        }
    } catch (Exception $e) {
        error_log("[chat-loja] getSenderName error: " . $e->getMessage());
    }
    return $senderType === 'customer' ? 'Cliente' : 'Loja';
}
