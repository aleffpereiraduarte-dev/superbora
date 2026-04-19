<?php
/**
 * Proactive Support Cron
 *
 * Runs every 10 minutes via crontab. Detects situations where the customer
 * is silently suffering and creates an AI ticket + push notification to
 * acknowledge the problem before they get angry enough to complain.
 *
 * Detected situations:
 *   1. PIX expired (paid via PIX, status still aguardando_pagamento, > 30min old)
 *   2. Order delivery very late (estimated_delivery_at + 30min and not delivered)
 *   3. Order ready but not picked up by driver in 15min
 *
 * Each situation creates:
 *   - One assistant message in om_ai_support_messages with apology + action card
 *   - One ticket in om_ai_support_tickets (or attaches to open one)
 *   - One push notification ("Notamos que seu pedido...")
 *   - Audit log entry
 *
 * Idempotency: each (customer_id, order_id, situation) is logged in
 * om_ai_action_log with action_name='proactive_X' so the same situation
 * is never alerted twice.
 *
 * Usage (crontab):
 *   every 10 minutes: php /var/www/html/api/mercado/cron/proactive-support.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/notify.php';

$db = getDB();
$now = date('Y-m-d H:i:s');
$alerted = ['pix_expired' => 0, 'late_delivery' => 0, 'ready_too_long' => 0];

function alreadyAlerted($db, $customerId, $orderId, $situation) {
    $stmt = $db->prepare("
        SELECT 1 FROM om_ai_action_log
        WHERE customer_id = ?
          AND action_name = ?
          AND (params->>'order_id')::int = ?
          AND created_at > NOW() - INTERVAL '24 hours'
        LIMIT 1
    ");
    $stmt->execute([$customerId, "proactive_{$situation}", $orderId]);
    return (bool)$stmt->fetchColumn();
}

function logAlert($db, $customerId, $orderId, $situation, $message) {
    $stmt = $db->prepare("
        INSERT INTO om_ai_action_log
          (customer_id, ticket_id, action_name, params, success, result, created_at)
        VALUES (?, NULL, ?, ?::jsonb, TRUE, ?::jsonb, NOW())
    ");
    $stmt->execute([
        $customerId,
        "proactive_{$situation}",
        json_encode(['order_id' => $orderId, 'situation' => $situation]),
        json_encode(['message' => $message]),
    ]);
}

function getOrCreateTicket($db, $customerId, $orderId, $category) {
    // Try to attach to an open AI ticket for this customer + order
    $stmt = $db->prepare("
        SELECT ticket_id FROM om_ai_support_tickets
        WHERE customer_id = ? AND order_id = ? AND status IN ('open', 'escalated')
        ORDER BY ticket_id DESC LIMIT 1
    ");
    $stmt->execute([$customerId, $orderId]);
    $existing = $stmt->fetchColumn();
    if ($existing) return (int)$existing;

    $stmt = $db->prepare("
        INSERT INTO om_ai_support_tickets (customer_id, order_id, status, category, created_at)
        VALUES (?, ?, 'open', ?, NOW())
        RETURNING ticket_id
    ");
    $stmt->execute([$customerId, $orderId, $category]);
    return (int)$stmt->fetchColumn();
}

function postProactiveMessage($db, $customerId, $orderId, $situation, $message, $category) {
    $ticketId = getOrCreateTicket($db, $customerId, $orderId, $category);
    $stmt = $db->prepare("
        INSERT INTO om_ai_support_messages (ticket_id, role, content, metadata, created_at)
        VALUES (?, 'assistant', ?, ?::jsonb, NOW())
    ");
    $stmt->execute([
        $ticketId,
        $message,
        json_encode([
            'source' => 'proactive',
            'situation' => $situation,
            'order_id' => $orderId,
            'sentiment' => 'apology',
        ]),
    ]);
    return $ticketId;
}

// ─── 1. PIX expired (paid > 30min ago, still aguardando_pagamento) ──────────
try {
    $stmt = $db->query("
        SELECT o.order_id, o.customer_id, o.total, p.name as partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.payment_method ILIKE '%pix%'
          AND o.status IN ('aguardando_pagamento', 'pendente', 'pix_pendente')
          AND o.created_at < NOW() - INTERVAL '30 minutes'
          AND o.created_at > NOW() - INTERVAL '24 hours'
    ");
    foreach ($stmt as $row) {
        if (alreadyAlerted($db, $row['customer_id'], $row['order_id'], 'pix_expired')) continue;

        $msg = "Oi! Notei que seu PIX do pedido #{$row['order_id']} (R$ "
            . number_format((float)$row['total'], 2, ',', '.')
            . ") ainda não confirmou após 30 minutos. Provavelmente expirou.\n\n"
            . "Quer que eu te ajude a refazer o pagamento ou você prefere que eu cancele o pedido?";

        $ticketId = postProactiveMessage($db, $row['customer_id'], $row['order_id'], 'pix_expired', $msg, 'payment');
        logAlert($db, $row['customer_id'], $row['order_id'], 'pix_expired', $msg);

        notifyCustomer($db, (int)$row['customer_id'],
            'PIX pendente',
            'Seu PIX do pedido #' . $row['order_id'] . ' parece ter expirado. Toque pra resolver.',
            '/suporte-ia?ticket=' . $ticketId,
            ['type' => 'proactive_pix', 'ticket_id' => $ticketId, 'order_id' => $row['order_id'], 'screen' => '/suporte-ia']
        );
        $alerted['pix_expired']++;
    }
} catch (\Throwable $e) {
    error_log('[proactive-support] pix_expired: ' . $e->getMessage());
}

// ─── 2. Late delivery (>30min past estimated_delivery_at) ──────────────────
try {
    $stmt = $db->query("
        SELECT o.order_id, o.customer_id, o.total, o.estimated_delivery_at, p.name as partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.status IN ('em_entrega', 'em_rota', 'em_preparo', 'preparando')
          AND o.estimated_delivery_at IS NOT NULL
          AND o.estimated_delivery_at < NOW() - INTERVAL '30 minutes'
          AND o.created_at > NOW() - INTERVAL '6 hours'
    ");
    foreach ($stmt as $row) {
        if (alreadyAlerted($db, $row['customer_id'], $row['order_id'], 'late_delivery')) continue;

        $minutesLate = round((time() - strtotime($row['estimated_delivery_at'])) / 60);
        $msg = "Olá! Notei que seu pedido #{$row['order_id']} ({$row['partner_name']}) "
            . "está atrasado em cerca de {$minutesLate} minutos do horário previsto.\n\n"
            . "Sinto muito pelo transtorno! Quer que eu rastreie o status agora ou prefere uma compensação pelo atraso?";

        $ticketId = postProactiveMessage($db, $row['customer_id'], $row['order_id'], 'late_delivery', $msg, 'delivery');
        logAlert($db, $row['customer_id'], $row['order_id'], 'late_delivery', $msg);

        notifyCustomer($db, (int)$row['customer_id'],
            'Seu pedido está atrasado',
            'Pedido #' . $row['order_id'] . ' atrasou ' . $minutesLate . ' min. Toque pra ver opções.',
            '/suporte-ia?ticket=' . $ticketId,
            ['type' => 'proactive_late', 'ticket_id' => $ticketId, 'order_id' => $row['order_id'], 'screen' => '/suporte-ia']
        );
        $alerted['late_delivery']++;
    }
} catch (\Throwable $e) {
    error_log('[proactive-support] late_delivery: ' . $e->getMessage());
}

// ─── 3. Order ready but no driver assigned in 15min ────────────────────────
try {
    $stmt = $db->query("
        SELECT o.order_id, o.customer_id, p.name as partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.status IN ('pronto', 'ready')
          AND o.ready_at IS NOT NULL
          AND o.ready_at < NOW() - INTERVAL '15 minutes'
          AND o.created_at > NOW() - INTERVAL '6 hours'
    ");
    foreach ($stmt as $row) {
        if (alreadyAlerted($db, $row['customer_id'], $row['order_id'], 'ready_too_long')) continue;

        $msg = "Oi! Seu pedido #{$row['order_id']} já está pronto na {$row['partner_name']}, "
            . "mas ainda está aguardando entregador há mais de 15 minutos.\n\n"
            . "Estou de olho aqui! Quer que eu tente acelerar a alocação ou prefere outra opção?";

        $ticketId = postProactiveMessage($db, $row['customer_id'], $row['order_id'], 'ready_too_long', $msg, 'delivery');
        logAlert($db, $row['customer_id'], $row['order_id'], 'ready_too_long', $msg);

        notifyCustomer($db, (int)$row['customer_id'],
            'Pedido pronto aguardando entregador',
            'Seu pedido #' . $row['order_id'] . ' está pronto. Toque pra acelerar.',
            '/suporte-ia?ticket=' . $ticketId,
            ['type' => 'proactive_ready', 'ticket_id' => $ticketId, 'order_id' => $row['order_id'], 'screen' => '/suporte-ia']
        );
        $alerted['ready_too_long']++;
    }
} catch (\Throwable $e) {
    error_log('[proactive-support] ready_too_long: ' . $e->getMessage());
}

echo "[proactive-support {$now}] alerted: pix={$alerted['pix_expired']}, late={$alerted['late_delivery']}, ready={$alerted['ready_too_long']}\n";

// Heartbeat for cron-watchdog
@file_put_contents('/var/log/cron-heartbeat-proactive-support.txt', time());
