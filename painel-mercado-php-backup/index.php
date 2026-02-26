<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Dashboard Principal
 * ══════════════════════════════════════════════════════════════════════════════
 */

session_start();
require_once dirname(__DIR__, 2) . '/database.php';
require_once dirname(__DIR__, 2) . '/includes/classes/OmAuth.php';

// Verificar autenticação
$db = getDB();
OmAuth::getInstance()->setDb($db);

// Se não estiver logado, redirecionar
if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

$mercado_id = $_SESSION['mercado_id'];

// Buscar dados do mercado
$stmt = $db->prepare("SELECT * FROM om_market_partners WHERE partner_id = ?");
$stmt->execute([$mercado_id]);
$mercado = $stmt->fetch();

if (!$mercado) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Check if store is currently paused
$is_paused = false;
$pause_until_ts = null;
if (!empty($mercado['pause_until'])) {
    $pause_until_ts = strtotime($mercado['pause_until']);
    if ($pause_until_ts > time()) {
        $is_paused = true;
    } else {
        // Pause has expired — auto-reopen the store
        $reopenStmt = $db->prepare("UPDATE om_market_partners SET is_open = 1, pause_until = NULL, pause_reason = NULL WHERE partner_id = ?");
        $reopenStmt->execute([$mercado_id]);
        $mercado['is_open'] = 1;
        $mercado['pause_until'] = null;
    }
}

// Buscar estatísticas
$hoje = date('Y-m-d');
$mes_atual = date('Y-m');

// Pedidos hoje
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as entregues,
        SUM(CASE WHEN status IN ('pendente', 'aceito', 'coletando', 'em_entrega') THEN 1 ELSE 0 END) as em_andamento,
        SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) as cancelados
    FROM om_market_orders
    WHERE partner_id = ? AND DATE(date_added) = ?
");
$stmt->execute([$mercado_id, $hoje]);
$pedidos_hoje = $stmt->fetch();

