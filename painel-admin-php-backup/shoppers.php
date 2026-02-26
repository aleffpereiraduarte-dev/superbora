<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL ADMIN - Gestão de Shoppers
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

// Processar ações
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $shopper_id = intval($_POST['shopper_id'] ?? 0);

    if ($action === 'toggle_status' && $shopper_id) {
        $stmt = $db->prepare("UPDATE om_shoppers SET status = IF(status = 1, 0, 1) WHERE shopper_id = ?");
        $stmt->execute([$shopper_id]);
        $message = 'Status atualizado';
    }

    if ($action === 'bloquear' && $shopper_id) {
        $stmt = $db->prepare("UPDATE om_shoppers SET status = 0, blocked_at = NOW(), blocked_reason = ? WHERE shopper_id = ?");
        $stmt->execute([$_POST['motivo'] ?? 'Bloqueado pelo suporte', $shopper_id]);
        $message = 'Shopper bloqueado';
    }
}

// Filtros
$busca = $_GET['busca'] ?? '';
$status = $_GET['status'] ?? '';
$pagina = max(1, intval($_GET['pagina'] ?? 1));
$por_pagina = 20;
$offset = ($pagina - 1) * $por_pagina;

$where = "WHERE 1=1";
$params = [];

if ($busca) {
    $where .= " AND (s.name LIKE ? OR s.email LIKE ? OR s.cpf LIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

if ($status !== '') {
    $where .= " AND s.status = ?";
    $params[] = $status;
}

$stmt = $db->prepare("SELECT COUNT(*) FROM om_shoppers s $where");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$total_paginas = ceil($total / $por_pagina);

$stmt = $db->prepare("
    SELECT s.*,
           (SELECT COUNT(*) FROM om_orders WHERE shopper_id = s.shopper_id AND status = 'finalizado') as entregas_finalizadas,
           (SELECT COALESCE(SUM(valor), 0) FROM om_shopper_wallet WHERE shopper_id = s.shopper_id AND tipo = 'credito') as total_ganho,
           (SELECT AVG(rating) FROM om_shopper_ratings WHERE shopper_id = s.shopper_id) as rating
    FROM om_shoppers s
    $where
    ORDER BY s.created_at DESC
    LIMIT $por_pagina OFFSET $offset
");
$stmt->execute($params);
$shoppers = $stmt->fetchAll();

$stmt = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as ativos,
        SUM(CASE WHEN status = 0 AND approved_at IS NULL THEN 1 ELSE 0 END) as pendentes
    FROM om_shoppers
");
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shoppers - Admin OneMundo</title>
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
        .om-rating {
            display: flex;
            align-items: center;
            gap: 2px;
            color: var(--om-warning);
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
            <a href="shoppers.php" class="om-sidebar-link active"><i class="lucide-shopping-cart"></i><span>Shoppers</span></a>
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
            <h1 class="om-topbar-title">Shoppers</h1>
            <div class="om-topbar-actions">
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($admin_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($admin_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <div class="om-page-content">
            <?php if ($message): ?>
            <div class="om-alert om-alert-success om-mb-4">
                <div class="om-alert-content"><div class="om-alert-message"><?= htmlspecialchars($message) ?></div></div>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="om-stats-grid om-stats-grid-3 om-mb-6">
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-primary-light"><i class="lucide-shopping-cart"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['total'] ?></span>
                        <span class="om-stat-label">Total de Shoppers</span>
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
                    <div class="om-stat-icon om-bg-warning-light"><i class="lucide-clock"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['pendentes'] ?></span>
                        <span class="om-stat-label">Aguardando Aprovação</span>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="om-card om-mb-6">
                <div class="om-card-body">
                    <form method="GET" class="om-flex om-flex-wrap om-gap-4 om-items-end">
                        <div class="om-form-group om-mb-0 om-flex-1">
                            <label class="om-label">Buscar</label>
                            <input type="text" name="busca" class="om-input" placeholder="Nome, email ou CPF..." value="<?= htmlspecialchars($busca) ?>">
                        </div>
                        <div class="om-form-group om-mb-0">
                            <label class="om-label">Status</label>
                            <select name="status" class="om-select">
                                <option value="">Todos</option>
                                <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Ativo</option>
                                <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inativo/Pendente</option>
                            </select>
                        </div>
                        <button type="submit" class="om-btn om-btn-primary"><i class="lucide-search"></i> Filtrar</button>
                    </form>
                </div>
            </div>

            <!-- Lista -->
            <div class="om-card">
                <div class="om-card-header">
                    <h3 class="om-card-title">Lista de Shoppers</h3>
                    <span class="om-badge om-badge-neutral"><?= $total ?> shoppers</span>
                </div>

                <div class="om-table-responsive">
                    <table class="om-table">
                        <thead>
                            <tr>
                                <th>Shopper</th>
                                <th>Contato</th>
                                <th class="om-text-center">Entregas</th>
                                <th class="om-text-center">Rating</th>
                                <th class="om-text-right">Ganhos</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($shoppers)): ?>
                            <tr>
                                <td colspan="7" class="om-text-center om-py-8">
                                    <i class="lucide-shopping-cart om-text-4xl om-text-muted"></i>
                                    <p class="om-mt-2">Nenhum shopper encontrado</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($shoppers as $shopper): ?>
                            <tr>
                                <td>
                                    <div class="om-flex om-items-center om-gap-3">
                                        <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($shopper['name'], 0, 2)) ?></div>
                                        <div>
                                            <div class="om-font-medium"><?= htmlspecialchars($shopper['name']) ?></div>
                                            <div class="om-text-xs om-text-muted">CPF: <?= htmlspecialchars($shopper['cpf'] ?? '-') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="om-text-sm"><?= htmlspecialchars($shopper['email']) ?></div>
                                    <div class="om-text-xs om-text-muted"><?= htmlspecialchars($shopper['phone'] ?? '-') ?></div>
                                </td>
                                <td class="om-text-center">
                                    <span class="om-badge om-badge-neutral"><?= $shopper['entregas_finalizadas'] ?></span>
                                </td>
                                <td class="om-text-center">
                                    <?php if ($shopper['rating']): ?>
                                    <div class="om-rating">
                                        <i class="lucide-star"></i>
                                        <span><?= number_format($shopper['rating'], 1) ?></span>
                                    </div>
                                    <?php else: ?>
                                    <span class="om-text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="om-text-right om-font-semibold">
                                    R$ <?= number_format($shopper['total_ganho'], 2, ',', '.') ?>
                                </td>
                                <td>
                                    <?php if ($shopper['status'] == 1): ?>
                                    <span class="om-badge om-badge-success">Ativo</span>
                                    <?php elseif ($shopper['approved_at']): ?>
                                    <span class="om-badge om-badge-error">Bloqueado</span>
                                    <?php else: ?>
                                    <span class="om-badge om-badge-warning">Pendente RH</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="om-btn-group">
                                        <a href="pedidos.php?shopper=<?= $shopper['shopper_id'] ?>" class="om-btn om-btn-sm om-btn-ghost" title="Ver Entregas">
                                            <i class="lucide-package"></i>
                                        </a>
                                        <button class="om-btn om-btn-sm om-btn-ghost" onclick="verShopper(<?= $shopper['shopper_id'] ?>)" title="Detalhes">
                                            <i class="lucide-eye"></i>
                                        </button>
                                        <?php if ($shopper['status'] == 1): ?>
                                        <button class="om-btn om-btn-sm om-btn-ghost om-text-error" onclick="bloquearShopper(<?= $shopper['shopper_id'] ?>, '<?= htmlspecialchars(addslashes($shopper['name'])) ?>')" title="Bloquear">
                                            <i class="lucide-ban"></i>
                                        </button>
                                        <?php endif; ?>
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

    <!-- Modal Bloquear -->
    <div id="modalBloquear" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModal()"></div>
        <div class="om-modal-content om-modal-sm">
            <div class="om-modal-header">
                <h3 class="om-modal-title">Bloquear Shopper</h3>
                <button class="om-modal-close" onclick="fecharModal()"><i class="lucide-x"></i></button>
            </div>
            <form method="POST">
                <div class="om-modal-body">
                    <input type="hidden" name="action" value="bloquear">
                    <input type="hidden" name="shopper_id" id="bloquearShopperId">
                    <p class="om-text-center om-mb-4">
                        <i class="lucide-alert-triangle om-text-4xl om-text-warning"></i>
                    </p>
                    <p class="om-text-center om-mb-4">
                        Bloquear <strong id="bloquearShopperNome"></strong>?
                    </p>
                    <div class="om-form-group">
                        <label class="om-label">Motivo</label>
                        <textarea name="motivo" class="om-input" rows="2" required placeholder="Informe o motivo do bloqueio"></textarea>
                    </div>
                </div>
                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-error">Bloquear</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function bloquearShopper(id, nome) {
        document.getElementById('bloquearShopperId').value = id;
        document.getElementById('bloquearShopperNome').textContent = nome;
        document.getElementById('modalBloquear').classList.add('open');
    }
    function fecharModal() {
        document.getElementById('modalBloquear').classList.remove('open');
    }
    </script>
</body>
</html>
