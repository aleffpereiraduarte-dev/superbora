<?php
/**
 * CRON: Payment Timeout Recovery
 * Run every 5 minutes via crontab
 *
 * Recovers stuck payments and prevents order limbo:
 * 1. Pending payments > 30 min → auto-cancel + restore stock + release shopper
 * 2. PIX expired > 1 hour → same cancel logic
 * 3. Paid but stuck in 'pending' status > 10 min → alert admin (do NOT cancel)
 */

$isCli = php_sapi_name() === 'cli';

function cron_log($msg) {
    global $isCli;
    $line = "[" . date('Y-m-d H:i:s') . "] [payment-timeout] {$msg}";
    if ($isCli) echo $line . "\n";
    error_log($line);
}

try {
    $db = new PDO(
        "pgsql:host=147.93.12.236;port=5432;dbname=love1",
        'love1', 'Aleff2009@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $stats = [
        'checked' => 0,
        'cancelled_timeout' => 0,
        'cancelled_pix' => 0,
        'alerted_paid_stuck' => 0,
        'stock_restored' => 0,
        'errors' => 0,
    ];

    // ============================================================
    // 1. PENDING PAYMENTS > 30 MIN → AUTO-CANCEL
    // ============================================================
    cron_log("--- Checking pending payments > 30 minutes ---");

    $stmtPending = $db->query("
        SELECT o.order_id, o.customer_id, o.partner_id, o.shopper_id,
               o.total, o.delivery_fee, o.subtotal, o.payment_method,
               o.payment_status, o.status, o.created_at
        FROM om_market_orders o
        WHERE (o.payment_status IN ('pending', 'awaiting') OR o.payment_status IS NULL)
          AND o.status NOT IN ('cancelado', 'cancelled')
          AND o.payment_method != 'pix'
          AND o.created_at < NOW() - INTERVAL '30 minutes'
        ORDER BY o.created_at ASC
        LIMIT 50
    ");
    $pendingOrders = $stmtPending->fetchAll();
    $stats['checked'] += count($pendingOrders);

    foreach ($pendingOrders as $order) {
        $orderId = (int)$order['order_id'];
        $customerId = (int)$order['customer_id'];

        try {
            $db->beginTransaction();

            // Cancel the order
            $db->prepare("
                UPDATE om_market_orders
                SET status = 'cancelado',
                    payment_status = 'timeout',
                    updated_at = NOW(),
                    notes = COALESCE(notes, '') || '|AUTO-CANCEL: pagamento nao confirmado apos 30min'
                WHERE order_id = ?
            ")->execute([$orderId]);

            // Restore stock for each item
            $stmtItems = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll();

            foreach ($items as $item) {
                $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                   ->execute([$item['quantity'], $item['product_id']]);
                $stats['stock_restored']++;
            }

            // Release shopper if assigned
            if (!empty($order['shopper_id'])) {
                $db->prepare("
                    UPDATE om_market_shoppers
                    SET disponivel = 1, pedido_atual_id = NULL
                    WHERE shopper_id = ? AND pedido_atual_id = ?
                ")->execute([$order['shopper_id'], $orderId]);
            }

            // Timeline entry
            $db->prepare("
                INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                VALUES (?, 'cancelado', 'Pedido cancelado automaticamente - pagamento nao confirmado apos 30 minutos. Estoque restaurado.', 'system', 0, NOW())
            ")->execute([$orderId]);

            // Notify customer
            $customerBody = "Seu pedido #{$orderId} foi cancelado porque o pagamento nao foi confirmado a tempo. Nenhum valor foi cobrado.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (?, 'customer', 'Pedido cancelado - pagamento nao confirmado', ?, 'payment_timeout', ?::jsonb, NOW())
            ")->execute([
                $customerId,
                $customerBody,
                json_encode(['order_id' => $orderId, 'reason' => 'payment_timeout_30min'])
            ]);

            $db->commit();
            $stats['cancelled_timeout']++;
            cron_log("CANCEL pedido #{$orderId} (pagamento pendente >30min, metodo: {$order['payment_method']})");

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $stats['errors']++;
            cron_log("ERRO cancelando pedido #{$orderId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 2. PIX EXPIRED > 1 HOUR → AUTO-CANCEL
    // ============================================================
    cron_log("--- Checking expired PIX payments > 1 hour ---");

    $stmtPix = $db->query("
        SELECT o.order_id, o.customer_id, o.partner_id, o.shopper_id,
               o.total, o.delivery_fee, o.subtotal, o.payment_method,
               o.payment_status, o.status, o.created_at
        FROM om_market_orders o
        WHERE o.payment_method = 'pix'
          AND (o.payment_status IN ('pending', 'awaiting') OR o.payment_status IS NULL)
          AND o.status NOT IN ('cancelado', 'cancelled')
          AND o.created_at < NOW() - INTERVAL '1 hour'
        ORDER BY o.created_at ASC
        LIMIT 50
    ");
    $pixOrders = $stmtPix->fetchAll();
    $stats['checked'] += count($pixOrders);

    foreach ($pixOrders as $order) {
        $orderId = (int)$order['order_id'];
        $customerId = (int)$order['customer_id'];

        try {
            $db->beginTransaction();

            // Cancel the order
            $db->prepare("
                UPDATE om_market_orders
                SET status = 'cancelado',
                    payment_status = 'expired',
                    updated_at = NOW(),
                    notes = COALESCE(notes, '') || '|AUTO-CANCEL: PIX expirado apos 1h'
                WHERE order_id = ?
            ")->execute([$orderId]);

            // Restore stock for each item
            $stmtItems = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll();

            foreach ($items as $item) {
                $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                   ->execute([$item['quantity'], $item['product_id']]);
                $stats['stock_restored']++;
            }

            // Release shopper if assigned
            if (!empty($order['shopper_id'])) {
                $db->prepare("
                    UPDATE om_market_shoppers
                    SET disponivel = 1, pedido_atual_id = NULL
                    WHERE shopper_id = ? AND pedido_atual_id = ?
                ")->execute([$order['shopper_id'], $orderId]);
            }

            // Timeline entry
            $db->prepare("
                INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                VALUES (?, 'cancelado', 'Pedido cancelado automaticamente - PIX expirado apos 1 hora. Estoque restaurado.', 'system', 0, NOW())
            ")->execute([$orderId]);

            // Notify customer
            $customerBody2 = "Seu pedido #{$orderId} foi cancelado porque o pagamento via PIX nao foi realizado dentro do prazo de 1 hora. Voce pode refazer o pedido a qualquer momento.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (?, 'customer', 'Pedido cancelado - PIX expirado', ?, 'pix_expired', ?::jsonb, NOW())
            ")->execute([
                $customerId,
                $customerBody2,
                json_encode(['order_id' => $orderId, 'reason' => 'pix_expired_1h'])
            ]);

            $db->commit();
            $stats['cancelled_pix']++;
            cron_log("CANCEL PIX pedido #{$orderId} (PIX expirado >1h)");

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $stats['errors']++;
            cron_log("ERRO cancelando PIX pedido #{$orderId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 3. PAID BUT STUCK IN 'PENDING' STATUS > 10 MIN → ALERT ADMIN
    // ============================================================
    cron_log("--- Checking paid orders stuck in pending status ---");

    $stmtPaidStuck = $db->query("
        SELECT o.order_id, o.customer_id, o.partner_id, o.total,
               o.payment_method, o.payment_status, o.payment_id,
               o.status, o.created_at, o.updated_at
        FROM om_market_orders o
        WHERE o.payment_status = 'paid'
          AND o.status = 'pending'
          AND o.created_at < NOW() - INTERVAL '10 minutes'
        ORDER BY o.created_at ASC
        LIMIT 50
    ");
    $paidStuckOrders = $stmtPaidStuck->fetchAll();
    $stats['checked'] += count($paidStuckOrders);

    foreach ($paidStuckOrders as $order) {
        $orderId = (int)$order['order_id'];

        try {
            // Check if we already alerted for this order recently (avoid spam)
            $stmtCheck = $db->prepare("
                SELECT COUNT(*) as cnt FROM om_notifications
                WHERE type = 'paid_stuck_alert'
                  AND data->>'order_id' = ?
                  AND created_at > NOW() - INTERVAL '1 hour'
            ");
            $stmtCheck->execute([(string)$orderId]);
            if ((int)$stmtCheck->fetch()['cnt'] > 0) continue;

            // Alert admin - do NOT cancel paid orders
            $paymentId = $order['payment_id'] ?? 'N/A';
            $adminBody = "Pedido #{$orderId} tem pagamento confirmado (metodo: {$order['payment_method']}, payment_id: {$paymentId}) mas continua com status pendente ha mais de 10 minutos. Possivel falha no webhook. Verificar urgente.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (1, 'admin', 'ALERTA: Pedido pago travado em pendente', ?, 'paid_stuck_alert', ?::jsonb, NOW())
            ")->execute([
                $adminBody,
                json_encode([
                    'order_id' => $orderId,
                    'payment_method' => $order['payment_method'],
                    'payment_id' => $order['payment_id'],
                    'total' => $order['total'],
                    'created_at' => $order['created_at']
                ])
            ]);

            $stats['alerted_paid_stuck']++;
            cron_log("ALERT pedido #{$orderId} pago mas travado em 'pending' (metodo: {$order['payment_method']}, total: R\${$order['total']})");

        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO alert paid-stuck #{$orderId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // SUMMARY
    // ============================================================
    cron_log("=== RESUMO ===");
    cron_log("Verificados: {$stats['checked']}");
    cron_log("Cancelados (timeout >30min): {$stats['cancelled_timeout']}");
    cron_log("Cancelados (PIX expirado >1h): {$stats['cancelled_pix']}");
    cron_log("Alertas pago-travado: {$stats['alerted_paid_stuck']}");
    cron_log("Estoque restaurado (itens): {$stats['stock_restored']}");
    cron_log("Erros: {$stats['errors']}");

    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'stats' => $stats, 'timestamp' => date('c')]);
    }

} catch (Exception $e) {
    cron_log("ERRO FATAL: " . $e->getMessage());
    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit(1);
}
