<?php
/**
 * GET/POST /api/mercado/shopper/batch.php
 * Batch deliveries - group nearby orders for efficient delivery
 *
 * GET: Shows available batches (groups of 2-3 nearby orders)
 * POST: Accept a batch of orders
 *
 * Body (POST): { "order_ids": [10, 11] }
 */
require_once __DIR__ . "/../config/auth.php";

try {
    $db = getDB();

    $auth = requireShopperAuth();
    $shopper_id = $auth['uid'];

    if (!om_auth()->isShopperApproved($shopper_id)) {
        response(false, null, "Cadastro nao aprovado pelo RH", 403);
    }

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        // Get shopper location
        $lat = floatval($_GET["lat"] ?? 0);
        $lng = floatval($_GET["lng"] ?? 0);

        // Find pending orders that can be batched
        // Criteria: stores within 2km of each other, delivery addresses within 3km
        $sql = "
            SELECT o.order_id, o.partner_id, o.total, o.delivery_address,
                   o.delivery_lat, o.delivery_lng, o.date_added,
                   p.name as store_name, p.address as store_address,
                   p.latitude as store_lat, p.longitude as store_lng,
                   (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as items_count
            FROM om_market_orders o
            INNER JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.status IN ('pendente', 'pending')
            AND o.shopper_id IS NULL
            AND o.batch_id IS NULL
            AND p.latitude IS NOT NULL AND p.longitude IS NOT NULL
            ORDER BY o.date_added ASC
            LIMIT 50
        ";

        $stmt = $db->query($sql);
        $orders = $stmt->fetchAll();

        // Group orders into potential batches
        $batches = [];
        $used = [];

        for ($i = 0; $i < count($orders); $i++) {
            if (in_array($orders[$i]['order_id'], $used)) continue;

            $batch = [$orders[$i]];
            $storeLat = (float)$orders[$i]['store_lat'];
            $storeLng = (float)$orders[$i]['store_lng'];
            $delLat = (float)($orders[$i]['delivery_lat'] ?: 0);
            $delLng = (float)($orders[$i]['delivery_lng'] ?: 0);

            for ($j = $i + 1; $j < count($orders) && count($batch) < 3; $j++) {
                if (in_array($orders[$j]['order_id'], $used)) continue;

                $sLat2 = (float)$orders[$j]['store_lat'];
                $sLng2 = (float)$orders[$j]['store_lng'];
                $dLat2 = (float)($orders[$j]['delivery_lat'] ?: 0);
                $dLng2 = (float)($orders[$j]['delivery_lng'] ?: 0);

                // Check store proximity (within 2km)
                $storeDistance = haversineDistance($storeLat, $storeLng, $sLat2, $sLng2);
                if ($storeDistance > 2.0) continue;

                // Check delivery address proximity (within 3km) - skip if no coords
                if ($delLat != 0 && $delLng != 0 && $dLat2 != 0 && $dLng2 != 0) {
                    $deliveryDistance = haversineDistance($delLat, $delLng, $dLat2, $dLng2);
                    if ($deliveryDistance > 3.0) continue;
                }

                $batch[] = $orders[$j];
            }

            // Only create batch if there are 2+ orders
            if (count($batch) >= 2) {
                $orderIds = array_column($batch, 'order_id');
                foreach ($orderIds as $id) $used[] = $id;

                $totalEarnings = 0;
                $batchOrders = [];
                foreach ($batch as $b) {
                    $est = calcBatchEarnings($b);
                    $totalEarnings += $est;
                    $batchOrders[] = [
                        'order_id' => (int)$b['order_id'],
                        'store_name' => $b['store_name'],
                        'store_address' => $b['store_address'],
                        'delivery_address' => $b['delivery_address'],
                        'items_count' => (int)$b['items_count'],
                        'total' => (float)$b['total'],
                    ];
                }

                // Batch bonus: +15% for taking a batch
                $batchBonus = round($totalEarnings * 0.15, 2);

                $batches[] = [
                    'order_ids' => $orderIds,
                    'orders' => $batchOrders,
                    'order_count' => count($batch),
                    'total_earnings' => round($totalEarnings + $batchBonus, 2),
                    'batch_bonus' => $batchBonus,
                    'total_items' => array_sum(array_column($batch, 'items_count')),
                ];
            }
        }

        response(true, [
            'total' => count($batches),
            'batches' => $batches,
        ]);

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $orderIds = $input['order_ids'] ?? [];

        if (!is_array($orderIds) || count($orderIds) < 2 || count($orderIds) > 3) {
            response(false, null, "Envie 2 ou 3 order_ids para criar um lote", 400);
        }

        $orderIds = array_map('intval', $orderIds);

        $db->beginTransaction();

        try {
            // Generate batch ID
            $batchId = 'BATCH-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));

            // Lock and validate all orders
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $stmt = $db->prepare("
                SELECT * FROM om_market_orders
                WHERE order_id IN ($placeholders)
                FOR UPDATE
            ");
            $stmt->execute($orderIds);
            $orders = $stmt->fetchAll();

            if (count($orders) !== count($orderIds)) {
                $db->rollBack();
                response(false, null, "Um ou mais pedidos nao foram encontrados", 404);
            }

            foreach ($orders as $order) {
                if (!in_array($order['status'], ['pendente', 'pending'])) {
                    $db->rollBack();
                    response(false, null, "Pedido #{$order['order_id']} nao esta pendente", 409);
                }
                if ($order['shopper_id']) {
                    $db->rollBack();
                    response(false, null, "Pedido #{$order['order_id']} ja foi aceito", 409);
                }
            }

            // Check shopper availability
            $stmt = $db->prepare("SELECT * FROM om_market_shoppers WHERE shopper_id = ? FOR UPDATE");
            $stmt->execute([$shopper_id]);
            $shopper = $stmt->fetch();

            if (!$shopper || !$shopper['disponivel']) {
                $db->rollBack();
                response(false, null, "Voce nao esta disponivel para aceitar pedidos", 400);
            }

            // Accept all orders in the batch
            $stmt = $db->prepare("
                UPDATE om_market_orders SET
                    shopper_id = ?,
                    status = 'aceito',
                    batch_id = ?,
                    accepted_at = NOW()
                WHERE order_id = ? AND shopper_id IS NULL AND status IN ('pendente', 'pending')
            ");

            foreach ($orderIds as $oid) {
                $stmt->execute([$shopper_id, $batchId, $oid]);
                if ($stmt->rowCount() === 0) {
                    $db->rollBack();
                    response(false, null, "Pedido #$oid nao disponivel (race condition)", 409);
                }
            }

            // Mark shopper as busy with first order
            $stmt = $db->prepare("
                UPDATE om_market_shoppers SET
                    disponivel = 0,
                    pedido_atual_id = ?
                WHERE shopper_id = ?
            ");
            $stmt->execute([$orderIds[0], $shopper_id]);

            $db->commit();

            // Log
            logAudit('create', 'batch', null, null, [
                'batch_id' => $batchId,
                'order_ids' => $orderIds,
                'shopper_id' => $shopper_id,
            ], "Lote criado com " . count($orderIds) . " pedidos");

            response(true, [
                'batch_id' => $batchId,
                'order_ids' => $orderIds,
                'order_count' => count($orderIds),
            ], "Lote aceito com sucesso! " . count($orderIds) . " pedidos atribuidos.");

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

    } else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[shopper/batch] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar lote", 500);
}

function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    $r = 6371; // km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $r * $c;
}

function calcBatchEarnings($order) {
    $subtotal = (float)($order['total'] ?: 0);
    $items = (int)($order['items_count'] ?: 1);
    $base = $subtotal * 0.05;
    $itemBonus = $items * 0.50;
    return max($base + $itemBonus, 5.00);
}
