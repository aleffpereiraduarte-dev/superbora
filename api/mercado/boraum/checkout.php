<?php
/**
 * POST /api/mercado/boraum/checkout.php
 * Criar pedido de comida via BoraUm
 *
 * Body: {
 *   partner_id, items[{id, quantity, notes, addons[]}], address_id,
 *   payment_method (pix|credito|saldo|misto), card_id, use_saldo,
 *   coupon_code, notes, delivery_instructions, contactless, tip,
 *   is_pickup, schedule_date, schedule_time
 * }
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../helpers/PromotionsHelper.php';

setCorsHeaders();

try {
    $db = getDB();
    $user = requirePassageiro($db);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();

    // =========================================================================
    // 1. Extrair e validar inputs
    // =========================================================================
    $partnerId      = (int)($input['partner_id'] ?? 0);
    $items          = $input['items'] ?? [];
    $addressId      = (int)($input['address_id'] ?? 0);
    $paymentMethod  = preg_replace('/[^a-z]/', '', $input['payment_method'] ?? 'pix');
    $cardId         = (int)($input['card_id'] ?? 0);
    $useSaldo       = max(0, (float)($input['use_saldo'] ?? 0));
    $couponCode     = strtoupper(trim($input['coupon_code'] ?? ''));
    $notes          = strip_tags(trim(substr($input['notes'] ?? '', 0, 1000)));
    $deliveryInstr  = strip_tags(trim(substr($input['delivery_instructions'] ?? '', 0, 500)));
    $contactless    = (bool)($input['contactless'] ?? false);
    $tip            = max(0, min(500, (float)($input['tip'] ?? 0)));
    $isPickup       = (bool)($input['is_pickup'] ?? false);
    $scheduleDate   = trim($input['schedule_date'] ?? '');
    $scheduleTime   = trim($input['schedule_time'] ?? '');
    $isScheduled    = !empty($scheduleDate) && !empty($scheduleTime);

    if (!$partnerId) {
        response(false, null, "Parceiro (partner_id) e obrigatorio.", 400);
    }

    if (empty($items) || !is_array($items)) {
        response(false, null, "Carrinho vazio. Adicione itens antes de finalizar.", 400);
    }

    // 2. Validar payment_method
    $formasPermitidas = ['pix', 'credito', 'saldo', 'misto'];
    if (!in_array($paymentMethod, $formasPermitidas, true)) {
        response(false, null, "Forma de pagamento invalida. Aceitas: " . implode(', ', $formasPermitidas), 400);
    }

    // =========================================================================
    // 3. Buscar parceiro
    // =========================================================================
    $stmtP = $db->prepare("SELECT partner_id, name, trade_name, logo, categoria, is_open, aberto, horario_funcionamento, delivery_fee, free_delivery_above, min_order_value, min_order, delivery_time_min, auto_accept, lat, lng FROM om_market_partners WHERE partner_id = ? AND status = '1'");
    $stmtP->execute([$partnerId]);
    $parceiro = $stmtP->fetch();

    if (!$parceiro) {
        response(false, null, "Estabelecimento nao disponivel ou desativado.", 400);
    }

    // =========================================================================
    // 4. Verificar se a loja esta aberta (skip para agendados)
    // =========================================================================
    if (!$isScheduled) {
        $isOpen = (bool)($parceiro['is_open'] ?? $parceiro['aberto'] ?? true);
        if (!$isOpen) {
            response(false, null, "Loja fechada no momento. O estabelecimento nao esta aceitando pedidos.", 400);
        }

        // Verificar horario_funcionamento se disponivel
        $horario = $parceiro['horario_funcionamento'] ?? null;
        if (!empty($horario)) {
            $spTz = new DateTimeZone('America/Sao_Paulo');
            $now = new DateTime('now', $spTz);
            $currentTime = $now->format('H:i');

            $dayMap = [
                1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui',
                5 => 'sex', 6 => 'sab', 0 => 'dom',
            ];
            $dayOfWeek = (int)$now->format('w');
            $dayKey = $dayMap[$dayOfWeek] ?? '';
            $todayRange = null;

            $horarioData = is_string($horario) ? json_decode($horario, true) : (is_array($horario) ? $horario : null);

            if (is_array($horarioData)) {
                if (isset($horarioData[$dayKey])) {
                    $todayRange = $horarioData[$dayKey];
                } elseif (isset($horarioData['abertura']) && isset($horarioData['fechamento'])) {
                    $todayRange = $horarioData['abertura'] . '-' . $horarioData['fechamento'];
                }
            } elseif (is_string($horario) && preg_match('/^\d{2}:\d{2}\s*-\s*\d{2}:\d{2}$/', trim($horario))) {
                $todayRange = trim($horario);
            }

            if ($todayRange !== null) {
                $todayRange = trim($todayRange);
                if (strtolower($todayRange) === 'fechado' || empty($todayRange)) {
                    response(false, null, "Loja fechada hoje.", 400);
                }
                if (preg_match('/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', $todayRange, $m)) {
                    $openTime = $m[1];
                    $closeTime = $m[2];
                    $storeOpen = ($closeTime > $openTime)
                        ? ($currentTime >= $openTime && $currentTime < $closeTime)
                        : ($currentTime >= $openTime || $currentTime < $closeTime);
                    if (!$storeOpen) {
                        response(false, null, "Loja fechada no momento. Horario: {$openTime} - {$closeTime}", 400);
                    }
                }
            }
        }
    }

    // =========================================================================
    // 5. Validar endereco (se nao for retirada)
    // =========================================================================
    $addr = null;
    $deliveryAddress = '';
    $shippingData = ['bairro' => '', 'cidade' => '', 'estado' => '', 'cep' => '', 'lat' => null, 'lng' => null];

    if (!$isPickup) {
        // Aceitar endereco por address_id (passageiro cadastrado) OU inline (server-to-server)
        $inlineAddress = trim($input['delivery_address'] ?? $input['endereco'] ?? '');

        if ($addressId && $user['passageiro_id'] > 0) {
            // Buscar endereco salvo do passageiro
            $stmtAddr = $db->prepare("SELECT * FROM om_boraum_passenger_addresses WHERE id = ? AND passageiro_id = ?");
            $stmtAddr->execute([$addressId, $user['passageiro_id']]);
            $addr = $stmtAddr->fetch();

            if (!$addr) {
                response(false, null, "Endereco nao encontrado ou nao pertence ao usuario.", 404);
            }

            $deliveryAddress = trim(
                $addr['endereco']
                . ($addr['complemento'] ? ' - ' . $addr['complemento'] : '')
                . ($addr['bairro'] ? ', ' . $addr['bairro'] : '')
                . ($addr['cidade'] ? ', ' . $addr['cidade'] : '')
                . ($addr['estado'] ? '/' . $addr['estado'] : '')
            );

            $shippingData = [
                'bairro' => $addr['bairro'] ?? '',
                'cidade' => $addr['cidade'] ?? '',
                'estado' => $addr['estado'] ?? '',
                'cep'    => $addr['cep'] ?? '',
                'lat'    => $addr['lat'] ? (float)$addr['lat'] : null,
                'lng'    => $addr['lng'] ? (float)$addr['lng'] : null,
            ];

        } elseif (!empty($inlineAddress)) {
            // Endereco inline (BoraUm server-to-server)
            $deliveryAddress = $inlineAddress;
            $shippingData = [
                'bairro' => trim($input['bairro'] ?? ''),
                'cidade' => trim($input['cidade'] ?? ''),
                'estado' => trim($input['estado'] ?? ''),
                'cep'    => trim($input['cep'] ?? ''),
                'lat'    => isset($input['lat']) ? (float)$input['lat'] : null,
                'lng'    => isset($input['lng']) ? (float)$input['lng'] : null,
            ];

        } else {
            response(false, null, "Endereco de entrega obrigatorio. Envie address_id ou delivery_address.", 400);
        }
    }

    // =========================================================================
    // 6. Validar produtos
    // =========================================================================
    $productIds = array_map(fn($i) => (int)$i['id'], $items);
    $ph = implode(',', array_fill(0, count($productIds), '?'));
    $stmtProd = $db->prepare(
        "SELECT id, name, price, special_price, quantity AS estoque, image, partner_id
         FROM om_market_products
         WHERE id IN ($ph) AND status = '1'"
    );
    $stmtProd->execute($productIds);
    $productsMap = [];
    foreach ($stmtProd->fetchAll() as $p) {
        $productsMap[(int)$p['id']] = $p;
    }

    // =========================================================================
    // 7-8. Checar estoque e calcular subtotal
    // =========================================================================
    $subtotal = 0;
    $addonsTotal = 0;
    $orderItems = [];
    $totalItemsCount = 0;

    // Pre-fetch all addon IDs across all items
    $allAddonIds = [];
    foreach ($items as $item) {
        if (!empty($item['addons']) && is_array($item['addons'])) {
            $allAddonIds = array_merge($allAddonIds, array_map('intval', $item['addons']));
        }
    }
    $allAddonIds = array_unique($allAddonIds);

    // Fetch all addons at once
    $addonsMap = [];
    if (!empty($allAddonIds)) {
        $phAddons = implode(',', array_fill(0, count($allAddonIds), '?'));
        $stmtAddons = $db->prepare("SELECT id, name, price_extra FROM om_product_options WHERE id IN ($phAddons)");
        $stmtAddons->execute(array_values($allAddonIds));
        foreach ($stmtAddons->fetchAll() as $ao) {
            $addonsMap[(int)$ao['id']] = $ao;
        }
    }

    foreach ($items as $item) {
        $pid = (int)$item['id'];
        $qty = max(1, (int)($item['quantity'] ?? 1));
        $itemNotes = strip_tags(trim(substr($item['notes'] ?? '', 0, 500)));

        $prod = $productsMap[$pid] ?? null;
        if (!$prod) {
            response(false, null, "Produto ID $pid nao encontrado ou indisponivel.", 400);
        }

        // Verificar que o produto pertence ao parceiro correto
        if ((int)$prod['partner_id'] !== $partnerId) {
            response(false, null, "Produto '{$prod['name']}' nao pertence a este estabelecimento.", 400);
        }

        // 7. Verificar estoque
        if ($qty > (int)$prod['estoque']) {
            response(false, null, "'{$prod['name']}' - estoque insuficiente (disponivel: {$prod['estoque']})", 400);
        }

        // 8. Preco (usar special_price se existir e for menor)
        $preco = ($prod['special_price'] && (float)$prod['special_price'] > 0 && (float)$prod['special_price'] < (float)$prod['price'])
            ? (float)$prod['special_price']
            : (float)$prod['price'];

        // 10. Calcular addons para este item
        $itemAddonsTotal = 0;
        $itemAddonsList = [];
        if (!empty($item['addons']) && is_array($item['addons'])) {
            foreach ($item['addons'] as $addonId) {
                $addonId = (int)$addonId;
                $addon = $addonsMap[$addonId] ?? null;
                if ($addon) {
                    $addonPrice = (float)$addon['price_extra'];
                    $itemAddonsTotal += $addonPrice;
                    $itemAddonsList[] = [
                        'id' => $addonId,
                        'name' => $addon['name'],
                        'price' => $addonPrice,
                    ];
                }
            }
        }

        $itemTotal = ($preco + $itemAddonsTotal) * $qty;
        $subtotal += $itemTotal;
        $addonsTotal += $itemAddonsTotal * $qty;
        $totalItemsCount += $qty;

        $orderItems[] = [
            'product_id' => $pid,
            'name'       => $prod['name'],
            'quantity'   => $qty,
            'price'      => $preco,
            'addons'     => $itemAddonsList,
            'addons_total' => $itemAddonsTotal,
            'total'      => $itemTotal,
            'image'      => $prod['image'] ?? null,
            'notes'      => $itemNotes,
        ];
    }

    // =========================================================================
    // 9. Pedido minimo
    // =========================================================================
    $pedidoMinimo = (float)($parceiro['min_order_value'] ?? $parceiro['min_order'] ?? 0);
    if ($subtotal < $pedidoMinimo) {
        response(false, null, "Pedido minimo: R$ " . number_format($pedidoMinimo, 2, ',', '.'), 400);
    }

    // =========================================================================
    // Calcular taxas
    // =========================================================================
    $deliveryFee = $isPickup ? 0 : (float)($parceiro['delivery_fee'] ?? 0);

    // Entrega gratis acima de X
    $freeDeliveryAbove = (float)($parceiro['free_delivery_above'] ?? 0);
    if (!$isPickup && $freeDeliveryAbove > 0 && $subtotal >= $freeDeliveryAbove) {
        $deliveryFee = 0;
    }

    $serviceFee = 2.49;

    // =========================================================================
    // Aplicar Promocoes Automaticas (Happy Hour, BOGO, etc.)
    // =========================================================================
    $promotionDiscount = 0;
    $promotionsApplied = [];
    $savingsBreakdown = [];

    try {
        $promoHelper = PromotionsHelper::getInstance($db);
        $promoResult = $promoHelper->applyPromotionsToCart($partnerId, $orderItems, $user['customer_id']);

        $promotionDiscount = $promoResult['total_discount'];
        $promotionsApplied = $promoResult['promotions_applied'];
        $savingsBreakdown = $promoResult['savings_breakdown'];

        // Atualizar orderItems com descontos aplicados
        $orderItems = $promoResult['items'];
    } catch (Exception $e) {
        // Log erro mas continua sem promocoes
        error_log("[BoraUm Checkout] Promocoes erro: " . $e->getMessage());
    }

    // =========================================================================
    // Validar cupom
    // =========================================================================
    $couponId = 0;
    $couponDiscount = 0;

    if (!empty($couponCode)) {
        $stmtC = $db->prepare("SELECT * FROM om_market_coupons WHERE code = ? AND status = 'active'");
        $stmtC->execute([$couponCode]);
        $coupon = $stmtC->fetch();

        if ($coupon) {
            // Verificar validade (se tiver data de expiracao)
            if (!empty($coupon['valid_until']) && strtotime($coupon['valid_until']) < time()) {
                // Cupom expirado - ignorar silenciosamente
                $coupon = null;
            }

            // Verificar limite de uso
            if ($coupon && isset($coupon['max_uses']) && $coupon['max_uses'] > 0 && ($coupon['current_uses'] ?? 0) >= $coupon['max_uses']) {
                $coupon = null;
            }

            // Verificar pedido minimo do cupom
            if ($coupon && isset($coupon['min_order_value']) && (float)$coupon['min_order_value'] > 0 && $subtotal < (float)$coupon['min_order_value']) {
                $coupon = null;
            }
        }

        if ($coupon) {
            $couponId = (int)$coupon['id'];
            switch ($coupon['discount_type'] ?? 'fixed') {
                case 'percentage':
                    $couponDiscount = round($subtotal * (float)$coupon['discount_value'] / 100, 2);
                    if (!empty($coupon['max_discount']) && $couponDiscount > (float)$coupon['max_discount']) {
                        $couponDiscount = (float)$coupon['max_discount'];
                    }
                    break;

                case 'fixed':
                    $couponDiscount = min((float)$coupon['discount_value'], $subtotal);
                    break;

                case 'free_delivery':
                    $deliveryFee = 0;
                    $couponDiscount = 0;
                    break;
            }
        }
    }

    // =========================================================================
    // Calcular total (incluindo desconto de promocoes)
    // =========================================================================
    $totalDiscount = $couponDiscount + $promotionDiscount;
    $total = $subtotal - $totalDiscount + $deliveryFee + $serviceFee + $tip;
    if ($total < 0) $total = 0;

    // =========================================================================
    // Payment Logic - Saldo / Misto
    // =========================================================================
    $saldoUsed = 0;
    $remainderAmount = $total;

    if ($paymentMethod === 'saldo') {
        if ($user['passageiro_id'] <= 0) {
            response(false, null, "Pagamento por saldo nao disponivel para este tipo de conta. Use pix ou credito.", 400);
        }
        if ($total > $user['saldo']) {
            response(false, null, "Saldo insuficiente. Seu saldo: R$ " . number_format($user['saldo'], 2, ',', '.') . ". Total: R$ " . number_format($total, 2, ',', '.'), 400);
        }
        $saldoUsed = $total;
        $remainderAmount = 0;

    } elseif ($paymentMethod === 'misto') {
        if ($user['passageiro_id'] <= 0) {
            response(false, null, "Pagamento misto com saldo nao disponivel para este tipo de conta. Use pix ou credito.", 400);
        }
        $saldoUsed = min($useSaldo, $user['saldo'], $total);
        $remainderAmount = $total - $saldoUsed;

        if ($saldoUsed <= 0) {
            response(false, null, "Para pagamento misto, informe use_saldo > 0.", 400);
        }

        if ($remainderAmount <= 0) {
            // Se o saldo cobre tudo, converter para pagamento por saldo
            $saldoUsed = $total;
            $remainderAmount = 0;
        }
    }

    // Validar cartao de credito (se necessario)
    if ($paymentMethod === 'credito' || ($paymentMethod === 'misto' && $remainderAmount > 0)) {
        if ($cardId) {
            $stmtCard = $db->prepare("SELECT id, bandeira, ultimos4 FROM om_boraum_passenger_cards WHERE id = ? AND passageiro_id = ?");
            $stmtCard->execute([$cardId, $user['passageiro_id']]);
            $savedCard = $stmtCard->fetch();
            if (!$savedCard) {
                response(false, null, "Cartao de credito nao encontrado.", 404);
            }
        }
    }

    // =========================================================================
    // Gerar identificadores
    // =========================================================================
    $orderNumber = 'BU-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $codigoEntrega = strtoupper(bin2hex(random_bytes(3)));

    // Map payment method para enum do banco
    $paymentEnum = match($paymentMethod) {
        'pix'     => 'pix',
        'credito' => 'credit_card',
        'saldo'   => 'wallet',
        'misto'   => 'wallet_mix',
        default   => 'pix',
    };

    // Auto-accept
    $autoAccept = (bool)($parceiro['auto_accept'] ?? false);
    $initialStatus = $autoAccept ? 'aceito' : 'pending';

    $partnerDisplayName = $parceiro['trade_name'] ?: ($parceiro['name'] ?? 'Loja');
    $partnerCategoria = $parceiro['categoria'] ?? 'restaurante';

    // =========================================================================
    // TRANSACAO - Criar pedido
    // =========================================================================
    $db->beginTransaction();

    try {
        // ── Inserir pedido ──────────────────────────────────────────────
        $stmtOrder = $db->prepare("INSERT INTO om_market_orders (
            order_number, market_id, partner_id, customer_id,
            customer_name, customer_phone, customer_email, customer_document,
            status, payment_method, forma_pagamento, payment_status,
            subtotal, delivery_fee, service_fee, discount, total, tip_amount,
            delivery_address, shipping_address, shipping_neighborhood,
            shipping_city, shipping_state, shipping_cep, shipping_lat, shipping_lng,
            notes, delivery_instructions, contactless,
            codigo_entrega, delivery_code, verification_code,
            coupon_id, coupon_discount,
            is_pickup, partner_name, partner_categoria, items_count,
            is_scheduled, schedule_date, schedule_time,
            source, date_added, created_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, 'pending',
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            'boraum', NOW(), NOW()
        ) RETURNING order_id");

        $stmtOrder->execute([
            $orderNumber, $partnerId, $partnerId, $user['customer_id'],
            $user['nome'], $user['telefone'], $user['email'] ?? null, $user['cpf'] ?? null,
            $initialStatus, $paymentEnum, $paymentMethod,
            round($subtotal, 2), round($deliveryFee, 2), $serviceFee, round($couponDiscount, 2), round($total, 2), round($tip, 2),
            $deliveryAddress, $deliveryAddress, $shippingData['bairro'],
            $shippingData['cidade'], $shippingData['estado'], $shippingData['cep'], $shippingData['lat'], $shippingData['lng'],
            $notes, $deliveryInstr ?: null, $contactless ? 1 : 0,
            $codigoEntrega, $codigoEntrega, $codigoEntrega,
            $couponId ?: null, round($couponDiscount, 2),
            $isPickup ? 1 : 0, $partnerDisplayName, $partnerCategoria, $totalItemsCount,
            $isScheduled ? 1 : 0, $scheduleDate ?: null, $scheduleTime ?: null,
        ]);

        $orderId = (int)$stmtOrder->fetch()['order_id'];

        // ── Inserir itens do pedido ─────────────────────────────────────
        $stmtItem = $db->prepare(
            "INSERT INTO om_market_order_items (order_id, product_id, product_name, quantity, price, total, product_image, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($orderItems as $oi) {
            $stmtItem->execute([
                $orderId,
                $oi['product_id'],
                $oi['name'],
                $oi['quantity'],
                $oi['price'],
                $oi['total'],
                $oi['image'] ?? null,
                $oi['notes'] ?: null,
            ]);

            // ── Decrementar estoque (atomic check) ──────────────────────
            $stmtStock = $db->prepare("UPDATE om_market_products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
            $stmtStock->execute([$oi['quantity'], $oi['product_id'], $oi['quantity']]);
            if ($stmtStock->rowCount() === 0) {
                throw new Exception("Estoque insuficiente para '{$oi['name']}' no momento da compra.");
            }
        }

        // ── Registrar uso do cupom (atomic check) ────────────────────────
        if ($couponId) {
            $stmtCoupon = $db->prepare("UPDATE om_market_coupons SET current_uses = current_uses + 1 WHERE id = ? AND (max_uses = 0 OR max_uses IS NULL OR current_uses < max_uses)");
            $stmtCoupon->execute([$couponId]);
            if ($stmtCoupon->rowCount() === 0) {
                throw new Exception("Cupom esgotado. Limite de uso atingido.");
            }
            $db->prepare("INSERT INTO om_market_coupon_usage (coupon_id, customer_id, order_id) VALUES (?, ?, ?)")
                ->execute([$couponId, $user['customer_id'], $orderId]);
        }

        // ── Debitar saldo (se usado) ────────────────────────────────────
        if ($saldoUsed > 0) {
            $stmtSaldo = $db->prepare("UPDATE boraum_passageiros SET saldo = saldo - ? WHERE id = ? AND saldo >= ?");
            $stmtSaldo->execute([$saldoUsed, $user['passageiro_id'], $saldoUsed]);

            if ($stmtSaldo->rowCount() === 0) {
                throw new Exception("Saldo insuficiente no momento do debito.");
            }

            $newSaldo = $user['saldo'] - $saldoUsed;

            $db->prepare(
                "INSERT INTO om_boraum_passenger_wallet (passageiro_id, tipo, valor, descricao, referencia, saldo_apos, created_at)
                 VALUES (?, 'debit', ?, ?, ?, ?, NOW())"
            )->execute([
                $user['passageiro_id'],
                $saldoUsed,
                "Pedido #$orderNumber",
                "order:$orderId",
                $newSaldo,
            ]);
        }

        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        error_log("[BoraUm Checkout] Transaction error: " . $e->getMessage());
        response(false, null, "Erro ao criar pedido. Tente novamente.", 500);
    }

    // =========================================================================
    // Gerar dados de pagamento PIX (se aplicavel)
    // =========================================================================
    $pixData = null;
    if ($paymentMethod === 'pix' || ($paymentMethod === 'misto' && $remainderAmount > 0)) {
        $pixCode = "00020126580014br.gov.bcb.pix0136" . bin2hex(random_bytes(16));
        $pixData = [
            "qr_code"      => $pixCode . "5204000053039865802BR5913SuperBora6008SaoPaulo62070503***6304",
            "qr_code_text"  => $pixCode,
            "expiration"    => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        ];
    }

    // =========================================================================
    // Notificacoes (nao-criticas)
    // =========================================================================
    $totalFormatted = number_format($total, 2, ',', '.');

    try {
        require_once __DIR__ . '/../config/notify.php';
        sendNotification($db, $partnerId, 'partner', 'Novo pedido BoraUm!',
            "Pedido #$orderNumber - R$ $totalFormatted",
            ['order_id' => $orderId, 'url' => '/pedidos']
        );
    } catch (Exception $e) {
        error_log("[BoraUm Checkout] Notification error: " . $e->getMessage());
    }

    try {
        require_once __DIR__ . '/../helpers/NotificationSender.php';
        $notifSender = NotificationSender::getInstance($db);
        $notifSender->notifyPartner($partnerId, "Novo pedido BoraUm #$orderNumber!",
            "R$ $totalFormatted - " . $user['nome'],
            ['order_id' => $orderId, 'order_number' => $orderNumber, 'url' => '/pedidos', 'type' => 'new_order']
        );
    } catch (Exception $e) {
        error_log("[BoraUm Checkout] FCM error: " . $e->getMessage());
    }

    // =========================================================================
    // Registrar uso de promocoes (apos commit bem-sucedido)
    // =========================================================================
    if (!empty($promotionsApplied) && $orderId) {
        try {
            $promoHelper->recordPromotionUsage($promotionsApplied, $user['customer_id'], $orderId);
        } catch (Exception $e) {
            error_log("[BoraUm Checkout] Registro promocao erro: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Resposta
    // =========================================================================
    $responseData = [
        "order_id"        => $orderId,
        "order_number"    => $orderNumber,
        "codigo_entrega"  => $codigoEntrega,
        "status"          => $initialStatus,
        "subtotal"        => round($subtotal, 2),
        "delivery_fee"    => round($deliveryFee, 2),
        "service_fee"     => $serviceFee,
        "tip"             => round($tip, 2),
        "coupon_discount" => round($couponDiscount, 2),
        "promotion_discount" => round($promotionDiscount, 2),
        "total_discount"  => round($totalDiscount, 2),
        "saldo_usado"     => round($saldoUsed, 2),
        "total"           => round($total, 2),
        "payment_method"  => $paymentMethod,
        "partner"         => [
            "id"   => $partnerId,
            "name" => $partnerDisplayName,
        ],
    ];

    // Adicionar detalhes de promocoes se houver economia
    if ($promotionDiscount > 0) {
        $responseData["promotions"] = [
            "applied" => $promotionsApplied,
            "savings" => $savingsBreakdown,
            "total_savings" => round($promotionDiscount, 2),
        ];
    }

    if ($pixData) {
        $responseData["pix"] = $pixData;
    }

    response(true, $responseData, "Pedido criado com sucesso!");

} catch (Exception $e) {
    error_log("[BoraUm Checkout] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar pedido. Tente novamente.", 500);
}
