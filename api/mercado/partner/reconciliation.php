<?php
/**
 * GET/POST /api/mercado/partner/reconciliation.php
 * Sistema de Reconciliacao Financeira
 *
 * GET: Retorna reconciliacao por periodo (dia a dia)
 * POST: Marcar dia como verificado ou disputado
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $period = $_GET['period'] ?? 'week';
        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;

        // Determinar datas baseado no periodo
        switch ($period) {
            case 'today':
                $startDate = date('Y-m-d');
                $endDate = date('Y-m-d');
                break;
            case 'week':
                $startDate = date('Y-m-d', strtotime('-7 days'));
                $endDate = date('Y-m-d');
                break;
            case 'month':
                $startDate = date('Y-m-01');
                $endDate = date('Y-m-d');
                break;
            case 'last_month':
                $startDate = date('Y-m-01', strtotime('-1 month'));
                $endDate = date('Y-m-t', strtotime('-1 month'));
                break;
            case 'custom':
                if (!$from || !$to) {
                    response(false, null, "Datas 'from' e 'to' sao obrigatorias para periodo personalizado", 400);
                }
                $startDate = $from;
                $endDate = $to;
                break;
            default:
                $startDate = date('Y-m-d', strtotime('-7 days'));
                $endDate = date('Y-m-d');
        }

        // Validar datas
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            response(false, null, "Formato de data invalido. Use YYYY-MM-DD", 400);
        }

        // Gerar reconciliacao para dias que ainda nao existem
        $currentDate = $startDate;
        $today = date('Y-m-d');
        while ($currentDate <= $endDate && $currentDate < $today) {
            // Verificar se ja existe reconciliacao para este dia
            $stmtCheck = $db->prepare("
                SELECT id FROM om_daily_reconciliation
                WHERE partner_id = ? AND date = ?
            ");
            $stmtCheck->execute([$partnerId, $currentDate]);

            if (!$stmtCheck->fetch()) {
                // Gerar reconciliacao para este dia
                generateDailyReconciliation($db, $partnerId, $currentDate);
            }

            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }

        // Buscar reconciliacoes do periodo
        $stmtRecon = $db->prepare("
            SELECT
                id,
                date,
                total_orders,
                total_sales,
                total_commission,
                total_delivery_fee,
                total_discounts,
                total_refunds,
                total_tips,
                net_amount,
                status,
                verified_at,
                dispute_reason,
                notes,
                created_at,
                updated_at
            FROM om_daily_reconciliation
            WHERE partner_id = ?
              AND date BETWEEN ? AND ?
            ORDER BY date DESC
        ");
        $stmtRecon->execute([$partnerId, $startDate, $endDate]);
        $reconciliations = $stmtRecon->fetchAll(PDO::FETCH_ASSOC);

        // Calcular totais do periodo
        $totals = [
            'total_orders' => 0,
            'total_sales' => 0,
            'total_commission' => 0,
            'total_delivery_fee' => 0,
            'total_discounts' => 0,
            'total_refunds' => 0,
            'total_tips' => 0,
            'net_amount' => 0,
            'verified_count' => 0,
            'pending_count' => 0,
            'disputed_count' => 0,
        ];

        $formattedRecon = [];
        foreach ($reconciliations as $r) {
            $totals['total_orders'] += (int)$r['total_orders'];
            $totals['total_sales'] += (float)$r['total_sales'];
            $totals['total_commission'] += (float)$r['total_commission'];
            $totals['total_delivery_fee'] += (float)$r['total_delivery_fee'];
            $totals['total_discounts'] += (float)$r['total_discounts'];
            $totals['total_refunds'] += (float)$r['total_refunds'];
            $totals['total_tips'] += (float)$r['total_tips'];
            $totals['net_amount'] += (float)$r['net_amount'];

            switch ($r['status']) {
                case 'verified':
                    $totals['verified_count']++;
                    break;
                case 'disputed':
                    $totals['disputed_count']++;
                    break;
                default:
                    $totals['pending_count']++;
            }

            $formattedRecon[] = [
                'id' => (int)$r['id'],
                'date' => $r['date'],
                'day_name' => getDayName(date('N', strtotime($r['date']))),
                'total_orders' => (int)$r['total_orders'],
                'total_sales' => round((float)$r['total_sales'], 2),
                'total_commission' => round((float)$r['total_commission'], 2),
                'total_delivery_fee' => round((float)$r['total_delivery_fee'], 2),
                'total_discounts' => round((float)$r['total_discounts'], 2),
                'total_refunds' => round((float)$r['total_refunds'], 2),
                'total_tips' => round((float)$r['total_tips'], 2),
                'net_amount' => round((float)$r['net_amount'], 2),
                'status' => $r['status'],
                'verified_at' => $r['verified_at'],
                'dispute_reason' => $r['dispute_reason'],
                'notes' => $r['notes'],
            ];
        }

        // Arredondar totais
        $totals['total_sales'] = round($totals['total_sales'], 2);
        $totals['total_commission'] = round($totals['total_commission'], 2);
        $totals['total_delivery_fee'] = round($totals['total_delivery_fee'], 2);
        $totals['total_discounts'] = round($totals['total_discounts'], 2);
        $totals['total_refunds'] = round($totals['total_refunds'], 2);
        $totals['total_tips'] = round($totals['total_tips'], 2);
        $totals['net_amount'] = round($totals['net_amount'], 2);

        // Buscar repasses do periodo para comparacao
        $stmtPayouts = $db->prepare("
            SELECT
                SUM(CASE WHEN status = 'completed' THEN net_amount ELSE 0 END) as paid_amount,
                SUM(CASE WHEN status IN ('pending', 'processing') THEN net_amount ELSE 0 END) as pending_amount
            FROM om_payouts
            WHERE partner_id = ?
              AND (period_start BETWEEN ? AND ? OR period_end BETWEEN ? AND ?)
        ");
        $stmtPayouts->execute([$partnerId, $startDate, $endDate, $startDate, $endDate]);
        $payoutSummary = $stmtPayouts->fetch();

        // Buscar disputas abertas
        $stmtDisputes = $db->prepare("
            SELECT
                d.id,
                d.reconciliation_id,
                r.date,
                d.dispute_type,
                d.description,
                d.expected_amount,
                d.actual_amount,
                d.status,
                d.created_at
            FROM om_reconciliation_disputes d
            JOIN om_daily_reconciliation r ON r.id = d.reconciliation_id
            WHERE d.partner_id = ?
              AND d.status IN ('open', 'investigating')
            ORDER BY d.created_at DESC
            LIMIT 10
        ");
        $stmtDisputes->execute([$partnerId]);
        $openDisputes = $stmtDisputes->fetchAll(PDO::FETCH_ASSOC);

        // Taxa de comissao do parceiro
        $stmtCommission = $db->prepare("
            SELECT commission_rate FROM om_market_partners WHERE partner_id = ?
        ");
        $stmtCommission->execute([$partnerId]);
        $commissionRate = (float)($stmtCommission->fetch()['commission_rate'] ?? 12);

        response(true, [
            'period' => [
                'type' => $period,
                'start' => $startDate,
                'end' => $endDate,
                'days' => count($formattedRecon),
            ],
            'reconciliations' => $formattedRecon,
            'totals' => $totals,
            'payouts_comparison' => [
                'paid' => round((float)($payoutSummary['paid_amount'] ?? 0), 2),
                'pending' => round((float)($payoutSummary['pending_amount'] ?? 0), 2),
                'difference' => round($totals['net_amount'] - (float)($payoutSummary['paid_amount'] ?? 0) - (float)($payoutSummary['pending_amount'] ?? 0), 2),
            ],
            'open_disputes' => array_map(function($d) {
                return [
                    'id' => (int)$d['id'],
                    'date' => $d['date'],
                    'type' => $d['dispute_type'],
                    'description' => $d['description'],
                    'expected_amount' => (float)$d['expected_amount'],
                    'actual_amount' => (float)$d['actual_amount'],
                    'status' => $d['status'],
                    'created_at' => $d['created_at'],
                ];
            }, $openDisputes),
            'commission_rate' => $commissionRate,
        ], "Reconciliacao carregada");
    }

    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? '';
        $date = $input['date'] ?? null;
        $reconciliationId = isset($input['reconciliation_id']) ? (int)$input['reconciliation_id'] : null;

        // Verificar permissao
        if ($reconciliationId) {
            $stmtCheck = $db->prepare("
                SELECT id, date, status FROM om_daily_reconciliation
                WHERE id = ? AND partner_id = ?
            ");
            $stmtCheck->execute([$reconciliationId, $partnerId]);
            $recon = $stmtCheck->fetch();

            if (!$recon) {
                response(false, null, "Reconciliacao nao encontrada", 404);
            }
            $date = $recon['date'];
        } elseif ($date) {
            $stmtCheck = $db->prepare("
                SELECT id, status FROM om_daily_reconciliation
                WHERE date = ? AND partner_id = ?
            ");
            $stmtCheck->execute([$date, $partnerId]);
            $recon = $stmtCheck->fetch();

            if (!$recon) {
                response(false, null, "Reconciliacao nao encontrada para esta data", 404);
            }
            $reconciliationId = (int)$recon['id'];
        } else {
            response(false, null, "ID ou data da reconciliacao e obrigatorio", 400);
        }

        switch ($action) {
            case 'verify':
                // Marcar como verificado
                $stmtUpdate = $db->prepare("
                    UPDATE om_daily_reconciliation
                    SET status = 'verified', verified_at = NOW()
                    WHERE id = ? AND partner_id = ?
                ");
                $stmtUpdate->execute([$reconciliationId, $partnerId]);

                om_audit()->log('reconciliation', 'verify', [
                    'reconciliation_id' => $reconciliationId,
                    'date' => $date,
                ], $partnerId);

                response(true, [
                    'reconciliation_id' => $reconciliationId,
                    'date' => $date,
                    'status' => 'verified',
                ], "Reconciliacao marcada como verificada");
                break;

            case 'dispute':
                $disputeType = $input['dispute_type'] ?? 'other';
                $description = $input['description'] ?? '';
                $expectedAmount = isset($input['expected_amount']) ? (float)$input['expected_amount'] : null;
                $actualAmount = isset($input['actual_amount']) ? (float)$input['actual_amount'] : null;

                if (empty($description)) {
                    response(false, null, "Descricao da disputa e obrigatoria", 400);
                }

                // Sanitize description to prevent stored XSS
                $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

                // Atualizar status da reconciliacao
                $stmtUpdate = $db->prepare("
                    UPDATE om_daily_reconciliation
                    SET status = 'disputed', dispute_reason = ?
                    WHERE id = ? AND partner_id = ?
                ");
                $stmtUpdate->execute([$description, $reconciliationId, $partnerId]);

                // Criar registro de disputa
                $stmtDispute = $db->prepare("
                    INSERT INTO om_reconciliation_disputes (
                        reconciliation_id, partner_id, dispute_type,
                        description, expected_amount, actual_amount, status
                    ) VALUES (?, ?, ?, ?, ?, ?, 'open')
                ");
                $stmtDispute->execute([
                    $reconciliationId,
                    $partnerId,
                    $disputeType,
                    $description,
                    $expectedAmount,
                    $actualAmount,
                ]);
                $disputeId = $db->lastInsertId();

                om_audit()->log('reconciliation', 'dispute', [
                    'reconciliation_id' => $reconciliationId,
                    'dispute_id' => $disputeId,
                    'date' => $date,
                    'type' => $disputeType,
                ], $partnerId);

                response(true, [
                    'reconciliation_id' => $reconciliationId,
                    'dispute_id' => (int)$disputeId,
                    'date' => $date,
                    'status' => 'disputed',
                ], "Disputa registrada com sucesso. Nossa equipe analisara em ate 48 horas.");
                break;

            case 'add_note':
                $notes = $input['notes'] ?? '';

                $stmtUpdate = $db->prepare("
                    UPDATE om_daily_reconciliation
                    SET notes = ?
                    WHERE id = ? AND partner_id = ?
                ");
                $stmtUpdate->execute([$notes, $reconciliationId, $partnerId]);

                response(true, [
                    'reconciliation_id' => $reconciliationId,
                    'notes' => $notes,
                ], "Observacao adicionada");
                break;

            case 'verify_all':
                // Verificar todos os dias pendentes do periodo
                $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
                $endDate = $input['end_date'] ?? date('Y-m-d', strtotime('-1 day'));

                $stmtBulk = $db->prepare("
                    UPDATE om_daily_reconciliation
                    SET status = 'verified', verified_at = NOW()
                    WHERE partner_id = ?
                      AND date BETWEEN ? AND ?
                      AND status = 'pending'
                ");
                $stmtBulk->execute([$partnerId, $startDate, $endDate]);
                $affected = $stmtBulk->rowCount();

                om_audit()->log('reconciliation', 'verify_bulk', [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'count' => $affected,
                ], $partnerId);

                response(true, [
                    'verified_count' => $affected,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ], "$affected dias verificados com sucesso");
                break;

            default:
                response(false, null, "Acao invalida", 400);
        }
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/reconciliation] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

/**
 * Gerar reconciliacao para um dia especifico
 */
