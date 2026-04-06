<?php
/**
 * POST /api/mercado/mesa/pedido.php
 *
 * Creates a table order from QR code menu.
 * Works WITH or WITHOUT customer authentication.
 *
 * Body: {
 *   table_code: string (required - compound "PARTNER_ID-TABLE_NUMBER" or table ID),
 *   items: [{ product_id, quantity, notes?, options?: [{ group_id, option_ids: [] }] }],
 *   customer_name: string (required if not authenticated),
 *   customer_phone: string (optional)
 * }
 */

require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $db = getDB();
    $input = getInput();

    // Optional auth: try to get customer_id, but don't require it
    $customerId = getCustomerIdFromToken();

    $tableCode = trim($input['table_code'] ?? '');
    if (empty($tableCode)) {
        response(false, null, "table_code e obrigatorio", 400);
    }

    // Sanitize table code
    $tableCode = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $tableCode), 0, 200);

    $items = $input['items'] ?? [];
    if (empty($items) || !is_array($items)) {
        response(false, null, "items e obrigatorio (array de produtos)", 400);
    }

    // Limit items count to prevent abuse
    if (count($items) > 50) {
        response(false, null, "Maximo de 50 itens por pedido", 400);
    }

    // Customer info: from auth or from input
    $customerName = '';
    $customerPhone = '';
    $customerEmail = '';

    if ($customerId > 0) {
        // Authenticated: fetch from DB
        $stmtCust = dbQuery($db,
            "SELECT name, phone, email FROM om_customers WHERE customer_id = ?",
            [$customerId]
        );
        $custData = $stmtCust->fetch();
        if ($custData) {
            $customerName = trim($custData['name'] ?: '');
            $customerPhone = preg_replace('/[^0-9]/', '', $custData['phone'] ?? '');
            $customerEmail = $custData['email'] ?? '';
        }
    }

    // Allow input to override/supplement (especially for non-authenticated users)
    if (empty($customerName)) {
        $customerName = trim(substr(strip_tags($input['customer_name'] ?? ''), 0, 200));
    }
    if (empty($customerPhone)) {
        $customerPhone = preg_replace('/[^0-9]/', '', substr($input['customer_phone'] ?? '', 0, 20));
    }

    if (empty($customerName)) {
        response(false, null, "Nome do cliente e obrigatorio", 400);
    }

    // Resolve table
    $table = null;
    $partnerId = 0;

    if (preg_match('/^(\d+)-(\d+)$/', $tableCode, $matches)) {
        $partnerId = (int)$matches[1];
        $tableNumero = (int)$matches[2];
        $stmt = dbQuery($db,
            "SELECT * FROM om_market_qr_tables WHERE partner_id = ? AND numero = ? AND ativo = true LIMIT 1",
            [$partnerId, $tableNumero]
        );
        $table = $stmt->fetch();
    }

    if (!$table && ctype_digit($tableCode)) {
        $stmt = dbQuery($db,
            "SELECT * FROM om_market_qr_tables WHERE id = ? AND ativo = true LIMIT 1",
            [(int)$tableCode]
        );
        $table = $stmt->fetch();
        if ($table) {
            $partnerId = (int)$table['partner_id'];
        }
    }

    if (!$table) {
        response(false, null, "Mesa nao encontrada ou inativa", 404);
    }

    $partnerId = (int)$table['partner_id'];
    $tableId = (int)$table['id'];

    // Validate QR ordering config
    $stmtConfig = dbQuery($db,
        "SELECT * FROM om_market_qr_config WHERE partner_id = ?",
        [$partnerId]
    );
    $config = $stmtConfig->fetch();

    if (!$config || !$config['enabled']) {
        response(false, null, "Pedido por QR Code nao esta habilitado", 403);
    }

    $autoAccept = (bool)($config['auto_accept'] ?? false);
    $allowPaymentAtTable = (bool)($config['allow_payment_at_table'] ?? false);

    // Parse payment method
    $paymentMethod = trim($input['payment_method'] ?? 'mesa');
    if (!in_array($paymentMethod, ['mesa', 'pix'], true)) {
        $paymentMethod = 'mesa';
    }

    // If PIX requested but payment at table not allowed, fall back to mesa
    if ($paymentMethod === 'pix' && !$allowPaymentAtTable) {
        response(false, null, "Pagamento na mesa nao esta habilitado neste estabelecimento", 403);
    }

    // Validate partner exists and is active
    $stmtPartner = dbQuery($db,
        "SELECT partner_id, name, trade_name, is_open, pause_until, categoria FROM om_market_partners WHERE partner_id = ? AND status::text = '1'",
        [$partnerId]
    );
    $partner = $stmtPartner->fetch();

    if (!$partner) {
        response(false, null, "Estabelecimento nao disponivel", 400);
    }

    // Check if store is open
    $isOpen = (int)($partner['is_open'] ?? 0) === 1;
    $isPaused = !empty($partner['pause_until']) && strtotime($partner['pause_until']) > time();
    if (!$isOpen || $isPaused) {
        response(false, null, "Estabelecimento fechado no momento", 400);
    }

    $partnerName = $partner['name'] ?? $partner['trade_name'] ?? '';

    // Validate and price items
    $validItems = [];
    $subtotal = 0;

    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = max(1, min(99, (int)($item['quantity'] ?? 1)));
        $itemNotes = trim(substr(strip_tags($item['notes'] ?? ''), 0, 500));
        $selectedOptions = $item['options'] ?? [];

        if ($productId <= 0) {
            response(false, null, "product_id invalido", 400);
        }

        // Fetch product - verify it belongs to this partner and is active
        $stmtProduct = dbQuery($db,
            "SELECT product_id, name, price, special_price, quantity as stock, partner_id
             FROM om_market_products
             WHERE product_id = ? AND partner_id = ? AND status::text = '1' AND (available::text = '1' OR available IS NULL)",
            [$productId, $partnerId]
        );
        $product = $stmtProduct->fetch();

        if (!$product) {
            response(false, null, "Produto #{$productId} nao encontrado ou indisponivel", 400);
        }

        // Stock checked inside transaction with FOR UPDATE (race-safe)

        // Determine price
        $price = (float)$product['price'];
        if ($product['special_price'] && (float)$product['special_price'] > 0 && (float)$product['special_price'] < $price) {
            $price = (float)$product['special_price'];
        }

        // Calculate option extras
        $optionExtras = 0;
        $selectedOptionNames = [];

        if (!empty($selectedOptions) && is_array($selectedOptions)) {
            foreach ($selectedOptions as $optGroup) {
                $groupId = (int)($optGroup['group_id'] ?? 0);
                $optionIds = $optGroup['option_ids'] ?? [];
                if (!is_array($optionIds) || $groupId <= 0) continue;

                foreach ($optionIds as $optId) {
                    $optId = (int)$optId;
                    if ($optId <= 0) continue;

                    $stmtOpt = dbQuery($db,
                        "SELECT o.name, o.price_extra
                         FROM om_product_options o
                         INNER JOIN om_product_option_groups g ON o.group_id = g.id
                         WHERE o.id = ? AND g.id = ? AND g.product_id = ? AND o.available = 1",
                        [$optId, $groupId, $productId]
                    );
                    $opt = $stmtOpt->fetch();
                    if ($opt) {
                        $optionExtras += (float)$opt['price_extra'];
                        $selectedOptionNames[] = $opt['name'];
                    }
                }
            }
        }

        $itemPrice = $price + $optionExtras;
        $itemTotal = $itemPrice * $qty;
        $subtotal += $itemTotal;

        $validItems[] = [
            'product_id' => $productId,
            'name' => $product['name'],
            'quantity' => $qty,
            'price' => $itemPrice,
            'total' => $itemTotal,
            'notes' => $itemNotes,
            'option_names' => $selectedOptionNames
        ];
    }

    if (empty($validItems)) {
        response(false, null, "Nenhum item valido no pedido", 400);
    }

    // Total for table orders: no delivery fee, no service fee, no tip
    $total = $subtotal;

    // Generate order identifiers
    $orderNumber = 'MESA-' . strtoupper(bin2hex(random_bytes(4)));
    $codigoEntrega = strtoupper(bin2hex(random_bytes(3)));

    // Build notes with table info and item notes
    $orderNotes = "Mesa {$table['numero']}" . ($table['nome'] ? " ({$table['nome']})" : "");
    $itemNotesArr = [];
    foreach ($validItems as $vi) {
        if (!empty($vi['notes'])) {
            $itemNotesArr[] = "{$vi['name']}: {$vi['notes']}";
        }
        if (!empty($vi['option_names'])) {
            $itemNotesArr[] = "{$vi['name']}: " . implode(', ', $vi['option_names']);
        }
    }
    if (!empty($itemNotesArr)) {
        $orderNotes .= " | " . implode(' | ', $itemNotesArr);
    }
    $orderNotes = substr($orderNotes, 0, 2000);

    $initialStatus = $autoAccept ? 'aceito' : 'pendente';

    // Create order inside transaction
    $db->beginTransaction();

    try {
        // Insert order
        $stmtOrder = dbQuery($db,
            "INSERT INTO om_market_orders (
                order_number, partner_id, partner_name, customer_id,
                customer_name, customer_phone, customer_email,
                status, subtotal, delivery_fee, total, tip_amount,
                delivery_address, shipping_address,
                notes, codigo_entrega, forma_pagamento,
                is_pickup, table_id, partner_categoria,
                delivery_type, service_fee, payment_status,
                date_added
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, ?, ?, ?, ?, 1, ?, ?, 'mesa', 0, 'pending', NOW())
            RETURNING order_id",
            [
                $orderNumber, $partnerId, $partnerName, $customerId ?: null,
                $customerName, $customerPhone, $customerEmail,
                $initialStatus, $subtotal, $total,
                'Mesa ' . $table['numero'], 'Mesa ' . $table['numero'],
                $orderNotes, $codigoEntrega, $paymentMethod,
                $tableId, $partner['categoria'] ?? 'restaurante'
            ]
        );
        $orderId = (int)$stmtOrder->fetchColumn();

        if (!$orderId) {
            throw new Exception("Falha ao criar pedido");
        }

        // Update order_number with the ID for readability
        $finalOrderNumber = 'MESA-' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
        dbQuery($db,
            "UPDATE om_market_orders SET order_number = ? WHERE order_id = ?",
            [$finalOrderNumber, $orderId]
        );

        // Insert order items
        $stmtItem = $db->prepare(
            "INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?)"
        );

        foreach ($validItems as $item) {
            $stmtItem->execute([
                $orderId,
                $item['product_id'],
                $item['name'],
                $item['quantity'],
                $item['price'],
                $item['total']
            ]);

            // Decrement stock
            $stmtStock = dbQuery($db,
                "UPDATE om_market_products SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?",
                [$item['quantity'], $item['product_id'], $item['quantity']]
            );
            if ($stmtStock->rowCount() === 0) {
                $db->rollBack();
                response(false, null, "'{$item['name']}' - estoque insuficiente", 400);
            }
        }

        // Generate PIX if requested (inside transaction so rollback restores stock on failure)
        $pixData = null;
        if ($paymentMethod === 'pix') {
            try {
                require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
                $efi = new EfiClient();

                $description = "Mesa {$table['numero']} - Pedido #{$orderId} - " . ($partnerName ?: 'SuperBora');
                $customerData = ['nome' => $customerName ?: 'Cliente'];

                $result = $efi->createPixCharge($total, $description, 600, $customerData);

                $brCode = $result['qrcode_text'] ?? '';
                $qrCodeUrl = $result['qrcode_image'] ?? '';
                $txid = $result['txid'] ?? '';

                if (empty($brCode)) {
                    error_log("[mesa/pedido] PIX generation failed: " . json_encode($result));
                    $db->rollBack();
                    response(false, null, "Pagamento PIX indisponivel no momento. Tente 'Pagar ao garcom'.", 503);
                }

                // Generate QR image fallback
                if (empty($qrCodeUrl)) {
                    $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($brCode);
                }

                $pixExpiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                // Update order with PIX data
                dbQuery($db,
                    "UPDATE om_market_orders SET pix_code = ?, pix_qr_code = ? WHERE order_id = ?",
                    [$brCode, $qrCodeUrl, $orderId]
                );

                // Save to pagarme_transacoes for webhook matching
                try {
                    dbQuery($db,
                        "INSERT INTO om_pagarme_transacoes (pedido_id, charge_id, pagarme_order_id, tipo, valor, qr_code, qr_code_url, status, created_at)
                         VALUES (?, ?, ?, 'pix', ?, ?, ?, 'pending', NOW())",
                        [$orderId, $txid, $txid, $total, $brCode, $qrCodeUrl]
                    );
                } catch (Exception $e) {
                    error_log("[mesa/pedido] PIX transacoes save error: " . $e->getMessage());
                }

                $pixData = [
                    'qr_code' => $brCode,
                    'qr_code_url' => $qrCodeUrl,
                    'qr_code_text' => $brCode,
                    'charge_id' => $txid,
                    'expiration' => date('c', strtotime($pixExpiresAt))
                ];

            } catch (Exception $e) {
                error_log("[mesa/pedido] PIX exception: " . $e->getMessage());
                $db->rollBack();
                response(false, null, "Pagamento PIX indisponivel no momento. Tente 'Pagar ao garcom'.", 503);
            }
        }

        $db->commit();

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    // ===== Post-commit notifications (non-blocking) =====

    // 1. Notify partner via Pusher (real-time for partner panel)
    try {
        require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";
        PusherService::newOrder($partnerId, [
            'order_id' => $orderId,
            'order_number' => $finalOrderNumber,
            'customer_name' => $customerName,
            'total' => round($total, 2),
            'payment_method' => $paymentMethod,
            'table_id' => $tableId,
            'table_number' => (int)$table['numero'],
            'is_table_order' => true,
            'pix_pending' => ($paymentMethod === 'pix')
        ]);
    } catch (Exception $e) {
        error_log("[mesa/pedido] Pusher erro: " . $e->getMessage());
    }

    // 2. Notify partner via push notification
    try {
        require_once __DIR__ . "/../helpers/notify.php";
        notifyPartner($db, $partnerId,
            "Novo pedido Mesa {$table['numero']}",
            "Pedido #{$finalOrderNumber} - {$customerName} - R$ " . number_format($total, 2, ',', '.'),
            '/painel/pedidos',
            ['order_id' => $orderId, 'type' => 'table_order']
        );
    } catch (Exception $e) {
        error_log("[mesa/pedido] Push notify erro: " . $e->getMessage());
    }

    // 3. Notify partner via DB notification (config/notify.php)
    try {
        require_once __DIR__ . "/../config/notify.php";
        sendNotification($db, $partnerId, 'partner',
            "Novo pedido Mesa {$table['numero']}",
            "Pedido #{$finalOrderNumber} de {$customerName} - R$ " . number_format($total, 2, ',', '.'),
            ['order_id' => $orderId, 'type' => 'new_table_order', 'table_number' => (int)$table['numero']]
        );
    } catch (Exception $e) {
        error_log("[mesa/pedido] DB notify erro: " . $e->getMessage());
    }

    // 4. WebSocket broadcast to partner
    try {
        require_once __DIR__ . "/../helpers/ws-customer-broadcast.php";
        wsBroadcastToChannel("partner_{$partnerId}", 'new_table_order', [
            'order_id' => $orderId,
            'order_number' => $finalOrderNumber,
            'table_id' => $tableId,
            'table_number' => (int)$table['numero'],
            'customer_name' => $customerName,
            'total' => round($total, 2),
            'status' => $initialStatus,
            'items_count' => count($validItems)
        ]);
    } catch (Exception $e) {
        error_log("[mesa/pedido] WS broadcast erro: " . $e->getMessage());
    }

    $responseData = [
        'order_id' => $orderId,
        'order_number' => $finalOrderNumber,
        'status' => $initialStatus,
        'table_number' => (int)$table['numero'],
        'table_name' => $table['nome'],
        'total' => round($total, 2),
        'items_count' => count($validItems),
        'auto_accepted' => $autoAccept,
        'payment_method' => $paymentMethod,
        'message' => $autoAccept
            ? "Pedido aceito automaticamente! Aguarde o preparo."
            : "Pedido enviado! Aguarde a confirmacao do estabelecimento."
    ];

    if ($paymentMethod === 'pix' && $pixData) {
        $responseData['pix'] = $pixData;
        $responseData['message'] = 'Pedido criado! Pague o PIX para confirmar.';
    }

    response(true, $responseData, "Pedido criado com sucesso");

} catch (Exception $e) {
    error_log("[mesa/pedido] Erro: " . $e->getMessage());
    response(false, null, "Erro ao criar pedido", 500);
}
