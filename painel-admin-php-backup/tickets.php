<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL ADMIN - Gestão de Tickets de Suporte
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

    if ($action === 'responder') {
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $resposta = trim($_POST['resposta'] ?? '');

        if ($ticket_id && $resposta) {
            $stmt = $db->prepare("INSERT INTO om_ticket_messages (ticket_id, sender_type, sender_id, message, created_at) VALUES (?, 'admin', ?, ?, NOW())");
            $stmt->execute([$ticket_id, $admin_id, $resposta]);

            $stmt = $db->prepare("UPDATE om_tickets SET updated_at = NOW(), admin_id = ? WHERE ticket_id = ?");
            $stmt->execute([$admin_id, $ticket_id]);

            $message = 'Resposta enviada';
        }
    }

    if ($action === 'fechar') {
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $stmt = $db->prepare("UPDATE om_tickets SET status = 'fechado', closed_at = NOW(), admin_id = ? WHERE ticket_id = ?");
        $stmt->execute([$admin_id, $ticket_id]);
        $message = 'Ticket fechado';
    }

    if ($action === 'assumir') {
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $stmt = $db->prepare("UPDATE om_tickets SET admin_id = ?, status = 'em_atendimento' WHERE ticket_id = ?");
        $stmt->execute([$admin_id, $ticket_id]);
        $message = 'Ticket assumido';
    }
}

// Filtros
$status = $_GET['status'] ?? '';
$tipo = $_GET['tipo'] ?? '';
$busca = $_GET['busca'] ?? '';

// Query
$where = "WHERE 1=1";
$params = [];

if ($status) {
    $where .= " AND t.status = ?";
    $params[] = $status;
}

if ($tipo) {
    $where .= " AND t.user_type = ?";
    $params[] = $tipo;
}

if ($busca) {
    $where .= " AND (t.subject LIKE ? OR t.ticket_id = ?)";
    $params[] = "%$busca%";
    $params[] = $busca;
}

