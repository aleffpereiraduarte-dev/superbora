<?php
/**
 * Guards: Race condition protection & validation middleware
 * Include this file and call guard functions from any endpoint.
 *
 * Todas as funcoes recebem $db (PDO) como primeiro parametro.
 * Usam transacoes + SELECT FOR UPDATE para prevenir race conditions.
 *
 * Uso:
 *   require_once __DIR__ . '/config/guards.php';
 *   $db = getDB();
 *
 *   // Debitar wallet com protecao contra saldo negativo
 *   $result = guard_wallet_debit($db, $customerId, 25.00, 'Pagamento pedido #123', 'order:123');
 *
 *   // Decrementar estoque atomicamente
 *   guard_stock_decrement($db, $productId, 3);
 *
 *   // Validar cupom com lock exclusivo
 *   guard_coupon_redeem($db, $couponId, $customerId, $orderId);
 *
 * @author  Sistema OneMundo
 * @version 1.0.0
 */

// Evitar inclusao dupla
if (defined('GUARDS_LOADED')) return;
define('GUARDS_LOADED', true);

// ══════════════════════════════════════════════════════════════════════════════
// FLAG INTERNA - Constraints aplicadas apenas uma vez por request
// ══════════════════════════════════════════════════════════════════════════════

/** @var bool Flag interna para garantir que constraints sao aplicadas apenas uma vez */
$_guards_constraints_ensured = false;

// ══════════════════════════════════════════════════════════════════════════════
// ENSURE CONSTRAINTS - Chamada automatica na inclusao do arquivo
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Garante que CHECK constraints existem nas tabelas criticas.
 * Executa ALTER TABLE ADD CONSTRAINT IF NOT EXISTS para prevenir
 * saldos negativos e estoque negativo no nivel do banco.
 *
 * Seguro para chamar multiplas vezes - usa IF NOT EXISTS.
 *
 * @param PDO $db Conexao PDO com PostgreSQL
 * @return void
 */
function guard_ensure_constraints(PDO $db): void {
    // Tables om_checkout_locks and constraints (check_balance, check_qty) created via migration
    global $_guards_constraints_ensured;
    $_guards_constraints_ensured = true;
    return;
}

// ══════════════════════════════════════════════════════════════════════════════
// 1. GUARD WALLET DEBIT - Debito atomico com FOR UPDATE
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Debita valor da wallet do cliente com protecao contra race condition.
 * Usa SELECT FOR UPDATE para garantir que apenas uma transacao por vez
 * altere o saldo. Valida saldo suficiente antes de debitar.
 *
 * IMPORTANTE: Esta funcao gerencia sua propria transacao.
 * Nao chame dentro de outra transacao ativa.
 *
 * @param PDO    $db          Conexao PDO
 * @param int    $customerId  ID do cliente
 * @param float  $amount      Valor a debitar (positivo)
 * @param string $description Descricao da transacao
 * @param string $reference   Referencia (ex: 'order:123', 'refund:456')
 *
 * @return array ['success' => true, 'new_balance' => float]
 * @throws \Exception Se saldo insuficiente ou wallet nao encontrada
 */