function generateDailyReconciliation($db, $partnerId, $date) {
    // Obter taxa de comissao do parceiro
    $stmtRate = $db->prepare("
        SELECT COALESCE(commission_rate, 12.00) as rate
        FROM om_market_partners WHERE partner_id = ?
    ");
    $stmtRate->execute([$partnerId]);
    $commissionRate = (float)$stmtRate->fetch()['rate'];

    // Calcular totais do dia
    $stmtTotals = $db->prepare("
        SELECT
            COUNT(*) as total_orders,
            COALESCE(SUM(total), 0) as total_sales,
            COALESCE(SUM(COALESCE(delivery_fee, 0)), 0) as total_delivery_fee,
            COALESCE(SUM(COALESCE(discount, 0)), 0) as total_discounts,
            COALESCE(SUM(COALESCE(tip_amount, 0)), 0) as total_tips
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) = ?
          AND status NOT IN ('cancelado', 'cancelled')
    ");
    $stmtTotals->execute([$partnerId, $date]);
    $totals = $stmtTotals->fetch();

    $totalSales = (float)$totals['total_sales'];
    $totalOrders = (int)$totals['total_orders'];
    $totalDeliveryFee = (float)$totals['total_delivery_fee'];
    $totalDiscounts = (float)$totals['total_discounts'];
    $totalTips = (float)$totals['total_tips'];
    $totalCommission = round($totalSales * $commissionRate / 100, 2);

    // Calcular reembolsos
    $stmtRefunds = $db->prepare("
        SELECT COALESCE(SUM(total), 0) as total_refunds
        FROM om_market_orders
        WHERE partner_id = ?
          AND DATE(date_added) = ?
          AND status IN ('cancelado', 'cancelled')
    ");
    $stmtRefunds->execute([$partnerId, $date]);
    $totalRefunds = (float)$stmtRefunds->fetch()['total_refunds'];

    // Calcular valor liquido
    $netAmount = $totalSales - $totalCommission - $totalDiscounts - $totalRefunds + $totalTips;

    // Inserir reconciliacao
    $stmtInsert = $db->prepare("
        INSERT INTO om_daily_reconciliation (
            partner_id, date, total_orders, total_sales, total_commission,
            total_delivery_fee, total_discounts, total_refunds, total_tips, net_amount
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (partner_id, date) DO UPDATE SET
            total_orders = EXCLUDED.total_orders,
            total_sales = EXCLUDED.total_sales,
            total_commission = EXCLUDED.total_commission,
            total_delivery_fee = EXCLUDED.total_delivery_fee,
            total_discounts = EXCLUDED.total_discounts,
            total_refunds = EXCLUDED.total_refunds,
            total_tips = EXCLUDED.total_tips,
            net_amount = EXCLUDED.net_amount,
            updated_at = NOW()
    ");
    $stmtInsert->execute([
        $partnerId, $date, $totalOrders, $totalSales, $totalCommission,
        $totalDeliveryFee, $totalDiscounts, $totalRefunds, $totalTips, $netAmount
    ]);
}

function getDayName($dayNumber) {
    $days = [
        1 => 'Segunda',
        2 => 'Terca',
        3 => 'Quarta',
        4 => 'Quinta',
        5 => 'Sexta',
        6 => 'Sabado',
        7 => 'Domingo',
    ];
    return $days[$dayNumber] ?? '';
}