$stmt = $db->prepare("
    SELECT t.*,
           CASE t.user_type
               WHEN 'cliente' THEN (SELECT name FROM om_customers WHERE customer_id = t.user_id)
               WHEN 'shopper' THEN (SELECT name FROM om_shoppers WHERE shopper_id = t.user_id)
               WHEN 'mercado' THEN (SELECT name FROM om_market_partners WHERE partner_id = t.user_id)
           END as user_name,
           a.name as admin_name,
           (SELECT COUNT(*) FROM om_ticket_messages WHERE ticket_id = t.ticket_id) as total_messages
    FROM om_tickets t
    LEFT JOIN om_admins a ON t.admin_id = a.admin_id
    $where
    ORDER BY
        CASE t.status WHEN 'aberto' THEN 0 WHEN 'em_atendimento' THEN 1 ELSE 2 END,
        t.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// Stats
$stmt = $db->query("
    SELECT
        SUM(CASE WHEN status = 'aberto' THEN 1 ELSE 0 END) as abertos,
        SUM(CASE WHEN status = 'em_atendimento' THEN 1 ELSE 0 END) as em_atendimento,
        SUM(CASE WHEN status = 'fechado' AND DATE(closed_at) = CURDATE() THEN 1 ELSE 0 END) as fechados_hoje
    FROM om_tickets
");
$stats = $stmt->fetch();

// Detalhes do ticket selecionado
$ticket_selecionado = null;
$mensagens = [];
if (isset($_GET['id'])) {
    $stmt = $db->prepare("
        SELECT t.*,
               CASE t.user_type
                   WHEN 'cliente' THEN (SELECT name FROM om_customers WHERE customer_id = t.user_id)
                   WHEN 'shopper' THEN (SELECT name FROM om_shoppers WHERE shopper_id = t.user_id)
                   WHEN 'mercado' THEN (SELECT name FROM om_market_partners WHERE partner_id = t.user_id)
               END as user_name,
               CASE t.user_type
                   WHEN 'cliente' THEN (SELECT email FROM om_customers WHERE customer_id = t.user_id)
                   WHEN 'shopper' THEN (SELECT email FROM om_shoppers WHERE shopper_id = t.user_id)
                   WHEN 'mercado' THEN (SELECT email FROM om_market_partners WHERE partner_id = t.user_id)
               END as user_email,
               CASE t.user_type
                   WHEN 'cliente' THEN (SELECT phone FROM om_customers WHERE customer_id = t.user_id)
                   WHEN 'shopper' THEN (SELECT phone FROM om_shoppers WHERE shopper_id = t.user_id)
                   WHEN 'mercado' THEN (SELECT phone FROM om_market_partners WHERE partner_id = t.user_id)
               END as user_phone
        FROM om_tickets t
        WHERE t.ticket_id = ?
    ");
    $stmt->execute([$_GET['id']]);
    $ticket_selecionado = $stmt->fetch();

    if ($ticket_selecionado) {
        $stmt = $db->prepare("
            SELECT m.*,
                   CASE m.sender_type
                       WHEN 'admin' THEN (SELECT name FROM om_admins WHERE admin_id = m.sender_id)
                       ELSE ?
                   END as sender_name
            FROM om_ticket_messages m
            WHERE m.ticket_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$ticket_selecionado['user_name'], $_GET['id']]);
        $mensagens = $stmt->fetchAll();
    }
}

$status_map = [
    'aberto' => ['label' => 'Aberto', 'class' => 'warning'],
    'em_atendimento' => ['label' => 'Em Atendimento', 'class' => 'info'],
    'fechado' => ['label' => 'Fechado', 'class' => 'success']
];

$tipo_map = [
    'cliente' => ['label' => 'Cliente', 'class' => 'info'],
    'shopper' => ['label' => 'Shopper', 'class' => 'success'],
    'mercado' => ['label' => 'Mercado', 'class' => 'warning'],
    'motorista' => ['label' => 'Motorista', 'class' => 'primary']
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tickets - Admin OneMundo</title>
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
            <a href="index.php" class="om-sidebar-link">
                <i class="lucide-layout-dashboard"></i>
                <span>Dashboard</span>
            </a>

            <div class="om-sidebar-section">Suporte</div>
            <a href="tickets.php" class="om-sidebar-link active">
                <i class="lucide-headphones"></i>
                <span>Tickets</span>
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
        <header class="om-topbar">
            <button class="om-sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                <i class="lucide-menu"></i>
            </button>
            <h1 class="om-topbar-title">Tickets de Suporte</h1>
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
                <div class="om-alert-content">
                    <div class="om-alert-message"><?= htmlspecialchars($message) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="om-stats-grid om-stats-grid-3 om-mb-6">
                <div class="om-stat-card <?= $stats['abertos'] > 0 ? 'om-stat-card-highlight' : '' ?>">
                    <div class="om-stat-icon om-bg-warning-light">
                        <i class="lucide-inbox"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['abertos'] ?? 0 ?></span>
                        <span class="om-stat-label">Abertos</span>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-info-light">
                        <i class="lucide-message-circle"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['em_atendimento'] ?? 0 ?></span>
                        <span class="om-stat-label">Em Atendimento</span>
                    </div>
                </div>

                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-success-light">
                        <i class="lucide-check-circle"></i>
                    </div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $stats['fechados_hoje'] ?? 0 ?></span>
                        <span class="om-stat-label">Fechados Hoje</span>
                    </div>
                </div>
            </div>

            <div class="om-grid om-grid-cols-1 lg:om-grid-cols-<?= $ticket_selecionado ? '2' : '1' ?> om-gap-6">
                <!-- Lista de Tickets -->
                <div class="om-card">
                    <div class="om-card-header">
                        <h3 class="om-card-title">Tickets</h3>

                        <form method="GET" class="om-flex om-gap-2">
                            <select name="status" class="om-select om-select-sm" onchange="this.form.submit()">
                                <option value="">Todos Status</option>
                                <option value="aberto" <?= $status === 'aberto' ? 'selected' : '' ?>>Abertos</option>
                                <option value="em_atendimento" <?= $status === 'em_atendimento' ? 'selected' : '' ?>>Em Atendimento</option>
                                <option value="fechado" <?= $status === 'fechado' ? 'selected' : '' ?>>Fechados</option>
                            </select>

                            <select name="tipo" class="om-select om-select-sm" onchange="this.form.submit()">
                                <option value="">Todos Tipos</option>
                                <option value="cliente" <?= $tipo === 'cliente' ? 'selected' : '' ?>>Clientes</option>
                                <option value="shopper" <?= $tipo === 'shopper' ? 'selected' : '' ?>>Shoppers</option>
                                <option value="mercado" <?= $tipo === 'mercado' ? 'selected' : '' ?>>Mercados</option>
                            </select>
                        </form>
                    </div>

                    <div class="om-card-body om-p-0">
                        <?php if (empty($tickets)): ?>
                        <div class="om-empty-state om-py-8">
                            <i class="lucide-inbox om-text-4xl om-text-muted"></i>
                            <p class="om-mt-2">Nenhum ticket encontrado</p>
                        </div>
                        <?php else: ?>
                        <div class="om-ticket-list">
                            <?php foreach ($tickets as $ticket): ?>
                            <?php $st = $status_map[$ticket['status']] ?? ['label' => $ticket['status'], 'class' => 'neutral']; ?>
                            <?php $tp = $tipo_map[$ticket['user_type']] ?? ['label' => $ticket['user_type'], 'class' => 'neutral']; ?>
                            <a href="?id=<?= $ticket['ticket_id'] ?>" class="om-ticket-item <?= isset($_GET['id']) && $_GET['id'] == $ticket['ticket_id'] ? 'active' : '' ?>">
                                <div class="om-ticket-header">
                                    <span class="om-ticket-id">#<?= $ticket['ticket_id'] ?></span>
                                    <span class="om-badge om-badge-xs om-badge-<?= $st['class'] ?>"><?= $st['label'] ?></span>
                                </div>
                                <div class="om-ticket-subject"><?= htmlspecialchars($ticket['subject']) ?></div>
                                <div class="om-ticket-meta">
                                    <span class="om-badge om-badge-xs om-badge-<?= $tp['class'] ?>"><?= $tp['label'] ?></span>
                                    <span><?= htmlspecialchars($ticket['user_name'] ?? 'Usuário') ?></span>
                                    <span class="om-text-muted"><?= date('d/m H:i', strtotime($ticket['created_at'])) ?></span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Detalhes do Ticket -->
                <?php if ($ticket_selecionado): ?>
                <div class="om-card">
                    <div class="om-card-header">
                        <div>
                            <h3 class="om-card-title">Ticket #<?= $ticket_selecionado['ticket_id'] ?></h3>
                            <p class="om-text-sm om-text-muted"><?= htmlspecialchars($ticket_selecionado['subject']) ?></p>
                        </div>
                        <div class="om-flex om-gap-2">
                            <?php if ($ticket_selecionado['status'] === 'aberto'): ?>
                            <form method="POST" class="om-inline">
                                <input type="hidden" name="action" value="assumir">
                                <input type="hidden" name="ticket_id" value="<?= $ticket_selecionado['ticket_id'] ?>">
                                <button type="submit" class="om-btn om-btn-sm om-btn-outline">
                                    <i class="lucide-user-check"></i> Assumir
                                </button>
                            </form>
                            <?php endif; ?>

                            <?php if ($ticket_selecionado['status'] !== 'fechado'): ?>
                            <form method="POST" class="om-inline">
                                <input type="hidden" name="action" value="fechar">
                                <input type="hidden" name="ticket_id" value="<?= $ticket_selecionado['ticket_id'] ?>">
                                <button type="submit" class="om-btn om-btn-sm om-btn-success">
                                    <i class="lucide-check"></i> Fechar
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Info do Usuário -->
                    <div class="om-card-section om-bg-gray-50">
                        <div class="om-grid om-grid-cols-3 om-gap-4">
                            <div>
                                <p class="om-text-xs om-text-muted">Usuário</p>
                                <p class="om-font-medium"><?= htmlspecialchars($ticket_selecionado['user_name']) ?></p>
                                <span class="om-badge om-badge-xs om-badge-<?= $tipo_map[$ticket_selecionado['user_type']]['class'] ?? 'neutral' ?>">
                                    <?= ucfirst($ticket_selecionado['user_type']) ?>
                                </span>
                            </div>
                            <div>
                                <p class="om-text-xs om-text-muted">Email</p>
                                <p class="om-text-sm"><?= htmlspecialchars($ticket_selecionado['user_email'] ?? 'N/A') ?></p>
                            </div>
                            <div>
                                <p class="om-text-xs om-text-muted">Telefone</p>
                                <p class="om-text-sm"><?= htmlspecialchars($ticket_selecionado['user_phone'] ?? 'N/A') ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Mensagens -->
                    <div class="om-card-body om-chat-container">
                        <div class="om-chat-messages">
                            <!-- Mensagem inicial -->
                            <div class="om-chat-message om-chat-message-user">
                                <div class="om-chat-avatar"><?= strtoupper(substr($ticket_selecionado['user_name'] ?? 'U', 0, 1)) ?></div>
                                <div class="om-chat-bubble">
                                    <div class="om-chat-sender"><?= htmlspecialchars($ticket_selecionado['user_name']) ?></div>
                                    <div class="om-chat-text"><?= nl2br(htmlspecialchars($ticket_selecionado['message'])) ?></div>
                                    <div class="om-chat-time"><?= date('d/m/Y H:i', strtotime($ticket_selecionado['created_at'])) ?></div>
                                </div>
                            </div>

                            <?php foreach ($mensagens as $msg): ?>
                            <div class="om-chat-message <?= $msg['sender_type'] === 'admin' ? 'om-chat-message-admin' : 'om-chat-message-user' ?>">
                                <div class="om-chat-avatar"><?= strtoupper(substr($msg['sender_name'] ?? 'A', 0, 1)) ?></div>
                                <div class="om-chat-bubble">
                                    <div class="om-chat-sender"><?= htmlspecialchars($msg['sender_name']) ?></div>
                                    <div class="om-chat-text"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                                    <div class="om-chat-time"><?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Responder -->
                    <?php if ($ticket_selecionado['status'] !== 'fechado'): ?>
                    <div class="om-card-footer">
                        <form method="POST" class="om-chat-form">
                            <input type="hidden" name="action" value="responder">
                            <input type="hidden" name="ticket_id" value="<?= $ticket_selecionado['ticket_id'] ?>">
                            <textarea name="resposta" class="om-input" placeholder="Digite sua resposta..." rows="2" required></textarea>
                            <button type="submit" class="om-btn om-btn-primary">
                                <i class="lucide-send"></i> Enviar
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <style>
    .om-sidebar-section {
        padding: var(--om-space-2) var(--om-space-4);
        font-size: var(--om-font-xs);
        font-weight: var(--om-font-semibold);
        color: rgba(255,255,255,0.5);
        text-transform: uppercase;
        margin-top: var(--om-space-4);
    }
    .om-stats-grid-3 {
        grid-template-columns: repeat(3, 1fr);
    }
    .om-stat-card-highlight {
        border-left: 4px solid var(--om-warning);
    }
    .om-ticket-list {
        max-height: 600px;
        overflow-y: auto;
    }
    .om-ticket-item {
        display: block;
        padding: var(--om-space-3) var(--om-space-4);
        border-bottom: 1px solid var(--om-gray-100);
        text-decoration: none;
        color: inherit;
        transition: background 0.15s;
    }
    .om-ticket-item:hover, .om-ticket-item.active {
        background: var(--om-gray-50);
    }
    .om-ticket-item.active {
        border-left: 3px solid var(--om-primary);
    }
    .om-ticket-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: var(--om-space-1);
    }
    .om-ticket-id {
        font-size: var(--om-font-xs);
        color: var(--om-text-muted);
        font-family: monospace;
    }
    .om-ticket-subject {
        font-weight: var(--om-font-medium);
        margin-bottom: var(--om-space-1);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .om-ticket-meta {
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
        font-size: var(--om-font-xs);
    }
    .om-card-section {
        padding: var(--om-space-4);
        border-bottom: 1px solid var(--om-gray-200);
    }
    .om-chat-container {
        max-height: 400px;
        overflow-y: auto;
    }
    .om-chat-messages {
        display: flex;
        flex-direction: column;
        gap: var(--om-space-4);
    }
    .om-chat-message {
        display: flex;
        gap: var(--om-space-3);
    }
    .om-chat-message-admin {
        flex-direction: row-reverse;
    }
    .om-chat-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: var(--om-gray-200);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: var(--om-font-semibold);
        font-size: var(--om-font-sm);
        flex-shrink: 0;
    }
    .om-chat-message-admin .om-chat-avatar {
        background: var(--om-primary);
        color: white;
    }
    .om-chat-bubble {
        max-width: 70%;
        padding: var(--om-space-3);
        border-radius: var(--om-radius-lg);
        background: var(--om-gray-100);
    }
    .om-chat-message-admin .om-chat-bubble {
        background: var(--om-primary-50);
    }
    .om-chat-sender {
        font-weight: var(--om-font-medium);
        font-size: var(--om-font-sm);
        margin-bottom: var(--om-space-1);
    }
    .om-chat-text {
        font-size: var(--om-font-sm);
    }
    .om-chat-time {
        font-size: var(--om-font-xs);
        color: var(--om-text-muted);
        margin-top: var(--om-space-1);
    }
    .om-chat-form {
        display: flex;
        gap: var(--om-space-3);
        align-items: flex-end;
    }
    .om-chat-form textarea {
        flex: 1;
    }
    .om-inline {
        display: inline;
    }
    </style>
</body>
</html>