function guard_wallet_debit(PDO $db, int $customerId, float $amount, string $description, string $reference): array {
    if ($amount <= 0) {
        throw new \Exception("Valor de debito deve ser positivo");
    }

    $db->beginTransaction();
    try {
        // Lock exclusivo na wallet do cliente
        $stmt = $db->prepare("
            SELECT balance FROM om_customer_wallet
            WHERE customer_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$customerId]);
        $wallet = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$wallet) {
            throw new \Exception("Wallet nao encontrada para cliente #{$customerId}");
        }

        $balanceBefore = (float)$wallet['balance'];

        if ($balanceBefore < $amount) {
            throw new \Exception(
                "Saldo insuficiente. Disponivel: R$ " . number_format($balanceBefore, 2, ',', '.') .
                " | Necessario: R$ " . number_format($amount, 2, ',', '.')
            );
        }

        $balanceAfter = round($balanceBefore - $amount, 2);

        // Debitar saldo
        $stmtUpdate = $db->prepare("
            UPDATE om_customer_wallet
            SET balance = ?
            WHERE customer_id = ?
        ");
        $stmtUpdate->execute([$balanceAfter, $customerId]);

        // Registrar transacao
        $stmtTx = $db->prepare("
            INSERT INTO om_wallet_transactions
            (wallet_id, customer_id, type, amount, balance_before, balance_after, description, reference, created_at)
            SELECT wallet_id, customer_id, 'debit', ?, ?, ?, ?, ?, NOW()
            FROM om_customer_wallet
            WHERE customer_id = ?
        ");
        $stmtTx->execute([$amount, $balanceBefore, $balanceAfter, $description, $reference, $customerId]);

        $db->commit();

        error_log("[guards] Wallet debit: customer#{$customerId} valor={$amount} saldo_antes={$balanceBefore} saldo_depois={$balanceAfter}");

        return [
            'success'        => true,
            'new_balance'    => $balanceAfter,
            'balance_before' => $balanceBefore,
            'amount'         => $amount,
        ];

    } catch (\Exception $e) {
        $db->rollBack();
        error_log("[guards] Wallet debit falhou: customer#{$customerId} valor={$amount} erro=" . $e->getMessage());
        throw $e;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 2. GUARD WALLET CREDIT - Credito atomico, cria wallet se necessario
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Credita valor na wallet do cliente. Cria a wallet se nao existir.
 * Usa SELECT FOR UPDATE se wallet ja existe para garantir atomicidade.
 *
 * @param PDO    $db          Conexao PDO
 * @param int    $customerId  ID do cliente
 * @param float  $amount      Valor a creditar (positivo)
 * @param int    $orderId     ID do pedido relacionado (0 se nao houver)
 * @param string $description Descricao da transacao
 * @param string $reference   Referencia (ex: 'cashback:123', 'refund:456')
 *
 * @return array ['success' => true, 'new_balance' => float]
 * @throws \Exception Em caso de erro no banco
 */
function guard_wallet_credit(PDO $db, int $customerId, float $amount, int $orderId, string $description, string $reference): array {
    if ($amount <= 0) {
        throw new \Exception("Valor de credito deve ser positivo");
    }

    $db->beginTransaction();
    try {
        // Tentar obter lock na wallet existente
        $stmt = $db->prepare("
            SELECT wallet_id, balance FROM om_customer_wallet
            WHERE customer_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$customerId]);
        $wallet = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($wallet) {
            // Wallet existe - creditar
            $balanceBefore = (float)$wallet['balance'];
            $balanceAfter = round($balanceBefore + $amount, 2);
            $walletId = (int)$wallet['wallet_id'];

            $stmtUpdate = $db->prepare("
                UPDATE om_customer_wallet
                SET balance = ?, total_earned = total_earned + ?
                WHERE customer_id = ?
            ");
            $stmtUpdate->execute([$balanceAfter, $amount, $customerId]);
        } else {
            // Criar wallet nova
            $balanceBefore = 0.00;
            $balanceAfter = round($amount, 2);

            $stmtInsert = $db->prepare("
                INSERT INTO om_customer_wallet (customer_id, balance, cashback_balance, points, total_earned, created_at)
                VALUES (?, ?, 0, 0, ?, NOW())
                RETURNING wallet_id
            ");
            $stmtInsert->execute([$customerId, $balanceAfter, $amount]);
            $row = $stmtInsert->fetch(\PDO::FETCH_ASSOC);
            $walletId = (int)$row['wallet_id'];
        }

        // Registrar transacao
        $stmtTx = $db->prepare("
            INSERT INTO om_wallet_transactions
            (wallet_id, customer_id, order_id, type, amount, balance_before, balance_after, description, reference, created_at)
            VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, NOW())
        ");
        $stmtTx->execute([
            $walletId,
            $customerId,
            $orderId > 0 ? $orderId : null,
            $amount,
            $balanceBefore,
            $balanceAfter,
            $description,
            $reference,
        ]);

        $db->commit();

        error_log("[guards] Wallet credit: customer#{$customerId} valor={$amount} saldo_antes={$balanceBefore} saldo_depois={$balanceAfter}");

        return [
            'success'        => true,
            'new_balance'    => $balanceAfter,
            'balance_before' => $balanceBefore,
            'amount'         => $amount,
        ];

    } catch (\Exception $e) {
        $db->rollBack();
        error_log("[guards] Wallet credit falhou: customer#{$customerId} valor={$amount} erro=" . $e->getMessage());
        throw $e;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 3. GUARD STOCK DECREMENT - Decremento atomico de estoque
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Decrementa estoque de um produto atomicamente.
 * Usa UPDATE com WHERE quantity >= ? para garantir que nunca fica negativo.
 * Verifica rowCount() para confirmar que o UPDATE afetou exatamente 1 linha.
 *
 * @param PDO $db        Conexao PDO
 * @param int $productId ID do produto
 * @param int $quantity  Quantidade a decrementar
 *
 * @return bool true se decrementado com sucesso
 * @throws \Exception Se estoque insuficiente ou produto nao encontrado
 */
function guard_stock_decrement(PDO $db, int $productId, int $quantity): bool {
    if ($quantity <= 0) {
        throw new \Exception("Quantidade deve ser positiva");
    }

    $stmt = $db->prepare("
        UPDATE om_market_products
        SET quantity = quantity - ?
        WHERE product_id = ?
          AND quantity >= ?
    ");
    $stmt->execute([$quantity, $productId, $quantity]);

    if ($stmt->rowCount() !== 1) {
        throw new \Exception("Estoque insuficiente para produto #{$productId}");
    }

    error_log("[guards] Stock decrement: produto#{$productId} qtd={$quantity}");
    return true;
}

// ══════════════════════════════════════════════════════════════════════════════
// 4. GUARD STOCK RESTORE - Restaurar estoque (cancelamento)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Restaura estoque de um produto (usado em cancelamentos).
 *
 * @param PDO $db        Conexao PDO
 * @param int $productId ID do produto
 * @param int $quantity  Quantidade a restaurar
 *
 * @return bool true se restaurado com sucesso
 */
function guard_stock_restore(PDO $db, int $productId, int $quantity): bool {
    if ($quantity <= 0) {
        return false;
    }

    $stmt = $db->prepare("
        UPDATE om_market_products
        SET quantity = quantity + ?
        WHERE product_id = ?
    ");
    $stmt->execute([$quantity, $productId]);

    error_log("[guards] Stock restore: produto#{$productId} qtd={$quantity}");
    return true;
}

// ══════════════════════════════════════════════════════════════════════════════
// 5. GUARD COUPON REDEEM - Resgate atomico de cupom
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Valida e registra uso de cupom com SELECT FOR UPDATE para prevenir
 * uso simultaneo que ultrapasse limites.
 *
 * Validacoes:
 *   - Cupom ativo e dentro do periodo de validade
 *   - Limite global de usos (max_uses) nao excedido
 *   - Limite por usuario (max_uses_per_user) nao excedido
 *   - INSERT com ON CONFLICT DO NOTHING para prevenir duplicatas
 *
 * @param PDO $db         Conexao PDO
 * @param int $couponId   ID do cupom
 * @param int $customerId ID do cliente
 * @param int $orderId    ID do pedido
 *
 * @return bool true se cupom resgatado com sucesso
 * @throws \Exception Se cupom invalido, expirado ou limite excedido
 */
function guard_coupon_redeem(PDO $db, int $couponId, int $customerId, int $orderId): bool {
    $db->beginTransaction();
    try {
        // Lock exclusivo no cupom
        $stmt = $db->prepare("
            SELECT * FROM om_market_coupons
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([$couponId]);
        $coupon = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$coupon) {
            throw new \Exception("Cupom #{$couponId} nao encontrado");
        }

        // Verificar status
        if (($coupon['status'] ?? '') !== 'active') {
            throw new \Exception("Cupom inativo");
        }

        // Verificar datas de validade
        $now = date('Y-m-d H:i:s');
        if (!empty($coupon['valid_from']) && $now < $coupon['valid_from']) {
            throw new \Exception("Cupom ainda nao esta ativo");
        }
        if (!empty($coupon['valid_until']) && $now > $coupon['valid_until']) {
            throw new \Exception("Cupom expirado");
        }

        // Verificar limite global de usos
        if (!empty($coupon['max_uses']) && (int)$coupon['max_uses'] > 0) {
            $stmtCount = $db->prepare("
                SELECT COUNT(*) FROM om_market_coupon_usage
                WHERE coupon_id = ?
            ");
            $stmtCount->execute([$couponId]);
            $totalUses = (int)$stmtCount->fetchColumn();

            if ($totalUses >= (int)$coupon['max_uses']) {
                throw new \Exception("Cupom esgotado (limite de {$coupon['max_uses']} usos atingido)");
            }
        }

        // Verificar limite por usuario
        if (!empty($coupon['max_uses_per_user']) && (int)$coupon['max_uses_per_user'] > 0) {
            $stmtUser = $db->prepare("
                SELECT COUNT(*) FROM om_market_coupon_usage
                WHERE coupon_id = ? AND customer_id = ?
            ");
            $stmtUser->execute([$couponId, $customerId]);
            $userUses = (int)$stmtUser->fetchColumn();

            if ($userUses >= (int)$coupon['max_uses_per_user']) {
                throw new \Exception("Voce ja usou este cupom o maximo de vezes permitido");
            }
        }

        // Verificar first_order_only
        if (!empty($coupon['first_order_only']) && (int)$coupon['first_order_only'] === 1) {
            $stmtOrders = $db->prepare("
                SELECT COUNT(*) FROM om_market_orders
                WHERE customer_id = ? AND status NOT IN ('cancelado', 'cancelled')
            ");
            $stmtOrders->execute([$customerId]);
            $orderCount = (int)$stmtOrders->fetchColumn();

            if ($orderCount > 0) {
                throw new \Exception("Cupom valido apenas para primeiro pedido");
            }
        }

        // Registrar uso com ON CONFLICT DO NOTHING para prevenir duplicata
        $stmtInsert = $db->prepare("
            INSERT INTO om_market_coupon_usage (coupon_id, customer_id, order_id, created_at)
            VALUES (?, ?, ?, NOW())
            ON CONFLICT DO NOTHING
        ");
        $stmtInsert->execute([$couponId, $customerId, $orderId]);

        if ($stmtInsert->rowCount() === 0) {
            // Conflito: cupom ja foi usado para este pedido/cliente
            throw new \Exception("Cupom ja registrado para este pedido");
        }

        $db->commit();

        error_log("[guards] Coupon redeemed: cupom#{$couponId} cliente#{$customerId} pedido#{$orderId}");
        return true;

    } catch (\Exception $e) {
        $db->rollBack();
        error_log("[guards] Coupon redeem falhou: cupom#{$couponId} cliente#{$customerId} erro=" . $e->getMessage());
        throw $e;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// 6. GUARD REFUND VALIDATE - Validar valor de reembolso
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Valida que o valor de reembolso solicitado nao excede o maximo permitido.
 * Calcula: max_refundable = total + delivery_fee - already_refunded
 *
 * Nao altera dados - apenas consulta e valida.
 *
 * @param PDO   $db      Conexao PDO
 * @param int   $orderId ID do pedido
 * @param float $amount  Valor do reembolso solicitado
 *
 * @return array ['max_refundable' => float, 'already_refunded' => float]
 * @throws \Exception Se pedido nao encontrado ou valor excede maximo
 */
function guard_refund_validate(PDO $db, int $orderId, float $amount): array {
    // Buscar valores do pedido
    $stmt = $db->prepare("
        SELECT total, delivery_fee
        FROM om_market_orders
        WHERE order_id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$order) {
        throw new \Exception("Pedido #{$orderId} nao encontrado");
    }

    $orderTotal = (float)$order['total'];
    $deliveryFee = (float)$order['delivery_fee'];

    // Calcular total ja reembolsado (excluindo falhas e rejeicoes)
    $stmtRefunds = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as already_refunded
        FROM om_market_refunds
        WHERE order_id = ?
          AND status NOT IN ('failed', 'rejected')
    ");
    $stmtRefunds->execute([$orderId]);
    $alreadyRefunded = (float)$stmtRefunds->fetch(\PDO::FETCH_ASSOC)['already_refunded'];

    $maxRefundable = round($orderTotal + $deliveryFee - $alreadyRefunded, 2);

    if ($amount > $maxRefundable) {
        throw new \Exception(
            "Valor de reembolso excede o maximo permitido. " .
            "Solicitado: R$ " . number_format($amount, 2, ',', '.') .
            " | Maximo: R$ " . number_format($maxRefundable, 2, ',', '.') .
            " (ja reembolsado: R$ " . number_format($alreadyRefunded, 2, ',', '.') . ")"
        );
    }

    error_log("[guards] Refund validate: pedido#{$orderId} valor={$amount} max={$maxRefundable} ja_reembolsado={$alreadyRefunded}");

    return [
        'max_refundable'   => $maxRefundable,
        'already_refunded' => $alreadyRefunded,
        'order_total'      => $orderTotal,
        'delivery_fee'     => $deliveryFee,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// 7. GUARD STORE OPEN - Verificar se loja esta aberta
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Verifica se uma loja parceira esta aberta para pedidos.
 * Auto-resume: se pause_until ja passou, reativa a loja automaticamente.
 *
 * @param PDO $db        Conexao PDO
 * @param int $partnerId ID do parceiro
 *
 * @return bool true se loja esta aberta
 * @throws \Exception Se loja nao encontrada ou fechada
 */
function guard_store_open(PDO $db, int $partnerId): bool {
    $stmt = $db->prepare("
        SELECT is_open, pause_until, status
        FROM om_market_partners
        WHERE partner_id = ?
    ");
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$partner) {
        throw new \Exception("Loja #{$partnerId} nao encontrada");
    }

    // Verificar status geral do parceiro
    if (isset($partner['status']) && $partner['status'] !== 'active' && $partner['status'] !== '1') {
        throw new \Exception("Loja fechada");
    }

    $isOpen = (bool)$partner['is_open'];
    $pauseUntil = $partner['pause_until'];

    // Auto-resume: se pause_until expirou, reativar a loja
    if (!$isOpen && $pauseUntil && strtotime($pauseUntil) < time()) {
        $stmtResume = $db->prepare("
            UPDATE om_market_partners
            SET is_open = 1, pause_until = NULL
            WHERE partner_id = ?
        ");
        $stmtResume->execute([$partnerId]);
        $isOpen = true;

        error_log("[guards] Auto-resume loja#{$partnerId}: pause_until={$pauseUntil} expirado");
    }

    // Verificar se esta pausada (pause_until no futuro)
    if (!$isOpen && $pauseUntil && strtotime($pauseUntil) > time()) {
        $resumeTime = date('H:i', strtotime($pauseUntil));
        throw new \Exception("Loja fechada temporariamente. Retorna as {$resumeTime}");
    }

    if (!$isOpen) {
        throw new \Exception("Loja fechada");
    }

    return true;
}

// ══════════════════════════════════════════════════════════════════════════════
// 8. GUARD GEOFENCE DELIVERY - Validar proximidade do BoraUm (motorista)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Verifica se o motorista BoraUm esta dentro do raio permitido do ponto de entrega.
 * Usa formula de Haversine para calculo de distancia geodesica.
 *
 * Nota: Esta funcao e para motoristas BoraUm (delivery), nao para shoppers.
 * Shoppers fazem compras no mercado; BoraUm faz a entrega ao cliente.
 *
 * @param float $driverLat   Latitude do motorista BoraUm
 * @param float $driverLng   Longitude do motorista BoraUm
 * @param float $deliveryLat Latitude do ponto de entrega
 * @param float $deliveryLng Longitude do ponto de entrega
 * @param int   $maxMeters   Raio maximo permitido em metros (padrao: 500)
 *
 * @return bool true se dentro do raio
 * @throws \Exception Se fora do raio (inclui distancia real na mensagem)
 */
function guard_geofence_delivery(float $driverLat, float $driverLng, float $deliveryLat, float $deliveryLng, int $maxMeters = 500): bool {
    // Validar coordenadas basicas
    if ($driverLat == 0 || $driverLng == 0 || $deliveryLat == 0 || $deliveryLng == 0) {
        throw new \Exception("Coordenadas invalidas para verificacao de geofence");
    }

    // Haversine formula
    $earthRadius = 6371000; // raio da Terra em metros

    $latDelta = deg2rad($deliveryLat - $driverLat);
    $lngDelta = deg2rad($deliveryLng - $driverLng);

    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos(deg2rad($driverLat)) * cos(deg2rad($deliveryLat)) *
         sin($lngDelta / 2) * sin($lngDelta / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    $distance = $earthRadius * $c; // distancia em metros

    if ($distance > $maxMeters) {
        $distFormatted = number_format($distance, 0, ',', '.');
        $maxFormatted = number_format($maxMeters, 0, ',', '.');
        throw new \Exception(
            "Motorista BoraUm fora do raio de entrega. " .
            "Distancia: {$distFormatted}m | Maximo permitido: {$maxFormatted}m"
        );
    }

    return true;
}

// ══════════════════════════════════════════════════════════════════════════════
// 9. GUARD REVIEW AUTH - Validar autorizacao para avaliacao
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Valida que o cliente pode avaliar um pedido:
 *   1. O pedido pertence ao cliente
 *   2. O status e 'entregue'
 *   3. A entrega foi nos ultimos 30 dias
 *   4. Nao existe avaliacao anterior para este pedido
 *
 * @param PDO $db         Conexao PDO
 * @param int $orderId    ID do pedido
 * @param int $customerId ID do cliente
 *
 * @return bool true se autorizado
 * @throws \Exception Se nao autorizado (com motivo especifico)
 */
function guard_review_auth(PDO $db, int $orderId, int $customerId): bool {
    // Buscar pedido
    $stmt = $db->prepare("
        SELECT customer_id, status, COALESCE(delivered_at, date_modified, date_added) AS updated_at
        FROM om_market_orders
        WHERE order_id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$order) {
        throw new \Exception("Pedido #{$orderId} nao encontrado");
    }

    // Validar proprietario
    if ((int)$order['customer_id'] !== $customerId) {
        throw new \Exception("Pedido nao pertence ao cliente autenticado");
    }

    // Validar status
    $status = strtolower($order['status'] ?? '');
    if ($status !== 'entregue') {
        throw new \Exception("Avaliacao disponivel apenas para pedidos entregues (status atual: {$order['status']})");
    }

    // Validar prazo (30 dias apos entrega)
    if (!empty($order['updated_at'])) {
        $deliveryDate = strtotime($order['updated_at']);
        $thirtyDaysAgo = strtotime('-30 days');

        if ($deliveryDate < $thirtyDaysAgo) {
            throw new \Exception("Prazo para avaliacao expirado (30 dias apos entrega)");
        }
    }

    // Verificar avaliacao duplicada
    $stmtReview = $db->prepare("
        SELECT review_id FROM om_market_reviews
        WHERE order_id = ? AND customer_id = ?
        LIMIT 1
    ");
    $stmtReview->execute([$orderId, $customerId]);

    if ($stmtReview->fetch()) {
        throw new \Exception("Voce ja avaliou este pedido");
    }

    return true;
}

// ══════════════════════════════════════════════════════════════════════════════
// 10. GUARD CHECKOUT IDEMPOTENCY - Prevenir checkout duplicado
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Previne que o mesmo checkout seja processado duas vezes dentro de um periodo.
 * Usa tabela om_checkout_locks com UNIQUE(customer_id, cart_hash).
 *
 * Fluxo:
 *   1. Tenta INSERT do lock
 *   2. Se conflito (ON CONFLICT), verifica se lock existente expirou
 *   3. Se expirado, atualiza com novo timestamp
 *   4. Se nao expirado, rejeita como duplicata
 *
 * Limpeza periodica: remove locks expirados com 1% de chance por request.
 *
 * @param PDO    $db          Conexao PDO
 * @param int    $customerId  ID do cliente
 * @param string $cartHash    Hash unico do carrinho (md5/sha256 dos itens)
 * @param int    $ttlSeconds  Tempo de vida do lock em segundos (padrao: 300 = 5min)
 *
 * @return bool true se checkout pode prosseguir
 * @throws \Exception Se checkout duplicado detectado
 */
function guard_checkout_idempotency(PDO $db, int $customerId, string $cartHash, int $ttlSeconds = 300): bool {
    if (empty($cartHash)) {
        throw new \Exception("Hash do carrinho obrigatorio para verificacao de idempotencia");
    }

    // Sanitizar cart_hash (max 64 chars)
    $cartHash = substr(trim($cartHash), 0, 64);
    $ttlSeconds = max(30, min($ttlSeconds, 3600)); // Min 30s, max 1h

    try {
        // Tentar inserir lock
        $stmt = $db->prepare("
            INSERT INTO om_checkout_locks (customer_id, cart_hash, created_at, expires_at)
            VALUES (?, ?, NOW(), NOW() + MAKE_INTERVAL(secs := ?))
            ON CONFLICT (customer_id, cart_hash) DO NOTHING
        ");
        $stmt->execute([$customerId, $cartHash, $ttlSeconds]);

        if ($stmt->rowCount() === 1) {
            // Lock adquirido com sucesso - checkout pode prosseguir
            _guards_cleanup_checkout_locks($db);
            return true;
        }

        // Conflito - verificar se o lock existente expirou
        $stmtCheck = $db->prepare("
            SELECT lock_id, expires_at
            FROM om_checkout_locks
            WHERE customer_id = ? AND cart_hash = ?
        ");
        $stmtCheck->execute([$customerId, $cartHash]);
        $existing = $stmtCheck->fetch(\PDO::FETCH_ASSOC);

        if ($existing && strtotime($existing['expires_at']) < time()) {
            // Lock expirado - renovar
            $stmtRenew = $db->prepare("
                UPDATE om_checkout_locks
                SET created_at = NOW(), expires_at = NOW() + MAKE_INTERVAL(secs := ?)
                WHERE lock_id = ?
            ");
            $stmtRenew->execute([$ttlSeconds, $existing['lock_id']]);

            error_log("[guards] Checkout lock renovado: cliente#{$customerId} hash={$cartHash}");
            return true;
        }

        // Lock ativo - checkout duplicado
        throw new \Exception("Pedido duplicado");

    } catch (\Exception $e) {
        // Re-throw se for nossa excecao de duplicata
        if ($e->getMessage() === "Pedido duplicado") {
            error_log("[guards] Checkout duplicado bloqueado: cliente#{$customerId} hash={$cartHash}");
            throw $e;
        }
        // Outros erros de banco: logar e permitir (fail open)
        error_log("[guards] Erro ao verificar checkout idempotency: " . $e->getMessage());
        return true;
    }
}

/**
 * Limpeza periodica de locks de checkout expirados.
 * Executa com 1% de chance por request para nao impactar performance.
 *
 * @param PDO $db Conexao PDO
 * @return void
 */
function _guards_cleanup_checkout_locks(PDO $db): void {
    if (random_int(1, 100) !== 1) return;

    try {
        $db->exec("DELETE FROM om_checkout_locks WHERE expires_at < NOW()");
    } catch (\Exception $e) {
        error_log("[guards] Erro ao limpar checkout locks: " . $e->getMessage());
    }
}
