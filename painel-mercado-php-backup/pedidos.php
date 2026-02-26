<?php
/**
 * PAINEL DO MERCADO - Gestao de Pedidos
 *
 * Fixes applied:
 * 1. MySQL -> PostgreSQL syntax (CURDATE -> CURRENT_DATE)
 * 2. Table names: om_market_orders, om_market_order_items, om_market_partners
 * 3. Column names: date_added (not created_at), forma_pagamento, etc.
 * 4. Order acceptance timer with countdown bars
 * 5. Pusher real-time + polling fallback
 * 6. Print receipt button
 */

session_start();

if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
$db = getDB();

$mercado_id = $_SESSION['mercado_id'];
$mercado_nome = $_SESSION['mercado_nome'] ?? 'Mercado';

// Fetch partner data for store location (used by tracking map)
$stmt_partner = $db->prepare("SELECT latitude, longitude, lat, lng FROM om_market_partners WHERE partner_id = ?");
$stmt_partner->execute([$mercado_id]);
$mercado = $stmt_partner->fetch() ?: [];

// Filtros
$status_filter = $_GET['status'] ?? '';
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-d', strtotime('-7 days'));
$data_fim = $_GET['data_fim'] ?? date('Y-m-d');
$busca = $_GET['busca'] ?? '';
$pagina = max(1, intval($_GET['pagina'] ?? 1));
$por_pagina = 20;
$offset = ($pagina - 1) * $por_pagina;

// Query base — om_market_orders with date_added column
$where = "WHERE o.partner_id = ?";
$params = [$mercado_id];

if ($status_filter) {
    $where .= " AND o.status = ?";
    $params[] = $status_filter;
}

if ($data_inicio) {
    $where .= " AND DATE(o.date_added) >= ?";
    $params[] = $data_inicio;
}

if ($data_fim) {
    $where .= " AND DATE(o.date_added) <= ?";
    $params[] = $data_fim;
}

