<?php
/**
 * GET/POST /api/mercado/partner/receivables.php
 * Sistema de Recebiveis e Antecipacao
 *
 * GET: Lista recebiveis pendentes (pedidos completos nao pagos)
 *      - Agrupa por data de pagamento esperada
 *      - Calcula taxa de antecipacao (2% flat)
 * POST: Solicitar antecipacao de recebiveis
 *      - Recebe receivable_ids[] e bank_account
 *      - Cria registro em om_partner_anticipations
 *      - Status: pending -> approved -> paid
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

// Taxa de antecipacao (2% flat conforme especificado)
define('ANTICIPATION_FEE_PERCENT', 2.0);

// Dias para pagamento padrao (apos pedido entregue)
define('DEFAULT_PAYMENT_DAYS', 14);

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    // Criar tabelas se nao existirem
    ensureTablesExist($db);

    if ($method === 'GET') {
        // Obter taxa de comissao do parceiro
        $stmtRate = $db->prepare("
            SELECT COALESCE(commission_rate, 12.00) as rate
            FROM om_market_partners WHERE partner_id = ?
        ");
        $stmtRate->execute([$partnerId]);
        $commissionRate = (float)$stmtRate->fetch()['rate'];

        // Buscar pedidos concluidos que ainda nao foram pagos
        // Considera pedidos entregues nos ultimos 60 dias
        $stmtOrders = $db->prepare("
            SELECT
                o.id,
                o.order_number,
                o.total,
                o.subtotal,
                COALESCE(o.delivery_fee, 0) as delivery_fee,
                COALESCE(o.discount, 0) as discount,
                COALESCE(o.tip, 0) as tip,
                o.status,
                o.date_added,
                o.date_delivered,
                COALESCE(o.date_delivered, o.date_added) + (? || ' days')::INTERVAL as expected_payment_date,
                ((COALESCE(o.date_delivered, o.date_added) + (? || ' days')::INTERVAL)::date - CURRENT_DATE) as days_until_payment
            FROM om_market_orders o
            LEFT JOIN om_partner_receivable_items pri ON pri.order_id = o.id
            WHERE o.partner_id = ?
              AND o.status IN ('entregue', 'completed', 'concluido')
              AND pri.id IS NULL
              AND o.date_delivered >= CURRENT_DATE - INTERVAL '60 days'
            ORDER BY expected_payment_date ASC
        ");
        $stmtOrders->execute([DEFAULT_PAYMENT_DAYS, DEFAULT_PAYMENT_DAYS, $partnerId]);
        $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

        // Calcular valores e agrupar por data esperada de pagamento
        $receivablesByDate = [];
        $totalPending = 0;
        $totalGrossAmount = 0;
        $totalCommission = 0;
        $totalNetAmount = 0;

        foreach ($orders as $order) {
            $grossAmount = (float)$order['total'];
            $commission = round($grossAmount * $commissionRate / 100, 2);
            $netAmount = round($grossAmount - $commission, 2);
            $paymentDate = $order['expected_payment_date'];
            $daysUntil = max(0, (int)$order['days_until_payment']);

            // Calcular taxa de antecipacao se for antecipar
            $anticipationFee = round($netAmount * ANTICIPATION_FEE_PERCENT / 100, 2);
            $netAfterAnticipation = round($netAmount - $anticipationFee, 2);

            $receivableItem = [
                'id' => (int)$order['id'],
                'order_number' => $order['order_number'],
                'order_date' => $order['date_added'],
                'delivered_date' => $order['date_delivered'],
                'expected_payment_date' => $paymentDate,
                'days_until_payment' => $daysUntil,
                'gross_amount' => round($grossAmount, 2),
                'commission' => $commission,
                'commission_rate' => $commissionRate,
                'net_amount' => $netAmount,
                'anticipation_fee' => $anticipationFee,
                'anticipation_fee_percent' => ANTICIPATION_FEE_PERCENT,
                'net_after_anticipation' => $netAfterAnticipation,
            ];

            if (!isset($receivablesByDate[$paymentDate])) {
                $receivablesByDate[$paymentDate] = [
                    'date' => $paymentDate,
                    'days_until' => $daysUntil,
                    'orders' => [],
                    'order_count' => 0,
                    'total_gross' => 0,
                    'total_commission' => 0,
                    'total_net' => 0,
                    'total_anticipation_fee' => 0,
                    'total_net_after_anticipation' => 0,
                ];
            }

            $receivablesByDate[$paymentDate]['orders'][] = $receivableItem;
            $receivablesByDate[$paymentDate]['order_count']++;
            $receivablesByDate[$paymentDate]['total_gross'] += $grossAmount;
            $receivablesByDate[$paymentDate]['total_commission'] += $commission;
            $receivablesByDate[$paymentDate]['total_net'] += $netAmount;
            $receivablesByDate[$paymentDate]['total_anticipation_fee'] += $anticipationFee;
            $receivablesByDate[$paymentDate]['total_net_after_anticipation'] += $netAfterAnticipation;

            $totalGrossAmount += $grossAmount;
            $totalCommission += $commission;
            $totalNetAmount += $netAmount;
            $totalPending++;
        }

        // Arredondar totais por data
        foreach ($receivablesByDate as $date => &$group) {
            $group['total_gross'] = round($group['total_gross'], 2);
            $group['total_commission'] = round($group['total_commission'], 2);
            $group['total_net'] = round($group['total_net'], 2);
            $group['total_anticipation_fee'] = round($group['total_anticipation_fee'], 2);
            $group['total_net_after_anticipation'] = round($group['total_net_after_anticipation'], 2);
        }
        unset($group);

        // Ordenar por data
        ksort($receivablesByDate);

        // Buscar historico de antecipacoes
        $stmtHistory = $db->prepare("
            SELECT
                id,
                total_amount,
                fee_amount,
                net_amount,
                status,
                requested_at,
                processed_at
            FROM om_partner_anticipations
            WHERE partner_id = ?
            ORDER BY requested_at DESC
            LIMIT 10
        ");
        $stmtHistory->execute([$partnerId]);
        $anticipationHistory = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

        // Formatar historico
        $formattedHistory = [];
        foreach ($anticipationHistory as $h) {
            $formattedHistory[] = [
                'id' => (int)$h['id'],
                'total_amount' => round((float)$h['total_amount'], 2),
                'fee_amount' => round((float)$h['fee_amount'], 2),
                'net_amount' => round((float)$h['net_amount'], 2),
                'status' => $h['status'],
                'status_label' => getStatusLabel($h['status']),
                'requested_at' => $h['requested_at'],
                'processed_at' => $h['processed_at'],
            ];
        }

        // Verificar se ha antecipacao pendente
        $stmtPending = $db->prepare("
            SELECT COUNT(*) as count
            FROM om_partner_anticipations
            WHERE partner_id = ? AND status = 'pending'
        ");
        $stmtPending->execute([$partnerId]);
        $hasPendingAnticipation = (int)$stmtPending->fetch()['count'] > 0;

        // Estatisticas
        $stmtStats = $db->prepare("
            SELECT
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END) as total_anticipated,
                SUM(CASE WHEN status = 'paid' THEN fee_amount ELSE 0 END) as total_fees_paid
            FROM om_partner_anticipations
            WHERE partner_id = ?
        ");
        $stmtStats->execute([$partnerId]);
        $stats = $stmtStats->fetch();

        response(true, [
            'summary' => [
                'total_pending_orders' => $totalPending,
                'total_gross_amount' => round($totalGrossAmount, 2),
                'total_commission' => round($totalCommission, 2),
                'total_net_amount' => round($totalNetAmount, 2),
                'anticipation_fee_percent' => ANTICIPATION_FEE_PERCENT,
                'total_anticipation_fee' => round($totalNetAmount * ANTICIPATION_FEE_PERCENT / 100, 2),
                'total_after_anticipation' => round($totalNetAmount * (1 - ANTICIPATION_FEE_PERCENT / 100), 2),
                'commission_rate' => $commissionRate,
            ],
            'receivables_by_date' => array_values($receivablesByDate),
            'anticipation_history' => $formattedHistory,
            'has_pending_anticipation' => $hasPendingAnticipation,
            'stats' => [
                'total_requests' => (int)$stats['total_requests'],
                'paid_count' => (int)$stats['paid_count'],
                'total_anticipated' => round((float)$stats['total_anticipated'], 2),
                'total_fees_paid' => round((float)$stats['total_fees_paid'], 2),
            ],
        ], "Recebiveis carregados");
    }

    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'request';

        if ($action === 'request') {
            $receivableIds = $input['receivable_ids'] ?? [];
            $bankAccount = $input['bank_account'] ?? [];

            // Validar IDs
            if (empty($receivableIds) || !is_array($receivableIds)) {
                response(false, null, "Selecione ao menos um recebivel para antecipar", 400);
            }

            // Validar dados bancarios
            if (empty($bankAccount['banco']) || empty($bankAccount['agencia']) || empty($bankAccount['conta'])) {
                response(false, null, "Dados bancarios incompletos", 400);
            }

            // Iniciar transacao cedo para lock de antecipacao pendente
            $db->beginTransaction();

            // Verificar se ja ha antecipacao pendente (with lock to prevent race condition)
            $stmtCheck = $db->prepare("
                SELECT id FROM om_partner_anticipations
                WHERE partner_id = ? AND status = 'pending'
                FOR UPDATE
            ");
            $stmtCheck->execute([$partnerId]);
            if ($stmtCheck->fetch()) {
                $db->rollBack();
                response(false, null, "Voce ja possui uma solicitacao de antecipacao pendente", 400);
            }

            // Obter taxa de comissao
            $stmtRate = $db->prepare("
                SELECT COALESCE(commission_rate, 12.00) as rate
                FROM om_market_partners WHERE partner_id = ?
            ");
            $stmtRate->execute([$partnerId]);
            $commissionRate = (float)$stmtRate->fetch()['rate'];

            // Buscar e validar os pedidos selecionados
            $placeholders = implode(',', array_fill(0, count($receivableIds), '?'));
            $params = array_merge($receivableIds, [$partnerId]);
            $stmtOrders = $db->prepare("
                SELECT
                    o.id,
                    o.order_number,
                    o.total
                FROM om_market_orders o
                LEFT JOIN om_partner_receivable_items pri ON pri.order_id = o.id
                WHERE o.id IN ($placeholders)
                  AND o.partner_id = ?
                  AND o.status IN ('entregue', 'completed', 'concluido')
                  AND pri.id IS NULL
            ");
            $stmtOrders->execute($params);
            $validOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

            if (empty($validOrders)) {
                response(false, null, "Nenhum recebivel valido encontrado", 400);
            }

            // Calcular totais
            $totalGross = 0;
            $orderIds = [];
            foreach ($validOrders as $order) {
                $totalGross += (float)$order['total'];
                $orderIds[] = (int)$order['id'];
            }

            $totalCommission = round($totalGross * $commissionRate / 100, 2);
            $totalNet = round($totalGross - $totalCommission, 2);
            $feeAmount = round($totalNet * ANTICIPATION_FEE_PERCENT / 100, 2);
            $netAmount = round($totalNet - $feeAmount, 2);

            try {
                // Criar registro de antecipacao
                $stmtInsert = $db->prepare("
                    INSERT INTO om_partner_anticipations (
                        partner_id, total_amount, fee_amount, net_amount, status,
                        bank_info, requested_at
                    ) VALUES (?, ?, ?, ?, 'pending', ?, NOW())
                    RETURNING id
                ");
                $stmtInsert->execute([
                    $partnerId,
                    $totalNet,
                    $feeAmount,
                    $netAmount,
                    json_encode($bankAccount),
                ]);
                $anticipationId = $stmtInsert->fetchColumn();

                // Registrar os itens da antecipacao
                $stmtItem = $db->prepare("
                    INSERT INTO om_partner_receivable_items (
                        anticipation_id, order_id, amount, created_at
                    ) VALUES (?, ?, ?, NOW())
                ");

                foreach ($validOrders as $order) {
                    $orderNet = round((float)$order['total'] * (1 - $commissionRate / 100), 2);
                    $stmtItem->execute([$anticipationId, $order['id'], $orderNet]);
                }

                $db->commit();

                // Registrar auditoria
                om_audit()->log('receivables', 'anticipation_request', [
                    'anticipation_id' => $anticipationId,
                    'order_count' => count($orderIds),
                    'total_amount' => $totalNet,
                    'fee_amount' => $feeAmount,
                    'net_amount' => $netAmount,
                ], $partnerId);

                response(true, [
                    'anticipation_id' => (int)$anticipationId,
                    'order_count' => count($orderIds),
                    'total_amount' => $totalNet,
                    'fee_amount' => $feeAmount,
                    'fee_percent' => ANTICIPATION_FEE_PERCENT,
                    'net_amount' => $netAmount,
                    'status' => 'pending',
                    'bank_account' => [
                        'banco' => $bankAccount['banco'],
                        'agencia' => substr($bankAccount['agencia'], 0, 2) . '***',
                        'conta' => '***' . substr($bankAccount['conta'], -3),
                    ],
                ], "Antecipacao solicitada com sucesso! Voce recebera R$ " .
                   number_format($netAmount, 2, ',', '.') . " em ate 24 horas uteis.");

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }

        if ($action === 'simulate') {
            $receivableIds = $input['receivable_ids'] ?? [];

            if (empty($receivableIds) || !is_array($receivableIds)) {
                response(false, null, "Selecione ao menos um recebivel para simular", 400);
            }

            // Obter taxa de comissao
            $stmtRate = $db->prepare("
                SELECT COALESCE(commission_rate, 12.00) as rate
                FROM om_market_partners WHERE partner_id = ?
            ");
            $stmtRate->execute([$partnerId]);
            $commissionRate = (float)$stmtRate->fetch()['rate'];

            // Buscar os pedidos selecionados
            $placeholders = implode(',', array_fill(0, count($receivableIds), '?'));
            $params = array_merge($receivableIds, [$partnerId]);
            $stmtOrders = $db->prepare("
                SELECT
                    o.id,
                    o.total
                FROM om_market_orders o
                LEFT JOIN om_partner_receivable_items pri ON pri.order_id = o.id
                WHERE o.id IN ($placeholders)
                  AND o.partner_id = ?
                  AND o.status IN ('entregue', 'completed', 'concluido')
                  AND pri.id IS NULL
            ");
            $stmtOrders->execute($params);
            $validOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

            if (empty($validOrders)) {
                response(false, null, "Nenhum recebivel valido encontrado", 400);
            }

            $totalGross = 0;
            foreach ($validOrders as $order) {
                $totalGross += (float)$order['total'];
            }

            $totalCommission = round($totalGross * $commissionRate / 100, 2);
            $totalNet = round($totalGross - $totalCommission, 2);
            $feeAmount = round($totalNet * ANTICIPATION_FEE_PERCENT / 100, 2);
            $netAmount = round($totalNet - $feeAmount, 2);

            response(true, [
                'simulation' => [
                    'order_count' => count($validOrders),
                    'total_gross' => round($totalGross, 2),
                    'total_commission' => $totalCommission,
                    'total_net' => $totalNet,
                    'fee_percent' => ANTICIPATION_FEE_PERCENT,
                    'fee_amount' => $feeAmount,
                    'net_amount' => $netAmount,
                ],
            ], "Simulacao de antecipacao");
        }

        response(false, null, "Acao invalida", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/receivables] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

/**
 * Criar tabelas necessarias se nao existirem
 */
function ensureTablesExist($db) {
    // No-op: tables created via migration
}

/**
 * Obter label do status
 */
function getStatusLabel($status) {
    $labels = [
        'pending' => 'Pendente',
        'approved' => 'Aprovado',
        'paid' => 'Pago',
        'rejected' => 'Rejeitado',
    ];
    return $labels[$status] ?? $status;
}
