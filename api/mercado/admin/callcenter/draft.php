<?php
/**
 * /api/mercado/admin/callcenter/draft.php
 *
 * Order draft management for call center agents.
 * This is the core file — agents build orders step by step, then submit.
 *
 * GET ?draft_id=X: Get draft details.
 * GET ?agent_id=X: List active drafts for an agent.
 * GET ?recent=true: Recent submitted drafts (last 20).
 *
 * POST action='create': Create new draft.
 * POST action='add_item': Add item to draft.
 * POST action='remove_item': Remove item by index.
 * POST action='update_item': Update item quantity at index.
 * POST action='set_customer': Set customer info + address on draft.
 * POST action='set_payment': Set payment method.
 * POST action='apply_coupon': Validate and apply coupon.
 * POST action='submit': Submit draft as a real order.
 * POST action='send_sms_summary': Send order summary via SMS.
 * POST action='cancel': Cancel draft.
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';

setCorsHeaders();

/**
 * Recalculate draft subtotal, service fee, and total.
 */
function recalculateDraft(PDO $db, int $draftId): void {
    $stmt = $db->prepare("SELECT * FROM om_callcenter_order_drafts WHERE id = ?");
    $stmt->execute([$draftId]);
    $draft = $stmt->fetch();
    if (!$draft) return;

    $items = json_decode($draft['items'], true) ?: [];
    $subtotal = 0;
    foreach ($items as $item) {
        $itemTotal = ((float)($item['price'] ?? 0)) * ((int)($item['quantity'] ?? 1));
        foreach (($item['options'] ?? []) as $opt) {
            $itemTotal += ((float)($opt['price'] ?? 0)) * ((int)($item['quantity'] ?? 1));
        }
        $subtotal += $itemTotal;
    }

    $serviceFee = round($subtotal * 0.08, 2); // 8% service fee for callcenter orders
    $deliveryFee = (float)($draft['delivery_fee'] ?? 0);
    $tip = (float)($draft['tip'] ?? 0);
    $discount = (float)($draft['discount'] ?? 0);
    $total = round($subtotal + $deliveryFee + $serviceFee + $tip - $discount, 2);

    $stmt = $db->prepare("
        UPDATE om_callcenter_order_drafts
        SET subtotal = ?, service_fee = ?, total = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$subtotal, $serviceFee, $total, $draftId]);
}

/**
 * Fetch and return a draft with parsed JSONB fields.
 */