if ($busca) {
    $where .= " AND (CAST(o.order_id AS TEXT) LIKE ? OR o.customer_name ILIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

// Total para paginacao
$stmt = $db->prepare("SELECT COUNT(*) FROM om_market_orders o $where");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$total_paginas = ceil($total / $por_pagina);

// Buscar pedidos — om_market_orders + om_market_order_items
$sql = "SELECT o.*,
        (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as total_itens
        FROM om_market_orders o
        $where
        ORDER BY o.date_added DESC
        LIMIT $por_pagina OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$pedidos = $stmt->fetchAll();

// Estatisticas do dia — PostgreSQL: CURRENT_DATE instead of CURDATE()
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as pendentes,
        SUM(CASE WHEN status = 'aceito' THEN 1 ELSE 0 END) as aceitos,
        SUM(CASE WHEN status = 'preparando' THEN 1 ELSE 0 END) as preparando,
        SUM(CASE WHEN status = 'pronto' THEN 1 ELSE 0 END) as prontos,
        SUM(CASE WHEN status IN ('coletando', 'em_entrega') THEN 1 ELSE 0 END) as em_entrega,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as entregues,
        SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) as cancelados
    FROM om_market_orders
    WHERE partner_id = ? AND DATE(date_added) = CURRENT_DATE
");
$stmt->execute([$mercado_id]);
$stats = $stmt->fetch();

// Pedidos pendentes com timestamp para timer (para JS)
$stmt = $db->prepare("
    SELECT order_id, date_added, timer_expires
    FROM om_market_orders
    WHERE partner_id = ? AND status = 'pendente'
    ORDER BY date_added ASC
");
$stmt->execute([$mercado_id]);
$pendingOrders = $stmt->fetchAll();

// Mapa de status — matching actual system statuses
$status_map = [
    'pendente' => ['label' => 'Pendente', 'class' => 'warning'],
    'confirmado' => ['label' => 'Confirmado', 'class' => 'info'],
    'aceito' => ['label' => 'Aceito', 'class' => 'info'],
    'preparando' => ['label' => 'Preparando', 'class' => 'info'],
    'pronto' => ['label' => 'Pronto', 'class' => 'success'],
    'coletando' => ['label' => 'Coletando', 'class' => 'primary'],
    'em_entrega' => ['label' => 'Em Entrega', 'class' => 'primary'],
    'delivered' => ['label' => 'Entregue', 'class' => 'success'],
    'entregue' => ['label' => 'Entregue', 'class' => 'success'],
    'retirado' => ['label' => 'Retirado', 'class' => 'success'],
    'cancelado' => ['label' => 'Cancelado', 'class' => 'error']
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos - Painel do Mercado</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://js.pusher.com/8.0/pusher.min.js"></script>
    <style>
        /* Order acceptance timer */
        .order-timer { height: 4px; background: #e5e7eb; border-radius: 2px; overflow: hidden; margin-top: 6px; }
        .order-timer-bar { height: 100%; background: #10b981; transition: width 1s linear; }
        .order-timer-bar.warning { background: #f59e0b; }
        .order-timer-bar.critical { background: #ef4444; }

        .order-timer-label {
            font-size: 11px;
            font-weight: 600;
            margin-top: 2px;
            display: block;
        }
        .order-timer-label.expired { color: #ef4444; }
        .order-timer-label.warning { color: #f59e0b; }
        .order-timer-label.ok { color: #6b7280; }

        /* Pending order row highlight */
        tr.pending-row { background: #fefce8; }
        tr.pending-row:hover { background: #fef9c3; }
        tr.pending-expired { background: #fef2f2; }
        tr.pending-expired:hover { background: #fee2e2; }

        /* Prominent action buttons for pending orders */
        .pending-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .pending-actions .om-btn {
            font-weight: 600;
            min-width: 90px;
            justify-content: center;
        }
        .btn-aceitar {
            background: #10b981 !important;
            border-color: #10b981 !important;
            color: #fff !important;
            font-size: 13px !important;
            padding: 6px 14px !important;
        }
        .btn-aceitar:hover { background: #059669 !important; }
        .btn-recusar {
            background: #ef4444 !important;
            border-color: #ef4444 !important;
            color: #fff !important;
            font-size: 13px !important;
            padding: 6px 14px !important;
        }
        .btn-recusar:hover { background: #dc2626 !important; }

        /* Pusher connection status */
        .om-pusher-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--color-text-muted, #888);
            margin-left: 12px;
        }
        .om-pusher-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #ef4444;
            transition: background 0.3s;
        }
        .om-pusher-dot.connected { background: #22c55e; }
        .om-pusher-dot.connecting { background: #f59e0b; animation: pulse-dot 1s infinite; }
        @keyframes pulse-dot { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }

        /* Toast notification */
        .om-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-left: 4px solid #10b981;
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            max-width: 400px;
            transform: translateX(120%);
            transition: transform 0.3s ease;
        }
        .om-toast.show { transform: translateX(0); }
        .om-toast-title { font-weight: 600; font-size: 14px; margin-bottom: 4px; color: #111; }
        .om-toast-body { font-size: 13px; color: #6b7280; }

        /* Stats active coloring */
        .om-stat-card.has-pending { border-left: 3px solid #f59e0b; }

        /* Delivery tracking map */
        .om-tracking-map { height: 220px; border-radius: 8px; border: 1px solid #e5e7eb; margin-bottom: 12px; z-index: 0; }

        /* Status timeline */
        .om-status-timeline { display: flex; align-items: center; justify-content: space-between; padding: 16px 0; position: relative; }
        .om-status-timeline::before { content: ''; position: absolute; top: 50%; left: 24px; right: 24px; height: 3px; background: #e5e7eb; transform: translateY(-50%); z-index: 0; }
        .om-timeline-step { display: flex; flex-direction: column; align-items: center; gap: 6px; z-index: 1; position: relative; }
        .om-timeline-dot { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; border: 3px solid #e5e7eb; background: #fff; color: #9ca3af; transition: all 0.3s; }
        .om-timeline-dot.completed { background: #22c55e; border-color: #22c55e; color: #fff; }
        .om-timeline-dot.current { background: #3b82f6; border-color: #3b82f6; color: #fff; animation: pulse-step 2s infinite; }
        .om-timeline-label { font-size: 10px; color: #6b7280; text-align: center; max-width: 60px; line-height: 1.2; }
        .om-timeline-label.completed { color: #16a34a; font-weight: 600; }
        .om-timeline-label.current { color: #2563eb; font-weight: 600; }
        @keyframes pulse-step { 0%,100% { box-shadow: 0 0 0 0 rgba(59,130,246,0.4); } 50% { box-shadow: 0 0 0 8px rgba(59,130,246,0); } }
    </style>
</head>
<body class="om-app-layout">
    <!-- Toast container -->
    <div id="orderToast" class="om-toast">
        <div class="om-toast-title" id="toastTitle">Novo Pedido!</div>
        <div class="om-toast-body" id="toastBody"></div>
    </div>

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
            <a href="pedidos.php" class="om-sidebar-link active">
                <i class="lucide-shopping-bag"></i>
                <span>Pedidos</span>
                <?php if (($stats['pendentes'] ?? 0) > 0): ?>
                <span class="om-sidebar-link-badge" id="sidebarPendingBadge"><?= $stats['pendentes'] ?></span>
                <?php endif; ?>
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
            <a href="faturamento.php" class="om-sidebar-link">
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

            <h1 class="om-topbar-title">
                Pedidos
                <span class="om-pusher-status" id="pusherStatus">
                    <span class="om-pusher-dot" id="pusherDot"></span>
                    <span id="pusherLabel">Conectando...</span>
                </span>
            </h1>

            <div class="om-topbar-actions">
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($mercado_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="om-page-content">
            <!-- Stats do Dia -->
            <div class="om-stats-grid om-mb-6">
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-primary-light">
                        <i class="lucide-shopping-bag"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value" id="statTotal"><?= $stats['total'] ?? 0 ?></span>
                        <span class="om-stat-label">Pedidos Hoje</span>
                    </div>
                </div>

                <div class="om-stat-card <?= ($stats['pendentes'] ?? 0) > 0 ? 'has-pending' : '' ?>">
                    <div class="om-stat-icon om-bg-warning-light">
                        <i class="lucide-clock"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value" id="statPendentes"><?= $stats['pendentes'] ?? 0 ?></span>
                        <span class="om-stat-label">Pendentes</span>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-info-light">
                        <i class="lucide-chef-hat"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= ($stats['aceitos'] ?? 0) + ($stats['preparando'] ?? 0) ?></span>
                        <span class="om-stat-label">Em Preparo</span>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-success-light">
                        <i class="lucide-check-circle"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['entregues'] ?? 0 ?></span>
                        <span class="om-stat-label">Entregues</span>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="om-card om-mb-6">
                <div class="om-card-body">
                    <form method="GET" class="om-filters-form">
                        <div class="om-form-row">
                            <div class="om-form-group om-col-md-3">
                                <label class="om-label">Buscar</label>
                                <input type="text" name="busca" class="om-input" placeholder="Pedido ou cliente..." value="<?= htmlspecialchars($busca) ?>">
                            </div>

                            <div class="om-form-group om-col-md-2">
                                <label class="om-label">Status</label>
                                <select name="status" class="om-select">
                                    <option value="">Todos</option>
                                    <?php foreach ($status_map as $key => $val): ?>
                                    <option value="<?= $key ?>" <?= $status_filter === $key ? 'selected' : '' ?>><?= $val['label'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="om-form-group om-col-md-2">
                                <label class="om-label">Data Inicio</label>
                                <input type="date" name="data_inicio" class="om-input" value="<?= $data_inicio ?>">
                            </div>

                            <div class="om-form-group om-col-md-2">
                                <label class="om-label">Data Fim</label>
                                <input type="date" name="data_fim" class="om-input" value="<?= $data_fim ?>">
                            </div>

                            <div class="om-form-group om-col-md-3 om-flex om-items-end om-gap-2">
                                <button type="submit" class="om-btn om-btn-primary">
                                    <i class="lucide-search"></i> Filtrar
                                </button>
                                <a href="pedidos.php" class="om-btn om-btn-outline">Limpar</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Lista de Pedidos -->
            <div class="om-card">
                <div class="om-card-header">
                    <h3 class="om-card-title">Lista de Pedidos</h3>
                    <span class="om-badge om-badge-neutral"><?= $total ?> pedidos</span>
                </div>

                <div class="om-table-responsive">
                    <table class="om-table">
                        <thead>
                            <tr>
                                <th>Pedido</th>
                                <th>Cliente</th>
                                <th>Itens</th>
                                <th>Valor</th>
                                <th>Pagamento</th>
                                <th>Status</th>
                                <th>Data</th>
                                <th>Acoes</th>
                            </tr>
                        </thead>
                        <tbody id="ordersTableBody">
                            <?php if (empty($pedidos)): ?>
                            <tr>
                                <td colspan="8" class="om-text-center om-py-8">
                                    <div class="om-empty-state">
                                        <i class="lucide-inbox om-text-4xl om-text-muted"></i>
                                        <p class="om-mt-2">Nenhum pedido encontrado</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($pedidos as $pedido): ?>
                            <?php
                                $isPending = ($pedido['status'] === 'pendente');
                                $dateAdded = strtotime($pedido['date_added']);
                                $elapsedSec = time() - $dateAdded;
                                $timerMaxSec = 600; // 10 minutes
                                $remainingSec = max(0, $timerMaxSec - $elapsedSec);
                                $timerPct = $timerMaxSec > 0 ? ($remainingSec / $timerMaxSec) * 100 : 0;
                                $isExpired = ($remainingSec <= 0);
                                $timerClass = $timerPct > 50 ? '' : ($timerPct > 20 ? 'warning' : 'critical');
                                $rowClass = '';
                                if ($isPending) {
                                    $rowClass = $isExpired ? 'pending-expired' : 'pending-row';
                                }
                            ?>
                            <tr class="<?= $rowClass ?>" data-order-id="<?= $pedido['order_id'] ?>" data-status="<?= $pedido['status'] ?>">
                                <td>
                                    <span class="om-font-mono om-font-semibold">#<?= $pedido['order_id'] ?></span>
                                    <?php if (!empty($pedido['order_number'])): ?>
                                    <div class="om-text-xs om-text-muted"><?= htmlspecialchars($pedido['order_number']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="om-flex om-items-center om-gap-2">
                                        <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($pedido['customer_name'] ?? 'C', 0, 1)) ?></div>
                                        <div>
                                            <div class="om-font-medium"><?= htmlspecialchars($pedido['customer_name'] ?? 'Cliente') ?></div>
                                            <div class="om-text-xs om-text-muted"><?= htmlspecialchars($pedido['customer_phone'] ?? '') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="om-badge om-badge-neutral"><?= $pedido['total_itens'] ?> itens</span>
                                </td>
                                <td>
                                    <span class="om-font-semibold">R$ <?= number_format($pedido['total'] ?? 0, 2, ',', '.') ?></span>
                                </td>
                                <td>
                                    <span class="om-text-sm"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $pedido['forma_pagamento'] ?? '-'))) ?></span>
                                </td>
                                <td>
                                    <?php $st = $status_map[$pedido['status']] ?? ['label' => $pedido['status'], 'class' => 'neutral']; ?>
                                    <span class="om-badge om-badge-<?= $st['class'] ?>"><?= $st['label'] ?></span>
                                    <?php if ($isPending): ?>
                                    <!-- Timer countdown bar -->
                                    <div class="order-timer">
                                        <div class="order-timer-bar <?= $timerClass ?>"
                                             id="timer-bar-<?= $pedido['order_id'] ?>"
                                             style="width: <?= round($timerPct, 1) ?>%"
                                             data-date-added="<?= $pedido['date_added'] ?>"
                                             data-max-seconds="<?= $timerMaxSec ?>"></div>
                                    </div>
                                    <span class="order-timer-label <?= $isExpired ? 'expired' : ($timerPct < 30 ? 'warning' : 'ok') ?>"
                                          id="timer-label-<?= $pedido['order_id'] ?>">
                                        <?php if ($isExpired): ?>
                                            EXPIRADO
                                        <?php else: ?>
                                            <?= floor($remainingSec / 60) ?>:<?= str_pad($remainingSec % 60, 2, '0', STR_PAD_LEFT) ?>
                                        <?php endif; ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="om-text-sm"><?= date('d/m/Y', strtotime($pedido['date_added'])) ?></div>
                                    <div class="om-text-xs om-text-muted"><?= date('H:i', strtotime($pedido['date_added'])) ?></div>
                                </td>
                                <td>
                                    <div class="om-btn-group" style="flex-wrap:wrap;gap:4px;">
                                        <?php if ($isPending): ?>
                                        <div class="pending-actions">
                                            <button class="om-btn om-btn-sm btn-aceitar" onclick="acaoPedido(<?= $pedido['order_id'] ?>, 'aceitar')" title="Aceitar Pedido">
                                                <i class="lucide-check"></i> Aceitar
                                            </button>
                                            <button class="om-btn om-btn-sm btn-recusar" onclick="recusarPedido(<?= $pedido['order_id'] ?>)" title="Recusar Pedido">
                                                <i class="lucide-x"></i> Recusar
                                            </button>
                                        </div>
                                        <?php elseif ($pedido['status'] === 'aceito' || $pedido['status'] === 'confirmado'): ?>
                                        <button class="om-btn om-btn-xs om-btn-primary" onclick="acaoPedido(<?= $pedido['order_id'] ?>, 'preparando')" title="Preparando">
                                            <i class="lucide-chef-hat"></i> Preparando
                                        </button>
                                        <?php elseif ($pedido['status'] === 'preparando'): ?>
                                        <button class="om-btn om-btn-xs om-btn-success" onclick="acaoPedido(<?= $pedido['order_id'] ?>, 'pronto')" title="Pronto">
                                            <i class="lucide-check-circle"></i> Pronto
                                        </button>
                                        <?php endif; ?>
                                        <button class="om-btn om-btn-xs om-btn-ghost" onclick="verPedido(<?= $pedido['order_id'] ?>)" title="Ver detalhes">
                                            <i class="lucide-eye"></i>
                                        </button>
                                        <button class="om-btn om-btn-xs om-btn-ghost" onclick="printReceipt(<?= $pedido['order_id'] ?>)" title="Imprimir">
                                            <i class="lucide-printer"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_paginas > 1): ?>
                <div class="om-card-footer">
                    <div class="om-pagination">
                        <?php if ($pagina > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])) ?>" class="om-pagination-btn">
                            <i class="lucide-chevron-left"></i>
                        </a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $pagina - 2); $i <= min($total_paginas, $pagina + 2); $i++): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"
                           class="om-pagination-btn <?= $i === $pagina ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                        <?php endfor; ?>

                        <?php if ($pagina < $total_paginas): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])) ?>" class="om-pagination-btn">
                            <i class="lucide-chevron-right"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal Detalhes do Pedido -->
    <div id="modalPedido" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModal()"></div>
        <div class="om-modal-content om-modal-lg">
            <div class="om-modal-header">
                <h3 class="om-modal-title">Detalhes do Pedido <span id="modalPedidoId"></span></h3>
                <button class="om-modal-close" onclick="fecharModal()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <div class="om-modal-body" id="modalPedidoBody">
                <div class="om-text-center om-py-8">
                    <div class="om-spinner"></div>
                    <p class="om-mt-2">Carregando...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Recusar -->
    <div id="modalRecusar" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalRecusar()"></div>
        <div class="om-modal-content om-modal-sm">
            <div class="om-modal-header">
                <h3 class="om-modal-title">Recusar Pedido</h3>
                <button class="om-modal-close" onclick="fecharModalRecusar()"><i class="lucide-x"></i></button>
            </div>
            <div class="om-modal-body">
                <input type="hidden" id="recusarOrderId" value="">
                <div class="om-form-group">
                    <label class="om-label">Motivo da recusa *</label>
                    <textarea id="recusarMotivo" class="om-input" rows="3" placeholder="Ex: Produto indisponivel, loja fechando..." required></textarea>
                </div>
            </div>
            <div class="om-modal-footer">
                <button class="om-btn om-btn-outline" onclick="fecharModalRecusar()">Cancelar</button>
                <button class="om-btn om-btn-error" onclick="confirmarRecusa()">Recusar Pedido</button>
            </div>
        </div>
    </div>

    <script>
    // ═══════════════════════════════════════════════════════════
    // CONSTANTS
    // ═══════════════════════════════════════════════════════════
    const PARTNER_ID = <?= (int)$mercado_id ?>;
    const TIMER_MAX_SECONDS = 600; // 10 minutes
    const ALERT_AFTER_SECONDS = 300; // 5 minutes - start alert sound

    // ═══════════════════════════════════════════════════════════
    // SOUND: Web Audio API alert for new/pending orders
    // ═══════════════════════════════════════════════════════════
    let audioCtx = null;

    function playSound() {
        try {
            if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.frequency.value = 800;
            osc.type = 'sine';
            gain.gain.value = 0.3;
            osc.start();
            osc.stop(audioCtx.currentTime + 0.15);
            setTimeout(() => {
                const osc2 = audioCtx.createOscillator();
                const gain2 = audioCtx.createGain();
                osc2.connect(gain2);
                gain2.connect(audioCtx.destination);
                osc2.frequency.value = 1000;
                osc2.type = 'sine';
                gain2.gain.value = 0.3;
                osc2.start();
                osc2.stop(audioCtx.currentTime + 0.15);
            }, 200);
            setTimeout(() => {
                const osc3 = audioCtx.createOscillator();
                const gain3 = audioCtx.createGain();
                osc3.connect(gain3);
                gain3.connect(audioCtx.destination);
                osc3.frequency.value = 1200;
                osc3.type = 'sine';
                gain3.gain.value = 0.3;
                osc3.start();
                osc3.stop(audioCtx.currentTime + 0.3);
            }, 400);
        } catch(e) {}
    }

    function playAlertSound() {
        try {
            if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            // Urgent double-beep for overdue orders
            [0, 300].forEach(delay => {
                setTimeout(() => {
                    const osc = audioCtx.createOscillator();
                    const gain = audioCtx.createGain();
                    osc.connect(gain);
                    gain.connect(audioCtx.destination);
                    osc.frequency.value = 1200;
                    osc.type = 'square';
                    gain.gain.value = 0.25;
                    osc.start();
                    osc.stop(audioCtx.currentTime + 0.2);
                }, delay);
            });
        } catch(e) {}
    }

    // ═══════════════════════════════════════════════════════════
    // TIMER: Countdown bars for pending orders
    // ═══════════════════════════════════════════════════════════
    let lastAlertTime = 0;

    function updateTimers() {
        const now = Date.now();
        const bars = document.querySelectorAll('.order-timer-bar');
        let hasOverdue = false;

        bars.forEach(bar => {
            const dateAdded = new Date(bar.dataset.dateAdded).getTime();
            const maxSec = parseInt(bar.dataset.maxSeconds) || TIMER_MAX_SECONDS;
            const elapsed = (now - dateAdded) / 1000;
            const remaining = Math.max(0, maxSec - elapsed);
            const pct = (remaining / maxSec) * 100;

            // Update bar width
            bar.style.width = pct.toFixed(1) + '%';

            // Update bar color class
            bar.className = 'order-timer-bar';
            if (pct <= 20) {
                bar.classList.add('critical');
            } else if (pct <= 50) {
                bar.classList.add('warning');
            }

            // Update label
            const orderId = bar.id.replace('timer-bar-', '');
            const label = document.getElementById('timer-label-' + orderId);
            if (label) {
                if (remaining <= 0) {
                    label.textContent = 'EXPIRADO';
                    label.className = 'order-timer-label expired';
                    // Mark row as expired
                    const row = bar.closest('tr');
                    if (row) {
                        row.classList.remove('pending-row');
                        row.classList.add('pending-expired');
                    }
                } else {
                    const mins = Math.floor(remaining / 60);
                    const secs = Math.floor(remaining % 60);
                    label.textContent = mins + ':' + String(secs).padStart(2, '0');
                    label.className = 'order-timer-label ' + (pct < 30 ? 'warning' : 'ok');
                }
            }

            // Track if any order is overdue (past 5 min alert threshold)
            if (elapsed >= ALERT_AFTER_SECONDS) {
                hasOverdue = true;
            }
        });

        // Play alert sound every 30 seconds for overdue pending orders
        if (hasOverdue && (now - lastAlertTime) >= 30000) {
            playAlertSound();
            lastAlertTime = now;
        }
    }

    // Update timers every second
    setInterval(updateTimers, 1000);
    // Initial update
    updateTimers();

    // ═══════════════════════════════════════════════════════════
    // TOAST NOTIFICATION
    // ═══════════════════════════════════════════════════════════
    const toast = document.getElementById('orderToast');
    const toastTitle = document.getElementById('toastTitle');
    const toastBody = document.getElementById('toastBody');
    let toastTimer = null;

    function showToast(title, message) {
        toastTitle.textContent = title;
        toastBody.textContent = message;
        toast.classList.add('show');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 6000);
    }

    // ═══════════════════════════════════════════════════════════
    // PUSHER: Real-time order updates (primary)
    // ═══════════════════════════════════════════════════════════
    const pusherDot = document.getElementById('pusherDot');
    const pusherLabel = document.getElementById('pusherLabel');
    let pusherConnected = false;
    let pollInterval = null;

    try {
        const pusher = new Pusher('1cd7a205ab19e56edcfe', {
            cluster: 'sa1',
            forceTLS: true
        });

        // Connection state monitoring
        pusher.connection.bind('connected', () => {
            pusherConnected = true;
            pusherDot.className = 'om-pusher-dot connected';
            pusherLabel.textContent = 'Tempo real';
            console.log('[Pusher] Conectado');
            startFallbackPolling(30000); // Slow polling when Pusher active
        });

        pusher.connection.bind('disconnected', () => {
            pusherConnected = false;
            pusherDot.className = 'om-pusher-dot';
            pusherLabel.textContent = 'Desconectado';
            console.log('[Pusher] Desconectado - polling ativo');
            startFallbackPolling(10000); // Fast polling when Pusher down
        });

        pusher.connection.bind('connecting', () => {
            pusherDot.className = 'om-pusher-dot connecting';
            pusherLabel.textContent = 'Conectando...';
        });

        pusher.connection.bind('error', (err) => {
            console.error('[Pusher] Erro:', err);
            pusherConnected = false;
            pusherDot.className = 'om-pusher-dot';
            pusherLabel.textContent = 'Erro';
            startFallbackPolling(10000);
        });

        // Subscribe to partner channel
        const channel = pusher.subscribe('partner-' + PARTNER_ID);

        channel.bind('new-order', function(data) {
            console.log('[Pusher] Novo pedido:', data);
            playSound();
            showToast(
                'Novo Pedido!',
                'Pedido #' + (data.order_number || data.order_id || '') +
                ' - R$ ' + (data.total ? parseFloat(data.total).toFixed(2).replace('.', ',') : '0,00') +
                ' - ' + (data.customer_name || 'Cliente')
            );
            // Reload to show new order (debounced)
            setTimeout(() => location.reload(), 2000);
        });

        channel.bind('order-update', function(data) {
            console.log('[Pusher] Pedido atualizado:', data);
            updateOrderRowStatus(data);
        });

        channel.bind('order-status', function(data) {
            console.log('[Pusher] Status atualizado:', data);
            updateOrderRowStatus(data);
        });

    } catch(e) {
        console.error('[Pusher] Falha ao inicializar:', e);
        pusherDot.className = 'om-pusher-dot';
        pusherLabel.textContent = 'Indisponivel';
        startFallbackPolling(10000);
    }

    // ═══════════════════════════════════════════════════════════
    // POLLING: Fallback for when Pusher is unavailable
    // ═══════════════════════════════════════════════════════════
    let lastPollTime = new Date().toISOString();
    let knownOrderIds = new Set(<?= json_encode(array_column($pedidos, 'order_id')) ?>);

    async function pollPedidos() {
        try {
            const res = await fetch('/api/mercado/pedido/polling.php?since=' + encodeURIComponent(lastPollTime));
            const data = await res.json();

            if (data.success && data.data) {
                let hasNew = false;
                if (data.data.pedidos && data.data.pedidos.length > 0) {
                    data.data.pedidos.forEach(p => {
                        if (!knownOrderIds.has(p.order_id) && p.status === 'pendente') {
                            hasNew = true;
                            knownOrderIds.add(p.order_id);
                        }
                    });
                }

                if (hasNew && !pusherConnected) {
                    playSound();
                    location.reload();
                }

                lastPollTime = data.data.server_time || new Date().toISOString();

                // Update pending count
                const statPendentes = document.getElementById('statPendentes');
                if (statPendentes && data.data.pendentes !== undefined) {
                    statPendentes.textContent = data.data.pendentes;
                }
            }
        } catch (e) {
            console.log('[Polling] Erro:', e);
        }
    }

    function startFallbackPolling(interval) {
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(pollPedidos, interval);
    }

    // Start with 30s polling (Pusher will adjust)
    startFallbackPolling(30000);

    // ═══════════════════════════════════════════════════════════
    // ACTIONS: Accept, prepare, ready, reject
    // ═══════════════════════════════════════════════════════════
    async function acaoPedido(orderId, acao) {
        try {
            const btn = event.target.closest('button');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="lucide-loader-2"></i> ...'; }

            const res = await fetch('/api/mercado/pedido/' + acao + '.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: orderId })
            });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Erro ao processar acao');
                if (btn) { btn.disabled = false; }
            }
        } catch(e) {
            alert('Erro de conexao');
        }
    }

    function recusarPedido(orderId) {
        document.getElementById('recusarOrderId').value = orderId;
        document.getElementById('recusarMotivo').value = '';
        document.getElementById('modalRecusar').classList.add('open');
    }

    function fecharModalRecusar() {
        document.getElementById('modalRecusar').classList.remove('open');
    }

    async function confirmarRecusa() {
        const orderId = document.getElementById('recusarOrderId').value;
        const motivo = document.getElementById('recusarMotivo').value.trim();
        if (!motivo) { alert('Informe o motivo da recusa'); return; }

        try {
            const res = await fetch('/api/mercado/pedido/recusar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: parseInt(orderId), motivo: motivo })
            });
            const data = await res.json();
            if (data.success) {
                fecharModalRecusar();
                location.reload();
            } else {
                alert(data.message);
            }
        } catch(e) {
            alert('Erro de conexao');
        }
    }

    // Update order row status in-place (from Pusher events)
    function updateOrderRowStatus(data) {
        const orderId = data.id || data.order_id;
        if (!orderId) return;
        const row = document.querySelector('tr[data-order-id="' + orderId + '"]');
        if (row) {
            const statusBadge = row.querySelector('.om-badge');
            const statusMap = {
                'pendente': { label: 'Pendente', cls: 'warning' },
                'confirmado': { label: 'Confirmado', cls: 'info' },
                'aceito': { label: 'Aceito', cls: 'info' },
                'preparando': { label: 'Preparando', cls: 'info' },
                'pronto': { label: 'Pronto', cls: 'success' },
                'coletando': { label: 'Coletando', cls: 'primary' },
                'em_entrega': { label: 'Em Entrega', cls: 'primary' },
                'delivered': { label: 'Entregue', cls: 'success' },
                'entregue': { label: 'Entregue', cls: 'success' },
                'retirado': { label: 'Retirado', cls: 'success' },
                'cancelado': { label: 'Cancelado', cls: 'error' }
            };
            if (statusBadge && data.status && statusMap[data.status]) {
                statusBadge.className = 'om-badge om-badge-' + statusMap[data.status].cls;
                statusBadge.textContent = statusMap[data.status].label;
            }
            row.dataset.status = data.status;
        }
    }

    // ═══════════════════════════════════════════════════════════
    // MODAL: View order details
    // ═══════════════════════════════════════════════════════════
    function verPedido(id) {
        document.getElementById('modalPedido').classList.add('open');
        document.getElementById('modalPedidoId').textContent = '#' + id;
        document.getElementById('modalPedidoBody').innerHTML =
            '<div class="om-text-center om-py-8"><div class="om-spinner"></div><p class="om-mt-2">Carregando...</p></div>';

        fetch('/painel/mercado/pedido-detalhe.php?id=' + id)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderPedido(data.pedido);
                } else {
                    document.getElementById('modalPedidoBody').innerHTML =
                        '<div class="om-alert om-alert-error">Erro ao carregar pedido</div>';
                }
            })
            .catch(err => {
                // Fallback to old endpoint if new one does not exist yet
                fetch('/api/mercado/pedido.php?id=' + id)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            renderPedido(data.pedido);
                        } else {
                            document.getElementById('modalPedidoBody').innerHTML =
                                '<div class="om-alert om-alert-error">Erro ao carregar pedido</div>';
                        }
                    })
                    .catch(() => {
                        document.getElementById('modalPedidoBody').innerHTML =
                            '<div class="om-alert om-alert-error">Erro de conexao</div>';
                    });
            });
    }

    // Store location for map
    const STORE_LAT = <?= json_encode((float)($mercado['latitude'] ?? $mercado['lat'] ?? 0)) ?>;
    const STORE_LNG = <?= json_encode((float)($mercado['longitude'] ?? $mercado['lng'] ?? 0)) ?>;

    let trackingMapInstance = null;

    function renderTrackingSection(pedido) {
        var html = '';
        // Status timeline - always show
        var steps = [
            { key: 'pendente', label: 'Pedido' },
            { key: 'preparando', label: 'Preparando' },
            { key: 'pronto', label: 'Pronto' },
            { key: 'coletando', label: 'Coletado' },
            { key: 'em_entrega', label: 'Em entrega' },
            { key: 'delivered', label: 'Entregue' }
        ];
        var statusOrder = ['pendente','confirmado','aceito','preparando','pronto','coletando','em_entrega','delivered','entregue','retirado'];
        var currentIdx = statusOrder.indexOf(pedido.status);
        if (pedido.status === 'cancelado') currentIdx = -2;
        if (pedido.status === 'retirado' || pedido.status === 'entregue') currentIdx = statusOrder.indexOf('delivered');

        html += '<h4 class="om-font-semibold om-mb-3">Acompanhamento</h4>';
        html += '<div class="om-status-timeline">';
        steps.forEach(function(step, i) {
            var stepIdx = statusOrder.indexOf(step.key);
            var isCompleted = currentIdx > stepIdx;
            var isCurrent = (currentIdx === stepIdx) || (step.key === 'preparando' && pedido.status === 'aceito') || (step.key === 'preparando' && pedido.status === 'confirmado');
            var dotClass = isCompleted ? 'completed' : (isCurrent ? 'current' : '');
            var labelClass = isCompleted ? 'completed' : (isCurrent ? 'current' : '');
            var icon = isCompleted ? '&#10003;' : (i + 1);
            html += '<div class="om-timeline-step">';
            html += '<div class="om-timeline-dot ' + dotClass + '">' + icon + '</div>';
            html += '<span class="om-timeline-label ' + labelClass + '">' + step.label + '</span>';
            html += '</div>';
        });
        html += '</div>';

        // Map - show if we have coordinates
        var custLat = parseFloat(pedido.customer_lat || pedido.customer_latitude || pedido.shipping_lat || pedido.shipping_latitude || 0);
        var custLng = parseFloat(pedido.customer_lng || pedido.customer_longitude || pedido.shipping_lng || pedido.shipping_longitude || 0);
        var storeLat = STORE_LAT;
        var storeLng = STORE_LNG;
        var hasCoords = (custLat !== 0 && custLng !== 0) || (storeLat !== 0 && storeLng !== 0);

        if (hasCoords && ['coletando','em_entrega','pronto'].indexOf(pedido.status) !== -1) {
            html += '<div id="trackingMap" class="om-tracking-map"></div>';
            // Defer map init until DOM is ready
            setTimeout(function() {
                initTrackingMap(storeLat, storeLng, custLat, custLng, pedido);
            }, 100);
        }

        return html;
    }

    function initTrackingMap(storeLat, storeLng, custLat, custLng, pedido) {
        var mapEl = document.getElementById('trackingMap');
        if (!mapEl) return;

        // Clean up previous map instance
        if (trackingMapInstance) {
            trackingMapInstance.remove();
            trackingMapInstance = null;
        }

        var centerLat = (storeLat && custLat) ? (storeLat + custLat) / 2 : (storeLat || custLat);
        var centerLng = (storeLng && custLng) ? (storeLng + custLng) / 2 : (storeLng || custLng);
        if (!centerLat || !centerLng) return;

        var map = L.map('trackingMap').setView([centerLat, centerLng], 14);
        trackingMapInstance = map;
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        var bounds = [];

        // Store marker (blue)
        if (storeLat && storeLng) {
            var storeIcon = L.divIcon({
                html: '<div style="background:#3b82f6;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);">&#x1F3EA;</div>',
                iconSize: [28, 28],
                iconAnchor: [14, 14],
                className: ''
            });
            L.marker([storeLat, storeLng], { icon: storeIcon }).addTo(map).bindPopup('Loja');
            bounds.push([storeLat, storeLng]);
        }

        // Customer marker (red)
        if (custLat && custLng) {
            var custIcon = L.divIcon({
                html: '<div style="background:#ef4444;color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);">&#x1F3E0;</div>',
                iconSize: [28, 28],
                iconAnchor: [14, 14],
                className: ''
            });
            L.marker([custLat, custLng], { icon: custIcon }).addTo(map).bindPopup('Cliente');
            bounds.push([custLat, custLng]);
        }

        // Fit bounds to show both markers
        if (bounds.length >= 2) {
            map.fitBounds(bounds, { padding: [30, 30] });
        }
    }

    function renderPedido(pedido) {
        const statusMap = {
            'pendente': { label: 'Pendente', class: 'warning' },
            'confirmado': { label: 'Confirmado', class: 'info' },
            'aceito': { label: 'Aceito', class: 'info' },
            'preparando': { label: 'Preparando', class: 'info' },
            'pronto': { label: 'Pronto', class: 'success' },
            'coletando': { label: 'Coletando', class: 'info' },
            'em_entrega': { label: 'Em Entrega', class: 'primary' },
            'delivered': { label: 'Entregue', class: 'success' },
            'entregue': { label: 'Entregue', class: 'success' },
            'retirado': { label: 'Retirado', class: 'success' },
            'cancelado': { label: 'Cancelado', class: 'error' }
        };

        const st = statusMap[pedido.status] || { label: pedido.status, class: 'neutral' };

        let html = '<div class="om-grid om-grid-cols-2 om-gap-4 om-mb-6">';
        html += '<div>';
        html += '<p class="om-text-muted om-text-sm">Cliente</p>';
        html += '<p class="om-font-semibold">' + (pedido.customer_name || 'N/A') + '</p>';
        html += '<p class="om-text-sm">' + (pedido.customer_phone || '') + '</p>';
        html += '</div>';
        html += '<div>';
        html += '<p class="om-text-muted om-text-sm">Status</p>';
        html += '<span class="om-badge om-badge-' + st.class + '">' + st.label + '</span>';
        html += '</div>';
        html += '<div>';
        html += '<p class="om-text-muted om-text-sm">Endereco de Entrega</p>';
        html += '<p class="om-text-sm">' + (pedido.delivery_address || pedido.endereco_completo || 'N/A') + '</p>';
        html += '</div>';
        html += '<div>';
        html += '<p class="om-text-muted om-text-sm">Data/Hora</p>';
        html += '<p class="om-text-sm">' + new Date(pedido.date_added || pedido.created_at).toLocaleString('pt-BR') + '</p>';
        html += '</div>';
        if (pedido.forma_pagamento) {
            html += '<div>';
            html += '<p class="om-text-muted om-text-sm">Pagamento</p>';
            html += '<p class="om-text-sm">' + pedido.forma_pagamento.replace(/_/g, ' ') + '</p>';
            html += '</div>';
        }
        if (pedido.notes || pedido.observacoes) {
            html += '<div>';
            html += '<p class="om-text-muted om-text-sm">Observacoes</p>';
            html += '<p class="om-text-sm">' + (pedido.notes || pedido.observacoes) + '</p>';
            html += '</div>';
        }
        html += '</div>';

        // Driver / Entregador info section
        html += '<div class="om-driver-info om-mb-6" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;">';
        if (pedido.driver_name || pedido.shopper_name) {
            var driverName = pedido.driver_name || pedido.shopper_name || '';
            var driverPhone = pedido.driver_phone || pedido.shopper_phone || '';
            var vehicleType = pedido.vehicle_required || pedido.tipo_entrega || '';
            var vehicleLabel = vehicleType ? (' | ' + vehicleType.replace(/_/g, ' ')) : '';
            html += '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
            html += '<span style="font-size:18px;">&#x1F6F5;</span>';
            html += '<span class="om-font-semibold" style="font-size:14px;">Entregador: ' + driverName + '</span>';
            if (vehicleLabel) {
                html += '<span class="om-badge om-badge-info" style="font-size:11px;">' + vehicleLabel.trim().replace(/^\|/, '').trim() + '</span>';
            }
            if (driverPhone) {
                html += '<a href="tel:' + driverPhone + '" style="display:inline-flex;align-items:center;gap:4px;color:#16a34a;font-weight:500;font-size:13px;text-decoration:none;margin-left:8px;">';
                html += '<span style="font-size:15px;">&#x1F4DE;</span> ' + driverPhone + '</a>';
            }
            html += '</div>';
        } else {
            html += '<div style="display:flex;align-items:center;gap:8px;color:#6b7280;">';
            html += '<span style="font-size:18px;">&#x1F6F5;</span>';
            html += '<span style="font-size:14px;font-style:italic;">Aguardando entregador...</span>';
            html += '</div>';
        }
        html += '</div>';

        html += '<h4 class="om-font-semibold om-mb-3">Itens do Pedido</h4>';
        html += '<div class="om-table-responsive om-mb-4"><table class="om-table om-table-sm">';
        html += '<thead><tr><th>Produto</th><th class="om-text-center">Qtd</th><th class="om-text-right">Preco</th><th class="om-text-right">Total</th></tr></thead>';
        html += '<tbody>';

        if (pedido.itens) {
            pedido.itens.forEach(item => {
                const itemName = item.name || item.product_name || 'Produto';
                const itemPrice = parseFloat(item.price || 0);
                const itemQty = parseInt(item.quantity || 1);
                html += '<tr>';
                html += '<td>' + itemName + '</td>';
                html += '<td class="om-text-center">' + itemQty + '</td>';
                html += '<td class="om-text-right">R$ ' + itemPrice.toFixed(2).replace('.', ',') + '</td>';
                html += '<td class="om-text-right">R$ ' + (itemQty * itemPrice).toFixed(2).replace('.', ',') + '</td>';
                html += '</tr>';
            });
        }

        html += '</tbody>';
        html += '<tfoot>';
        html += '<tr><td colspan="3" class="om-text-right om-font-semibold">Subtotal</td>';
        html += '<td class="om-text-right">R$ ' + parseFloat(pedido.subtotal || 0).toFixed(2).replace('.', ',') + '</td></tr>';
        if (parseFloat(pedido.delivery_fee || 0) > 0) {
            html += '<tr><td colspan="3" class="om-text-right">Taxa de Entrega</td>';
            html += '<td class="om-text-right">R$ ' + parseFloat(pedido.delivery_fee || 0).toFixed(2).replace('.', ',') + '</td></tr>';
        }
        if (parseFloat(pedido.service_fee || 0) > 0) {
            html += '<tr><td colspan="3" class="om-text-right">Taxa de Servico</td>';
            html += '<td class="om-text-right">R$ ' + parseFloat(pedido.service_fee || 0).toFixed(2).replace('.', ',') + '</td></tr>';
        }
        if (parseFloat(pedido.coupon_discount || 0) > 0) {
            html += '<tr><td colspan="3" class="om-text-right om-text-success">Desconto Cupom</td>';
            html += '<td class="om-text-right om-text-success">- R$ ' + parseFloat(pedido.coupon_discount || 0).toFixed(2).replace('.', ',') + '</td></tr>';
        }
        html += '<tr><td colspan="3" class="om-text-right om-font-bold">Total</td>';
        html += '<td class="om-text-right om-font-bold">R$ ' + parseFloat(pedido.total || 0).toFixed(2).replace('.', ',') + '</td></tr>';
        html += '</tfoot></table></div>';

        // Delivery tracking section
        html += renderTrackingSection(pedido);

        // Print button inside modal
        html += '<div class="om-flex om-justify-end om-gap-2">';
        html += '<button class="om-btn om-btn-outline om-btn-sm" onclick="printReceipt(' + (pedido.order_id) + ')">';
        html += '<i class="lucide-printer"></i> Imprimir</button>';
        html += '</div>';

        document.getElementById('modalPedidoBody').innerHTML = html;
    }

    function fecharModal() {
        document.getElementById('modalPedido').classList.remove('open');
    }

    // ═══════════════════════════════════════════════════════════
    // PRINT: Open print-friendly receipt window
    // ═══════════════════════════════════════════════════════════
    function printReceipt(orderId) {
        const win = window.open('/painel/mercado/imprimir-pedido.php?id=' + orderId, '_blank', 'width=400,height=600');
        if (win) {
            win.addEventListener('load', function() {
                setTimeout(function() { win.print(); }, 500);
            });
        }
    }

    // Fechar modal com ESC
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { fecharModal(); fecharModalRecusar(); }
    });

    // Initialize AudioContext on first user interaction (required by browsers)
    document.addEventListener('click', function initAudio() {
        if (!audioCtx) {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        document.removeEventListener('click', initAudio);
    }, { once: true });
    </script>
</body>
</html>