// Faturamento hoje
$stmt = $db->prepare("
    SELECT COALESCE(SUM(total), 0) as faturamento
    FROM om_market_orders
    WHERE partner_id = ? AND DATE(date_added) = ? AND status = 'delivered'
");
$stmt->execute([$mercado_id, $hoje]);
$faturamento_hoje = floatval($stmt->fetchColumn());

// Faturamento do mês (PostgreSQL: TO_CHAR instead of DATE_FORMAT)
$stmt = $db->prepare("
    SELECT COALESCE(SUM(total), 0) as faturamento
    FROM om_market_orders
    WHERE partner_id = ? AND TO_CHAR(date_added, 'YYYY-MM') = ? AND status = 'delivered'
");
$stmt->execute([$mercado_id, $mes_atual]);
$faturamento_mes = floatval($stmt->fetchColumn());

// Total de produtos
$stmt = $db->prepare("SELECT COUNT(*) FROM om_market_products WHERE partner_id = ? AND status = 1");
$stmt->execute([$mercado_id]);
$total_produtos = $stmt->fetchColumn();

// ── KPI: Tempo Médio de Preparo (últimos 30 dias) ──
$stmt = $db->prepare("
    SELECT AVG(EXTRACT(EPOCH FROM (ready_at - preparing_started_at)) / 60) as avg_prep_minutes
    FROM om_market_orders
    WHERE partner_id = ?
      AND status IN ('delivered', 'entregue', 'pronto', 'em_entrega', 'coletando')
      AND preparing_started_at IS NOT NULL
      AND ready_at IS NOT NULL
      AND date_added >= CURRENT_DATE - INTERVAL '30 days'
");
$stmt->execute([$mercado_id]);
$avg_prep_time = $stmt->fetchColumn();
$avg_prep_display = $avg_prep_time !== null && $avg_prep_time !== false
    ? round((float)$avg_prep_time) . ' min'
    : '—';

// ── KPI: Taxa de Cancelamento (mês atual) ──
$stmt = $db->prepare("
    SELECT
        COUNT(*) as total_mes,
        SUM(CASE WHEN status = 'cancelado' THEN 1 ELSE 0 END) as cancelados_mes
    FROM om_market_orders
    WHERE partner_id = ? AND TO_CHAR(date_added, 'YYYY-MM') = ?
");
$stmt->execute([$mercado_id, $mes_atual]);
$cancel_data = $stmt->fetch();
$cancel_rate = ($cancel_data['total_mes'] > 0)
    ? round(($cancel_data['cancelados_mes'] / $cancel_data['total_mes']) * 100, 1)
    : 0;

// ── KPI: Avaliação Média (om_market_reviews) ──
$avg_rating = null;
$rating_count = 0;
try {
    $stmt = $db->prepare("
        SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
        FROM om_market_reviews
        WHERE partner_id = ?
    ");
    $stmt->execute([$mercado_id]);
    $rating_data = $stmt->fetch();
    if ($rating_data && $rating_data['total_reviews'] > 0) {
        $avg_rating = round((float)$rating_data['avg_rating'], 1);
        $rating_count = (int)$rating_data['total_reviews'];
    }
} catch (PDOException $e) {
    // Table may not exist yet — show placeholder
    $avg_rating = null;
}

// ── KPI: Saldo Disponível (om_mercado_saldo) ──
$saldo_disponivel = 0;
$saldo_pendente = 0;
try {
    $stmt = $db->prepare("
        SELECT
            COALESCE(saldo_disponivel, 0) as saldo_disponivel,
            COALESCE(saldo_pendente, 0) as saldo_pendente
        FROM om_mercado_saldo
        WHERE partner_id = ?
    ");
    $stmt->execute([$mercado_id]);
    $saldo_row = $stmt->fetch();
    if ($saldo_row) {
        $saldo_disponivel = (float)$saldo_row['saldo_disponivel'];
        $saldo_pendente = (float)$saldo_row['saldo_pendente'];
    }
} catch (PDOException $e) {
    // Table issue — leave at 0
}

// Últimos pedidos
$stmt = $db->prepare("
    SELECT o.*,
        (SELECT COUNT(*) FROM om_market_order_items WHERE order_id = o.order_id) as itens_count
    FROM om_market_orders o
    WHERE o.partner_id = ?
    ORDER BY o.date_added DESC
    LIMIT 10
");
$stmt->execute([$mercado_id]);
$ultimos_pedidos = $stmt->fetchAll();

// Produtos com baixo estoque (limite de 10 unidades)
$stmt = $db->prepare("
    SELECT * FROM om_market_products
    WHERE partner_id = ? AND stock <= 10 AND status = 1
    ORDER BY stock ASC
    LIMIT 5
");
$stmt->execute([$mercado_id]);
$baixo_estoque = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= htmlspecialchars($mercado['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <script src="https://js.pusher.com/8.0/pusher.min.js"></script>
    <style>
        .icon { font-family: 'lucide'; font-size: 20px; }
        .om-stats-grid-extended {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .om-stat-card .om-stat-subtitle {
            font-size: 12px;
            color: var(--color-text-muted, #888);
            margin-top: 2px;
        }
        .om-stat-icon.danger { background: var(--color-danger-bg, #fef2f2); color: var(--color-danger, #ef4444); }
        .om-stat-icon.purple { background: #f3e8ff; color: #9333ea; }
        .om-star { color: #f59e0b; }
        /* Pusher connection status indicator */
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
            border-left: 4px solid var(--color-primary, #2563eb);
            border-radius: 8px;
            padding: 16px 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.12);
            max-width: 380px;
            transform: translateX(120%);
            transition: transform 0.3s ease;
        }
        .om-toast.show { transform: translateX(0); }
        .om-toast-title { font-weight: 600; font-size: 14px; margin-bottom: 4px; }
        .om-toast-body { font-size: 13px; color: #6b7280; }
    </style>
</head>
<body>
    <!-- Toast container for Pusher notifications -->
    <div id="orderToast" class="om-toast">
        <div class="om-toast-title" id="toastTitle">Novo Pedido!</div>
        <div class="om-toast-body" id="toastBody"></div>
    </div>

    <!-- Sidebar -->
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <div class="om-sidebar-logo">
                <img src="<?= $mercado['logo'] ?: '/assets/img/logo-mercado.png' ?>" alt="Logo" onerror="this.style.display='none'">
                <span class="om-sidebar-logo-text">Painel</span>
            </div>
        </div>

        <nav class="om-sidebar-nav">
            <div class="om-sidebar-section">
                <div class="om-sidebar-section-title">Menu</div>
                <a href="index.php" class="om-sidebar-link active">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7"></rect>
                        <rect x="14" y="3" width="7" height="7"></rect>
                        <rect x="14" y="14" width="7" height="7"></rect>
                        <rect x="3" y="14" width="7" height="7"></rect>
                    </svg>
                    <span class="om-sidebar-link-text">Dashboard</span>
                </a>
                <a href="pedidos.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
                        <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
                    </svg>
                    <span class="om-sidebar-link-text">Pedidos</span>
                    <?php if ($pedidos_hoje['em_andamento'] > 0): ?>
                    <span class="om-sidebar-link-badge"><?= $pedidos_hoje['em_andamento'] ?></span>
                    <?php endif; ?>
                </a>
                <a href="produtos.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                    </svg>
                    <span class="om-sidebar-link-text">Produtos</span>
                </a>
                <a href="cardapio-ia.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path><path d="M12 18v4"></path><path d="M8 22h8"></path></svg>
                    <span class="om-sidebar-link-text">Cardapio IA</span>
                    <span style="background:#8b5cf6;color:#fff;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:700;">NOVO</span>
                </a>
                <a href="categorias.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="8" y1="6" x2="21" y2="6"></line>
                        <line x1="8" y1="12" x2="21" y2="12"></line>
                        <line x1="8" y1="18" x2="21" y2="18"></line>
                        <line x1="3" y1="6" x2="3.01" y2="6"></line>
                        <line x1="3" y1="12" x2="3.01" y2="12"></line>
                        <line x1="3" y1="18" x2="3.01" y2="18"></line>
                    </svg>
                    <span class="om-sidebar-link-text">Categorias</span>
                </a>
            </div>

            <div class="om-sidebar-section">
                <div class="om-sidebar-section-title">Financeiro</div>
                <a href="faturamento.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="1" x2="12" y2="23"></line>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                    <span class="om-sidebar-link-text">Faturamento</span>
                </a>
                <a href="repasses.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                        <line x1="1" y1="10" x2="23" y2="10"></line>
                    </svg>
                    <span class="om-sidebar-link-text">Repasses</span>
                </a>
                <a href="avaliacoes.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                    </svg>
                    <span class="om-sidebar-link-text">Avaliacoes</span>
                </a>
            </div>

            <div class="om-sidebar-section">
                <div class="om-sidebar-section-title">Configurações</div>
                <a href="perfil.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    <span class="om-sidebar-link-text">Perfil da Loja</span>
                </a>
                <a href="horarios.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span class="om-sidebar-link-text">Horários</span>
                </a>
                <a href="equipe.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <span class="om-sidebar-link-text">Equipe</span>
                </a>
            </div>
        </nav>

        <div class="om-sidebar-footer">
            <div class="om-sidebar-user">
                <div class="om-avatar om-avatar-md">
                    <?php if ($mercado['logo']): ?>
                        <img src="<?= $mercado['logo'] ?>" alt="">
                    <?php else: ?>
                        <?= strtoupper(substr($mercado['name'], 0, 2)) ?>
                    <?php endif; ?>
                </div>
                <div class="om-sidebar-user-info">
                    <div class="om-sidebar-user-name"><?= htmlspecialchars($mercado['name']) ?></div>
                    <div class="om-sidebar-user-role">Mercado</div>
                </div>
            </div>
        </div>
    </aside>

    <!-- Overlay mobile -->
    <div class="om-sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Topbar -->
    <header class="om-topbar">
        <button class="om-topbar-toggle" id="sidebarToggle">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
        <h1 class="om-topbar-title">
            Dashboard
            <span class="om-pusher-status" id="pusherStatus">
                <span class="om-pusher-dot" id="pusherDot"></span>
                <span id="pusherLabel">Conectando...</span>
            </span>
        </h1>
        <div class="om-topbar-actions">
            <div class="om-topbar-notifications">
                <button class="om-btn om-btn-ghost om-btn-icon">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                </button>
                <?php if ($pedidos_hoje['em_andamento'] > 0): ?>
                <span class="om-topbar-notifications-badge" id="pendingBadge"><?= $pedidos_hoje['em_andamento'] ?></span>
                <?php else: ?>
                <span class="om-topbar-notifications-badge" id="pendingBadge" style="display:none">0</span>
                <?php endif; ?>
            </div>
            <div class="om-dropdown">
                <button class="om-btn om-btn-ghost om-btn-icon" onclick="this.parentElement.classList.toggle('open')">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                </button>
                <div class="om-dropdown-menu">
                    <a href="perfil.php" class="om-dropdown-item">Configurações</a>
                    <div class="om-dropdown-divider"></div>
                    <a href="logout.php" class="om-dropdown-item danger">Sair</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="om-main">
        <!-- Stats Row 1: Main metrics -->
        <div class="om-stats-grid">
            <div class="om-stat-card">
                <div class="om-stat-header">
                    <div class="om-stat-icon primary">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
                            <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
                        </svg>
                    </div>
                </div>
                <div class="om-stat-value" id="statPedidosHoje"><?= $pedidos_hoje['total'] ?></div>
                <div class="om-stat-label">Pedidos Hoje</div>
            </div>

            <div class="om-stat-card">
                <div class="om-stat-header">
                    <div class="om-stat-icon success">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="1" x2="12" y2="23"></line>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                        </svg>
                    </div>
                </div>
                <div class="om-stat-value">R$ <?= number_format($faturamento_hoje, 2, ',', '.') ?></div>
                <div class="om-stat-label">Faturamento Hoje</div>
            </div>

            <div class="om-stat-card">
                <div class="om-stat-header">
                    <div class="om-stat-icon info">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                            <line x1="1" y1="10" x2="23" y2="10"></line>
                        </svg>
                    </div>
                </div>
                <div class="om-stat-value">R$ <?= number_format($faturamento_mes, 2, ',', '.') ?></div>
                <div class="om-stat-label">Faturamento do Mês</div>
            </div>

            <div class="om-stat-card">
                <div class="om-stat-header">
                    <div class="om-stat-icon warning">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                        </svg>
                    </div>
                </div>
                <div class="om-stat-value"><?= $total_produtos ?></div>
                <div class="om-stat-label">Produtos Ativos</div>
            </div>
        </div>

        <!-- Stats Row 2: New KPI cards -->
        <div class="om-stats-grid-extended">
            <!-- Tempo Médio de Preparo -->
            <div class="om-stat-card">
                <div class="om-stat-header">
                    <div class="om-stat-icon info">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                </div>
                <div class="om-stat-value"><?= $avg_prep_display ?></div>
                <div class="om-stat-label">Tempo Médio de Preparo</div>
                <div class="om-stat-subtitle">Últimos 30 dias</div>
            </div>

            <!-- Taxa de Cancelamento -->
            <div class="om-stat-card">
                <div class="om-stat-header">
                    <div class="om-stat-icon danger">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="15" y1="9" x2="9" y2="15"></line>
                            <line x1="9" y1="9" x2="15" y2="15"></line>
                        </svg>
                    </div>
                </div>
                <div class="om-stat-value"><?= $cancel_rate ?>%</div>
                <div class="om-stat-label">Taxa de Cancelamento</div>
                <div class="om-stat-subtitle"><?= (int)$cancel_data['cancelados_mes'] ?> de <?= (int)$cancel_data['total_mes'] ?> pedidos este mês</div>
            </div>

            <!-- Avaliação Média -->
            <div class="om-stat-card">
                <div class="om-stat-header">
                    <div class="om-stat-icon purple">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                        </svg>
                    </div>
                </div>
                <div class="om-stat-value">
                    <?php if ($avg_rating !== null): ?>
                        <span class="om-star">&#9733;</span> <?= $avg_rating ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
                <div class="om-stat-label">Avaliação Média</div>
                <div class="om-stat-subtitle">
                    <?php if ($avg_rating !== null): ?>
                        <?= $rating_count ?> avaliações
                    <?php else: ?>
                        Em breve
                    <?php endif; ?>
                </div>
            </div>

            <!-- Saldo Disponível -->
            <div class="om-stat-card">
                <div class="om-stat-header">
                    <div class="om-stat-icon success">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                            <line x1="2" y1="10" x2="22" y2="10"></line>
                        </svg>
                    </div>
                </div>
                <div class="om-stat-value">R$ <?= number_format($saldo_disponivel, 2, ',', '.') ?></div>
                <div class="om-stat-label">Saldo Disponível</div>
                <?php if ($saldo_pendente > 0): ?>
                <div class="om-stat-subtitle">+ R$ <?= number_format($saldo_pendente, 2, ',', '.') ?> pendente</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="om-row">
            <!-- Últimos Pedidos -->
            <div class="om-col-8">
                <div class="om-data-table">
                    <div class="om-data-table-header">
                        <h3 class="om-data-table-title">Últimos Pedidos</h3>
                        <a href="pedidos.php" class="om-btn om-btn-outline om-btn-sm">Ver Todos</a>
                    </div>
                    <div class="om-data-table-content">
                        <table class="om-table">
                            <thead>
                                <tr>
                                    <th>Pedido</th>
                                    <th>Cliente</th>
                                    <th>Itens</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Data</th>
                                </tr>
                            </thead>
                            <tbody id="ordersTableBody">
                                <?php if (empty($ultimos_pedidos)): ?>
                                <tr>
                                    <td colspan="6" class="om-text-center om-text-muted om-p-6">
                                        Nenhum pedido ainda
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($ultimos_pedidos as $pedido): ?>
                                <tr data-order-id="<?= $pedido['order_id'] ?>">
                                    <td><strong>#<?= $pedido['order_id'] ?></strong></td>
                                    <td><?= htmlspecialchars($pedido['customer_name']) ?></td>
                                    <td><?= $pedido['itens_count'] ?> itens</td>
                                    <td><strong>R$ <?= number_format($pedido['total'], 2, ',', '.') ?></strong></td>
                                    <td>
                                        <span class="om-order-status <?= $pedido['status'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $pedido['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="om-text-muted"><?= date('d/m H:i', strtotime($pedido['date_added'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Alertas -->
            <div class="om-col-4">
                <div class="om-card">
                    <div class="om-card-header">
                        <h3 class="om-card-title">Alertas de Estoque</h3>
                    </div>
                    <div class="om-card-body">
                        <?php if (empty($baixo_estoque)): ?>
                        <div class="om-empty-state om-p-4">
                            <svg class="om-empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                            <p class="om-text-sm om-text-muted">Estoque OK!</p>
                        </div>
                        <?php else: ?>
                        <div class="om-flex om-flex-col om-gap-3">
                            <?php foreach ($baixo_estoque as $produto): ?>
                            <div class="om-flex om-items-center om-gap-3 om-p-3 om-bg-warning om-rounded-lg">
                                <div class="om-flex-1">
                                    <div class="om-font-medium om-text-sm"><?= htmlspecialchars($produto['name']) ?></div>
                                    <div class="om-text-xs om-text-muted">Estoque: <?= $produto['stock'] ?> unidades</div>
                                </div>
                                <a href="produtos.php?edit=<?= $produto['product_id'] ?>" class="om-btn om-btn-sm om-btn-outline">
                                    Editar
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Status do Mercado -->
                <div class="om-card om-mt-4">
                    <div class="om-card-header">
                        <h3 class="om-card-title">Status da Loja</h3>
                    </div>
                    <div class="om-card-body">
                        <div class="om-flex om-items-center om-justify-between om-mb-4">
                            <span>Loja</span>
                            <span class="om-badge <?= $is_paused ? 'om-badge-warning' : ($mercado['is_open'] ? 'om-badge-success' : 'om-badge-error') ?>" id="storeStatusBadge">
                                <?= $is_paused ? 'Em pausa' : ($mercado['is_open'] ? 'Aberta' : 'Fechada') ?>
                            </span>
                        </div>

                        <!-- Pause countdown (visible when paused) -->
                        <div id="pauseCountdownBox" style="<?= $is_paused ? '' : 'display:none;' ?>background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:12px;text-align:center;">
                            <div style="font-size:12px;color:#92400e;font-weight:500;margin-bottom:4px;">Pausado - reabre em</div>
                            <div id="pauseCountdownTimer" style="font-size:22px;font-weight:700;color:#d97706;font-variant-numeric:tabular-nums;">
                                <?php if ($is_paused): ?>
                                    <?php
                                        $remaining = $pause_until_ts - time();
                                        $h = floor($remaining / 3600);
                                        $m = floor(($remaining % 3600) / 60);
                                        $s = $remaining % 60;
                                        echo ($h > 0 ? str_pad($h, 2, '0', STR_PAD_LEFT) . ':' : '') . str_pad($m, 2, '0', STR_PAD_LEFT) . ':' . str_pad($s, 2, '0', STR_PAD_LEFT);
                                    ?>
                                <?php endif; ?>
                            </div>
                            <button class="om-btn om-btn-sm om-btn-outline" onclick="reopenStore()" style="margin-top:8px;font-size:12px;">Reabrir agora</button>
                        </div>

                        <div class="om-flex om-items-center om-justify-between om-mb-3">
                            <span>Aceitando Pedidos</span>
                            <label class="om-switch">
                                <input type="checkbox" class="om-switch-input" <?= ($mercado['is_open'] && !$is_paused) ? 'checked' : '' ?> id="toggleOrders">
                                <span class="om-switch-track"><span class="om-switch-thumb"></span></span>
                            </label>
                        </div>

                        <!-- Pause button -->
                        <button class="om-btn om-btn-sm om-btn-outline" id="btnPausar" onclick="showPauseModal()" style="width:100%;justify-content:center;gap:6px;border-color:#f59e0b;color:#d97706;">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>
                            Pausar temporariamente
                        </button>
                    </div>
                </div>

                <!-- Pause Modal -->
                <div id="pauseModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;background:rgba(0,0,0,0.5);display:none;align-items:center;justify-content:center;">
                    <div style="background:#fff;border-radius:12px;padding:24px;max-width:360px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
                        <h3 style="margin:0 0 4px;font-size:18px;font-weight:600;">Pausar loja</h3>
                        <p style="color:#6b7280;font-size:14px;margin:0 0 16px;">A loja reabre automaticamente apos o tempo selecionado.</p>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;">
                            <button class="om-btn om-btn-outline pause-opt" onclick="pauseStore(15)" style="justify-content:center;font-weight:600;">15 min</button>
                            <button class="om-btn om-btn-outline pause-opt" onclick="pauseStore(30)" style="justify-content:center;font-weight:600;">30 min</button>
                            <button class="om-btn om-btn-outline pause-opt" onclick="pauseStore(60)" style="justify-content:center;font-weight:600;">1 hora</button>
                            <button class="om-btn om-btn-outline pause-opt" onclick="pauseStore(120)" style="justify-content:center;font-weight:600;">2 horas</button>
                        </div>
                        <div style="margin-bottom:16px;">
                            <label style="font-size:13px;color:#374151;font-weight:500;display:block;margin-bottom:4px;">Tempo personalizado (min)</label>
                            <div style="display:flex;gap:8px;">
                                <input type="number" id="customPauseMinutes" class="om-input" min="5" max="480" placeholder="Ex: 45" style="flex:1;">
                                <button class="om-btn om-btn-primary om-btn-sm" onclick="pauseStore(parseInt(document.getElementById('customPauseMinutes').value))" style="white-space:nowrap;">Pausar</button>
                            </div>
                        </div>
                        <button class="om-btn om-btn-ghost" onclick="hidePauseModal()" style="width:100%;justify-content:center;">Cancelar</button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Toggle sidebar mobile
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            sidebarOverlay.classList.toggle('show');
        });

        sidebarOverlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            sidebarOverlay.classList.remove('show');
        });

        // Toggle aceitar pedidos — uses is_open field via toggle-orders.php
        const toggleCheckbox = document.getElementById('toggleOrders');
        const storeStatusBadge = document.getElementById('storeStatusBadge');

        toggleCheckbox.addEventListener('change', async function() {
            const action = this.checked ? 'open' : 'close';
            try {
                const response = await fetch('/api/mercado/painel/toggle-orders.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: action })
                });
                const data = await response.json();
                if (!data.success) {
                    alert('Erro ao atualizar: ' + (data.error || data.message || 'Erro desconhecido'));
                    this.checked = !this.checked;
                } else {
                    if (action === 'open') {
                        storeStatusBadge.textContent = 'Aberta';
                        storeStatusBadge.className = 'om-badge om-badge-success';
                        // Clear pause countdown if was paused
                        clearPauseUI();
                    } else {
                        storeStatusBadge.textContent = 'Fechada';
                        storeStatusBadge.className = 'om-badge om-badge-error';
                        clearPauseUI();
                    }
                }
            } catch (e) {
                alert('Erro de conexão');
                this.checked = !this.checked;
            }
        });

        // ═══════════════════════════════════════════════════════════
        // PAUSE: Temporary pause with countdown
        // ═══════════════════════════════════════════════════════════
        let pauseEndTime = <?= $is_paused ? ($pause_until_ts * 1000) : 'null' ?>;
        let pauseInterval = null;

        function showPauseModal() {
            const modal = document.getElementById('pauseModal');
            modal.style.display = 'flex';
        }

        function hidePauseModal() {
            const modal = document.getElementById('pauseModal');
            modal.style.display = 'none';
        }

        async function pauseStore(minutes) {
            if (!minutes || minutes < 1 || minutes > 480) {
                alert('Informe um tempo entre 1 e 480 minutos.');
                return;
            }
            try {
                const response = await fetch('/api/mercado/painel/toggle-orders.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'pause', minutes: minutes })
                });
                const data = await response.json();
                if (data.success) {
                    hidePauseModal();
                    // Set pause end time
                    pauseEndTime = new Date(data.data.pause_until).getTime();
                    // Update UI
                    storeStatusBadge.textContent = 'Em pausa';
                    storeStatusBadge.className = 'om-badge om-badge-warning';
                    toggleCheckbox.checked = false;
                    document.getElementById('pauseCountdownBox').style.display = '';
                    startPauseCountdown();
                } else {
                    alert(data.message || 'Erro ao pausar');
                }
            } catch (e) {
                alert('Erro de conexão');
            }
        }

        async function reopenStore() {
            try {
                const response = await fetch('/api/mercado/painel/toggle-orders.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'open' })
                });
                const data = await response.json();
                if (data.success) {
                    storeStatusBadge.textContent = 'Aberta';
                    storeStatusBadge.className = 'om-badge om-badge-success';
                    toggleCheckbox.checked = true;
                    clearPauseUI();
                } else {
                    alert(data.message || 'Erro ao reabrir');
                }
            } catch (e) {
                alert('Erro de conexão');
            }
        }

        function clearPauseUI() {
            pauseEndTime = null;
            if (pauseInterval) { clearInterval(pauseInterval); pauseInterval = null; }
            document.getElementById('pauseCountdownBox').style.display = 'none';
            document.getElementById('pauseCountdownTimer').textContent = '';
        }

        function startPauseCountdown() {
            if (pauseInterval) clearInterval(pauseInterval);
            updatePauseCountdown(); // immediate
            pauseInterval = setInterval(updatePauseCountdown, 1000);
        }

        function updatePauseCountdown() {
            if (!pauseEndTime) return;
            const now = Date.now();
            const diff = Math.max(0, Math.floor((pauseEndTime - now) / 1000));
            if (diff <= 0) {
                // Timer expired — auto reopen
                clearPauseUI();
                reopenStore();
                return;
            }
            const h = Math.floor(diff / 3600);
            const m = Math.floor((diff % 3600) / 60);
            const s = diff % 60;
            const timerEl = document.getElementById('pauseCountdownTimer');
            if (timerEl) {
                timerEl.textContent = (h > 0 ? String(h).padStart(2, '0') + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
            }
        }

        // Start countdown if currently paused
        if (pauseEndTime) {
            startPauseCountdown();
        }

        // Close pause modal on backdrop click
        document.getElementById('pauseModal').addEventListener('click', function(e) {
            if (e.target === this) hidePauseModal();
        });

        // Fechar dropdown ao clicar fora
        document.addEventListener('click', (e) => {
            document.querySelectorAll('.om-dropdown.open').forEach(dropdown => {
                if (!dropdown.contains(e.target)) {
                    dropdown.classList.remove('open');
                }
            });
        });

        // === Push Notification Registration ===
        async function registerPush() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

            try {
                const registration = await navigator.serviceWorker.register('/mercado/sw.js');
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') return;

                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(
                        document.querySelector('meta[name="vapid-key"]')?.content || ''
                    )
                });

                await fetch('/api/mercado/parceiro/push-subscribe.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ subscription: subscription.toJSON() })
                });

                console.log('[Push] Parceiro registrado para push');
            } catch (e) {
                console.log('[Push] Erro ao registrar:', e);
            }
        }

        function urlBase64ToUint8Array(base64String) {
            if (!base64String) return new Uint8Array();
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
        }

        registerPush();

        // === Som de Novo Pedido ===
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

        // === Toast notification ===
        const toast = document.getElementById('orderToast');
        const toastTitle = document.getElementById('toastTitle');
        const toastBody = document.getElementById('toastBody');
        let toastTimer = null;

        function showOrderNotification(data) {
            toastTitle.textContent = data.title || 'Novo Pedido!';
            toastBody.textContent = data.message || ('Pedido #' + (data.order_id || data.id || '') + ' - R$ ' + (data.total || '0,00'));
            toast.classList.add('show');
            if (toastTimer) clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toast.classList.remove('show'), 6000);
        }

        // === Update counters without full reload ===
        function updateCounters() {
            // Increment "Pedidos Hoje" counter
            const statEl = document.getElementById('statPedidosHoje');
            if (statEl) {
                const current = parseInt(statEl.textContent) || 0;
                statEl.textContent = current + 1;
            }
            // Update pending badge
            const badge = document.getElementById('pendingBadge');
            if (badge) {
                const current = parseInt(badge.textContent) || 0;
                badge.textContent = current + 1;
                badge.style.display = '';
            }
        }

        // === Update order row status ===
        function updateOrderRow(data) {
            const orderId = data.id || data.order_id;
            if (!orderId) return;
            const row = document.querySelector(`tr[data-order-id="${orderId}"]`);
            if (row) {
                const statusCell = row.querySelector('.om-order-status');
                if (statusCell && data.status) {
                    statusCell.className = 'om-order-status ' + data.status;
                    statusCell.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1).replace(/_/g, ' ');
                }
            }
        }

        // ═══════════════════════════════════════════════════════════
        // Pusher Real-Time (primary) + Polling Fallback (secondary)
        // ═══════════════════════════════════════════════════════════
        const PARTNER_ID = <?= (int)$mercado_id ?>;
        const pusherDot = document.getElementById('pusherDot');
        const pusherLabel = document.getElementById('pusherLabel');
        let pusherConnected = false;
        let pollInterval = null;

        // Initialize Pusher
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
                // Slow down polling when Pusher is active
                startFallbackPolling(30000);
            });

            pusher.connection.bind('disconnected', () => {
                pusherConnected = false;
                pusherDot.className = 'om-pusher-dot';
                pusherLabel.textContent = 'Desconectado';
                console.log('[Pusher] Desconectado — polling ativo');
                // Speed up polling when Pusher is down
                startFallbackPolling(10000);
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
                showOrderNotification(data);
                updateCounters();
                // Reload to show new order in table (debounced)
                setTimeout(() => location.reload(), 2000);
            });

            channel.bind('order-update', function(data) {
                console.log('[Pusher] Pedido atualizado:', data);
                updateOrderRow(data);
            });

            channel.bind('order-status', function(data) {
                console.log('[Pusher] Status atualizado:', data);
                updateOrderRow(data);
            });

            channel.bind('wallet-update', function(data) {
                console.log('[Pusher] Saldo atualizado:', data);
                // Could update saldo card here in future
            });

        } catch(e) {
            console.error('[Pusher] Falha ao inicializar:', e);
            pusherDot.className = 'om-pusher-dot';
            pusherLabel.textContent = 'Indisponivel';
            // Fall back to aggressive polling
            startFallbackPolling(10000);
        }

        // === Fallback Polling (30s when Pusher connected, 10s when disconnected) ===
        let lastPollTime = new Date().toISOString();
        let knownPendentes = <?= (int)($pedidos_hoje['em_andamento'] ?? 0) ?>;

        async function pollNovoPedido() {
            try {
                const res = await fetch(`/api/mercado/pedido/polling.php?since=${encodeURIComponent(lastPollTime)}`);
                const data = await res.json();
                if (data.success) {
                    if (data.data.pendentes > knownPendentes) {
                        // Only play sound if Pusher did not already handle it
                        if (!pusherConnected) {
                            playSound();
                        }
                    }
                    knownPendentes = data.data.pendentes;
                    lastPollTime = data.data.server_time;

                    // Update pending badge
                    const badge = document.getElementById('pendingBadge');
                    if (badge && data.data.pendentes > 0) {
                        badge.textContent = data.data.pendentes;
                        badge.style.display = '';
                    }

                    // Only reload page if not connected to Pusher
                    if (!pusherConnected && data.data.pedidos && data.data.pedidos.length > 0) {
                        location.reload();
                    }
                }
            } catch(e) {
                console.log('[Polling] Erro:', e);
            }
        }

        function startFallbackPolling(interval) {
            if (pollInterval) clearInterval(pollInterval);
            pollInterval = setInterval(pollNovoPedido, interval);
        }

        // Start with 30s polling (Pusher will adjust if needed)
        startFallbackPolling(30000);
    </script>
</body>
</html>
