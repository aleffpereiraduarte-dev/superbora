<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL ADMIN - Dashboard Principal
 * Visão geral do sistema e acesso rápido ao suporte
 * ══════════════════════════════════════════════════════════════════════════════
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once dirname(__DIR__, 2) . '/database.php';
$db = getDB();

$admin_id = $_SESSION['admin_id'];
$admin_nome = $_SESSION['admin_nome'];
$admin_role = $_SESSION['admin_role'] ?? 'suporte';

// Estatísticas gerais
$stmt = $db->query("
    SELECT
        (SELECT COUNT(*) FROM om_orders WHERE DATE(created_at) = CURDATE()) as pedidos_hoje,
        (SELECT COUNT(*) FROM om_orders WHERE status = 'pendente') as pedidos_pendentes,
        (SELECT COUNT(*) FROM om_orders WHERE status IN ('em_coleta', 'em_entrega')) as pedidos_andamento,
        (SELECT COUNT(*) FROM om_shoppers WHERE status = 1) as shoppers_ativos,
        (SELECT COUNT(*) FROM om_market_partners WHERE status = 1) as mercados_ativos,
        (SELECT COUNT(*) FROM om_tickets WHERE status = 'aberto') as tickets_abertos,
        (SELECT COALESCE(SUM(total), 0) FROM om_orders WHERE status = 'finalizado' AND DATE(created_at) = CURDATE()) as faturamento_hoje
");
$stats = $stmt->fetch();

// Últimos tickets de suporte
$stmt = $db->query("
    SELECT t.*,
           CASE t.user_type
               WHEN 'cliente' THEN (SELECT name FROM om_customers WHERE customer_id = t.user_id)
               WHEN 'shopper' THEN (SELECT name FROM om_shoppers WHERE shopper_id = t.user_id)
               WHEN 'mercado' THEN (SELECT name FROM om_market_partners WHERE partner_id = t.user_id)
           END as user_name
    FROM om_tickets t
    WHERE t.status = 'aberto'
    ORDER BY t.created_at DESC
    LIMIT 5
");
$tickets = $stmt->fetchAll();

// Pedidos problemáticos (muito tempo no mesmo status)
$stmt = $db->query("
    SELECT o.*,
           p.name as mercado_name,
           TIMESTAMPDIFF(MINUTE, o.updated_at, NOW()) as minutos_parado
    FROM om_orders o
    LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
    WHERE o.status NOT IN ('finalizado', 'cancelado')
    AND o.updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ORDER BY o.updated_at ASC
    LIMIT 5
");
$pedidos_atrasados = $stmt->fetchAll();

// Atividade recente
$stmt = $db->query("
    SELECT * FROM om_audit_log
    ORDER BY created_at DESC
    LIMIT 10
");
$atividades = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Admin OneMundo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
    <style>
        .om-sidebar { background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%); }
        .om-sidebar-link:hover, .om-sidebar-link.active { background: rgba(255,255,255,0.1); }
    </style>
</head>
<body class="om-app-layout">
    <!-- Sidebar -->
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <img src="/assets/img/logo-onemundo-white.png" alt="OneMundo" class="om-sidebar-logo"
                 onerror="this.outerHTML='<span class=\'om-sidebar-logo-text\'>OneMundo</span>'">
            <span class="om-badge om-badge-sm" style="background: rgba(255,255,255,0.2); color: white;">ADMIN</span>
        </div>

        <nav class="om-sidebar-nav">
            <a href="index.php" class="om-sidebar-link active">
                <i class="lucide-layout-dashboard"></i>
                <span>Dashboard</span>
            </a>

            <div class="om-sidebar-section">Suporte</div>
            <a href="tickets.php" class="om-sidebar-link">
                <i class="lucide-headphones"></i>
                <span>Tickets</span>
                <?php if ($stats['tickets_abertos'] > 0): ?>
                <span class="om-badge om-badge-sm om-badge-error"><?= $stats['tickets_abertos'] ?></span>
                <?php endif; ?>
            </a>
            <a href="clientes.php" class="om-sidebar-link">
                <i class="lucide-users"></i>
                <span>Clientes</span>
            </a>
            <a href="shoppers.php" class="om-sidebar-link">
                <i class="lucide-shopping-cart"></i>
                <span>Shoppers</span>
            </a>
            <a href="motoristas.php" class="om-sidebar-link">
                <i class="lucide-truck"></i>
                <span>Motoristas</span>
            </a>
            <a href="mercados.php" class="om-sidebar-link">
                <i class="lucide-store"></i>
                <span>Mercados</span>
            </a>

            <div class="om-sidebar-section">Operações</div>
            <a href="pedidos.php" class="om-sidebar-link">
                <i class="lucide-package"></i>
                <span>Pedidos</span>
            </a>
            <a href="financeiro.php" class="om-sidebar-link">
                <i class="lucide-wallet"></i>
                <span>Financeiro</span>
            </a>
            <a href="relatorios.php" class="om-sidebar-link">
                <i class="lucide-bar-chart-3"></i>
                <span>Relatórios</span>
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

            <h1 class="om-topbar-title">Dashboard</h1>

            <div class="om-topbar-actions">
                <button class="om-btn om-btn-ghost om-btn-icon" title="Notificações">
                    <i class="lucide-bell"></i>
                </button>
                <div class="om-user-menu">
                    <div>
                        <span class="om-user-name"><?= htmlspecialchars($admin_nome) ?></span>
                        <span class="om-user-role"><?= ucfirst($admin_role) ?></span>
                    </div>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($admin_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="om-page-content">
            <!-- Estatísticas -->
            <div class="om-stats-grid om-mb-6">
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-primary-light">
                        <i class="lucide-shopping-bag"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['pedidos_hoje'] ?></span>
                        <span class="om-stat-label">Pedidos Hoje</span>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-warning-light">
                        <i class="lucide-clock"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['pedidos_pendentes'] ?></span>
                        <span class="om-stat-label">Pendentes</span>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-info-light">
                        <i class="lucide-bike"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['pedidos_andamento'] ?></span>
                        <span class="om-stat-label">Em Andamento</span>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-success-light">
                        <i class="lucide-dollar-sign"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value">R$ <?= number_format($stats['faturamento_hoje'], 2, ',', '.') ?></span>
                        <span class="om-stat-label">Faturamento Hoje</span>
                    </div>
                </div>
            </div>

            <!-- Segunda linha de cards -->
            <div class="om-grid om-grid-cols-1 md:om-grid-cols-3 om-gap-6 om-mb-6">
                <div class="om-card om-card-sm">
                    <div class="om-card-body om-flex om-items-center om-gap-4">
                        <div class="om-stat-icon om-bg-primary-light">
                            <i class="lucide-shopping-cart"></i>
                        </div>
                        <div>
                            <span class="om-text-2xl om-font-bold"><?= $stats['shoppers_ativos'] ?></span>
                            <span class="om-text-muted om-block">Shoppers Ativos</span>
                        </div>
                    </div>
                </div>

                <div class="om-card om-card-sm">
                    <div class="om-card-body om-flex om-items-center om-gap-4">
                        <div class="om-stat-icon om-bg-success-light">
                            <i class="lucide-store"></i>
                        </div>
                        <div>
                            <span class="om-text-2xl om-font-bold"><?= $stats['mercados_ativos'] ?></span>
                            <span class="om-text-muted om-block">Mercados Ativos</span>
                        </div>
                    </div>
                </div>

                <div class="om-card om-card-sm <?= $stats['tickets_abertos'] > 0 ? 'om-card-highlight-error' : '' ?>">
                    <div class="om-card-body om-flex om-items-center om-gap-4">
                        <div class="om-stat-icon <?= $stats['tickets_abertos'] > 0 ? 'om-bg-error-light' : 'om-bg-gray-100' ?>">
                            <i class="lucide-headphones"></i>
                        </div>
                        <div>
                            <span class="om-text-2xl om-font-bold"><?= $stats['tickets_abertos'] ?></span>
                            <span class="om-text-muted om-block">Tickets Abertos</span>
                        </div>
                        <?php if ($stats['tickets_abertos'] > 0): ?>
                        <a href="tickets.php" class="om-btn om-btn-sm om-btn-error om-ml-auto">Ver</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="om-grid om-grid-cols-1 lg:om-grid-cols-2 om-gap-6">
                <!-- Tickets Abertos -->
                <div class="om-card">
                    <div class="om-card-header">
                        <h3 class="om-card-title">
                            <i class="lucide-headphones om-text-muted"></i>
                            Tickets Recentes
                        </h3>
                        <a href="tickets.php" class="om-btn om-btn-sm om-btn-ghost">Ver todos</a>
                    </div>

                    <div class="om-card-body om-p-0">
                        <?php if (empty($tickets)): ?>
                        <div class="om-empty-state om-py-8">
                            <i class="lucide-check-circle om-text-4xl om-text-success"></i>
                            <p class="om-mt-2">Nenhum ticket aberto</p>
                        </div>
                        <?php else: ?>
                        <div class="om-list">
                            <?php foreach ($tickets as $ticket): ?>
                            <a href="tickets.php?id=<?= $ticket['ticket_id'] ?>" class="om-list-item">
                                <div class="om-list-item-icon">
                                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($ticket['user_name'] ?? 'U', 0, 1)) ?></div>
                                </div>
                                <div class="om-list-item-content">
                                    <div class="om-list-item-title"><?= htmlspecialchars($ticket['subject']) ?></div>
                                    <div class="om-list-item-meta">
                                        <span class="om-badge om-badge-xs om-badge-<?= $ticket['user_type'] === 'cliente' ? 'info' : ($ticket['user_type'] === 'shopper' ? 'success' : 'warning') ?>">
                                            <?= ucfirst($ticket['user_type']) ?>
                                        </span>
                                        <?= htmlspecialchars($ticket['user_name'] ?? 'Usuário') ?>
                                    </div>
                                </div>
                                <div class="om-list-item-action">
                                    <span class="om-text-xs om-text-muted"><?= date('H:i', strtotime($ticket['created_at'])) ?></span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pedidos Atrasados -->
                <div class="om-card">
                    <div class="om-card-header">
                        <h3 class="om-card-title">
                            <i class="lucide-alert-triangle om-text-warning"></i>
                            Pedidos que Precisam de Atenção
                        </h3>
                        <a href="pedidos.php?filter=atrasados" class="om-btn om-btn-sm om-btn-ghost">Ver todos</a>
                    </div>

                    <div class="om-card-body om-p-0">
                        <?php if (empty($pedidos_atrasados)): ?>
                        <div class="om-empty-state om-py-8">
                            <i class="lucide-check-circle om-text-4xl om-text-success"></i>
                            <p class="om-mt-2">Todos os pedidos estão fluindo normalmente</p>
                        </div>
                        <?php else: ?>
                        <div class="om-list">
                            <?php foreach ($pedidos_atrasados as $pedido): ?>
                            <a href="pedidos.php?id=<?= $pedido['order_id'] ?>" class="om-list-item">
                                <div class="om-list-item-content">
                                    <div class="om-list-item-title">
                                        Pedido #<?= $pedido['order_id'] ?>
                                        <span class="om-badge om-badge-xs om-badge-warning"><?= $pedido['status'] ?></span>
                                    </div>
                                    <div class="om-list-item-meta">
                                        <?= htmlspecialchars($pedido['mercado_name'] ?? 'Mercado') ?>
                                    </div>
                                </div>
                                <div class="om-list-item-action">
                                    <span class="om-badge om-badge-error">
                                        <?= $pedido['minutos_parado'] ?> min parado
                                    </span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Atividade Recente -->
            <div class="om-card om-mt-6">
                <div class="om-card-header">
                    <h3 class="om-card-title">
                        <i class="lucide-activity om-text-muted"></i>
                        Atividade Recente
                    </h3>
                </div>

                <div class="om-card-body">
                    <?php if (empty($atividades)): ?>
                    <p class="om-text-muted om-text-center">Nenhuma atividade registrada</p>
                    <?php else: ?>
                    <div class="om-timeline">
                        <?php foreach ($atividades as $ativ): ?>
                        <div class="om-timeline-item">
                            <div class="om-timeline-dot"></div>
                            <div class="om-timeline-content">
                                <p class="om-timeline-title"><?= htmlspecialchars($ativ['action']) ?></p>
                                <p class="om-timeline-meta">
                                    <?= $ativ['actor_type'] ?? 'sistema' ?> #<?= $ativ['actor_id'] ?? 0 ?>
                                    <span class="om-mx-1">&bull;</span>
                                    <?= date('d/m H:i', strtotime($ativ['created_at'])) ?>
                                </p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <style>
    .om-user-role {
        display: block;
        font-size: var(--om-font-xs);
        color: var(--om-text-muted);
    }
    .om-sidebar-section {
        padding: var(--om-space-2) var(--om-space-4);
        font-size: var(--om-font-xs);
        font-weight: var(--om-font-semibold);
        color: rgba(255,255,255,0.5);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-top: var(--om-space-4);
    }
    .om-card-highlight-error {
        border-left: 4px solid var(--om-error);
    }
    .om-list {
        display: flex;
        flex-direction: column;
    }
    .om-list-item {
        display: flex;
        align-items: center;
        gap: var(--om-space-3);
        padding: var(--om-space-3) var(--om-space-4);
        border-bottom: 1px solid var(--om-gray-100);
        text-decoration: none;
        color: inherit;
        transition: background 0.15s;
    }
    .om-list-item:hover {
        background: var(--om-gray-50);
    }
    .om-list-item:last-child {
        border-bottom: none;
    }
    .om-list-item-content {
        flex: 1;
        min-width: 0;
    }
    .om-list-item-title {
        font-weight: var(--om-font-medium);
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
    }
    .om-list-item-meta {
        font-size: var(--om-font-sm);
        color: var(--om-text-muted);
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
    }
    </style>
</body>
</html>
