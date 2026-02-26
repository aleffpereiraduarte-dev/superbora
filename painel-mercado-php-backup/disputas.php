<?php
/**
 * PAINEL DO MERCADO - Gestao de Disputas
 * Visualizar e responder disputas de clientes
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

// ── POST: Aceitar disputa ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $dispute_id = (int)($_POST['dispute_id'] ?? 0);

    if (!$dispute_id) {
        echo json_encode(['success' => false, 'message' => 'ID da disputa invalido']);
        exit;
    }

    // Verify dispute belongs to this partner
    $stmtCheck = $db->prepare("
        SELECT dispute_id, status FROM om_order_disputes
        WHERE dispute_id = ? AND partner_id = ?
    ");
    $stmtCheck->execute([$dispute_id, $mercado_id]);
    $dispute = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$dispute) {
        echo json_encode(['success' => false, 'message' => 'Disputa nao encontrada']);
        exit;
    }

    if (in_array($dispute['status'], ['auto_resolved', 'resolved', 'closed'])) {
        echo json_encode(['success' => false, 'message' => 'Esta disputa ja foi resolvida']);
        exit;
    }

    try {
        $db->beginTransaction();

        if ($action === 'accept_dispute') {
            $db->prepare("
                UPDATE om_order_disputes
                SET partner_response = 'Aceito pelo parceiro',
                    status = 'resolved',
                    resolution_type = 'partner_accepted',
                    resolution_note = 'Parceiro aceitou a disputa',
                    resolved_at = NOW(),
                    updated_at = NOW()
                WHERE dispute_id = ? AND partner_id = ?
            ")->execute([$dispute_id, $mercado_id]);

            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                VALUES (?, 'partner_accepted', 'partner', ?, 'Parceiro aceitou a disputa', NOW())
            ")->execute([$dispute_id, $mercado_id]);

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Disputa aceita com sucesso']);
            exit;

        } elseif ($action === 'contest_dispute') {
            $reason = trim($_POST['reason'] ?? '');
            if (strlen($reason) < 10) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'Descreva o motivo (minimo 10 caracteres)']);
                exit;
            }

            $reason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');

            $db->prepare("
                UPDATE om_order_disputes
                SET partner_response = ?,
                    status = 'escalated',
                    updated_at = NOW()
                WHERE dispute_id = ? AND partner_id = ?
            ")->execute([$reason, $dispute_id, $mercado_id]);

            $db->prepare("
                INSERT INTO om_dispute_timeline (dispute_id, action, actor_type, actor_id, description, created_at)
                VALUES (?, 'partner_contested', 'partner', ?, ?, NOW())
            ")->execute([$dispute_id, $mercado_id, 'Parceiro contestou: ' . $reason]);

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Contestacao enviada para analise']);
            exit;

        } else {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Acao invalida']);
            exit;
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("[painel/disputas] Erro POST: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erro ao processar']);
        exit;
    }
}

// ── Filtros ──
$filter_status = $_GET['status'] ?? '';
$filter_period = $_GET['period'] ?? '30';
$filter_severity = $_GET['severity'] ?? '';

// ── Dados ──
$stats = ['total' => 0, 'pending' => 0, 'resolved_month' => 0, 'avg_sla' => 0];
$disputes = [];
$has_table = true;

try {
    // Summary stats
    $stmtStats = $db->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status IN ('open','in_review','awaiting_evidence') THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status IN ('resolved','closed','auto_resolved')
                AND resolved_at >= date_trunc('month', CURRENT_DATE) THEN 1 ELSE 0 END) as resolved_month,
            COALESCE(
                AVG(
                    CASE WHEN resolved_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (resolved_at - created_at)) / 3600
                    END
                ), 0
            ) as avg_sla
        FROM om_order_disputes
        WHERE partner_id = ?
    ");
    $stmtStats->execute([$mercado_id]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
    $stats['total'] = (int)$stats['total'];
    $stats['pending'] = (int)$stats['pending'];
    $stats['resolved_month'] = (int)$stats['resolved_month'];
    $stats['avg_sla'] = round((float)$stats['avg_sla'], 1);

    // Build filter query
    $where = ["d.partner_id = ?"];
    $params = [$mercado_id];

    if ($filter_status) {
        if ($filter_status === 'open') {
            $where[] = "d.status IN ('open','awaiting_evidence')";
        } elseif ($filter_status === 'in_review') {
            $where[] = "d.status = 'in_review'";
        } elseif ($filter_status === 'awaiting_evidence') {
            $where[] = "d.status = 'awaiting_evidence'";
        } elseif ($filter_status === 'escalated') {
            $where[] = "d.status = 'escalated'";
        } elseif ($filter_status === 'resolved') {
            $where[] = "d.status IN ('resolved','closed','auto_resolved')";
        }
    }

    if ($filter_period && is_numeric($filter_period)) {
        $days = (int)$filter_period;
        $where[] = "d.created_at >= NOW() - INTERVAL '{$days} days'";
    }

    if ($filter_severity && in_array($filter_severity, ['high', 'medium', 'low', 'critical'])) {
        $where[] = "d.severity = ?";
        $params[] = $filter_severity;
    }

    $whereSQL = implode(' AND ', $where);

    $stmtDisputes = $db->prepare("
        SELECT d.*,
               o.total as order_total,
               o.order_id as order_number,
               o.date_added as order_date,
               c.name as customer_name
        FROM om_order_disputes d
        LEFT JOIN om_market_orders o ON o.order_id = d.order_id
        LEFT JOIN om_customers c ON c.customer_id = d.customer_id
        WHERE {$whereSQL}
        ORDER BY
            CASE WHEN d.status IN ('open','awaiting_evidence','in_review','escalated') THEN 0 ELSE 1 END,
            d.created_at DESC
        LIMIT 200
    ");
    $stmtDisputes->execute($params);
    $disputes = $stmtDisputes->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Table might not exist yet
    if (strpos($e->getMessage(), 'om_order_disputes') !== false ||
        strpos($e->getMessage(), 'does not exist') !== false ||
        strpos($e->getMessage(), 'relation') !== false) {
        $has_table = false;
    } else {
        error_log("[painel/disputas] DB error: " . $e->getMessage());
    }
}

// Category labels
$categoryLabels = [
    'wrong_items' => 'Itens errados',
    'missing_items' => 'Itens faltando',
    'damaged_items' => 'Itens danificados',
    'quality' => 'Qualidade',
    'expired' => 'Produto vencido',
    'late_delivery' => 'Entrega atrasada',
    'not_delivered' => 'Nao entregue',
    'overcharged' => 'Cobranca indevida',
    'other' => 'Outros',
];

$statusLabels = [
    'open' => 'Aberta',
    'awaiting_evidence' => 'Aguardando Evidencia',
    'in_review' => 'Em Analise',
    'escalated' => 'Escalada',
    'resolved' => 'Resolvida',
    'auto_resolved' => 'Resolvida (auto)',
    'closed' => 'Fechada',
];

$statusColors = [
    'open' => 'orange',
    'awaiting_evidence' => 'yellow',
    'in_review' => 'blue',
    'escalated' => 'red',
    'resolved' => 'green',
    'auto_resolved' => 'green',
    'closed' => 'gray',
];

$severityLabels = [
    'high' => 'Alta',
    'critical' => 'Critica',
    'medium' => 'Media',
    'low' => 'Baixa',
];

$severityColors = [
    'high' => 'red',
    'critical' => 'red',
    'medium' => 'yellow',
    'low' => 'green',
];

$filtered_count = count($disputes);

// Pre-fetch timeline and evidence for each dispute (batched)
$disputeTimelines = [];
$disputeEvidence = [];
if (!empty($disputes) && $has_table) {
    $disputeIds = array_column($disputes, 'dispute_id');
    if (!empty($disputeIds)) {
        $placeholders = implode(',', array_fill(0, count($disputeIds), '?'));

        try {
            $stmtTl = $db->prepare("
                SELECT * FROM om_dispute_timeline
                WHERE dispute_id IN ({$placeholders})
                ORDER BY created_at ASC
            ");
            $stmtTl->execute($disputeIds);
            foreach ($stmtTl->fetchAll(PDO::FETCH_ASSOC) as $tl) {
                $disputeTimelines[(int)$tl['dispute_id']][] = $tl;
            }
        } catch (PDOException $e) {
            // timeline table might not exist
        }

        try {
            $stmtEv = $db->prepare("
                SELECT * FROM om_dispute_evidence
                WHERE dispute_id IN ({$placeholders})
                ORDER BY created_at ASC
            ");
            $stmtEv->execute($disputeIds);
            foreach ($stmtEv->fetchAll(PDO::FETCH_ASSOC) as $ev) {
                $disputeEvidence[(int)$ev['dispute_id']][] = $ev;
            }
        } catch (PDOException $e) {
            // evidence table might not exist
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disputas - <?= htmlspecialchars($mercado_nome, ENT_QUOTES, 'UTF-8') ?></title>
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
            <div class="om-sidebar-section">Financeiro</div>
            <a href="faturamento.php" class="om-sidebar-link"><i class="lucide-bar-chart-3"></i><span>Faturamento</span></a>
            <a href="repasses.php" class="om-sidebar-link"><i class="lucide-wallet"></i><span>Repasses</span></a>
            <a href="avaliacoes.php" class="om-sidebar-link"><i class="lucide-star"></i><span>Avaliacoes</span></a>
            <div class="om-sidebar-section">Atendimento</div>
            <a href="disputas.php" class="om-sidebar-link active"><i class="lucide-shield-alert"></i><span>Disputas</span></a>
            <a href="chat.php" class="om-sidebar-link"><i class="lucide-message-circle"></i><span>Chat</span></a>
            <div class="om-sidebar-section">Configuracoes</div>
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
            <h1 class="om-topbar-title">Gestao de Disputas</h1>
            <div class="om-topbar-actions">
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome, ENT_QUOTES, 'UTF-8') ?></span>
                    <div class="om-avatar om-avatar-sm"><?= htmlspecialchars(strtoupper(substr($mercado_nome, 0, 2)), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </header>

        <div class="om-page-content">

            <!-- ===== SUMMARY CARDS ===== -->
            <div class="dp-summary-grid">
                <div class="dp-card dp-card-stat">
                    <div class="dp-stat-icon dp-icon-total">
                        <i class="lucide-shield-alert"></i>
                    </div>
                    <div class="dp-stat-info">
                        <div class="dp-stat-number"><?= $stats['total'] ?></div>
                        <div class="dp-stat-label">Total de Disputas</div>
                    </div>
                </div>

                <div class="dp-card dp-card-stat dp-card-warning">
                    <div class="dp-stat-icon dp-icon-pending">
                        <i class="lucide-clock"></i>
                    </div>
                    <div class="dp-stat-info">
                        <div class="dp-stat-number"><?= $stats['pending'] ?></div>
                        <div class="dp-stat-label">Pendentes</div>
                    </div>
                </div>

                <div class="dp-card dp-card-stat">
                    <div class="dp-stat-icon dp-icon-resolved">
                        <i class="lucide-check-circle"></i>
                    </div>
                    <div class="dp-stat-info">
                        <div class="dp-stat-number"><?= $stats['resolved_month'] ?></div>
                        <div class="dp-stat-label">Resolvidas este mes</div>
                    </div>
                </div>

                <div class="dp-card dp-card-stat">
                    <div class="dp-stat-icon dp-icon-sla">
                        <i class="lucide-timer"></i>
                    </div>
                    <div class="dp-stat-info">
                        <div class="dp-stat-number"><?= $stats['avg_sla'] ?>h</div>
                        <div class="dp-stat-label">SLA Medio</div>
                    </div>
                </div>
            </div>

            <!-- ===== FILTERS ===== -->
            <div class="dp-filters om-card">
                <form method="GET" class="dp-filter-form">
                    <div class="dp-filter-group">
                        <label>Status</label>
                        <select name="status" class="dp-select">
                            <option value="" <?= $filter_status === '' ? 'selected' : '' ?>>Todas</option>
                            <option value="open" <?= $filter_status === 'open' ? 'selected' : '' ?>>Abertas</option>
                            <option value="in_review" <?= $filter_status === 'in_review' ? 'selected' : '' ?>>Em Analise</option>
                            <option value="awaiting_evidence" <?= $filter_status === 'awaiting_evidence' ? 'selected' : '' ?>>Aguardando Evidencia</option>
                            <option value="escalated" <?= $filter_status === 'escalated' ? 'selected' : '' ?>>Escaladas</option>
                            <option value="resolved" <?= $filter_status === 'resolved' ? 'selected' : '' ?>>Resolvidas</option>
                        </select>
                    </div>

                    <div class="dp-filter-group">
                        <label>Periodo</label>
                        <select name="period" class="dp-select">
                            <option value="7" <?= $filter_period === '7' ? 'selected' : '' ?>>Ultimos 7 dias</option>
                            <option value="30" <?= $filter_period === '30' ? 'selected' : '' ?>>Ultimos 30 dias</option>
                            <option value="90" <?= $filter_period === '90' ? 'selected' : '' ?>>Ultimos 90 dias</option>
                        </select>
                    </div>

                    <div class="dp-filter-group">
                        <label>Severidade</label>
                        <select name="severity" class="dp-select">
                            <option value="" <?= $filter_severity === '' ? 'selected' : '' ?>>Todas</option>
                            <option value="high" <?= $filter_severity === 'high' ? 'selected' : '' ?>>Alta</option>
                            <option value="medium" <?= $filter_severity === 'medium' ? 'selected' : '' ?>>Media</option>
                            <option value="low" <?= $filter_severity === 'low' ? 'selected' : '' ?>>Baixa</option>
                        </select>
                    </div>

                    <div class="dp-filter-actions">
                        <button type="submit" class="dp-btn dp-btn-primary"><i class="lucide-search"></i> Filtrar</button>
                        <a href="disputas.php" class="dp-btn dp-btn-ghost"><i class="lucide-x"></i> Limpar</a>
                    </div>
                </form>
            </div>

            <!-- ===== DISPUTES LIST ===== -->
            <div class="dp-list-header">
                <span><?= $filtered_count ?> disputa<?= $filtered_count !== 1 ? 's' : '' ?> encontrada<?= $filtered_count !== 1 ? 's' : '' ?></span>
            </div>

            <?php if (!$has_table): ?>
            <div class="om-card dp-empty">
                <i class="lucide-database"></i>
                <p>Tabela de disputas nao encontrada</p>
                <small>O sistema de disputas ainda nao foi configurado</small>
            </div>

            <?php elseif (empty($disputes)): ?>
            <div class="om-card dp-empty">
                <i class="lucide-check-circle"></i>
                <p>Nenhuma disputa encontrada. Isso e otimo!</p>
                <small>Disputas abertas por clientes aparecerao aqui</small>
            </div>

            <?php else: ?>

            <!-- Table for desktop -->
            <div class="dp-table-wrap om-card">
                <table class="dp-table">
                    <thead>
                        <tr>
                            <th>#ID</th>
                            <th>Pedido</th>
                            <th>Categoria</th>
                            <th>Severidade</th>
                            <th>Status</th>
                            <th>Valor</th>
                            <th>SLA</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($disputes as $d):
                            $did = (int)$d['dispute_id'];
                            $cat = $categoryLabels[$d['category'] ?? ''] ?? htmlspecialchars($d['category'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                            $sev = $d['severity'] ?? 'low';
                            $sevLabel = $severityLabels[$sev] ?? $sev;
                            $sevColor = $severityColors[$sev] ?? 'gray';
                            $st = $d['status'] ?? 'open';
                            $stLabel = $statusLabels[$st] ?? $st;
                            $stColor = $statusColors[$st] ?? 'gray';
                            $amount = (float)($d['requested_amount'] ?? $d['order_total'] ?? 0);

                            // SLA calculation
                            $slaText = '';
                            $slaBreach = false;
                            $slaHours = (int)($d['sla_target_hours'] ?? 48);
                            if (in_array($st, ['open', 'awaiting_evidence', 'in_review', 'escalated'])) {
                                $createdTs = strtotime($d['created_at']);
                                $deadlineTs = $createdTs + ($slaHours * 3600);
                                $remaining = $deadlineTs - time();
                                if ($remaining <= 0 || !empty($d['sla_breached'])) {
                                    $slaText = 'SLA Estourado';
                                    $slaBreach = true;
                                } else {
                                    $hoursLeft = floor($remaining / 3600);
                                    $minsLeft = floor(($remaining % 3600) / 60);
                                    $slaText = $hoursLeft . 'h ' . $minsLeft . 'm';
                                }
                            } elseif ($d['resolved_at']) {
                                $resolveTime = (strtotime($d['resolved_at']) - strtotime($d['created_at'])) / 3600;
                                $slaText = round($resolveTime, 1) . 'h';
                                $slaBreach = !empty($d['sla_breached']);
                            } else {
                                $slaText = '-';
                            }
                        ?>
                        <tr class="dp-row" onclick="toggleDetail(<?= $did ?>)" data-id="<?= $did ?>">
                            <td class="dp-cell-id">#<?= $did ?></td>
                            <td class="dp-cell-order">#<?= (int)$d['order_id'] ?></td>
                            <td><?= $cat ?></td>
                            <td><span class="dp-badge dp-badge-<?= $sevColor ?>"><?= $sevLabel ?></span></td>
                            <td><span class="dp-badge dp-badge-<?= $stColor ?>"><?= htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="dp-cell-amount">R$ <?= number_format($amount, 2, ',', '.') ?></td>
                            <td class="<?= $slaBreach ? 'dp-sla-breach' : '' ?>"><?= htmlspecialchars($slaText, ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <button class="dp-btn dp-btn-sm dp-btn-ghost dp-expand-btn" onclick="event.stopPropagation(); toggleDetail(<?= $did ?>)">
                                    <i class="lucide-chevron-down" id="chevron-<?= $did ?>"></i>
                                </button>
                            </td>
                        </tr>

                        <!-- Expandable detail row -->
                        <tr class="dp-detail-row" id="detail-<?= $did ?>" style="display:none">
                            <td colspan="8">
                                <div class="dp-detail-content">
                                    <div class="dp-detail-grid">
                                        <!-- Order & Dispute Info -->
                                        <div class="dp-detail-section">
                                            <h4><i class="lucide-shopping-bag"></i> Pedido</h4>
                                            <div class="dp-detail-field">
                                                <span class="dp-field-label">Pedido</span>
                                                <span class="dp-field-value">#<?= (int)$d['order_id'] ?></span>
                                            </div>
                                            <div class="dp-detail-field">
                                                <span class="dp-field-label">Cliente</span>
                                                <span class="dp-field-value"><?= htmlspecialchars($d['customer_name'] ?? 'Cliente', ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="dp-detail-field">
                                                <span class="dp-field-label">Total do Pedido</span>
                                                <span class="dp-field-value">R$ <?= number_format((float)($d['order_total'] ?? 0), 2, ',', '.') ?></span>
                                            </div>
                                            <div class="dp-detail-field">
                                                <span class="dp-field-label">Data do Pedido</span>
                                                <span class="dp-field-value"><?= $d['order_date'] ? date('d/m/Y H:i', strtotime($d['order_date'])) : '-' ?></span>
                                            </div>
                                        </div>

                                        <div class="dp-detail-section">
                                            <h4><i class="lucide-shield-alert"></i> Disputa</h4>
                                            <div class="dp-detail-field">
                                                <span class="dp-field-label">Categoria</span>
                                                <span class="dp-field-value"><?= $cat ?></span>
                                            </div>
                                            <?php if (!empty($d['subcategory'])): ?>
                                            <div class="dp-detail-field">
                                                <span class="dp-field-label">Subcategoria</span>
                                                <span class="dp-field-value"><?= htmlspecialchars($d['subcategory'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <div class="dp-detail-field">
                                                <span class="dp-field-label">Severidade</span>
                                                <span class="dp-field-value"><span class="dp-badge dp-badge-<?= $sevColor ?>"><?= $sevLabel ?></span></span>
                                            </div>
                                            <div class="dp-detail-field">
                                                <span class="dp-field-label">Data da Disputa</span>
                                                <span class="dp-field-value"><?= $d['created_at'] ? date('d/m/Y H:i', strtotime($d['created_at'])) : '-' ?></span>
                                            </div>
                                            <?php if ((float)($d['requested_amount'] ?? 0) > 0): ?>
                                            <div class="dp-detail-field">
                                                <span class="dp-field-label">Valor Solicitado</span>
                                                <span class="dp-field-value dp-amount-highlight">R$ <?= number_format((float)$d['requested_amount'], 2, ',', '.') ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Description -->
                                    <?php if (!empty($d['description'])): ?>
                                    <div class="dp-detail-description">
                                        <h4><i class="lucide-message-square"></i> Descricao do Cliente</h4>
                                        <p><?= nl2br(htmlspecialchars($d['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Customer Photos -->
                                    <?php
                                    $photos = [];
                                    if (!empty($d['photo_urls'])) {
                                        $decoded = json_decode($d['photo_urls'], true);
                                        if (is_array($decoded)) $photos = $decoded;
                                    }
                                    // Also include evidence photos
                                    $evidencePhotos = $disputeEvidence[$did] ?? [];
                                    ?>
                                    <?php if (!empty($photos) || !empty($evidencePhotos)): ?>
                                    <div class="dp-detail-photos">
                                        <h4><i class="lucide-camera"></i> Fotos</h4>
                                        <div class="dp-photo-grid">
                                            <?php foreach ($photos as $photo): ?>
                                            <div class="dp-photo-thumb" onclick="openPhoto('<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>')">
                                                <img src="<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>" alt="Foto da disputa" loading="lazy">
                                                <div class="dp-photo-overlay"><i class="lucide-maximize-2"></i></div>
                                            </div>
                                            <?php endforeach; ?>
                                            <?php foreach ($evidencePhotos as $ev): ?>
                                            <div class="dp-photo-thumb" onclick="openPhoto('<?= htmlspecialchars($ev['photo_url'], ENT_QUOTES, 'UTF-8') ?>')">
                                                <img src="<?= htmlspecialchars($ev['photo_url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($ev['caption'] ?? 'Evidencia', ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                                                <div class="dp-photo-overlay"><i class="lucide-maximize-2"></i></div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Timeline -->
                                    <?php $timeline = $disputeTimelines[$did] ?? []; ?>
                                    <?php if (!empty($timeline)): ?>
                                    <div class="dp-detail-timeline">
                                        <h4><i class="lucide-git-branch"></i> Linha do Tempo</h4>
                                        <div class="dp-timeline">
                                            <?php foreach ($timeline as $tl): ?>
                                            <div class="dp-timeline-item">
                                                <div class="dp-timeline-dot"></div>
                                                <div class="dp-timeline-content">
                                                    <span class="dp-timeline-text"><?= htmlspecialchars($tl['description'] ?? $tl['action'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <span class="dp-timeline-time">
                                                        <?= $tl['created_at'] ? date('d/m H:i', strtotime($tl['created_at'])) : '' ?>
                                                        <span class="dp-timeline-actor"><?= htmlspecialchars($tl['actor_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Partner Response Section -->
                                    <div class="dp-detail-response">
                                        <?php if (!empty($d['partner_response'])): ?>
                                        <!-- Already responded -->
                                        <div class="dp-response-box">
                                            <div class="dp-response-header">
                                                <i class="lucide-corner-down-right"></i>
                                                <strong>Sua Resposta</strong>
                                                <?php if (in_array($st, ['resolved', 'auto_resolved', 'closed'])): ?>
                                                <span class="dp-badge dp-badge-green" style="margin-left:8px">Resolvida</span>
                                                <?php elseif ($st === 'escalated'): ?>
                                                <span class="dp-badge dp-badge-red" style="margin-left:8px">Escalada</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="dp-response-text"><?= nl2br(htmlspecialchars($d['partner_response'], ENT_QUOTES, 'UTF-8')) ?></p>
                                            <?php if ((float)($d['approved_amount'] ?? 0) > 0): ?>
                                            <div class="dp-approved-amount">
                                                Valor aprovado: <strong>R$ <?= number_format((float)$d['approved_amount'], 2, ',', '.') ?></strong>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php elseif (in_array($st, ['open', 'awaiting_evidence', 'in_review'])): ?>
                                        <!-- Response buttons -->
                                        <h4><i class="lucide-reply"></i> Responder</h4>
                                        <div class="dp-action-buttons" id="action-btns-<?= $did ?>">
                                            <button class="dp-btn dp-btn-accept" onclick="event.stopPropagation(); acceptDispute(<?= $did ?>)">
                                                <i class="lucide-check"></i> Aceitar
                                            </button>
                                            <button class="dp-btn dp-btn-contest" onclick="event.stopPropagation(); showContestForm(<?= $did ?>)">
                                                <i class="lucide-x"></i> Contestar
                                            </button>
                                        </div>

                                        <!-- Contest form (hidden) -->
                                        <div class="dp-contest-form" id="contest-form-<?= $did ?>" style="display:none">
                                            <textarea id="contest-reason-<?= $did ?>" class="dp-textarea"
                                                      placeholder="Descreva o motivo da contestacao (minimo 10 caracteres)..."
                                                      rows="3"></textarea>
                                            <div class="dp-contest-actions">
                                                <button class="dp-btn dp-btn-sm dp-btn-ghost" onclick="event.stopPropagation(); hideContestForm(<?= $did ?>)">Cancelar</button>
                                                <button class="dp-btn dp-btn-sm dp-btn-contest" onclick="event.stopPropagation(); submitContest(<?= $did ?>)">
                                                    <i class="lucide-send"></i> Enviar Contestacao
                                                </button>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <!-- Resolved without partner response -->
                                        <div class="dp-response-box dp-response-auto">
                                            <i class="lucide-info"></i>
                                            <span>Esta disputa foi <?= $st === 'escalated' ? 'escalada para analise' : 'resolvida automaticamente' ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- Photo Lightbox -->
    <div class="dp-lightbox" id="lightbox" onclick="closeLightbox()">
        <div class="dp-lightbox-close"><i class="lucide-x"></i></div>
        <img id="lightbox-img" src="" alt="Foto ampliada">
    </div>

    <style>
    *{box-sizing:border-box}

    /* ===== Summary Grid ===== */
    .dp-summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
    .dp-card{background:#fff;border-radius:16px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
    .dp-card-stat{display:flex;align-items:center;gap:16px}
    .dp-card-warning{border-left:4px solid #f59e0b;background:linear-gradient(135deg,#fffbeb,#fff)}
    .dp-stat-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0}
    .dp-icon-total{background:#ede9fe;color:#7c3aed}
    .dp-icon-pending{background:#fef3c7;color:#d97706}
    .dp-icon-resolved{background:#d1fae5;color:#059669}
    .dp-icon-sla{background:#dbeafe;color:#2563eb}
    .dp-stat-number{font-size:28px;font-weight:800;color:#1f2937;line-height:1}
    .dp-stat-label{font-size:13px;color:#6b7280;margin-top:2px}

    /* ===== Filters ===== */
    .dp-filters{padding:16px 20px;margin-bottom:20px}
    .dp-filter-form{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}
    .dp-filter-group{display:flex;flex-direction:column;gap:4px}
    .dp-filter-group label{font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.3px}
    .dp-select{padding:8px 12px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:13px;font-family:inherit;background:#fff;color:#1f2937;min-width:160px;transition:.2s}
    .dp-select:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.12)}
    .dp-filter-actions{display:flex;gap:8px;align-items:flex-end}

    /* ===== Buttons ===== */
    .dp-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;border:none;transition:.2s;text-decoration:none}
    .dp-btn-primary{background:#7c3aed;color:#fff}.dp-btn-primary:hover{background:#6d28d9}
    .dp-btn-ghost{background:#f3f4f6;color:#374151}.dp-btn-ghost:hover{background:#e5e7eb}
    .dp-btn-accept{background:#10b981;color:#fff;padding:10px 20px}.dp-btn-accept:hover{background:#059669}
    .dp-btn-contest{background:#ef4444;color:#fff;padding:10px 20px}.dp-btn-contest:hover{background:#dc2626}
    .dp-btn-sm{padding:6px 12px;font-size:12px}

    /* ===== List Header ===== */
    .dp-list-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;font-size:14px;font-weight:500;color:#6b7280}

    /* ===== Empty State ===== */
    .dp-empty{text-align:center;padding:64px 24px;color:#9ca3af}
    .dp-empty i{font-size:48px;display:block;margin-bottom:16px;color:#d1d5db}
    .dp-empty p{font-size:16px;margin:8px 0 4px;color:#6b7280}
    .dp-empty small{font-size:13px}

    /* ===== Table ===== */
    .dp-table-wrap{padding:0;overflow-x:auto}
    .dp-table{width:100%;border-collapse:collapse;font-size:14px}
    .dp-table thead th{padding:14px 16px;text-align:left;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.3px;border-bottom:2px solid #f3f4f6;white-space:nowrap}
    .dp-table tbody td{padding:14px 16px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
    .dp-row{cursor:pointer;transition:background .15s}
    .dp-row:hover{background:#f9fafb}
    .dp-cell-id{font-weight:700;color:#7c3aed}
    .dp-cell-order{font-weight:600;color:#374151}
    .dp-cell-amount{font-weight:600;color:#1f2937}

    /* Badges */
    .dp-badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
    .dp-badge-red{background:#fee2e2;color:#991b1b}
    .dp-badge-orange{background:#ffedd5;color:#9a3412}
    .dp-badge-yellow{background:#fef3c7;color:#92400e}
    .dp-badge-green{background:#d1fae5;color:#065f46}
    .dp-badge-blue{background:#dbeafe;color:#1e40af}
    .dp-badge-gray{background:#f3f4f6;color:#4b5563}

    /* SLA breach */
    .dp-sla-breach{color:#ef4444;font-weight:700}

    /* Expand button */
    .dp-expand-btn{padding:4px 8px;border-radius:8px}
    .dp-expand-btn i{transition:transform .2s}
    .dp-expand-btn.open i{transform:rotate(180deg)}

    /* ===== Detail Row ===== */
    .dp-detail-row td{padding:0 !important;border-bottom:2px solid #e5e7eb}
    .dp-detail-content{padding:20px 24px;background:#fafbfc;border-top:1px solid #e5e7eb}
    .dp-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
    .dp-detail-section{background:#fff;border-radius:12px;padding:16px;border:1px solid #e5e7eb}
    .dp-detail-section h4{font-size:14px;font-weight:700;color:#1f2937;margin:0 0 12px;display:flex;align-items:center;gap:8px}
    .dp-detail-section h4 i{font-size:16px;color:#7c3aed}
    .dp-detail-field{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f3f4f6}
    .dp-detail-field:last-child{border-bottom:none}
    .dp-field-label{font-size:13px;color:#6b7280}
    .dp-field-value{font-size:13px;font-weight:600;color:#1f2937}
    .dp-amount-highlight{color:#ef4444}

    /* Description */
    .dp-detail-description{background:#fff;border-radius:12px;padding:16px;border:1px solid #e5e7eb;margin-bottom:20px}
    .dp-detail-description h4{font-size:14px;font-weight:700;color:#1f2937;margin:0 0 10px;display:flex;align-items:center;gap:8px}
    .dp-detail-description h4 i{font-size:16px;color:#7c3aed}
    .dp-detail-description p{font-size:14px;color:#374151;line-height:1.6;margin:0;white-space:pre-wrap}

    /* Photos */
    .dp-detail-photos{margin-bottom:20px}
    .dp-detail-photos h4{font-size:14px;font-weight:700;color:#1f2937;margin:0 0 12px;display:flex;align-items:center;gap:8px}
    .dp-detail-photos h4 i{font-size:16px;color:#7c3aed}
    .dp-photo-grid{display:flex;gap:10px;flex-wrap:wrap}
    .dp-photo-thumb{width:100px;height:100px;border-radius:12px;overflow:hidden;cursor:pointer;position:relative;border:2px solid #e5e7eb;transition:transform .15s, border-color .15s}
    .dp-photo-thumb:hover{transform:scale(1.05);border-color:#7c3aed}
    .dp-photo-thumb img{width:100%;height:100%;object-fit:cover}
    .dp-photo-overlay{position:absolute;inset:0;background:rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;opacity:0;transition:opacity .15s}
    .dp-photo-thumb:hover .dp-photo-overlay{opacity:1}

    /* Timeline */
    .dp-detail-timeline{margin-bottom:20px}
    .dp-detail-timeline h4{font-size:14px;font-weight:700;color:#1f2937;margin:0 0 12px;display:flex;align-items:center;gap:8px}
    .dp-detail-timeline h4 i{font-size:16px;color:#7c3aed}
    .dp-timeline{position:relative;padding-left:24px}
    .dp-timeline::before{content:'';position:absolute;left:7px;top:4px;bottom:4px;width:2px;background:#e5e7eb}
    .dp-timeline-item{position:relative;margin-bottom:14px;display:flex;align-items:flex-start;gap:12px}
    .dp-timeline-item:last-child{margin-bottom:0}
    .dp-timeline-dot{width:16px;height:16px;border-radius:50%;background:#7c3aed;border:3px solid #ede9fe;flex-shrink:0;margin-left:-24px;position:relative;z-index:1}
    .dp-timeline-content{flex:1;min-width:0}
    .dp-timeline-text{font-size:13px;color:#374151;line-height:1.4}
    .dp-timeline-time{display:flex;align-items:center;gap:8px;font-size:11px;color:#9ca3af;margin-top:2px}
    .dp-timeline-actor{background:#f3f4f6;padding:1px 6px;border-radius:4px;font-size:10px;text-transform:uppercase;font-weight:600}

    /* Response */
    .dp-detail-response{margin-top:4px}
    .dp-detail-response h4{font-size:14px;font-weight:700;color:#1f2937;margin:0 0 12px;display:flex;align-items:center;gap:8px}
    .dp-detail-response h4 i{font-size:16px;color:#7c3aed}
    .dp-response-box{background:#f0fdf4;border-radius:12px;padding:14px 16px;border-left:3px solid #10b981}
    .dp-response-header{display:flex;align-items:center;gap:8px;font-size:13px;color:#065f46;margin-bottom:8px}
    .dp-response-header i{font-size:14px}
    .dp-response-text{font-size:14px;color:#374151;line-height:1.5;white-space:pre-wrap;margin:0}
    .dp-response-auto{display:flex;align-items:center;gap:10px;background:#f3f4f6;border-left-color:#9ca3af;color:#6b7280;font-size:13px}
    .dp-approved-amount{margin-top:10px;font-size:13px;color:#065f46;background:#d1fae5;padding:6px 12px;border-radius:8px;display:inline-block}

    /* Action Buttons */
    .dp-action-buttons{display:flex;gap:12px;margin-bottom:12px}

    /* Contest Form */
    .dp-contest-form{margin-top:12px}
    .dp-textarea{width:100%;padding:12px 14px;border:1.5px solid #e5e7eb;border-radius:12px;font-size:14px;font-family:inherit;color:#1f2937;resize:vertical;min-height:80px;transition:.2s}
    .dp-textarea:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.12)}
    .dp-contest-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}

    /* Lightbox */
    .dp-lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:10000;align-items:center;justify-content:center;cursor:pointer}
    .dp-lightbox.open{display:flex}
    .dp-lightbox img{max-width:90vw;max-height:90vh;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.4)}
    .dp-lightbox-close{position:absolute;top:20px;right:20px;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;cursor:pointer;transition:.2s}
    .dp-lightbox-close:hover{background:rgba(255,255,255,.3)}

    /* Sidebar section labels */
    .om-sidebar-section{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:rgba(255,255,255,.4);padding:16px 20px 4px;margin-top:4px}

    /* ===== Responsive ===== */
    @media(max-width:1100px){
        .dp-summary-grid{grid-template-columns:1fr 1fr}
        .dp-detail-grid{grid-template-columns:1fr}
    }
    @media(max-width:768px){
        .dp-summary-grid{grid-template-columns:1fr}
        .dp-filter-form{flex-direction:column}
        .dp-filter-group{width:100%}
        .dp-select{min-width:0;width:100%}
        .dp-table{font-size:12px}
        .dp-table thead th,.dp-table tbody td{padding:10px 8px}
        .dp-action-buttons{flex-direction:column}
        .dp-photo-thumb{width:80px;height:80px}
    }
    @media(max-width:480px){
        .dp-table thead th:nth-child(6),
        .dp-table tbody td:nth-child(6),
        .dp-table thead th:nth-child(7),
        .dp-table tbody td:nth-child(7){display:none}
    }

    /* Spin animation */
    .spin{animation:spin 1s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
    </style>

    <script>
    function toggleDetail(id) {
        var row = document.getElementById('detail-' + id);
        var chevron = document.getElementById('chevron-' + id);
        var btn = chevron ? chevron.closest('.dp-expand-btn') : null;

        if (row.style.display === 'none') {
            // Close all other open details first
            document.querySelectorAll('.dp-detail-row').forEach(function(r) {
                if (r.id !== 'detail-' + id) {
                    r.style.display = 'none';
                    var otherId = r.id.replace('detail-', '');
                    var otherBtn = document.querySelector('[data-id="' + otherId + '"] .dp-expand-btn');
                    if (otherBtn) otherBtn.classList.remove('open');
                }
            });
            row.style.display = 'table-row';
            if (btn) btn.classList.add('open');
        } else {
            row.style.display = 'none';
            if (btn) btn.classList.remove('open');
        }
    }

    function acceptDispute(id) {
        if (!confirm('Tem certeza que deseja aceitar esta disputa?\n\nIsso resolverá a disputa a favor do cliente.')) {
            return;
        }

        var btns = document.getElementById('action-btns-' + id);
        btns.innerHTML = '<span style="color:#6b7280"><i class="lucide-loader spin"></i> Processando...</span>';

        var formData = new FormData();
        formData.append('action', 'accept_dispute');
        formData.append('dispute_id', id);

        fetch('disputas.php', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Erro ao aceitar disputa');
                btns.innerHTML = buildActionButtons(id);
            }
        })
        .catch(function() {
            alert('Erro de conexao. Tente novamente.');
            btns.innerHTML = buildActionButtons(id);
        });
    }

    function showContestForm(id) {
        document.getElementById('action-btns-' + id).style.display = 'none';
        document.getElementById('contest-form-' + id).style.display = 'block';
        document.getElementById('contest-reason-' + id).focus();
    }

    function hideContestForm(id) {
        document.getElementById('contest-form-' + id).style.display = 'none';
        document.getElementById('action-btns-' + id).style.display = 'flex';
    }

    function submitContest(id) {
        var textarea = document.getElementById('contest-reason-' + id);
        var reason = textarea.value.trim();

        if (reason.length < 10) {
            textarea.style.borderColor = '#ef4444';
            textarea.focus();
            alert('Descreva o motivo da contestacao (minimo 10 caracteres)');
            return;
        }

        var form = document.getElementById('contest-form-' + id);
        var submitBtn = form.querySelector('.dp-btn-contest');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="lucide-loader spin"></i> Enviando...';

        var formData = new FormData();
        formData.append('action', 'contest_dispute');
        formData.append('dispute_id', id);
        formData.append('reason', reason);

        fetch('disputas.php', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Erro ao contestar disputa');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="lucide-send"></i> Enviar Contestacao';
            }
        })
        .catch(function() {
            alert('Erro de conexao. Tente novamente.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="lucide-send"></i> Enviar Contestacao';
        });
    }

    function buildActionButtons(id) {
        return '<button class="dp-btn dp-btn-accept" onclick="event.stopPropagation(); acceptDispute(' + id + ')">' +
               '<i class="lucide-check"></i> Aceitar</button>' +
               '<button class="dp-btn dp-btn-contest" onclick="event.stopPropagation(); showContestForm(' + id + ')">' +
               '<i class="lucide-x"></i> Contestar</button>';
    }

    // Photo lightbox
    function openPhoto(url) {
        event.stopPropagation();
        var lb = document.getElementById('lightbox');
        document.getElementById('lightbox-img').src = url;
        lb.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        var lb = document.getElementById('lightbox');
        lb.classList.remove('open');
        document.body.style.overflow = '';
    }

    // Close lightbox on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeLightbox();
    });
    </script>
</body>
</html>
