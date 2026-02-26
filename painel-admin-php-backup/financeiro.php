<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL ADMIN - Gestão Financeira
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

// Processar ações
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'aprovar_saque') {
        $saque_id = intval($_POST['saque_id'] ?? 0);
        $stmt = $db->prepare("UPDATE om_saques SET status = 'aprovado', processed_at = NOW(), processed_by = ? WHERE saque_id = ? AND status = 'pendente'");
        $stmt->execute([$admin_id, $saque_id]);
        $message = 'Saque aprovado';
    }

    if ($action === 'rejeitar_saque') {
        $saque_id = intval($_POST['saque_id'] ?? 0);
        $motivo = $_POST['motivo'] ?? 'Rejeitado pelo admin';
        $stmt = $db->prepare("UPDATE om_saques SET status = 'rejeitado', processed_at = NOW(), processed_by = ?, reject_reason = ? WHERE saque_id = ? AND status = 'pendente'");
        $stmt->execute([$admin_id, $motivo, $saque_id]);
        $message = 'Saque rejeitado';
    }
}

// Filtros
$tipo = $_GET['tipo'] ?? '';
$status = $_GET['status'] ?? '';

// Query saques
$where = "WHERE 1=1";
$params = [];

if ($tipo) {
    $where .= " AND s.usuario_tipo = ?";
    $params[] = $tipo;
}

if ($status) {
    $where .= " AND s.status = ?";
    $params[] = $status;
}

