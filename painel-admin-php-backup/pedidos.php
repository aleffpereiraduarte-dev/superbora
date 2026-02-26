<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL ADMIN - Gestão de Pedidos
 * ══════════════════════════════════════════════════════════════════════════════
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
$db = getDB();

$admin_nome = $_SESSION['admin_nome'];

// Filtros
$busca = $_GET['busca'] ?? '';
$status = $_GET['status'] ?? '';
$mercado = $_GET['mercado'] ?? '';
$shopper = $_GET['shopper'] ?? '';
$cliente = $_GET['cliente'] ?? '';
$filter = $_GET['filter'] ?? '';
$data = $_GET['data'] ?? '';
$pagina = max(1, intval($_GET['pagina'] ?? 1));
$por_pagina = 25;
$offset = ($pagina - 1) * $por_pagina;

$where = "WHERE 1=1";
$params = [];

if ($busca) {
    $where .= " AND (o.order_id = ? OR o.customer_name LIKE ?)";
    $params[] = $busca;
    $params[] = "%$busca%";
}

if ($status) {
    $where .= " AND o.status = ?";
    $params[] = $status;
}

if ($mercado) {
    $where .= " AND o.partner_id = ?";
    $params[] = $mercado;
}

if ($shopper) {
    $where .= " AND o.shopper_id = ?";
    $params[] = $shopper;
}

if ($cliente) {
    $where .= " AND o.customer_id = ?";
    $params[] = $cliente;
}

if ($filter === 'atrasados') {
    $where .= " AND o.status NOT IN ('finalizado', 'cancelado') AND o.updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
}

if ($data) {
    $where .= " AND DATE(o.created_at) = ?";
    $params[] = $data;
}

$stmt = $db->prepare("SELECT COUNT(*) FROM om_orders o $where");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$total_paginas = ceil($total / $por_pagina);

