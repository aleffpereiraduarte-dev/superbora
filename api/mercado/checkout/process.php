<?php
/**
 * POST /api/mercado/checkout/process.php
 * Checkout React - cria pedido com items inline
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";
require_once __DIR__ . "/../helpers/notify.php";
setCorsHeaders();

// CSRF token validation for checkout
function validateCsrfToken(): bool {
    $tokenFromHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $tokenFromBody = null;

    // Also check in request body for POST requests
    $input = json_decode(file_get_contents('php://input'), true);
    if (is_array($input)) {
        $tokenFromBody = $input['csrf_token'] ?? null;
    }

    $token = $tokenFromHeader ?: $tokenFromBody;
    if (empty($token)) {
        return false;
    }

    // Get session token - check both session and cookie
    session_start();
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    // Also accept token from secure cookie as fallback for SPA
    if (empty($sessionToken)) {
        $sessionToken = $_COOKIE['csrf_token'] ?? '';
    }

    if (empty($sessionToken)) {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

try {
    // CSRF validation - skip for API key authentication
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $isApiKeyAuth = !empty($_SERVER['HTTP_X_API_KEY']);

    if (!$isApiKeyAuth && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCsrfToken()) {
            response(false, null, "Token de seguranca invalido. Recarregue a pagina e tente novamente.", 403);
        }
    }
    $input = getInput();
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    // Buscar dados do cliente
    $stmtCust = $db->prepare("SELECT name, email, phone, cpf FROM om_customers WHERE customer_id = ?");
    $stmtCust->execute([$customerId]);
    $customer = $stmtCust->fetch();
    if (!$customer) response(false, null, "Cliente nao encontrado", 404);

    // Input
    $addressId = (int)($input['address_id'] ?? 0);
    $paymentMethod = preg_replace('/[^a-z_]/', '', $input['payment_method'] ?? 'pix');
    $items = $input['items'] ?? [];
    $partnerId = (int)($input['partner_id'] ?? 0);
    $tip = max(0, (float)($input['tip'] ?? 0));
    $couponCode = strtoupper(trim($input['coupon_code'] ?? ''));
    $notes = trim(substr($input['notes'] ?? '', 0, 1000));
    $deliveryInstructions = trim(substr($input['delivery_instructions'] ?? '', 0, 500));
    $isPickup = (bool)($input['is_pickup'] ?? false);

    // Feature: Entrega sem contato
    $contactless = (bool)($input['contactless'] ?? false);

    // Feature 2: Agendamento
    $scheduleDate = trim($input['schedule_date'] ?? '');
    $scheduleTime = trim($input['schedule_time'] ?? '');
    $isScheduled = !empty($scheduleDate) && !empty($scheduleTime);

    // Feature 3: Cartao salvo
    $savedCardId = (int)($input['saved_card_id'] ?? 0);

    // Feature 6: Usar pontos de fidelidade
    $usePoints = (int)($input['use_points'] ?? 0);

    if (empty($items)) response(false, null, "Carrinho vazio", 400);
    if (!$partnerId) response(false, null, "Parceiro nao informado", 400);
    if (!in_array($paymentMethod, ['pix', 'credito', 'debito', 'dinheiro'])) {
        response(false, null, "Forma de pagamento invalida", 400);
    }

    // Feature 3: Se usando cartao salvo, buscar dados
    if ($savedCardId && $paymentMethod === 'credito') {
        $stmtCard = $db->prepare("SELECT * FROM om_market_saved_cards WHERE id = ? AND customer_id = ?");
        $stmtCard->execute([$savedCardId, $customerId]);
        $savedCard = $stmtCard->fetch();
        if (!$savedCard) response(false, null, "Cartao salvo nao encontrado", 404);
        // card_token seria usado com o gateway de pagamento
    }

    // Endereco
    $address = '';
    $shippingData = ['cep' => '', 'city' => '', 'state' => '', 'neighborhood' => '', 'street' => '', 'number' => '', 'lat' => null, 'lng' => null];

    if (!$isPickup) {
        if (!$addressId) response(false, null, "Endereco de entrega obrigatorio", 400);
        $stmtAddr = $db->prepare("SELECT * FROM om_customer_addresses WHERE address_id = ? AND customer_id = ?");
        $stmtAddr->execute([$addressId, $customerId]);
        $addr = $stmtAddr->fetch();
        if (!$addr) response(false, null, "Endereco nao encontrado", 404);

        $address = trim($addr['street'] . ', ' . $addr['number']
            . ($addr['complement'] ? ' - ' . $addr['complement'] : '')
            . ' - ' . $addr['neighborhood']
            . ', ' . $addr['city'] . '/' . $addr['state']);
        $shippingData = [
            'cep' => $addr['zipcode'], 'city' => $addr['city'], 'state' => $addr['state'],
            'neighborhood' => $addr['neighborhood'], 'street' => $addr['street'],
            'number' => $addr['number'], 'lat' => $addr['lat'], 'lng' => $addr['lng']
        ];
    }

    // Parceiro
    // Fetch partner with auto_accept field
    $stmtP = $db->prepare("SELECT * FROM om_market_partners WHERE partner_id = ? AND status = '1'");
    $stmtP->execute([$partnerId]);
    $parceiro = $stmtP->fetch();
    if (!$parceiro) response(false, null, "Estabelecimento nao disponivel", 400);

    // ── Carregar plano de comissao do parceiro ──────────────────────────
    $partnerPlan = null;
    if (!empty($parceiro['plan_id'])) {
        $stmtPlan = $db->prepare("SELECT slug, commission_rate, commission_online_rate, uses_platform_delivery, delivery_commission FROM om_partner_plans WHERE id = ? AND status::text = '1'");
        $stmtPlan->execute([$parceiro['plan_id']]);
        $partnerPlan = $stmtPlan->fetch();
    }
    if (!$partnerPlan) {
        // Fallback: plano basico (10% entrega propria)
        $partnerPlan = ['slug' => 'basico', 'commission_rate' => '10.00', 'commission_online_rate' => '10.00', 'uses_platform_delivery' => 0, 'delivery_commission' => '0.00'];
    }

    // ── Verificar se a loja esta aberta ────────────────────────────────
    // Skip store-hours check for scheduled orders (they will be fulfilled later)
    if (!$isScheduled) {
        // 1) Flag manual: aberto = 0 means always closed
        if (isset($parceiro['aberto']) && !$parceiro['aberto']) {
            response(false, null, "Loja fechada no momento. O estabelecimento nao esta aceitando pedidos.", 400);
        }

        // 2) Check horario_funcionamento schedule
        $horario = $parceiro['horario_funcionamento'] ?? null;
        if (!empty($horario)) {
            $spTz = new DateTimeZone('America/Sao_Paulo');
            $now = new DateTime('now', $spTz);
            $currentTime = $now->format('H:i');

            // Map PHP day-of-week to Portuguese abbreviations
            $dayMap = [
                1 => 'seg', // Monday
                2 => 'ter',
                3 => 'qua',
                4 => 'qui',
                5 => 'sex',
                6 => 'sab',
                0 => 'dom', // Sunday
            ];
            $dayOfWeek = (int)$now->format('w'); // 0=Sun .. 6=Sat
            $dayKey = $dayMap[$dayOfWeek] ?? '';

            $todayRange = null;

            // Try parsing as JSON object: {"seg":"08:00-22:00","ter":"08:00-22:00",...}
            $horarioData = is_string($horario) ? json_decode($horario, true) : (is_array($horario) ? $horario : null);

            if (is_array($horarioData)) {
                // JSON with per-day schedule
                if (isset($horarioData[$dayKey])) {
                    $todayRange = $horarioData[$dayKey];
                }
                // Also support "abertura"/"fechamento" format: {"abertura":"08:00","fechamento":"22:00"}
                elseif (isset($horarioData['abertura']) && isset($horarioData['fechamento'])) {
                    $todayRange = $horarioData['abertura'] . '-' . $horarioData['fechamento'];
                }
            } elseif (is_string($horario)) {
                // Simple string format: "08:00-22:00" or "08:00 - 22:00"
                $horario = trim($horario);
                if (preg_match('/^\d{2}:\d{2}\s*-\s*\d{2}:\d{2}$/', $horario)) {
                    $todayRange = $horario;
                }
            }

            if ($todayRange !== null) {
                $todayRange = trim($todayRange);

                // "fechado" or empty means closed today
                if (strtolower($todayRange) === 'fechado' || empty($todayRange)) {
                    response(false, null, "Loja fechada hoje.", 400);
                }

                // Parse "HH:MM-HH:MM" or "HH:MM - HH:MM"
                if (preg_match('/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', $todayRange, $m)) {
                    $openTime = $m[1];
                    $closeTime = $m[2];

                    $isOpen = false;
                    if ($closeTime > $openTime) {
                        // Normal range: e.g. 08:00-22:00
                        $isOpen = ($currentTime >= $openTime && $currentTime < $closeTime);
                    } else {
                        // Overnight range: e.g. 18:00-02:00
                        $isOpen = ($currentTime >= $openTime || $currentTime < $closeTime);
                    }

                    if (!$isOpen) {
                        response(false, null, "Loja fechada no momento. Horario: {$openTime} - {$closeTime}", 400);
                    }
                }
            }
            // If todayRange is null (day not in schedule), we allow the order through
            // (benefit of the doubt - partner may not have configured that day)
        }
    }

    // Validar items — constrain to the requested partner to prevent cross-store manipulation
    $productIds = array_map(fn($i) => (int)$i['id'], $items);
    $ph = implode(',', array_fill(0, count($productIds), '?'));
    $stmtProd = $db->prepare("SELECT product_id, name, price, special_price, quantity as estoque, image FROM om_market_products WHERE product_id IN ($ph) AND partner_id = ?");
    $stmtProd->execute(array_merge($productIds, [$partnerId]));
    $productsMap = [];
    foreach ($stmtProd->fetchAll() as $p) $productsMap[$p['product_id']] = $p;

    // Verify all requested products belong to this partner
    $missingProducts = array_diff($productIds, array_keys($productsMap));
    if (!empty($missingProducts)) {
        response(false, null, "Um ou mais produtos nao pertencem a este estabelecimento", 400);
    }

    $subtotal = 0;
    $orderItems = [];
    $totalItemsCount = 0;
    foreach ($items as $item) {
        $pid = (int)$item['id'];
        $qty = max(1, (int)($item['quantity'] ?? 1));
        $itemNotes = trim(substr($item['notes'] ?? '', 0, 500));
        $prod = $productsMap[$pid] ?? null;
        if (!$prod) continue;
        if ($qty > $prod['estoque']) response(false, null, "'{$prod['name']}' sem estoque suficiente", 400);

        $preco = ($prod['special_price'] && (float)$prod['special_price'] > 0 && (float)$prod['special_price'] < (float)$prod['price'])
            ? (float)$prod['special_price'] : (float)$prod['price'];
        $itemTotal = $preco * $qty;
        $subtotal += $itemTotal;
        $totalItemsCount += $qty;
        $orderItems[] = ['product_id' => $pid, 'name' => $prod['name'], 'quantity' => $qty, 'price' => $preco, 'total' => $itemTotal, 'image' => $prod['image'], 'notes' => $itemNotes];
    }

    // Delivery fee
    $deliveryFee = $isPickup ? 0 : (float)($parceiro['delivery_fee'] ?? 5.99);
    $freeAbove = (float)($parceiro['free_delivery_above'] ?? 99);
    if (!$isPickup && $subtotal >= $freeAbove) $deliveryFee = 0;

    // Cupom
    $couponId = 0;
    $couponDiscount = 0;
    if (!empty($couponCode)) {
        $stmtC = $db->prepare("SELECT * FROM om_market_coupons WHERE code = ? AND status = 'active'");
        $stmtC->execute([$couponCode]);
        $coupon = $stmtC->fetch();
        if ($coupon) {
            // Validate date window
            $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
            if (!empty($coupon['valid_from']) && $now < new DateTime($coupon['valid_from'])) {
                $coupon = null; // Not yet valid
            }
            if ($coupon && !empty($coupon['valid_until']) && $now > new DateTime($coupon['valid_until'])) {
                $coupon = null; // Expired
            }
            // Validate global usage limit
            if ($coupon && !empty($coupon['max_uses']) && (int)$coupon['current_uses'] >= (int)$coupon['max_uses']) {
                $coupon = null; // Max uses reached
            }
            // Validate per-user usage limit
            if ($coupon && !empty($coupon['max_uses_per_user'])) {
                $stmtUsage = $db->prepare("SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ?");
                $stmtUsage->execute([(int)$coupon['id'], $customerId]);
                if ($stmtUsage->fetchColumn() >= (int)$coupon['max_uses_per_user']) {
                    $coupon = null; // Per-user limit reached
                }
            }
            // Validate first_order_only
            if ($coupon && !empty($coupon['first_order_only'])) {
                $stmtOrders = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND status NOT IN ('cancelado','cancelled')");
                $stmtOrders->execute([$customerId]);
                if ($stmtOrders->fetchColumn() > 0) {
                    $coupon = null; // Not first order
                }
            }
            // Validate min_order_value
            if ($coupon && !empty($coupon['min_order_value']) && $subtotal < (float)$coupon['min_order_value']) {
                $coupon = null; // Below minimum
            }
        }
        if ($coupon) {
            $couponId = (int)$coupon['id'];
            switch ($coupon['discount_type']) {
                case 'percentage':
                    $couponDiscount = round($subtotal * (float)$coupon['discount_value'] / 100, 2);
                    if ($coupon['max_discount'] && $couponDiscount > (float)$coupon['max_discount']) $couponDiscount = (float)$coupon['max_discount'];
                    break;
                case 'fixed':
                    $couponDiscount = min((float)$coupon['discount_value'], $subtotal);
                    break;
                case 'free_delivery':
                    $deliveryFee = 0;
                    break;
            }
        }
    }

    // Pedido minimo
    $minOrder = (float)($parceiro['min_order_value'] ?? 0);
    if ($subtotal < $minOrder) response(false, null, "Pedido minimo: R$ " . number_format($minOrder, 2, ',', '.'), 400);

    $serviceFee = 2.49;

    // ── Calcular comissao usando OmPricing (fonte unica de verdade) ─────
    $usaBoraUm = !$isPickup && !($parceiro['entrega_propria'] ?? false);
    $tipoEntregaComissao = $usaBoraUm ? 'boraum' : 'proprio';

    // Se o parceiro tem commission_rate override do admin, usar esse
    $partnerOverrideRate = (float)($parceiro['commission_rate'] ?? 0);
    if ($partnerOverrideRate > 0) {
        $commissionRate = $partnerOverrideRate;
        $commissionAmount = round($subtotal * $commissionRate / 100, 2);
    } else {
        $comissaoCalc = OmPricing::calcularComissao($subtotal, $tipoEntregaComissao);
        $commissionRate = $comissaoCalc['taxa'] * 100; // 10 ou 18
        $commissionAmount = $comissaoCalc['valor'];
    }
    $platformFee = round($serviceFee + $commissionAmount, 2);

    // SECURITY: Validate price calculations are within acceptable bounds
    if ($subtotal < 0 || $subtotal > 100000) {
        response(false, null, "Valor do subtotal invalido", 400);
    }
    if ($couponDiscount < 0 || $couponDiscount > $subtotal) {
        response(false, null, "Valor de desconto invalido", 400);
    }
    if ($deliveryFee < 0 || $deliveryFee > 1000) {
        response(false, null, "Taxa de entrega invalida", 400);
    }
    if ($tip < 0 || $tip > 1000) {
        response(false, null, "Valor de gorjeta invalido", 400);
    }

    // Feature 6: Desconto por pontos de fidelidade
    $pointsDiscount = 0;
    if ($usePoints > 0) {
        $stmtPts = $db->prepare("SELECT current_points FROM om_market_loyalty_points WHERE customer_id = ?");
        $stmtPts->execute([$customerId]);
        $ptsRow = $stmtPts->fetch();
        $availablePoints = $ptsRow ? (int)$ptsRow['current_points'] : 0;
        $usePoints = min($usePoints, $availablePoints);
        $pointsDiscount = round($usePoints * 0.01, 2); // 1 ponto = R$ 0.01
        $maxPointsDiscount = $subtotal * 0.5; // Maximo 50% do subtotal
        if ($pointsDiscount > $maxPointsDiscount) {
            $pointsDiscount = $maxPointsDiscount;
            $usePoints = (int)($pointsDiscount / 0.01);
        }
    }

    $total = $subtotal - $couponDiscount - $pointsDiscount + $deliveryFee + $serviceFee + $tip;
    if ($total < 0) $total = 0;

    $orderNumber = 'SB-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $codigoEntrega = strtoupper(bin2hex(random_bytes(3)));

    // Map payment method to enum value
    $paymentEnum = match($paymentMethod) {
        'pix' => 'pix',
        'credito' => 'credit_card',
        'debito' => 'debit_card',
        'dinheiro' => 'cash',
        default => 'pix'
    };

    // Feature: Auto-accept orders - check if partner has auto_accept enabled
    // SECURITY: Only auto-accept for cash/PIX (credit/debit needs payment confirmation first)
    $autoAccept = (bool)($parceiro['auto_accept'] ?? false);
    if ($autoAccept && in_array($paymentMethod, ['credito', 'debito'])) {
        $autoAccept = false; // Wait for payment confirmation before accepting
    }
    $initialStatus = $autoAccept ? 'aceito' : 'pendente';

    $db->beginTransaction();
    try {
        // Re-validate loyalty points inside transaction with FOR UPDATE to prevent double-spend
        if ($usePoints > 0) {
            $stmtPtsLock = $db->prepare("SELECT current_points FROM om_market_loyalty_points WHERE customer_id = ? FOR UPDATE");
            $stmtPtsLock->execute([$customerId]);
            $ptsRowLocked = $stmtPtsLock->fetch();
            $lockedPoints = $ptsRowLocked ? (int)$ptsRowLocked['current_points'] : 0;
            $usePoints = min($usePoints, $lockedPoints);
            $pointsDiscount = round($usePoints * 0.01, 2);
            $maxPointsDiscount = $subtotal * 0.5;
            if ($pointsDiscount > $maxPointsDiscount) {
                $pointsDiscount = $maxPointsDiscount;
                $usePoints = (int)($pointsDiscount / 0.01);
            }
            // Recalculate total with locked values
            $total = $subtotal - $couponDiscount - $pointsDiscount + $deliveryFee + $serviceFee + $tip;
            if ($total < 0) $total = 0;
        }

        $stmt = $db->prepare("INSERT INTO om_market_orders (
            order_number, market_id, partner_id, customer_id,
            customer_name, customer_phone, customer_email, customer_document,
            status, payment_method, forma_pagamento, payment_status,
            subtotal, delivery_fee, service_fee, discount, total, tip_amount,
            delivery_address, shipping_address, shipping_neighborhood, shipping_city, shipping_state, shipping_cep,
            shipping_lat, shipping_lng,
            notes, delivery_instructions, contactless, codigo_entrega, delivery_code, verification_code,
            coupon_id, coupon_discount,
            is_pickup, partner_name, partner_categoria, items_count,
            is_scheduled, schedule_date, schedule_time,
            partner_plan_slug, commission_rate, commission_amount, platform_fee,
            date_added, created_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, 'pending',
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            NOW(), NOW()
        ) RETURNING order_id");

        $partnerDisplayName = $parceiro['trade_name'] ?: $parceiro['name'];

        $stmt->execute([
            $orderNumber, $partnerId, $partnerId, $customerId,
            $customer['name'], $customer['phone'], $customer['email'], $customer['cpf'],
            $initialStatus, $paymentEnum, $paymentMethod,
            $subtotal, $deliveryFee, $serviceFee, $couponDiscount, $total, $tip,
            $address, $address, $shippingData['neighborhood'], $shippingData['city'], $shippingData['state'], $shippingData['cep'],
            $shippingData['lat'], $shippingData['lng'],
            $notes, $deliveryInstructions ?: null, $contactless ? 1 : 0, $codigoEntrega, $codigoEntrega, $codigoEntrega,
            $couponId ?: null, $couponDiscount,
            $isPickup ? 1 : 0, $partnerDisplayName, $parceiro['categoria'] ?? 'mercado', $totalItemsCount,
            $isScheduled ? 1 : 0, $scheduleDate ?: null, $scheduleTime ?: null,
            $partnerPlan['slug'], $commissionRate, $commissionAmount, $platformFee,
        ]);

        $orderId = (int)$stmt->fetch()['order_id'];

        // Items
        $stmtItem = $db->prepare("INSERT INTO om_market_order_items (order_id, product_id, product_name, quantity, price, total, product_image, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($orderItems as $oi) {
            $stmtItem->execute([$orderId, $oi['product_id'], $oi['name'], $oi['quantity'], $oi['price'], $oi['total'], $oi['image'], $oi['notes'] ?: null]);
            $stmtStock = $db->prepare("UPDATE om_market_products SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?");
            $stmtStock->execute([$oi['quantity'], $oi['product_id'], $oi['quantity']]);
            if ($stmtStock->rowCount() === 0) {
                throw new \Exception("Estoque insuficiente para o produto: " . $oi['name']);
            }
        }

        // Cupom usage (with atomic increment to prevent race condition)
        if ($couponId) {
            $db->prepare("INSERT INTO om_market_coupon_usage (coupon_id, customer_id, order_id) VALUES (?, ?, ?)")->execute([$couponId, $customerId, $orderId]);
            $db->prepare("UPDATE om_market_coupons SET current_uses = current_uses + 1 WHERE id = ?")->execute([$couponId]);
        }

        // Feature 6: Pontos de fidelidade - gastar pontos
        if ($usePoints > 0) {
            $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points - ? WHERE customer_id = ?")
                ->execute([$usePoints, $customerId]);
            $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, type, points, description, created_at) VALUES (?, 'spent', ?, ?, NOW())")
                ->execute([$customerId, -$usePoints, "Desconto no pedido #$orderNumber"]);
        }

        // Feature 6: Pontos de fidelidade - ganhar pontos (1 ponto por real gasto)
        // SECURITY: Validate total before using for points calculation
        if ($total < 0) {
            throw new Exception("Total do pedido invalido");
        }
        $earnedPoints = (int)floor($total);
        if ($earnedPoints > 0) {
            $db->prepare("
                INSERT INTO om_market_loyalty_points (customer_id, current_points) VALUES (?, ?)
                ON CONFLICT (customer_id) DO UPDATE SET current_points = om_market_loyalty_points.current_points + EXCLUDED.current_points
            ")->execute([$customerId, $earnedPoints]);
            $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, type, points, description, created_at) VALUES (?, 'earned', ?, ?, NOW())")
                ->execute([$customerId, $earnedPoints, "Pedido #$orderNumber"]);
        }

        // ── First Order Welcome Coupon ──────────────────────────────────────
        // Check if this is the customer's first completed order
        $stmtOrderCount = $db->prepare("
            SELECT COUNT(*) as cnt FROM om_market_orders
            WHERE customer_id = ? AND status NOT IN ('cancelled', 'cancelado') AND order_id != ?
        ");
        $stmtOrderCount->execute([$customerId, $orderId]);
        $previousOrders = (int)$stmtOrderCount->fetch()['cnt'];

        if ($previousOrders === 0) {
            // This is the first order - create a welcome coupon for next order
            $welcomeCode = 'BEMVINDO' . $customerId;
            $welcomeExpires = date('Y-m-d H:i:s', strtotime('+30 days'));

            // Only create if coupon doesn't already exist
            $stmtWelcomeCheck = $db->prepare("SELECT id FROM om_market_coupons WHERE code = ?");
            $stmtWelcomeCheck->execute([$welcomeCode]);
            if (!$stmtWelcomeCheck->fetch()) {
                // Table om_market_coupons created via migration

                $stmtWelcomeCoupon = $db->prepare("
                    INSERT INTO om_market_coupons (code, discount_type, discount_value, max_uses, status, valid_until, created_at)
                    VALUES (?, 'fixed', 5.00, 1, 'active', ?, NOW())
                ");
                $stmtWelcomeCoupon->execute([$welcomeCode, $welcomeExpires]);
            }
        }

        // Limpar carrinho
        $db->prepare("DELETE FROM om_market_cart WHERE customer_id = ?")->execute([$customerId]);

        $db->commit();

        // Notificacao por tipo de categoria
        $categoria = strtolower(trim($parceiro['categoria'] ?? 'mercado'));
        $isMercado = in_array($categoria, ['mercado', 'supermercado']);
        $totalFormatted = number_format($total, 2, ',', '.');

        // Sempre notificar parceiro (legacy web push)
        $notifTitle = $autoAccept ? 'Pedido aceito automaticamente!' : 'Novo pedido!';
        notifyPartner($db, $partnerId, $notifTitle,
            "Pedido #$orderNumber - R$ $totalFormatted - " . $customer['name'],
            '/painel/mercado/pedidos.php'
        );

        // Feature 9: Notificacao in-app para parceiro (inclui FCM + WhatsApp automatico)
        require_once __DIR__ . '/../config/notify.php';
        if ($isMercado) {
            sendNotification($db, $partnerId, 'partner', 'Novo pedido de mercado!',
                "Pedido #$orderNumber - R$ $totalFormatted - Aguardando shopper",
                ['order_id' => $orderId, 'url' => '/pedidos']
            );
            require_once __DIR__ . '/../helpers/shopper-notify.php';
            notifyAvailableShoppers($db, $orderId, $orderNumber, $total, $partnerDisplayName);
        } else {
            sendNotification($db, $partnerId, 'partner', 'Novo pedido!',
                "Pedido #$orderNumber - R$ $totalFormatted - " . $customer['name'] . " - Confirme agora!",
                ['order_id' => $orderId, 'url' => '/pedidos']
            );
        }

        // FCM Push via NotificationSender (direct FCM for partner + customer confirmation)
        try {
            require_once __DIR__ . '/../helpers/NotificationSender.php';
            $notifSender = NotificationSender::getInstance($db);

            // Push to partner via FCM
            $notifSender->notifyPartner($partnerId, "Novo pedido #$orderNumber!",
                "R$ $totalFormatted - " . $customer['name'] . ($isPickup ? ' (Retirada)' : ''),
                ['order_id' => $orderId, 'order_number' => $orderNumber, 'url' => '/pedidos', 'type' => 'new_order']
            );

            // Push confirmation to customer via FCM
            $notifSender->notifyCustomer($customerId, 'Pedido confirmado!',
                "Seu pedido #$orderNumber foi recebido! Acompanhe em tempo real.",
                ['order_id' => $orderId, 'order_number' => $orderNumber, 'url' => '/pedidos?id=' . $orderId, 'type' => 'order_confirmed']
            );
        } catch (Exception $pushErr) {
            error_log("[checkout] FCM push erro: " . $pushErr->getMessage());
        }

        // WhatsApp direto para o cliente (confirmacao de pedido)
        try {
            require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
            if ($customer['phone']) {
                whatsappOrderCreated($customer['phone'], $orderNumber, $total, $partnerDisplayName);
            }
            // WhatsApp para o parceiro com template proprio
            if ($parceiro['phone']) {
                whatsappNewOrderPartner($parceiro['phone'], $orderNumber, $total, $customer['name']);
            }
        } catch (Exception $we) {
            error_log("[checkout] WhatsApp erro: " . $we->getMessage());
        }

        // Pusher: notificar parceiro em tempo real sobre novo pedido
        try {
            PusherService::newOrder($partnerId, [
                'id' => $orderId,
                'order_number' => $orderNumber,
                'status' => $initialStatus,
                'customer_name' => $customer['name'],
                'total' => $total,
                'items_count' => $totalItemsCount,
                'is_pickup' => $isPickup,
                'is_scheduled' => $isScheduled,
                'auto_accepted' => $autoAccept
            ]);
        } catch (Exception $pusherErr) {
            error_log("[checkout] Pusher erro: " . $pusherErr->getMessage());
        }

        $responseData = [
            "order_id" => $orderId,
            "order_number" => $orderNumber,
            "codigo_entrega" => $codigoEntrega,
            "status" => $initialStatus,
            "auto_accepted" => $autoAccept,
            "contactless" => $contactless,
            "subtotal" => round($subtotal, 2),
            "delivery_fee" => round($deliveryFee, 2),
            "service_fee" => $serviceFee,
            "tip" => round($tip, 2),
            "coupon_discount" => round($couponDiscount, 2),
            "total" => round($total, 2),
            "payment_method" => $paymentMethod,
            "points_discount" => round($pointsDiscount, 2),
            "points_spent" => $usePoints,
            "points_earned" => $earnedPoints ?? 0,
            "is_scheduled" => $isScheduled,
            "schedule_date" => $scheduleDate ?: null,
            "schedule_time" => $scheduleTime ?: null,
            "delivery_instructions" => $deliveryInstructions ?: null,
            "commission_rate" => $commissionRate,
            "commission_amount" => $commissionAmount,
            "platform_fee" => $platformFee,
            "partner" => ["id" => $partnerId, "name" => $partnerDisplayName, "plan" => $partnerPlan['slug']],
            "is_first_order" => ($previousOrders === 0),
            "welcome_coupon" => ($previousOrders === 0) ? [
                "code" => 'BEMVINDO' . $customerId,
                "value" => 5.00,
                "message" => "Parabens pela primeira compra! Use o cupom BEMVINDO{$customerId} e ganhe R\$5 de desconto no proximo pedido!"
            ] : null,
        ];

        if ($paymentMethod === 'pix') {
            // PIX payment requires a real Stripe PaymentIntent — client must call
            // /checkout/stripe-payment.php with the order_id to get a real PIX QR code.
            $responseData["pix"] = [
                "requires_payment" => true,
                "payment_url" => "/api/mercado/checkout/stripe-payment.php",
                "message" => "Finalize o pagamento PIX para confirmar o pedido.",
                "expiration" => date('Y-m-d H:i:s', strtotime('+30 minutes'))
            ];
        }

        response(true, $responseData, "Pedido criado com sucesso!");

    } catch (Exception $e) {
        // SECURITY: Ensure transaction is rolled back on any error
        if ($db->inTransaction()) {
            $db->rollBack();
            error_log("[API Checkout Process] Transaction rolled back due to error: " . $e->getMessage());
        }
        throw $e;
    }

} catch (Exception $e) {
    // Double-check rollback in case of errors outside the inner try block
    // This handles edge cases where $db exists but transaction wasn't rolled back
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        try {
            $db->rollBack();
            error_log("[API Checkout Process] Outer rollback executed");
        } catch (Exception $rollbackErr) {
            error_log("[API Checkout Process] Rollback failed: " . $rollbackErr->getMessage());
        }
    }
    error_log("[API Checkout Process] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar pedido", 500);
}
