<?php
/**
 * /api/mercado/admin/callcenter/customer-lookup.php
 *
 * Customer lookup for call center agents.
 *
 * GET ?q=text: Search customers by phone or name (ILIKE).
 * GET ?phone=exact: Exact phone match for caller ID lookup.
 * POST action='create_quick': Quick-create customer with phone + name.
 *
 * For each customer found, returns: recent orders (last 5 with items),
 * saved addresses, total orders count, total spent.
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    // =================== GET: Search / Lookup ===================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        $q = trim($_GET['q'] ?? '');
        $phone = trim($_GET['phone'] ?? '');

        if (!$q && !$phone) {
            response(false, null, "Informe 'q' (busca) ou 'phone' (exato)", 400);
        }

        $customers = [];

        if ($phone) {
            // Exact phone match (caller ID)
            $cleanPhone = preg_replace('/\D/', '', $phone);
            $stmt = $db->prepare("
                SELECT customer_id, name, email, phone, cpf, foto, created_at, last_login
                FROM om_customers
                WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?
                   OR phone = ?
                LIMIT 5
            ");
            $stmt->execute(['%' . $cleanPhone, $phone]);
            $customers = $stmt->fetchAll();
        } else {
            // Fuzzy search by phone or name
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $q);
            $like = '%' . $escaped . '%';
            $stmt = $db->prepare("
                SELECT customer_id, name, email, phone, cpf, foto, created_at, last_login
                FROM om_customers
                WHERE phone ILIKE ? OR name ILIKE ? OR email ILIKE ?
                ORDER BY
                    CASE WHEN phone ILIKE ? THEN 0 ELSE 1 END,
                    last_login DESC NULLS LAST
                LIMIT 10
            ");
            $stmt->execute([$like, $like, $like, $like]);
            $customers = $stmt->fetchAll();
        }

        // Enrich each customer with stats and recent data
        $results = [];
        foreach ($customers as $c) {
            $cid = (int)$c['customer_id'];

            // Order stats
            $stmt = $db->prepare("
                SELECT
                    COUNT(*) AS total_orders,
                    COALESCE(SUM(total), 0) AS total_spent,
                    MAX(created_at) AS last_order_date
                FROM om_market_orders
                WHERE customer_id = ? AND status NOT IN ('cancelado')
            ");
            $stmt->execute([$cid]);
            $stats = $stmt->fetch();

            // Recent orders (last 5)
            $stmt = $db->prepare("
                SELECT o.order_id, o.order_number, o.status, o.total, o.subtotal,
                       o.delivery_fee, o.forma_pagamento, o.created_at,
                       o.delivery_address, o.delivered_at,
                       p.name AS partner_name, p.partner_id
                FROM om_market_orders o
                LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                WHERE o.customer_id = ?
                ORDER BY o.created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$cid]);
            $recentOrders = $stmt->fetchAll();

            // Fetch items for each recent order
            foreach ($recentOrders as &$order) {
                $order['order_id'] = (int)$order['order_id'];
                $order['total'] = (float)$order['total'];
                $order['subtotal'] = (float)$order['subtotal'];
                $order['delivery_fee'] = (float)$order['delivery_fee'];

                $stmtItems = $db->prepare("
                    SELECT name, quantity, price, total
                    FROM om_market_order_items
                    WHERE order_id = ?
                    ORDER BY id ASC
                ");
                $stmtItems->execute([$order['order_id']]);
                $order['items'] = $stmtItems->fetchAll();
            }
            unset($order);

            // Saved addresses
            $stmt = $db->prepare("
                SELECT address_id, label, street, number, complement, neighborhood,
                       city, state, zipcode, lat, lng, is_default
                FROM om_customer_addresses
                WHERE customer_id = ? AND is_active = '1'
                ORDER BY is_default DESC, address_id DESC
                LIMIT 10
            ");
            $stmt->execute([$cid]);
            $addresses = $stmt->fetchAll();

            foreach ($addresses as &$addr) {
                $addr['address_id'] = (int)$addr['address_id'];
                $addr['lat'] = $addr['lat'] ? (float)$addr['lat'] : null;
                $addr['lng'] = $addr['lng'] ? (float)$addr['lng'] : null;
                $addr['full_address'] = $addr['street'] . ', ' . $addr['number']
                    . ($addr['complement'] ? ' - ' . $addr['complement'] : '')
                    . ' - ' . $addr['neighborhood'] . ', ' . $addr['city'] . '/' . $addr['state'];
            }
            unset($addr);

            $results[] = [
                'customer_id' => $cid,
                'name' => $c['name'],
                'email' => $c['email'],
                'phone' => $c['phone'],
                'cpf' => $c['cpf'],
                'foto' => $c['foto'],
                'created_at' => $c['created_at'],
                'last_login' => $c['last_login'],
                'total_orders' => (int)$stats['total_orders'],
                'total_spent' => round((float)$stats['total_spent'], 2),
                'last_order_date' => $stats['last_order_date'],
                'recent_orders' => $recentOrders,
                'addresses' => $addresses,
            ];
        }

        response(true, [
            'customers' => $results,
            'count' => count($results),
        ]);
    }

    // =================== POST: Quick create customer ===================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $input = getInput();
        $action = trim($input['action'] ?? '');

        if ($action === 'create_quick') {
            $name = strip_tags(trim($input['name'] ?? ''));
            $phone = strip_tags(trim($input['phone'] ?? ''));

            if (!$name) response(false, null, "Nome obrigatorio", 400);
            if (!$phone) response(false, null, "Telefone obrigatorio", 400);

            // Validate phone format (at least 10 digits)
            $cleanPhone = preg_replace('/\D/', '', $phone);
            if (strlen($cleanPhone) < 10 || strlen($cleanPhone) > 15) {
                response(false, null, "Telefone invalido (min 10 digitos)", 400);
            }

            // Check if phone already exists
            $stmt = $db->prepare("
                SELECT customer_id, name, phone
                FROM om_customers
                WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?
                LIMIT 1
            ");
            $stmt->execute(['%' . $cleanPhone]);
            $existing = $stmt->fetch();

            if ($existing) {
                response(false, [
                    'existing_customer' => [
                        'customer_id' => (int)$existing['customer_id'],
                        'name' => $existing['name'],
                        'phone' => $existing['phone'],
                    ]
                ], "Ja existe cliente com este telefone", 409);
            }

            // Format phone
            $formattedPhone = '+' . $cleanPhone;

            $stmt = $db->prepare("
                INSERT INTO om_customers (name, phone, is_active, status, created_at)
                VALUES (?, ?, '1', 'active', NOW())
                RETURNING customer_id
            ");
            $stmt->execute([$name, $formattedPhone]);
            $row = $stmt->fetch();
            $newCustomerId = (int)$row['customer_id'];

            om_audit()->log(
                'callcenter_customer_quick_create',
                'customer',
                $newCustomerId,
                null,
                ['name' => $name, 'phone' => $formattedPhone, 'created_by_agent' => $admin_id],
                "Cliente '{$name}' criado via call center"
            );

            response(true, [
                'customer_id' => $newCustomerId,
                'name' => $name,
                'phone' => $formattedPhone,
            ], "Cliente criado com sucesso");
        }

        response(false, null, "Acao invalida. Valores: create_quick", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/callcenter/customer-lookup] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
