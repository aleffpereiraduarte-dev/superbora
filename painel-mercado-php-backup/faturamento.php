<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Faturamento e Relatorios
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Fixes applied:
 * 1. MySQL -> PostgreSQL syntax (DATE_FORMAT -> TO_CHAR, CURDATE -> CURRENT_DATE)
 * 2. Table names: om_market_orders, om_market_order_items, om_market_products, om_market_categories
 * 3. Column names: date_added (not created_at), status 'delivered' (not 'finalizado')
 * 4. Added CSV export, PDF/print, payment method breakdown, hourly/daily analysis
 */

session_start();

if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
$db = getDB();

$mercado_id = $_SESSION['mercado_id'];
$mercado_nome = $_SESSION['mercado_nome'];

// ══════════════════════════════════════════════════════════════════════════════
// CSV EXPORT HANDLER (must run before any HTML output)
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_csv') {
    $csv_inicio = $_POST['data_inicio'] ?? date('Y-m-01');
    $csv_fim = $_POST['data_fim'] ?? date('Y-m-d');

    // Validate dates
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $csv_inicio)) $csv_inicio = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $csv_fim)) $csv_fim = date('Y-m-d');

    $stmt = $db->prepare("
        SELECT
            order_id,
            date_added,
            customer_name,
            forma_pagamento,
            subtotal,
            delivery_fee,
            coupon_discount,
            total,
            status
        FROM om_market_orders
        WHERE partner_id = ?
        AND DATE(date_added) BETWEEN ? AND ?
        ORDER BY date_added DESC
    ");
    $stmt->execute([$mercado_id, $csv_inicio, $csv_fim]);
    $rows = $stmt->fetchAll();

    // Status labels
    $status_labels = [
        'pendente' => 'Pendente',
        'aceito' => 'Aceito',
        'preparando' => 'Preparando',
        'pronto' => 'Pronto',
        'coletando' => 'Coletando',
        'em_entrega' => 'Em Entrega',
        'delivered' => 'Entregue',
        'cancelado' => 'Cancelado',
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="faturamento_' . $csv_inicio . '_' . $csv_fim . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Data', 'Pedido#', 'Cliente', 'Forma Pagamento', 'Subtotal', 'Taxa Entrega', 'Desconto', 'Total', 'Status'], ';');

    foreach ($rows as $row) {
        fputcsv($output, [
            date('d/m/Y H:i', strtotime($row['date_added'])),
            '#' . $row['order_id'],
            $row['customer_name'] ?? '-',
            ucfirst(str_replace('_', ' ', $row['forma_pagamento'] ?? '-')),
            number_format((float)($row['subtotal'] ?? 0), 2, ',', '.'),
            number_format((float)($row['delivery_fee'] ?? 0), 2, ',', '.'),
            number_format((float)($row['coupon_discount'] ?? 0), 2, ',', '.'),
            number_format((float)($row['total'] ?? 0), 2, ',', '.'),
            $status_labels[$row['status']] ?? ucfirst($row['status']),
        ], ';');
    }

    fclose($output);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// PERIODO FILTER
// ══════════════════════════════════════════════════════════════════════════════
$periodo = $_GET['periodo'] ?? 'mes';
$data_inicio = $_GET['data_inicio'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';

switch ($periodo) {
    case 'hoje':
        $data_inicio = date('Y-m-d');
        $data_fim = date('Y-m-d');
        break;
    case 'semana':
        $data_inicio = date('Y-m-d', strtotime('-7 days'));
        $data_fim = date('Y-m-d');
        break;
    case 'mes':
        $data_inicio = date('Y-m-01');
        $data_fim = date('Y-m-d');
        break;
    case 'ano':
        $data_inicio = date('Y-01-01');
        $data_fim = date('Y-m-d');
        break;
    case 'custom':
        if (!$data_inicio) $data_inicio = date('Y-m-01');
        if (!$data_fim) $data_fim = date('Y-m-d');
        break;
}

// ══════════════════════════════════════════════════════════════════════════════
// QUERIES — PostgreSQL compatible, om_market_orders, status='delivered'
// ══════════════════════════════════════════════════════════════════════════════

// Resumo do periodo
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total_pedidos,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as pedidos_finalizados,
        SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) as pedidos_cancelados,
        COALESCE(SUM(CASE WHEN status = 'delivered' THEN total ELSE 0 END), 0) as valor_total,
        COALESCE(SUM(CASE WHEN status = 'delivered' THEN subtotal ELSE 0 END), 0) as valor_produtos,
        COALESCE(AVG(CASE WHEN status = 'delivered' THEN total ELSE NULL END), 0) as ticket_medio
    FROM om_market_orders
    WHERE partner_id = ?
    AND DATE(date_added) BETWEEN ? AND ?
");
$stmt->execute([$mercado_id, $data_inicio, $data_fim]);
$resumo = $stmt->fetch();

// Vendas por dia
$stmt = $db->prepare("
    SELECT
        DATE(date_added) as data,
        COUNT(*) as pedidos,
        SUM(CASE WHEN status = 'delivered' THEN total ELSE 0 END) as valor
    FROM om_market_orders
    WHERE partner_id = ?
    AND DATE(date_added) BETWEEN ? AND ?
    GROUP BY DATE(date_added)
    ORDER BY data ASC
");
$stmt->execute([$mercado_id, $data_inicio, $data_fim]);
$vendas_dia = $stmt->fetchAll();

// Top produtos vendidos
$stmt = $db->prepare("
    SELECT
        oi.name,
        SUM(oi.quantity) as quantidade,
        SUM(oi.quantity * oi.price) as valor_total
    FROM om_market_order_items oi
    JOIN om_market_orders o ON oi.order_id = o.order_id
    WHERE o.partner_id = ?
    AND o.status = 'delivered'
    AND DATE(o.date_added) BETWEEN ? AND ?
    GROUP BY oi.product_id, oi.name
    ORDER BY quantidade DESC
    LIMIT 10
");
$stmt->execute([$mercado_id, $data_inicio, $data_fim]);
$top_produtos = $stmt->fetchAll();

// Vendas por categoria
$stmt = $db->prepare("
    SELECT
        COALESCE(c.name, 'Sem Categoria') as categoria,
        SUM(oi.quantity) as quantidade,
        SUM(oi.quantity * oi.price) as valor_total
    FROM om_market_order_items oi
    JOIN om_market_orders o ON oi.order_id = o.order_id
    LEFT JOIN om_market_products p ON oi.product_id = p.product_id
    LEFT JOIN om_market_categories c ON p.category_id = c.category_id
    WHERE o.partner_id = ?
    AND o.status = 'delivered'
    AND DATE(o.date_added) BETWEEN ? AND ?
    GROUP BY c.category_id, c.name
    ORDER BY valor_total DESC
");
$stmt->execute([$mercado_id, $data_inicio, $data_fim]);
$vendas_categoria = $stmt->fetchAll();

// Comparativo com periodo anterior
$dias_periodo = (strtotime($data_fim) - strtotime($data_inicio)) / 86400 + 1;
$data_inicio_anterior = date('Y-m-d', strtotime($data_inicio . " -$dias_periodo days"));
$data_fim_anterior = date('Y-m-d', strtotime($data_inicio . ' -1 day'));

$stmt = $db->prepare("
    SELECT
        COUNT(*) as total_pedidos,
        COALESCE(SUM(CASE WHEN status = 'delivered' THEN total ELSE 0 END), 0) as valor_total
    FROM om_market_orders
    WHERE partner_id = ?
    AND DATE(date_added) BETWEEN ? AND ?
");
$stmt->execute([$mercado_id, $data_inicio_anterior, $data_fim_anterior]);
$periodo_anterior = $stmt->fetch();

// Calcular variacao percentual
$var_pedidos = $periodo_anterior['total_pedidos'] > 0
    ? (($resumo['total_pedidos'] - $periodo_anterior['total_pedidos']) / $periodo_anterior['total_pedidos']) * 100
    : 0;
$var_valor = $periodo_anterior['valor_total'] > 0
    ? (($resumo['valor_total'] - $periodo_anterior['valor_total']) / $periodo_anterior['valor_total']) * 100
    : 0;

// ══════════════════════════════════════════════════════════════════════════════
// PAYMENT METHOD BREAKDOWN
// ══════════════════════════════════════════════════════════════════════════════
$stmt = $db->prepare("
    SELECT
        COALESCE(forma_pagamento, 'nao_informado') as forma_pagamento,
        COUNT(*) as qty,
        COALESCE(SUM(total), 0) as revenue
    FROM om_market_orders
    WHERE partner_id = ?
    AND status = 'delivered'
    AND DATE(date_added) BETWEEN ? AND ?
    GROUP BY forma_pagamento
    ORDER BY revenue DESC
");
$stmt->execute([$mercado_id, $data_inicio, $data_fim]);
$payment_breakdown = $stmt->fetchAll();

$total_payment_revenue = 0;
$total_payment_qty = 0;
foreach ($payment_breakdown as $pm) {
    $total_payment_revenue += (float)$pm['revenue'];
    $total_payment_qty += (int)$pm['qty'];
}

// Payment method display config
$payment_config = [
    'pix' => ['label' => 'PIX', 'color' => '#36B37E', 'bg' => '#E3FCEF', 'text' => '#006644'],
    'credito' => ['label' => 'Cartao Credito', 'color' => '#0065FF', 'bg' => '#DEEBFF', 'text' => '#0747A6'],
    'cartao_credito' => ['label' => 'Cartao Credito', 'color' => '#0065FF', 'bg' => '#DEEBFF', 'text' => '#0747A6'],
    'credit_card' => ['label' => 'Cartao Credito', 'color' => '#0065FF', 'bg' => '#DEEBFF', 'text' => '#0747A6'],
    'debito' => ['label' => 'Cartao Debito', 'color' => '#6554C0', 'bg' => '#EAE6FF', 'text' => '#403294'],
    'cartao_debito' => ['label' => 'Cartao Debito', 'color' => '#6554C0', 'bg' => '#EAE6FF', 'text' => '#403294'],
    'debit_card' => ['label' => 'Cartao Debito', 'color' => '#6554C0', 'bg' => '#EAE6FF', 'text' => '#403294'],
    'dinheiro' => ['label' => 'Dinheiro', 'color' => '#FFAB00', 'bg' => '#FFF7E6', 'text' => '#7A5C00'],
    'cash' => ['label' => 'Dinheiro', 'color' => '#FFAB00', 'bg' => '#FFF7E6', 'text' => '#7A5C00'],
];

// ══════════════════════════════════════════════════════════════════════════════
// HOURLY ANALYSIS
// ══════════════════════════════════════════════════════════════════════════════
$stmt = $db->prepare("
    SELECT
        EXTRACT(HOUR FROM date_added)::int as hora,
        COUNT(*) as qty,
        COALESCE(SUM(total), 0) as revenue
    FROM om_market_orders
    WHERE partner_id = ?
    AND status = 'delivered'
    AND DATE(date_added) BETWEEN ? AND ?
    GROUP BY EXTRACT(HOUR FROM date_added)
    ORDER BY hora
");
$stmt->execute([$mercado_id, $data_inicio, $data_fim]);
$hourly_raw = $stmt->fetchAll();

// Build full 24h array (6h-23h typical for market)
$hourly_data = [];
for ($h = 0; $h < 24; $h++) {
    $hourly_data[$h] = ['hora' => $h, 'qty' => 0, 'revenue' => 0];
}
foreach ($hourly_raw as $row) {
    $h = (int)$row['hora'];
    $hourly_data[$h] = ['hora' => $h, 'qty' => (int)$row['qty'], 'revenue' => (float)$row['revenue']];
}
// Filter to relevant hours (6-23) for display
$hourly_display = array_values(array_filter($hourly_data, fn($d) => $d['hora'] >= 6 && $d['hora'] <= 23));

// ══════════════════════════════════════════════════════════════════════════════
// DAY-OF-WEEK ANALYSIS
// ══════════════════════════════════════════════════════════════════════════════
$stmt = $db->prepare("
    SELECT
        EXTRACT(DOW FROM date_added)::int as dia,
        COUNT(*) as qty,
        COALESCE(SUM(total), 0) as revenue
    FROM om_market_orders
    WHERE partner_id = ?
    AND status = 'delivered'
    AND DATE(date_added) BETWEEN ? AND ?
    GROUP BY EXTRACT(DOW FROM date_added)
    ORDER BY dia
");
$stmt->execute([$mercado_id, $data_inicio, $data_fim]);
$dow_raw = $stmt->fetchAll();

// DOW: 0=Sunday, 1=Monday ... 6=Saturday (PostgreSQL)
$dow_labels = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];
$dow_short = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'];
$dow_data = [];
for ($d = 0; $d < 7; $d++) {
    $dow_data[$d] = ['dia' => $d, 'label' => $dow_labels[$d], 'short' => $dow_short[$d], 'qty' => 0, 'revenue' => 0];
}
foreach ($dow_raw as $row) {
    $d = (int)$row['dia'];
    $dow_data[$d]['qty'] = (int)$row['qty'];
    $dow_data[$d]['revenue'] = (float)$row['revenue'];
}

// Find best day
$best_day_idx = 0;
$best_day_qty = 0;
foreach ($dow_data as $d) {
    if ($d['qty'] > $best_day_qty) {
        $best_day_qty = $d['qty'];
        $best_day_idx = $d['dia'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faturamento - Painel do Mercado</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="om-app-layout">
    <!-- Sidebar -->
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <img src="/assets/img/logo-onemundo-white.png" alt="OneMundo" class="om-sidebar-logo"
                 onerror="this.outerHTML='<span class=\'om-sidebar-logo-text\'>OneMundo</span>'">
        </div>

        <nav class="om-sidebar-nav">
            <a href="index.php" class="om-sidebar-link">
                <i class="lucide-layout-dashboard"></i>
                <span>Dashboard</span>
            </a>
            <a href="pedidos.php" class="om-sidebar-link">
                <i class="lucide-shopping-bag"></i>
                <span>Pedidos</span>
            </a>
            <a href="produtos.php" class="om-sidebar-link">
                <i class="lucide-package"></i>
                <span>Produtos</span>
            </a>
                <a href="cardapio-ia.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path><path d="M12 18v4"></path><path d="M8 22h8"></path></svg>
                    <span class="om-sidebar-link-text">Cardapio IA</span>
                    <span style="background:#8b5cf6;color:#fff;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:700;">NOVO</span>
                </a>
            <a href="categorias.php" class="om-sidebar-link">
                <i class="lucide-tags"></i>
                <span>Categorias</span>
            </a>
            <a href="faturamento.php" class="om-sidebar-link active">
                <i class="lucide-bar-chart-3"></i>
                <span>Faturamento</span>
            </a>
            <a href="repasses.php" class="om-sidebar-link">
                <i class="lucide-wallet"></i>
                <span>Repasses</span>
            </a>
            <a href="avaliacoes.php" class="om-sidebar-link">
                <i class="lucide-star"></i>
                <span>Avaliacoes</span>
            </a>
            <a href="horarios.php" class="om-sidebar-link">
                <i class="lucide-clock"></i>
                <span>Horarios</span>
            </a>
            <a href="perfil.php" class="om-sidebar-link">
                <i class="lucide-settings"></i>
                <span>Configuracoes</span>
            </a>
        </nav>

        <div class="om-sidebar-footer">
            <a href="logout.php" class="om-sidebar-link">
                <i class="lucide-log-out"></i>
                <span>Sair</span>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="om-main-content">
        <!-- Topbar -->
        <header class="om-topbar">
            <button class="om-sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                <i class="lucide-menu"></i>
            </button>

            <h1 class="om-topbar-title">Faturamento</h1>

            <div class="om-topbar-actions">
                <div class="om-export-buttons">
                    <button onclick="exportCSV()" class="btn-export" title="Exportar CSV">
                        <i class="lucide-download"></i> Exportar CSV
                    </button>
                    <button onclick="window.print()" class="btn-export btn-export-print" title="Imprimir relatorio">
                        <i class="lucide-printer"></i> Imprimir
                    </button>
                </div>
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($mercado_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="om-page-content">
            <!-- Filtro de Periodo -->
            <div class="om-card om-mb-6">
                <div class="om-card-body">
                    <form method="GET" class="om-flex om-flex-wrap om-items-end om-gap-4">
                        <div class="om-btn-group-toggle">
                            <button type="submit" name="periodo" value="hoje" class="om-btn <?= $periodo === 'hoje' ? 'om-btn-primary' : 'om-btn-outline' ?>">Hoje</button>
                            <button type="submit" name="periodo" value="semana" class="om-btn <?= $periodo === 'semana' ? 'om-btn-primary' : 'om-btn-outline' ?>">7 dias</button>
                            <button type="submit" name="periodo" value="mes" class="om-btn <?= $periodo === 'mes' ? 'om-btn-primary' : 'om-btn-outline' ?>">Este mes</button>
                            <button type="submit" name="periodo" value="ano" class="om-btn <?= $periodo === 'ano' ? 'om-btn-primary' : 'om-btn-outline' ?>">Este ano</button>
                        </div>

                        <div class="om-flex om-gap-2 om-items-end">
                            <div class="om-form-group om-mb-0">
                                <label class="om-label om-text-xs">De</label>
                                <input type="date" name="data_inicio" class="om-input om-input-sm" value="<?= $data_inicio ?>">
                            </div>
                            <div class="om-form-group om-mb-0">
                                <label class="om-label om-text-xs">Ate</label>
                                <input type="date" name="data_fim" class="om-input om-input-sm" value="<?= $data_fim ?>">
                            </div>
                            <input type="hidden" name="periodo" value="custom">
                            <button type="submit" class="om-btn om-btn-sm om-btn-outline">
                                <i class="lucide-filter"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Cards de Resumo -->
            <div class="om-stats-grid om-mb-6">
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-success-light">
                        <i class="lucide-dollar-sign"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value">R$ <?= number_format($resumo['valor_total'], 2, ',', '.') ?></span>
                        <span class="om-stat-label">Faturamento Total</span>
                        <?php if ($var_valor != 0): ?>
                        <span class="om-stat-change <?= $var_valor >= 0 ? 'positive' : 'negative' ?>">
                            <i class="lucide-trending-<?= $var_valor >= 0 ? 'up' : 'down' ?>"></i>
                            <?= abs(round($var_valor, 1)) ?>%
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-primary-light">
                        <i class="lucide-shopping-bag"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $resumo['pedidos_finalizados'] ?></span>
                        <span class="om-stat-label">Pedidos Finalizados</span>
                        <?php if ($var_pedidos != 0): ?>
                        <span class="om-stat-change <?= $var_pedidos >= 0 ? 'positive' : 'negative' ?>">
                            <i class="lucide-trending-<?= $var_pedidos >= 0 ? 'up' : 'down' ?>"></i>
                            <?= abs(round($var_pedidos, 1)) ?>%
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-info-light">
                        <i class="lucide-receipt"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value">R$ <?= number_format($resumo['ticket_medio'], 2, ',', '.') ?></span>
                        <span class="om-stat-label">Ticket Medio</span>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-error-light">
                        <i class="lucide-x-circle"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $resumo['pedidos_cancelados'] ?></span>
                        <span class="om-stat-label">Cancelados</span>
                    </div>
                </div>
            </div>

            <!-- Graficos: Vendas + Categorias -->
            <div class="om-grid om-grid-cols-1 lg:om-grid-cols-2 om-gap-6 om-mb-6">
                <!-- Grafico de Vendas -->
                <div class="om-card">
                    <div class="om-card-header">
                        <h3 class="om-card-title">Vendas por Dia</h3>
                    </div>
                    <div class="om-card-body">
                        <canvas id="chartVendas" height="250"></canvas>
                    </div>
                </div>

                <!-- Grafico por Categoria -->
                <div class="om-card">
                    <div class="om-card-header">
                        <h3 class="om-card-title">Vendas por Categoria</h3>
                    </div>
                    <div class="om-card-body">
                        <canvas id="chartCategorias" height="250"></canvas>
                    </div>
                </div>
            </div>

            <!-- FEATURE 2: Payment Method Breakdown -->
            <div class="om-card om-mb-6">
                <div class="om-card-header">
                    <h3 class="om-card-title">
                        <i class="lucide-credit-card" style="margin-right:6px;vertical-align:middle;"></i>
                        Faturamento por Forma de Pagamento
                    </h3>
                </div>
                <div class="om-card-body">
                    <?php if (empty($payment_breakdown)): ?>
                    <p class="om-text-center om-py-8 om-text-muted">Nenhuma venda no periodo selecionado</p>
                    <?php else: ?>
                    <div class="payment-breakdown-grid">
                        <?php foreach ($payment_breakdown as $pm):
                            $key = strtolower(trim($pm['forma_pagamento'] ?? 'nao_informado'));
                            $config = $payment_config[$key] ?? ['label' => ucfirst(str_replace('_', ' ', $key)), 'color' => '#97A0AF', 'bg' => '#F4F5F7', 'text' => '#505F79'];
                            $pct = $total_payment_revenue > 0 ? ((float)$pm['revenue'] / $total_payment_revenue) * 100 : 0;
                            $qty_pct = $total_payment_qty > 0 ? ((int)$pm['qty'] / $total_payment_qty) * 100 : 0;
                        ?>
                        <div class="payment-method-row">
                            <div class="payment-method-info">
                                <span class="payment-badge" style="background:<?= $config['bg'] ?>;color:<?= $config['text'] ?>;">
                                    <?= htmlspecialchars($config['label']) ?>
                                </span>
                                <span class="payment-count"><?= (int)$pm['qty'] ?> pedidos</span>
                            </div>
                            <div class="payment-method-bar-wrap">
                                <div class="payment-method-bar" style="width:<?= round($pct, 1) ?>%;background:<?= $config['color'] ?>;"></div>
                            </div>
                            <div class="payment-method-values">
                                <span class="payment-revenue">R$ <?= number_format((float)$pm['revenue'], 2, ',', '.') ?></span>
                                <span class="payment-pct"><?= round($pct, 1) ?>%</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- FEATURE 3: Hourly + Day-of-Week Analysis -->
            <div class="om-grid om-grid-cols-1 lg:om-grid-cols-2 om-gap-6 om-mb-6">
                <!-- Hourly Analysis -->
                <div class="om-card">
                    <div class="om-card-header">
                        <h3 class="om-card-title">
                            <i class="lucide-clock" style="margin-right:6px;vertical-align:middle;"></i>
                            Vendas por Horario
                        </h3>
                    </div>
                    <div class="om-card-body">
                        <canvas id="chartHorario" height="280"></canvas>
                    </div>
                </div>

                <!-- Day of Week Analysis -->
                <div class="om-card">
                    <div class="om-card-header">
                        <h3 class="om-card-title">
                            <i class="lucide-calendar-days" style="margin-right:6px;vertical-align:middle;"></i>
                            Vendas por Dia da Semana
                        </h3>
                        <?php if ($best_day_qty > 0): ?>
                        <span class="dow-best-badge">
                            <i class="lucide-trophy" style="font-size:12px;"></i>
                            Melhor dia: <?= $dow_data[$best_day_idx]['label'] ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="om-card-body">
                        <canvas id="chartDiaSemana" height="280"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Produtos -->
            <div class="om-card">
                <div class="om-card-header">
                    <h3 class="om-card-title">Top 10 Produtos Mais Vendidos</h3>
                </div>

                <div class="om-table-responsive">
                    <table class="om-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Produto</th>
                                <th class="om-text-center">Quantidade</th>
                                <th class="om-text-right">Valor Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_produtos)): ?>
                            <tr>
                                <td colspan="4" class="om-text-center om-py-8 om-text-muted">
                                    Nenhuma venda no periodo selecionado
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($top_produtos as $i => $prod): ?>
                            <tr>
                                <td>
                                    <span class="om-badge om-badge-<?= $i < 3 ? 'primary' : 'neutral' ?>"><?= $i + 1 ?></span>
                                </td>
                                <td class="om-font-medium"><?= htmlspecialchars($prod['name']) ?></td>
                                <td class="om-text-center"><?= $prod['quantidade'] ?></td>
                                <td class="om-text-right om-font-semibold">R$ <?= number_format($prod['valor_total'], 2, ',', '.') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <style>
    /* ── Existing styles ── */
    .om-btn-group-toggle {
        display: flex;
        gap: 0;
    }
    .om-btn-group-toggle .om-btn {
        border-radius: 0;
    }
    .om-btn-group-toggle .om-btn:first-child {
        border-radius: var(--om-radius-md) 0 0 var(--om-radius-md);
    }
    .om-btn-group-toggle .om-btn:last-child {
        border-radius: 0 var(--om-radius-md) var(--om-radius-md) 0;
    }
    .om-stat-change {
        font-size: var(--om-font-xs);
        display: flex;
        align-items: center;
        gap: 2px;
        margin-top: 4px;
    }
    .om-stat-change.positive {
        color: var(--om-success);
    }
    .om-stat-change.negative {
        color: var(--om-error);
    }
    .om-stat-change i {
        font-size: 14px;
    }

    /* ── Export buttons ── */
    .om-export-buttons {
        display: flex;
        gap: 8px;
        margin-right: 16px;
    }
    .btn-export {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        border: 1px solid var(--om-border, #DFE1E6);
        border-radius: var(--om-radius-md, 8px);
        background: #fff;
        color: var(--om-text, #172B4D);
        font-size: 13px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.15s ease;
        font-family: inherit;
    }
    .btn-export:hover {
        background: var(--om-bg-hover, #F4F5F7);
        border-color: var(--om-primary, #FF6A00);
        color: var(--om-primary, #FF6A00);
    }
    .btn-export i {
        font-size: 15px;
    }

    /* ── Payment breakdown ── */
    .payment-breakdown-grid {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .payment-method-row {
        display: grid;
        grid-template-columns: 180px 1fr 160px;
        align-items: center;
        gap: 12px;
    }
    .payment-method-info {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .payment-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
    }
    .payment-count {
        font-size: 11px;
        color: var(--om-text-muted, #97A0AF);
        padding-left: 4px;
    }
    .payment-method-bar-wrap {
        height: 24px;
        background: var(--om-bg-subtle, #F4F5F7);
        border-radius: 12px;
        overflow: hidden;
    }
    .payment-method-bar {
        height: 100%;
        border-radius: 12px;
        min-width: 4px;
        transition: width 0.6s ease;
    }
    .payment-method-values {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 0;
    }
    .payment-revenue {
        font-weight: 600;
        font-size: 14px;
        color: var(--om-text, #172B4D);
    }
    .payment-pct {
        font-size: 12px;
        color: var(--om-text-muted, #97A0AF);
    }

    /* ── Day-of-week best badge ── */
    .dow-best-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        background: #FFF7E6;
        color: #7A5C00;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    /* ── Print styles ── */
    @media print {
        .om-sidebar,
        .om-topbar,
        .om-sidebar-toggle,
        .btn-export,
        .om-export-buttons,
        .om-btn-group-toggle,
        form,
        .om-user-menu {
            display: none !important;
        }
        .om-main-content {
            margin-left: 0 !important;
            padding: 0 !important;
        }
        .om-page-content {
            padding: 10px !important;
        }
        .om-card {
            break-inside: avoid;
            box-shadow: none !important;
            border: 1px solid #ddd !important;
        }
        body {
            background: #fff !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .om-stats-grid {
            grid-template-columns: repeat(4, 1fr) !important;
        }
        canvas {
            max-height: 250px !important;
        }
        /* Print header */
        .om-page-content::before {
            content: 'Relatorio de Faturamento - <?= htmlspecialchars($mercado_nome) ?> | Periodo: <?= date("d/m/Y", strtotime($data_inicio)) ?> a <?= date("d/m/Y", strtotime($data_fim)) ?>';
            display: block;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #333;
        }
    }

    /* ── Responsive tweaks ── */
    @media (max-width: 768px) {
        .payment-method-row {
            grid-template-columns: 1fr;
            gap: 6px;
        }
        .payment-method-values {
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
        }
        .om-export-buttons {
            display: none;
        }
    }
    </style>

    <script>
    // ══════════════════════════════════════════════════════════════════════════
    // DATA from PHP
    // ══════════════════════════════════════════════════════════════════════════
    const vendasData = <?= json_encode($vendas_dia) ?>;
    const categoriasData = <?= json_encode($vendas_categoria) ?>;
    const hourlyData = <?= json_encode(array_values($hourly_display)) ?>;
    const dowData = <?= json_encode(array_values($dow_data)) ?>;
    const bestDayIdx = <?= (int)$best_day_idx ?>;

    // ══════════════════════════════════════════════════════════════════════════
    // CHART 1: Vendas por Dia (existing)
    // ══════════════════════════════════════════════════════════════════════════
    new Chart(document.getElementById('chartVendas'), {
        type: 'line',
        data: {
            labels: vendasData.map(d => {
                const date = new Date(d.data + 'T00:00:00');
                return date.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
            }),
            datasets: [{
                label: 'Faturamento',
                data: vendasData.map(d => parseFloat(d.valor)),
                borderColor: '#FF6A00',
                backgroundColor: 'rgba(255, 106, 0, 0.1)',
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: value => 'R$ ' + value.toFixed(0)
                    }
                }
            }
        }
    });

    // ══════════════════════════════════════════════════════════════════════════
    // CHART 2: Categorias (existing)
    // ══════════════════════════════════════════════════════════════════════════
    const chartColors = ['#FF6A00', '#00B8D9', '#36B37E', '#FFAB00', '#6554C0', '#FF5630', '#00875A', '#172B4D', '#97A0AF', '#DFE1E6'];
    new Chart(document.getElementById('chartCategorias'), {
        type: 'doughnut',
        data: {
            labels: categoriasData.map(c => c.categoria),
            datasets: [{
                data: categoriasData.map(c => parseFloat(c.valor_total)),
                backgroundColor: chartColors.slice(0, categoriasData.length)
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right'
                }
            }
        }
    });

    // ══════════════════════════════════════════════════════════════════════════
    // CHART 3: Vendas por Horario (NEW)
    // ══════════════════════════════════════════════════════════════════════════
    (function() {
        const ctx = document.getElementById('chartHorario').getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, 280);
        gradient.addColorStop(0, 'rgba(255, 106, 0, 0.85)');
        gradient.addColorStop(1, 'rgba(255, 171, 0, 0.4)');

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: hourlyData.map(d => d.hora + 'h'),
                datasets: [{
                    label: 'Pedidos',
                    data: hourlyData.map(d => d.qty),
                    backgroundColor: gradient,
                    borderColor: '#FF6A00',
                    borderWidth: 1,
                    borderRadius: 4,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            afterLabel: function(context) {
                                const d = hourlyData[context.dataIndex];
                                return 'Faturamento: R$ ' + parseFloat(d.revenue).toFixed(2).replace('.', ',');
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            callback: value => Number.isInteger(value) ? value : ''
                        },
                        title: {
                            display: true,
                            text: 'Quantidade de pedidos',
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    })();

    // ══════════════════════════════════════════════════════════════════════════
    // CHART 4: Vendas por Dia da Semana (NEW)
    // ══════════════════════════════════════════════════════════════════════════
    (function() {
        const ctx = document.getElementById('chartDiaSemana').getContext('2d');

        const barColors = dowData.map((d, i) => i === bestDayIdx ? '#FF6A00' : '#00B8D9');
        const borderColors = dowData.map((d, i) => i === bestDayIdx ? '#E55A00' : '#008DA6');

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: dowData.map(d => d.short),
                datasets: [{
                    label: 'Pedidos',
                    data: dowData.map(d => d.qty),
                    backgroundColor: barColors,
                    borderColor: borderColors,
                    borderWidth: 1,
                    borderRadius: 4,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(context) {
                                return dowData[context[0].dataIndex].label;
                            },
                            afterLabel: function(context) {
                                const d = dowData[context.dataIndex];
                                return 'Faturamento: R$ ' + parseFloat(d.revenue).toFixed(2).replace('.', ',');
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            callback: value => Number.isInteger(value) ? value : ''
                        },
                        title: {
                            display: true,
                            text: 'Quantidade de pedidos',
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    })();

    // ══════════════════════════════════════════════════════════════════════════
    // CSV EXPORT FUNCTION
    // ══════════════════════════════════════════════════════════════════════════
    function exportCSV() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'faturamento.php';
        form.style.display = 'none';

        const actionField = document.createElement('input');
        actionField.type = 'hidden';
        actionField.name = 'action';
        actionField.value = 'export_csv';
        form.appendChild(actionField);

        const inicioField = document.createElement('input');
        inicioField.type = 'hidden';
        inicioField.name = 'data_inicio';
        inicioField.value = '<?= htmlspecialchars($data_inicio) ?>';
        form.appendChild(inicioField);

        const fimField = document.createElement('input');
        fimField.type = 'hidden';
        fimField.name = 'data_fim';
        fimField.value = '<?= htmlspecialchars($data_fim) ?>';
        form.appendChild(fimField);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
    </script>
</body>
</html>
