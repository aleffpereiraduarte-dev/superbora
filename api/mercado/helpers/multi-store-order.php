<?php
/**
 * Multi-Store Order Helper
 * Allows customers to order from multiple stores in a single session.
 * Orders are grouped under a group_id (MSG-XXXXX) for unified tracking.
 *
 * Table: om_multi_store_orders (from sql/047_enterprise_ai.sql)
 *
 * Usage:
 *   require_once __DIR__ . '/../helpers/multi-store-order.php';
 *   $groupId = initMultiStoreOrder($db, $customerId, $phone, 'whatsapp');
 *   addStoreToMultiOrder($db, $groupId, $orderId, $orderTotal);
 *   $status = getMultiStoreStatus($db, $groupId);
 *   $summary = buildMultiStoreSummary($db, $groupId);
 */

/**
 * Create a new multi-store order group.
 *
 * @param PDO     $db         Database connection
 * @param int|null $customerId Customer ID (null for anonymous)
 * @param string  $phone      Customer phone number
 * @param string  $source     Source channel: 'whatsapp' or 'voice'
 * @return string Group ID in format 'MSG-XXXXX'
 */
function initMultiStoreOrder(PDO $db, ?int $customerId, string $phone, string $source): string
{
    // Generate unique group ID: MSG- + 5 alphanumeric chars
    $maxAttempts = 10;
    $groupId = '';

    for ($i = 0; $i < $maxAttempts; $i++) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $random = '';
        for ($j = 0; $j < 5; $j++) {
            $random .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $candidate = 'MSG-' . $random;

        // Check uniqueness
        $stmt = $db->prepare("SELECT id FROM om_multi_store_orders WHERE group_id = ?");
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) {
            $groupId = $candidate;
            break;
        }
    }

    if (empty($groupId)) {
        throw new RuntimeException('Failed to generate unique multi-store group ID after ' . $maxAttempts . ' attempts');
    }

    $stmt = $db->prepare("
        INSERT INTO om_multi_store_orders (group_id, customer_id, customer_phone, order_ids, total_combined, status, source)
        VALUES (?, ?, ?, '{}', 0, 'building', ?)
    ");
    $stmt->execute([$groupId, $customerId, $phone, $source]);

    error_log("[multi-store-order] Created group {$groupId} for phone={$phone} source={$source}");

    return $groupId;
}

/**
 * Add an order to the multi-store group.
 * Updates order_ids array and total_combined.
 *
 * @param PDO    $db         Database connection
 * @param string $groupId    Group ID (MSG-XXXXX)
 * @param int    $orderId    Order ID to add
 * @param float  $orderTotal Order subtotal
 * @throws RuntimeException if group not found
 */
