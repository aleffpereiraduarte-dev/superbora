<?php
/**
 * CRON: Order Recovery System
 * Run every 5 minutes: star/5 * * * * php /var/www/html/mercado/cron/cron_order_recovery.php
 *
 * Detects and recovers stuck orders:
 * 1. Pending orders with no acceptance after 30 min → auto-cancel + refund
 * 2. Accepted orders with no collection after 45 min → reassign shopper
 * 3. In-transit deliveries with no completion after 2 hours → alert admin
 * 4. Preparing orders with no progress after 1 hour → alert partner + admin
 * 5. Orphaned orders (store closed) → auto-cancel + refund
 */

$isCli = php_sapi_name() === 'cli';

function cron_log($msg) {
    global $isCli;
    $line = "[" . date('Y-m-d H:i:s') . "] [order-recovery] {$msg}";
    if ($isCli) echo $line . "\n";
    error_log($line);
}

try {
    // Use PostgreSQL (same as API endpoints)
    $dbHost = '147.93.12.236';
    $dbPort = '5432';
    $dbName = 'love1';
    $dbUser = 'love1';
    $dbPass = 'Aleff2009@';

    $db = new PDO(
        "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $stats = [
        'checked' => 0,
        'cancelled_pending' => 0,
        'reassigned_shopper' => 0,
        'alerted_stuck_delivery' => 0,
        'alerted_stuck_preparing' => 0,
        'cancelled_orphaned' => 0,
        'refunds_created' => 0,
        'errors' => 0,
    ];

    // ============================================================
    // 1. PENDING ORDERS > 30 MIN WITHOUT ACCEPTANCE → AUTO-CANCEL
    // ============================================================
    cron_log("--- Checking pending orders without acceptance ---");

    $stmtPending = $db->query("
        SELECT o.order_id, o.customer_id, o.partner_id, o.total, o.delivery_fee,
               o.payment_method, o.created_at
        FROM om_market_orders o
        WHERE o.status IN ('pending', 'pendente')
          AND o.created_at < NOW() - INTERVAL '30 minutes'
        ORDER BY o.created_at ASC
        LIMIT 50
    ");
    $pendingOrders = $stmtPending->fetchAll();
    $stats['checked'] += count($pendingOrders);

    foreach ($pendingOrders as $order) {
        try {
            $db->beginTransaction();
            $orderId = (int)$order['order_id'];
            $customerId = (int)$order['customer_id'];
            $total = (float)$order['total'];

            // Cancel order
            $db->prepare("
                UPDATE om_market_orders
                SET status = 'cancelado', updated_at = NOW(),
                    notes = COALESCE(notes, '') || '|AUTO-CANCEL: sem aceitacao apos 30min'
                WHERE order_id = ?
            ")->execute([$orderId]);

            // Create refund record (full amount including delivery)
            $refundAmount = $total + (float)($order['delivery_fee'] ?? 0);
            $db->prepare("
                INSERT INTO om_market_refunds (order_id, amount, reason, status, created_at)
                VALUES (?, ?, 'auto_cancel_timeout', 'approved', NOW())
            ")->execute([$orderId, $refundAmount]);

            // Timeline entry
            $timelineDesc = "Pedido cancelado automaticamente - sem aceitacao apos 30 minutos. Reembolso de R\$" . number_format($refundAmount, 2, '.', '') . " aprovado.";
            $db->prepare("
                INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                VALUES (?, 'cancelado', ?, 'system', 0, NOW())
            ")->execute([$orderId, $timelineDesc]);

            // Restore stock
            $stmtItems = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll();
            foreach ($items as $item) {
                $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                   ->execute([$item['quantity'], $item['product_id']]);
            }

            // Notify customer
            $customerBody = "Seu pedido #{$orderId} foi cancelado automaticamente pois nenhuma loja aceitou a tempo. Reembolso de R\$" . number_format($refundAmount, 2, ',', '.') . " sera processado.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (?, 'customer', 'Pedido cancelado', ?, 'order_cancelled', ?::jsonb, NOW())
            ")->execute([$customerId, $customerBody, json_encode(['reference_type' => 'order', 'reference_id' => $orderId])]);

            $db->commit();
            $stats['cancelled_pending']++;
            $stats['refunds_created']++;
            cron_log("AUTO-CANCEL pedido #{$orderId} (pendente >30min). Reembolso R\${$refundAmount}");
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $stats['errors']++;
            cron_log("ERRO cancelando pedido #{$orderId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 2. ACCEPTED ORDERS > 45 MIN WITHOUT COLLECTION → REASSIGN
    // ============================================================
    cron_log("--- Checking accepted orders without collection ---");

    $stmtAccepted = $db->query("
        SELECT o.order_id, o.customer_id, o.partner_id, o.shopper_id, o.total, o.created_at
        FROM om_market_orders o
        WHERE o.status IN ('aceito', 'accepted')
          AND o.shopper_id IS NOT NULL
          AND o.updated_at < NOW() - INTERVAL '45 minutes'
        ORDER BY o.updated_at ASC
        LIMIT 30
    ");
    $acceptedOrders = $stmtAccepted->fetchAll();
    $stats['checked'] += count($acceptedOrders);

    foreach ($acceptedOrders as $order) {
        try {
            $db->beginTransaction();
            $orderId = (int)$order['order_id'];
            $oldShopperId = (int)$order['shopper_id'];

            // Release old shopper
            $db->prepare("
                UPDATE om_market_shoppers
                SET disponivel = 1, pedido_atual_id = NULL
                WHERE shopper_id = ? AND pedido_atual_id = ?
            ")->execute([$oldShopperId, $orderId]);

            // Reset order to pending (re-offer to other shoppers)
            $db->prepare("
                UPDATE om_market_orders
                SET status = 'pendente', shopper_id = NULL, updated_at = NOW(),
                    notes = COALESCE(notes, '') || '|AUTO-REASSIGN: shopper #{$oldShopperId} nao coletou em 45min'
                WHERE order_id = ?
            ")->execute([$orderId]);

            // Timeline
            $timelineDesc2 = "Shopper #{$oldShopperId} nao coletou em 45 minutos. Pedido disponibilizado para outro entregador.";
            $db->prepare("
                INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                VALUES (?, 'pendente', ?, 'system', 0, NOW())
            ")->execute([$orderId, $timelineDesc2]);

            // Notify old shopper
            $shopperBody = "O pedido #{$orderId} foi reatribuido pois nao foi coletado a tempo.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (?, 'shopper', 'Pedido reatribuido', ?, 'order_reassigned', ?::jsonb, NOW())
            ")->execute([$oldShopperId, $shopperBody, json_encode(['reference_type' => 'order', 'reference_id' => $orderId])]);

            // Notify partner
            $partnerBody = "Pedido #{$orderId}: entregador anterior nao apareceu. Buscando novo entregador.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (?, 'partner', 'Entregador reatribuido', ?, 'shopper_reassigned', ?::jsonb, NOW())
            ")->execute([$order['partner_id'], $partnerBody, json_encode(['reference_type' => 'order', 'reference_id' => $orderId])]);

            $db->commit();
            $stats['reassigned_shopper']++;
            cron_log("REASSIGN pedido #{$orderId}: shopper #{$oldShopperId} nao coletou em 45min");
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $stats['errors']++;
            cron_log("ERRO reassign pedido #{$orderId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 3. IN-TRANSIT > 2 HOURS → ALERT ADMIN
    // ============================================================
    cron_log("--- Checking stuck deliveries ---");

    $stmtTransit = $db->query("
        SELECT o.order_id, o.customer_id, o.partner_id, o.shopper_id, o.total
        FROM om_market_orders o
        WHERE o.status IN ('em_transito', 'in_transit', 'a_caminho')
          AND o.updated_at < NOW() - INTERVAL '2 hours'
        ORDER BY o.updated_at ASC
        LIMIT 20
    ");
    $transitOrders = $stmtTransit->fetchAll();
    $stats['checked'] += count($transitOrders);

    foreach ($transitOrders as $order) {
        try {
            $orderId = (int)$order['order_id'];

            // Check if we already alerted for this order (avoid spam)
            $stmtCheck = $db->prepare("
                SELECT COUNT(*) as cnt FROM om_notifications
                WHERE type = 'stuck_delivery' AND data->>'reference_id' = ?
                  AND created_at > NOW() - INTERVAL '2 hours'
            ");
            $stmtCheck->execute([(string)$orderId]);
            if ((int)$stmtCheck->fetch()['cnt'] > 0) continue;

            // Alert admin
            $adminBody = "Pedido #{$orderId} esta em transito ha mais de 2 horas. Shopper #{$order['shopper_id']}. Verificar urgente.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (1, 'admin', 'ALERTA: Entrega travada >2h', ?, 'stuck_delivery', ?::jsonb, NOW())
            ")->execute([$adminBody, json_encode(['reference_type' => 'order', 'reference_id' => $orderId])]);

            // Alert customer
            $customerBody2 = "Seu pedido #{$orderId} esta demorando mais que o normal. Estamos acompanhando e garantimos que sera resolvido.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                Values (?, 'customer', 'Atraso na entrega', ?, 'delivery_delay', ?::jsonb, NOW())
            ")->execute([$order['customer_id'], $customerBody2, json_encode(['reference_type' => 'order', 'reference_id' => $orderId])]);

            $stats['alerted_stuck_delivery']++;
            cron_log("ALERT entrega travada pedido #{$orderId} (>2h em transito)");
        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO alert delivery #{$orderId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 4. PREPARING > 1 HOUR → ALERT PARTNER + ADMIN
    // ============================================================
    cron_log("--- Checking stuck preparation ---");

    $stmtPrep = $db->query("
        SELECT o.order_id, o.customer_id, o.partner_id, o.total
        FROM om_market_orders o
        WHERE o.status IN ('preparando', 'preparing')
          AND o.updated_at < NOW() - INTERVAL '1 hour'
        ORDER BY o.updated_at ASC
        LIMIT 30
    ");
    $prepOrders = $stmtPrep->fetchAll();
    $stats['checked'] += count($prepOrders);

    foreach ($prepOrders as $order) {
        try {
            $orderId = (int)$order['order_id'];

            $stmtCheck = $db->prepare("
                SELECT COUNT(*) as cnt FROM om_notifications
                WHERE type = 'stuck_preparing' AND data->>'reference_id' = ?
                  AND created_at > NOW() - INTERVAL '1 hour'
            ");
            $stmtCheck->execute([(string)$orderId]);
            if ((int)$stmtCheck->fetch()['cnt'] > 0) continue;

            $partnerBody2 = "Pedido #{$orderId} esta em preparo ha mais de 1 hora. Atualize o status ou cancele.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (?, 'partner', 'Pedido em preparo ha muito tempo', ?, 'stuck_preparing', ?::jsonb, NOW())
            ")->execute([$order['partner_id'], $partnerBody2, json_encode(['reference_type' => 'order', 'reference_id' => $orderId])]);

            $adminBody2 = "Pedido #{$orderId} em preparo ha >1h no parceiro #{$order['partner_id']}.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (1, 'admin', 'Preparo demorado', ?, 'stuck_preparing', ?::jsonb, NOW())
            ")->execute([$adminBody2, json_encode(['reference_type' => 'order', 'reference_id' => $orderId])]);

            $stats['alerted_stuck_preparing']++;
            cron_log("ALERT preparo demorado pedido #{$orderId} (>1h)");
        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO alert preparing #{$orderId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 5. VERY OLD PENDING/ACCEPTED (>3 HOURS) → FORCE CANCEL
    // ============================================================
    cron_log("--- Force-cancelling very old orders ---");

    $stmtOld = $db->query("
        SELECT o.order_id, o.customer_id, o.partner_id, o.shopper_id, o.total, o.delivery_fee, o.status
        FROM om_market_orders o
        WHERE o.status IN ('pending', 'pendente', 'aceito', 'accepted')
          AND o.created_at < NOW() - INTERVAL '3 hours'
        ORDER BY o.created_at ASC
        LIMIT 20
    ");
    $oldOrders = $stmtOld->fetchAll();
    $stats['checked'] += count($oldOrders);

    foreach ($oldOrders as $order) {
        try {
            $db->beginTransaction();
            $orderId = (int)$order['order_id'];
            $customerId = (int)$order['customer_id'];
            $refundAmount = (float)$order['total'] + (float)($order['delivery_fee'] ?? 0);

            // Release shopper if assigned
            if ($order['shopper_id']) {
                $db->prepare("
                    UPDATE om_market_shoppers SET disponivel = 1, pedido_atual_id = NULL
                    WHERE shopper_id = ? AND pedido_atual_id = ?
                ")->execute([$order['shopper_id'], $orderId]);
            }

            // Cancel
            $cancelNote = "|FORCE-CANCEL: pedido orfao >3h (status: {$order['status']})";
            $db->prepare("
                UPDATE om_market_orders
                SET status = 'cancelado', updated_at = NOW(),
                    notes = COALESCE(notes, '') || ?
                WHERE order_id = ?
            ")->execute([$cancelNote, $orderId]);

            // Refund
            $db->prepare("
                INSERT INTO om_market_refunds (order_id, amount, reason, status, created_at)
                VALUES (?, ?, 'force_cancel_orphaned', 'approved', NOW())
            ")->execute([$orderId, $refundAmount]);

            // Restore stock
            $stmtItems = $db->prepare("SELECT product_id, quantity FROM om_market_order_items WHERE order_id = ?");
            $stmtItems->execute([$orderId]);
            foreach ($stmtItems->fetchAll() as $item) {
                $db->prepare("UPDATE om_market_products SET quantity = quantity + ? WHERE product_id = ?")
                   ->execute([$item['quantity'], $item['product_id']]);
            }

            // Timeline
            $db->prepare("
                INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                VALUES (?, 'cancelado', 'Pedido cancelado automaticamente - orfao por mais de 3 horas. Reembolso total aprovado.', 'system', 0, NOW())
            ")->execute([$orderId]);

            // Notify customer
            $customerBody3 = "Infelizmente nao foi possivel processar seu pedido #{$orderId}. Reembolso total sera creditado.";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (?, 'customer', 'Pedido cancelado', ?, 'order_force_cancelled', ?::jsonb, NOW())
            ")->execute([$customerId, $customerBody3, json_encode(['reference_type' => 'order', 'reference_id' => $orderId])]);

            // Notify admin
            $adminBody3 = "Pedido #{$orderId} foi cancelado automaticamente (>3h sem progresso). Reembolso R\$" . number_format($refundAmount, 2, '.', '') . ".";
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (1, 'admin', 'Pedido orfao cancelado', ?, 'order_force_cancelled', ?::jsonb, NOW())
            ")->execute([$adminBody3, json_encode(['reference_type' => 'order', 'reference_id' => $orderId])]);

            $db->commit();
            $stats['cancelled_orphaned']++;
            $stats['refunds_created']++;
            cron_log("FORCE-CANCEL pedido orfao #{$orderId} (>3h). Reembolso R\${$refundAmount}");
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $stats['errors']++;
            cron_log("ERRO force-cancel #{$orderId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // SUMMARY
    // ============================================================
    cron_log("=== RESUMO ===");
    cron_log("Verificados: {$stats['checked']}");
    cron_log("Cancelados (pendente >30min): {$stats['cancelled_pending']}");
    cron_log("Shoppers reatribuidos: {$stats['reassigned_shopper']}");
    cron_log("Alertas entrega travada: {$stats['alerted_stuck_delivery']}");
    cron_log("Alertas preparo demorado: {$stats['alerted_stuck_preparing']}");
    cron_log("Orfaos cancelados: {$stats['cancelled_orphaned']}");
    cron_log("Reembolsos criados: {$stats['refunds_created']}");
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
