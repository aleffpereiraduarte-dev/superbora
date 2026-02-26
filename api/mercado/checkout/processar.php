<?php
/**
 * POST /api/mercado/checkout/processar.php
 * B1: Checkout inline - processa pedido com suporte a PIX, cartao, dinheiro
 * Body: { customer_id, partner_id, items[], address, payment_method, coupon_id, tip, notes, is_pickup, schedule_date, schedule_time }
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
require_once __DIR__ . "/../helpers/notify.php";
require_once dirname(__DIR__, 3) . "/includes/classes/PusherService.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmRealtimeNotify.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmPricing.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmDailyBudget.php";
require_once __DIR__ . "/../helpers/EmailService.php";

// Rate limiting: 10 pedidos por minuto
if (!RateLimiter::check(10, 60)) {
    exit;
}

try {
    $input = getInput();
    $db = getDB();

    // Bug 4: Autenticacao obrigatoria - nao confiar no customer_id do client
    $auth_customer_id = requireCustomerAuth();

    // Sanitizar entrada — usar customer_id autenticado, ignorar valor do client
    $customer_id = $auth_customer_id;
    $session_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $input["session_id"] ?? session_id());
    $partner_id = (int)($input["partner_id"] ?? 0);
    $payment_method = preg_replace('/[^a-z_]/', '', $input["payment_method"] ?? "pix");
    $coupon_id = (int)($input["coupon_id"] ?? 0);
    $coupon_discount = 0; // Calculado server-side - nunca confiar no valor do client
    $tip = min(max(0, (float)($input["tip"] ?? 0)), OmPricing::GORJETA_MAX);
    $notes = trim(substr($input["notes"] ?? "", 0, 1000));
    $is_pickup = (int)($input["is_pickup"] ?? 0);
    $schedule_date = preg_replace('/[^0-9-]/', '', $input["schedule_date"] ?? "");
    $schedule_time = preg_replace('/[^0-9:]/', '', $input["schedule_time"] ?? "");
    $change_for = (float)($input["change_for"] ?? 0);
    $cpf_nota = preg_replace('/[^0-9]/', '', $input["cpf_nota"] ?? "");
    if ($cpf_nota && strlen($cpf_nota) !== 11) $cpf_nota = "";

    // Multi-stop route fields
    $is_route_primary = !empty($input['is_route_primary']);
    $route_partner_ids = $input['route_partner_ids'] ?? [];
    if (!is_array($route_partner_ids)) $route_partner_ids = [];
    $incoming_route_id = (int)($input['route_id'] ?? 0);
    $is_route_secondary = $incoming_route_id > 0;

    // Endereco
    $addressRaw = $input["address"] ?? "";
    if (is_array($addressRaw)) {
        $addressRaw = implode(", ", array_filter(array_map('strval', array_values($addressRaw))));
    }
    $address = trim(substr((string)$addressRaw, 0, 500));
    $shipping_cep = preg_replace('/[^0-9]/', '', $input["cep"] ?? "");
    $shipping_city = trim(substr($input["city"] ?? "", 0, 100));
    $shipping_state = trim(substr($input["state"] ?? "", 0, 2));

    // Dados do cliente - buscar do banco para usuarios autenticados (nao confiar no request body)
    $custStmt = $db->prepare("SELECT name, phone, email FROM om_customers WHERE customer_id = ?");
    $custStmt->execute([$customer_id]);
    $custData = $custStmt->fetch(PDO::FETCH_ASSOC);
    if (!$custData) {
        response(false, null, "Cliente nao encontrado", 404);
    }
    $customer_name = trim($custData['name'] ?: 'Cliente');
    $customer_phone = preg_replace('/[^0-9]/', '', $custData['phone'] ?? '');
    // Normalizar: remover codigo do pais se presente
    if (strlen($customer_phone) === 13 && substr($customer_phone, 0, 2) === '55') {
        $customer_phone = substr($customer_phone, 2);
    }
    $customer_email = $custData['email'] ?? '';

    // Validacoes
    $formasPermitidas = ['pix', 'credito', 'stripe_card', 'debito', 'dinheiro', 'cartao_entrega', 'stripe_wallet', 'vale_refeicao'];
    if (!in_array($payment_method, $formasPermitidas)) {
        response(false, null, "Forma de pagamento invalida", 400);
    }
    if ($payment_method === 'vale_refeicao') {
        response(false, null, "Pagamento com VR/VA estara disponivel em breve. Use PIX ou cartao.", 501);
    }

    // ═══════════════════════════════════════════════════════
    // GATE: Dinheiro/maquininha — anti-fraude + regras
    // ═══════════════════════════════════════════════════════
    if (in_array($payment_method, ['dinheiro', 'cartao_entrega'])) {
        // 1. Precisa ter 1+ pedido entregue com pagamento online
        $stmtCompleted = $db->prepare("
            SELECT COUNT(*) FROM om_market_orders
            WHERE customer_id = ? AND status IN ('entregue','retirado','completed')
            AND payment_method NOT IN ('dinheiro','cartao_entrega')
        ");
        $stmtCompleted->execute([$customer_id]);
        if ((int)$stmtCompleted->fetchColumn() < 1) {
            response(false, null, "Pagamento na entrega disponivel a partir do 2o pedido. Faca seu primeiro pedido com PIX ou cartao.", 403);
        }

        // 2. Cash nao permitido com BoraUm (apenas retirada ou entrega propria)
        if (!$is_pickup) {
            $stmtPCash = $db->prepare("SELECT entrega_propria FROM om_market_partners WHERE partner_id = ?");
            $stmtPCash->execute([$partner_id]);
            $pCash = $stmtPCash->fetch();
            if (!$pCash || !$pCash['entrega_propria']) {
                response(false, null, "Pagamento na entrega disponivel apenas para retirada ou entrega propria do restaurante.", 403);
            }
        }

        // 3. Wallet: checar limite de credito do parceiro
        $walletCheck = OmPricing::getWalletParceiro($db, $partner_id);
        if ($walletCheck['cash_bloqueado']) {
            response(false, null, "Pagamento na entrega temporariamente indisponivel para este restaurante. Use PIX ou cartao.", 403);
        }
    }

    if (!$is_pickup && empty($address)) {
        response(false, null, "Endereco de entrega obrigatorio", 400);
    }

    // Validar partner_id — obrigatorio (exceto quando rota secundaria herda do primario)
    if ($partner_id <= 0 && !$is_route_secondary) {
        response(false, null, "partner_id obrigatorio", 400);
    }

    // Buscar carrinho — SECURITY: authenticated users query by customer_id only
    $cartParams = [$customer_id];
    $partnerFilter = "";
    if ($partner_id > 0) {
        $partnerFilter = " AND c.partner_id = ?";
        $cartParams[] = $partner_id;
    }

    $stmt = $db->prepare("
        SELECT c.*, p.name, p.price, p.special_price, p.quantity as estoque
        FROM om_market_cart c
        INNER JOIN om_market_products p ON c.product_id = p.product_id
        WHERE c.customer_id = ? $partnerFilter
    ");
    $stmt->execute($cartParams);
    $itens = $stmt->fetchAll();

    if (empty($itens)) {
        response(false, null, "Carrinho vazio", 400);
    }

    $actual_partner_id = (int)$itens[0]["partner_id"];
    if ($partner_id && $actual_partner_id !== $partner_id) {
        // Log mismatch for debugging, but don't block — SQL filter already ensures consistency
        error_log("[Checkout] partner_id mismatch: requested={$partner_id}, actual={$actual_partner_id}, customer={$customer_id}, session={$session_id}");
    }
    $partner_id = $actual_partner_id;

    // Verificar estoque
    $errosEstoque = [];
    foreach ($itens as $item) {
        if ($item["quantity"] > $item["estoque"]) {
            $errosEstoque[] = "'{$item['name']}' - estoque insuficiente";
        }
    }
    if (!empty($errosEstoque)) {
        response(false, ["erros_estoque" => $errosEstoque], "Alguns itens estao sem estoque: " . $errosEstoque[0], 400);
    }

    // Buscar parceiro
    $stmt = $db->prepare("SELECT * FROM om_market_partners WHERE partner_id = ? AND status::text = '1'");
    $stmt->execute([$partner_id]);
    $parceiro = $stmt->fetch();

    if (!$parceiro) {
        response(false, null, "Estabelecimento nao disponivel", 400);
    }

    // Calcular valores
    $subtotal = 0;
    foreach ($itens as $item) {
        $preco = ($item['special_price'] && (float)$item['special_price'] > 0 && (float)$item['special_price'] < (float)$item['price'])
            ? (float)$item['special_price'] : (float)$item['price'];
        $subtotal += $preco * (int)$item['quantity'];
    }

    // ═══════════════════════════════════════════════════════
    // FRETE — calculado via OmPricing (fonte unica de verdade)
    // ═══════════════════════════════════════════════════════
    $usaBoraUm = !$is_pickup && !$parceiro['entrega_propria'];
    $express_fee_raw = (float)($input['express_fee'] ?? 0);
    // Only allow express fee if store supports it
    $express_enabled = !empty($parceiro['delivery_express']) || !empty($parceiro['express_disponivel']);
    $express_fee = $express_enabled ? max(0, min(OmPricing::EXPRESS_FEE_MAX, $express_fee_raw)) : 0;

    // Calcular distancia (se BoraUm)
    $distancia_km = 3.0; // fallback
    $lat_cliente = (float)($input['lat'] ?? $input['latitude'] ?? 0);
    $lng_cliente = (float)($input['lng'] ?? $input['longitude'] ?? 0);
    $lat_parceiro = (float)($parceiro['latitude'] ?? $parceiro['lat'] ?? 0);
    $lng_parceiro = (float)($parceiro['longitude'] ?? $parceiro['lng'] ?? 0);
    // Validate coordinate ranges
    if ($lat_cliente && ($lat_cliente < -90 || $lat_cliente > 90)) $lat_cliente = 0;
    if ($lng_cliente && ($lng_cliente < -180 || $lng_cliente > 180)) $lng_cliente = 0;
    if ($usaBoraUm && $lat_parceiro && $lat_cliente) {
        $distancia_km = OmPricing::calcularDistancia($lat_parceiro, $lng_parceiro, $lat_cliente, $lng_cliente);
    }


    // ═══════════════════════════════════════════════════════
    // RAIO DE ENTREGA — rejeitar pedidos fora da area (server-side enforcement)
    // ═══════════════════════════════════════════════════════
    if ($usaBoraUm && $lat_parceiro && $lat_cliente) {
        $raio_km = (float)($parceiro['delivery_radius_km'] ?? 0);
        if ($raio_km > 0 && $distancia_km > $raio_km) {
            response(false, null, "Voce esta fora da area de entrega deste estabelecimento (" . number_format($distancia_km, 1, ',', '') . " km, maximo " . number_format($raio_km, 1, ',', '') . " km)", 400);
        }
    }
    // Validacao de pedido minimo por distancia (BoraUm)
    if ($usaBoraUm) {
        $minimoBoraUm = OmPricing::getMinimoBoraUm($distancia_km);
        if ($subtotal < $minimoBoraUm) {
            response(false, null, "Pedido minimo R$ " . number_format($minimoBoraUm, 2, ',', '.') . " para entregas " . ($distancia_km <= 3 ? 'ate 3km' : ($distancia_km <= 6 ? 'de 3 a 6km' : 'acima de 6km')), 400);
        }
    }

    // Calcular frete via OmPricing (inclui subsidio inteligente + SuperBora+)
    $freteCalc = OmPricing::calcularFrete($parceiro, $subtotal, $distancia_km, (bool)$is_pickup, $usaBoraUm, $db, $customer_id);
    $base_delivery_fee = $freteCalc['frete'];
    $delivery_fee = $base_delivery_fee + ($express_fee > 0 && !$is_pickup ? $express_fee : 0);

    // Log mismatch entre frete do client e do server (anti-fraude + debug)
    $client_delivery_fee = (float)($input['delivery_fee'] ?? -1);
    if ($client_delivery_fee >= 0 && abs($client_delivery_fee - $delivery_fee) > 0.01) {
        error_log("[Checkout] delivery_fee mismatch: client={$client_delivery_fee}, server={$delivery_fee}, partner={$partner_id}, customer={$customer_id}, subtotal={$subtotal}");
    }

    // Validar cupom server-side (nunca confiar no coupon_discount do client)
    $free_delivery_coupon = false;
    $coupon_discount = 0; // Reset - será calculado server-side
    if ($coupon_id) {
        $stmtC = $db->prepare("SELECT * FROM om_market_coupons WHERE id = ? AND status = 'active' AND (partner_id IS NULL OR partner_id = 0 OR partner_id = ?)");
        $stmtC->execute([$coupon_id, $partner_id]);
        $cupomData = $stmtC->fetch();
        if ($cupomData) {
            // Verificar validade
            $now = date('Y-m-d H:i:s');
            $valid = true;
            if (!empty($cupomData['start_date']) && $now < $cupomData['start_date']) $valid = false;
            if (!empty($cupomData['end_date']) && $now > $cupomData['end_date']) $valid = false;
            if (isset($cupomData['min_order']) && $subtotal < (float)$cupomData['min_order']) $valid = false;

            // Verificar uso unico por cliente
            if ($valid && $customer_id > 0 && !empty($cupomData['single_use'])) {
                $stmtUsed = $db->prepare("SELECT 1 FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ? LIMIT 1");
                $stmtUsed->execute([$coupon_id, $customer_id]);
                if ($stmtUsed->fetch()) {
                    $valid = false; // Ja usado por este cliente
                }
            }

            if ($valid) {
                if ($cupomData['discount_type'] === 'free_delivery') {
                    $delivery_fee = 0;
                    $free_delivery_coupon = true;
                } elseif ($cupomData['discount_type'] === 'percentage') {
                    $pct = min(100, max(0, (float)$cupomData['discount_value']));
                    $coupon_discount = round($subtotal * $pct / 100, 2);
                    if (!empty($cupomData['max_discount']) && $coupon_discount > (float)$cupomData['max_discount']) {
                        $coupon_discount = (float)$cupomData['max_discount'];
                    }
                } elseif ($cupomData['discount_type'] === 'fixed') {
                    $coupon_discount = min((float)$cupomData['discount_value'], $subtotal);
                    if (!empty($cupomData['max_discount']) && $coupon_discount > (float)$cupomData['max_discount']) {
                        $coupon_discount = (float)$cupomData['max_discount'];
                    }
                }
            } else {
                $coupon_id = 0; // Cupom inválido
            }
        } else {
            $coupon_id = 0; // Cupom não encontrado
        }
    }

    // Validar agendamento no futuro
    if ($schedule_date && $schedule_time) {
        $scheduleDT = strtotime("$schedule_date $schedule_time");
        if ($scheduleDT && $scheduleDT < time()) {
            // Agendamento no passado - limpar (pedido vira imediato)
            $schedule_date = "";
            $schedule_time = "";
        }
    }

    // Pedido minimo
    $pedidoMinimo = (float)($parceiro['min_order_value'] ?? $parceiro['min_order'] ?? 0);
    if ($subtotal < $pedidoMinimo) {
        response(false, null, "Pedido minimo: R$ " . number_format($pedidoMinimo, 2, ',', '.'), 400);
    }

    // Service fee: sempre usar valor do servidor. Client nao pode alterar.
    $service_fee = OmPricing::TAXA_SERVICO;

    // Loyalty/cashback pre-calc (preliminary — re-validated inside transaction with FOR UPDATE)
    $loyalty_points_used = 0;
    $loyalty_discount = 0;
    $use_points = max(0, (int)($input['use_points'] ?? 0));
    $cashback_discount = 0;
    $use_cashback = max(0, (float)($input['use_cashback'] ?? 0));

    // Preliminary estimates (for total/validation before transaction)
    if ($use_points > 0 && $customer_id > 0) {
        $stmtPts = $db->prepare("SELECT current_points FROM om_market_loyalty_points WHERE customer_id = ?");
        $stmtPts->execute([$customer_id]);
        $currentPoints = (int)$stmtPts->fetchColumn();
        if ($currentPoints > 0) {
            $loyalty_points_used = min($use_points, $currentPoints);
            $loyalty_discount = round($loyalty_points_used * OmPricing::PONTO_VALOR, 2);
            $maxLoyaltyDiscount = round($subtotal * OmPricing::PONTOS_MAX_DESCONTO_PCT, 2);
            if ($loyalty_discount > $maxLoyaltyDiscount) {
                $loyalty_points_used = (int)floor($maxLoyaltyDiscount / OmPricing::PONTO_VALOR);
                $loyalty_discount = round($loyalty_points_used * OmPricing::PONTO_VALOR, 2);
            }
        }
    }
    if ($use_cashback > 0 && $customer_id > 0) {
        $stmtCb = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_cashback WHERE customer_id = ? AND type IN ('earned','bonus') AND status = 'available' AND (expires_at IS NULL OR expires_at > NOW())");
        $stmtCb->execute([$customer_id]);
        $cbBalance = (float)$stmtCb->fetchColumn();
        if ($cbBalance > 0) {
            $cashback_discount = min($use_cashback, $cbBalance);
        }
    }

    $total = $subtotal - $coupon_discount - $loyalty_discount - $cashback_discount + $delivery_fee + $tip + $service_fee;
    if ($total < 0) $total = 0;

    // Teto para dinheiro (apos calcular total)
    if ($payment_method === 'dinheiro' && $total > OmPricing::CASH_LIMITE) {
        response(false, null, "Limite de R$" . number_format(OmPricing::CASH_LIMITE, 0) . " para pagamento em dinheiro. Use PIX ou cartao para valores maiores.", 403);
    }

    // Validar: total 0 so e permitido se houve desconto real cobrindo o valor
    $totalDiscounts = $coupon_discount + $loyalty_discount + $cashback_discount;
    if ($total <= 0 && $totalDiscounts <= 0) {
        response(false, null, "Erro no calculo do pedido. Tente novamente.", 400);
    }

    // Gerar codigo_entrega (order_number sera gerado apos INSERT com o order_id)
    $order_number_temp = 'SB-' . strtoupper(bin2hex(random_bytes(4)));
    $codigo_entrega = strtoupper(bin2hex(random_bytes(3)));
    $market_id = (int)($parceiro["market_id"] ?? $parceiro["partner_id"] ?? 0);
    $partner_categoria = $parceiro['categoria'] ?? 'mercado';
    $timer_started = date('Y-m-d H:i:s');
    // PIX: 5 min para pagar. Cartao/outros: 5 min padrao
    $timer_minutes = 5;
    $timer_expires = date('Y-m-d H:i:s', strtotime("+{$timer_minutes} minutes"));
    $timer_expires_iso = date('c', strtotime("+{$timer_minutes} minutes")); // ISO 8601 for frontend

    // ===========================================
    // CARTAO: Verificar pagamento ANTES de criar pedido (estilo iFood)
    // ===========================================
    $stripe_pi_id = trim($input['stripe_payment_intent_id'] ?? '');
    $stripe_verified = false;
    $stripe_pi_status = '';

    if (in_array($payment_method, ['stripe_card', 'stripe_wallet', 'credito']) && $stripe_pi_id) {
        $stripeCheckResult = verificarStripePayment($stripe_pi_id);
        if (!$stripeCheckResult['paid']) {
            response(false, null, "Pagamento nao confirmado. Tente novamente.", 402);
        }
        $stripe_pi_status = $stripeCheckResult['status'] ?? '';

        // SECURITY: Verify amount matches order total (compare in cents to avoid float precision errors)
        // For route primary orders, PI covers all stores so amount may be higher — skip strict check
        $paidAmountCents = (int)($stripeCheckResult['amount_cents'] ?? 0);
        $totalCents = (int)round($total * 100);
        if ($paidAmountCents > 0 && !$is_route_primary && abs($paidAmountCents - $totalCents) > 5) { // tolerance: 5 cents
            error_log("[Checkout] SECURITY: Stripe amount mismatch! Paid: {$paidAmountCents} cents, Total: {$totalCents} cents, PI: {$stripe_pi_id}");
            response(false, null, "Valor do pagamento nao confere com o total do pedido.", 402);
        }
        if ($is_route_primary && $paidAmountCents > 0 && $paidAmountCents < $totalCents - 5) {
            error_log("[Checkout] SECURITY: Route primary PI underpaid! Paid: {$paidAmountCents} cents, Store total: {$totalCents} cents, PI: {$stripe_pi_id}");
            response(false, null, "Valor do pagamento insuficiente.", 402);
        }

        $stripe_verified = true;
        error_log("[Checkout] Stripe PI {$stripe_pi_id} verificado ANTES de criar pedido. Status: {$stripeCheckResult['status']}, Amount: {$paidAmountCents} cents");
    } elseif (in_array($payment_method, ['stripe_card', 'stripe_wallet', 'credito']) && !$stripe_pi_id) {
        // Secondary route orders inherit payment from primary — skip PI requirement
        if (!$is_route_secondary) {
            response(false, null, "Pagamento nao processado. Tente novamente.", 400);
        }
    }

    // Multi-stop route: secondary orders inherit payment from primary
    if ($is_route_secondary) {
        $stmtRouteCheck = $db->prepare("SELECT o.order_id, o.status, o.stripe_payment_intent_id, o.forma_pagamento
            FROM om_market_orders o WHERE o.route_id = ? AND o.route_stop_sequence = 1 AND o.customer_id = ? LIMIT 1");
        $stmtRouteCheck->execute([$incoming_route_id, $customer_id]);
        $primaryOrder = $stmtRouteCheck->fetch();
        if (!$primaryOrder) {
            response(false, null, "Rota nao encontrada ou nao pertence a este cliente.", 400);
        }
        // Inherit payment verification from primary order — actually verify PI status
        if (in_array($payment_method, ['stripe_card', 'stripe_wallet', 'credito'])) {
            $stripe_pi_id = $primaryOrder['stripe_payment_intent_id'] ?? '';
            if (!empty($stripe_pi_id)) {
                $secondaryCheckResult = verificarStripePayment($stripe_pi_id);
                $stripe_verified = $secondaryCheckResult['paid'] === true;
                $stripe_pi_status = $secondaryCheckResult['status'] ?? '';
                if (!$stripe_verified) {
                    error_log("[Checkout] Secondary route PI {$stripe_pi_id} not succeeded (status: {$stripe_pi_status})");
                    response(false, null, "Pagamento da rota primaria nao confirmado.", 402);
                }
            } else {
                $stripe_verified = false;
            }
        }
        // Force delivery_fee to 0 for secondary stops
        $delivery_fee = 0;
    }

    // Determinar tipo de entrega
    $delivery_type = 'boraum';
    if ($is_pickup) {
        $delivery_type = 'retirada';
    } elseif ($parceiro['entrega_propria']) {
        $delivery_type = 'proprio';
    }

    // Transacao
    $db->beginTransaction();

    try {
        // SECURITY: Check Stripe PI not reused — INSIDE transaction to prevent double-spend race
        // Skip for secondary route orders (they inherit PI from primary)
        // Check both stripe_payment_intent_id and payment_id columns; lock rows to block concurrent inserts
        if ($stripe_verified && $stripe_pi_id && !$is_route_secondary) {
            // Advisory lock on PI hash to prevent race condition when no order exists yet
            $db->prepare("SELECT pg_advisory_xact_lock(?)")->execute([crc32($stripe_pi_id)]);
            $stmtPiCheck = $db->prepare("SELECT order_id FROM om_market_orders WHERE (stripe_payment_intent_id = ? OR payment_id = ?) AND status != 'cancelado' LIMIT 1 FOR UPDATE");
            $stmtPiCheck->execute([$stripe_pi_id, $stripe_pi_id]);
            $existingPiOrder = $stmtPiCheck->fetch();
            if ($existingPiOrder) {
                $db->rollBack();
                error_log("[Checkout] SECURITY: PI reuse blocked. PI={$stripe_pi_id}, existing_order={$existingPiOrder['order_id']}");
                response(false, null, "Este pagamento ja foi utilizado para outro pedido.", 409);
            }
        }

        // Re-verificar estoque dentro da transacao com lock
        foreach ($itens as $item) {
            $stmtLock = $db->prepare("SELECT quantity FROM om_market_products WHERE product_id = ? FOR UPDATE");
            $stmtLock->execute([$item['product_id']]);
            $estoque = $stmtLock->fetchColumn();
            if ((int)$item['quantity'] > (int)$estoque) {
                $db->rollBack();
                response(false, null, "'{$item['name']}' - estoque insuficiente (disponivel: {$estoque})", 400);
            }
        }

        // Re-validate loyalty points inside transaction with FOR UPDATE
        if ($use_points > 0 && $customer_id > 0) {
            $stmtPtsLock = $db->prepare("SELECT current_points FROM om_market_loyalty_points WHERE customer_id = ? FOR UPDATE");
            $stmtPtsLock->execute([$customer_id]);
            $lockedPoints = (int)$stmtPtsLock->fetchColumn();
            $loyalty_points_used = min($use_points, $lockedPoints);
            $loyalty_discount = round($loyalty_points_used * OmPricing::PONTO_VALOR, 2);
            $maxLoyaltyDiscount = round($subtotal * OmPricing::PONTOS_MAX_DESCONTO_PCT, 2);
            if ($loyalty_discount > $maxLoyaltyDiscount) {
                $loyalty_discount = $maxLoyaltyDiscount;
                $loyalty_points_used = (int)round($loyalty_discount / OmPricing::PONTO_VALOR);
            }
        }

        // Re-validate cashback inside transaction with FOR UPDATE
        if ($use_cashback > 0 && $customer_id > 0) {
            $stmtCbLock = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM om_cashback WHERE customer_id = ? AND type IN ('earned','bonus') AND status = 'available' AND (expires_at IS NULL OR expires_at > NOW()) FOR UPDATE");
            $stmtCbLock->execute([$customer_id]);
            $lockedCbBalance = (float)$stmtCbLock->fetchColumn();
            $cashback_discount = min($use_cashback, $lockedCbBalance);
        }

        // Re-validate coupon usage inside transaction (single_use + max_uses + max_uses_per_user)
        if ($coupon_id > 0 && $customer_id > 0) {
            $stmtCouponLock = $db->prepare("SELECT * FROM om_market_coupons WHERE id = ? FOR UPDATE");
            $stmtCouponLock->execute([$coupon_id]);
            $lockedCoupon = $stmtCouponLock->fetch();
            if ($lockedCoupon) {
                // Check single_use flag
                if (!empty($lockedCoupon['single_use'])) {
                    $stmtUsedCheck = $db->prepare("SELECT 1 FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ? LIMIT 1");
                    $stmtUsedCheck->execute([$coupon_id, $customer_id]);
                    if ($stmtUsedCheck->fetch()) {
                        $db->rollBack();
                        response(false, null, "Cupom ja utilizado", 400);
                    }
                }
                // Check max_uses global limit
                if (!empty($lockedCoupon['max_uses']) && (int)$lockedCoupon['max_uses'] > 0) {
                    $stmtGlobal = $db->prepare("SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = ?");
                    $stmtGlobal->execute([$coupon_id]);
                    if ((int)$stmtGlobal->fetchColumn() >= (int)$lockedCoupon['max_uses']) {
                        $db->rollBack();
                        response(false, null, "Cupom esgotado", 400);
                    }
                }
                // Check max_uses_per_user limit
                if (!empty($lockedCoupon['max_uses_per_user']) && (int)$lockedCoupon['max_uses_per_user'] > 0) {
                    $stmtUser = $db->prepare("SELECT COUNT(*) FROM om_market_coupon_usage WHERE coupon_id = ? AND customer_id = ?");
                    $stmtUser->execute([$coupon_id, $customer_id]);
                    if ((int)$stmtUser->fetchColumn() >= (int)$lockedCoupon['max_uses_per_user']) {
                        $db->rollBack();
                        response(false, null, "Voce ja usou este cupom o maximo de vezes", 400);
                    }
                }
            } else {
                $coupon_id = 0;
                $coupon_discount = 0;
            }
        }

        // Recalculate total with locked values
        $total = $subtotal - $coupon_discount - $loyalty_discount - $cashback_discount + $delivery_fee + $tip + $service_fee;
        if ($total < 0) $total = 0;

        // Criar pedido
        $installments = max(1, min(12, (int)($input['installments'] ?? 1)));
        $installment_value = $installments > 1 ? round($total / $installments, 2) : $total;

        $stmt = $db->prepare("INSERT INTO om_market_orders (
            order_number, partner_id, market_id, customer_id,
            customer_name, customer_phone, customer_email,
            status, subtotal, delivery_fee, total, tip_amount,
            delivery_address, shipping_address, shipping_city, shipping_state, shipping_cep,
            notes, codigo_entrega, forma_pagamento,
            coupon_id, coupon_discount,
            loyalty_points_used, loyalty_discount,
            is_pickup, schedule_date, schedule_time,
            timer_started, timer_expires, partner_categoria,
            delivery_type, cpf_nota,
            service_fee, express_fee, installments, installment_value,
            cashback_discount, stripe_payment_intent_id,
            route_id, route_stop_sequence, shipping_lat, shipping_lng,
            date_added
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendente', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        RETURNING order_id");

        // Route sequence: primary=1, secondary=next stop
        $route_stop_seq = null;
        $order_route_id = null;
        if ($is_route_primary) {
            $route_stop_seq = 1;
            // Route will be created after INSERT to get route_id
        } elseif ($is_route_secondary) {
            $order_route_id = $incoming_route_id;
            // Determine next stop sequence
            $stmtSeq = $db->prepare("SELECT COALESCE(MAX(route_stop_sequence), 1) + 1 FROM om_market_orders WHERE route_id = ? FOR UPDATE");
            $stmtSeq->execute([$incoming_route_id]);
            $route_stop_seq = (int)$stmtSeq->fetchColumn();
        }

        $stmt->execute([
            $order_number_temp, $partner_id, $market_id, $customer_id,
            $customer_name, $customer_phone, $customer_email,
            $subtotal, $delivery_fee, $total, $tip,
            $address, $address, $shipping_city, $shipping_state, $shipping_cep,
            $notes, $codigo_entrega, $payment_method,
            $coupon_id ?: null, $coupon_discount,
            $loyalty_points_used, $loyalty_discount,
            $is_pickup, $schedule_date ?: null, $schedule_time ?: null,
            $timer_started, $timer_expires, $partner_categoria,
            $delivery_type, $cpf_nota ?: null,
            $service_fee, $express_fee, $installments, $installment_value,
            $cashback_discount, $stripe_pi_id ?: null,
            $order_route_id, $route_stop_seq, $lat_cliente ?: null, $lng_cliente ?: null
        ]);

        $order_id = (int)$stmt->fetchColumn();

        // Gerar order_number bonito com o order_id: SB00025
        $order_number = 'SB' . str_pad($order_id, 5, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE om_market_orders SET order_number = ? WHERE order_id = ?")->execute([$order_number, $order_id]);

        // Multi-stop route: create route record for primary order, add stop for secondary
        $created_route_id = null;
        if ($is_route_primary) {
            $stmtRoute = $db->prepare("INSERT INTO om_delivery_routes (customer_id, origin_partner_id, customer_lat, customer_lng, customer_address, total_delivery_fee, total_orders, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW()) RETURNING route_id");
            $totalRouteOrders = 1 + count($route_partner_ids);
            $stmtRoute->execute([$customer_id, $partner_id, $lat_cliente ?: null, $lng_cliente ?: null, $address, $delivery_fee, $totalRouteOrders]);
            $created_route_id = (int)$stmtRoute->fetchColumn();
            // Update order with route_id
            $db->prepare("UPDATE om_market_orders SET route_id = ? WHERE order_id = ?")->execute([$created_route_id, $order_id]);
            // Add primary stop
            $db->prepare("INSERT INTO om_delivery_route_stops (route_id, order_id, partner_id, stop_sequence, partner_lat, partner_lng, partner_name, stop_type, status) VALUES (?, ?, ?, 1, ?, ?, ?, 'pickup', 'pending')")
                ->execute([$created_route_id, $order_id, $partner_id, $lat_parceiro ?: null, $lng_parceiro ?: null, $parceiro['trade_name'] ?? $parceiro['name']]);
        } elseif ($is_route_secondary) {
            // Add secondary stop
            $db->prepare("INSERT INTO om_delivery_route_stops (route_id, order_id, partner_id, stop_sequence, partner_lat, partner_lng, partner_name, stop_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pickup', 'pending')")
                ->execute([$incoming_route_id, $order_id, $partner_id, $route_stop_seq, $lat_parceiro ?: null, $lng_parceiro ?: null, $parceiro['trade_name'] ?? $parceiro['name']]);
        }

        // Criar itens do pedido
        $stmtItem = $db->prepare("INSERT INTO om_market_order_items (order_id, product_id, name, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?)");

        foreach ($itens as $item) {
            $preco = ($item['special_price'] && (float)$item['special_price'] > 0 && (float)$item['special_price'] < (float)$item['price'])
                ? (float)$item['special_price'] : (float)$item['price'];
            $itemTotal = $preco * (int)$item['quantity'];
            $stmtItem->execute([$order_id, $item['product_id'], $item['name'], $item['quantity'], $preco, $itemTotal]);

            // Decrementar estoque — defensive WHERE prevents negative stock
            $stmtEstoque = $db->prepare("UPDATE om_market_products SET quantity = quantity - ? WHERE product_id = ? AND quantity >= ?");
            $stmtEstoque->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
            if ($stmtEstoque->rowCount() === 0) {
                $db->rollBack();
                response(false, null, "'{$item['name']}' - estoque insuficiente", 400);
            }
        }

        // Registrar uso do cupom + incrementar current_uses
        if ($coupon_id) {
            $stmtCoupon = $db->prepare("INSERT INTO om_market_coupon_usage (coupon_id, customer_id, order_id) VALUES (?, ?, ?)");
            $stmtCoupon->execute([$coupon_id, $customer_id, $order_id]);
            $db->prepare("UPDATE om_market_coupons SET current_uses = current_uses + 1 WHERE id = ?")->execute([$coupon_id]);
        }

        // Deduzir pontos de fidelidade
        if ($loyalty_points_used > 0 && $customer_id > 0) {
            $db->prepare("UPDATE om_market_loyalty_points SET current_points = current_points - ?, updated_at = NOW() WHERE customer_id = ?")
               ->execute([$loyalty_points_used, $customer_id]);
            $db->prepare("INSERT INTO om_market_loyalty_transactions (customer_id, points, type, source, reference_id, description, created_at) VALUES (?, ?, 'redeem', 'checkout', ?, ?, NOW())")
               ->execute([$customer_id, -$loyalty_points_used, $order_id, "Resgate no pedido #$order_number"]);
        }

        // Deduzir cashback
        if ($cashback_discount > 0 && $customer_id > 0) {
            $remaining = $cashback_discount;
            $stmtCbList = $db->prepare("SELECT id, COALESCE(amount, 0) as amount FROM om_cashback WHERE customer_id = ? AND type IN ('earned','bonus') AND status = 'available' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY expires_at ASC NULLS LAST FOR UPDATE");
            $stmtCbList->execute([$customer_id]);
            $cbRows = $stmtCbList->fetchAll();
            foreach ($cbRows as $cb) {
                if ($remaining <= 0) break;
                $use = min($remaining, (float)$cb['amount']);
                if ($use >= (float)$cb['amount']) {
                    $db->prepare("UPDATE om_cashback SET status = 'used', order_id = ? WHERE id = ?")->execute([$order_id, $cb['id']]);
                } else {
                    $db->prepare("UPDATE om_cashback SET amount = amount - ? WHERE id = ?")->execute([$use, $cb['id']]);
                }
                $remaining -= $use;
            }
        }

        // Limpar carrinho — SECURITY: use customer_id only (no session_id OR leak)
        $stmtClear = $db->prepare("DELETE FROM om_market_cart WHERE customer_id = ? AND partner_id = ?");
        $stmtClear->execute([$customer_id, $partner_id]);

        // Para PIX, gerar cobranca via Woovi ANTES do commit (dentro da transacao)
        $pixData = null;
        if ($payment_method === 'pix') {
            $pixData = gerarPixWoovi($order_id, $total, $customer_name, $customer_email, $customer_phone, $customer_id, $cpf_nota);
            if ($pixData && !empty($pixData['qr_code_text'])) {
                // Save PIX data to order within the same transaction
                $db->prepare("UPDATE om_market_orders SET pix_code = ?, pix_qr_code = ? WHERE order_id = ?")
                   ->execute([$pixData['qr_code_text'], $pixData['qr_code_url'] ?? '', $order_id]);
            } else {
                // PIX falhou — rollback tudo (estoque, pontos, cashback, cupom voltam automaticamente)
                $db->rollBack();
                error_log("[Checkout] PIX generation failed for order #{$order_id}. Transaction rolled back.");
                response(false, null, "Pagamento PIX indisponivel no momento. Tente novamente em alguns minutos ou use outro metodo de pagamento.", 503);
            }
        }

        $db->commit();

        // P&L DIARIO — NAO registrar no checkout.
        // O P&L e registrado SOMENTE em confirmar-entrega.php (quando pedido e entregue)
        // para evitar contabilizar pedidos cancelados/nao entregues.

        $responseData = [
            "order_id" => $order_id,
            "order_number" => $order_number,
            "codigo_entrega" => $codigo_entrega,
            "status" => "pendente",
            "subtotal" => round($subtotal, 2),
            "desconto_cupom" => round($coupon_discount, 2),
            "desconto_pontos" => round($loyalty_discount, 2),
            "pontos_usados" => $loyalty_points_used,
            "desconto_cashback" => round($cashback_discount, 2),
            "taxa_entrega" => round($delivery_fee, 2),
            "gorjeta" => round($tip, 2),
            "total" => round($total, 2),
            "forma_pagamento" => $payment_method,
            "tempo_estimado" => (int)($parceiro["delivery_time_min"] ?? 60),
            "parceiro" => [
                "id" => $partner_id,
                "nome" => $parceiro["trade_name"] ?? $parceiro["name"]
            ],
            "frete_info" => [
                "gratis" => $freteCalc['gratis'] ?? false,
                "custo_boraum" => $freteCalc['custo_boraum'] ?? 0,
                "desconto_plus" => $freteCalc['desconto_plus'] ?? 0,
                "distancia_km" => $distancia_km ?? 0,
            ],
            "route_id" => $created_route_id ?: ($incoming_route_id ?: null),
            "route_stop_sequence" => $route_stop_seq,
        ];

        // Add PIX data to response
        if ($payment_method === 'pix' && $pixData) {
            $responseData["pix"] = $pixData;
        }

        // Para cartao/wallet via Stripe — pagamento JA verificado antes do INSERT, PI already in INSERT
        // SECURITY: Only confirm if PI status is truly 'succeeded' (not processing/requires_capture)
        if ($stripe_verified && $stripe_pi_id && $stripe_pi_status === 'succeeded') {
            try {
                $db->prepare("UPDATE om_market_orders SET status = 'confirmado' WHERE order_id = ?")
                   ->execute([$order_id]);
                $responseData["status"] = "confirmado";
            } catch (Exception $e) {
                error_log("[Checkout] stripe status update error: " . $e->getMessage());
            }
            $responseData["card_payment"] = ["status" => "paid", "payment_intent_id" => $stripe_pi_id];
        }

        // Para pagamento na entrega (dinheiro/maquininha), salvar troco
        if (in_array($payment_method, ['dinheiro', 'cartao_entrega'])) {
            if ($change_for > 0 && $payment_method === 'dinheiro') {
                try {
                    $db->prepare("UPDATE om_market_orders SET change_for = ? WHERE order_id = ?")
                       ->execute([$change_for, $order_id]);
                } catch (Exception $e) {
                    error_log("[Checkout] Erro ao salvar troco: " . $e->getMessage());
                }
            }
            $responseData["pagamento_entrega"] = [
                "tipo" => $payment_method === 'dinheiro' ? 'Dinheiro' : 'Cartao na entrega',
                "troco_para" => $payment_method === 'dinheiro' && $change_for > 0 ? $change_for : null
            ];
        }

        // Notificar parceiro — para PIX, SÓ notificar quando pagamento for confirmado via webhook
        // Para outros metodos (dinheiro, cartao), notificar imediatamente
        if ($payment_method !== 'pix') {
            notifyPartner($db, $partner_id,
                'Novo pedido!',
                "Pedido #$order_number - R$ " . number_format($total, 2, ',', '.') . " - $customer_name",
                '/painel/mercado/pedidos.php'
            );
            try {
                PusherService::newOrder($partner_id, [
                    'order_id' => $order_id,
                    'order_number' => $order_number,
                    'customer_name' => $customer_name,
                    'total' => round($total, 2),
                    'payment_method' => $payment_method,
                    'is_pickup' => (bool)$is_pickup,
                    'items_count' => count($itens),
                    'created_at' => date('c')
                ]);
            } catch (Exception $pusherErr) {
                error_log("[Checkout] Pusher error: " . $pusherErr->getMessage());
            }
            try {
                om_realtime()->setDb($db);
                om_realtime()->pedidoCriado($order_id, $partner_id, $customer_id, [
                    'order_number' => $order_number,
                    'total' => round($total, 2),
                    'payment_method' => $payment_method
                ]);
            } catch (Exception $rtErr) {
                error_log("[Checkout] Realtime error: " . $rtErr->getMessage());
            }
            try {
                require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
                $partnerPhone = $parceiro['phone'] ?? $parceiro['telefone'] ?? '';
                if ($partnerPhone) {
                    whatsappNewOrderPartner($partnerPhone, $order_number, round($total, 2), $customer_name);
                }
            } catch (Exception $waErr) {
                error_log("[Checkout] WhatsApp partner error: " . $waErr->getMessage());
            }
        }

        // Notificar cliente (push + in-app)
        try {
            $nomeParceiroNotif = $parceiro['trade_name'] ?? $parceiro['name'] ?? 'SuperBora';
            $clientMsg = $payment_method === 'pix'
                ? "Seu pedido #{$order_number} foi criado! Pague o PIX para confirmar."
                : ($is_pickup
                    ? "Seu pedido #{$order_number} no {$nomeParceiroNotif} foi recebido! Avisaremos quando estiver pronto para retirada."
                    : "Seu pedido #{$order_number} no {$nomeParceiroNotif} foi recebido! Acompanhe o preparo pelo app.");
            notifyCustomer($db, $customer_id,
                $payment_method === 'pix' ? 'Pague o PIX!' : 'Pedido confirmado!',
                $clientMsg,
                '/mercado/pedido.php?id=' . $order_id
            );
        } catch (Exception $custNotifErr) {
            error_log("[Checkout] Customer notification error: " . $custNotifErr->getMessage());
        }

        // Enviar email de confirmacao de pedido (async, nao bloqueia resposta)
        try {
            $emailService = new EmailService($db);
            $emailService->sendOrderConfirmation($customer_id, $order_id);
        } catch (Exception $emailErr) {
            error_log("[Checkout] Email error: " . $emailErr->getMessage());
        }

        response(true, $responseData, "Pedido criado com sucesso!");

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[Checkout] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar pedido", 500);
}

/**
 * Gerar PIX via Woovi (OpenPix) API
 */
