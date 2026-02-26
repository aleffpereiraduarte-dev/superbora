<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Cardapio por Horario
 * Configure cardapios diferentes para cada momento do dia
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

// ═══════════════════════════════════════════════════════════════════
// Dias da semana
// ═══════════════════════════════════════════════════════════════════
$dias_labels = [
    1 => 'Seg',
    2 => 'Ter',
    3 => 'Qua',
    4 => 'Qui',
    5 => 'Sex',
    6 => 'Sab',
    7 => 'Dom'
];

$dias_labels_full = [
    1 => 'Segunda',
    2 => 'Terca',
    3 => 'Quarta',
    4 => 'Quinta',
    5 => 'Sexta',
    6 => 'Sabado',
    7 => 'Domingo'
];

// ═══════════════════════════════════════════════════════════════════
// POST HANDLERS
// ═══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Create Schedule ---
    if ($action === 'create_schedule') {
        $name = trim($_POST['schedule_name'] ?? '');
        $type = $_POST['schedule_type'] ?? 'time_based';

        if (!in_array($type, ['time_based', 'day_based', 'seasonal'])) {
            $type = 'time_based';
        }

        if ($name === '') {
            $error = 'Nome do agendamento e obrigatorio';
        } else {
            $start_time = null;
            $end_time = null;
            $days_of_week = null;
            $days_col = null;
            $start_date = null;
            $end_date = null;

            if ($type === 'time_based') {
                $start_time = $_POST['start_time'] ?? null;
                $end_time = $_POST['end_time'] ?? null;
                if (!$start_time || !$end_time) {
                    $error = 'Hora inicio e hora fim sao obrigatorios';
                }
            } elseif ($type === 'day_based') {
                $selected_days = $_POST['days_of_week'] ?? [];
                if (empty($selected_days)) {
                    $error = 'Selecione pelo menos um dia da semana';
                } else {
                    $days_of_week = implode(',', array_map('intval', $selected_days));
                    $days_col = $days_of_week;
                }
            } elseif ($type === 'seasonal') {
                $start_date = $_POST['start_date'] ?? null;
                $end_date = $_POST['end_date'] ?? null;
                if (!$start_date || !$end_date) {
                    $error = 'Datas de inicio e fim sao obrigatorias';
                }
            }

            if (!$error) {
                try {
                    $stmt = $db->prepare("
                        INSERT INTO om_menu_schedules
                        (partner_id, name, schedule_type, start_time, end_time, days_of_week, days, start_date, end_date, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                    ");
                    $stmt->execute([
                        $mercado_id,
                        $name,
                        $type,
                        $start_time,
                        $end_time,
                        $days_of_week,
                        $days_col,
                        $start_date,
                        $end_date
                    ]);
                    $message = 'Agendamento "' . htmlspecialchars($name) . '" criado com sucesso';
                } catch (Exception $e) {
                    error_log('Menu schedule create error: ' . $e->getMessage());
                    $error = 'Erro ao criar agendamento';
                }
            }
        }
    }

    // --- Update Schedule ---
    if ($action === 'update_schedule') {
        $schedule_id = intval($_POST['schedule_id'] ?? 0);
        $name = trim($_POST['schedule_name'] ?? '');
        $type = $_POST['schedule_type'] ?? 'time_based';

        if (!in_array($type, ['time_based', 'day_based', 'seasonal'])) {
            $type = 'time_based';
        }

        if ($name === '' || $schedule_id <= 0) {
            $error = 'Dados invalidos';
        } else {
            // Verify ownership
            $stmt = $db->prepare("SELECT id FROM om_menu_schedules WHERE id = ? AND partner_id = ? AND status >= 0");
            $stmt->execute([$schedule_id, $mercado_id]);
            if (!$stmt->fetch()) {
                $error = 'Agendamento nao encontrado';
            } else {
                $start_time = null;
                $end_time = null;
                $days_of_week = null;
                $days_col = null;
                $start_date = null;
                $end_date = null;

                if ($type === 'time_based') {
                    $start_time = $_POST['start_time'] ?? null;
                    $end_time = $_POST['end_time'] ?? null;
                    if (!$start_time || !$end_time) {
                        $error = 'Hora inicio e hora fim sao obrigatorios';
                    }
                } elseif ($type === 'day_based') {
                    $selected_days = $_POST['days_of_week'] ?? [];
                    if (empty($selected_days)) {
                        $error = 'Selecione pelo menos um dia da semana';
                    } else {
                        $days_of_week = implode(',', array_map('intval', $selected_days));
                        $days_col = $days_of_week;
                    }
                } elseif ($type === 'seasonal') {
                    $start_date = $_POST['start_date'] ?? null;
                    $end_date = $_POST['end_date'] ?? null;
                    if (!$start_date || !$end_date) {
                        $error = 'Datas de inicio e fim sao obrigatorias';
                    }
                }

                if (!$error) {
                    try {
                        $stmt = $db->prepare("
                            UPDATE om_menu_schedules SET
                                name = ?, schedule_type = ?, start_time = ?, end_time = ?,
                                days_of_week = ?, days = ?, start_date = ?, end_date = ?, updated_at = NOW()
                            WHERE id = ? AND partner_id = ?
                        ");
                        $stmt->execute([
                            $name, $type, $start_time, $end_time,
                            $days_of_week, $days_col, $start_date, $end_date,
                            $schedule_id, $mercado_id
                        ]);
                        $message = 'Agendamento atualizado com sucesso';
                    } catch (Exception $e) {
                        error_log('Menu schedule update error: ' . $e->getMessage());
                        $error = 'Erro ao atualizar agendamento';
                    }
                }
            }
        }
    }

    // --- Delete Schedule (soft delete) ---
    if ($action === 'delete_schedule') {
        $schedule_id = intval($_POST['schedule_id'] ?? 0);
        if ($schedule_id > 0) {
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("UPDATE om_menu_schedules SET status = 0, updated_at = NOW() WHERE id = ? AND partner_id = ?");
                $stmt->execute([$schedule_id, $mercado_id]);
                $stmt = $db->prepare("DELETE FROM om_product_schedule_links WHERE schedule_id = ?");
                $stmt->execute([$schedule_id]);
                $db->commit();
                $message = 'Agendamento excluido com sucesso';
            } catch (Exception $e) {
                $db->rollBack();
                error_log('Menu schedule delete error: ' . $e->getMessage());
                $error = 'Erro ao excluir agendamento';
            }
        }
    }

    // --- Toggle Schedule Status ---
    if ($action === 'toggle_schedule') {
        $schedule_id = intval($_POST['schedule_id'] ?? 0);
        $new_status = intval($_POST['new_status'] ?? 1);
        if ($schedule_id > 0) {
            $stmt = $db->prepare("UPDATE om_menu_schedules SET status = ?, updated_at = NOW() WHERE id = ? AND partner_id = ?");
            $stmt->execute([$new_status ? 1 : 2, $schedule_id, $mercado_id]);
            $message = $new_status ? 'Agendamento ativado' : 'Agendamento desativado';
        }
    }

    // --- Add Products to Schedule (AJAX) ---
    if ($action === 'add_products') {
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
                   || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
                   || !empty($_SERVER['HTTP_X_AJAX']);
        $schedule_id = intval($_POST['schedule_id'] ?? 0);
        $product_ids = $_POST['product_ids'] ?? [];

        if ($schedule_id > 0 && !empty($product_ids)) {
            // Verify schedule ownership
            $stmt = $db->prepare("SELECT id FROM om_menu_schedules WHERE id = ? AND partner_id = ?");
            $stmt->execute([$schedule_id, $mercado_id]);
            if ($stmt->fetch()) {
                $added = 0;
                foreach ($product_ids as $pid) {
                    $pid = intval($pid);
                    // Verify product belongs to this partner
                    $stmt = $db->prepare("SELECT product_id FROM om_market_products WHERE product_id = ? AND partner_id = ?");
                    $stmt->execute([$pid, $mercado_id]);
                    if ($stmt->fetch()) {
                        try {
                            $stmt2 = $db->prepare("INSERT INTO om_product_schedule_links (schedule_id, product_id) VALUES (?, ?) ON CONFLICT (schedule_id, product_id) DO NOTHING");
                            $stmt2->execute([$schedule_id, $pid]);
                            $added++;
                        } catch (Exception $e) {
                            // Duplicate, ignore
                        }
                    }
                }
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'added' => $added]);
                    exit;
                }
                $message = $added . ' produto(s) adicionado(s) ao agendamento';
            } else {
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Agendamento nao encontrado']);
                    exit;
                }
                $error = 'Agendamento nao encontrado';
            }
        }
    }

    // --- Remove Product from Schedule (AJAX) ---
    if ($action === 'remove_product') {
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
                   || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
                   || !empty($_SERVER['HTTP_X_AJAX']);
        $schedule_id = intval($_POST['schedule_id'] ?? 0);
        $product_id = intval($_POST['product_id'] ?? 0);

        if ($schedule_id > 0 && $product_id > 0) {
            // Verify schedule ownership
            $stmt = $db->prepare("SELECT id FROM om_menu_schedules WHERE id = ? AND partner_id = ?");
            $stmt->execute([$schedule_id, $mercado_id]);
            if ($stmt->fetch()) {
                $stmt = $db->prepare("DELETE FROM om_product_schedule_links WHERE schedule_id = ? AND product_id = ?");
                $stmt->execute([$schedule_id, $product_id]);
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true]);
                    exit;
                }
                $message = 'Produto removido do agendamento';
            }
        }
    }

    // Redirect to avoid re-post (PRG pattern)
    if ($message || $error) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_error'] = $error;
        header('Location: cardapio-horarios.php');
        exit;
    }
}