function addStoreToMultiOrder(PDO $db, string $groupId, int $orderId, float $orderTotal): void
{
    // Verify group exists
    $stmt = $db->prepare("SELECT id, order_ids FROM om_multi_store_orders WHERE group_id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        throw new RuntimeException("Multi-store group {$groupId} not found");
    }

    // Add order_id to array and update total
    $stmt = $db->prepare("
        UPDATE om_multi_store_orders
        SET order_ids = array_append(order_ids, ?::int),
            total_combined = total_combined + ?,
            status = CASE
                WHEN status = 'building' THEN 'building'
                ELSE 'partial'
            END
        WHERE group_id = ?
    ");
    $stmt->execute([$orderId, $orderTotal, $groupId]);

    error_log("[multi-store-order] Added order #{$orderId} (R\${$orderTotal}) to group {$groupId}");
}

/**
 * Get the status of a multi-store order group.
 * Returns group info with individual order details.
 *
 * @param PDO    $db      Database connection
 * @param string $groupId Group ID (MSG-XXXXX)
 * @return array Group status with orders
 */
function getMultiStoreStatus(PDO $db, string $groupId): array
{
    // Fetch group
    $stmt = $db->prepare("
        SELECT group_id, customer_id, customer_phone, order_ids,
               total_combined, status, source, created_at
        FROM om_multi_store_orders
        WHERE group_id = ?
    ");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        return ['error' => 'Group not found', 'group_id' => $groupId];
    }

    // Parse order_ids from PostgreSQL array
    $orderIdsRaw = $group['order_ids'] ?? '{}';
    $orderIds = parsePostgresArray($orderIdsRaw);

    $orders = [];
    $overallStatus = 'completed';
    $statusPriority = [
        'cancelado' => 0, 'cancelled' => 0, 'recusado' => 0,
        'pendente' => 1, 'confirmado' => 2, 'aceito' => 3,
        'preparando' => 4, 'em_preparo' => 4, 'pronto' => 5,
        'em_entrega' => 6, 'saiu_entrega' => 6,
        'entregue' => 7, 'retirado' => 7, 'finalizado' => 8,
    ];
    $lowestPriority = 8;

    foreach ($orderIds as $orderId) {
        try {
            $stmt = $db->prepare("
                SELECT o.order_id, o.order_number, o.status, o.total,
                       COALESCE(o.partner_name, p.trade_name, p.name) AS partner_name,
                       o.partner_id, o.distancia_km, o.date_added
                FROM om_market_orders o
                LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
                WHERE o.order_id = ?
            ");
            $stmt->execute([(int) $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) continue;

            // Get items
            $stmtItems = $db->prepare("
                SELECT name, quantity, price
                FROM om_market_order_items
                WHERE order_id = ?
            ");
            $stmtItems->execute([(int) $orderId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // Calculate ETA
            $etaMinutes = null;
            try {
                if (function_exists('calculateSmartETA') && (int) $order['partner_id'] > 0) {
                    $dist = (float) ($order['distancia_km'] ?? 3.0);
                    $etaMinutes = calculateSmartETA($db, (int) $order['partner_id'], $dist, $order['status']);
                }
            } catch (Exception $e) { /* skip */ }

            $orders[] = [
                'order_id'     => (int) $order['order_id'],
                'order_number' => $order['order_number'],
                'partner_name' => $order['partner_name'],
                'status'       => $order['status'],
                'total'        => (float) $order['total'],
                'items'        => $items,
                'eta_minutes'  => $etaMinutes,
            ];

            // Track lowest status priority for overall status
            $priority = $statusPriority[$order['status']] ?? 4;
            if ($priority < $lowestPriority) {
                $lowestPriority = $priority;
                $overallStatus = $order['status'];
            }
        } catch (Exception $e) {
            error_log("[multi-store-order] Error fetching order #{$orderId}: " . $e->getMessage());
        }
    }

    return [
        'group_id'       => $group['group_id'],
        'customer_id'    => $group['customer_id'] ? (int) $group['customer_id'] : null,
        'customer_phone' => $group['customer_phone'],
        'orders'         => $orders,
        'total_combined'  => (float) $group['total_combined'],
        'overall_status' => $overallStatus,
        'source'         => $group['source'],
        'created_at'     => $group['created_at'],
    ];
}

/**
 * Build a human-readable summary for WhatsApp/voice.
 *
 * @param PDO    $db      Database connection
 * @param string $groupId Group ID (MSG-XXXXX)
 * @return string Formatted summary text
 */
function buildMultiStoreSummary(PDO $db, string $groupId): string
{
    $status = getMultiStoreStatus($db, $groupId);

    if (isset($status['error'])) {
        return "Pedido combinado {$groupId} nao encontrado.";
    }

    $orders = $status['orders'] ?? [];
    if (empty($orders)) {
        return "Pedido combinado {$groupId}: nenhum pedido adicionado ainda.";
    }

    $lines = [];
    $lines[] = "Seu pedido combinado ({$groupId}):";
    $lines[] = "";

    foreach ($orders as $i => $order) {
        $num = $i + 1;
        $partnerName = $order['partner_name'] ?? 'Loja';
        $total = 'R$' . number_format($order['total'], 2, ',', '.');

        // ETA range (±5 min)
        $etaText = '';
        if ($order['eta_minutes']) {
            $etaMin = max(5, $order['eta_minutes'] - 5);
            $etaMax = $order['eta_minutes'] + 5;
            $etaText = " ({$etaMin}-{$etaMax} min)";
        }

        $lines[] = "{$num}. {$partnerName} -- {$total}{$etaText}";

        // List items
        $items = $order['items'] ?? [];
        foreach ($items as $item) {
            $qty = (int) ($item['quantity'] ?? 1);
            $name = $item['name'] ?? 'Item';
            $lines[] = "   - {$qty}x {$name}";
        }

        $lines[] = "";
    }

    $totalFormatted = 'R$' . number_format($status['total_combined'], 2, ',', '.');
    $lines[] = "Total: {$totalFormatted}";

    return implode("\n", $lines);
}

/**
 * Detect if a customer message indicates multi-store ordering intent.
 * Heuristic check for Portuguese patterns suggesting multiple stores.
 *
 * @param string $message Customer message text
 * @return bool True if multi-store intent detected
 */
function detectMultiStoreIntent(string $message): bool
{
    $lower = mb_strtolower(trim($message), 'UTF-8');

    $patterns = [
        // "e tambem da/do/de" — and also from
        '/e\s+tamb[eé]m\s+d[aoe]\b/',
        // "e da [store]" / "e do [store]"
        '/\be\s+d[ao]\s+\w+/',
        // "de dois/duas lugares/lojas/restaurantes"
        '/de\s+(dois|duas|tres|tr[eê]s)\s+(lugar|loja|restaurante|sitio)/u',
        // "mais um pedido da" / "outro pedido da/do"
        '/mais\s+um\s+pedido\s+d[aoe]\b/',
        '/outro\s+pedido\s+d[aoe]\b/',
        // "quero pedir tambem" / "tambem quero"
        '/quero\s+pedir\s+tamb[eé]m/',
        '/tamb[eé]m\s+quero\s+(pedir|da|do)/',
        // "de duas lojas" / "duas lojas diferentes"
        '/(duas|dois|tres)\s+lojas?\s*(diferente)?/',
        // "e mais da/do" — "and more from"
        '/e\s+mais\s+d[aoe]\b/',
        // "alem de" / "alem do"
        '/al[eé]m\s+d[aoe]\b/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $lower)) {
            return true;
        }
    }

    return false;
}

/**
 * Mark a multi-store group as submitted (all stores added).
 *
 * @param PDO    $db      Database connection
 * @param string $groupId Group ID
 */
function submitMultiStoreOrder(PDO $db, string $groupId): void
{
    $stmt = $db->prepare("
        UPDATE om_multi_store_orders
        SET status = 'submitted'
        WHERE group_id = ? AND status = 'building'
    ");
    $stmt->execute([$groupId]);
}

/**
 * Mark multi-store group as completed (all orders delivered).
 *
 * @param PDO    $db      Database connection
 * @param string $groupId Group ID
 */
function completeMultiStoreOrder(PDO $db, string $groupId): void
{
    $stmt = $db->prepare("
        UPDATE om_multi_store_orders
        SET status = 'completed'
        WHERE group_id = ? AND status IN ('submitted', 'partial')
    ");
    $stmt->execute([$groupId]);
}

/**
 * Parse a PostgreSQL array string like '{1,2,3}' into a PHP array.
 *
 * @param string $pgArray PostgreSQL array string
 * @return array Parsed array of values
 */
function parsePostgresArray(string $pgArray): array
{
    $pgArray = trim($pgArray);
    if ($pgArray === '{}' || $pgArray === '' || $pgArray === 'NULL') {
        return [];
    }

    // Remove braces
    $inner = trim($pgArray, '{}');
    if (empty($inner)) {
        return [];
    }

    return array_map('trim', explode(',', $inner));
}
