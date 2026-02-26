<?php
/**
 * /api/mercado/boraum/chat.php
 * Chat do pedido - passageiro BoraUm conversa com estabelecimento e motorista
 *
 * GET  ?order_id=X                          - Buscar mensagens do pedido
 * GET  ?order_id=X&after=123                - Buscar novas mensagens apos message_id 123 (polling)
 * POST { order_id, message, to: "partner"|"delivery", image_url }  - Enviar mensagem
 * PUT  { order_id }                         - Marcar mensagens como lidas
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

setCorsHeaders();

try {
    $db = getDB();
    $user = requirePassageiro($db);

    $customerId = $user['customer_id'];

    // =====================================================================
    // GET - Buscar mensagens
    // =====================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$orderId) {
            response(false, null, "order_id obrigatorio", 400);
        }

        // Verificar que pedido pertence ao cliente
        $stmt = $db->prepare("SELECT order_id, partner_id, partner_name, driver_name, driver_phone, driver_photo, status FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmt->execute([$orderId, $customerId]);
        $order = $stmt->fetch();

        if (!$order) {
            response(false, null, "Pedido nao encontrado", 404);
        }

        // Buscar mensagens (todas ou apos um ID)
        $afterId = (int)($_GET['after'] ?? 0);
        if ($afterId > 0) {
            $stmt = $db->prepare("
                SELECT message_id, order_id, sender_type, sender_id, sender_name, message, message_type, image_url, attachment_url, is_read, created_at
                FROM om_order_chat
                WHERE order_id = ? AND message_id > ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$orderId, $afterId]);
        } else {
            $stmt = $db->prepare("
                SELECT message_id, order_id, sender_type, sender_id, sender_name, message, message_type, image_url, attachment_url, is_read, created_at
                FROM om_order_chat
                WHERE order_id = ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$orderId]);
        }

        $mensagens = [];
        foreach ($stmt->fetchAll() as $m) {
            $mensagens[] = [
                'id'         => (int)$m['message_id'],
                'tipo'       => $m['sender_type'],
                'nome'       => $m['sender_name'] ?: senderLabel($m['sender_type']),
                'mensagem'   => $m['message'],
                'tipo_msg'   => $m['message_type'] ?? 'text',
                'imagem'     => $m['image_url'] ?: ($m['attachment_url'] ?: null),
                'eu'         => $m['sender_type'] === 'customer',
                'lida'       => (bool)$m['is_read'],
                'hora'       => $m['created_at'],
            ];
        }

        // Contar nao lidas (mensagens que NAO sao do cliente e nao foram lidas)
        $stmtUnread = $db->prepare("
            SELECT COUNT(*) FROM om_order_chat
            WHERE order_id = ? AND sender_type != 'customer' AND is_read = 0
        ");
        $stmtUnread->execute([$orderId]);
        $naoLidas = (int)$stmtUnread->fetchColumn();

        // Info dos participantes
        $participantes = [
            'estabelecimento' => [
                'nome' => $order['partner_name'] ?? 'Estabelecimento',
            ],
        ];
        if ($order['driver_name']) {
            $participantes['motorista'] = [
                'nome'  => $order['driver_name'],
                'phone' => $order['driver_phone'] ?? null,
                'foto'  => $order['driver_photo'] ?? null,
            ];
        }

        response(true, [
            'order_id'      => $orderId,
            'status'        => $order['status'],
            'participantes' => $participantes,
            'nao_lidas'     => $naoLidas,
            'total'         => count($mensagens),
            'mensagens'     => $mensagens,
        ]);
    }

    // =====================================================================
    // PUT - Marcar como lidas
    // =====================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $input = getInput();
        $orderId = (int)($input['order_id'] ?? 0);
        if (!$orderId) {
            response(false, null, "order_id obrigatorio", 400);
        }

        // Verificar ownership
        $stmt = $db->prepare("SELECT order_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
        $stmt->execute([$orderId, $customerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Pedido nao encontrado", 404);
        }

        // Marcar como lidas todas as mensagens que NAO sao do cliente
        $stmt = $db->prepare("UPDATE om_order_chat SET is_read = 1 WHERE order_id = ? AND sender_type != 'customer' AND is_read = 0");
        $stmt->execute([$orderId]);
        $marcadas = $stmt->rowCount();

        response(true, ['marcadas_lidas' => $marcadas]);
    }

    // =====================================================================
    // POST - Enviar mensagem
    // =====================================================================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();
    $orderId = (int)($input['order_id'] ?? 0);
    $message = trim(substr($input['message'] ?? '', 0, 1000));
    $imageUrl = trim(substr($input['image_url'] ?? '', 0, 500)) ?: null;
    if ($imageUrl && !preg_match('#^https?://#i', $imageUrl)) {
        response(false, null, "URL de imagem invalida", 400);
    }
    $to = trim($input['to'] ?? '');

    if (!$orderId) {
        response(false, null, "order_id obrigatorio", 400);
    }

    if (empty($message) && empty($imageUrl)) {
        response(false, null, "Mensagem ou imagem obrigatoria", 400);
    }

    // Verificar ownership e status
    $stmt = $db->prepare("SELECT order_id, status, partner_id, partner_name, driver_name, driver_id FROM om_market_orders WHERE order_id = ? AND customer_id = ?");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Nao permitir chat em pedidos finalizados
    $statusFinais = ['entregue', 'cancelado', 'cancelled'];
    if (in_array($order['status'], $statusFinais)) {
        response(false, null, "Pedido ja finalizado. Nao e possivel enviar mensagens.", 400);
    }

    // Tipo de mensagem
    $messageType = $imageUrl ? 'image' : 'text';

    // Determinar chat_type baseado no destinatario
    $chatType = 'customer'; // padrao: conversa com loja
    if ($to === 'delivery' || $to === 'motorista') {
        $chatType = 'delivery';
    }

    // Sanitizar
    $message = strip_tags($message);

    $stmt = $db->prepare("
        INSERT INTO om_order_chat (order_id, sender_type, sender_id, sender_name, message, message_type, image_url, chat_type, is_read, created_at)
        VALUES (?, 'customer', ?, ?, ?, ?, ?, ?, 0, NOW())
    ");
    $stmt->execute([
        $orderId,
        $customerId,
        $user['nome'],
        $message ?: ($imageUrl ? '[Imagem]' : ''),
        $messageType,
        $imageUrl,
        $chatType,
    ]);

    $messageId = (int)$db->lastInsertId();

    // Gravar tambem em om_market_order_chat (painel do parceiro)
    if ($chatType === 'customer') {
        try {
            $db->prepare("
                INSERT INTO om_market_order_chat (order_id, sender_type, sender_id, sender_name, recipient_type, message, attachment_url, created_at)
                VALUES (?, 'customer', ?, ?, 'partner', ?, ?, NOW())
            ")->execute([$orderId, $customerId, $user['nome'], $message ?: '[Imagem]', $imageUrl]);
        } catch (Exception $e) {}
    }

    // Notificar estabelecimento (se mensagem pra loja)
    if ($chatType === 'customer') {
        try {
            require_once __DIR__ . '/../config/notify.php';
            sendNotification($db, (int)$order['partner_id'], 'partner',
                'Nova mensagem do cliente',
                $user['nome'] . ': ' . substr($message, 0, 100),
                ['order_id' => $orderId, 'type' => 'chat', 'url' => '/pedidos?id=' . $orderId]
            );
        } catch (Exception $e) {}
    }

    // Notificar motorista (se mensagem pro motorista)
    if ($chatType === 'delivery' && ($order['driver_id'] ?? 0) > 0) {
        try {
            require_once __DIR__ . '/../helpers/delivery.php';
            boraUmRequest('delivery/notify', 'POST', [
                'driver_id' => (int)$order['driver_id'],
                'type' => 'chat_message',
                'title' => 'Mensagem do cliente',
                'body' => $user['nome'] . ': ' . substr($message, 0, 100),
                'data' => ['order_id' => $orderId],
            ]);
        } catch (Exception $e) {}
    }

    response(true, [
        'message_id' => $messageId,
        'order_id'   => $orderId,
        'to'         => $chatType === 'delivery' ? 'motorista' : 'estabelecimento',
        'mensagem'   => $message,
        'tipo_msg'   => $messageType,
        'imagem'     => $imageUrl,
        'hora'       => date('Y-m-d H:i:s'),
    ], "Mensagem enviada");

} catch (Exception $e) {
    error_log("[BoraUm chat.php] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar chat. Tente novamente.", 500);
}

function senderLabel(string $type): string {
    return match($type) {
        'customer' => 'Voce',
        'partner'  => 'Estabelecimento',
        'delivery' => 'Entregador',
        'shopper'  => 'Comprador',
        'system'   => 'Sistema',
        default    => $type,
    };
}
