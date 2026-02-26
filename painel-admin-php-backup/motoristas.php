<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL ADMIN - Gestão de Motoristas (Boraum)
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

$where = "WHERE 1=1";
$params = [];

if ($busca) {
    $where .= " AND (m.name LIKE ? OR m.email LIKE ? OR m.cpf LIKE ? OR m.cnh LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

if ($status !== '') {
    $where .= " AND m.status = ?";
    $params[] = $status;
}

$stmt = $db->prepare("SELECT COUNT(*) FROM om_motoristas m $where");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$total_paginas = ceil($total / $por_pagina);

$stmt = $db->prepare("
    SELECT m.*,
           (SELECT COUNT(*) FROM om_entregas WHERE motorista_id = m.motorista_id AND status = 'finalizado') as entregas_finalizadas,
           (SELECT COALESCE(SUM(valor), 0) FROM om_motorista_wallet WHERE motorista_id = m.motorista_id AND tipo = 'credito') as total_ganho,
           (SELECT AVG(rating) FROM om_motorista_ratings WHERE motorista_id = m.motorista_id) as rating
    FROM om_motoristas m
    $where
    ORDER BY m.created_at DESC
    LIMIT $por_pagina OFFSET $offset
");
$stmt->execute($params);
$motoristas = $stmt->fetchAll();

$stmt = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as ativos,
        SUM(CASE WHEN online = 1 THEN 1 ELSE 0 END) as online
    FROM om_motoristas
");
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motoristas - Admin OneMundo</title>
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
        .om-rating { display: flex; align-items: center; gap: 2px; color: var(--om-warning); }
        .om-online-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 4px; }
        .om-online-dot.online { background: var(--om-success); }
        .om-online-dot.offline { background: var(--om-gray-400); }
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
            <a href="motoristas.php" class="om-sidebar-link active"><i class="lucide-truck"></i><span>Motoristas</span></a>
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
            <h1 class="om-topbar-title">Motoristas (Boraum)</h1>
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
                    <div class="om-stat-icon om-bg-primary-light"><i class="lucide-truck"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['total'] ?? 0 ?></span>
                        <span class="om-stat-label">Total de Motoristas</span>
                    </div>
                </div>
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-success-light"><i class="lucide-user-check"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['ativos'] ?? 0 ?></span>
                        <span class="om-stat-label">Ativos</span>
                    </div>
                </div>
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-info-light"><i class="lucide-wifi"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['online'] ?? 0 ?></span>
                        <span class="om-stat-label">Online Agora</span>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="om-card om-mb-6">
                <div class="om-card-body">
                    <form method="GET" class="om-flex om-flex-wrap om-gap-4 om-items-end">
                        <div class="om-form-group om-mb-0 om-flex-1">
                            <label class="om-label">Buscar</label>
                            <input type="text" name="busca" class="om-input" placeholder="Nome, email, CPF ou CNH..." value="<?= htmlspecialchars($busca) ?>">
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
                    <h3 class="om-card-title">Lista de Motoristas</h3>
                    <span class="om-badge om-badge-neutral"><?= $total ?> motoristas</span>
                </div>

                <div class="om-table-responsive">
                    <table class="om-table">
                        <thead>
                            <tr>
                                <th>Motorista</th>
                                <th>Contato</th>
                                <th>Veículo</th>
                                <th class="om-text-center">Entregas</th>
                                <th class="om-text-center">Rating</th>
                                <th class="om-text-right">Ganhos</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($motoristas)): ?>
                            <tr>
                                <td colspan="8" class="om-text-center om-py-8">
                                    <i class="lucide-truck om-text-4xl om-text-muted"></i>
                                    <p class="om-mt-2">Nenhum motorista encontrado</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($motoristas as $mot): ?>
                            <tr>
                                <td>
                                    <div class="om-flex om-items-center om-gap-3">
                                        <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($mot['name'], 0, 2)) ?></div>
                                        <div>
                                            <div class="om-font-medium">
                                                <span class="om-online-dot <?= $mot['online'] ? 'online' : 'offline' ?>"></span>
                                                <?= htmlspecialchars($mot['name']) ?>
                                            </div>
                                            <div class="om-text-xs om-text-muted">CNH: <?= htmlspecialchars($mot['cnh'] ?? '-') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="om-text-sm"><?= htmlspecialchars($mot['email']) ?></div>
                                    <div class="om-text-xs om-text-muted"><?= htmlspecialchars($mot['phone'] ?? '-') ?></div>
                                </td>
                                <td>
                                    <div class="om-text-sm"><?= htmlspecialchars($mot['veiculo_modelo'] ?? '-') ?></div>
                                    <div class="om-text-xs om-text-muted"><?= htmlspecialchars($mot['veiculo_placa'] ?? '') ?></div>
                                </td>
                                <td class="om-text-center">
                                    <span class="om-badge om-badge-neutral"><?= $mot['entregas_finalizadas'] ?? 0 ?></span>
                                </td>
                                <td class="om-text-center">
                                    <?php if ($mot['rating']): ?>
                                    <div class="om-rating">
                                        <i class="lucide-star"></i>
                                        <span><?= number_format($mot['rating'], 1) ?></span>
                                    </div>
                                    <?php else: ?>
                                    <span class="om-text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="om-text-right om-font-semibold">
                                    R$ <?= number_format($mot['total_ganho'] ?? 0, 2, ',', '.') ?>
                                </td>
                                <td>
                                    <span class="om-badge om-badge-<?= $mot['status'] ? 'success' : 'error' ?>">
                                        <?= $mot['status'] ? 'Ativo' : 'Inativo' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="om-btn-group">
                                        <button class="om-btn om-btn-sm om-btn-ghost" onclick="verMotorista(<?= $mot['motorista_id'] ?>)" title="Detalhes">
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