function gerarPixWoovi($orderId, $total, $name, $email, $phone, $customerId, $cpfNota = '') {
    try {
        require_once dirname(__DIR__, 3) . '/includes/classes/WooviClient.php';
        $woovi = new WooviClient();

        $amountCents = (int)round($total * 100);
        $correlationId = 'order_' . $orderId . '_' . time();
        $comment = "Pedido #{$orderId} - SuperBora Mercado";

        // Build customer data for Woovi
        $cpf = preg_replace('/[^0-9]/', '', $cpfNota ?? '');
        if (strlen($cpf) !== 11) {
            try {
                $dbCpf = getDB();
                $stmtCpf = $dbCpf->prepare("SELECT cpf FROM om_customers WHERE customer_id = ? LIMIT 1");
                $stmtCpf->execute([$customerId]);
                $cpfRow = $stmtCpf->fetch(PDO::FETCH_ASSOC);
                if ($cpfRow && !empty($cpfRow['cpf'])) {
                    $cpf = preg_replace('/[^0-9]/', '', $cpfRow['cpf']);
                }
            } catch (Exception $e) {}
        }

        // Woovi requires at least one identifier: taxID (CPF), email, or phone
        $customerData = [
            'name' => $name ?: 'Cliente SuperBora',
        ];
        if (strlen($cpf) === 11) {
            $customerData['taxID'] = $cpf;
        }
        $phone = preg_replace('/[^0-9]/', '', $phone ?? '');
        if (strlen($phone) >= 10) {
            $customerData['phone'] = '+55' . $phone;
        }
        // Include email as fallback identifier (Woovi rejects if no taxID/email/phone)
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $customerData['email'] = $email;
        }
        // Final fallback: if no identifier at all, use a placeholder email
        if (!isset($customerData['taxID']) && !isset($customerData['phone']) && !isset($customerData['email'])) {
            $customerData['email'] = 'customer' . $customerId . '@superbora.com.br';
        }

        $result = $woovi->createCharge($amountCents, $correlationId, $comment, 600, $customerData);
        $chargeData = $result['data'] ?? [];
        $charge = $chargeData['charge'] ?? $chargeData;

        $brCode = $charge['brCode'] ?? $charge['pixCopiaECola'] ?? '';
        $qrCodeUrl = $charge['qrCodeImage'] ?? '';
        $chargeId = $charge['correlationID'] ?? $correlationId;

        if (empty($brCode)) {
            error_log("[PIX-Woovi] Charge created but no brCode returned: " . json_encode($chargeData));
            return null;
        }

        // Generate QR code image if Woovi didn't return one
        if (empty($qrCodeUrl)) {
            $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($brCode);
        }

        // Save to pagarme_transacoes table (reuse existing table)
        try {
            $db = getDB();
            $db->prepare("INSERT INTO om_pagarme_transacoes (pedido_id, charge_id, pagarme_order_id, tipo, valor, qr_code, qr_code_url, status, created_at)
                VALUES (?, ?, ?, 'pix', ?, ?, ?, 'pending', NOW())
                "
            )->execute([$orderId, $chargeId, $correlationId, $total, $brCode, $qrCodeUrl]);
        } catch (Exception $e) {
            error_log("[PIX-Woovi] transacoes save error: " . $e->getMessage());
        }

        return [
            "qr_code" => $brCode,
            "qr_code_url" => $qrCodeUrl,
            "qr_code_text" => $brCode,
            "charge_id" => $chargeId,
            "expiration" => date('c', strtotime('+10 minutes'))
        ];
    } catch (Exception $e) {
        error_log("[PIX-Woovi] Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Gerar PIX via Pagar.me v5 API (DESATIVADO — usando Woovi agora)
 */
function gerarPixPagarme($orderId, $total, $name, $email, $phone, $customerId, $cpfNota = '') {
    // Resolver CPF: priorizar cpf_nota do checkout, senao buscar do cadastro
    $cpf = preg_replace('/[^0-9]/', '', $cpfNota ?? '');
    if (strlen($cpf) !== 11) {
        // Buscar CPF do cadastro do cliente
        try {
            $dbCpf = getDB();
            $stmtCpf = $dbCpf->prepare("SELECT cpf FROM om_customers WHERE customer_id = ? LIMIT 1");
            $stmtCpf->execute([$customerId]);
            $cpfRow = $stmtCpf->fetch(PDO::FETCH_ASSOC);
            if ($cpfRow && !empty($cpfRow['cpf'])) {
                $cpf = preg_replace('/[^0-9]/', '', $cpfRow['cpf']);
            }
        } catch (Exception $e) {
            error_log("[PIX-Checkout] Erro ao buscar CPF do cliente {$customerId}: " . $e->getMessage());
        }
    }

    if (strlen($cpf) !== 11) {
        error_log("[PIX-Checkout] CPF nao disponivel para cliente {$customerId} - Pagar.me pode rejeitar");
    }

    // Carregar chave Pagar.me
    $envPath = dirname(dirname(dirname(__DIR__))) . '/.env';
    $pagarmeKey = '';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, 'PAGARME_SECRET_KEY=') === 0) {
                $pagarmeKey = trim(substr($line, strlen('PAGARME_SECRET_KEY=')));
                break;
            }
        }
    }

    if (empty($pagarmeKey)) {
        error_log("[PIX-Checkout] Pagar.me key not configured");
        return null;
    }

    $amountCents = (int)round($total * 100);
    $phone = preg_replace('/[^0-9]/', '', $phone);
    // Normalizar: remover codigo do pais se presente (55XXXXXXXXXXX → XXXXXXXXXXX)
    if (strlen($phone) === 13 && substr($phone, 0, 2) === '55') {
        $phone = substr($phone, 2);
    }

    // Build customer object — Pagar.me v5 REQUIRES document + phones for PIX
    $customerObj = [
        "name" => $name ?: "Cliente SuperBora",
        "email" => $email ?: "cliente@superbora.com.br",
        "type" => "individual",
    ];

    // Document is required for PIX — use customer CPF or fallback
    if (strlen($cpf) === 11) {
        $customerObj["document"] = $cpf;
        $customerObj["document_type"] = "cpf";
    } else {
        // Fallback: use a generic CPF so Pagar.me doesn't reject
        // This is common practice for marketplaces where CPF is optional at signup
        $customerObj["document"] = "00000000000";
        $customerObj["document_type"] = "cpf";
        error_log("[PIX-Checkout] Using fallback CPF for customer {$customerId}");
    }

    // Phone is required — normalize and provide fallback
    if (strlen($phone) >= 10) {
        $customerObj["phones"] = [
            "mobile_phone" => [
                "country_code" => "55",
                "area_code" => substr($phone, 0, 2),
                "number" => substr($phone, 2)
            ]
        ];
    } else {
        // Fallback phone so Pagar.me doesn't reject
        $customerObj["phones"] = [
            "mobile_phone" => [
                "country_code" => "55",
                "area_code" => "11",
                "number" => "999999999"
            ]
        ];
        error_log("[PIX-Checkout] Using fallback phone for customer {$customerId}");
    }

    $payload = [
        "items" => [
            [
                "amount" => $amountCents,
                "description" => "Pedido #{$orderId} - SuperBora Mercado",
                "quantity" => 1,
                "code" => "ORDER-{$orderId}"
            ]
        ],
        "customer" => $customerObj,
        "payments" => [
            [
                "payment_method" => "pix",
                "pix" => [
                    "expires_in" => 600
                ]
            ]
        ],
        "metadata" => [
            "source" => "superbora_checkout",
            "order_id" => (string)$orderId,
            "customer_id" => (string)$customerId
        ]
    ];

    $ch = curl_init('https://api.pagar.me/core/v5/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($pagarmeKey . ':'),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log only status code and error info (avoid logging full gateway response with sensitive data)
    if ($httpCode >= 400) {
        error_log("[PIX-Checkout] Pagarme HTTP {$httpCode}: " . substr($response, 0, 200));
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($data['charges'][0])) {
        $charge = $data['charges'][0];
        $lastTx = $charge['last_transaction'] ?? [];

        // Check if charge actually succeeded (Pagar.me can return 200 with failed charge)
        $chargeStatus = $charge['status'] ?? 'unknown';
        if ($chargeStatus === 'failed') {
            $gatewayMsg = $lastTx['gateway_response']['errors'][0]['message'] ?? 'Erro no gateway PIX';
            error_log("[PIX-Checkout] Charge FAILED (HTTP 200 but charge failed): {$gatewayMsg}");
            return null;
        }

        // Generate QR code image URL from PIX string (Pagar.me only returns the string)
        $qrCodeStr = $lastTx['qr_code'] ?? '';
        $qrCodeUrl = $lastTx['qr_code_url'] ?? '';

        // If QR code is empty, the charge didn't generate a valid PIX
        if (empty($qrCodeStr)) {
            error_log("[PIX-Checkout] Charge created but no QR code returned. Status: {$chargeStatus}");
            return null;
        }

        if (empty($qrCodeUrl) && !empty($qrCodeStr)) {
            $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($qrCodeStr);
        }

        // Save charge_id to pagarme_transacoes for webhook lookup
        try {
            $db = getDB();
            $db->prepare("INSERT INTO om_pagarme_transacoes (pedido_id, charge_id, pagarme_order_id, tipo, valor, qr_code, qr_code_url, status, created_at)
                VALUES (?, ?, ?, 'pix', ?, ?, ?, 'pending', NOW())
                "
            )->execute([$orderId, $charge['id'] ?? '', $data['id'] ?? '', $total, $qrCodeStr, $qrCodeUrl]);
        } catch (Exception $e) {
            error_log("[PIX-Checkout] transacoes save error: " . $e->getMessage());
        }

        return [
            "qr_code" => $qrCodeStr,
            "qr_code_url" => $qrCodeUrl,
            "qr_code_text" => $qrCodeStr,
            "charge_id" => $charge['id'] ?? '',
            "expiration" => date('c', strtotime('+10 minutes')) // ISO 8601 with timezone
        ];
    }

    error_log("[PIX-Checkout] Pagarme error: " . json_encode($data));
    return null;
}

