<?php
/**
 * GET/POST /api/mercado/partner/anticipation.php
 * Sistema de Antecipacao de Recebiveis
 *
 * GET: Ver saldo disponivel para antecipacao, taxas e historico
 * POST: Solicitar antecipacao (simular ou confirmar)
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
        // Buscar taxas de antecipacao configuradas
        $stmtFees = $db->query("
            SELECT days_min, days_max, fee_percent
            FROM om_anticipation_fees
            WHERE active = 1
            ORDER BY days_min ASC
        ");
        $feeRanges = $stmtFees->fetchAll(PDO::FETCH_ASSOC);

        // Se nao houver taxas configuradas, usar padrao
        if (empty($feeRanges)) {
            $feeRanges = [
                ['days_min' => 1, 'days_max' => 7, 'fee_percent' => 3.00],
                ['days_min' => 8, 'days_max' => 14, 'fee_percent' => 5.00],
                ['days_min' => 15, 'days_max' => 30, 'fee_percent' => 8.00],
            ];
        }

        // Calcular valor disponivel para antecipacao (repasses agendados nos proximos 30 dias)
        $stmtPayouts = $db->prepare("
            SELECT
                id,
                amount,
                net_amount,
                scheduled_date,
                (scheduled_date::date - CURRENT_DATE) as days_until
            FROM om_payouts
            WHERE partner_id = ?
              AND status IN ('scheduled', 'pending')
              AND scheduled_date > CURRENT_DATE
              AND scheduled_date <= CURRENT_DATE + INTERVAL '30 days'
            ORDER BY scheduled_date ASC
        ");
        $stmtPayouts->execute([$partnerId]);
        $upcomingPayouts = $stmtPayouts->fetchAll(PDO::FETCH_ASSOC);

        // Calcular saldo pendente que ainda nao virou repasse
        $stmtPending = $db->prepare("
            SELECT COALESCE(SUM(net_amount), 0) as pending_balance
            FROM om_daily_reconciliation
            WHERE partner_id = ?
              AND date >= CURRENT_DATE - INTERVAL '30 days'
              AND date < CURRENT_DATE
        ");
        $stmtPending->execute([$partnerId]);
        $pendingBalance = (float)$stmtPending->fetch()['pending_balance'];

        // Total disponivel para antecipacao
        $totalAvailable = $pendingBalance;
        foreach ($upcomingPayouts as $payout) {
            $totalAvailable += (float)$payout['net_amount'];
        }

        // Agrupar por periodo de antecipacao
        $availableByPeriod = [];
        foreach ($feeRanges as $range) {
            $periodAmount = 0;
            foreach ($upcomingPayouts as $payout) {
                $daysUntil = (int)$payout['days_until'];
                if ($daysUntil >= $range['days_min'] && $daysUntil <= $range['days_max']) {
                    $periodAmount += (float)$payout['net_amount'];
                }
            }
            $availableByPeriod[] = [
                'days_min' => $range['days_min'],
                'days_max' => $range['days_max'],
                'fee_percent' => (float)$range['fee_percent'],
                'available_amount' => round($periodAmount, 2),
                'fee_amount' => round($periodAmount * $range['fee_percent'] / 100, 2),
                'net_amount' => round($periodAmount * (1 - $range['fee_percent'] / 100), 2),
            ];
        }

        // Historico de antecipacoes
        $stmtHistory = $db->prepare("
            SELECT
                id,
                requested_amount,
                fee_percent,
                fee_amount,
                net_amount,
                days_ahead,
                status,
                requested_at,
                processed_at,
                paid_at,
                rejection_reason
            FROM om_anticipations
            WHERE partner_id = ?
            ORDER BY requested_at DESC
            LIMIT 20
        ");
        $stmtHistory->execute([$partnerId]);
        $history = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

        // Formatar historico
        $formattedHistory = [];
        foreach ($history as $h) {
            $formattedHistory[] = [
                'id' => (int)$h['id'],
                'requested_amount' => (float)$h['requested_amount'],
                'fee_percent' => (float)$h['fee_percent'],
                'fee_amount' => (float)$h['fee_amount'],
                'net_amount' => (float)$h['net_amount'],
                'days_ahead' => (int)$h['days_ahead'],
                'status' => $h['status'],
                'requested_at' => $h['requested_at'],
                'processed_at' => $h['processed_at'],
                'paid_at' => $h['paid_at'],
                'rejection_reason' => $h['rejection_reason'],
            ];
        }

        // Verificar se ha antecipacao pendente
        $stmtPendingAnticipation = $db->prepare("
            SELECT COUNT(*) as count
            FROM om_anticipations
            WHERE partner_id = ? AND status = 'pending'
        ");
        $stmtPendingAnticipation->execute([$partnerId]);
        $hasPendingAnticipation = (int)$stmtPendingAnticipation->fetch()['count'] > 0;

        // Estatisticas de antecipacoes
        $stmtStats = $db->prepare("
            SELECT
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END) as total_anticipated,
                SUM(CASE WHEN status = 'paid' THEN fee_amount ELSE 0 END) as total_fees_paid
            FROM om_anticipations
            WHERE partner_id = ?
        ");
        $stmtStats->execute([$partnerId]);
        $stats = $stmtStats->fetch();

        response(true, [
            'available' => [
                'total' => round($totalAvailable, 2),
                'pending_balance' => round($pendingBalance, 2),
                'scheduled_payouts' => round($totalAvailable - $pendingBalance, 2),
            ],
            'fee_ranges' => $feeRanges,
            'available_by_period' => $availableByPeriod,
            'upcoming_payouts' => array_map(function($p) {
                return [
                    'id' => (int)$p['id'],
                    'amount' => (float)$p['net_amount'],
                    'scheduled_date' => $p['scheduled_date'],
                    'days_until' => (int)$p['days_until'],
                ];
            }, $upcomingPayouts),
            'history' => $formattedHistory,
            'has_pending_request' => $hasPendingAnticipation,
            'stats' => [
                'total_requests' => (int)$stats['total_requests'],
                'approved_count' => (int)$stats['approved_count'],
                'total_anticipated' => round((float)$stats['total_anticipated'], 2),
                'total_fees_paid' => round((float)$stats['total_fees_paid'], 2),
            ],
        ], "Dados de antecipacao carregados");
    }

    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? 'simulate';
        $amount = isset($input['amount']) ? (float)$input['amount'] : 0;
        $daysAhead = isset($input['days_ahead']) ? (int)$input['days_ahead'] : 7;

        // Validar valor
        if ($amount <= 0) {
            response(false, null, "Valor invalido", 400);
        }

        // Validar dias
        if ($daysAhead < 1 || $daysAhead > 30) {
            response(false, null, "Prazo de antecipacao deve ser entre 1 e 30 dias", 400);
        }

        // Buscar taxa aplicavel
        $stmtFee = $db->prepare("
            SELECT fee_percent
            FROM om_anticipation_fees
            WHERE ? >= days_min AND ? <= days_max AND active = 1
            LIMIT 1
        ");
        $stmtFee->execute([$daysAhead, $daysAhead]);
        $feeRow = $stmtFee->fetch();

        if (!$feeRow) {
            // Taxa padrao baseada nos dias
            if ($daysAhead <= 7) {
                $feePercent = 3.00;
            } elseif ($daysAhead <= 14) {
                $feePercent = 5.00;
            } else {
                $feePercent = 8.00;
            }
        } else {
            $feePercent = (float)$feeRow['fee_percent'];
        }

        // Calcular valores
        $feeAmount = round($amount * $feePercent / 100, 2);
        $netAmount = round($amount - $feeAmount, 2);

        // Verificar saldo disponivel
        $stmtAvailable = $db->prepare("
            SELECT COALESCE(SUM(net_amount), 0) as available
            FROM om_payouts
            WHERE partner_id = ?
              AND status IN ('scheduled', 'pending')
              AND scheduled_date > CURRENT_DATE
              AND scheduled_date <= CURRENT_DATE + INTERVAL '1 day' * ?
        ");
        $stmtAvailable->execute([$partnerId, (int)$daysAhead]);
        $available = (float)$stmtAvailable->fetch()['available'];

        // Adicionar saldo pendente
        $stmtPending = $db->prepare("
            SELECT COALESCE(SUM(net_amount), 0) as pending
            FROM om_daily_reconciliation
            WHERE partner_id = ?
              AND date >= CURRENT_DATE - INTERVAL '30 days'
              AND date < CURRENT_DATE
        ");
        $stmtPending->execute([$partnerId]);
        $available += (float)$stmtPending->fetch()['pending'];

        if ($amount > $available) {
            response(false, [
                'available' => round($available, 2),
                'requested' => $amount,
            ], "Valor solicitado excede o disponivel para antecipacao", 400);
        }

        // Simulacao
        $simulation = [
            'requested_amount' => $amount,
            'days_ahead' => $daysAhead,
            'fee_percent' => $feePercent,
            'fee_amount' => $feeAmount,
            'net_amount' => $netAmount,
            'available' => round($available, 2),
            'estimated_payment_date' => date('Y-m-d', strtotime('+1 day')),
        ];

        if ($action === 'simulate') {
            response(true, [
                'simulation' => $simulation,
                'message' => "Para receber R$ " . number_format($netAmount, 2, ',', '.') .
                             " agora, a taxa sera de R$ " . number_format($feeAmount, 2, ',', '.') .
                             " (" . $feePercent . "%)",
            ], "Simulacao de antecipacao");
        }

        // Confirmar antecipacao
        if ($action === 'confirm') {
            $db->beginTransaction();
            try {
                // Verificar se ja ha antecipacao pendente (with lock to prevent race condition)
                $stmtCheck = $db->prepare("
                    SELECT id FROM om_anticipations
                    WHERE partner_id = ? AND status = 'pending'
                    FOR UPDATE
                ");
                $stmtCheck->execute([$partnerId]);
                if ($stmtCheck->fetch()) {
                    $db->rollBack();
                    response(false, null, "Voce ja possui uma solicitacao de antecipacao pendente", 400);
                }

                // Buscar repasses que serao antecipados
                $stmtPayouts = $db->prepare("
                    SELECT id FROM om_payouts
                    WHERE partner_id = ?
                      AND status IN ('scheduled', 'pending')
                      AND scheduled_date > CURRENT_DATE
                      AND scheduled_date <= CURRENT_DATE + INTERVAL '1 day' * ?
                    FOR UPDATE
                ");
                $stmtPayouts->execute([$partnerId, (int)$daysAhead]);
                $payoutIds = array_column($stmtPayouts->fetchAll(), 'id');

                // Criar solicitacao de antecipacao
                $stmtInsert = $db->prepare("
                    INSERT INTO om_anticipations (
                        partner_id, requested_amount, fee_percent, fee_amount,
                        net_amount, days_ahead, status, payout_ids
                    ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
                    RETURNING id
                ");
                $stmtInsert->execute([
                    $partnerId,
                    $amount,
                    $feePercent,
                    $feeAmount,
                    $netAmount,
                    $daysAhead,
                    json_encode($payoutIds),
                ]);
                $anticipationId = $stmtInsert->fetchColumn();

                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }

            // Registrar auditoria
            om_audit()->log('anticipation', 'request', [
                'anticipation_id' => $anticipationId,
                'amount' => $amount,
                'net_amount' => $netAmount,
                'fee_percent' => $feePercent,
            ], $partnerId);

            response(true, [
                'anticipation_id' => (int)$anticipationId,
                'requested_amount' => $amount,
                'fee_amount' => $feeAmount,
                'net_amount' => $netAmount,
                'status' => 'pending',
                'message' => "Sua solicitacao foi recebida! Voce recebera R$ " .
                             number_format($netAmount, 2, ',', '.') .
                             " em ate 24 horas uteis.",
            ], "Antecipacao solicitada com sucesso");
        }

        response(false, null, "Acao invalida. Use 'simulate' ou 'confirm'", 400);
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/anticipation] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