function fetchDraft(PDO $db, int $draftId): ?array {
    $stmt = $db->prepare("
        SELECT d.*,
               a.display_name AS agent_name,
               p.name AS partner_display_name
        FROM om_callcenter_order_drafts d
        LEFT JOIN om_callcenter_agents a ON d.agent_id = a.id
        LEFT JOIN om_market_partners p ON d.partner_id = p.partner_id
        WHERE d.id = ?
    ");
    $stmt->execute([$draftId]);
    $draft = $stmt->fetch();
    if (!$draft) return null;

    $draft['id'] = (int)$draft['id'];
    $draft['items'] = json_decode($draft['items'], true) ?: [];
    $draft['address'] = $draft['address'] ? json_decode($draft['address'], true) : null;
    $draft['subtotal'] = (float)$draft['subtotal'];
    $draft['delivery_fee'] = (float)$draft['delivery_fee'];
    $draft['service_fee'] = (float)$draft['service_fee'];
    $draft['tip'] = (float)$draft['tip'];
    $draft['discount'] = (float)$draft['discount'];
    $draft['total'] = (float)$draft['total'];
    $draft['partner_id'] = $draft['partner_id'] ? (int)$draft['partner_id'] : null;
    $draft['customer_id'] = $draft['customer_id'] ? (int)$draft['customer_id'] : null;
    $draft['agent_id'] = $draft['agent_id'] ? (int)$draft['agent_id'] : null;
    $draft['submitted_order_id'] = $draft['submitted_order_id'] ? (int)$draft['submitted_order_id'] : null;

    return $draft;
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = (int)$payload['uid'];

    // Resolve agent_id from admin_id
    $stmtAgent = $db->prepare("SELECT id FROM om_callcenter_agents WHERE admin_id = ?");
    $stmtAgent->execute([$admin_id]);
    $agentRow = $stmtAgent->fetch();
    $currentAgentId = $agentRow ? (int)$agentRow['id'] : null;

    // =================== GET ===================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        $draftId = (int)($_GET['draft_id'] ?? 0);
        $agentId = (int)($_GET['agent_id'] ?? 0);
        $recent = ($_GET['recent'] ?? '') === 'true';

        // Single draft detail
        if ($draftId) {
            $draft = fetchDraft($db, $draftId);
            if (!$draft) response(false, null, "Rascunho nao encontrado", 404);
            response(true, ['draft' => $draft]);
        }

        // Active drafts for agent
        if ($agentId) {
            $stmt = $db->prepare("
                SELECT d.id, d.status, d.customer_name, d.customer_phone,
                       d.partner_name, d.subtotal, d.total, d.source,
                       d.created_at, d.updated_at,
                       (SELECT COUNT(*) FROM jsonb_array_elements(d.items)) AS items_count
                FROM om_callcenter_order_drafts d
                WHERE d.agent_id = ? AND d.status IN ('building', 'review', 'awaiting_payment')
                ORDER BY d.updated_at DESC
            ");
            $stmt->execute([$agentId]);
            $drafts = $stmt->fetchAll();

            foreach ($drafts as &$d) {
                $d['id'] = (int)$d['id'];
                $d['subtotal'] = (float)$d['subtotal'];
                $d['total'] = (float)$d['total'];
                $d['items_count'] = (int)$d['items_count'];
            }
            unset($d);

            response(true, ['drafts' => $drafts]);
        }

        // Recent submitted drafts
        if ($recent) {
            $stmt = $db->prepare("
                SELECT d.id, d.status, d.customer_name, d.customer_phone,
                       d.partner_name, d.total, d.source, d.submitted_order_id,
                       d.created_at, d.updated_at,
                       a.display_name AS agent_name
                FROM om_callcenter_order_drafts d
                LEFT JOIN om_callcenter_agents a ON d.agent_id = a.id
                WHERE d.status IN ('submitted', 'cancelled')
                ORDER BY d.updated_at DESC
                LIMIT 20
            ");
            $stmt->execute();
            $drafts = $stmt->fetchAll();

            foreach ($drafts as &$d) {
                $d['id'] = (int)$d['id'];
                $d['total'] = (float)$d['total'];
                $d['submitted_order_id'] = $d['submitted_order_id'] ? (int)$d['submitted_order_id'] : null;
            }
            unset($d);

            response(true, ['drafts' => $drafts]);
        }

        response(false, null, "Informe draft_id, agent_id, ou recent=true", 400);
    }

    // =================== POST ===================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $input = getInput();
        $action = trim($input['action'] ?? '');

        // ── Create draft ──
        if ($action === 'create') {
            $partnerId = (int)($input['partner_id'] ?? 0);
            $source = trim($input['source'] ?? 'manual');

            $validSources = ['phone', 'whatsapp', 'manual'];
            if (!in_array($source, $validSources, true)) {
                $source = 'manual';
            }

            // Validate partner if provided
            $partnerName = null;
            if ($partnerId) {
                $stmt = $db->prepare("SELECT name FROM om_market_partners WHERE partner_id = ?");
                $stmt->execute([$partnerId]);
                $partner = $stmt->fetch();
                if (!$partner) response(false, null, "Loja nao encontrada", 404);
                $partnerName = $partner['name'];
            }

            $callId = !empty($input['call_id']) ? (int)$input['call_id'] : null;
            $whatsappId = !empty($input['whatsapp_id']) ? (int)$input['whatsapp_id'] : null;

            $stmt = $db->prepare("
                INSERT INTO om_callcenter_order_drafts
                    (agent_id, call_id, whatsapp_id, source, partner_id, partner_name, status, items, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'building', '[]', NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([
                $currentAgentId, $callId, $whatsappId,
                $source, $partnerId ?: null, $partnerName
            ]);
            $row = $stmt->fetch();
            $newDraftId = (int)$row['id'];

            $draft = fetchDraft($db, $newDraftId);
            response(true, ['draft' => $draft], "Rascunho criado");
        }

        // ── Add item ──
        if ($action === 'add_item') {
            $draftId = (int)($input['draft_id'] ?? 0);
            if (!$draftId) response(false, null, "draft_id obrigatorio", 400);

            $stmt = $db->prepare("SELECT id, items, status FROM om_callcenter_order_drafts WHERE id = ?");
            $stmt->execute([$draftId]);
            $draft = $stmt->fetch();
            if (!$draft) response(false, null, "Rascunho nao encontrado", 404);
            if (!in_array($draft['status'], ['building', 'review'])) {
                response(false, null, "Rascunho nao pode ser editado (status: {$draft['status']})", 400);
            }

            $items = json_decode($draft['items'], true) ?: [];

            $newItem = [
                'product_id' => (int)($input['product_id'] ?? 0),
                'name' => strip_tags(trim($input['name'] ?? '')),
                'price' => round((float)($input['price'] ?? 0), 2),
                'quantity' => max(1, (int)($input['quantity'] ?? 1)),
                'options' => [],
                'notes' => strip_tags(trim($input['notes'] ?? '')),
            ];

            if (!$newItem['name']) response(false, null, "Nome do item obrigatorio", 400);
            if ($newItem['price'] < 0) response(false, null, "Preco invalido", 400);

            // Parse options
            if (isset($input['options']) && is_array($input['options'])) {
                foreach ($input['options'] as $opt) {
                    $newItem['options'][] = [
                        'name' => strip_tags(trim($opt['name'] ?? '')),
                        'price' => round((float)($opt['price'] ?? 0), 2),
                    ];
                }
            }

            $items[] = $newItem;

            $stmt = $db->prepare("UPDATE om_callcenter_order_drafts SET items = ?::jsonb, updated_at = NOW() WHERE id = ?");
            $stmt->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $draftId]);

            recalculateDraft($db, $draftId);
            $updatedDraft = fetchDraft($db, $draftId);

            response(true, ['draft' => $updatedDraft], "Item adicionado");
        }

        // ── Remove item ──
        if ($action === 'remove_item') {
            $draftId = (int)($input['draft_id'] ?? 0);
            $index = isset($input['index']) ? (int)$input['index'] : -1;

            if (!$draftId) response(false, null, "draft_id obrigatorio", 400);
            if ($index < 0) response(false, null, "index obrigatorio (0-based)", 400);

            $stmt = $db->prepare("SELECT id, items, status FROM om_callcenter_order_drafts WHERE id = ?");
            $stmt->execute([$draftId]);
            $draft = $stmt->fetch();
            if (!$draft) response(false, null, "Rascunho nao encontrado", 404);
            if (!in_array($draft['status'], ['building', 'review'])) {
                response(false, null, "Rascunho nao pode ser editado", 400);
            }

            $items = json_decode($draft['items'], true) ?: [];
            if ($index >= count($items)) {
                response(false, null, "Indice invalido (max: " . (count($items) - 1) . ")", 400);
            }

            array_splice($items, $index, 1);

            $stmt = $db->prepare("UPDATE om_callcenter_order_drafts SET items = ?::jsonb, updated_at = NOW() WHERE id = ?");
            $stmt->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $draftId]);

            recalculateDraft($db, $draftId);
            $updatedDraft = fetchDraft($db, $draftId);

            response(true, ['draft' => $updatedDraft], "Item removido");
        }

        // ── Update item quantity ──
        if ($action === 'update_item') {
            $draftId = (int)($input['draft_id'] ?? 0);
            $index = isset($input['index']) ? (int)$input['index'] : -1;
            $quantity = max(1, (int)($input['quantity'] ?? 1));

            if (!$draftId) response(false, null, "draft_id obrigatorio", 400);
            if ($index < 0) response(false, null, "index obrigatorio", 400);

            $stmt = $db->prepare("SELECT id, items, status FROM om_callcenter_order_drafts WHERE id = ?");
            $stmt->execute([$draftId]);
            $draft = $stmt->fetch();
            if (!$draft) response(false, null, "Rascunho nao encontrado", 404);
            if (!in_array($draft['status'], ['building', 'review'])) {
                response(false, null, "Rascunho nao pode ser editado", 400);
            }

            $items = json_decode($draft['items'], true) ?: [];
            if ($index >= count($items)) {
                response(false, null, "Indice invalido", 400);
            }

            $items[$index]['quantity'] = $quantity;

            $stmt = $db->prepare("UPDATE om_callcenter_order_drafts SET items = ?::jsonb, updated_at = NOW() WHERE id = ?");
            $stmt->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $draftId]);

            recalculateDraft($db, $draftId);
            $updatedDraft = fetchDraft($db, $draftId);

            response(true, ['draft' => $updatedDraft], "Quantidade atualizada");
        }

        // ── Set customer ──
        if ($action === 'set_customer') {
            $draftId = (int)($input['draft_id'] ?? 0);
            if (!$draftId) response(false, null, "draft_id obrigatorio", 400);

            $stmt = $db->prepare("SELECT id, status FROM om_callcenter_order_drafts WHERE id = ?");
            $stmt->execute([$draftId]);
            $draft = $stmt->fetch();
            if (!$draft) response(false, null, "Rascunho nao encontrado", 404);
            if (!in_array($draft['status'], ['building', 'review'])) {
                response(false, null, "Rascunho nao pode ser editado", 400);
            }

            $customerId = (int)($input['customer_id'] ?? 0);
            $customerName = strip_tags(trim($input['customer_name'] ?? ''));
            $customerPhone = strip_tags(trim($input['customer_phone'] ?? ''));
            $addressData = $input['address'] ?? null;
            $addressId = (int)($input['customer_address_id'] ?? 0);

            // If customer_id provided, validate and fetch info
            if ($customerId) {
                $stmt = $db->prepare("SELECT customer_id, name, phone FROM om_customers WHERE customer_id = ?");
                $stmt->execute([$customerId]);
                $customer = $stmt->fetch();
                if (!$customer) response(false, null, "Cliente nao encontrado", 404);
                if (!$customerName) $customerName = $customer['name'];
                if (!$customerPhone) $customerPhone = $customer['phone'];
            }

            // If address_id provided, fetch from DB
            $addressJson = null;
            if ($addressId && $customerId) {
                $stmt = $db->prepare("
                    SELECT address_id, label, street, number, complement, neighborhood,
                           city, state, zipcode, lat, lng
                    FROM om_customer_addresses
                    WHERE address_id = ? AND customer_id = ? AND is_active = '1'
                ");
                $stmt->execute([$addressId, $customerId]);
                $addr = $stmt->fetch();
                if ($addr) {
                    $addressJson = json_encode([
                        'address_id' => (int)$addr['address_id'],
                        'label' => $addr['label'],
                        'street' => $addr['street'],
                        'number' => $addr['number'],
                        'complement' => $addr['complement'],
                        'neighborhood' => $addr['neighborhood'],
                        'city' => $addr['city'],
                        'state' => $addr['state'],
                        'zipcode' => $addr['zipcode'],
                        'lat' => $addr['lat'],
                        'lng' => $addr['lng'],
                        'full' => $addr['street'] . ', ' . $addr['number']
                            . ($addr['complement'] ? ' - ' . $addr['complement'] : '')
                            . ' - ' . $addr['neighborhood'] . ', ' . $addr['city'] . '/' . $addr['state'],
                    ], JSON_UNESCAPED_UNICODE);
                }
            } elseif ($addressData && is_array($addressData)) {
                // Manual address
                $addressJson = json_encode($addressData, JSON_UNESCAPED_UNICODE);
            }

            $stmt = $db->prepare("
                UPDATE om_callcenter_order_drafts
                SET customer_id = ?, customer_name = ?, customer_phone = ?,
                    customer_address_id = ?, address = ?::jsonb, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $customerId ?: null, $customerName, $customerPhone,
                $addressId ?: null, $addressJson, $draftId
            ]);

            $updatedDraft = fetchDraft($db, $draftId);
            response(true, ['draft' => $updatedDraft], "Cliente definido");
        }

        // ── Set payment ──
        if ($action === 'set_payment') {
            $draftId = (int)($input['draft_id'] ?? 0);
            if (!$draftId) response(false, null, "draft_id obrigatorio", 400);

            $stmt = $db->prepare("SELECT id, status FROM om_callcenter_order_drafts WHERE id = ?");
            $stmt->execute([$draftId]);
            $draft = $stmt->fetch();
            if (!$draft) response(false, null, "Rascunho nao encontrado", 404);

            $paymentMethod = strip_tags(trim($input['payment_method'] ?? ''));
            $paymentChange = isset($input['payment_change']) ? round((float)$input['payment_change'], 2) : null;

            $validMethods = ['pix', 'credit_card', 'debit_card', 'dinheiro', 'maquininha', 'pay_on_delivery', 'stripe'];
            if (!in_array($paymentMethod, $validMethods, true)) {
                response(false, null, "Metodo de pagamento invalido. Valores: " . implode(', ', $validMethods), 400);
            }

            $stmt = $db->prepare("
                UPDATE om_callcenter_order_drafts
                SET payment_method = ?, payment_change = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$paymentMethod, $paymentChange, $draftId]);

            $updatedDraft = fetchDraft($db, $draftId);
            response(true, ['draft' => $updatedDraft], "Pagamento definido");
        }

        // ── Apply coupon ──
        if ($action === 'apply_coupon') {
            $draftId = (int)($input['draft_id'] ?? 0);
            $couponCode = strtoupper(trim(substr($input['coupon_code'] ?? '', 0, 50)));

            if (!$draftId) response(false, null, "draft_id obrigatorio", 400);
            if (!$couponCode) response(false, null, "coupon_code obrigatorio", 400);

            $stmt = $db->prepare("SELECT * FROM om_callcenter_order_drafts WHERE id = ?");
            $stmt->execute([$draftId]);
            $draft = $stmt->fetch();
            if (!$draft) response(false, null, "Rascunho nao encontrado", 404);

            // Lookup coupon
            $stmt = $db->prepare("
                SELECT * FROM om_market_coupons
                WHERE code = ? AND status = 'active'
            ");
            $stmt->execute([$couponCode]);
            $coupon = $stmt->fetch();
            if (!$coupon) response(false, null, "Cupom invalido ou expirado", 400);

            // Check dates
            $now = date('Y-m-d H:i:s');
            if (!empty($coupon['valid_from']) && $now < $coupon['valid_from']) {
                response(false, null, "Cupom ainda nao esta ativo", 400);
            }
            if (!empty($coupon['valid_until']) && $now > $coupon['valid_until']) {
                response(false, null, "Cupom expirado", 400);
            }

            // Check max uses
            if (!empty($coupon['max_uses']) && (int)$coupon['max_uses'] > 0) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = ?");
                $stmt->execute([$coupon['id']]);
                $totalUses = (int)$stmt->fetchColumn();
                if ($totalUses >= (int)$coupon['max_uses']) {
                    response(false, null, "Cupom esgotado", 400);
                }
            }

            // Check per-user usage
            if ($draft['customer_id'] && !empty($coupon['max_uses_per_user']) && (int)$coupon['max_uses_per_user'] > 0) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ?");
                $stmt->execute([$coupon['id'], $draft['customer_id']]);
                $userUses = (int)$stmt->fetchColumn();
                if ($userUses >= (int)$coupon['max_uses_per_user']) {
                    response(false, null, "Cliente ja usou este cupom o maximo de vezes", 400);
                }
            }

            // Check min order value
            $subtotal = (float)$draft['subtotal'];
            if (!empty($coupon['min_order_value']) && $subtotal < (float)$coupon['min_order_value']) {
                $minVal = number_format((float)$coupon['min_order_value'], 2, ',', '.');
                response(false, null, "Pedido minimo para este cupom: R$ {$minVal}", 400);
            }

            // Check partner restriction
            if (!empty($coupon['specific_partners']) && $draft['partner_id']) {
                $partners = json_decode($coupon['specific_partners'], true);
                if (is_array($partners) && !empty($partners) && !in_array((int)$draft['partner_id'], $partners)) {
                    response(false, null, "Cupom nao valido para esta loja", 400);
                }
            }

            // Calculate discount
            $discountType = $coupon['discount_type'] ?? 'percentage';
            $discountValue = (float)($coupon['discount_value'] ?? 0);
            $maxDiscount = !empty($coupon['max_discount']) ? (float)$coupon['max_discount'] : null;
            $discount = 0;

            if ($discountType === 'percentage') {
                $discount = round($subtotal * ($discountValue / 100), 2);
                if ($maxDiscount && $discount > $maxDiscount) {
                    $discount = $maxDiscount;
                }
            } elseif ($discountType === 'fixed') {
                $discount = min($discountValue, $subtotal);
            } elseif ($discountType === 'free_delivery') {
                // Zero the delivery fee
                $discount = (float)$draft['delivery_fee'];
            }

            $stmt = $db->prepare("
                UPDATE om_callcenter_order_drafts
                SET coupon_code = ?, discount = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$couponCode, $discount, $draftId]);

            recalculateDraft($db, $draftId);
            $updatedDraft = fetchDraft($db, $draftId);

            response(true, [
                'draft' => $updatedDraft,
                'coupon' => [
                    'code' => $couponCode,
                    'type' => $discountType,
                    'discount' => $discount,
                ],
            ], "Cupom aplicado: desconto de R$ " . number_format($discount, 2, ',', '.'));
        }

        // ── Submit draft as real order ──
        if ($action === 'submit') {
            $draftId = (int)($input['draft_id'] ?? 0);
            if (!$draftId) response(false, null, "draft_id obrigatorio", 400);

            $stmt = $db->prepare("SELECT * FROM om_callcenter_order_drafts WHERE id = ? FOR UPDATE");

            $db->beginTransaction();

            try {
                $stmt->execute([$draftId]);
                $draft = $stmt->fetch();

                if (!$draft) {
                    $db->rollBack();
                    response(false, null, "Rascunho nao encontrado", 404);
                }
                if ($draft['status'] !== 'building' && $draft['status'] !== 'review') {
                    $db->rollBack();
                    response(false, null, "Rascunho nao pode ser submetido (status: {$draft['status']})", 400);
                }

                // Validate required fields
                if (!$draft['customer_id'] && !$draft['customer_name']) {
                    $db->rollBack();
                    response(false, null, "Cliente obrigatorio. Use set_customer antes de submeter.", 400);
                }
                if (!$draft['partner_id']) {
                    $db->rollBack();
                    response(false, null, "Loja obrigatoria", 400);
                }

                $items = json_decode($draft['items'], true) ?: [];
                if (empty($items)) {
                    $db->rollBack();
                    response(false, null, "Pedido sem itens", 400);
                }

                $address = $draft['address'] ? json_decode($draft['address'], true) : null;
                if (!$address) {
                    $db->rollBack();
                    response(false, null, "Endereco de entrega obrigatorio. Use set_customer com address.", 400);
                }

                if (!$draft['payment_method']) {
                    $db->rollBack();
                    response(false, null, "Metodo de pagamento obrigatorio. Use set_payment.", 400);
                }

                // Recalculate one final time
                $subtotal = 0;
                foreach ($items as $item) {
                    $itemTotal = ((float)($item['price'] ?? 0)) * ((int)($item['quantity'] ?? 1));
                    foreach (($item['options'] ?? []) as $opt) {
                        $itemTotal += ((float)($opt['price'] ?? 0)) * ((int)($item['quantity'] ?? 1));
                    }
                    $subtotal += $itemTotal;
                }
                $serviceFee = round($subtotal * 0.08, 2);
                $deliveryFee = (float)($draft['delivery_fee'] ?? 0);
                $tip = (float)($draft['tip'] ?? 0);
                $discount = (float)($draft['discount'] ?? 0);
                $total = round($subtotal + $deliveryFee + $serviceFee + $tip - $discount, 2);

                // Build delivery address string
                $deliveryAddress = $address['full'] ?? (
                    ($address['street'] ?? '') . ', ' . ($address['number'] ?? '')
                    . (($address['complement'] ?? '') ? ' - ' . $address['complement'] : '')
                    . ' - ' . ($address['neighborhood'] ?? '')
                    . ', ' . ($address['city'] ?? '') . '/' . ($address['state'] ?? '')
                );

                // Determine customer info
                $customerId = $draft['customer_id'] ? (int)$draft['customer_id'] : null;
                $customerName = $draft['customer_name'] ?? '';
                $customerPhone = $draft['customer_phone'] ?? '';

                // If customer_id, get canonical name/phone
                if ($customerId) {
                    $stmtC = $db->prepare("SELECT name, phone, email FROM om_customers WHERE customer_id = ?");
                    $stmtC->execute([$customerId]);
                    $customerData = $stmtC->fetch();
                    if ($customerData) {
                        $customerName = $customerData['name'];
                        $customerPhone = $customerData['phone'];
                    }
                }

                $codigoEntrega = strtoupper(bin2hex(random_bytes(3)));

                // Determine initial status
                $initialStatus = 'pendente';
                $paymentStatus = 'pendente';
                if (in_array($draft['payment_method'], ['pay_on_delivery', 'dinheiro', 'maquininha'])) {
                    $initialStatus = 'confirmado';
                    $paymentStatus = 'pendente_entrega';
                }

                // Look up coupon_id if coupon was applied
                $couponId = null;
                if (!empty($draft['coupon_code'])) {
                    $stmtCouponLookup = $db->prepare("SELECT id FROM om_market_coupons WHERE code = ? LIMIT 1");
                    $stmtCouponLookup->execute([$draft['coupon_code']]);
                    $couponLookup = $stmtCouponLookup->fetch();
                    if ($couponLookup) {
                        $couponId = (int)$couponLookup['id'];
                    }
                }

                // Build order notes
                $orderNotes = $draft['notes'] ?? '';
                if (!$orderNotes) {
                    $orderNotes = "Pedido via call center - agente #{$draft['agent_id']}";
                    if ($draft['coupon_code']) {
                        $orderNotes .= " - cupom: {$draft['coupon_code']}";
                    }
                }

                // Create the order
                $stmt = $db->prepare("
                    INSERT INTO om_market_orders (
                        customer_id, partner_id,
                        customer_name, customer_phone,
                        status, subtotal, delivery_fee, service_fee, tip_amount, total,
                        delivery_address,
                        shipping_address, shipping_city, shipping_state, shipping_cep,
                        shipping_lat, shipping_lng,
                        discount, coupon_id, coupon_discount, notes,
                        forma_pagamento, payment_status, codigo_entrega,
                        source,
                        date_added
                    ) VALUES (
                        ?, ?,
                        ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?,
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        'callcenter',
                        NOW()
                    )
                    RETURNING order_id
                ");
                $stmt->execute([
                    $customerId, (int)$draft['partner_id'],
                    $customerName, $customerPhone,
                    $initialStatus, $subtotal, $deliveryFee, $serviceFee, $tip, $total,
                    $deliveryAddress,
                    $address['street'] ?? '', $address['city'] ?? '', $address['state'] ?? '', $address['zipcode'] ?? '',
                    $address['lat'] ?? null, $address['lng'] ?? null,
                    $discount, $couponId, $discount > 0 ? $discount : null, $orderNotes,
                    $draft['payment_method'], $paymentStatus, $codigoEntrega,
                ]);
                $row = $stmt->fetch();
                $orderId = (int)$row['order_id'];

                // Generate order number
                $orderNumber = 'SB' . str_pad($orderId, 5, '0', STR_PAD_LEFT);
                $db->prepare("UPDATE om_market_orders SET order_number = ? WHERE order_id = ?")->execute([$orderNumber, $orderId]);

                // Insert order items
                $stmtItem = $db->prepare("
                    INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($items as $item) {
                    $itemPrice = (float)($item['price'] ?? 0);
                    $itemQty = (int)($item['quantity'] ?? 1);
                    $itemOptionsTotal = 0;
                    $optionNotes = [];
                    foreach (($item['options'] ?? []) as $opt) {
                        $itemOptionsTotal += (float)($opt['price'] ?? 0);
                        $optionNotes[] = $opt['name'] . ((float)($opt['price'] ?? 0) > 0 ? ' (+R$ ' . number_format((float)$opt['price'], 2, ',', '.') . ')' : '');
                    }
                    $itemTotal = round(($itemPrice + $itemOptionsTotal) * $itemQty, 2);

                    $notesText = !empty($optionNotes) ? implode('; ', $optionNotes) : null;
                    if (!empty($item['notes'])) {
                        $notesText = $notesText ? $notesText . ' | ' . $item['notes'] : $item['notes'];
                    }

                    $stmtItem->execute([
                        $orderId,
                        $item['product_id'] ?? null,
                        $item['name'],
                        $itemQty,
                        $itemPrice,
                        $itemTotal,
                        $notesText,
                    ]);
                }

                // Record coupon usage
                if ($couponId && $customerId) {
                    $db->prepare("
                        INSERT INTO om_market_coupon_usage (coupon_id, customer_id, order_id, discount_applied, used_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ")->execute([$couponId, $customerId, $orderId, $discount]);
                }

                // Timeline entry
                $db->prepare("
                    INSERT INTO om_order_timeline (order_id, status, description, actor_type, actor_id, created_at)
                    VALUES (?, ?, ?, 'admin', ?, NOW())
                ")->execute([
                    $orderId, $initialStatus,
                    "Pedido criado via call center pelo agente #{$draft['agent_id']}",
                    $admin_id
                ]);

                // Update draft status
                $db->prepare("
                    UPDATE om_callcenter_order_drafts
                    SET status = 'submitted', submitted_order_id = ?, subtotal = ?, service_fee = ?, total = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$orderId, $subtotal, $serviceFee, $total, $draftId]);

                $db->commit();

                // Audit
                om_audit()->log(
                    'callcenter_order_submit',
                    'order',
                    $orderId,
                    null,
                    [
                        'draft_id' => $draftId,
                        'agent_id' => $draft['agent_id'],
                        'customer_id' => $customerId,
                        'partner_id' => (int)$draft['partner_id'],
                        'total' => $total,
                        'items_count' => count($items),
                    ],
                    "Pedido {$orderNumber} criado via call center (draft #{$draftId})"
                );

                // Try to notify customer
                try {
                    require_once __DIR__ . '/../../helpers/notify.php';
                    if ($customerId) {
                        notifyCustomer(
                            $db,
                            $customerId,
                            "Novo pedido {$orderNumber}",
                            "Seu pedido foi criado pela central de atendimento SuperBora. Total: R$ " . number_format($total, 2, ',', '.'),
                            '/pedidos',
                            ['order_id' => $orderId, 'type' => 'order_created']
                        );
                    }
                } catch (Exception $e) {
                    error_log("[callcenter/draft submit] Notify error: " . $e->getMessage());
                }

                response(true, [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'status' => $initialStatus,
                    'subtotal' => $subtotal,
                    'delivery_fee' => $deliveryFee,
                    'service_fee' => $serviceFee,
                    'tip' => $tip,
                    'discount' => $discount,
                    'total' => $total,
                    'items_count' => count($items),
                    'draft_id' => $draftId,
                ], "Pedido {$orderNumber} criado com sucesso");

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }

        // ── Send SMS summary ──
        if ($action === 'send_sms_summary') {
            $draftId = (int)($input['draft_id'] ?? 0);
            if (!$draftId) response(false, null, "draft_id obrigatorio", 400);

            $stmt = $db->prepare("SELECT * FROM om_callcenter_order_drafts WHERE id = ?");
            $stmt->execute([$draftId]);
            $draft = $stmt->fetch();
            if (!$draft) response(false, null, "Rascunho nao encontrado", 404);

            $phone = $draft['customer_phone'] ?? '';
            if (!$phone) response(false, null, "Telefone do cliente nao definido", 400);

            require_once __DIR__ . '/../../helpers/twilio-sms.php';

            // Build SMS body
            $items = json_decode($draft['items'], true) ?: [];
            $smsLines = ["SuperBora - Resumo do Pedido"];
            $smsLines[] = "";

            if ($draft['partner_name']) {
                $smsLines[] = "Loja: {$draft['partner_name']}";
            }

            $smsLines[] = "---";
            foreach ($items as $idx => $item) {
                $qty = (int)($item['quantity'] ?? 1);
                $price = (float)($item['price'] ?? 0);
                $line = "{$qty}x {$item['name']} - R$ " . number_format($price * $qty, 2, ',', '.');
                $smsLines[] = $line;
                // Item options
                foreach (($item['options'] ?? []) as $opt) {
                    if (!empty($opt['name'])) {
                        $smsLines[] = "  + {$opt['name']}" . ((float)($opt['price'] ?? 0) > 0 ? " R$ " . number_format((float)$opt['price'], 2, ',', '.') : '');
                    }
                }
            }
            $smsLines[] = "---";
            $smsLines[] = "Subtotal: R$ " . number_format((float)$draft['subtotal'], 2, ',', '.');
            if ((float)$draft['delivery_fee'] > 0) {
                $smsLines[] = "Entrega: R$ " . number_format((float)$draft['delivery_fee'], 2, ',', '.');
            }
            if ((float)$draft['service_fee'] > 0) {
                $smsLines[] = "Taxa: R$ " . number_format((float)$draft['service_fee'], 2, ',', '.');
            }
            if ((float)$draft['discount'] > 0) {
                $smsLines[] = "Desconto: -R$ " . number_format((float)$draft['discount'], 2, ',', '.');
            }
            $smsLines[] = "TOTAL: R$ " . number_format((float)$draft['total'], 2, ',', '.');

            // Tracking link if order submitted
            if ($draft['submitted_order_id']) {
                $smsLines[] = "";
                $smsLines[] = "Acompanhe: https://superbora.com.br/tracking/{$draft['submitted_order_id']}";
            }

            $smsBody = implode("\n", $smsLines);
            $smsResult = sendSMS($phone, $smsBody);

            if ($smsResult['success']) {
                $db->prepare("
                    UPDATE om_callcenter_order_drafts
                    SET sms_sent = TRUE, sms_sent_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$draftId]);
            }

            response($smsResult['success'], [
                'sms_sid' => $smsResult['sid'],
                'phone' => $phone,
            ], $smsResult['message']);
        }

        // ── Set tip ──
        if ($action === 'set_tip') {
            $draftId = (int)($input['draft_id'] ?? 0);
            if (!$draftId) response(false, null, "draft_id obrigatorio", 400);

            $stmt = $db->prepare("SELECT id, status FROM om_callcenter_order_drafts WHERE id = ?");
            $stmt->execute([$draftId]);
            $draft = $stmt->fetch();
            if (!$draft) response(false, null, "Rascunho nao encontrado", 404);

            $tip = max(0, round((float)($input['tip'] ?? 0), 2));

            $stmt = $db->prepare("UPDATE om_callcenter_order_drafts SET tip = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$tip, $draftId]);

            recalculateDraft($db, $draftId);
            $updatedDraft = fetchDraft($db, $draftId);

            response(true, ['draft' => $updatedDraft], "Gorjeta definida");
        }

        // ── Cancel draft ──
        if ($action === 'cancel') {
            $draftId = (int)($input['draft_id'] ?? 0);
            if (!$draftId) response(false, null, "draft_id obrigatorio", 400);

            $stmt = $db->prepare("SELECT id, status FROM om_callcenter_order_drafts WHERE id = ?");
            $stmt->execute([$draftId]);
            $draft = $stmt->fetch();
            if (!$draft) response(false, null, "Rascunho nao encontrado", 404);
            if ($draft['status'] === 'submitted') {
                response(false, null, "Rascunho ja foi submetido como pedido — cancele o pedido diretamente", 400);
            }
            if ($draft['status'] === 'cancelled') {
                response(false, null, "Rascunho ja esta cancelado", 400);
            }

            $db->prepare("
                UPDATE om_callcenter_order_drafts
                SET status = 'cancelled', updated_at = NOW()
                WHERE id = ?
            ")->execute([$draftId]);

            response(true, ['draft_id' => $draftId, 'status' => 'cancelled'], "Rascunho cancelado");
        }

        response(false, null, "Acao invalida. Valores: create, add_item, remove_item, update_item, set_customer, set_payment, apply_coupon, submit, send_sms_summary, cancel", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/callcenter/draft] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
