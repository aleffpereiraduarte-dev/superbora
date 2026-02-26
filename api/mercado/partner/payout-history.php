<?php
/**
 * GET /api/mercado/partner/payout-history.php
 * Historico de Repasses
 *
 * GET: Lista todos repasses com status e comprovantes
 * Parametros: status, limit, offset, from, to
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

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        response(false, null, "Metodo nao permitido", 405);
    }

    // Parametros de filtro
    $status = $_GET['status'] ?? null;
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;

    // Construir query
    $where = ["partner_id = ?"];
    $params = [$partnerId];

    if ($status && in_array($status, ['scheduled', 'pending', 'processing', 'completed', 'failed', 'cancelled'])) {
        $where[] = "status = ?";
        $params[] = $status;
    }

    if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = "period_start >= ?";
        $params[] = $from;
    }

    if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = "period_end <= ?";
        $params[] = $to;
    }

    $whereClause = implode(' AND ', $where);

    // Contar total
    $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM om_market_partner_payouts WHERE $whereClause");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetch()['total'];

    // Buscar repasses
    $stmtPayouts = $db->prepare("
        SELECT
            id,
            gross_sales,
            commission_amount,
            net_payout,
            status,
            period_start,
            period_end,
            paid_at,
            created_at
        FROM om_market_partner_payouts
        WHERE $whereClause
        ORDER BY period_end DESC, created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmtPayouts->execute($params);
    $payouts = $stmtPayouts->fetchAll(PDO::FETCH_ASSOC);

    // Formatar repasses
    $formattedPayouts = [];
    foreach ($payouts as $p) {
        $formattedPayouts[] = [
            'id' => (int)$p['id'],
            'gross_sales' => round((float)$p['gross_sales'], 2),
            'commission' => round((float)$p['commission_amount'], 2),
            'net_amount' => round((float)$p['net_payout'], 2),
            'status' => $p['status'],
            'status_label' => getStatusLabel($p['status']),
            'paid_at' => $p['paid_at'],
            'period' => [
                'start' => $p['period_start'],
                'end' => $p['period_end'],
            ],
            'created_at' => $p['created_at'],
        ];
    }

    // Estatisticas gerais
    $stmtStats = $db->prepare("
        SELECT
            COUNT(*) as total_payouts,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN status = 'paid' THEN net_payout ELSE 0 END) as total_paid,
            SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END) as total_fees,
            SUM(CASE WHEN status IN ('pending', 'processing', 'scheduled') THEN net_payout ELSE 0 END) as pending_amount,
            AVG(CASE WHEN status = 'paid' THEN net_payout ELSE NULL END) as avg_payout
        FROM om_market_partner_payouts
        WHERE partner_id = ?
    ");
    $stmtStats->execute([$partnerId]);
    $stats = $stmtStats->fetch();

    // Proximos repasses agendados
    $stmtUpcoming = $db->prepare("
        SELECT
            id,
            net_payout,
            period_end,
            status
        FROM om_market_partner_payouts
        WHERE partner_id = ?
          AND status IN ('scheduled', 'pending')
          AND period_end >= CURRENT_DATE
        ORDER BY period_end ASC
        LIMIT 5
    ");
    $stmtUpcoming->execute([$partnerId]);
    $upcoming = $stmtUpcoming->fetchAll(PDO::FETCH_ASSOC);

    $formattedUpcoming = [];
    foreach ($upcoming as $u) {
        $daysUntil = (int)ceil((strtotime($u['period_end']) - time()) / 86400);
        $formattedUpcoming[] = [
            'id' => (int)$u['id'],
            'net_amount' => round((float)$u['net_payout'], 2),
            'period_end' => $u['period_end'],
            'status' => $u['status'],
            'days_until' => max(0, $daysUntil),
        ];
    }

    // Configuracao de repasse do parceiro
    $stmtConfig = $db->prepare("
        SELECT
            payout_frequency,
            payout_day,
            min_payout,
            bank_name,
            bank_agency,
            bank_account,
            bank_account_type,
            pix_key,
            pix_key_type,
            auto_payout
        FROM om_payout_config
        WHERE partner_id = ?
    ");
    $stmtConfig->execute([$partnerId]);
    $config = $stmtConfig->fetch();

    $payoutConfig = null;
    if ($config) {
        $payoutConfig = [
            'frequency' => $config['payout_frequency'] ?? 'weekly',
            'frequency_label' => getFrequencyLabel($config['payout_frequency'] ?? 'weekly'),
            'payout_day' => (int)($config['payout_day'] ?? 1),
            'min_payout' => round((float)($config['min_payout'] ?? 50), 2),
            'bank_configured' => !empty($config['bank_name']) || !empty($config['pix_key']),
            'bank_name' => $config['bank_name'],
            'bank_agency' => maskBankAccount($config['bank_agency']),
            'bank_account' => maskBankAccount($config['bank_account']),
            'bank_account_type' => $config['bank_account_type'],
            'pix_key' => maskPixKey($config['pix_key']),
            'pix_key_type' => $config['pix_key_type'],
            'auto_payout' => (bool)($config['auto_payout'] ?? true),
        ];
    }

    response(true, [
        'payouts' => $formattedPayouts,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
        ],
        'stats' => [
            'total_payouts' => (int)$stats['total_payouts'],
            'completed_count' => (int)$stats['completed_count'],
            'total_paid' => round((float)($stats['total_paid'] ?? 0), 2),
            'total_fees' => round((float)($stats['total_fees'] ?? 0), 2),
            'pending_amount' => round((float)($stats['pending_amount'] ?? 0), 2),
            'avg_payout' => round((float)($stats['avg_payout'] ?? 0), 2),
        ],
        'upcoming' => $formattedUpcoming,
        'config' => $payoutConfig,
    ], "Historico de repasses carregado");

} catch (Exception $e) {
    error_log("[partner/payout-history] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

function getStatusLabel($status) {
    $labels = [
        'scheduled' => 'Agendado',
        'pending' => 'Pendente',
        'processing' => 'Processando',
        'completed' => 'Concluido',
        'failed' => 'Falhou',
        'cancelled' => 'Cancelado',
    ];
    return $labels[$status] ?? $status;
}

function getFrequencyLabel($frequency) {
    $labels = [
        'daily' => 'Diario',
        'weekly' => 'Semanal',
        'biweekly' => 'Quinzenal',
        'monthly' => 'Mensal',
    ];
    return $labels[$frequency] ?? $frequency;
}

function maskPixKey($key) {
    if (empty($key)) return null;
    $length = strlen($key);
    if ($length <= 4) return $key;
    return substr($key, 0, 4) . str_repeat('*', $length - 4);
}

function maskBankAccount($account) {
    if (empty($account)) return null;
    $length = strlen($account);
    if ($length <= 2) return $account;
    return str_repeat('*', $length - 2) . substr($account, -2);
}
