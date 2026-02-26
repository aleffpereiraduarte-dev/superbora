<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $ticket_id = (int)($_GET['ticket_id'] ?? 0);
        $order_id = (int)($_GET['order_id'] ?? 0);

        if ($ticket_id) {
            $stmt = $db->prepare("
                SELECT m.*,
                    CASE
                        WHEN m.remetente_tipo = 'admin' THEN (SELECT name FROM om_admins WHERE admin_id = m.remetente_id)
                        WHEN m.remetente_tipo = 'customer' THEN (SELECT firstname FROM oc_customer WHERE customer_id = m.remetente_id)
                        WHEN m.remetente_tipo = 'shopper' THEN (SELECT name FROM om_market_shoppers WHERE shopper_id = m.remetente_id)
                        WHEN m.remetente_tipo = 'partner' THEN (SELECT name FROM om_market_partners WHERE partner_id = m.remetente_id)
                        ELSE 'Sistema'
                    END as sender_name
                FROM om_support_messages m
                WHERE m.ticket_id = ?
                ORDER BY m.created_at ASC
            ");
            $stmt->execute([$ticket_id]);
            $messages = $stmt->fetchAll();
            response(true, ['messages' => $messages], "Mensagens carregadas");

        } elseif ($order_id) {
            $stmt = $db->prepare("
                SELECT ch.*,
                    CASE
                        WHEN ch.sender_type = 'admin' THEN (SELECT name FROM om_admins WHERE admin_id = ch.sender_id)
                        WHEN ch.sender_type = 'customer' THEN (SELECT firstname FROM oc_customer WHERE customer_id = ch.sender_id)
                        WHEN ch.sender_type = 'shopper' THEN (SELECT name FROM om_market_shoppers WHERE shopper_id = ch.sender_id)
                        WHEN ch.sender_type = 'partner' THEN (SELECT name FROM om_market_partners WHERE partner_id = ch.sender_id)
                        ELSE 'Sistema'
                    END as sender_name
                FROM om_order_chat ch
                WHERE ch.order_id = ?
                ORDER BY ch.created_at ASC
            ");
            $stmt->execute([$order_id]);
            $messages = $stmt->fetchAll();
            response(true, ['messages' => $messages], "Mensagens carregadas");

        } elseif (isset($_GET['list'])) {
            // List all conversations (tickets + order chats)
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 30;
            $offset = ($page - 1) * $limit;
            $search = trim($_GET['search'] ?? '');

            $conversations = [];

            // 1) Support tickets with last message
            // Escape LIKE special characters to prevent wildcard injection
            $search_escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $searchWhere = $search ? "AND (t.entidade_nome ILIKE ? OR t.assunto ILIKE ?)" : "";
            $searchParams = $search ? ["%{$search_escaped}%", "%{$search_escaped}%"] : [];

            $stmtTickets = $db->prepare("
                SELECT t.id, t.ticket_number, t.entidade_tipo, t.entidade_id, t.entidade_nome,
                    t.assunto, t.status, t.categoria, t.updated_at,
                    'ticket' as source,
                    (SELECT mensagem FROM om_support_messages WHERE ticket_id = t.id ORDER BY created_at DESC LIMIT 1) as last_message,
                    (SELECT remetente_tipo FROM om_support_messages WHERE ticket_id = t.id ORDER BY created_at DESC LIMIT 1) as last_sender,
                    (SELECT COUNT(*) FROM om_support_messages WHERE ticket_id = t.id AND lida = 0 AND remetente_tipo != 'admin') as unread_count,
                    (SELECT COUNT(*) FROM om_support_messages WHERE ticket_id = t.id) as message_count
                FROM om_support_tickets t
                WHERE 1=1 $searchWhere
                ORDER BY t.updated_at DESC
                LIMIT ? OFFSET ?
            ");
            $searchParams[] = (int)$limit;
            $searchParams[] = (int)$offset;
            $stmtTickets->execute($searchParams);
            $ticketRows = $stmtTickets->fetchAll();

            foreach ($ticketRows as $row) {
                $conversations[] = [
                    'id' => $row['id'],
                    'source' => 'ticket',
                    'ticket_number' => $row['ticket_number'],
                    'entity_type' => $row['entidade_tipo'],
                    'entity_name' => $row['entidade_nome'],
                    'subject' => $row['assunto'],
                    'category' => $row['categoria'],
                    'status' => $row['status'],
                    'last_message' => $row['last_message'] ? mb_substr($row['last_message'], 0, 100) : null,
                    'last_sender' => $row['last_sender'],
                    'unread_count' => (int)$row['unread_count'],
                    'message_count' => (int)$row['message_count'],
                    'updated_at' => $row['updated_at'],
                ];
            }

            // 2) Order chats with recent activity
            try {
                $stmtOrders = $db->query("
                    SELECT ch.order_id,
                        MAX(ch.created_at) as last_activity,
                        COUNT(*) as message_count,
                        (SELECT message FROM om_order_chat WHERE order_id = ch.order_id ORDER BY created_at DESC LIMIT 1) as last_message,
                        (SELECT sender_type FROM om_order_chat WHERE order_id = ch.order_id ORDER BY created_at DESC LIMIT 1) as last_sender,
                        o.status as order_status,
                        p.nome as partner_name
                    FROM om_order_chat ch
                    LEFT JOIN om_market_orders o ON o.order_id = ch.order_id
                    LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
                    WHERE ch.created_at > NOW() - INTERVAL '7 days'
                    GROUP BY ch.order_id, o.status, p.nome
                    ORDER BY last_activity DESC
                    LIMIT 20
                ");
                foreach ($stmtOrders->fetchAll() as $row) {
                    $conversations[] = [
                        'id' => $row['order_id'],
                        'source' => 'order_chat',
                        'ticket_number' => null,
                        'entity_type' => 'order',
                        'entity_name' => $row['partner_name'] ?: "Pedido #{$row['order_id']}",
                        'subject' => "Chat Pedido #{$row['order_id']}",
                        'category' => 'pedido',
                        'status' => $row['order_status'],
                        'last_message' => $row['last_message'] ? mb_substr($row['last_message'], 0, 100) : null,
                        'last_sender' => $row['last_sender'],
                        'unread_count' => 0,
                        'message_count' => (int)$row['message_count'],
                        'updated_at' => $row['last_activity'],
                    ];
                }
            } catch (Exception $e) {
                // om_order_chat may not exist
            }

            // Sort all by updated_at
            usort($conversations, fn($a, $b) => strtotime($b['updated_at']) - strtotime($a['updated_at']));

            response(true, ['conversations' => $conversations]);
        } else {
            response(false, null, "ticket_id, order_id ou list obrigatorio", 400);
        }

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $ticket_id = (int)($input['ticket_id'] ?? 0);
        $message = trim($input['message'] ?? '');

        if (!$ticket_id || !$message) response(false, null, "ticket_id e message obrigatorios", 400);

        // Sanitize to prevent stored XSS
        $message = strip_tags($message);

        $stmt = $db->prepare("
            INSERT INTO om_support_messages (ticket_id, remetente_tipo, remetente_id, mensagem, created_at)
            VALUES (?, 'admin', ?, ?, NOW())
        ");
        $stmt->execute([$ticket_id, $admin_id, $message]);
        $msg_id = (int)$db->lastInsertId();

        // Update ticket status to in_progress if open
        $stmt = $db->prepare("
            UPDATE om_support_tickets
            SET status = 'in_progress', updated_at = NOW()
            WHERE id = ? AND status = 'open'
        ");
        $stmt->execute([$ticket_id]);

        response(true, ['message_id' => $msg_id], "Mensagem enviada");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/chat-central] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
