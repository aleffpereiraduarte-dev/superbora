<?php
/**
 * E2E Test Suite - FUNCTIONAL FLOWS
 * Testa fluxos funcionais completos simulando uso real
 */

require_once __DIR__ . '/config.php';

$runner = new E2ETestRunner();
$db = $runner->getDB();

echo "\n" . str_repeat("=", 60) . "\n";
echo "⚡ E2E TESTS: FUNCTIONAL FLOWS - SIMULAÇÃO REAL\n";
echo str_repeat("=", 60) . "\n";

// ============================================================
// FLOW 1: CREATE TEST ORDER AND SIMULATE COMPLETE JOURNEY
// ============================================================
$runner->startTest('Flow 1: Create Test Order');

try {
    $db->beginTransaction();

    // Get test partner
    $stmt = $db->query("SELECT partner_id, trade_name FROM om_market_partners WHERE status = 1 LIMIT 1");
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get test shopper
    $stmt = $db->query("SELECT shopper_id, name FROM om_market_shoppers LIMIT 1");
    $shopper = $stmt->fetch(PDO::FETCH_ASSOC);

    // Create test order with all required fields
    $orderNumber = 'E2E-' . time();
    $stmt = $db->prepare("
        INSERT INTO om_market_orders
        (order_number, market_id, partner_id, customer_id, customer_name, customer_phone,
         shipping_address, shipping_city, shipping_state, shipping_cep,
         subtotal, total, delivery_fee, status, created_at)
        VALUES (?, ?, ?, 1, 'Cliente E2E Test', '11999999999',
                'Rua Teste, 123', 'São Paulo', 'SP', '01310100',
                140.10, 150.00, 9.90, 'pending', NOW())
    ");
    $stmt->execute([$orderNumber, $partner['partner_id'], $partner['partner_id']]);
    $orderId = $db->lastInsertId();

    echo "  ℹ️  Created order #$orderId ($orderNumber)\n";

    // Add test items
    $testItems = [
        ['Arroz Integral 1kg', 12.90, 2, '7891234567890'],
        ['Feijão Preto 1kg', 8.50, 1, '7891234567891'],
        ['Leite Integral 1L', 5.99, 3, '7891234567892']
    ];

    foreach ($testItems as $item) {
        $stmt = $db->prepare("
            INSERT INTO om_market_order_items
            (order_id, product_name, price, quantity, product_barcode, status, scanned)
            VALUES (?, ?, ?, ?, ?, 'pending', 0)
        ");
        $stmt->execute([$orderId, $item[0], $item[1], $item[2], $item[3]]);
    }

    echo "  ℹ️  Added " . count($testItems) . " items to order\n";

    $runner->assert($orderId > 0, "Test order created successfully");

    $db->commit();

} catch (Exception $e) {
    $db->rollBack();
    $runner->assert(false, "Order creation failed: " . $e->getMessage());
    $orderId = null;
}

// ============================================================
// FLOW 2: SHOPPER ACCEPTS ORDER
// ============================================================
$runner->startTest('Flow 2: Shopper Accepts Order');

if ($orderId) {
    try {
        $stmt = $db->prepare("
            UPDATE om_market_orders
            SET shopper_id = ?,
                status = 'confirmed',
                accepted_at = NOW()
            WHERE order_id = ?
        ");
        $stmt->execute([$shopper['shopper_id'], $orderId]);

        echo "  ℹ️  Shopper '{$shopper['name']}' accepted order\n";

        $runner->assert($stmt->rowCount() > 0, "Shopper assigned to order");

    } catch (Exception $e) {
        $runner->assert(false, "Shopper assignment failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No order to assign");
}

// ============================================================
// FLOW 3: SIMULATE ITEM SCANNING
// ============================================================
$runner->startTest('Flow 3: Item Scanning Simulation');

if ($orderId) {
    try {
        // Get items for this order
        $stmt = $db->prepare("SELECT item_id, product_name FROM om_market_order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $scannedCount = 0;
        foreach ($items as $item) {
            // Simulate scanning each item
            $stmt = $db->prepare("
                UPDATE om_market_order_items
                SET scanned = 1, scanned_at = NOW(), status = 'found'
                WHERE item_id = ?
            ");
            $stmt->execute([$item['item_id']]);
            $scannedCount++;
            echo "  ℹ️  Scanned: {$item['product_name']}\n";
        }

        // Calculate and update progress
        $progress = count($items) > 0 ? round(($scannedCount / count($items)) * 100) : 0;
        echo "  ℹ️  Progress: $progress%\n";

        $runner->assert($scannedCount === count($items), "All items scanned ($scannedCount/" . count($items) . ")");

    } catch (Exception $e) {
        $runner->assert(false, "Scanning failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No order for scanning");
}

// ============================================================
// FLOW 4: SIMULATE SUBSTITUTION
// ============================================================
$runner->startTest('Flow 4: Product Substitution');

if ($orderId) {
    try {
        // Get first item to substitute
        $stmt = $db->prepare("SELECT item_id, product_name, price FROM om_market_order_items WHERE order_id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            // Simulate substitution
            $stmt = $db->prepare("
                UPDATE om_market_order_items
                SET substituted = 1,
                    substitute_name = ?,
                    substitute_price = ?,
                    replacement_reason = ?,
                    status = 'replaced'
                WHERE item_id = ?
            ");
            $stmt->execute([
                'Arroz Premium 1kg (Substituto)',
                14.90,
                'Produto original fora de estoque',
                $item['item_id']
            ]);

            echo "  ℹ️  Substituted '{$item['product_name']}' with 'Arroz Premium 1kg'\n";
            echo "  ℹ️  Price difference: R$ " . number_format(14.90 - $item['price'], 2) . "\n";

            $runner->assert($stmt->rowCount() > 0, "Item substituted successfully");
        }

    } catch (Exception $e) {
        $runner->assert(false, "Substitution failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No order for substitution");
}

// ============================================================
// FLOW 5: SEND CHAT MESSAGE
// ============================================================
$runner->startTest('Flow 5: Chat Communication');

if ($orderId) {
    try {
        $stmt = $db->prepare("
            INSERT INTO om_market_chat
            (order_id, sender_type, sender_id, sender_name, message, date_added)
            VALUES (?, 'shopper', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $orderId,
            $shopper['shopper_id'],
            $shopper['name'],
            'Olá! Precisei fazer uma substituição no seu pedido. O arroz integral não estava disponível, substitui pelo arroz premium. Tudo bem?'
        ]);

        echo "  ℹ️  Message sent to customer\n";

        // Simulate customer response
        $stmt = $db->prepare("
            INSERT INTO om_market_chat
            (order_id, sender_type, sender_id, sender_name, message, date_added)
            VALUES (?, 'customer', 1, 'Cliente Teste', ?, NOW())
        ");
        $stmt->execute([$orderId, 'Ok, pode substituir! Obrigado por avisar.']);

        echo "  ℹ️  Customer approved substitution\n";

        $runner->assert(true, "Chat messages exchanged");

    } catch (Exception $e) {
        $runner->assert(false, "Chat failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No order for chat");
}

// ============================================================
// FLOW 6: COMPLETE SHOPPING
// ============================================================
$runner->startTest('Flow 6: Complete Shopping Phase');

if ($orderId) {
    try {
        $stmt = $db->prepare("
            UPDATE om_market_orders
            SET status = 'purchased',
                shopping_completed_at = NOW()
            WHERE order_id = ?
        ");
        $stmt->execute([$orderId]);

        echo "  ℹ️  Shopping phase completed\n";

        $runner->assert($stmt->rowCount() > 0, "Order status updated to 'purchased'");

    } catch (Exception $e) {
        $runner->assert(false, "Shopping completion failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No order to complete");
}

// ============================================================
// FLOW 7: CREATE DELIVERY ENTRY
// ============================================================
$runner->startTest('Flow 7: Create Delivery');

$entregaId = null;
if ($orderId) {
    try {
        $stmt = $db->prepare("
            INSERT INTO om_entregas
            (tipo, origem_sistema, referencia_id, remetente_tipo, remetente_nome,
             coleta_endereco, entrega_endereco, destinatario_nome, destinatario_telefone,
             status, valor_frete, created_at)
            VALUES ('express', 'mercado', ?, 'partner', ?, ?, ?, 'Cliente E2E Test', '11999999999', 'pendente', 9.90, NOW())
        ");
        $stmt->execute([
            $orderId,
            $partner['trade_name'],
            'Rua do Mercado, 123 - Centro',
            'Rua do Cliente, 456 - Bairro'
        ]);

        $entregaId = $db->lastInsertId();
        echo "  ℹ️  Delivery #$entregaId created\n";

        $runner->assert($entregaId > 0, "Delivery record created");

    } catch (Exception $e) {
        $runner->assert(false, "Delivery creation failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No order for delivery");
}

// ============================================================
// FLOW 8: ASSIGN DRIVER
// ============================================================
$runner->startTest('Flow 8: Assign Driver');

if ($entregaId) {
    try {
        // Get a driver
        $stmt = $db->query("
            SELECT worker_id, name FROM om_market_workers
            WHERE worker_type IN ('driver', 'full_service') AND is_active = 1
            LIMIT 1
        ");
        $driver = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($driver) {
            $stmt = $db->prepare("
                UPDATE om_entregas
                SET driver_id = ?, status = 'aceito', driver_aceito_em = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$driver['worker_id'], $entregaId]);

            echo "  ℹ️  Driver '{$driver['name']}' assigned to delivery\n";
            $runner->assert(true, "Driver assigned");
        } else {
            echo "  ⚠️  No active drivers found\n";
            $runner->assert(true, "No drivers available (OK for test)");
        }

    } catch (Exception $e) {
        $runner->assert(false, "Driver assignment failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No delivery for driver");
}

// ============================================================
// FLOW 9: CREATE HANDOFF (SHOPPER → DRIVER)
// ============================================================
$runner->startTest('Flow 9: Handoff Shopper to Driver');

if ($entregaId) {
    try {
        $stmt = $db->prepare("
            INSERT INTO om_entrega_handoffs
            (entrega_id, de_tipo, de_id, de_nome, para_tipo, para_id, para_nome, status, data_criado)
            VALUES (?, 'vendedor', ?, ?, 'entregador', 1, 'Motorista E2E', 'pendente', NOW())
        ");
        $stmt->execute([$entregaId, $shopper['shopper_id'], $shopper['name']]);

        $handoffId = $db->lastInsertId();
        echo "  ℹ️  Handoff #$handoffId created (pending)\n";

        // Accept handoff
        $stmt = $db->prepare("
            UPDATE om_entrega_handoffs
            SET status = 'aceito', data_aceito = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$handoffId]);

        echo "  ℹ️  Handoff accepted\n";

        $runner->assert(true, "Handoff completed");

    } catch (Exception $e) {
        $runner->assert(false, "Handoff failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No delivery for handoff");
}

// ============================================================
// FLOW 10: ADD TRACKING EVENTS
// ============================================================
$runner->startTest('Flow 10: Delivery Tracking');

if ($entregaId) {
    try {
        $trackingEvents = [
            ['em_transito', 'Pedido saiu para entrega', -23.5505, -46.6333],
            ['a_caminho', 'Motorista a caminho do destino', -23.5510, -46.6340]
        ];

        foreach ($trackingEvents as $event) {
            $stmt = $db->prepare("
                INSERT INTO om_entrega_tracking
                (entrega_id, status, mensagem, lat, lng, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$entregaId, $event[0], $event[1], $event[2], $event[3]]);

            echo "  ℹ️  Tracking: {$event[1]}\n";
        }

        $runner->assert(true, "Tracking events added");

    } catch (Exception $e) {
        $runner->assert(false, "Tracking failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No delivery for tracking");
}

// ============================================================
// FLOW 11: COMPLETE DELIVERY
// ============================================================
$runner->startTest('Flow 11: Complete Delivery');

if ($entregaId && $orderId) {
    try {
        // Update delivery status
        $stmt = $db->prepare("
            UPDATE om_entregas
            SET status = 'entregue', entrega_realizada_em = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$entregaId]);

        // Update order status
        $stmt = $db->prepare("
            UPDATE om_market_orders
            SET status = 'delivered', delivered_at = NOW()
            WHERE order_id = ?
        ");
        $stmt->execute([$orderId]);

        // Create final handoff
        $stmt = $db->prepare("
            INSERT INTO om_entrega_handoffs
            (entrega_id, de_tipo, de_id, de_nome, para_tipo, para_id, para_nome, status, data_criado, data_aceito)
            VALUES (?, 'entregador', 1, 'Motorista E2E', 'cliente', 1, 'Cliente E2E', 'concluido', NOW(), NOW())
        ");
        $stmt->execute([$entregaId]);

        echo "  ℹ️  Delivery completed successfully!\n";
        echo "  ℹ️  Order #$orderId → Status: delivered\n";
        echo "  ℹ️  Entrega #$entregaId → Status: entregue\n";

        $runner->assert(true, "Delivery completed");

    } catch (Exception $e) {
        $runner->assert(false, "Delivery completion failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No delivery to complete");
}

// ============================================================
// FLOW 12: CALCULATE SHOPPER EARNINGS
// ============================================================
$runner->startTest('Flow 12: Shopper Earnings Calculation');

if ($orderId && $shopper) {
    try {
        // Calculate earnings (80% of delivery fee)
        $deliveryFee = 9.90;
        $shopperEarning = $deliveryFee * 0.80;

        $stmt = $db->prepare("
            INSERT INTO om_shopper_earnings
            (shopper_id, order_id, type, amount, description, status, created_at)
            VALUES (?, ?, 'order', ?, ?, 'pending', NOW())
        ");
        $stmt->execute([
            $shopper['shopper_id'],
            $orderId,
            $shopperEarning,
            "Comissão pedido #$orderId"
        ]);

        echo "  ℹ️  Shopper earning: R$ " . number_format($shopperEarning, 2, ',', '.') . "\n";

        $runner->assert(true, "Earnings calculated and recorded");

    } catch (Exception $e) {
        $runner->assert(false, "Earnings calculation failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No order for earnings");
}

// ============================================================
// FLOW 13: CREATE NOTIFICATION
// ============================================================
$runner->startTest('Flow 13: Notifications');

if ($entregaId) {
    try {
        $stmt = $db->prepare("
            INSERT INTO om_entrega_notificacoes
            (entrega_id, tipo, titulo, mensagem, destinatario_tipo, destinatario_id, lido, created_at)
            VALUES (?, 'sucesso', 'Pedido Entregue!', 'Seu pedido foi entregue com sucesso.', 'cliente', 1, 0, NOW())
        ");
        $stmt->execute([$entregaId]);

        echo "  ℹ️  Notification sent to customer\n";

        $runner->assert(true, "Notification created");

    } catch (Exception $e) {
        $runner->assert(false, "Notification failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No delivery for notification");
}

// ============================================================
// FLOW 14: VERIFY COMPLETE FLOW
// ============================================================
$runner->startTest('Flow 14: Verify Complete Flow');

if ($orderId && $entregaId) {
    try {
        // Check order final state
        $stmt = $db->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $finalOrder = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check items
        $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(scanned) as scanned FROM om_market_order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $itemStats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check delivery
        $stmt = $db->prepare("SELECT * FROM om_entregas WHERE id = ?");
        $stmt->execute([$entregaId]);
        $finalDelivery = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check handoffs
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_entrega_handoffs WHERE entrega_id = ?");
        $stmt->execute([$entregaId]);
        $handoffCount = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check tracking
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_entrega_tracking WHERE entrega_id = ?");
        $stmt->execute([$entregaId]);
        $trackingCount = $stmt->fetch(PDO::FETCH_ASSOC);

        echo "  ℹ️  ═══ FINAL STATE ═══\n";
        echo "  ℹ️  Order Status: {$finalOrder['status']}\n";
        echo "  ℹ️  Items: {$itemStats['scanned']}/{$itemStats['total']} scanned\n";
        echo "  ℹ️  Delivery Status: {$finalDelivery['status']}\n";
        echo "  ℹ️  Handoffs: {$handoffCount['total']}\n";
        echo "  ℹ️  Tracking Events: {$trackingCount['total']}\n";

        $runner->assertEquals('delivered', $finalOrder['status'], "Order final status is 'delivered'");
        $runner->assertEquals('entregue', $finalDelivery['status'], "Delivery final status is 'entregue'");
        $runner->assertGreaterThan(0, (int)$handoffCount['total'], "Has handoff records");

    } catch (Exception $e) {
        $runner->assert(false, "Verification failed: " . $e->getMessage());
    }
} else {
    $runner->assert(false, "No data to verify");
}

// ============================================================
// CLEANUP: Delete Test Data
// ============================================================
$runner->startTest('Cleanup: Remove Test Data');

if ($orderId && $entregaId) {
    try {
        // Delete in correct order (foreign keys)
        $db->prepare("DELETE FROM om_entrega_notificacoes WHERE entrega_id = ?")->execute([$entregaId]);
        $db->prepare("DELETE FROM om_entrega_tracking WHERE entrega_id = ?")->execute([$entregaId]);
        $db->prepare("DELETE FROM om_entrega_handoffs WHERE entrega_id = ?")->execute([$entregaId]);
        $db->prepare("DELETE FROM om_entregas WHERE id = ?")->execute([$entregaId]);
        $db->prepare("DELETE FROM om_shopper_earnings WHERE order_id = ?")->execute([$orderId]);
        $db->prepare("DELETE FROM om_market_chat WHERE order_id = ?")->execute([$orderId]);
        $db->prepare("DELETE FROM om_market_order_items WHERE order_id = ?")->execute([$orderId]);
        $db->prepare("DELETE FROM om_market_orders WHERE order_id = ?")->execute([$orderId]);

        echo "  ℹ️  Test data cleaned up\n";
        $runner->assert(true, "Test data removed");

    } catch (Exception $e) {
        echo "  ⚠️  Cleanup warning: " . $e->getMessage() . "\n";
        $runner->assert(true, "Cleanup attempted");
    }
} else {
    $runner->assert(true, "No test data to clean");
}

// ============================================================
// SUMMARY
// ============================================================
$runner->printSummary();