// Flash messages from redirect
if (isset($_SESSION['flash_message']) && $_SESSION['flash_message']) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION['flash_error']) && $_SESSION['flash_error']) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ═══════════════════════════════════════════════════════════════════
// FETCH DATA
// ═══════════════════════════════════════════════════════════════════

// Fetch active schedules
$stmt = $db->prepare("
    SELECT s.*,
           (SELECT COUNT(*) FROM om_product_schedule_links psl WHERE psl.schedule_id = s.id) AS product_count
    FROM om_menu_schedules s
    WHERE s.partner_id = ? AND s.status > 0
    ORDER BY s.created_at DESC
");
$stmt->execute([$mercado_id]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all products for this partner (for manage modal)
$stmt = $db->prepare("
    SELECT product_id, name, price
    FROM om_market_products
    WHERE partner_id = ? AND status = 1
    ORDER BY name ASC
");
$stmt->execute([$mercado_id]);
$all_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$total_schedules = count($schedules);
$active_schedules = count(array_filter($schedules, fn($s) => $s['status'] == 1));
$total_linked = array_sum(array_column($schedules, 'product_count'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cardapio por Horario - Painel do Mercado</title>
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
            <img src="/assets/img/logo-onemundo-white.png" alt="OneMundo" class="om-sidebar-logo"
                 onerror="this.outerHTML='<span class=\'om-sidebar-logo-text\'>OneMundo</span>'">
        </div>

        <nav class="om-sidebar-nav">
            <a href="index.php" class="om-sidebar-link">
                <i class="lucide-layout-dashboard"></i>
                <span>Dashboard</span>
            </a>
            <a href="pedidos.php" class="om-sidebar-link">
                <i class="lucide-shopping-bag"></i>
                <span>Pedidos</span>
            </a>
            <a href="produtos.php" class="om-sidebar-link">
                <i class="lucide-package"></i>
                <span>Produtos</span>
            </a>
            <a href="categorias.php" class="om-sidebar-link">
                <i class="lucide-tags"></i>
                <span>Categorias</span>
            </a>
            <a href="promocoes.php" class="om-sidebar-link">
                <i class="lucide-percent"></i>
                <span>Promocoes</span>
            </a>

            <div class="om-sidebar-divider"></div>
            <div class="om-sidebar-section-title">Financeiro</div>

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

            <div class="om-sidebar-divider"></div>
            <div class="om-sidebar-section-title">Configuracoes</div>

            <a href="perfil.php" class="om-sidebar-link">
                <i class="lucide-settings"></i>
                <span>Perfil</span>
            </a>
            <a href="horarios.php" class="om-sidebar-link">
                <i class="lucide-clock"></i>
                <span>Horarios</span>
            </a>
            <a href="cardapio-horarios.php" class="om-sidebar-link active">
                <i class="lucide-calendar-clock"></i>
                <span>Cardapio Horarios</span>
            </a>
            <a href="equipe.php" class="om-sidebar-link">
                <i class="lucide-users"></i>
                <span>Equipe</span>
            </a>
            <a href="zonas-entrega.php" class="om-sidebar-link">
                <i class="lucide-map-pin"></i>
                <span>Zonas de Entrega</span>
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

            <div>
                <h1 class="om-topbar-title">Cardapio por Horario</h1>
                <p class="om-text-muted om-text-sm" style="margin:0;">Configure cardapios diferentes para cada momento do dia</p>
            </div>

            <div class="om-topbar-actions">
                <button class="om-btn om-btn-primary" onclick="abrirModalCriar()">
                    <i class="lucide-plus"></i> Novo Agendamento
                </button>
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

            <!-- Schedule Type Cards -->
            <div class="ch-type-cards om-mb-6">
                <div class="ch-type-card ch-type-time">
                    <div class="ch-type-icon">
                        <i class="lucide-clock"></i>
                    </div>
                    <div class="ch-type-info">
                        <h4>Por Horario</h4>
                        <p>Cardapios para periodos do dia (cafe da manha, almoco, jantar)</p>
                    </div>
                </div>
                <div class="ch-type-card ch-type-day">
                    <div class="ch-type-icon">
                        <i class="lucide-calendar"></i>
                    </div>
                    <div class="ch-type-info">
                        <h4>Por Dia da Semana</h4>
                        <p>Itens disponiveis apenas em certos dias (feijoada no sabado)</p>
                    </div>
                </div>
                <div class="ch-type-card ch-type-seasonal">
                    <div class="ch-type-icon">
                        <i class="lucide-gift"></i>
                    </div>
                    <div class="ch-type-info">
                        <h4>Temporario/Sazonal</h4>
                        <p>Menus especiais por periodo (Natal, Pascoa, Festival)</p>
                    </div>
                </div>
            </div>

            <!-- Quick Templates -->
            <div class="om-card om-mb-6">
                <div class="om-card-header">
                    <h3 class="om-card-title">
                        <i class="lucide-zap"></i> Templates Rapidos
                    </h3>
                </div>
                <div class="om-card-body">
                    <div class="ch-templates-grid">
                        <button type="button" class="ch-template-btn" onclick="aplicarTemplate('Cafe da Manha', 'time_based', '06:00', '10:00', '', '')">
                            <i class="lucide-coffee"></i>
                            <span class="ch-template-name">Cafe da Manha</span>
                            <span class="ch-template-desc">06:00 - 10:00</span>
                        </button>
                        <button type="button" class="ch-template-btn" onclick="aplicarTemplate('Almoco', 'time_based', '11:00', '14:00', '', '')">
                            <i class="lucide-utensils"></i>
                            <span class="ch-template-name">Almoco</span>
                            <span class="ch-template-desc">11:00 - 14:00</span>
                        </button>
                        <button type="button" class="ch-template-btn" onclick="aplicarTemplate('Jantar', 'time_based', '18:00', '22:00', '', '')">
                            <i class="lucide-moon"></i>
                            <span class="ch-template-name">Jantar</span>
                            <span class="ch-template-desc">18:00 - 22:00</span>
                        </button>
                        <button type="button" class="ch-template-btn" onclick="aplicarTemplate('Happy Hour', 'time_based', '17:00', '19:00', '1,2,3,4,5', '')">
                            <i class="lucide-beer"></i>
                            <span class="ch-template-name">Happy Hour</span>
                            <span class="ch-template-desc">17:00 - 19:00 (Seg-Sex)</span>
                        </button>
                        <button type="button" class="ch-template-btn" onclick="aplicarTemplate('Feijoada Sabado', 'day_based', '11:00', '15:00', '6', '')">
                            <i class="lucide-flame"></i>
                            <span class="ch-template-name">Feijoada Sabado</span>
                            <span class="ch-template-desc">Sab 11:00 - 15:00</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Schedules List -->
            <div class="om-card">
                <div class="om-card-header">
                    <h3 class="om-card-title">
                        Agendamentos
                        <span class="om-badge om-badge-neutral om-ml-2"><?= $total_schedules ?></span>
                    </h3>
                </div>

                <div class="om-card-body">
                    <?php if (empty($schedules)): ?>
                    <div class="ch-empty-state">
                        <i class="lucide-calendar-clock"></i>
                        <h4>Nenhum agendamento criado</h4>
                        <p>Crie agendamentos para definir cardapios diferentes por horario, dia ou temporada.</p>
                        <button class="om-btn om-btn-primary" onclick="abrirModalCriar()">
                            <i class="lucide-plus"></i> Criar Primeiro Agendamento
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="ch-schedules-list">
                        <?php foreach ($schedules as $sched):
                            $stype = $sched['schedule_type'] ?? 'time_based';
                            $is_active = ($sched['status'] == 1);

                            // Parse days
                            $sched_days_str = $sched['days_of_week'] ?? $sched['days'] ?? '';
                            $sched_days = array_filter(array_map('intval', explode(',', $sched_days_str)));
                        ?>
                        <div class="ch-schedule-card <?= !$is_active ? 'ch-inactive' : '' ?>">
                            <div class="ch-schedule-header">
                                <div class="ch-schedule-title-row">
                                    <h4 class="ch-schedule-name"><?= htmlspecialchars($sched['name']) ?></h4>
                                    <?php if ($stype === 'time_based'): ?>
                                    <span class="om-badge om-badge-primary">Horario</span>
                                    <?php elseif ($stype === 'day_based'): ?>
                                    <span class="om-badge om-badge-success">Dia</span>
                                    <?php elseif ($stype === 'seasonal'): ?>
                                    <span class="om-badge ch-badge-purple">Sazonal</span>
                                    <?php endif; ?>
                                    <?php if (!$is_active): ?>
                                    <span class="om-badge om-badge-warning">Inativo</span>
                                    <?php endif; ?>
                                </div>

                                <div class="ch-schedule-meta">
                                    <?php if ($stype === 'time_based' && $sched['start_time'] && $sched['end_time']): ?>
                                    <span class="ch-meta-item">
                                        <i class="lucide-clock"></i>
                                        <?= substr($sched['start_time'], 0, 5) ?> - <?= substr($sched['end_time'], 0, 5) ?>
                                    </span>
                                    <?php endif; ?>

                                    <?php if ($stype === 'day_based' && !empty($sched_days)): ?>
                                    <span class="ch-meta-item ch-days-badges">
                                        <i class="lucide-calendar"></i>
                                        <?php foreach ($dias_labels as $dnum => $dlabel): ?>
                                        <span class="ch-day-badge <?= in_array($dnum, $sched_days) ? 'ch-day-active' : '' ?>"><?= $dlabel ?></span>
                                        <?php endforeach; ?>
                                    </span>
                                    <?php endif; ?>

                                    <?php if ($stype === 'seasonal' && $sched['start_date'] && $sched['end_date']): ?>
                                    <span class="ch-meta-item">
                                        <i class="lucide-calendar-range"></i>
                                        <?= date('d/m', strtotime($sched['start_date'])) ?> - <?= date('d/m', strtotime($sched['end_date'])) ?>
                                    </span>
                                    <?php endif; ?>

                                    <span class="ch-meta-item">
                                        <i class="lucide-package"></i>
                                        <?= intval($sched['product_count']) ?> produto<?= intval($sched['product_count']) !== 1 ? 's' : '' ?>
                                    </span>
                                </div>
                            </div>

                            <div class="ch-schedule-actions">
                                <!-- Toggle status -->
                                <form method="POST" class="om-inline">
                                    <input type="hidden" name="action" value="toggle_schedule">
                                    <input type="hidden" name="schedule_id" value="<?= $sched['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $is_active ? 0 : 1 ?>">
                                    <button type="submit" class="om-btn om-btn-sm om-btn-ghost" title="<?= $is_active ? 'Desativar' : 'Ativar' ?>">
                                        <i class="lucide-<?= $is_active ? 'pause' : 'play' ?>"></i>
                                    </button>
                                </form>

                                <button type="button" class="om-btn om-btn-sm om-btn-outline"
                                        onclick='abrirModalEditar(<?= json_encode([
                                            "id" => $sched["id"],
                                            "name" => $sched["name"],
                                            "schedule_type" => $stype,
                                            "start_time" => $sched["start_time"] ? substr($sched["start_time"], 0, 5) : "",
                                            "end_time" => $sched["end_time"] ? substr($sched["end_time"], 0, 5) : "",
                                            "days_of_week" => $sched_days_str,
                                            "start_date" => $sched["start_date"] ?? "",
                                            "end_date" => $sched["end_date"] ?? ""
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <i class="lucide-pencil"></i> Editar
                                </button>

                                <button type="button" class="om-btn om-btn-sm om-btn-outline"
                                        onclick="abrirModalProdutos(<?= $sched['id'] ?>, '<?= htmlspecialchars(addslashes($sched['name']), ENT_QUOTES) ?>')">
                                    <i class="lucide-list-plus"></i> Gerenciar Produtos
                                </button>

                                <form method="POST" class="om-inline" onsubmit="return confirm('Excluir agendamento &quot;<?= htmlspecialchars(addslashes($sched['name']), ENT_QUOTES) ?>&quot;? Os produtos vinculados serao desvinculados.')">
                                    <input type="hidden" name="action" value="delete_schedule">
                                    <input type="hidden" name="schedule_id" value="<?= $sched['id'] ?>">
                                    <button type="submit" class="om-btn om-btn-sm om-btn-ghost om-text-error" title="Excluir">
                                        <i class="lucide-trash-2"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
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
                    <div class="om-alert-title">Como funciona o cardapio por horario</div>
                    <div class="om-alert-message">
                        <ul class="om-mb-0">
                            <li><strong>Por Horario:</strong> Produtos aparecem apenas no periodo definido (ex: cafe da manha das 6h as 10h)</li>
                            <li><strong>Por Dia da Semana:</strong> Produtos aparecem apenas nos dias selecionados (ex: feijoada no sabado)</li>
                            <li><strong>Temporario:</strong> Menu especial com data de inicio e fim (ex: cardapio de Natal)</li>
                            <li>Produtos podem estar em multiplos agendamentos</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- Modal: Criar / Editar Agendamento -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div id="modalSchedule" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalSchedule()"></div>
        <div class="om-modal-content">
            <div class="om-modal-header">
                <h3 class="om-modal-title" id="modalScheduleTitle">Novo Agendamento</h3>
                <button class="om-modal-close" onclick="fecharModalSchedule()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <form method="POST" id="formSchedule">
                <input type="hidden" name="action" id="scheduleAction" value="create_schedule">
                <input type="hidden" name="schedule_id" id="scheduleId" value="">

                <div class="om-modal-body">
                    <div class="om-form-group">
                        <label class="om-label">Nome do Agendamento *</label>
                        <input type="text" name="schedule_name" id="scheduleName" class="om-input"
                               placeholder="Ex: Cafe da Manha, Almoco Executivo..." required maxlength="100">
                    </div>

                    <div class="om-form-group">
                        <label class="om-label">Tipo</label>
                        <div class="ch-type-radios">
                            <label class="ch-type-radio">
                                <input type="radio" name="schedule_type" value="time_based" checked onchange="toggleTypeFields()">
                                <span class="ch-type-radio-box">
                                    <i class="lucide-clock"></i>
                                    <span>Por Horario</span>
                                </span>
                            </label>
                            <label class="ch-type-radio">
                                <input type="radio" name="schedule_type" value="day_based" onchange="toggleTypeFields()">
                                <span class="ch-type-radio-box">
                                    <i class="lucide-calendar"></i>
                                    <span>Por Dia da Semana</span>
                                </span>
                            </label>
                            <label class="ch-type-radio">
                                <input type="radio" name="schedule_type" value="seasonal" onchange="toggleTypeFields()">
                                <span class="ch-type-radio-box">
                                    <i class="lucide-gift"></i>
                                    <span>Temporario</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Time-based fields -->
                    <div id="fieldsTimeBased" class="ch-conditional-fields">
                        <div class="om-form-row">
                            <div class="om-form-group om-col-6">
                                <label class="om-label">Hora Inicio *</label>
                                <input type="time" name="start_time" id="fieldStartTime" class="om-input" value="06:00">
                            </div>
                            <div class="om-form-group om-col-6">
                                <label class="om-label">Hora Fim *</label>
                                <input type="time" name="end_time" id="fieldEndTime" class="om-input" value="10:00">
                            </div>
                        </div>
                    </div>

                    <!-- Day-based fields -->
                    <div id="fieldsDayBased" class="ch-conditional-fields" style="display: none;">
                        <label class="om-label">Dias da Semana *</label>
                        <div class="ch-days-checkboxes">
                            <?php foreach ($dias_labels as $dnum => $dlabel): ?>
                            <label class="ch-day-checkbox">
                                <input type="checkbox" name="days_of_week[]" value="<?= $dnum ?>" class="om-checkbox day-checkbox">
                                <span class="ch-day-checkbox-label"><?= $dlabel ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Seasonal fields -->
                    <div id="fieldsSeasonal" class="ch-conditional-fields" style="display: none;">
                        <div class="om-form-row">
                            <div class="om-form-group om-col-6">
                                <label class="om-label">Data Inicio *</label>
                                <input type="date" name="start_date" id="fieldStartDate" class="om-input">
                            </div>
                            <div class="om-form-group om-col-6">
                                <label class="om-label">Data Fim *</label>
                                <input type="date" name="end_date" id="fieldEndDate" class="om-input">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModalSchedule()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-primary" id="btnSaveSchedule">
                        <i class="lucide-save"></i> Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- Modal: Gerenciar Produtos -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div id="modalProdutos" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalProdutos()"></div>
        <div class="om-modal-content om-modal-lg">
            <div class="om-modal-header">
                <h3 class="om-modal-title">
                    <i class="lucide-list-plus"></i>
                    Gerenciar Produtos - <span id="prodModalScheduleName"></span>
                </h3>
                <button class="om-modal-close" onclick="fecharModalProdutos()">
                    <i class="lucide-x"></i>
                </button>
            </div>

            <div class="om-modal-body">
                <input type="hidden" id="prodScheduleId" value="">

                <div class="ch-products-layout">
                    <!-- Left: Available Products -->
                    <div class="ch-products-column">
                        <div class="ch-products-column-header">
                            <h5>Produtos Disponiveis</h5>
                            <input type="text" class="om-input om-input-sm" id="searchAvailable"
                                   placeholder="Buscar produto..." oninput="filtrarProdutos('available')">
                        </div>
                        <div class="ch-products-list" id="listAvailable">
                            <p class="om-text-center om-text-muted om-py-4">Carregando...</p>
                        </div>
                        <div class="ch-products-column-footer">
                            <button type="button" class="om-btn om-btn-sm om-btn-primary" id="btnAddAll" onclick="adicionarTodosSelecionados()">
                                <i class="lucide-plus-circle"></i> Adicionar Selecionados
                            </button>
                        </div>
                    </div>

                    <!-- Right: Products in Schedule -->
                    <div class="ch-products-column">
                        <div class="ch-products-column-header">
                            <h5>Produtos no Agendamento</h5>
                            <input type="text" class="om-input om-input-sm" id="searchLinked"
                                   placeholder="Buscar produto..." oninput="filtrarProdutos('linked')">
                        </div>
                        <div class="ch-products-list" id="listLinked">
                            <p class="om-text-center om-text-muted om-py-4">Carregando...</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="om-modal-footer">
                <button type="button" class="om-btn om-btn-outline" onclick="fecharModalProdutos()">Fechar</button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- All products data (for product modal) -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <script>
    const allProducts = <?= json_encode(array_map(function($p) {
        return [
            'id' => intval($p['product_id']),
            'name' => $p['name'],
            'price' => floatval($p['price'])
        ];
    }, $all_products)) ?>;

    // Pre-fetch linked product IDs for each schedule
    const scheduleProducts = {};
    <?php
    foreach ($schedules as $sched) {
        $stmt2 = $db->prepare("SELECT product_id FROM om_product_schedule_links WHERE schedule_id = ?");
        $stmt2->execute([$sched['id']]);
        $linked_ids = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        echo "scheduleProducts[" . intval($sched['id']) . "] = " . json_encode(array_map('intval', $linked_ids)) . ";\n    ";
    }
    ?>

    // ═══════════════════════════════════════════════════════
    // Schedule Modal
    // ═══════════════════════════════════════════════════════
    function abrirModalCriar() {
        document.getElementById('modalScheduleTitle').textContent = 'Novo Agendamento';
        document.getElementById('scheduleAction').value = 'create_schedule';
        document.getElementById('scheduleId').value = '';
        document.getElementById('scheduleName').value = '';
        document.querySelector('input[name="schedule_type"][value="time_based"]').checked = true;
        document.getElementById('fieldStartTime').value = '06:00';
        document.getElementById('fieldEndTime').value = '10:00';
        document.getElementById('fieldStartDate').value = '';
        document.getElementById('fieldEndDate').value = '';
        document.querySelectorAll('.day-checkbox').forEach(cb => cb.checked = false);
        toggleTypeFields();
        document.getElementById('modalSchedule').classList.add('open');
    }

    function abrirModalEditar(data) {
        document.getElementById('modalScheduleTitle').textContent = 'Editar Agendamento';
        document.getElementById('scheduleAction').value = 'update_schedule';
        document.getElementById('scheduleId').value = data.id;
        document.getElementById('scheduleName').value = data.name;

        // Set type radio
        const typeRadio = document.querySelector('input[name="schedule_type"][value="' + data.schedule_type + '"]');
        if (typeRadio) typeRadio.checked = true;

        // Set fields
        document.getElementById('fieldStartTime').value = data.start_time || '06:00';
        document.getElementById('fieldEndTime').value = data.end_time || '10:00';
        document.getElementById('fieldStartDate').value = data.start_date || '';
        document.getElementById('fieldEndDate').value = data.end_date || '';

        // Set day checkboxes
        const days = data.days_of_week ? data.days_of_week.split(',').map(Number) : [];
        document.querySelectorAll('.day-checkbox').forEach(cb => {
            cb.checked = days.includes(parseInt(cb.value));
        });

        toggleTypeFields();
        document.getElementById('modalSchedule').classList.add('open');
    }

    function fecharModalSchedule() {
        document.getElementById('modalSchedule').classList.remove('open');
    }

    function toggleTypeFields() {
        const type = document.querySelector('input[name="schedule_type"]:checked').value;
        document.getElementById('fieldsTimeBased').style.display = type === 'time_based' ? '' : 'none';
        document.getElementById('fieldsDayBased').style.display = type === 'day_based' ? '' : 'none';
        document.getElementById('fieldsSeasonal').style.display = type === 'seasonal' ? '' : 'none';
    }

    // ═══════════════════════════════════════════════════════
    // Quick Templates
    // ═══════════════════════════════════════════════════════
    function aplicarTemplate(name, type, startTime, endTime, days, dates) {
        document.getElementById('modalScheduleTitle').textContent = 'Novo Agendamento';
        document.getElementById('scheduleAction').value = 'create_schedule';
        document.getElementById('scheduleId').value = '';
        document.getElementById('scheduleName').value = name;

        const typeRadio = document.querySelector('input[name="schedule_type"][value="' + type + '"]');
        if (typeRadio) typeRadio.checked = true;

        document.getElementById('fieldStartTime').value = startTime || '';
        document.getElementById('fieldEndTime').value = endTime || '';

        // Days
        const dayArr = days ? days.split(',').map(Number) : [];
        document.querySelectorAll('.day-checkbox').forEach(cb => {
            cb.checked = dayArr.includes(parseInt(cb.value));
        });

        toggleTypeFields();
        document.getElementById('modalSchedule').classList.add('open');
    }

    // ═══════════════════════════════════════════════════════
    // Products Modal
    // ═══════════════════════════════════════════════════════
    let currentScheduleId = null;
    let currentLinkedIds = [];

    function abrirModalProdutos(scheduleId, scheduleName) {
        currentScheduleId = scheduleId;
        currentLinkedIds = (scheduleProducts[scheduleId] || []).slice();
        document.getElementById('prodModalScheduleName').textContent = scheduleName;
        document.getElementById('prodScheduleId').value = scheduleId;
        document.getElementById('searchAvailable').value = '';
        document.getElementById('searchLinked').value = '';
        renderProductLists();
        document.getElementById('modalProdutos').classList.add('open');
    }

    function fecharModalProdutos() {
        document.getElementById('modalProdutos').classList.remove('open');
        // Reload page to reflect changes
        if (currentScheduleId) {
            window.location.reload();
        }
    }

    function renderProductLists() {
        const listAvail = document.getElementById('listAvailable');
        const listLinked = document.getElementById('listLinked');
        const searchAvail = document.getElementById('searchAvailable').value.toLowerCase();
        const searchLink = document.getElementById('searchLinked').value.toLowerCase();

        // Available (not linked)
        const available = allProducts.filter(p => !currentLinkedIds.includes(p.id));
        const filteredAvail = searchAvail
            ? available.filter(p => p.name.toLowerCase().includes(searchAvail))
            : available;

        if (filteredAvail.length === 0) {
            listAvail.innerHTML = '<p class="om-text-center om-text-muted om-py-4">' +
                (available.length === 0 ? 'Todos os produtos ja foram adicionados' : 'Nenhum produto encontrado') + '</p>';
        } else {
            listAvail.innerHTML = filteredAvail.map(p =>
                '<div class="ch-product-item">' +
                    '<label class="ch-product-check">' +
                        '<input type="checkbox" class="om-checkbox avail-checkbox" value="' + p.id + '">' +
                        '<div class="ch-product-info">' +
                            '<span class="ch-product-name">' + escapeHtml(p.name) + '</span>' +
                            '<span class="ch-product-price">R$ ' + p.price.toFixed(2).replace('.', ',') + '</span>' +
                        '</div>' +
                    '</label>' +
                    '<button type="button" class="om-btn om-btn-xs om-btn-primary" onclick="adicionarProduto(' + p.id + ')" title="Adicionar">' +
                        '<i class="lucide-plus"></i>' +
                    '</button>' +
                '</div>'
            ).join('');
        }

        // Linked
        const linked = allProducts.filter(p => currentLinkedIds.includes(p.id));
        const filteredLinked = searchLink
            ? linked.filter(p => p.name.toLowerCase().includes(searchLink))
            : linked;

        if (filteredLinked.length === 0) {
            listLinked.innerHTML = '<p class="om-text-center om-text-muted om-py-4">' +
                (linked.length === 0 ? 'Nenhum produto vinculado' : 'Nenhum produto encontrado') + '</p>';
        } else {
            listLinked.innerHTML = filteredLinked.map(p =>
                '<div class="ch-product-item">' +
                    '<div class="ch-product-info">' +
                        '<span class="ch-product-name">' + escapeHtml(p.name) + '</span>' +
                        '<span class="ch-product-price">R$ ' + p.price.toFixed(2).replace('.', ',') + '</span>' +
                    '</div>' +
                    '<button type="button" class="om-btn om-btn-xs om-btn-ghost om-text-error" onclick="removerProduto(' + p.id + ')" title="Remover">' +
                        '<i class="lucide-x"></i>' +
                    '</button>' +
                '</div>'
            ).join('');
        }
    }

    function filtrarProdutos(which) {
        renderProductLists();
    }

    function adicionarProduto(productId) {
        // Submit via form POST
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="add_products">' +
            '<input type="hidden" name="schedule_id" value="' + currentScheduleId + '">' +
            '<input type="hidden" name="product_ids[]" value="' + productId + '">';
        document.body.appendChild(form);

        // Update local state immediately for UX
        currentLinkedIds.push(productId);
        scheduleProducts[currentScheduleId] = currentLinkedIds.slice();
        renderProductLists();

        // Submit in background via AJAX
        const fd = new FormData(form);
        fetch('cardapio-horarios.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Ajax': '1' }
        }).then(() => {
            document.body.removeChild(form);
        }).catch(() => {
            document.body.removeChild(form);
        });
    }

    function adicionarTodosSelecionados() {
        const checkboxes = document.querySelectorAll('.avail-checkbox:checked');
        if (checkboxes.length === 0) {
            alert('Selecione pelo menos um produto');
            return;
        }

        const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));

        const form = document.createElement('form');
        form.method = 'POST';
        let html = '<input type="hidden" name="action" value="add_products">' +
            '<input type="hidden" name="schedule_id" value="' + currentScheduleId + '">';
        ids.forEach(id => {
            html += '<input type="hidden" name="product_ids[]" value="' + id + '">';
        });
        form.innerHTML = html;
        document.body.appendChild(form);

        // Update local state
        ids.forEach(id => {
            if (!currentLinkedIds.includes(id)) currentLinkedIds.push(id);
        });
        scheduleProducts[currentScheduleId] = currentLinkedIds.slice();
        renderProductLists();

        const fd = new FormData(form);
        fetch('cardapio-horarios.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Ajax': '1' }
        }).then(() => {
            document.body.removeChild(form);
        }).catch(() => {
            document.body.removeChild(form);
        });
    }

    function removerProduto(productId) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="remove_product">' +
            '<input type="hidden" name="schedule_id" value="' + currentScheduleId + '">' +
            '<input type="hidden" name="product_id" value="' + productId + '">';
        document.body.appendChild(form);

        // Update local state
        currentLinkedIds = currentLinkedIds.filter(id => id !== productId);
        scheduleProducts[currentScheduleId] = currentLinkedIds.slice();
        renderProductLists();

        const fd = new FormData(form);
        fetch('cardapio-horarios.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Ajax': '1' }
        }).then(() => {
            document.body.removeChild(form);
        }).catch(() => {
            document.body.removeChild(form);
        });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ═══════════════════════════════════════════════════════
    // Global shortcuts
    // ═══════════════════════════════════════════════════════
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            fecharModalSchedule();
            fecharModalProdutos();
        }
    });

    // Auto-hide alerts
    document.querySelectorAll('.om-alert-success, .om-alert-error').forEach(el => {
        setTimeout(() => {
            el.style.transition = 'opacity 0.3s';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 300);
        }, 4000);
    });
    </script>

    <style>
    /* ═══════════════════════════════════════════════════════
       Schedule Type Cards
       ═══════════════════════════════════════════════════════ */
    .ch-type-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: var(--om-space-4);
    }
    .ch-type-card {
        display: flex;
        align-items: flex-start;
        gap: var(--om-space-4);
        padding: var(--om-space-5);
        border-radius: var(--om-radius-lg);
        border: 1px solid var(--om-gray-200);
        background: var(--om-white, #fff);
        transition: transform 0.15s, box-shadow 0.15s;
    }
    .ch-type-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--om-shadow-md);
    }
    .ch-type-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 48px;
        height: 48px;
        border-radius: var(--om-radius-lg);
        font-size: 1.25rem;
        flex-shrink: 0;
    }
    .ch-type-time .ch-type-icon {
        background: var(--om-primary-50, #eef2ff);
        color: var(--om-primary, #4f46e5);
    }
    .ch-type-day .ch-type-icon {
        background: rgba(54, 179, 126, 0.1);
        color: var(--om-success, #36b37e);
    }
    .ch-type-seasonal .ch-type-icon {
        background: rgba(131, 56, 236, 0.1);
        color: #8338ec;
    }
    .ch-type-info h4 {
        margin: 0 0 4px 0;
        font-size: 0.95rem;
        font-weight: 600;
    }
    .ch-type-info p {
        margin: 0;
        font-size: 0.82rem;
        color: var(--om-gray-500);
        line-height: 1.4;
    }

    /* ═══════════════════════════════════════════════════════
       Quick Templates
       ═══════════════════════════════════════════════════════ */
    .ch-templates-grid {
        display: flex;
        flex-wrap: wrap;
        gap: var(--om-space-3);
    }
    .ch-template-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        padding: var(--om-space-3) var(--om-space-4);
        border: 1px dashed var(--om-gray-300);
        border-radius: var(--om-radius-lg);
        background: var(--om-gray-50);
        cursor: pointer;
        transition: all 0.15s;
        min-width: 130px;
        text-align: center;
    }
    .ch-template-btn:hover {
        border-color: var(--om-primary);
        background: var(--om-primary-50, #eef2ff);
        color: var(--om-primary);
    }
    .ch-template-btn i {
        font-size: 1.25rem;
        color: var(--om-gray-500);
    }
    .ch-template-btn:hover i {
        color: var(--om-primary);
    }
    .ch-template-name {
        font-weight: 600;
        font-size: 0.85rem;
    }
    .ch-template-desc {
        font-size: 0.75rem;
        color: var(--om-gray-500);
    }

    /* ═══════════════════════════════════════════════════════
       Schedules List
       ═══════════════════════════════════════════════════════ */
    .ch-schedules-list {
        display: flex;
        flex-direction: column;
        gap: var(--om-space-3);
    }
    .ch-schedule-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: var(--om-space-4) var(--om-space-5);
        border-radius: var(--om-radius-lg);
        background: var(--om-gray-50);
        border: 1px solid var(--om-gray-200);
        transition: all 0.15s;
    }
    .ch-schedule-card:hover {
        border-color: var(--om-primary-200, #c7d2fe);
        box-shadow: var(--om-shadow-sm);
    }
    .ch-schedule-card.ch-inactive {
        opacity: 0.6;
        background: var(--om-gray-100);
    }
    .ch-schedule-header {
        flex: 1;
        min-width: 0;
    }
    .ch-schedule-title-row {
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
        margin-bottom: 6px;
        flex-wrap: wrap;
    }
    .ch-schedule-name {
        margin: 0;
        font-size: 1rem;
        font-weight: 600;
    }
    .ch-schedule-meta {
        display: flex;
        align-items: center;
        gap: var(--om-space-4);
        flex-wrap: wrap;
    }
    .ch-meta-item {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 0.82rem;
        color: var(--om-gray-600);
    }
    .ch-meta-item i {
        font-size: 0.85rem;
        color: var(--om-gray-400);
    }
    .ch-days-badges {
        gap: 4px !important;
        flex-wrap: wrap;
    }
    .ch-day-badge {
        display: inline-block;
        padding: 1px 6px;
        font-size: 0.7rem;
        font-weight: 500;
        border-radius: var(--om-radius-sm);
        background: var(--om-gray-200);
        color: var(--om-gray-500);
    }
    .ch-day-badge.ch-day-active {
        background: var(--om-success, #36b37e);
        color: white;
    }
    .ch-schedule-actions {
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
        flex-shrink: 0;
        margin-left: var(--om-space-4);
    }

    /* Purple badge for seasonal */
    .ch-badge-purple {
        background: rgba(131, 56, 236, 0.15) !important;
        color: #8338ec !important;
    }

    /* Empty state */
    .ch-empty-state {
        text-align: center;
        padding: var(--om-space-8) var(--om-space-4);
    }
    .ch-empty-state i {
        font-size: 3rem;
        color: var(--om-gray-300);
        margin-bottom: var(--om-space-3);
    }
    .ch-empty-state h4 {
        margin: 0 0 var(--om-space-2) 0;
        color: var(--om-gray-700);
    }
    .ch-empty-state p {
        margin: 0 0 var(--om-space-4) 0;
        color: var(--om-gray-500);
        font-size: 0.9rem;
    }

    /* ═══════════════════════════════════════════════════════
       Schedule Modal: Type Radios
       ═══════════════════════════════════════════════════════ */
    .ch-type-radios {
        display: flex;
        gap: var(--om-space-3);
        flex-wrap: wrap;
    }
    .ch-type-radio {
        cursor: pointer;
        flex: 1;
        min-width: 140px;
    }
    .ch-type-radio input[type="radio"] {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
    }
    .ch-type-radio-box {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        padding: var(--om-space-3) var(--om-space-2);
        border: 2px solid var(--om-gray-200);
        border-radius: var(--om-radius-lg);
        text-align: center;
        font-size: 0.85rem;
        font-weight: 500;
        transition: all 0.15s;
    }
    .ch-type-radio-box i {
        font-size: 1.25rem;
        color: var(--om-gray-400);
    }
    .ch-type-radio input:checked + .ch-type-radio-box {
        border-color: var(--om-primary);
        background: var(--om-primary-50, #eef2ff);
        color: var(--om-primary);
    }
    .ch-type-radio input:checked + .ch-type-radio-box i {
        color: var(--om-primary);
    }

    /* Day checkboxes */
    .ch-days-checkboxes {
        display: flex;
        flex-wrap: wrap;
        gap: var(--om-space-2);
        margin-top: var(--om-space-2);
    }
    .ch-day-checkbox {
        cursor: pointer;
    }
    .ch-day-checkbox input {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
    }
    .ch-day-checkbox-label {
        display: inline-block;
        padding: 8px 16px;
        border: 2px solid var(--om-gray-200);
        border-radius: var(--om-radius-md);
        font-size: 0.85rem;
        font-weight: 500;
        transition: all 0.15s;
        user-select: none;
    }
    .ch-day-checkbox input:checked + .ch-day-checkbox-label {
        border-color: var(--om-success, #36b37e);
        background: rgba(54, 179, 126, 0.1);
        color: var(--om-success, #36b37e);
    }

    .ch-conditional-fields {
        padding-top: var(--om-space-2);
    }

    /* ═══════════════════════════════════════════════════════
       Products Modal
       ═══════════════════════════════════════════════════════ */
    .ch-products-layout {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: var(--om-space-4);
        min-height: 400px;
    }
    .ch-products-column {
        display: flex;
        flex-direction: column;
        border: 1px solid var(--om-gray-200);
        border-radius: var(--om-radius-lg);
        overflow: hidden;
    }
    .ch-products-column-header {
        padding: var(--om-space-3) var(--om-space-4);
        background: var(--om-gray-50);
        border-bottom: 1px solid var(--om-gray-200);
    }
    .ch-products-column-header h5 {
        margin: 0 0 var(--om-space-2) 0;
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--om-gray-600);
    }
    .ch-products-column-footer {
        padding: var(--om-space-3) var(--om-space-4);
        border-top: 1px solid var(--om-gray-200);
        background: var(--om-gray-50);
        text-align: center;
    }
    .ch-products-list {
        flex: 1;
        overflow-y: auto;
        max-height: 350px;
        padding: var(--om-space-2);
    }
    .ch-product-item {
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
        padding: var(--om-space-2) var(--om-space-3);
        border-radius: var(--om-radius-md);
        transition: background 0.1s;
    }
    .ch-product-item:hover {
        background: var(--om-gray-50);
    }
    .ch-product-check {
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
        flex: 1;
        cursor: pointer;
        min-width: 0;
    }
    .ch-product-info {
        display: flex;
        flex-direction: column;
        flex: 1;
        min-width: 0;
    }
    .ch-product-name {
        font-size: 0.85rem;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .ch-product-price {
        font-size: 0.75rem;
        color: var(--om-gray-500);
    }

    /* ═══════════════════════════════════════════════════════
       Sidebar section titles & dividers
       ═══════════════════════════════════════════════════════ */
    .om-sidebar-divider {
        height: 1px;
        background: rgba(255,255,255,0.1);
        margin: var(--om-space-3) var(--om-space-4);
    }
    .om-sidebar-section-title {
        padding: var(--om-space-1) var(--om-space-4) var(--om-space-2);
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: rgba(255,255,255,0.4);
    }

    /* ═══════════════════════════════════════════════════════
       Responsive
       ═══════════════════════════════════════════════════════ */
    @media (max-width: 768px) {
        .ch-type-cards {
            grid-template-columns: 1fr;
        }
        .ch-schedule-card {
            flex-direction: column;
            align-items: flex-start;
            gap: var(--om-space-3);
        }
        .ch-schedule-actions {
            margin-left: 0;
            width: 100%;
            flex-wrap: wrap;
        }
        .ch-products-layout {
            grid-template-columns: 1fr;
        }
        .ch-templates-grid {
            flex-direction: column;
        }
        .ch-template-btn {
            flex-direction: row;
            min-width: auto;
        }
    }

    /* Form layout helpers */
    .om-form-row {
        display: flex;
        gap: var(--om-space-4);
    }
    .om-col-6 {
        flex: 1;
    }
    .om-inline {
        display: inline;
    }
    .om-ml-2 {
        margin-left: 8px;
    }

    /* Modal large */
    .om-modal-lg {
        max-width: 800px;
    }

    /* Checkbox styling fix */
    .om-checkbox {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    </style>
</body>
</html>
