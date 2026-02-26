<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Gerenciamento de Equipe
 * ══════════════════════════════════════════════════════════════════════════════
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

$message = '';
$error = '';

// ── Criar tabela se nao existir ──
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_partner_team (
            id SERIAL PRIMARY KEY,
            partner_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) DEFAULT NULL,
            role VARCHAR(20) DEFAULT 'atendente' CHECK (role IN ('admin','gerente','atendente')),
            status SMALLINT DEFAULT 1,
            last_login TIMESTAMP DEFAULT NULL,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW(),
            UNIQUE(partner_id, email)
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_partner_team_status ON om_partner_team(partner_id, status)");
} catch (PDOException $e) {
    // Table likely already exists — ignore
}

// ── Processar formulario ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Adicionar membro
    if ($action === 'add_member') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'atendente';
        $password = $_POST['password'] ?? '';

        if (empty($name) || empty($email)) {
            $error = 'Nome e email sao obrigatorios';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email invalido';
        } elseif (!in_array($role, ['admin', 'gerente', 'atendente'], true)) {
            $error = 'Cargo invalido';
        } elseif (empty($password) || strlen($password) < 6) {
            $error = 'Senha deve ter no minimo 6 caracteres';
        } else {
            // Verificar duplicidade de email
            $stmt = $db->prepare("SELECT id FROM om_partner_team WHERE partner_id = ? AND email = ?");
            $stmt->execute([$mercado_id, $email]);
            if ($stmt->fetch()) {
                $error = 'Ja existe um membro com este email';
            } else {
                try {
                    $password_hash = password_hash($password, PASSWORD_ARGON2ID);
                    $stmt = $db->prepare("
                        INSERT INTO om_partner_team (partner_id, name, email, password_hash, role)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$mercado_id, $name, $email, $password_hash, $role]);
                    $message = 'Membro adicionado com sucesso';
                } catch (PDOException $e) {
                    error_log("[equipe] Erro ao adicionar membro: " . $e->getMessage());
                    $error = 'Erro ao adicionar membro';
                }
            }
        }
    }

    // Atualizar membro
    if ($action === 'update_member') {
        $member_id = intval($_POST['member_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'atendente';
        $password = $_POST['password'] ?? '';

        if (!$member_id) {
            $error = 'Membro nao encontrado';
        } elseif (empty($name) || empty($email)) {
            $error = 'Nome e email sao obrigatorios';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email invalido';
        } elseif (!in_array($role, ['admin', 'gerente', 'atendente'], true)) {
            $error = 'Cargo invalido';
        } else {
            // Verificar duplicidade de email (excluindo o proprio membro)
            $stmt = $db->prepare("SELECT id FROM om_partner_team WHERE partner_id = ? AND email = ? AND id != ?");
            $stmt->execute([$mercado_id, $email, $member_id]);
            if ($stmt->fetch()) {
                $error = 'Ja existe outro membro com este email';
            } else {
                try {
                    $sets = ["name = ?", "email = ?", "role = ?", "updated_at = NOW()"];
                    $params = [$name, $email, $role];

                    if (!empty($password)) {
                        if (strlen($password) < 6) {
                            $error = 'Senha deve ter no minimo 6 caracteres';
                        } else {
                            $sets[] = "password_hash = ?";
                            $params[] = password_hash($password, PASSWORD_ARGON2ID);
                        }
                    }

                    if (empty($error)) {
                        $params[] = $member_id;
                        $params[] = $mercado_id;
                        $stmt = $db->prepare("UPDATE om_partner_team SET " . implode(', ', $sets) . " WHERE id = ? AND partner_id = ?");
                        $stmt->execute($params);
                        $message = 'Membro atualizado com sucesso';
                    }
                } catch (PDOException $e) {
                    error_log("[equipe] Erro ao atualizar membro: " . $e->getMessage());
                    $error = 'Erro ao atualizar membro';
                }
            }
        }
    }

    // Ativar/Desativar membro
    if ($action === 'toggle_status') {
        $member_id = intval($_POST['member_id'] ?? 0);
        if ($member_id) {
            try {
                $stmt = $db->prepare("
                    UPDATE om_partner_team
                    SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END, updated_at = NOW()
                    WHERE id = ? AND partner_id = ?
                ");
                $stmt->execute([$member_id, $mercado_id]);
                $message = 'Status do membro atualizado';
            } catch (PDOException $e) {
                error_log("[equipe] Erro ao alterar status: " . $e->getMessage());
                $error = 'Erro ao alterar status do membro';
            }
        }
    }
}

// ── Buscar membros da equipe ──
$stmt = $db->prepare("
    SELECT id, name, email, role, status, last_login, created_at
    FROM om_partner_team
    WHERE partner_id = ?
    ORDER BY
        CASE role WHEN 'admin' THEN 1 WHEN 'gerente' THEN 2 WHEN 'atendente' THEN 3 END,
        name ASC
");
$stmt->execute([$mercado_id]);
$membros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Contadores para summary cards ──
$total_ativos = 0;
$total_admins = 0;
$total_gerentes = 0;
$total_atendentes = 0;

foreach ($membros as $m) {
    if ($m['status'] == 1) {
        $total_ativos++;
        switch ($m['role']) {
            case 'admin': $total_admins++; break;
            case 'gerente': $total_gerentes++; break;
            case 'atendente': $total_atendentes++; break;
        }
    }
}

// Mapa de cargos para exibicao
$role_labels = [
    'admin' => 'Administrador',
    'gerente' => 'Gerente',
    'atendente' => 'Atendente',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipe - Painel do Mercado</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
</head>
<body class="om-app-layout">
    <!-- Sidebar -->
    <aside class="om-sidebar" id="sidebar">
        <div class="om-sidebar-header">
            <div class="om-sidebar-logo">
                <img src="/assets/img/logo-mercado.png" alt="Logo" onerror="this.style.display='none'">
                <span class="om-sidebar-logo-text">Painel</span>
            </div>
        </div>

        <nav class="om-sidebar-nav">
            <div class="om-sidebar-section">
                <div class="om-sidebar-section-title">Menu</div>
                <a href="index.php" class="om-sidebar-link">
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
                </a>
                <a href="produtos.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                    </svg>
                    <span class="om-sidebar-link-text">Produtos</span>
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
                <a href="promocoes.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                        <line x1="7" y1="7" x2="7.01" y2="7"></line>
                    </svg>
                    <span class="om-sidebar-link-text">Promocoes</span>
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
                <div class="om-sidebar-section-title">Configuracoes</div>
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
                    <span class="om-sidebar-link-text">Horarios</span>
                </a>
                <a href="equipe.php" class="om-sidebar-link active">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <span class="om-sidebar-link-text">Equipe</span>
                </a>
                <a href="zonas-entrega.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                        <circle cx="12" cy="10" r="3"></circle>
                    </svg>
                    <span class="om-sidebar-link-text">Zonas de Entrega</span>
                </a>
            </div>
        </nav>

        <div class="om-sidebar-footer">
            <a href="logout.php" class="om-sidebar-link">
                <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
                <span class="om-sidebar-link-text">Sair</span>
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

            <h1 class="om-topbar-title">Equipe</h1>

            <div class="om-topbar-actions">
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($mercado_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="om-page-content">
            <?php if ($message): ?>
            <div class="om-alert om-alert-success om-mb-4">
                <div class="om-alert-content">
                    <div class="om-alert-message"><?= htmlspecialchars($message) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="om-alert om-alert-error om-mb-4">
                <div class="om-alert-content">
                    <div class="om-alert-message"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="om-flex om-flex-wrap om-justify-between om-items-center om-mb-6">
                <div>
                    <h2 class="om-text-xl om-font-semibold">Equipe</h2>
                    <p class="om-text-muted om-text-sm">Gerencie os membros da sua equipe</p>
                </div>
                <button type="button" class="om-btn om-btn-primary" onclick="abrirModalMembro()">
                    <i class="lucide-user-plus"></i> Adicionar Membro
                </button>
            </div>

            <!-- Summary Cards -->
            <div class="om-equipe-stats om-mb-6">
                <div class="om-card om-equipe-stat-card">
                    <div class="om-card-body">
                        <div class="om-equipe-stat-icon total">
                            <i class="lucide-users"></i>
                        </div>
                        <div class="om-equipe-stat-info">
                            <span class="om-equipe-stat-value"><?= $total_ativos ?></span>
                            <span class="om-equipe-stat-label">Membros Ativos</span>
                        </div>
                    </div>
                </div>
                <div class="om-card om-equipe-stat-card">
                    <div class="om-card-body">
                        <div class="om-equipe-stat-icon admin">
                            <i class="lucide-shield"></i>
                        </div>
                        <div class="om-equipe-stat-info">
                            <span class="om-equipe-stat-value"><?= $total_admins ?></span>
                            <span class="om-equipe-stat-label">Administradores</span>
                        </div>
                    </div>
                </div>
                <div class="om-card om-equipe-stat-card">
                    <div class="om-card-body">
                        <div class="om-equipe-stat-icon gerente">
                            <i class="lucide-briefcase"></i>
                        </div>
                        <div class="om-equipe-stat-info">
                            <span class="om-equipe-stat-value"><?= $total_gerentes ?></span>
                            <span class="om-equipe-stat-label">Gerentes</span>
                        </div>
                    </div>
                </div>
                <div class="om-card om-equipe-stat-card">
                    <div class="om-card-body">
                        <div class="om-equipe-stat-icon atendente">
                            <i class="lucide-headphones"></i>
                        </div>
                        <div class="om-equipe-stat-info">
                            <span class="om-equipe-stat-value"><?= $total_atendentes ?></span>
                            <span class="om-equipe-stat-label">Atendentes</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Team Members Table -->
            <div class="om-card">
                <div class="om-card-header">
                    <h3 class="om-card-title">
                        <i class="lucide-users"></i> Membros da Equipe
                    </h3>
                </div>
                <div class="om-card-body">
                    <?php if (empty($membros)): ?>
                    <div class="om-empty-state">
                        <div class="om-empty-state-icon">
                            <i class="lucide-user-plus"></i>
                        </div>
                        <h4 class="om-empty-state-title">Nenhum membro cadastrado</h4>
                        <p class="om-empty-state-text">Adicione membros para gerenciar sua equipe e delegar tarefas.</p>
                        <button type="button" class="om-btn om-btn-primary" onclick="abrirModalMembro()">
                            <i class="lucide-plus"></i> Adicionar Primeiro Membro
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="om-table-responsive">
                        <table class="om-table">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Email</th>
                                    <th>Cargo</th>
                                    <th>Status</th>
                                    <th>Ultimo Login</th>
                                    <th>Acoes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($membros as $m): ?>
                                <tr class="<?= $m['status'] == 0 ? 'om-row-inactive' : '' ?>">
                                    <td>
                                        <div class="om-flex om-items-center om-gap-3">
                                            <div class="om-avatar om-avatar-sm om-avatar-<?= $m['role'] ?>">
                                                <?= strtoupper(substr($m['name'], 0, 2)) ?>
                                            </div>
                                            <span class="om-font-semibold"><?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="om-text-muted"><?= htmlspecialchars($m['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td>
                                        <span class="om-badge om-badge-role-<?= $m['role'] ?>">
                                            <?= htmlspecialchars($role_labels[$m['role']] ?? $m['role'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($m['status'] == 1): ?>
                                        <span class="om-badge om-badge-success">Ativo</span>
                                        <?php else: ?>
                                        <span class="om-badge om-badge-neutral">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($m['last_login']): ?>
                                        <span class="om-text-sm om-text-muted"><?= date('d/m/Y H:i', strtotime($m['last_login'])) ?></span>
                                        <?php else: ?>
                                        <span class="om-text-sm om-text-muted">Nunca</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="om-btn-group">
                                            <button type="button"
                                                    class="om-btn om-btn-sm om-btn-ghost"
                                                    title="Editar"
                                                    onclick="editarMembro(<?= htmlspecialchars(json_encode([
                                                        'id' => $m['id'],
                                                        'name' => $m['name'],
                                                        'email' => $m['email'],
                                                        'role' => $m['role'],
                                                    ], JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)">
                                                <i class="lucide-pencil"></i>
                                            </button>
                                            <form method="POST" class="om-inline" onsubmit="return confirm('<?= $m['status'] == 1 ? 'Desativar este membro?' : 'Reativar este membro?' ?>')">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                                <button type="submit"
                                                        class="om-btn om-btn-sm om-btn-ghost <?= $m['status'] == 1 ? 'om-text-error' : 'om-text-success' ?>"
                                                        title="<?= $m['status'] == 1 ? 'Desativar' : 'Ativar' ?>">
                                                    <i class="lucide-<?= $m['status'] == 1 ? 'user-minus' : 'user-check' ?>"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Dica -->
            <div class="om-alert om-alert-info om-mt-6">
                <div class="om-alert-icon">
                    <i class="lucide-lightbulb"></i>
                </div>
                <div class="om-alert-content">
                    <div class="om-alert-title">Niveis de acesso</div>
                    <div class="om-alert-message">
                        <ul class="om-mb-0">
                            <li><strong>Administrador:</strong> Acesso total (produtos, pedidos, financeiro, equipe)</li>
                            <li><strong>Gerente:</strong> Produtos, pedidos e promocoes</li>
                            <li><strong>Atendente:</strong> Apenas pedidos e chat</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Adicionar/Editar Membro -->
    <div id="modalMembro" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalMembro()"></div>
        <div class="om-modal-content om-modal-sm">
            <div class="om-modal-header">
                <h3 class="om-modal-title" id="modalMembroTitle">Adicionar Membro</h3>
                <button class="om-modal-close" onclick="fecharModalMembro()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <form method="POST" id="formMembro" onsubmit="return validarFormMembro()">
                <input type="hidden" name="action" id="formAction" value="add_member">
                <input type="hidden" name="member_id" id="formMemberId" value="">
                <div class="om-modal-body">
                    <div class="om-form-group">
                        <label class="om-label">Nome *</label>
                        <input type="text" name="name" id="formName" class="om-input" required
                               placeholder="Nome completo" maxlength="100">
                    </div>

                    <div class="om-form-group">
                        <label class="om-label">Email *</label>
                        <input type="email" name="email" id="formEmail" class="om-input" required
                               placeholder="email@exemplo.com" maxlength="255">
                    </div>

                    <div class="om-form-group">
                        <label class="om-label">Cargo *</label>
                        <select name="role" id="formRole" class="om-select" onchange="atualizarDescricaoCargo()">
                            <option value="atendente">Atendente</option>
                            <option value="gerente">Gerente</option>
                            <option value="admin">Administrador</option>
                        </select>
                        <div class="om-role-description" id="roleDescription">
                            <i class="lucide-info"></i>
                            <span id="roleDescriptionText">Apenas pedidos e chat</span>
                        </div>
                    </div>

                    <div class="om-form-group">
                        <label class="om-label">
                            Senha <span id="senhaObrigatoria">*</span>
                        </label>
                        <input type="password" name="password" id="formPassword" class="om-input"
                               placeholder="Minimo 6 caracteres" minlength="6">
                        <small class="om-text-muted" id="senhaHint">Obrigatoria para novos membros</small>
                    </div>
                </div>

                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModalMembro()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-primary" id="btnSalvar">
                        <i class="lucide-save"></i> Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
    /* ── Summary Cards Grid ── */
    .om-equipe-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
    }
    .om-equipe-stat-card .om-card-body {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 20px;
    }
    .om-equipe-stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        flex-shrink: 0;
    }
    .om-equipe-stat-icon.total {
        background: var(--om-primary-50, #eff6ff);
        color: var(--om-primary, #2563eb);
    }
    .om-equipe-stat-icon.admin {
        background: #f3e8ff;
        color: #9333ea;
    }
    .om-equipe-stat-icon.gerente {
        background: #dbeafe;
        color: #2563eb;
    }
    .om-equipe-stat-icon.atendente {
        background: var(--om-gray-100, #f3f4f6);
        color: var(--om-gray-600, #4b5563);
    }
    .om-equipe-stat-info {
        display: flex;
        flex-direction: column;
    }
    .om-equipe-stat-value {
        font-size: 28px;
        font-weight: 700;
        line-height: 1;
        color: var(--om-gray-900, #111827);
    }
    .om-equipe-stat-label {
        font-size: 13px;
        color: var(--om-gray-500, #6b7280);
        margin-top: 4px;
    }

    /* ── Role Badges ── */
    .om-badge-role-admin {
        background: #f3e8ff;
        color: #7c3aed;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
    }
    .om-badge-role-gerente {
        background: #dbeafe;
        color: #2563eb;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
    }
    .om-badge-role-atendente {
        background: var(--om-gray-100, #f3f4f6);
        color: var(--om-gray-600, #4b5563);
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
    }

    /* ── Avatar colors by role ── */
    .om-avatar-admin {
        background: #f3e8ff;
        color: #7c3aed;
    }
    .om-avatar-gerente {
        background: #dbeafe;
        color: #2563eb;
    }
    .om-avatar-atendente {
        background: var(--om-gray-100, #f3f4f6);
        color: var(--om-gray-600, #4b5563);
    }

    /* ── Inactive row ── */
    .om-row-inactive {
        opacity: 0.55;
    }

    /* ── Empty state ── */
    .om-empty-state {
        text-align: center;
        padding: 48px 24px;
    }
    .om-empty-state-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 64px;
        height: 64px;
        border-radius: 16px;
        background: var(--om-gray-100, #f3f4f6);
        color: var(--om-gray-400, #9ca3af);
        font-size: 28px;
        margin-bottom: 16px;
    }
    .om-empty-state-title {
        font-size: 18px;
        font-weight: 600;
        color: var(--om-gray-900, #111827);
        margin-bottom: 8px;
    }
    .om-empty-state-text {
        font-size: 14px;
        color: var(--om-gray-500, #6b7280);
        margin-bottom: 20px;
        max-width: 360px;
        margin-left: auto;
        margin-right: auto;
    }

    /* ── Role description in modal ── */
    .om-role-description {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 6px;
        padding: 8px 12px;
        background: var(--om-gray-50, #f9fafb);
        border-radius: 6px;
        font-size: 13px;
        color: var(--om-gray-600, #4b5563);
    }
    .om-role-description i {
        font-size: 14px;
        color: var(--om-gray-400, #9ca3af);
        flex-shrink: 0;
    }

    /* ── Inline form for toggle ── */
    .om-inline {
        display: inline;
    }

    /* ── Button group ── */
    .om-btn-group {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    /* ── Responsive ── */
    @media (max-width: 1024px) {
        .om-equipe-stats {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    @media (max-width: 640px) {
        .om-equipe-stats {
            grid-template-columns: 1fr;
        }
    }
    </style>

    <script>
    // ── Descricoes de cargo ──
    const roleDescriptions = {
        admin: 'Acesso total (produtos, pedidos, financeiro, equipe)',
        gerente: 'Produtos, pedidos e promocoes',
        atendente: 'Apenas pedidos e chat'
    };

    let isEditing = false;

    function atualizarDescricaoCargo() {
        const role = document.getElementById('formRole').value;
        document.getElementById('roleDescriptionText').textContent = roleDescriptions[role] || '';
    }

    function abrirModalMembro() {
        isEditing = false;
        document.getElementById('modalMembroTitle').textContent = 'Adicionar Membro';
        document.getElementById('formAction').value = 'add_member';
        document.getElementById('formMemberId').value = '';
        document.getElementById('formName').value = '';
        document.getElementById('formEmail').value = '';
        document.getElementById('formRole').value = 'atendente';
        document.getElementById('formPassword').value = '';
        document.getElementById('formPassword').required = true;
        document.getElementById('senhaObrigatoria').style.display = 'inline';
        document.getElementById('senhaHint').textContent = 'Obrigatoria para novos membros';
        atualizarDescricaoCargo();
        document.getElementById('modalMembro').classList.add('open');
        setTimeout(() => document.getElementById('formName').focus(), 100);
    }

    function editarMembro(membro) {
        isEditing = true;
        document.getElementById('modalMembroTitle').textContent = 'Editar Membro';
        document.getElementById('formAction').value = 'update_member';
        document.getElementById('formMemberId').value = membro.id;
        document.getElementById('formName').value = membro.name;
        document.getElementById('formEmail').value = membro.email;
        document.getElementById('formRole').value = membro.role;
        document.getElementById('formPassword').value = '';
        document.getElementById('formPassword').required = false;
        document.getElementById('senhaObrigatoria').style.display = 'none';
        document.getElementById('senhaHint').textContent = 'Deixe vazio para manter a senha atual';
        atualizarDescricaoCargo();
        document.getElementById('modalMembro').classList.add('open');
        setTimeout(() => document.getElementById('formName').focus(), 100);
    }

    function fecharModalMembro() {
        document.getElementById('modalMembro').classList.remove('open');
    }

    function validarFormMembro() {
        const name = document.getElementById('formName').value.trim();
        const email = document.getElementById('formEmail').value.trim();
        const password = document.getElementById('formPassword').value;

        if (!name || !email) {
            alert('Nome e email sao obrigatorios');
            return false;
        }

        // Basic email validation
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            alert('Email invalido');
            return false;
        }

        // Password required on create
        if (!isEditing && password.length < 6) {
            alert('Senha deve ter no minimo 6 caracteres');
            return false;
        }

        // Password min length on edit (if provided)
        if (isEditing && password.length > 0 && password.length < 6) {
            alert('Senha deve ter no minimo 6 caracteres');
            return false;
        }

        return true;
    }

    // Escape key closes modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            fecharModalMembro();
        }
    });
    </script>
</body>
</html>
