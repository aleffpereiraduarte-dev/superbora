<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL ADMIN - Gestão de Clientes
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
$pagina = max(1, intval($_GET['pagina'] ?? 1));
$por_pagina = 20;
$offset = ($pagina - 1) * $por_pagina;

// Query
$where = "WHERE 1=1";
$params = [];

if ($busca) {
    $where .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

if ($status !== '') {
    $where .= " AND c.status = ?";
    $params[] = $status;
}

// Total
$stmt = $db->prepare("SELECT COUNT(*) FROM om_customers c $where");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$total_paginas = ceil($total / $por_pagina);

// Buscar clientes
$stmt = $db->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM om_orders WHERE customer_id = c.customer_id) as total_pedidos,
           (SELECT COALESCE(SUM(total), 0) FROM om_orders WHERE customer_id = c.customer_id AND status = 'finalizado') as total_gasto
    FROM om_customers c
    $where
    ORDER BY c.created_at DESC
    LIMIT $por_pagina OFFSET $offset
");
$stmt->execute($params);
$clientes = $stmt->fetchAll();

// Stats
$stmt = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as ativos,
        SUM(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as novos_30d
    FROM om_customers
");
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes - Admin OneMundo</title>
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
            <a href="clientes.php" class="om-sidebar-link active"><i class="lucide-users"></i><span>Clientes</span></a>
            <a href="shoppers.php" class="om-sidebar-link"><i class="lucide-shopping-cart"></i><span>Shoppers</span></a>
            <a href="motoristas.php" class="om-sidebar-link"><i class="lucide-truck"></i><span>Motoristas</span></a>
            <a href="mercados.php" class="om-sidebar-link"><i class="lucide-store"></i><span>Mercados</span></a>
            <div class="om-sidebar-section">Operações</div>
            <a href="pedidos.php" class="om-sidebar-link"><i class="lucide-package"></i><span>Pedidos</span></a>
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
            <h1 class="om-topbar-title">Clientes</h1>
            <div class="om-topbar-actions">
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($admin_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($admin_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <div class="om-page-content">
            <!-- Stats -->
            <div class="om-stats-grid om-stats-grid-3 om-mb-6">
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-primary-light"><i class="lucide-users"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['total'] ?></span>
                        <span class="om-stat-label">Total de Clientes</span>
                    </div>
                </div>
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-success-light"><i class="lucide-user-check"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['ativos'] ?></span>
                        <span class="om-stat-label">Ativos</span>
                    </div>
                </div>
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-info-light"><i class="lucide-user-plus"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['novos_30d'] ?></span>
                        <span class="om-stat-label">Novos (30 dias)</span>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="om-card om-mb-6">
                <div class="om-card-body">
                    <form method="GET" class="om-flex om-flex-wrap om-gap-4 om-items-end">
                        <div class="om-form-group om-mb-0 om-flex-1">
                            <label class="om-label">Buscar</label>
                            <input type="text" name="busca" class="om-input" placeholder="Nome, email ou telefone..." value="<?= htmlspecialchars($busca) ?>">
                        </div>
                        <div class="om-form-group om-mb-0">
                            <label class="om-label">Status</label>
                            <select name="status" class="om-select">
                                <option value="">Todos</option>
                                <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Ativo</option>
                                <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inativo</option>
                            </select>
                        </div>
                        <button type="submit" class="om-btn om-btn-primary"><i class="lucide-search"></i> Filtrar</button>
                    </form>
                </div>
            </div>

            <!-- Lista -->
            <div class="om-card">
                <div class="om-card-header">
                    <h3 class="om-card-title">Lista de Clientes</h3>
                    <span class="om-badge om-badge-neutral"><?= $total ?> clientes</span>
                </div>

                <div class="om-table-responsive">
                    <table class="om-table">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Contato</th>
                                <th class="om-text-center">Pedidos</th>
                                <th class="om-text-right">Total Gasto</th>
                                <th>Cadastro</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($clientes)): ?>
                            <tr>
                                <td colspan="7" class="om-text-center om-py-8">
                                    <i class="lucide-users om-text-4xl om-text-muted"></i>
                                    <p class="om-mt-2">Nenhum cliente encontrado</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($clientes as $cliente): ?>
                            <tr>
                                <td>
                                    <div class="om-flex om-items-center om-gap-3">
                                        <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($cliente['name'], 0, 2)) ?></div>
                                        <div>
                                            <div class="om-font-medium"><?= htmlspecialchars($cliente['name']) ?></div>
                                            <div class="om-text-xs om-text-muted">#<?= $cliente['customer_id'] ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="om-text-sm"><?= htmlspecialchars($cliente['email']) ?></div>
                                    <div class="om-text-xs om-text-muted"><?= htmlspecialchars($cliente['phone'] ?? '-') ?></div>
                                </td>
                                <td class="om-text-center">
                                    <span class="om-badge om-badge-neutral"><?= $cliente['total_pedidos'] ?></span>
                                </td>
                                <td class="om-text-right om-font-semibold">
                                    R$ <?= number_format($cliente['total_gasto'], 2, ',', '.') ?>
                                </td>
                                <td>
                                    <div class="om-text-sm"><?= date('d/m/Y', strtotime($cliente['created_at'])) ?></div>
                                </td>
                                <td>
                                    <span class="om-badge om-badge-<?= $cliente['status'] ? 'success' : 'error' ?>">
                                        <?= $cliente['status'] ? 'Ativo' : 'Inativo' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="om-btn-group">
                                        <a href="pedidos.php?cliente=<?= $cliente['customer_id'] ?>" class="om-btn om-btn-sm om-btn-ghost" title="Ver Pedidos">
                                            <i class="lucide-package"></i>
                                        </a>
                                        <button class="om-btn om-btn-sm om-btn-ghost" onclick="verCliente(<?= $cliente['customer_id'] ?>)" title="Detalhes">
                                            <i class="lucide-eye"></i>
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
</body>
</html>