/**
 * Cobrar cartao de credito via Pagar.me v5 API
 */
function cobrarCartaoPagarme($orderId, $total, $name, $email, $phone, $customerId, $card, $installments = 1) {
    $envPath = dirname(dirname(dirname(__DIR__))) . '/.env';
    $pagarmeKey = '';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, 'PAGARME_SECRET_KEY=') === 0) {
                $pagarmeKey = trim(substr($line, strlen('PAGARME_SECRET_KEY=')));
                break;
            }
        }
    }

    if (empty($pagarmeKey)) {
        error_log("[Card-Checkout] Pagar.me key not configured");
        return ['error' => 'Gateway not configured'];
    }

    $amountCents = (int)round($total * 100);
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $cardNumber = preg_replace('/[^0-9]/', '', $card['number'] ?? '');
    $expiry = $card['expiry'] ?? '';
    $expMonth = (int)substr($expiry, 0, 2);
    $expYear = (int)('20' . substr($expiry, 2, 2));

    $payload = [
        "items" => [
            [
                "amount" => $amountCents,
                "description" => "Pedido #{$orderId} - SuperBora Mercado",
                "quantity" => 1,
                "code" => "ORDER-{$orderId}"
            ]
        ],
        "customer" => [
            "name" => $name ?: "Cliente SuperBora",
            "email" => $email ?: "cliente@superbora.com.br",
            "type" => "individual"
        ],
        "payments" => [
            [
                "payment_method" => "credit_card",
                "credit_card" => [
                    "installments" => max(1, min(12, $installments)),
                    "card" => [
                        "number" => $cardNumber,
                        "holder_name" => $card['name'] ?? $name,
                        "exp_month" => $expMonth,
                        "exp_year" => $expYear,
                        "cvv" => $card['cvv'] ?? ''
                    ]
                ]
            ]
        ],
        "metadata" => [
            "source" => "superbora_checkout",
            "order_id" => (string)$orderId,
            "customer_id" => (string)$customerId
        ]
    ];

    if (strlen($phone) >= 10) {
        $payload["customer"]["phones"] = [
            "mobile_phone" => [
                "country_code" => "55",
                "area_code" => substr($phone, 0, 2),
                "number" => substr($phone, 2)
            ]
        ];
    }

    $ch = curl_init('https://api.pagar.me/core/v5/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($pagarmeKey . ':'),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log only on errors (avoid logging full gateway response with sensitive data)
    if ($httpCode >= 400) {
        error_log("[Card-Checkout] Pagarme HTTP {$httpCode}: " . substr($response, 0, 200));
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($data['charges'][0])) {
        $charge = $data['charges'][0];
        $chargeStatus = $charge['status'] ?? 'pending';

        // Save charge to transacoes
        try {
            $db = getDB();
            $db->prepare("INSERT INTO om_pagarme_transacoes (pedido_id, charge_id, pagarme_order_id, valor, tipo, status, created_at)
                VALUES (?, ?, ?, ?, 'credit_card', ?, NOW())
                "
            )->execute([$orderId, $charge['id'] ?? '', $data['id'] ?? '', $total, $chargeStatus]);
        } catch (Exception $e) {
            error_log("[Card-Checkout] transacoes save error: " . $e->getMessage());
        }

        return [
            'status' => $chargeStatus,
            'charge_id' => $charge['id'] ?? '',
            'pagarme_order_id' => $data['id'] ?? '',
        ];
    }

    error_log("[Card-Checkout] Pagarme error: " . json_encode($data));
    return ['error' => $data['message'] ?? 'Payment gateway error'];
}