$stmt = $db->prepare("
    SELECT o.*,
           p.name as mercado_name,
           s.name as shopper_name,
           (SELECT COUNT(*) FROM om_order_items WHERE order_id = o.order_id) as total_itens
    FROM om_orders o
    LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
    LEFT JOIN om_shoppers s ON o.shopper_id = s.shopper_id
    $where
    ORDER BY o.created_at DESC
    LIMIT $por_pagina OFFSET $offset
");
$stmt->execute($params);
$pedidos = $stmt->fetchAll();

$stmt = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as pendentes,
        SUM(CASE WHEN status IN ('em_coleta', 'em_entrega') THEN 1 ELSE 0 END) as andamento,
        SUM(CASE WHEN status = 'finalizado' AND DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as finalizados_hoje,
        SUM(CASE WHEN status NOT IN ('finalizado', 'cancelado') AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 1 ELSE 0 END) as atrasados
    FROM om_orders
");
$stats = $stmt->fetch();

$status_map = [
    'pendente' => ['label' => 'Pendente', 'class' => 'warning'],
    'aceito' => ['label' => 'Aceito', 'class' => 'info'],
    'em_coleta' => ['label' => 'Em Coleta', 'class' => 'info'],
    'coletado' => ['label' => 'Coletado', 'class' => 'info'],
    'em_entrega' => ['label' => 'Em Entrega', 'class' => 'primary'],
    'finalizado' => ['label' => 'Finalizado', 'class' => 'success'],
    'cancelado' => ['label' => 'Cancelado', 'class' => 'error']
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos - Admin OneMundo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
    <style>
        .om-sidebar { background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%); }
        .om-sidebar-link:hover, .om-sidebar-link.active { background: rgba(255,255,255,0.1); }
        .om-sidebar-section {
            padding: var(--om-space-2) var(--om-space-4);
            font-size: var(--om-font-xs);
            font-weight: var(--om-font-semibold);
            color: rgba(255,255,255,0.5);
            text-transform: uppercase;
            margin-top: var(--om-space-4);
        }
    </style>
</head>
<body class="om-app-layout">
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <img src="/assets/img/logo-onemundo-white.png" alt="OneMundo" class="om-sidebar-logo"
                 onerror="this.outerHTML='<span class=\'om-sidebar-logo-text\'>OneMundo</span>'">
            <span class="om-badge om-badge-sm" style="background: rgba(255,255,255,0.2); color: white;">ADMIN</span>
        </div>

        <nav class="om-sidebar-nav">
            <a href="index.php" class="om-sidebar-link"><i class="lucide-layout-dashboard"></i><span>Dashboard</span></a>
            <div class="om-sidebar-section">Suporte</div>
            <a href="tickets.php" class="om-sidebar-link"><i class="lucide-headphones"></i><span>Tickets</span></a>
            <a href="clientes.php" class="om-sidebar-link"><i class="lucide-users"></i><span>Clientes</span></a>
            <a href="shoppers.php" class="om-sidebar-link"><i class="lucide-shopping-cart"></i><span>Shoppers</span></a>
            <a href="motoristas.php" class="om-sidebar-link"><i class="lucide-truck"></i><span>Motoristas</span></a>
            <a href="mercados.php" class="om-sidebar-link"><i class="lucide-store"></i><span>Mercados</span></a>
            <div class="om-sidebar-section">Operações</div>
            <a href="pedidos.php" class="om-sidebar-link active"><i class="lucide-package"></i><span>Pedidos</span></a>
            <a href="financeiro.php" class="om-sidebar-link"><i class="lucide-wallet"></i><span>Financeiro</span></a>
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
            <h1 class="om-topbar-title">Pedidos</h1>
            <div class="om-topbar-actions">
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($admin_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($admin_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <div class="om-page-content">
            <!-- Stats -->
            <div class="om-stats-grid om-mb-6" style="grid-template-columns: repeat(5, 1fr);">
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-primary-light"><i class="lucide-package"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['total'] ?></span>
                        <span class="om-stat-label">Total</span>
                    </div>
                </div>
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-warning-light"><i class="lucide-clock"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['pendentes'] ?></span>
                        <span class="om-stat-label">Pendentes</span>
                    </div>
                </div>
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-info-light"><i class="lucide-bike"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['andamento'] ?></span>
                        <span class="om-stat-label">Em Andamento</span>
                    </div>
                </div>
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-success-light"><i class="lucide-check-circle"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['finalizados_hoje'] ?></span>
                        <span class="om-stat-label">Finalizados Hoje</span>
                    </div>
                </div>
                <div class="om-stat-card <?= $stats['atrasados'] > 0 ? 'om-card-highlight-error' : '' ?>">
                    <div class="om-stat-icon om-bg-error-light"><i class="lucide-alert-triangle"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['atrasados'] ?></span>
                        <span class="om-stat-label">Atrasados</span>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="om-card om-mb-6">
                <div class="om-card-body">
                    <form method="GET" class="om-flex om-flex-wrap om-gap-4 om-items-end">
                        <div class="om-form-group om-mb-0">
                            <label class="om-label">Buscar</label>
                            <input type="text" name="busca" class="om-input" placeholder="ID ou cliente..." value="<?= htmlspecialchars($busca) ?>">
                        </div>
                        <div class="om-form-group om-mb-0">
                            <label class="om-label">Status</label>
                            <select name="status" class="om-select">
                                <option value="">Todos</option>
                                <?php foreach ($status_map as $key => $val): ?>
                                <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= $val['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="om-form-group om-mb-0">
                            <label class="om-label">Data</label>
                            <input type="date" name="data" class="om-input" value="<?= $data ?>">
                        </div>
                        <button type="submit" class="om-btn om-btn-primary"><i class="lucide-search"></i> Filtrar</button>
                        <a href="pedidos.php" class="om-btn om-btn-outline">Limpar</a>
                        <?php if ($stats['atrasados'] > 0): ?>
                        <a href="?filter=atrasados" class="om-btn om-btn-error"><i class="lucide-alert-triangle"></i> Ver Atrasados</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Lista -->
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
                                <th>Mercado</th>
                                <th>Shopper</th>
                                <th class="om-text-center">Itens</th>
                                <th class="om-text-right">Valor</th>
                                <th>Status</th>
                                <th>Data</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pedidos)): ?>
                            <tr>
                                <td colspan="9" class="om-text-center om-py-8">
                                    <i class="lucide-package om-text-4xl om-text-muted"></i>
                                    <p class="om-mt-2">Nenhum pedido encontrado</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($pedidos as $pedido): ?>
                            <?php $st = $status_map[$pedido['status']] ?? ['label' => $pedido['status'], 'class' => 'neutral']; ?>
                            <tr>
                                <td>
                                    <span class="om-font-mono om-font-semibold">#<?= $pedido['order_id'] ?></span>
                                </td>
                                <td>
                                    <div class="om-font-medium"><?= htmlspecialchars($pedido['customer_name'] ?? 'Cliente') ?></div>
                                </td>
                                <td>
                                    <div class="om-text-sm"><?= htmlspecialchars($pedido['mercado_name'] ?? '-') ?></div>
                                </td>
                                <td>
                                    <?php if ($pedido['shopper_name']): ?>
                                    <div class="om-text-sm"><?= htmlspecialchars($pedido['shopper_name']) ?></div>
                                    <?php else: ?>
                                    <span class="om-text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="om-text-center">
                                    <span class="om-badge om-badge-neutral"><?= $pedido['total_itens'] ?></span>
                                </td>
                                <td class="om-text-right om-font-semibold">
                                    R$ <?= number_format($pedido['total'] ?? 0, 2, ',', '.') ?>
                                </td>
                                <td>
                                    <span class="om-badge om-badge-<?= $st['class'] ?>"><?= $st['label'] ?></span>
                                </td>
                                <td>
                                    <div class="om-text-sm"><?= date('d/m/Y', strtotime($pedido['created_at'])) ?></div>
                                    <div class="om-text-xs om-text-muted"><?= date('H:i', strtotime($pedido['created_at'])) ?></div>
                                </td>
                                <td>
                                    <button class="om-btn om-btn-sm om-btn-ghost" onclick="verPedido(<?= $pedido['order_id'] ?>)" title="Ver Detalhes">
                                        <i class="lucide-eye"></i>
                                    </button>
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
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])) ?>" class="om-pagination-btn"><i class="lucide-chevron-left"></i></a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $pagina - 2); $i <= min($total_paginas, $pagina + 2); $i++): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>" class="om-pagination-btn <?= $i === $pagina ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <?php if ($pagina < $total_paginas): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])) ?>" class="om-pagination-btn"><i class="lucide-chevron-right"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <style>
    .om-card-highlight-error { border-left: 4px solid var(--om-error); }
    </style>
</body>
</html>
