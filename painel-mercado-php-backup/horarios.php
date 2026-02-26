<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Horários de Funcionamento
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

// Dias da semana
$dias_semana = [
    0 => 'Domingo',
    1 => 'Segunda-feira',
    2 => 'Terça-feira',
    3 => 'Quarta-feira',
    4 => 'Quinta-feira',
    5 => 'Sexta-feira',
    6 => 'Sábado'
];

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_horarios') {
        try {
            $db->beginTransaction();

            // Deletar horários existentes
            $stmt = $db->prepare("DELETE FROM om_partner_hours WHERE partner_id = ?");
            $stmt->execute([$mercado_id]);

            // Inserir novos horários
            $stmt = $db->prepare("INSERT INTO om_partner_hours (partner_id, day_of_week, open_time, close_time, is_closed, break_start, break_end) VALUES (?, ?, ?, ?, ?, ?, ?)");

            foreach ($dias_semana as $dia => $nome) {
                $fechado = isset($_POST["fechado_$dia"]);
                $abertura = $_POST["abertura_$dia"] ?? '08:00';
                $fechamento = $_POST["fechamento_$dia"] ?? '22:00';
                $break_start = !empty($_POST["break_start_$dia"]) ? $_POST["break_start_$dia"] : null;
                $break_end = !empty($_POST["break_end_$dia"]) ? $_POST["break_end_$dia"] : null;

                // Only store break if both start and end are set and day is not closed
                if ($fechado || !$break_start || !$break_end) {
                    $break_start = null;
                    $break_end = null;
                }

                $stmt->execute([
                    $mercado_id,
                    $dia,
                    $fechado ? null : $abertura,
                    $fechado ? null : $fechamento,
                    $fechado ? 1 : 0,
                    $break_start,
                    $break_end
                ]);
            }

            $db->commit();
            $message = 'Horários atualizados com sucesso';
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Erro ao salvar horários';
        }
    }

    if ($action === 'toggle_status') {
        $stmt = $db->prepare("UPDATE om_market_partners SET is_open = CASE WHEN is_open = 1 THEN 0 ELSE 1 END WHERE partner_id = ?");
        $stmt->execute([$mercado_id]);
        $message = 'Status atualizado';
    }

    // Adicionar fechamento especial
    if ($action === 'add_closure') {
        $closure_date = $_POST['closure_date'] ?? '';
        $closure_end = $_POST['closure_end'] ?? null;
        $reason = trim($_POST['reason'] ?? 'Fechado');
        $all_day = isset($_POST['all_day']) ? 1 : 0;
        $open_time = $_POST['closure_open'] ?? null;
        $close_time = $_POST['closure_close'] ?? null;

        if ($closure_date) {
            $stmt = $db->prepare("
                INSERT INTO om_partner_closures
                (partner_id, closure_date, closure_end, reason, all_day, open_time, close_time)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (partner_id, closure_date) DO UPDATE SET reason = EXCLUDED.reason, all_day = EXCLUDED.all_day
            ");
            $stmt->execute([$mercado_id, $closure_date, $closure_end ?: null, $reason, $all_day, $all_day ? null : $open_time, $all_day ? null : $close_time]);
            $message = 'Fechamento adicionado';
        }
    }

    // Remover fechamento
    if ($action === 'delete_closure') {
        $closure_id = intval($_POST['closure_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM om_partner_closures WHERE id = ? AND partner_id = ?");
        $stmt->execute([$closure_id, $mercado_id]);
        $message = 'Fechamento removido';
    }

    // Importar feriados nacionais
    if ($action === 'import_feriados') {
        $selected = $_POST['feriados'] ?? [];
        if (!empty($selected) && is_array($selected)) {
            $count = 0;
            $stmt = $db->prepare("
                INSERT INTO om_partner_closures
                (partner_id, closure_date, closure_end, reason, all_day, open_time, close_time, created_at)
                VALUES (?, ?, NULL, ?, 1, NULL, NULL, NOW())
                ON CONFLICT (partner_id, closure_date) DO UPDATE SET reason = EXCLUDED.reason, all_day = 1
            ");
            foreach ($selected as $feriado) {
                // Format: "date|name"
                $parts = explode('|', $feriado, 2);
                if (count($parts) === 2) {
                    $date = trim($parts[0]);
                    $name = trim($parts[1]);
                    // Validate date format
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        $reason = 'Feriado - ' . $name;
                        $stmt->execute([$mercado_id, $date, $reason]);
                        $count++;
                    }
                }
            }
            $message = $count > 0 ? "$count feriado(s) importado(s) com sucesso" : 'Nenhum feriado selecionado';
        } else {
            $error = 'Selecione pelo menos um feriado';
        }
    }
}

// Buscar horários cadastrados
$stmt = $db->prepare("SELECT * FROM om_partner_hours WHERE partner_id = ?");
$stmt->execute([$mercado_id]);
$horarios_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
$horarios_db = [];
foreach ($horarios_raw as $h) {
    $horarios_db[$h['day_of_week']] = $h;
}

// Buscar status atual
$stmt = $db->prepare("SELECT is_open, accepting_orders FROM om_market_partners WHERE partner_id = ?");
$stmt->execute([$mercado_id]);
$mercado_status = $stmt->fetch();
$aceitando_pedidos = $mercado_status['is_open'] ?? $mercado_status['accepting_orders'] ?? 0;

// Buscar fechamentos especiais
$stmt = $db->prepare("
    SELECT * FROM om_partner_closures
    WHERE partner_id = ? AND closure_date >= CURRENT_DATE
    ORDER BY closure_date ASC
    LIMIT 20
");
$stmt->execute([$mercado_id]);
$fechamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Feriados nacionais brasileiros 2025/2026
$ano_atual = (int)date('Y');
$feriados = [];

// Helper to compute Easter-based holidays
function calcularPascoa($ano) {
    $a = $ano % 19;
    $b = intdiv($ano, 100);
    $c = $ano % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return mktime(0, 0, 0, $mes, $dia, $ano);
}

// Generate holidays for the current year and next year
foreach ([$ano_atual, $ano_atual + 1] as $ano) {
    $pascoa = calcularPascoa($ano);
    $feriados_ano = [
        ['date' => "$ano-01-01", 'name' => 'Ano Novo'],
        ['date' => date('Y-m-d', strtotime('-47 days', $pascoa)), 'name' => 'Carnaval'],
        ['date' => date('Y-m-d', strtotime('-46 days', $pascoa)), 'name' => 'Carnaval'],
        ['date' => date('Y-m-d', strtotime('-2 days', $pascoa)), 'name' => 'Sexta-feira Santa'],
        ['date' => "$ano-04-21", 'name' => 'Tiradentes'],
        ['date' => "$ano-05-01", 'name' => 'Dia do Trabalho'],
        ['date' => date('Y-m-d', strtotime('+60 days', $pascoa)), 'name' => 'Corpus Christi'],
        ['date' => "$ano-09-07", 'name' => 'Independencia'],
        ['date' => "$ano-10-12", 'name' => 'Nossa Senhora Aparecida'],
        ['date' => "$ano-11-02", 'name' => 'Finados'],
        ['date' => "$ano-11-15", 'name' => 'Proclamacao da Republica'],
        ['date' => "$ano-12-25", 'name' => 'Natal'],
    ];
    $feriados = array_merge($feriados, $feriados_ano);
}

// Filter: only future holidays
$hoje = date('Y-m-d');
$feriados = array_filter($feriados, fn($f) => $f['date'] >= $hoje);
$feriados = array_values($feriados);

// Build set of already-imported closure dates
$datas_fechamento = [];
foreach ($fechamentos as $f) {
    $datas_fechamento[] = $f['closure_date'];
}

// Verificar se está aberto agora
$dia_atual = date('w');
$hora_atual = date('H:i');
$horario_hoje = $horarios_db[$dia_atual] ?? null;
$aberto_agora = false;

if ($horario_hoje && !$horario_hoje['is_closed']) {
    $aberto_agora = $hora_atual >= $horario_hoje['open_time'] && $hora_atual <= $horario_hoje['close_time'];
    // Check if currently in break time
    if ($aberto_agora && !empty($horario_hoje['break_start']) && !empty($horario_hoje['break_end'])) {
        $em_intervalo = $hora_atual >= $horario_hoje['break_start'] && $hora_atual < $horario_hoje['break_end'];
    } else {
        $em_intervalo = false;
    }
} else {
    $em_intervalo = false;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horários - Painel do Mercado</title>
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
                <a href="cardapio-ia.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path><path d="M12 18v4"></path><path d="M8 22h8"></path></svg>
                    <span class="om-sidebar-link-text">Cardapio IA</span>
                    <span style="background:#8b5cf6;color:#fff;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:700;">NOVO</span>
                </a>
            <a href="categorias.php" class="om-sidebar-link">
                <i class="lucide-tags"></i>
                <span>Categorias</span>
            </a>
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
            <a href="horarios.php" class="om-sidebar-link active">
                <i class="lucide-clock"></i>
                <span>Horários</span>
            </a>
            <a href="equipe.php" class="om-sidebar-link">
                <i class="lucide-users"></i>
                <span>Equipe</span>
            </a>
            <a href="perfil.php" class="om-sidebar-link">
                <i class="lucide-settings"></i>
                <span>Configurações</span>
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

            <h1 class="om-topbar-title">Horários de Funcionamento</h1>

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

            <!-- Status Card -->
            <div class="om-card om-mb-6">
                <div class="om-card-body">
                    <div class="om-flex om-flex-wrap om-justify-between om-items-center om-gap-4">
                        <div class="om-flex om-items-center om-gap-4">
                            <div class="om-status-indicator <?= $aberto_agora && $aceitando_pedidos ? 'online' : 'offline' ?>">
                                <span class="om-status-dot"></span>
                            </div>
                            <div>
                                <h3 class="om-font-semibold">
                                    <?php if ($aceitando_pedidos && $aberto_agora): ?>
                                    Sua loja está aberta
                                    <?php elseif ($aceitando_pedidos && !$aberto_agora): ?>
                                    Fora do horário de funcionamento
                                    <?php else: ?>
                                    Sua loja está pausada
                                    <?php endif; ?>
                                </h3>
                                <p class="om-text-muted om-text-sm">
                                    <?php if ($aberto_agora && $aceitando_pedidos): ?>
                                    Você está recebendo pedidos normalmente
                                    <?php elseif (!$aberto_agora): ?>
                                    Hoje: <?= $horario_hoje && !$horario_hoje['is_closed'] ? substr($horario_hoje['open_time'],0,5) . ' às ' . substr($horario_hoje['close_time'],0,5) . (!empty($horario_hoje['break_start']) && !empty($horario_hoje['break_end']) ? ' (intervalo ' . substr($horario_hoje['break_start'],0,5) . ' - ' . substr($horario_hoje['break_end'],0,5) . ')' : '') : 'Fechado' ?>
                                    <?php else: ?>
                                    Você não está recebendo pedidos no momento
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="toggle_status">
                            <button type="submit" class="om-btn <?= $aceitando_pedidos ? 'om-btn-outline om-btn-error' : 'om-btn-success' ?>">
                                <?php if ($aceitando_pedidos): ?>
                                <i class="lucide-pause"></i> Pausar Loja
                                <?php else: ?>
                                <i class="lucide-play"></i> Abrir Loja
                                <?php endif; ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Horários -->
            <div class="om-card">
                <div class="om-card-header">
                    <h3 class="om-card-title">Configurar Horários</h3>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="save_horarios">

                    <div class="om-card-body">
                        <div class="om-horarios-grid">
                            <?php foreach ($dias_semana as $dia => $nome): ?>
                            <?php
                            $horario = $horarios_db[$dia] ?? null;
                            $fechado = $horario ? $horario['is_closed'] : ($dia == 0);
                            $abertura = $horario ? $horario['open_time'] : '08:00';
                            $fechamento = $horario ? $horario['close_time'] : '22:00';
                            $break_start = $horario['break_start'] ?? '';
                            $break_end = $horario['break_end'] ?? '';
                            $has_break = !empty($break_start) && !empty($break_end);
                            $is_hoje = $dia == $dia_atual;
                            ?>
                            <div class="om-horario-row <?= $is_hoje ? 'hoje' : '' ?> <?= $fechado ? 'fechado' : '' ?>" id="row_<?= $dia ?>">
                                <div class="om-horario-dia">
                                    <span class="om-font-semibold"><?= $nome ?></span>
                                    <?php if ($is_hoje): ?>
                                    <span class="om-badge om-badge-primary om-badge-sm">Hoje</span>
                                    <?php endif; ?>
                                </div>

                                <div class="om-horario-config">
                                    <label class="om-checkbox-label">
                                        <input type="checkbox" name="fechado_<?= $dia ?>"
                                               class="om-checkbox"
                                               <?= $fechado ? 'checked' : '' ?>
                                               onchange="toggleDia(<?= $dia ?>)">
                                        <span>Fechado</span>
                                    </label>

                                    <div class="om-horario-inputs" id="inputs_<?= $dia ?>" style="<?= $fechado ? 'opacity: 0.3; pointer-events: none;' : '' ?>">
                                        <div class="om-form-group om-mb-0">
                                            <label class="om-label om-text-xs">Abre</label>
                                            <input type="time" name="abertura_<?= $dia ?>"
                                                   class="om-input om-input-sm"
                                                   value="<?= $abertura ?>">
                                        </div>
                                        <span class="om-text-muted">às</span>
                                        <div class="om-form-group om-mb-0">
                                            <label class="om-label om-text-xs">Fecha</label>
                                            <input type="time" name="fechamento_<?= $dia ?>"
                                                   class="om-input om-input-sm"
                                                   value="<?= $fechamento ?>">
                                        </div>

                                        <div class="om-break-toggle">
                                            <button type="button"
                                                    class="om-btn om-btn-xs om-btn-ghost om-break-btn <?= $has_break ? 'active' : '' ?>"
                                                    onclick="toggleBreak(<?= $dia ?>)"
                                                    title="Intervalo / Almoço">
                                                <i class="lucide-coffee"></i>
                                                <span class="om-break-label"><?= $has_break ? 'Intervalo' : '+ Intervalo' ?></span>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="om-break-fields" id="break_<?= $dia ?>" style="<?= $has_break && !$fechado ? '' : 'display: none;' ?>">
                                        <span class="om-text-muted om-text-xs">Intervalo:</span>
                                        <div class="om-form-group om-mb-0">
                                            <input type="time" name="break_start_<?= $dia ?>"
                                                   class="om-input om-input-sm"
                                                   value="<?= substr($break_start, 0, 5) ?>"
                                                   placeholder="12:00">
                                        </div>
                                        <span class="om-text-muted">às</span>
                                        <div class="om-form-group om-mb-0">
                                            <input type="time" name="break_end_<?= $dia ?>"
                                                   class="om-input om-input-sm"
                                                   value="<?= substr($break_end, 0, 5) ?>"
                                                   placeholder="13:00">
                                        </div>
                                        <button type="button" class="om-btn om-btn-xs om-btn-ghost om-text-error" onclick="removeBreak(<?= $dia ?>)" title="Remover intervalo">
                                            <i class="lucide-x"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="om-card-footer">
                        <button type="submit" class="om-btn om-btn-primary">
                            <i class="lucide-save"></i> Salvar Horários
                        </button>
                    </div>
                </form>
            </div>

            <!-- Fechamentos Especiais -->
            <div class="om-card om-mt-6">
                <div class="om-card-header">
                    <h3 class="om-card-title">
                        <i class="lucide-calendar-x"></i> Fechamentos Especiais
                    </h3>
                    <div class="om-btn-group">
                        <button type="button" class="om-btn om-btn-sm om-btn-outline" onclick="abrirModalFeriados()">
                            <i class="lucide-calendar-days"></i> Importar Feriados <?= $ano_atual ?>
                        </button>
                        <button type="button" class="om-btn om-btn-sm om-btn-primary" onclick="abrirModalFechamento()">
                            <i class="lucide-plus"></i> Adicionar
                        </button>
                    </div>
                </div>

                <div class="om-card-body">
                    <?php if (empty($fechamentos)): ?>
                    <p class="om-text-center om-text-muted om-py-4">
                        <i class="lucide-calendar-check om-text-2xl"></i><br>
                        Nenhum fechamento programado
                    </p>
                    <?php else: ?>
                    <div class="om-table-responsive">
                        <table class="om-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Motivo</th>
                                    <th>Horário</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fechamentos as $f): ?>
                                <tr>
                                    <td>
                                        <strong><?= date('d/m/Y', strtotime($f['closure_date'])) ?></strong>
                                        <?php if ($f['closure_end'] && $f['closure_end'] != $f['closure_date']): ?>
                                        <br><small class="om-text-muted">até <?= date('d/m/Y', strtotime($f['closure_end'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="om-badge om-badge-warning"><?= htmlspecialchars($f['reason']) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($f['all_day']): ?>
                                        <span class="om-text-muted">Dia inteiro</span>
                                        <?php else: ?>
                                        <?= substr($f['open_time'], 0, 5) ?> - <?= substr($f['close_time'], 0, 5) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" class="om-inline">
                                            <input type="hidden" name="action" value="delete_closure">
                                            <input type="hidden" name="closure_id" value="<?= $f['id'] ?>">
                                            <button type="submit" class="om-btn om-btn-sm om-btn-ghost om-text-error" title="Remover">
                                                <i class="lucide-trash-2"></i>
                                            </button>
                                        </form>
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
                    <div class="om-alert-title">Como funciona para os clientes</div>
                    <div class="om-alert-message">
                        <ul class="om-mb-0">
                            <li><strong>Loja aberta:</strong> Cliente pode pedir para entrega imediata ou agendar</li>
                            <li><strong>Loja fechada:</strong> Cliente só pode agendar para quando você abrir</li>
                            <li><strong>Fechamento especial:</strong> Sistema avisa o cliente e sugere outro dia</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Fechamento -->
    <div id="modalFechamento" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalFechamento()"></div>
        <div class="om-modal-content om-modal-sm">
            <div class="om-modal-header">
                <h3 class="om-modal-title">Adicionar Fechamento</h3>
                <button class="om-modal-close" onclick="fecharModalFechamento()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_closure">
                <div class="om-modal-body">
                    <div class="om-form-group">
                        <label class="om-label">Data de Início *</label>
                        <input type="date" name="closure_date" class="om-input" required min="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="om-form-group">
                        <label class="om-label">Data de Fim (opcional)</label>
                        <input type="date" name="closure_end" class="om-input" min="<?= date('Y-m-d') ?>">
                        <small class="om-text-muted">Deixe vazio para fechamento de um dia só</small>
                    </div>

                    <div class="om-form-group">
                        <label class="om-label">Motivo</label>
                        <select name="reason" class="om-select">
                            <option value="Feriado">Feriado</option>
                            <option value="Férias">Férias</option>
                            <option value="Manutenção">Manutenção</option>
                            <option value="Inventário">Inventário</option>
                            <option value="Fechado">Outro</option>
                        </select>
                    </div>

                    <div class="om-form-group">
                        <label class="om-checkbox-label">
                            <input type="checkbox" name="all_day" class="om-checkbox" checked onchange="toggleHorarioFechamento()">
                            <span>Fechado o dia inteiro</span>
                        </label>
                    </div>

                    <div id="horarioFechamento" style="display: none;">
                        <div class="om-form-row">
                            <div class="om-form-group om-col-6">
                                <label class="om-label">Abre às</label>
                                <input type="time" name="closure_open" class="om-input" value="08:00">
                            </div>
                            <div class="om-form-group om-col-6">
                                <label class="om-label">Fecha às</label>
                                <input type="time" name="closure_close" class="om-input" value="14:00">
                            </div>
                        </div>
                        <small class="om-text-muted">Use para horário especial (ex: feriado aberto só de manhã)</small>
                    </div>
                </div>

                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModalFechamento()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Importar Feriados -->
    <div id="modalFeriados" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalFeriados()"></div>
        <div class="om-modal-content">
            <div class="om-modal-header">
                <h3 class="om-modal-title">
                    <i class="lucide-calendar-days"></i> Importar Feriados Nacionais
                </h3>
                <button class="om-modal-close" onclick="fecharModalFeriados()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="import_feriados">
                <div class="om-modal-body">
                    <p class="om-text-muted om-mb-4">
                        Selecione os feriados que deseja adicionar como fechamentos. Feriados já cadastrados estão marcados.
                    </p>

                    <div class="om-feriados-actions om-mb-3">
                        <button type="button" class="om-btn om-btn-xs om-btn-ghost" onclick="selecionarTodosFeriados(true)">
                            Selecionar todos
                        </button>
                        <button type="button" class="om-btn om-btn-xs om-btn-ghost" onclick="selecionarTodosFeriados(false)">
                            Desmarcar todos
                        </button>
                    </div>

                    <?php if (empty($feriados)): ?>
                    <p class="om-text-center om-text-muted om-py-4">Nenhum feriado futuro disponível.</p>
                    <?php else: ?>
                    <div class="om-feriados-list">
                        <?php
                        $last_year = '';
                        foreach ($feriados as $f):
                            $f_year = substr($f['date'], 0, 4);
                            $ja_cadastrado = in_array($f['date'], $datas_fechamento);
                            if ($f_year !== $last_year):
                                $last_year = $f_year;
                        ?>
                        <div class="om-feriado-year-divider">
                            <span class="om-badge om-badge-neutral"><?= $f_year ?></span>
                        </div>
                        <?php endif; ?>
                        <label class="om-feriado-item <?= $ja_cadastrado ? 'ja-cadastrado' : '' ?>">
                            <input type="checkbox"
                                   name="feriados[]"
                                   value="<?= htmlspecialchars($f['date'] . '|' . $f['name']) ?>"
                                   class="om-checkbox feriado-checkbox"
                                   <?= $ja_cadastrado ? '' : 'checked' ?>>
                            <div class="om-feriado-info">
                                <span class="om-feriado-date"><?= date('d/m', strtotime($f['date'])) ?></span>
                                <span class="om-feriado-weekday"><?= ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][date('w', strtotime($f['date']))] ?></span>
                            </div>
                            <span class="om-feriado-name"><?= htmlspecialchars($f['name']) ?></span>
                            <?php if ($ja_cadastrado): ?>
                            <span class="om-badge om-badge-sm om-badge-success">Já cadastrado</span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModalFeriados()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-primary">
                        <i class="lucide-download"></i> Importar Selecionados
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
    .om-status-indicator {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .om-status-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: var(--om-gray-400);
    }
    .om-status-indicator.online .om-status-dot {
        background: var(--om-success);
        box-shadow: 0 0 0 3px rgba(54, 179, 126, 0.2);
        animation: pulse 2s infinite;
    }
    .om-status-indicator.offline .om-status-dot {
        background: var(--om-error);
    }
    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.1); }
    }

    .om-horarios-grid {
        display: flex;
        flex-direction: column;
        gap: var(--om-space-3);
    }
    .om-horario-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: var(--om-space-4);
        border-radius: var(--om-radius-lg);
        background: var(--om-gray-50);
        transition: all 0.2s;
    }
    .om-horario-row.hoje {
        background: var(--om-primary-50);
        border: 1px solid var(--om-primary-200);
    }
    .om-horario-row.fechado {
        background: var(--om-gray-100);
    }
    .om-horario-dia {
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
        min-width: 150px;
    }
    .om-horario-config {
        display: flex;
        align-items: center;
        gap: var(--om-space-6);
    }
    .om-horario-inputs {
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
        transition: all 0.2s;
    }
    .om-checkbox-label {
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
        cursor: pointer;
    }
    .om-checkbox {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }

    /* Break time fields */
    .om-horario-config {
        flex-wrap: wrap;
    }
    .om-break-toggle {
        margin-left: 4px;
    }
    .om-break-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 0.75rem;
        color: var(--om-gray-500);
        border: 1px dashed var(--om-gray-300);
        border-radius: var(--om-radius-sm);
        padding: 4px 8px;
        transition: all 0.2s;
    }
    .om-break-btn:hover {
        color: var(--om-primary);
        border-color: var(--om-primary);
    }
    .om-break-btn.active {
        color: var(--om-primary);
        border-color: var(--om-primary-300);
        background: var(--om-primary-50);
        border-style: solid;
    }
    .om-break-fields {
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
        width: 100%;
        margin-top: var(--om-space-2);
        padding: var(--om-space-2) var(--om-space-3);
        background: var(--om-warning-50, #fffbeb);
        border-radius: var(--om-radius-sm);
        border: 1px solid var(--om-warning-200, #fde68a);
    }
    .om-break-fields .om-input-sm {
        max-width: 110px;
    }
    .om-break-label {
        white-space: nowrap;
    }
    .om-btn-xs {
        padding: 2px 6px;
        font-size: 0.75rem;
    }

    /* Feriados list */
    .om-feriados-list {
        display: flex;
        flex-direction: column;
        gap: 2px;
        max-height: 400px;
        overflow-y: auto;
    }
    .om-feriado-year-divider {
        padding: var(--om-space-2) 0;
        margin-top: var(--om-space-2);
    }
    .om-feriado-year-divider:first-child {
        margin-top: 0;
    }
    .om-feriado-item {
        display: flex;
        align-items: center;
        gap: var(--om-space-3);
        padding: var(--om-space-3) var(--om-space-3);
        border-radius: var(--om-radius-md);
        cursor: pointer;
        transition: background 0.15s;
    }
    .om-feriado-item:hover {
        background: var(--om-gray-50);
    }
    .om-feriado-item.ja-cadastrado {
        opacity: 0.6;
    }
    .om-feriado-info {
        display: flex;
        flex-direction: column;
        min-width: 50px;
    }
    .om-feriado-date {
        font-weight: 600;
        font-size: 0.9rem;
    }
    .om-feriado-weekday {
        font-size: 0.7rem;
        color: var(--om-gray-500);
        text-transform: uppercase;
    }
    .om-feriado-name {
        flex: 1;
        font-size: 0.9rem;
    }
    .om-feriados-actions {
        display: flex;
        gap: var(--om-space-2);
    }

    @media (max-width: 768px) {
        .om-horario-row {
            flex-direction: column;
            align-items: flex-start;
            gap: var(--om-space-3);
        }
        .om-horario-config {
            width: 100%;
            flex-wrap: wrap;
        }
        .om-break-fields {
            flex-wrap: wrap;
        }
    }
    </style>

    <script>
    function toggleDia(dia) {
        const checkbox = document.querySelector(`input[name="fechado_${dia}"]`);
        const inputs = document.getElementById(`inputs_${dia}`);
        const row = document.getElementById(`row_${dia}`);
        const breakFields = document.getElementById(`break_${dia}`);

        if (checkbox.checked) {
            inputs.style.opacity = '0.3';
            inputs.style.pointerEvents = 'none';
            row.classList.add('fechado');
            if (breakFields) breakFields.style.display = 'none';
        } else {
            inputs.style.opacity = '1';
            inputs.style.pointerEvents = 'auto';
            row.classList.remove('fechado');
        }
    }

    function toggleBreak(dia) {
        const breakFields = document.getElementById(`break_${dia}`);
        const btn = breakFields.closest('.om-horario-config').querySelector('.om-break-btn');

        if (breakFields.style.display === 'none') {
            breakFields.style.display = 'flex';
            btn.classList.add('active');
            btn.querySelector('.om-break-label').textContent = 'Intervalo';
            // Set default break times if empty
            const startInput = breakFields.querySelector(`input[name="break_start_${dia}"]`);
            const endInput = breakFields.querySelector(`input[name="break_end_${dia}"]`);
            if (!startInput.value) startInput.value = '12:00';
            if (!endInput.value) endInput.value = '13:00';
        } else {
            breakFields.style.display = 'none';
            btn.classList.remove('active');
            btn.querySelector('.om-break-label').textContent = '+ Intervalo';
        }
    }

    function removeBreak(dia) {
        const breakFields = document.getElementById(`break_${dia}`);
        const btn = breakFields.closest('.om-horario-config').querySelector('.om-break-btn');

        breakFields.style.display = 'none';
        breakFields.querySelector(`input[name="break_start_${dia}"]`).value = '';
        breakFields.querySelector(`input[name="break_end_${dia}"]`).value = '';
        btn.classList.remove('active');
        btn.querySelector('.om-break-label').textContent = '+ Intervalo';
    }

    function abrirModalFechamento() {
        document.getElementById('modalFechamento').classList.add('open');
    }

    function fecharModalFechamento() {
        document.getElementById('modalFechamento').classList.remove('open');
    }

    function toggleHorarioFechamento() {
        const checkbox = document.querySelector('input[name="all_day"]');
        const horarios = document.getElementById('horarioFechamento');
        horarios.style.display = checkbox.checked ? 'none' : 'block';
    }

    function abrirModalFeriados() {
        document.getElementById('modalFeriados').classList.add('open');
    }

    function fecharModalFeriados() {
        document.getElementById('modalFeriados').classList.remove('open');
    }

    function selecionarTodosFeriados(checked) {
        document.querySelectorAll('.feriado-checkbox').forEach(cb => {
            cb.checked = checked;
        });
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            fecharModalFechamento();
            fecharModalFeriados();
        }
    });
    </script>
</body>
</html>
