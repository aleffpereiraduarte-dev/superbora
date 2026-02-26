<?php
/**
 * PAINEL DO MERCADO - Antecipacao de Recebiveis
 * Permite que parceiros antecipem recebiveis futuros com taxa proporcional.
 */
session_start();

if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
$db = getDB();

$mercado_id = (int)$_SESSION['mercado_id'];
$mercado_nome = $_SESSION['mercado_nome'] ?? 'Mercado';

// ============================================================
// ENSURE TABLES EXIST
// ============================================================
$db->exec("
    CREATE TABLE IF NOT EXISTS om_anticipations (
        id SERIAL PRIMARY KEY,
        partner_id INT NOT NULL,
        requested_amount DECIMAL(10,2) NOT NULL,
        fee_percent DECIMAL(5,2) NOT NULL,
        fee_amount DECIMAL(10,2) NOT NULL,
        net_amount DECIMAL(10,2) NOT NULL,
        days_ahead INT NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        requested_at TIMESTAMP DEFAULT NOW(),
        processed_at TIMESTAMP,
        paid_at TIMESTAMP,
        rejection_reason TEXT,
        transaction_id VARCHAR(100)
    )
");
$db->exec("CREATE INDEX IF NOT EXISTS idx_anticipations_partner ON om_anticipations(partner_id, status)");

$db->exec("
    CREATE TABLE IF NOT EXISTS om_anticipation_fees (
        id SERIAL PRIMARY KEY,
        days_min INT NOT NULL,
        days_max INT NOT NULL,
        fee_percent DECIMAL(5,2) NOT NULL,
        active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT NOW()
    )
");

// Insert default fees if empty
$feeCount = (int)$db->query("SELECT COUNT(*) FROM om_anticipation_fees")->fetchColumn();
if ($feeCount === 0) {
    $db->exec("INSERT INTO om_anticipation_fees (days_min, days_max, fee_percent) VALUES (1, 7, 3.00)");
    $db->exec("INSERT INTO om_anticipation_fees (days_min, days_max, fee_percent) VALUES (8, 14, 5.00)");
    $db->exec("INSERT INTO om_anticipation_fees (days_min, days_max, fee_percent) VALUES (15, 30, 8.00)");
}

// ============================================================
// POST HANDLERS
// ============================================================
$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    // Simple CSRF check
    if (empty($csrfToken) || $csrfToken !== ($_SESSION['csrf_antecipacao'] ?? '')) {
        $msg = 'Token de seguranca invalido. Recarregue a pagina.';
        $msgType = 'error';
    } else {
        if ($action === 'request_anticipation') {
            $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
            $daysAhead = isset($_POST['days_ahead']) ? (int)$_POST['days_ahead'] : 0;

            // Validation
            if ($amount < 50) {
                $msg = 'Valor minimo para antecipacao: R$ 50,00';
                $msgType = 'error';
            } elseif ($daysAhead < 1 || $daysAhead > 30) {
                $msg = 'Prazo de antecipacao deve ser entre 1 e 30 dias';
                $msgType = 'error';
            } else {
                // Check for pending anticipation
                $stmtCheck = $db->prepare("SELECT id FROM om_anticipations WHERE partner_id = ? AND status = 'pending'");
                $stmtCheck->execute([$mercado_id]);
                if ($stmtCheck->fetch()) {
                    $msg = 'Voce ja possui uma solicitacao de antecipacao pendente. Aguarde o processamento ou cancele antes de solicitar outra.';
                    $msgType = 'error';
                } else {
                    // Get saldo pendente
                    $stmtSaldoCheck = $db->prepare("SELECT COALESCE(saldo_pendente, 0) as saldo_pendente FROM om_mercado_saldo WHERE partner_id = ?");
                    $stmtSaldoCheck->execute([$mercado_id]);
                    $saldoPendCheck = $stmtSaldoCheck->fetch();
                    $maxAvailable = (float)($saldoPendCheck['saldo_pendente'] ?? 0);

                    // Also add future repasses
                    $stmtRepFut = $db->prepare("
                        SELECT COALESCE(SUM(valor_liquido), 0) as total
                        FROM om_repasses
                        WHERE destinatario_id = ? AND tipo = 'mercado' AND status = 'pendente'
                    ");
                    $stmtRepFut->execute([$mercado_id]);
                    $maxAvailable += (float)$stmtRepFut->fetchColumn();

                    if ($amount > $maxAvailable && $maxAvailable > 0) {
                        $msg = 'Valor solicitado excede o disponivel (R$ ' . number_format($maxAvailable, 2, ',', '.') . ')';
                        $msgType = 'error';
                    } else {
                        // Get fee for days_ahead
                        $stmtFee = $db->prepare("
                            SELECT fee_percent FROM om_anticipation_fees
                            WHERE ? >= days_min AND ? <= days_max AND active = TRUE
                            LIMIT 1
                        ");
                        $stmtFee->execute([$daysAhead, $daysAhead]);
                        $feeRow = $stmtFee->fetch();
                        if ($feeRow) {
                            $feePercent = (float)$feeRow['fee_percent'];
                        } elseif ($daysAhead <= 7) {
                            $feePercent = 3.00;
                        } elseif ($daysAhead <= 14) {
                            $feePercent = 5.00;
                        } else {
                            $feePercent = 8.00;
                        }

                        $feeAmount = round($amount * $feePercent / 100, 2);
                        $netAmount = round($amount - $feeAmount, 2);

                        $stmtInsert = $db->prepare("
                            INSERT INTO om_anticipations (partner_id, requested_amount, fee_percent, fee_amount, net_amount, days_ahead, status)
                            VALUES (?, ?, ?, ?, ?, ?, 'pending')
                        ");
                        $stmtInsert->execute([$mercado_id, $amount, $feePercent, $feeAmount, $netAmount, $daysAhead]);

                        $msg = 'Antecipacao solicitada com sucesso! Voce recebera R$ ' . number_format($netAmount, 2, ',', '.') . ' em ate 24 horas uteis.';
                        $msgType = 'success';
                    }
                }
            }
        } elseif ($action === 'cancel_anticipation') {
            $anticipation_id = isset($_POST['anticipation_id']) ? (int)$_POST['anticipation_id'] : 0;
            if ($anticipation_id > 0) {
                $stmtCancel = $db->prepare("
                    UPDATE om_anticipations SET status = 'cancelled', processed_at = NOW()
                    WHERE id = ? AND partner_id = ? AND status = 'pending'
                ");
                $stmtCancel->execute([$anticipation_id, $mercado_id]);
                if ($stmtCancel->rowCount() > 0) {
                    $msg = 'Antecipacao cancelada com sucesso.';
                    $msgType = 'success';
                } else {
                    $msg = 'Nao foi possivel cancelar. A antecipacao pode ja ter sido processada.';
                    $msgType = 'error';
                }
            }
        }
    }
}

// Generate CSRF token
$_SESSION['csrf_antecipacao'] = bin2hex(random_bytes(16));
$csrfToken = $_SESSION['csrf_antecipacao'];

// ============================================================
// DATA QUERIES
// ============================================================

// Saldo
$stmtSaldo = $db->prepare("
    SELECT
        COALESCE(saldo_disponivel, 0) as saldo_disponivel,
        COALESCE(saldo_pendente, 0) as saldo_pendente
    FROM om_mercado_saldo WHERE partner_id = ?
");
$stmtSaldo->execute([$mercado_id]);
$saldo = $stmtSaldo->fetch();
if (!$saldo) {
    $saldo = ['saldo_disponivel' => 0, 'saldo_pendente' => 0];
}

// Payout config (for proximo repasse)
$stmtConfig = $db->prepare("
    SELECT payout_frequency, payout_day, auto_payout
    FROM om_payout_config WHERE partner_id = ?
");
$stmtConfig->execute([$mercado_id]);
$payoutConfig = $stmtConfig->fetch();

// Calculate next payout date
$proximoRepasse = '---';
if ($payoutConfig) {
    $freq = $payoutConfig['payout_frequency'] ?? 'weekly';
    $dayConfig = (int)($payoutConfig['payout_day'] ?? 5); // Friday default
    $today = new DateTime();

    if ($freq === 'daily') {
        $next = clone $today;
        $next->modify('+1 day');
        $proximoRepasse = $next->format('d/m/Y');
    } elseif ($freq === 'weekly') {
        $daysOfWeek = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
        $targetDay = $daysOfWeek[$dayConfig] ?? 'Friday';
        $next = clone $today;
        $next->modify("next $targetDay");
        $proximoRepasse = $next->format('d/m/Y');
    } elseif ($freq === 'biweekly') {
        $next = clone $today;
        $next->modify('+14 days');
        $proximoRepasse = $next->format('d/m/Y');
    } elseif ($freq === 'monthly') {
        $next = clone $today;
        $next->modify('first day of next month');
        if ($dayConfig > 1) {
            $next->modify('+' . ($dayConfig - 1) . ' days');
        }
        $proximoRepasse = $next->format('d/m/Y');
    }
}

// Total antecipado este mes
$stmtTotalMes = $db->prepare("
    SELECT COALESCE(SUM(net_amount), 0) as total
    FROM om_anticipations
    WHERE partner_id = ? AND status = 'paid'
      AND requested_at >= date_trunc('month', CURRENT_DATE)
");
$stmtTotalMes->execute([$mercado_id]);
$totalAntecipadoMes = (float)$stmtTotalMes->fetchColumn();

// Fee table
$stmtFees = $db->query("
    SELECT days_min, days_max, fee_percent
    FROM om_anticipation_fees
    WHERE active = TRUE
    ORDER BY days_min ASC
");
$feeRanges = $stmtFees->fetchAll();
if (empty($feeRanges)) {
    $feeRanges = [
        ['days_min' => 1, 'days_max' => 7, 'fee_percent' => 3.00],
        ['days_min' => 8, 'days_max' => 14, 'fee_percent' => 5.00],
        ['days_min' => 15, 'days_max' => 30, 'fee_percent' => 8.00],
    ];
}

// Pending receivables (future repasses)
$stmtRecebiveis = $db->prepare("
    SELECT
        id, order_id, valor_bruto, taxa_plataforma, valor_liquido, status, created_at,
        created_at::date as data_prevista
    FROM om_repasses
    WHERE destinatario_id = ? AND tipo = 'mercado' AND status = 'pendente'
    ORDER BY created_at ASC
");
$stmtRecebiveis->execute([$mercado_id]);
$recebiveis = $stmtRecebiveis->fetchAll();

// Group receivables by date
$recebiveisPorData = [];
foreach ($recebiveis as $r) {
    $data = $r['data_prevista'];
    if (!isset($recebiveisPorData[$data])) {
        $recebiveisPorData[$data] = [
            'data' => $data,
            'pedidos' => 0,
            'valor_bruto' => 0,
            'comissao' => 0,
            'valor_liquido' => 0,
        ];
    }
    $recebiveisPorData[$data]['pedidos']++;
    $recebiveisPorData[$data]['valor_bruto'] += (float)$r['valor_bruto'];
    $recebiveisPorData[$data]['comissao'] += (float)$r['taxa_plataforma'];
    $recebiveisPorData[$data]['valor_liquido'] += (float)$r['valor_liquido'];
}
ksort($recebiveisPorData);
$totalRecebiveisFuturos = array_sum(array_column($recebiveisPorData, 'valor_liquido'));

// Anticipation history with pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$stmtCount = $db->prepare("SELECT COUNT(*) FROM om_anticipations WHERE partner_id = ?");
$stmtCount->execute([$mercado_id]);
$totalAnticipations = (int)$stmtCount->fetchColumn();
$totalPages = max(1, ceil($totalAnticipations / $perPage));

$stmtHistory = $db->prepare("
    SELECT id, requested_amount, fee_percent, fee_amount, net_amount, days_ahead, status, requested_at, processed_at, paid_at, rejection_reason
    FROM om_anticipations
    WHERE partner_id = ?
    ORDER BY requested_at DESC
    LIMIT ? OFFSET ?
");
$stmtHistory->execute([$mercado_id, $perPage, $offset]);
$anticipations = $stmtHistory->fetchAll();

// Has pending anticipation?
$stmtPending = $db->prepare("SELECT COUNT(*) FROM om_anticipations WHERE partner_id = ? AND status = 'pending'");
$stmtPending->execute([$mercado_id]);
$hasPendingAnticipation = (int)$stmtPending->fetchColumn() > 0;

// Max anticipable = saldo_pendente + future receivables
$maxAnticipable = (float)$saldo['saldo_pendente'] + $totalRecebiveisFuturos;

$statusColors = [
    'pending' => '#f59e0b',
    'approved' => '#3b82f6',
    'paid' => '#10b981',
    'rejected' => '#ef4444',
    'cancelled' => '#9ca3af',
];
$statusLabels = [
    'pending' => 'Pendente',
    'approved' => 'Aprovado',
    'paid' => 'Pago',
    'rejected' => 'Rejeitado',
    'cancelled' => 'Cancelado',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Antecipacao de Recebiveis - <?= htmlspecialchars($mercado_nome) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
</head>
<body class="om-app-layout">
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <img src="/assets/img/logo-onemundo-white.png" alt="OneMundo" class="om-sidebar-logo"
                 onerror="this.outerHTML='<span class=\'om-sidebar-logo-text\'>SuperBora</span>'">
        </div>
        <nav class="om-sidebar-nav">
            <a href="index.php" class="om-sidebar-link"><i class="lucide-layout-dashboard"></i><span>Dashboard</span></a>
            <a href="pedidos.php" class="om-sidebar-link"><i class="lucide-shopping-bag"></i><span>Pedidos</span></a>
            <a href="produtos.php" class="om-sidebar-link"><i class="lucide-package"></i><span>Produtos</span></a>
            <a href="categorias.php" class="om-sidebar-link"><i class="lucide-tags"></i><span>Categorias</span></a>
            <a href="promocoes.php" class="om-sidebar-link"><i class="lucide-percent"></i><span>Promocoes</span></a>
            <div class="om-sidebar-divider"></div>
            <div class="om-sidebar-section-label">Financeiro</div>
            <a href="faturamento.php" class="om-sidebar-link"><i class="lucide-bar-chart-3"></i><span>Faturamento</span></a>
            <a href="repasses.php" class="om-sidebar-link"><i class="lucide-wallet"></i><span>Repasses</span></a>
            <a href="antecipacao.php" class="om-sidebar-link active"><i class="lucide-zap"></i><span>Antecipacao</span></a>
            <div class="om-sidebar-divider"></div>
            <a href="avaliacoes.php" class="om-sidebar-link"><i class="lucide-star"></i><span>Avaliacoes</span></a>
            <div class="om-sidebar-divider"></div>
            <div class="om-sidebar-section-label">Configuracoes</div>
            <a href="perfil.php" class="om-sidebar-link"><i class="lucide-settings"></i><span>Perfil</span></a>
            <a href="horarios.php" class="om-sidebar-link"><i class="lucide-clock"></i><span>Horarios</span></a>
        </nav>
        <div class="om-sidebar-footer">
            <a href="logout.php" class="om-sidebar-link"><i class="lucide-log-out"></i><span>Sair</span></a>
        </div>
    </aside>

    <main class="om-main-content">
        <header class="om-topbar">
            <button class="om-sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                <i class="lucide-menu"></i>
            </button>
            <h1 class="om-topbar-title">Antecipacao de Recebiveis</h1>
            <div class="om-topbar-actions">
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($mercado_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <div class="om-page-content">

            <!-- Page Subtitle + Info -->
            <div class="page-header-row">
                <div>
                    <p class="page-subtitle">Receba antes com uma pequena taxa</p>
                </div>
                <div class="info-tooltip" id="infoToggle">
                    <i class="lucide-info"></i>
                    <div class="info-tooltip-content" id="infoContent">
                        A antecipacao permite que voce receba valores futuros hoje, com desconto de uma taxa proporcional aos dias antecipados. O valor e creditado em ate 24 horas uteis apos a aprovacao.
                    </div>
                </div>
            </div>

            <!-- Flash Messages -->
            <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType === 'success' ? 'green' : 'red' ?>">
                <i class="lucide-<?= $msgType === 'success' ? 'check-circle' : 'alert-triangle' ?>"></i>
                <div><?= htmlspecialchars($msg) ?></div>
            </div>
            <?php endif; ?>

            <!-- ===== SUMMARY CARDS ===== -->
            <div class="card-grid-4">
                <div class="summary-card sc-green">
                    <div class="sc-icon"><i class="lucide-wallet"></i></div>
                    <div class="sc-body">
                        <div class="sc-label">Saldo Disponivel</div>
                        <div class="sc-value sc-value-lg">R$ <?= number_format(max(0, $saldo['saldo_disponivel']), 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="summary-card sc-blue">
                    <div class="sc-icon"><i class="lucide-clock"></i></div>
                    <div class="sc-body">
                        <div class="sc-label">Saldo Pendente</div>
                        <div class="sc-value">R$ <?= number_format($saldo['saldo_pendente'], 2, ',', '.') ?></div>
                        <div class="sc-hint">Disponivel para antecipacao</div>
                    </div>
                </div>
                <div class="summary-card sc-gray">
                    <div class="sc-icon"><i class="lucide-calendar"></i></div>
                    <div class="sc-body">
                        <div class="sc-label">Proximo Repasse</div>
                        <div class="sc-value"><?= htmlspecialchars($proximoRepasse) ?></div>
                        <?php if ($payoutConfig): ?>
                        <div class="sc-hint"><?= ucfirst($payoutConfig['payout_frequency'] ?? 'semanal') ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="summary-card sc-purple">
                    <div class="sc-icon"><i class="lucide-zap"></i></div>
                    <div class="sc-body">
                        <div class="sc-label">Total Antecipado</div>
                        <div class="sc-value">R$ <?= number_format($totalAntecipadoMes, 2, ',', '.') ?></div>
                        <div class="sc-hint">Este mes</div>
                    </div>
                </div>
            </div>

            <!-- Pending anticipation alert -->
            <?php if ($hasPendingAnticipation): ?>
            <div class="alert alert-yellow">
                <i class="lucide-loader ant-spin"></i>
                <div>
                    <strong>Antecipacao em andamento</strong><br>
                    Voce tem uma solicitacao pendente sendo analisada. Novas solicitacoes serao liberadas apos o processamento.
                </div>
            </div>
            <?php endif; ?>

            <div class="two-col-layout">
                <!-- LEFT COLUMN -->
                <div class="col-left">

                    <!-- Fee Table -->
                    <div class="ant-card">
                        <div class="ant-card-header">
                            <h3><i class="lucide-percent"></i> Tabela de Taxas de Antecipacao</h3>
                        </div>
                        <div class="ant-card-body">
                            <table class="ant-table">
                                <thead>
                                    <tr>
                                        <th>Prazo</th>
                                        <th class="r">Taxa</th>
                                        <th class="r">Exemplo (R$ 100)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($feeRanges as $fee): ?>
                                    <tr>
                                        <td><span class="badge-days"><?= $fee['days_min'] ?>-<?= $fee['days_max'] ?> dias</span></td>
                                        <td class="r bold"><?= number_format($fee['fee_percent'], 1, ',', '.') ?>%</td>
                                        <td class="r text-green">R$ <?= number_format(100 - (100 * $fee['fee_percent'] / 100), 2, ',', '.') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="ant-card-footer-note">
                                <i class="lucide-info" style="font-size:12px"></i> Taxas aplicadas sobre o valor bruto antecipado
                            </div>
                        </div>
                    </div>

                    <!-- Pending Receivables -->
                    <div class="ant-card">
                        <div class="ant-card-header">
                            <h3><i class="lucide-banknote"></i> Recebiveis Futuros</h3>
                            <?php if ($totalRecebiveisFuturos > 0): ?>
                            <span class="header-badge">R$ <?= number_format($totalRecebiveisFuturos, 2, ',', '.') ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="ant-card-body">
                            <?php if (empty($recebiveisPorData)): ?>
                            <div class="empty-state">
                                <i class="lucide-inbox"></i>
                                <p>Nenhum recebivel futuro pendente</p>
                                <small>Novos recebiveis aparecerao apos pedidos entregues</small>
                            </div>
                            <?php else: ?>
                            <div class="ant-table-responsive">
                                <table class="ant-table">
                                    <thead>
                                        <tr>
                                            <th>Data Prevista</th>
                                            <th class="r">Pedidos</th>
                                            <th class="r">Bruto</th>
                                            <th class="r">Comissao</th>
                                            <th class="r">Liquido</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recebiveisPorData as $grupo): ?>
                                        <tr>
                                            <td class="mono"><?= date('d/m/Y', strtotime($grupo['data'])) ?></td>
                                            <td class="r"><?= $grupo['pedidos'] ?></td>
                                            <td class="r">R$ <?= number_format($grupo['valor_bruto'], 2, ',', '.') ?></td>
                                            <td class="r muted">-R$ <?= number_format($grupo['comissao'], 2, ',', '.') ?></td>
                                            <td class="r bold">R$ <?= number_format($grupo['valor_liquido'], 2, ',', '.') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td><strong>Total</strong></td>
                                            <td class="r"><strong><?= array_sum(array_column($recebiveisPorData, 'pedidos')) ?></strong></td>
                                            <td class="r"><strong>R$ <?= number_format(array_sum(array_column($recebiveisPorData, 'valor_bruto')), 2, ',', '.') ?></strong></td>
                                            <td class="r muted"><strong>-R$ <?= number_format(array_sum(array_column($recebiveisPorData, 'comissao')), 2, ',', '.') ?></strong></td>
                                            <td class="r bold text-green"><strong>R$ <?= number_format($totalRecebiveisFuturos, 2, ',', '.') ?></strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN -->
                <div class="col-right">
                    <!-- Simulator -->
                    <div class="ant-card sim-card">
                        <div class="ant-card-header">
                            <h3><i class="lucide-calculator"></i> Simulador de Antecipacao</h3>
                        </div>
                        <div class="ant-card-body">
                            <div class="sim-field">
                                <label>Valor para antecipar (R$)</label>
                                <div class="sim-input-wrap">
                                    <span class="sim-prefix">R$</span>
                                    <input type="number" id="sim-valor" min="50" max="<?= number_format($maxAnticipable, 2, '.', '') ?>"
                                           step="10" value="<?= $maxAnticipable >= 50 ? min(100, $maxAnticipable) : 0 ?>"
                                           oninput="updateSimulation()">
                                </div>
                                <div class="sim-range-wrap">
                                    <input type="range" id="sim-valor-range" min="50" max="<?= max(50, $maxAnticipable) ?>"
                                           step="10" value="<?= $maxAnticipable >= 50 ? min(100, $maxAnticipable) : 50 ?>"
                                           oninput="document.getElementById('sim-valor').value=this.value;updateSimulation()">
                                    <div class="sim-range-labels">
                                        <span>R$ 50</span>
                                        <span>R$ <?= number_format(max(50, $maxAnticipable), 0, ',', '.') ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="sim-field">
                                <label>Dias de antecipacao</label>
                                <div class="sim-days-display">
                                    <span id="sim-days-val">7</span> dias
                                </div>
                                <div class="sim-range-wrap">
                                    <input type="range" id="sim-dias" min="1" max="30" step="1" value="7"
                                           oninput="document.getElementById('sim-days-val').textContent=this.value;updateSimulation()">
                                    <div class="sim-range-labels">
                                        <span>1 dia</span>
                                        <span>30 dias</span>
                                    </div>
                                </div>
                            </div>

                            <div class="sim-divider"></div>

                            <div class="sim-result">
                                <div class="sim-result-row">
                                    <span>Valor solicitado</span>
                                    <span id="sim-requested">R$ 0,00</span>
                                </div>
                                <div class="sim-result-row sim-fee">
                                    <span>Taxa (<span id="sim-fee-pct">0</span>%)</span>
                                    <span id="sim-fee-val">-R$ 0,00</span>
                                </div>
                                <div class="sim-result-row sim-net">
                                    <span>Valor liquido</span>
                                    <strong id="sim-net-val">R$ 0,00</strong>
                                </div>
                            </div>

                            <?php if ($hasPendingAnticipation): ?>
                            <button class="btn-antecipar" disabled title="Aguarde o processamento da antecipacao pendente">
                                <i class="lucide-loader ant-spin"></i> Antecipacao Pendente
                            </button>
                            <?php elseif ($maxAnticipable < 50): ?>
                            <button class="btn-antecipar" disabled title="Saldo insuficiente para antecipacao">
                                <i class="lucide-zap"></i> Saldo Insuficiente (min R$ 50)
                            </button>
                            <?php else: ?>
                            <button class="btn-antecipar" id="btn-solicitar" onclick="openConfirmModal()">
                                <i class="lucide-zap"></i> Solicitar Antecipacao
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== ANTICIPATION HISTORY ===== -->
            <div class="ant-card" style="margin-top:24px">
                <div class="ant-card-header">
                    <h3><i class="lucide-history"></i> Historico de Antecipacoes</h3>
                    <?php if ($totalAnticipations > 0): ?>
                    <span class="header-badge header-badge-gray"><?= $totalAnticipations ?> total</span>
                    <?php endif; ?>
                </div>
                <div class="ant-card-body">
                    <?php if (empty($anticipations)): ?>
                    <div class="empty-state">
                        <i class="lucide-zap"></i>
                        <p>Nenhuma antecipacao solicitada</p>
                        <small>Use o simulador acima para solicitar sua primeira antecipacao</small>
                    </div>
                    <?php else: ?>
                    <div class="ant-table-responsive">
                        <table class="ant-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th class="r">Valor Solicitado</th>
                                    <th class="r">Taxa</th>
                                    <th class="r">Valor Liquido</th>
                                    <th>Dias</th>
                                    <th>Status</th>
                                    <th>Acoes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($anticipations as $a): ?>
                                <tr>
                                    <td class="mono"><?= date('d/m/Y H:i', strtotime($a['requested_at'])) ?></td>
                                    <td class="r">R$ <?= number_format($a['requested_amount'], 2, ',', '.') ?></td>
                                    <td class="r muted"><?= number_format($a['fee_percent'], 1, ',', '.') ?>% (-R$ <?= number_format($a['fee_amount'], 2, ',', '.') ?>)</td>
                                    <td class="r bold">R$ <?= number_format($a['net_amount'], 2, ',', '.') ?></td>
                                    <td><?= $a['days_ahead'] ?>d</td>
                                    <td>
                                        <span class="status-dot" style="background:<?= $statusColors[$a['status']] ?? '#9ca3af' ?>"></span>
                                        <span class="status-label" style="color:<?= $statusColors[$a['status']] ?? '#9ca3af' ?>"><?= $statusLabels[$a['status']] ?? $a['status'] ?></span>
                                        <?php if ($a['rejection_reason']): ?>
                                        <br><small class="muted" title="<?= htmlspecialchars($a['rejection_reason']) ?>"><?= htmlspecialchars(mb_substr($a['rejection_reason'], 0, 40)) ?></small>
                                        <?php endif; ?>
                                        <?php if ($a['paid_at']): ?>
                                        <br><small class="muted">Pago: <?= date('d/m H:i', strtotime($a['paid_at'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($a['status'] === 'pending'): ?>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Cancelar esta antecipacao?')">
                                            <input type="hidden" name="action" value="cancel_anticipation">
                                            <input type="hidden" name="anticipation_id" value="<?= $a['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <button type="submit" class="btn-cancel-small" title="Cancelar solicitacao">
                                                <i class="lucide-x"></i>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="muted">---</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>" class="pg-btn"><i class="lucide-chevron-left"></i></a>
                        <?php endif; ?>
                        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                        <a href="?page=<?= $p ?>" class="pg-btn <?= $p === $page ? 'pg-active' : '' ?>"><?= $p ?></a>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="pg-btn"><i class="lucide-chevron-right"></i></a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Info Box -->
            <div class="alert alert-info-box" style="margin-top:24px">
                <i class="lucide-info"></i>
                <div>
                    <strong>Como funciona a antecipacao</strong><br>
                    Seus recebiveis futuros (repasses pendentes) podem ser antecipados com uma taxa proporcional ao prazo.
                    Quanto mais proximo o vencimento, menor a taxa. Apos aprovacao, o valor liquido e creditado em ate 24 horas uteis.
                    Voce pode cancelar solicitacoes pendentes a qualquer momento.
                </div>
            </div>
        </div>
    </main>

    <!-- ===== CONFIRMATION MODAL ===== -->
    <div class="modal-bg" id="confirmModal" onclick="if(event.target===this)closeConfirmModal()">
        <div class="modal">
            <div class="modal-head">
                <h3><i class="lucide-zap"></i> Confirmar Antecipacao</h3>
                <button onclick="closeConfirmModal()" class="modal-x">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-summary">
                    <div class="modal-summary-row">
                        <span>Valor solicitado</span>
                        <strong id="modal-requested">R$ 0,00</strong>
                    </div>
                    <div class="modal-summary-row">
                        <span>Dias de antecipacao</span>
                        <strong id="modal-days">0 dias</strong>
                    </div>
                    <div class="modal-summary-row modal-fee-row">
                        <span>Taxa (<span id="modal-fee-pct">0</span>%)</span>
                        <strong id="modal-fee">-R$ 0,00</strong>
                    </div>
                    <div class="modal-summary-divider"></div>
                    <div class="modal-summary-row modal-net-row">
                        <span>Valor liquido a receber</span>
                        <strong id="modal-net" class="text-green">R$ 0,00</strong>
                    </div>
                </div>
                <div class="modal-warning">
                    <i class="lucide-alert-triangle"></i>
                    <span>Ao confirmar, o valor sera creditado em ate 24h uteis e a taxa sera descontada do valor bruto.</span>
                </div>
            </div>
            <div class="modal-foot">
                <button onclick="closeConfirmModal()" class="mbtn mbtn-cancel">Cancelar</button>
                <form method="POST" id="formAntecipar" style="display:inline">
                    <input type="hidden" name="action" value="request_anticipation">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="amount" id="form-amount" value="">
                    <input type="hidden" name="days_ahead" id="form-days" value="">
                    <button type="submit" class="mbtn mbtn-go" id="btn-confirm-submit">
                        <i class="lucide-zap"></i> Confirmar Antecipacao
                    </button>
                </form>
            </div>
        </div>
    </div>

    <style>
    *{box-sizing:border-box}

    /* Page Header Row */
    .page-header-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
    .page-subtitle{color:#6b7280;font-size:15px;margin:0}

    /* Info Tooltip */
    .info-tooltip{position:relative;cursor:pointer;color:#6b7280;font-size:18px}
    .info-tooltip-content{display:none;position:absolute;right:0;top:32px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;width:320px;font-size:13px;color:#374151;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:100;line-height:1.6}
    .info-tooltip.open .info-tooltip-content{display:block}

    /* Summary Cards Grid */
    .card-grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
    .summary-card{background:#fff;border-radius:16px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;align-items:center;gap:14px;border:1px solid #f1f5f9}
    .sc-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
    .sc-green .sc-icon{background:#d1fae5;color:#059669}
    .sc-blue .sc-icon{background:#dbeafe;color:#3b82f6}
    .sc-gray .sc-icon{background:#f3f4f6;color:#6b7280}
    .sc-purple .sc-icon{background:#ede9fe;color:#7c3aed}
    .sc-label{font-size:13px;color:#6b7280;font-weight:500}
    .sc-value{font-size:20px;font-weight:700;color:#1f2937;letter-spacing:-.3px}
    .sc-value-lg{font-size:26px;font-weight:800;letter-spacing:-.5px}
    .sc-hint{font-size:11px;color:#9ca3af;margin-top:2px}

    /* Alerts */
    .alert{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:12px;font-size:14px;margin-bottom:16px;line-height:1.5}
    .alert i{flex-shrink:0;margin-top:2px}
    .alert-green{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
    .alert-red{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
    .alert-yellow{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
    .alert-info-box{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
    .ant-spin{animation:antspin 1s linear infinite}
    @keyframes antspin{to{transform:rotate(360deg)}}

    /* Two Column Layout */
    .two-col-layout{display:grid;grid-template-columns:1.2fr 1fr;gap:24px}
    .col-left,.col-right{display:flex;flex-direction:column;gap:20px}

    /* Cards */
    .ant-card{background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);border:1px solid #f1f5f9;overflow:hidden}
    .ant-card-header{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid #f1f5f9}
    .ant-card-header h3{margin:0;font-size:16px;font-weight:600;display:flex;align-items:center;gap:8px;color:#1f2937}
    .ant-card-header h3 i{font-size:18px;color:#6b7280}
    .ant-card-body{padding:20px}
    .ant-card-footer-note{padding:12px 0 0;border-top:1px solid #f1f5f9;margin-top:12px;font-size:12px;color:#9ca3af;display:flex;align-items:center;gap:6px}

    .header-badge{background:#d1fae5;color:#065f46;font-size:13px;font-weight:600;padding:4px 12px;border-radius:20px}
    .header-badge-gray{background:#f3f4f6;color:#6b7280}

    /* Tables */
    .ant-table-responsive{overflow-x:auto}
    .ant-table{width:100%;border-collapse:collapse;font-size:14px}
    .ant-table th{text-align:left;padding:10px 12px;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #f1f5f9}
    .ant-table td{padding:12px;border-bottom:1px solid #f8fafc}
    .ant-table tbody tr:hover{background:#f8fafc}
    .ant-table tfoot td{border-top:2px solid #e5e7eb;background:#f8fafc}
    .r{text-align:right}.bold{font-weight:600}.mono{font-family:monospace;font-size:13px}.muted{color:#9ca3af}
    .text-green{color:#059669}

    .badge-days{background:#eff6ff;color:#1e40af;font-size:12px;font-weight:600;padding:4px 10px;border-radius:8px;white-space:nowrap}

    .status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;vertical-align:middle}
    .status-label{font-size:13px;font-weight:500}

    /* Simulator */
    .sim-card{border:2px solid #d1fae5}
    .sim-field{margin-bottom:20px}
    .sim-field label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:8px}
    .sim-input-wrap{display:flex;align-items:center;border:2px solid #e5e7eb;border-radius:12px;overflow:hidden;transition:.2s}
    .sim-input-wrap:focus-within{border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.15)}
    .sim-prefix{padding:0 4px 0 16px;font-size:16px;font-weight:600;color:#6b7280}
    .sim-input-wrap input[type=number]{border:none;outline:none;flex:1;padding:12px 16px 12px 4px;font-size:22px;font-weight:700;font-family:inherit;width:100%}
    .sim-range-wrap{margin-top:8px}
    .sim-range-wrap input[type=range]{width:100%;accent-color:#10b981;height:6px;cursor:pointer}
    .sim-range-labels{display:flex;justify-content:space-between;font-size:11px;color:#9ca3af;margin-top:4px}
    .sim-days-display{font-size:28px;font-weight:800;color:#1f2937;margin-bottom:4px}
    .sim-days-display span{color:#10b981}
    .sim-divider{height:1px;background:#e5e7eb;margin:20px 0}

    .sim-result{display:flex;flex-direction:column;gap:10px;margin-bottom:20px}
    .sim-result-row{display:flex;justify-content:space-between;align-items:center;font-size:14px;color:#6b7280}
    .sim-fee span:last-child{color:#ef4444;font-weight:500}
    .sim-net{background:#f0fdf4;margin:0 -20px;padding:14px 20px;border-radius:0}
    .sim-net span{font-size:15px;color:#065f46;font-weight:600}
    .sim-net strong{font-size:22px;color:#059669}

    .btn-antecipar{width:100%;padding:14px;border:none;border-radius:12px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-size:15px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:.2s}
    .btn-antecipar:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 4px 12px rgba(16,185,129,.3)}
    .btn-antecipar:disabled{opacity:.5;cursor:not-allowed;background:#9ca3af}

    .btn-cancel-small{background:none;border:1px solid #fecaca;color:#ef4444;border-radius:8px;padding:5px 10px;cursor:pointer;font-size:12px;transition:.15s;display:inline-flex;align-items:center;gap:4px}
    .btn-cancel-small:hover{background:#fef2f2}

    /* Empty State */
    .empty-state{text-align:center;padding:40px 16px;color:#9ca3af}
    .empty-state i{font-size:40px;display:block;margin-bottom:12px}
    .empty-state p{font-size:15px;margin:4px 0}
    .empty-state small{font-size:12px}

    /* Pagination */
    .pagination{display:flex;gap:6px;justify-content:center;padding:16px 0 0;border-top:1px solid #f1f5f9;margin-top:16px}
    .pg-btn{display:flex;align-items:center;justify-content:center;min-width:36px;height:36px;border-radius:8px;border:1px solid #e5e7eb;background:#fff;color:#374151;font-size:14px;font-weight:500;text-decoration:none;cursor:pointer;transition:.15s}
    .pg-btn:hover{background:#f3f4f6}.pg-active{background:#10b981;color:#fff;border-color:#10b981}

    /* Modal */
    .modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:1000;backdrop-filter:blur(4px)}
    .modal-bg.open{display:flex}
    .modal{background:#fff;border-radius:20px;width:480px;max-width:92vw;box-shadow:0 24px 80px rgba(0,0,0,.2);animation:modalIn .2s ease-out}
    @keyframes modalIn{from{opacity:0;transform:scale(.95)translateY(10px)}to{opacity:1;transform:none}}
    .modal-head{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid #f1f5f9}
    .modal-head h3{font-size:17px;font-weight:600;margin:0;display:flex;align-items:center;gap:8px}
    .modal-x{background:none;border:none;font-size:28px;cursor:pointer;color:#9ca3af;line-height:1;padding:0 4px}
    .modal-body{padding:24px}
    .modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:16px 24px;border-top:1px solid #f1f5f9}

    .modal-summary{display:flex;flex-direction:column;gap:12px}
    .modal-summary-row{display:flex;justify-content:space-between;align-items:center;font-size:14px}
    .modal-summary-row span{color:#6b7280}
    .modal-summary-row strong{color:#1f2937}
    .modal-fee-row strong{color:#ef4444}
    .modal-net-row strong{font-size:20px}
    .modal-summary-divider{height:1px;background:#e5e7eb;margin:4px 0}

    .modal-warning{display:flex;align-items:flex-start;gap:10px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-top:20px;font-size:13px;color:#92400e;line-height:1.5}
    .modal-warning i{flex-shrink:0;margin-top:2px}

    .mbtn{padding:10px 22px;border-radius:12px;cursor:pointer;font-weight:600;font-size:14px;border:none;transition:.2s;display:flex;align-items:center;gap:6px}
    .mbtn-cancel{background:#f3f4f6;color:#374151}.mbtn-cancel:hover{background:#e5e7eb}
    .mbtn-go{background:#10b981;color:#fff}.mbtn-go:hover{background:#059669}

    /* Sidebar helpers */
    .om-sidebar-divider{height:1px;background:rgba(255,255,255,.1);margin:8px 16px}
    .om-sidebar-section-label{font-size:11px;text-transform:uppercase;letter-spacing:1px;color:rgba(255,255,255,.4);padding:8px 20px 4px;font-weight:600}

    /* Responsive */
    @media(max-width:1100px){
        .card-grid-4{grid-template-columns:repeat(2,1fr)}
        .two-col-layout{grid-template-columns:1fr}
    }
    @media(max-width:640px){
        .card-grid-4{grid-template-columns:1fr}
        .ant-table{font-size:12px}
        .ant-table th,.ant-table td{padding:8px 6px}
    }
    </style>

    <script>
    // Fee ranges from PHP
    var feeRanges = <?= json_encode(array_map(function($f) {
        return ['min' => (int)$f['days_min'], 'max' => (int)$f['days_max'], 'pct' => (float)$f['fee_percent']];
    }, $feeRanges)) ?>;

    var maxAnticipable = <?= json_encode($maxAnticipable) ?>;

    function getFeePercent(days) {
        for (var i = 0; i < feeRanges.length; i++) {
            if (days >= feeRanges[i].min && days <= feeRanges[i].max) {
                return feeRanges[i].pct;
            }
        }
        // Fallback
        if (days <= 7) return 3;
        if (days <= 14) return 5;
        return 8;
    }

    function formatBRL(val) {
        return 'R$ ' + val.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function updateSimulation() {
        var valor = parseFloat(document.getElementById('sim-valor').value) || 0;
        var dias = parseInt(document.getElementById('sim-dias').value) || 1;
        var feePct = getFeePercent(dias);
        var feeVal = Math.round(valor * feePct) / 100;
        var netVal = Math.round((valor - feeVal) * 100) / 100;

        document.getElementById('sim-requested').textContent = formatBRL(valor);
        document.getElementById('sim-fee-pct').textContent = feePct.toFixed(1).replace('.', ',');
        document.getElementById('sim-fee-val').textContent = '-' + formatBRL(feeVal);
        document.getElementById('sim-net-val').textContent = formatBRL(Math.max(0, netVal));

        // Sync range -> input
        var rangeEl = document.getElementById('sim-valor-range');
        if (rangeEl && document.activeElement !== rangeEl) {
            rangeEl.value = valor;
        }
    }

    function openConfirmModal() {
        var valor = parseFloat(document.getElementById('sim-valor').value) || 0;
        var dias = parseInt(document.getElementById('sim-dias').value) || 1;

        if (valor < 50) {
            alert('Valor minimo para antecipacao: R$ 50,00');
            return;
        }
        if (valor > maxAnticipable && maxAnticipable > 0) {
            alert('Valor excede o disponivel para antecipacao: ' + formatBRL(maxAnticipable));
            return;
        }

        var feePct = getFeePercent(dias);
        var feeVal = Math.round(valor * feePct) / 100;
        var netVal = Math.round((valor - feeVal) * 100) / 100;

        document.getElementById('modal-requested').textContent = formatBRL(valor);
        document.getElementById('modal-days').textContent = dias + ' dia' + (dias > 1 ? 's' : '');
        document.getElementById('modal-fee-pct').textContent = feePct.toFixed(1).replace('.', ',');
        document.getElementById('modal-fee').textContent = '-' + formatBRL(feeVal);
        document.getElementById('modal-net').textContent = formatBRL(netVal);

        document.getElementById('form-amount').value = valor.toFixed(2);
        document.getElementById('form-days').value = dias;

        document.getElementById('confirmModal').classList.add('open');
    }

    function closeConfirmModal() {
        document.getElementById('confirmModal').classList.remove('open');
    }

    // Info tooltip toggle
    document.getElementById('infoToggle').addEventListener('click', function(e) {
        e.stopPropagation();
        this.classList.toggle('open');
    });
    document.addEventListener('click', function() {
        document.getElementById('infoToggle').classList.remove('open');
    });

    // Prevent double submit
    document.getElementById('formAntecipar').addEventListener('submit', function() {
        var btn = document.getElementById('btn-confirm-submit');
        btn.disabled = true;
        btn.innerHTML = '<i class="lucide-loader ant-spin"></i> Processando...';
    });

    // Initialize
    updateSimulation();
    </script>
</body>
</html>