/**
 * Verificar status de PaymentIntent no Stripe
 */
function verificarStripePayment($paymentIntentId) {
    $envFile = dirname(dirname(dirname(__DIR__))) . '/.env.stripe';
    $stripeSK = '';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, 'STRIPE_SECRET_KEY=') === 0) {
                $stripeSK = trim(substr($line, strlen('STRIPE_SECRET_KEY=')));
                break;
            }
        }
    }

    if (empty($stripeSK)) {
        error_log("[Stripe-Verify] Stripe secret key not configured");
        return ['paid' => false, 'error' => 'Stripe not configured'];
    }

    // Sanitize PI ID
    $piId = preg_replace('/[^a-zA-Z0-9_]/', '', $paymentIntentId);

    $ch = curl_init("https://api.stripe.com/v1/payment_intents/{$piId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $stripeSK],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    error_log("[Stripe-Verify] PI {$piId}: HTTP {$httpCode}, status=" . ($data['status'] ?? 'null'));

    $piStatus = $data['status'] ?? 'unknown';
    if ($httpCode === 200 && $piStatus === 'succeeded') {
        return [
            'paid' => true,
            'amount' => ($data['amount'] ?? 0) / 100,
            'amount_cents' => (int)($data['amount'] ?? 0),
            'status' => $piStatus,
        ];
    }

    return ['paid' => false, 'status' => $piStatus];
}