$stmt = $db->prepare("
    SELECT s.*,
           CASE s.usuario_tipo
               WHEN 'shopper' THEN (SELECT name FROM om_shoppers WHERE shopper_id = s.usuario_id)
               WHEN 'motorista' THEN (SELECT name FROM om_motoristas WHERE motorista_id = s.usuario_id)
           END as usuario_nome
    FROM om_saques s
    $where
    ORDER BY
        CASE s.status WHEN 'pendente' THEN 0 WHEN 'aprovado' THEN 1 ELSE 2 END,
        s.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$saques = $stmt->fetchAll();

// Stats
$stmt = $db->query("
    SELECT
        (SELECT COUNT(*) FROM om_saques WHERE status = 'pendente') as saques_pendentes,
        (SELECT COALESCE(SUM(valor), 0) FROM om_saques WHERE status = 'pendente') as valor_pendente,
        (SELECT COALESCE(SUM(total), 0) FROM om_orders WHERE status = 'finalizado' AND DATE(created_at) = CURDATE()) as faturamento_hoje,
        (SELECT COALESCE(SUM(total), 0) FROM om_orders WHERE status = 'finalizado' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())) as faturamento_mes
");
$stats = $stmt->fetch();

$status_map = [
    'pendente' => ['label' => 'Pendente', 'class' => 'warning'],
    'aprovado' => ['label' => 'Aprovado', 'class' => 'success'],
    'rejeitado' => ['label' => 'Rejeitado', 'class' => 'error'],
    'processando' => ['label' => 'Processando', 'class' => 'info']
];

$tipo_map = [
    'shopper' => ['label' => 'Shopper', 'class' => 'success'],
    'motorista' => ['label' => 'Motorista', 'class' => 'primary'],
    'mercado' => ['label' => 'Mercado', 'class' => 'warning']
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financeiro - Admin OneMundo</title>
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
            <a href="pedidos.php" class="om-sidebar-link"><i class="lucide-package"></i><span>Pedidos</span></a>
            <a href="financeiro.php" class="om-sidebar-link active"><i class="lucide-wallet"></i><span>Financeiro</span></a>
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
            <h1 class="om-topbar-title">Financeiro</h1>
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
            <div class="om-stats-grid om-mb-6">
                <div class="om-stat-card <?= $stats['saques_pendentes'] > 0 ? 'om-card-highlight-warning' : '' ?>">
                    <div class="om-stat-icon om-bg-warning-light"><i class="lucide-clock"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['saques_pendentes'] ?></span>
                        <span class="om-stat-label">Saques Pendentes</span>
                    </div>
                </div>
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-error-light"><i class="lucide-arrow-up-right"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value">R$ <?= number_format($stats['valor_pendente'], 2, ',', '.') ?></span>
                        <span class="om-stat-label">Valor Pendente</span>
                    </div>
                </div>
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-success-light"><i class="lucide-dollar-sign"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value">R$ <?= number_format($stats['faturamento_hoje'], 2, ',', '.') ?></span>
                        <span class="om-stat-label">Faturamento Hoje</span>
                    </div>
                </div>
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-primary-light"><i class="lucide-trending-up"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value">R$ <?= number_format($stats['faturamento_mes'], 2, ',', '.') ?></span>
                        <span class="om-stat-label">Faturamento Mês</span>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="om-tabs om-mb-6">
                <button class="om-tab active" onclick="showTab('saques')">
                    <i class="lucide-wallet"></i> Saques
                    <?php if ($stats['saques_pendentes'] > 0): ?>
                    <span class="om-badge om-badge-warning om-badge-sm"><?= $stats['saques_pendentes'] ?></span>
                    <?php endif; ?>
                </button>
                <button class="om-tab" onclick="showTab('repasses')">
                    <i class="lucide-building"></i> Repasses Mercados
                </button>
            </div>

            <!-- Tab Saques -->
            <div id="tab-saques" class="om-tab-content active">
                <div class="om-card">
                    <div class="om-card-header">
                        <h3 class="om-card-title">Solicitações de Saque</h3>

                        <form method="GET" class="om-flex om-gap-2">
                            <select name="tipo" class="om-select om-select-sm" onchange="this.form.submit()">
                                <option value="">Todos Tipos</option>
                                <option value="shopper" <?= $tipo === 'shopper' ? 'selected' : '' ?>>Shoppers</option>
                                <option value="motorista" <?= $tipo === 'motorista' ? 'selected' : '' ?>>Motoristas</option>
                            </select>
                            <select name="status" class="om-select om-select-sm" onchange="this.form.submit()">
                                <option value="">Todos Status</option>
                                <option value="pendente" <?= $status === 'pendente' ? 'selected' : '' ?>>Pendentes</option>
                                <option value="aprovado" <?= $status === 'aprovado' ? 'selected' : '' ?>>Aprovados</option>
                                <option value="rejeitado" <?= $status === 'rejeitado' ? 'selected' : '' ?>>Rejeitados</option>
                            </select>
                        </form>
                    </div>

                    <div class="om-table-responsive">
                        <table class="om-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Solicitante</th>
                                    <th>Tipo</th>
                                    <th class="om-text-right">Valor</th>
                                    <th>Chave PIX</th>
                                    <th>Status</th>
                                    <th>Data</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($saques)): ?>
                                <tr>
                                    <td colspan="8" class="om-text-center om-py-8">
                                        <i class="lucide-wallet om-text-4xl om-text-muted"></i>
                                        <p class="om-mt-2">Nenhum saque encontrado</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($saques as $saque): ?>
                                <?php $st = $status_map[$saque['status']] ?? ['label' => $saque['status'], 'class' => 'neutral']; ?>
                                <?php $tp = $tipo_map[$saque['usuario_tipo']] ?? ['label' => $saque['usuario_tipo'], 'class' => 'neutral']; ?>
                                <tr>
                                    <td><span class="om-font-mono">#<?= $saque['saque_id'] ?></span></td>
                                    <td>
                                        <div class="om-font-medium"><?= htmlspecialchars($saque['usuario_nome'] ?? 'N/A') ?></div>
                                        <div class="om-text-xs om-text-muted">ID: <?= $saque['usuario_id'] ?></div>
                                    </td>
                                    <td>
                                        <span class="om-badge om-badge-<?= $tp['class'] ?>"><?= $tp['label'] ?></span>
                                    </td>
                                    <td class="om-text-right om-font-semibold">
                                        R$ <?= number_format($saque['valor'], 2, ',', '.') ?>
                                    </td>
                                    <td>
                                        <div class="om-text-sm om-font-mono"><?= htmlspecialchars($saque['pix_key'] ?? '-') ?></div>
                                    </td>
                                    <td>
                                        <span class="om-badge om-badge-<?= $st['class'] ?>"><?= $st['label'] ?></span>
                                    </td>
                                    <td>
                                        <div class="om-text-sm"><?= date('d/m/Y', strtotime($saque['created_at'])) ?></div>
                                        <div class="om-text-xs om-text-muted"><?= date('H:i', strtotime($saque['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($saque['status'] === 'pendente'): ?>
                                        <div class="om-btn-group">
                                            <form method="POST" class="om-inline">
                                                <input type="hidden" name="action" value="aprovar_saque">
                                                <input type="hidden" name="saque_id" value="<?= $saque['saque_id'] ?>">
                                                <button type="submit" class="om-btn om-btn-sm om-btn-success" title="Aprovar">
                                                    <i class="lucide-check"></i>
                                                </button>
                                            </form>
                                            <button class="om-btn om-btn-sm om-btn-error" onclick="rejeitarSaque(<?= $saque['saque_id'] ?>)" title="Rejeitar">
                                                <i class="lucide-x"></i>
                                            </button>
                                        </div>
                                        <?php else: ?>
                                        <span class="om-text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Tab Repasses -->
            <div id="tab-repasses" class="om-tab-content">
                <div class="om-card">
                    <div class="om-card-body om-text-center om-py-12">
                        <i class="lucide-building om-text-4xl om-text-muted"></i>
                        <p class="om-mt-4 om-font-semibold">Repasses para Mercados</p>
                        <p class="om-text-muted">Funcionalidade em desenvolvimento</p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Rejeitar -->
    <div id="modalRejeitar" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModal()"></div>
        <div class="om-modal-content om-modal-sm">
            <div class="om-modal-header">
                <h3 class="om-modal-title">Rejeitar Saque</h3>
                <button class="om-modal-close" onclick="fecharModal()"><i class="lucide-x"></i></button>
            </div>
            <form method="POST">
                <div class="om-modal-body">
                    <input type="hidden" name="action" value="rejeitar_saque">
                    <input type="hidden" name="saque_id" id="rejeitarSaqueId">
                    <div class="om-form-group">
                        <label class="om-label">Motivo da Rejeição</label>
                        <textarea name="motivo" class="om-input" rows="3" required placeholder="Informe o motivo"></textarea>
                    </div>
                </div>
                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-error">Rejeitar</button>
                </div>
            </form>
        </div>
    </div>

    <style>
    .om-tabs { display: flex; gap: var(--om-space-2); border-bottom: 1px solid var(--om-gray-200); padding-bottom: var(--om-space-2); }
    .om-tab { display: flex; align-items: center; gap: var(--om-space-2); padding: var(--om-space-2) var(--om-space-4); border: none; background: none; color: var(--om-text-muted); font-size: var(--om-font-sm); cursor: pointer; border-radius: var(--om-radius-md); transition: all 0.2s; }
    .om-tab:hover { background: var(--om-gray-100); color: var(--om-text-primary); }
    .om-tab.active { background: var(--om-primary-50); color: var(--om-primary); font-weight: var(--om-font-medium); }
    .om-tab-content { display: none; }
    .om-tab-content.active { display: block; }
    .om-card-highlight-warning { border-left: 4px solid var(--om-warning); }
    .om-inline { display: inline; }
    </style>

    <script>
    function showTab(tabId) {
        document.querySelectorAll('.om-tab').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.om-tab-content').forEach(content => content.classList.remove('active'));
        event.target.closest('.om-tab').classList.add('active');
        document.getElementById('tab-' + tabId).classList.add('active');
    }

    function rejeitarSaque(id) {
        document.getElementById('rejeitarSaqueId').value = id;
        document.getElementById('modalRejeitar').classList.add('open');
    }

    function fecharModal() {
        document.getElementById('modalRejeitar').classList.remove('open');
    }
    </script>
</body>
</html>
