<?php
/**
 * /api/mercado/admin/cestas/producao.php
 * Production planning (admin only).
 *
 * GET ?view=schedule&date=YYYY-MM-DD   — Production schedule for a date (or date range)
 * GET ?view=shopping_list&date=YYYY-MM-DD — Aggregated shopping list
 * GET ?view=by_zone&date=YYYY-MM-DD    — Orders grouped by delivery zone/neighborhood
 * POST ?action=generate_orders         — Generate delivery orders for a date from active subscriptions
 */
require_once __DIR__ . "/../../config/database.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $payload = om_auth()->requireAdmin();

    // Ensure tables exist
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_cesta_orders (
            id SERIAL PRIMARY KEY,
            subscription_id INT NOT NULL,
            customer_id INT NOT NULL,
            box_id INT NOT NULL,
            variant_id INT,
            delivery_date DATE NOT NULL,
            status VARCHAR(30) DEFAULT 'scheduled',
            assembly_location VARCHAR(200),
            delivery_zone_id INT,
            driver_id INT,
            driver_name VARCHAR(100),
            delivery_photo TEXT,
            customer_confirmed BOOLEAN DEFAULT false,
            customer_confirmed_at TIMESTAMP,
            delivered_at TIMESTAMP,
            notes TEXT,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS om_cesta_order_items (
            id SERIAL PRIMARY KEY,
            order_id INT NOT NULL,
            product_name VARCHAR(200) NOT NULL,
            quantity NUMERIC(10,2) NOT NULL DEFAULT 1,
            unit VARCHAR(30) NOT NULL DEFAULT 'un',
            cost_price NUMERIC(10,2) DEFAULT 0,
            was_substituted BOOLEAN DEFAULT false,
            substitution_note TEXT,
            created_at TIMESTAMP DEFAULT NOW()
        );
    ");

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ── GET: Production views ────────────────────────────────────
    if ($method === 'GET') {
        $view = $_GET['view'] ?? 'schedule';
        $date = $_GET['date'] ?? date('Y-m-d');
        $dateTo = $_GET['date_to'] ?? $date;

        if ($view === 'schedule') {
            // Production schedule: how many baskets per type per day
            $stmt = $db->prepare("
                SELECT
                    o.delivery_date,
                    b.id AS box_id,
                    b.name AS box_name,
                    b.category,
                    v.name AS variant_name,
                    o.status,
                    COUNT(*) AS order_count,
                    o.assembly_location
                FROM om_cesta_orders o
                JOIN om_subscription_boxes b ON o.box_id = b.id
                LEFT JOIN om_cesta_variants v ON o.variant_id = v.id
                WHERE o.delivery_date BETWEEN ? AND ?
                GROUP BY o.delivery_date, b.id, b.name, b.category, v.name, o.status, o.assembly_location
                ORDER BY o.delivery_date ASC, b.name ASC
            ");
            $stmt->execute([$date, $dateTo]);
            $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($schedule as &$s) {
                $s['order_count'] = (int)$s['order_count'];
            }

            // Daily totals
            $stmt = $db->prepare("
                SELECT
                    delivery_date,
                    COUNT(*) AS total_orders,
                    COUNT(*) FILTER (WHERE status = 'scheduled') AS scheduled,
                    COUNT(*) FILTER (WHERE status = 'preparing') AS preparing,
                    COUNT(*) FILTER (WHERE status = 'ready') AS ready,
                    COUNT(*) FILTER (WHERE status = 'out_for_delivery') AS out_for_delivery,
                    COUNT(*) FILTER (WHERE status = 'delivered') AS delivered,
                    COUNT(*) FILTER (WHERE status = 'failed') AS failed,
                    COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled
                FROM om_cesta_orders
                WHERE delivery_date BETWEEN ? AND ?
                GROUP BY delivery_date
                ORDER BY delivery_date ASC
            ");
            $stmt->execute([$date, $dateTo]);
            $dailyTotals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            response(true, [
                'schedule' => $schedule,
                'daily_totals' => $dailyTotals,
                'date_range' => ['from' => $date, 'to' => $dateTo],
            ]);
        }

        if ($view === 'shopping_list') {
            // Aggregated shopping list for a date: total of each item needed
            $stmt = $db->prepare("
                SELECT
                    oi.product_name,
                    oi.unit,
                    SUM(oi.quantity) AS total_quantity,
                    COUNT(DISTINCT oi.order_id) AS order_count,
                    AVG(oi.cost_price) AS avg_cost,
                    SUM(oi.quantity * oi.cost_price) AS total_cost
                FROM om_cesta_order_items oi
                JOIN om_cesta_orders o ON oi.order_id = o.id
                WHERE o.delivery_date = ?
                  AND o.status NOT IN ('cancelled', 'failed')
                GROUP BY oi.product_name, oi.unit
                ORDER BY oi.product_name ASC
            ");
            $stmt->execute([$date]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as &$item) {
                $item['total_quantity'] = (float)$item['total_quantity'];
                $item['order_count'] = (int)$item['order_count'];
                $item['avg_cost'] = round((float)$item['avg_cost'], 2);
                $item['total_cost'] = round((float)$item['total_cost'], 2);
            }

            // If no order items exist yet, fall back to aggregating from cesta_items templates
            if (empty($items)) {
                $stmt = $db->prepare("
                    SELECT
                        ci.product_name,
                        ci.unit,
                        ci.supplier,
                        SUM(ci.quantity) AS total_quantity,
                        COUNT(DISTINCT o.id) AS order_count,
                        AVG(ci.cost_price) AS avg_cost,
                        SUM(ci.quantity * ci.cost_price) AS total_cost
                    FROM om_cesta_orders o
                    JOIN om_cesta_items ci ON ci.box_id = o.box_id
                    WHERE o.delivery_date = ?
                      AND o.status NOT IN ('cancelled', 'failed')
                    GROUP BY ci.product_name, ci.unit, ci.supplier
                    ORDER BY ci.product_name ASC
                ");
                $stmt->execute([$date]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($items as &$item) {
                    $item['total_quantity'] = (float)$item['total_quantity'];
                    $item['order_count'] = (int)$item['order_count'];
                    $item['avg_cost'] = round((float)$item['avg_cost'], 2);
                    $item['total_cost'] = round((float)$item['total_cost'], 2);
                }
            }

            $totalCost = array_sum(array_column($items, 'total_cost'));

            response(true, [
                'shopping_list' => $items,
                'total_cost' => round($totalCost, 2),
                'date' => $date,
            ]);
        }

        if ($view === 'by_zone') {
            // Orders grouped by city/neighborhood/zone
            $stmt = $db->prepare("
                SELECT
                    COALESCE(s.city, 'Nao informada') AS city,
                    COALESCE(s.neighborhood, 'Nao informado') AS neighborhood,
                    b.name AS box_name,
                    o.status,
                    COUNT(*) AS order_count,
                    o.assembly_location,
                    o.driver_name
                FROM om_cesta_orders o
                JOIN om_box_subscriptions s ON o.subscription_id = s.id
                JOIN om_subscription_boxes b ON o.box_id = b.id
                WHERE o.delivery_date = ?
                  AND o.status NOT IN ('cancelled')
                GROUP BY s.city, s.neighborhood, b.name, o.status, o.assembly_location, o.driver_name
                ORDER BY s.city ASC, s.neighborhood ASC, b.name ASC
            ");
            $stmt->execute([$date]);
            $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($zones as &$z) {
                $z['order_count'] = (int)$z['order_count'];
            }

            response(true, [
                'by_zone' => $zones,
                'date' => $date,
            ]);
        }

        // Calendar view: order counts for each day in a month
        if ($view === 'calendar') {
            $month = $_GET['month'] ?? date('Y-m');
            $startDate = $month . '-01';
            $endDate = date('Y-m-t', strtotime($startDate));

            $stmt = $db->prepare("
                SELECT
                    delivery_date,
                    COUNT(*) AS total_orders,
                    COUNT(*) FILTER (WHERE status = 'delivered') AS delivered,
                    COUNT(*) FILTER (WHERE status IN ('scheduled', 'preparing', 'ready')) AS pending
                FROM om_cesta_orders
                WHERE delivery_date BETWEEN ? AND ?
                GROUP BY delivery_date
                ORDER BY delivery_date ASC
            ");
            $stmt->execute([$startDate, $endDate]);
            $days = $stmt->fetchAll(PDO::FETCH_ASSOC);

            response(true, [
                'calendar' => $days,
                'month' => $month,
            ]);
        }

        response(true, ['message' => 'view invalida. Use: schedule, shopping_list, by_zone, calendar']);
    }

    // ── POST: Generate orders from active subscriptions ──────────
    if ($method === 'POST') {
        $input = getInput();
        $action = trim($input['action'] ?? '');

        if ($action === 'generate_orders') {
            $date = trim($input['date'] ?? '');
            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                response(false, null, 'Data invalida (use YYYY-MM-DD)', 400);
            }

            $dow = (int)(new DateTime($date))->format('w'); // 0=Sun ... 6=Sat

            // Find active subscriptions that should deliver on this day of week
            // and match the frequency schedule
            $stmt = $db->prepare("
                SELECT s.id AS subscription_id, s.customer_id, s.box_id, s.variant_id,
                       s.frequency, s.city, s.neighborhood
                FROM om_box_subscriptions s
                WHERE s.status = 'active'
                  AND s.delivery_day = ?
                  AND NOT EXISTS (
                      SELECT 1 FROM om_cesta_orders o
                      WHERE o.subscription_id = s.id AND o.delivery_date = ?
                  )
            ");
            $stmt->execute([$dow, $date]);
            $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $created = 0;
            $skipped = 0;

            $stmtInsert = $db->prepare("
                INSERT INTO om_cesta_orders (subscription_id, customer_id, box_id, variant_id, delivery_date, status)
                VALUES (?, ?, ?, ?, ?, 'scheduled')
            ");

            $stmtInsertItems = $db->prepare("
                INSERT INTO om_cesta_order_items (order_id, product_name, quantity, unit, cost_price)
                SELECT ?, ci.product_name, ci.quantity, ci.unit, ci.cost_price
                FROM om_cesta_items ci WHERE ci.box_id = ?
            ");

            $db->beginTransaction();

            foreach ($subs as $sub) {
                // Check frequency: for biweekly, check if this is the right week
                if ($sub['frequency'] === 'biweekly') {
                    $subStart = new DateTime($date);
                    $weekNum = (int)$subStart->format('W');
                    if ($weekNum % 2 !== 0) {
                        $skipped++;
                        continue;
                    }
                }
                if ($sub['frequency'] === 'monthly') {
                    // Only deliver on the first occurrence of this weekday in the month
                    $dayOfMonth = (int)(new DateTime($date))->format('j');
                    if ($dayOfMonth > 7) {
                        $skipped++;
                        continue;
                    }
                }

                $stmtInsert->execute([
                    $sub['subscription_id'],
                    $sub['customer_id'],
                    $sub['box_id'],
                    $sub['variant_id'],
                    $date
                ]);

                // Get the newly created order ID
                $orderId = (int)$db->lastInsertId('om_cesta_orders_id_seq');

                // Copy template items into order items
                $stmtInsertItems->execute([$orderId, $sub['box_id']]);

                $created++;
            }

            // Update next_delivery for these subscriptions
            foreach ($subs as $sub) {
                $daysAhead = match ($sub['frequency']) {
                    'daily' => 1,
                    '3x_week' => 2,
                    'weekly' => 7,
                    'biweekly' => 14,
                    'monthly' => 28,
                    default => 7
                };
                $nextDate = (new DateTime($date))->modify("+{$daysAhead} days")->format('Y-m-d');
                $db->prepare("UPDATE om_box_subscriptions SET next_delivery = ?, renewal_count = renewal_count + 1 WHERE id = ?")
                   ->execute([$nextDate, $sub['subscription_id']]);
            }

            $db->commit();

            response(true, [
                'created' => $created,
                'skipped' => $skipped,
                'date' => $date,
            ], "Gerados {$created} pedidos para {$date}");
        }

        response(false, null, 'Acao invalida', 400);
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[cestas/producao] ' . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
