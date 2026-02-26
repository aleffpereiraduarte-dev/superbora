<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Zonas de Entrega
 * Configure taxas de entrega por distancia
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

// Ensure table exists
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_partner_delivery_zones (
            id SERIAL PRIMARY KEY,
            partner_id INT NOT NULL,
            label VARCHAR(100) NOT NULL,
            radius_min_km DECIMAL(5,2) DEFAULT 0,
            radius_max_km DECIMAL(5,2) DEFAULT 5,
            fee DECIMAL(10,2) DEFAULT 5,
            estimated_time VARCHAR(50) DEFAULT '30-45 min',
            status SMALLINT DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_delivery_zones_partner ON om_partner_delivery_zones(partner_id, status)");
} catch (Exception $e) {
    // Table likely already exists
}

// ─── POST Handlers ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Adicionar nova zona
    if ($action === 'add_zone') {
        $label = trim($_POST['label'] ?? '');
        $radius_min = floatval($_POST['radius_min_km'] ?? 0);
        $radius_max = floatval($_POST['radius_max_km'] ?? 5);
        $fee = floatval(str_replace(',', '.', $_POST['fee'] ?? '5'));
        $estimated_time = trim($_POST['estimated_time'] ?? '30-45 min');

        if (empty($label)) {
            $error = 'Nome da zona e obrigatorio';
        } elseif ($radius_max <= $radius_min) {
            $error = 'Raio maximo deve ser maior que o minimo';
        } elseif ($fee < 0) {
            $error = 'Taxa nao pode ser negativa';
        } elseif ($radius_min < 0 || $radius_max > 100) {
            $error = 'Valores de raio invalidos';
        } else {
            // Check for overlapping zones
            $stmt = $db->prepare("
                SELECT id, label FROM om_partner_delivery_zones
                WHERE partner_id = ? AND status = 1
                AND (
                    (? >= radius_min_km AND ? < radius_max_km)
                    OR (? > radius_min_km AND ? <= radius_max_km)
                    OR (? <= radius_min_km AND ? >= radius_max_km)
                )
            ");
            $stmt->execute([$mercado_id, $radius_min, $radius_min, $radius_max, $radius_max, $radius_min, $radius_max]);
            $overlap = $stmt->fetch();

            if ($overlap) {
                $error = 'Zona sobrepoe a zona existente: ' . htmlspecialchars($overlap['label']);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO om_partner_delivery_zones
                    (partner_id, label, radius_min_km, radius_max_km, fee, estimated_time, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(z.sort_order), 0) + 1 FROM om_partner_delivery_zones z WHERE z.partner_id = ?))
                ");
                $stmt->execute([$mercado_id, $label, $radius_min, $radius_max, $fee, $estimated_time, $mercado_id]);
                $message = 'Zona "' . htmlspecialchars($label) . '" adicionada com sucesso';
            }
        }
    }

    // Atualizar zona
    if ($action === 'update_zone') {
        $zone_id = intval($_POST['zone_id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $radius_min = floatval($_POST['radius_min_km'] ?? 0);
        $radius_max = floatval($_POST['radius_max_km'] ?? 5);
        $fee = floatval(str_replace(',', '.', $_POST['fee'] ?? '5'));
        $estimated_time = trim($_POST['estimated_time'] ?? '30-45 min');

        if (empty($label)) {
            $error = 'Nome da zona e obrigatorio';
        } elseif ($radius_max <= $radius_min) {
            $error = 'Raio maximo deve ser maior que o minimo';
        } elseif ($fee < 0) {
            $error = 'Taxa nao pode ser negativa';
        } elseif ($radius_min < 0 || $radius_max > 100) {
            $error = 'Valores de raio invalidos';
        } else {
            // Check for overlapping zones (excluding current)
            $stmt = $db->prepare("
                SELECT id, label FROM om_partner_delivery_zones
                WHERE partner_id = ? AND status = 1 AND id != ?
                AND (
                    (? >= radius_min_km AND ? < radius_max_km)
                    OR (? > radius_min_km AND ? <= radius_max_km)
                    OR (? <= radius_min_km AND ? >= radius_max_km)
                )
            ");
            $stmt->execute([$mercado_id, $zone_id, $radius_min, $radius_min, $radius_max, $radius_max, $radius_min, $radius_max]);
            $overlap = $stmt->fetch();

            if ($overlap) {
                $error = 'Zona sobrepoe a zona existente: ' . htmlspecialchars($overlap['label']);
            } else {
                $stmt = $db->prepare("
                    UPDATE om_partner_delivery_zones
                    SET label = ?, radius_min_km = ?, radius_max_km = ?, fee = ?, estimated_time = ?, updated_at = NOW()
                    WHERE id = ? AND partner_id = ?
                ");
                $stmt->execute([$label, $radius_min, $radius_max, $fee, $estimated_time, $zone_id, $mercado_id]);
                $message = 'Zona atualizada com sucesso';
            }
        }
    }

    // Soft delete zona
    if ($action === 'delete_zone') {
        $zone_id = intval($_POST['zone_id'] ?? 0);
        $stmt = $db->prepare("UPDATE om_partner_delivery_zones SET status = 0, updated_at = NOW() WHERE id = ? AND partner_id = ?");
        $stmt->execute([$zone_id, $mercado_id]);
        $message = 'Zona removida com sucesso';
    }

    // Toggle status (ativo/inativo)
    if ($action === 'toggle_status') {
        $zone_id = intval($_POST['zone_id'] ?? 0);
        $new_status = intval($_POST['new_status'] ?? 1);
        $new_status = ($new_status === 1) ? 1 : 2; // 1=active, 2=inactive (0=deleted)
        $stmt = $db->prepare("UPDATE om_partner_delivery_zones SET status = ?, updated_at = NOW() WHERE id = ? AND partner_id = ?");
        $stmt->execute([$new_status, $zone_id, $mercado_id]);
        $message = $new_status === 1 ? 'Zona ativada' : 'Zona desativada';
    }

    // Quick setup - templates
    if ($action === 'quick_setup') {
        $template = $_POST['template'] ?? '';
        $templates = [
            'padrao' => [
                ['label' => 'Perto (Gratis)', 'min' => 0, 'max' => 2, 'fee' => 0, 'time' => '15-25 min'],
                ['label' => 'Medio', 'min' => 2, 'max' => 5, 'fee' => 5, 'time' => '25-35 min'],
                ['label' => 'Distante', 'min' => 5, 'max' => 8, 'fee' => 8, 'time' => '35-50 min'],
                ['label' => 'Muito Longe', 'min' => 8, 'max' => 12, 'fee' => 12, 'time' => '45-60 min'],
            ],
            'compacto' => [
                ['label' => 'Proximo', 'min' => 0, 'max' => 3, 'fee' => 3, 'time' => '20-30 min'],
                ['label' => 'Intermediario', 'min' => 3, 'max' => 6, 'fee' => 6, 'time' => '30-40 min'],
                ['label' => 'Distante', 'min' => 6, 'max' => 10, 'fee' => 10, 'time' => '40-55 min'],
            ],
            'premium' => [
                ['label' => 'VIP (Gratis)', 'min' => 0, 'max' => 1, 'fee' => 0, 'time' => '10-20 min'],
                ['label' => 'Centro', 'min' => 1, 'max' => 3, 'fee' => 4, 'time' => '15-25 min'],
                ['label' => 'Bairro', 'min' => 3, 'max' => 5, 'fee' => 7, 'time' => '25-35 min'],
                ['label' => 'Regiao', 'min' => 5, 'max' => 8, 'fee' => 10, 'time' => '35-45 min'],
                ['label' => 'Distante', 'min' => 8, 'max' => 12, 'fee' => 15, 'time' => '45-60 min'],
            ],
        ];

        if (!isset($templates[$template])) {
            $error = 'Template invalido';
        } else {
            try {
                $db->beginTransaction();

                // Remove existing active zones (soft delete)
                $stmt = $db->prepare("UPDATE om_partner_delivery_zones SET status = 0, updated_at = NOW() WHERE partner_id = ? AND status IN (1, 2)");
                $stmt->execute([$mercado_id]);

                // Insert template zones
                $stmt = $db->prepare("
                    INSERT INTO om_partner_delivery_zones
                    (partner_id, label, radius_min_km, radius_max_km, fee, estimated_time, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $order = 1;
                foreach ($templates[$template] as $zone) {
                    $stmt->execute([$mercado_id, $zone['label'], $zone['min'], $zone['max'], $zone['fee'], $zone['time'], $order]);
                    $order++;
                }

                $db->commit();
                $names = ['padrao' => 'Padrao', 'compacto' => 'Compacto', 'premium' => 'Premium'];
                $message = 'Configuracao rapida "' . ($names[$template] ?? $template) . '" aplicada com sucesso';
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Erro ao aplicar configuracao rapida';
            }
        }
    }
}

// ─── Fetch Zones ─────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT * FROM om_partner_delivery_zones
    WHERE partner_id = ? AND status IN (1, 2)
    ORDER BY radius_min_km ASC
");
$stmt->execute([$mercado_id]);
$zonas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$total_zonas = count($zonas);
$zonas_ativas = count(array_filter($zonas, fn($z) => $z['status'] == 1));
$max_radius = $total_zonas > 0 ? max(array_column($zonas, 'radius_max_km')) : 0;
$avg_fee = $total_zonas > 0 ? array_sum(array_column($zonas, 'fee')) / $total_zonas : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zonas de Entrega - Painel do Mercado</title>
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
            <a href="perfil.php" class="om-sidebar-link">
                <i class="lucide-settings"></i>
                <span>Configuracoes</span>
            </a>
            <a href="horarios.php" class="om-sidebar-link">
                <i class="lucide-clock"></i>
                <span>Horarios</span>
            </a>
            <a href="zonas-entrega.php" class="om-sidebar-link active">
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

            <h1 class="om-topbar-title">Zonas de Entrega</h1>

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
            <div class="om-flex om-flex-wrap om-justify-between om-items-center om-gap-4 om-mb-6">
                <div>
                    <h2 class="om-text-xl om-font-semibold">Zonas de Entrega</h2>
                    <p class="om-text-muted">Configure taxas de entrega por distancia</p>
                </div>
                <div class="om-btn-group">
                    <button type="button" class="om-btn om-btn-outline" onclick="abrirModalQuickSetup()">
                        <i class="lucide-zap"></i> Configuracao Rapida
                    </button>
                    <button type="button" class="om-btn om-btn-primary" onclick="abrirModalZona()">
                        <i class="lucide-plus"></i> Adicionar Zona
                    </button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="om-grid om-grid-4 om-mb-6">
                <div class="om-card">
                    <div class="om-card-body om-text-center">
                        <div class="om-text-2xl om-font-bold"><?= $total_zonas ?></div>
                        <div class="om-text-muted om-text-sm">Zonas Configuradas</div>
                    </div>
                </div>
                <div class="om-card">
                    <div class="om-card-body om-text-center">
                        <div class="om-text-2xl om-font-bold"><?= $zonas_ativas ?></div>
                        <div class="om-text-muted om-text-sm">Zonas Ativas</div>
                    </div>
                </div>
                <div class="om-card">
                    <div class="om-card-body om-text-center">
                        <div class="om-text-2xl om-font-bold"><?= number_format($max_radius, 1, ',', '.') ?> km</div>
                        <div class="om-text-muted om-text-sm">Alcance Maximo</div>
                    </div>
                </div>
                <div class="om-card">
                    <div class="om-card-body om-text-center">
                        <div class="om-text-2xl om-font-bold">R$ <?= number_format($avg_fee, 2, ',', '.') ?></div>
                        <div class="om-text-muted om-text-sm">Taxa Media</div>
                    </div>
                </div>
            </div>

            <?php if (!empty($zonas)): ?>
            <!-- Visual Distance Map -->
            <div class="om-card om-mb-6">
                <div class="om-card-header">
                    <h3 class="om-card-title">
                        <i class="lucide-radar"></i> Mapa de Zonas
                    </h3>
                </div>
                <div class="om-card-body">
                    <div class="zones-map-container">
                        <div class="zones-map">
                            <?php
                            // Build rings from outermost to innermost so inner ones render on top
                            $zonas_reversed = array_reverse($zonas);
                            $active_zones = array_filter($zonas, fn($z) => $z['status'] == 1);
                            $max_r = count($active_zones) > 0 ? max(array_column($active_zones, 'radius_max_km')) : 10;
                            if ($max_r <= 0) $max_r = 10;

                            $zone_colors = ['#22c55e', '#84cc16', '#eab308', '#f97316', '#ef4444', '#a855f7'];
                            $zone_bg = ['rgba(34,197,94,0.12)', 'rgba(132,204,22,0.12)', 'rgba(234,179,8,0.12)', 'rgba(249,115,22,0.12)', 'rgba(239,68,68,0.12)', 'rgba(168,85,247,0.12)'];

                            $idx_map = [];
                            foreach ($zonas as $i => $z) { $idx_map[$z['id']] = $i; }

                            foreach ($zonas_reversed as $z):
                                if ($z['status'] != 1) continue;
                                $i = $idx_map[$z['id']];
                                $pct = ($z['radius_max_km'] / $max_r) * 100;
                                $color = $zone_colors[$i % count($zone_colors)];
                                $bg = $zone_bg[$i % count($zone_bg)];
                                $fee_display = $z['fee'] > 0 ? 'R$ ' . number_format($z['fee'], 2, ',', '.') : 'Gratis';
                            ?>
                            <div class="zone-ring" style="
                                width: <?= $pct ?>%;
                                height: 0;
                                padding-bottom: <?= $pct ?>%;
                                border-color: <?= $color ?>;
                                background: <?= $bg ?>;
                            ">
                                <div class="zone-ring-label" style="color: <?= $color ?>;">
                                    <span class="zone-ring-name"><?= htmlspecialchars($z['label']) ?></span>
                                    <span class="zone-ring-fee"><?= $fee_display ?></span>
                                    <span class="zone-ring-km"><?= number_format($z['radius_max_km'], 1, ',', '.') ?> km</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div class="zone-center">
                                <i class="lucide-store"></i>
                                <span>Loja</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Zones Table -->
            <div class="om-card">
                <div class="om-card-header">
                    <h3 class="om-card-title">
                        <i class="lucide-list"></i> Zonas Configuradas
                    </h3>
                </div>

                <div class="om-card-body">
                    <?php if (empty($zonas)): ?>
                    <div class="om-text-center om-py-8">
                        <i class="lucide-map-pin-off" style="font-size: 3rem; color: var(--om-gray-300);"></i>
                        <h4 class="om-mt-4 om-font-semibold">Nenhuma zona configurada</h4>
                        <p class="om-text-muted om-mt-2">Adicione zonas para definir taxas por distancia.</p>
                        <div class="om-mt-4 om-flex om-justify-center om-gap-3">
                            <button type="button" class="om-btn om-btn-outline" onclick="abrirModalQuickSetup()">
                                <i class="lucide-zap"></i> Configuracao Rapida
                            </button>
                            <button type="button" class="om-btn om-btn-primary" onclick="abrirModalZona()">
                                <i class="lucide-plus"></i> Adicionar Zona
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="om-table-responsive">
                        <table class="om-table">
                            <thead>
                                <tr>
                                    <th>Zona</th>
                                    <th>Distancia</th>
                                    <th>Taxa</th>
                                    <th>Tempo Estimado</th>
                                    <th>Status</th>
                                    <th>Acoes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($zonas as $i => $z):
                                    $color = $zone_colors[$i % count($zone_colors)];
                                    $fee_display = $z['fee'] > 0 ? 'R$ ' . number_format($z['fee'], 2, ',', '.') : 'Gratis';
                                    $is_active = $z['status'] == 1;
                                ?>
                                <tr class="<?= !$is_active ? 'zona-inativa' : '' ?>">
                                    <td>
                                        <div class="om-flex om-items-center om-gap-2">
                                            <span class="zone-color-dot" style="background: <?= $color ?>;"></span>
                                            <strong><?= htmlspecialchars($z['label']) ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <?= number_format($z['radius_min_km'], 1, ',', '.') ?> - <?= number_format($z['radius_max_km'], 1, ',', '.') ?> km
                                    </td>
                                    <td>
                                        <span class="om-font-semibold <?= $z['fee'] == 0 ? 'om-text-success' : '' ?>">
                                            <?= $fee_display ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="om-text-muted">
                                            <i class="lucide-clock" style="font-size: 0.85em;"></i>
                                            <?= htmlspecialchars($z['estimated_time']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" class="om-inline">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="zone_id" value="<?= $z['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $is_active ? 2 : 1 ?>">
                                            <button type="submit" class="om-badge <?= $is_active ? 'om-badge-success' : 'om-badge-neutral' ?>" style="cursor: pointer; border: none;" title="Clique para <?= $is_active ? 'desativar' : 'ativar' ?>">
                                                <?= $is_active ? 'Ativa' : 'Inativa' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <div class="om-flex om-gap-1">
                                            <button type="button" class="om-btn om-btn-sm om-btn-ghost"
                                                    onclick="editarZona(<?= htmlspecialchars(json_encode($z)) ?>)" title="Editar">
                                                <i class="lucide-pencil"></i>
                                            </button>
                                            <form method="POST" class="om-inline" onsubmit="return confirm('Remover esta zona?')">
                                                <input type="hidden" name="action" value="delete_zone">
                                                <input type="hidden" name="zone_id" value="<?= $z['id'] ?>">
                                                <button type="submit" class="om-btn om-btn-sm om-btn-ghost om-text-error" title="Remover">
                                                    <i class="lucide-trash-2"></i>
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
                    <div class="om-alert-title">Como funciona</div>
                    <div class="om-alert-message">
                        <ul class="om-mb-0">
                            <li><strong>Zonas nao devem se sobrepor:</strong> Cada faixa de distancia deve ser unica</li>
                            <li><strong>Taxa zero = Entrega gratis:</strong> Use taxa R$ 0,00 para entregas gratuitas em zonas proximas</li>
                            <li><strong>Tempo estimado:</strong> Exibido ao cliente no checkout para cada zona</li>
                            <li><strong>Zonas inativas:</strong> Nao sao exibidas para clientes mas permanecem salvas</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Adicionar/Editar Zona -->
    <div id="modalZona" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalZona()"></div>
        <div class="om-modal-content om-modal-sm">
            <div class="om-modal-header">
                <h3 class="om-modal-title" id="modalZonaTitle">Adicionar Zona</h3>
                <button class="om-modal-close" onclick="fecharModalZona()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <form method="POST" id="formZona" onsubmit="return validarZona()">
                <input type="hidden" name="action" id="zonaAction" value="add_zone">
                <input type="hidden" name="zone_id" id="zonaId" value="">
                <div class="om-modal-body">
                    <div class="om-form-group">
                        <label class="om-label">Nome da Zona *</label>
                        <input type="text" name="label" id="zonaLabel" class="om-input"
                               placeholder="Ex: Centro, Zona Sul, Bairro" required maxlength="100">
                    </div>

                    <div class="om-form-row">
                        <div class="om-form-group om-col-6">
                            <label class="om-label">Raio Minimo (km) *</label>
                            <input type="number" name="radius_min_km" id="zonaMin" class="om-input"
                                   value="0" min="0" max="100" step="0.5" required>
                        </div>
                        <div class="om-form-group om-col-6">
                            <label class="om-label">Raio Maximo (km) *</label>
                            <input type="number" name="radius_max_km" id="zonaMax" class="om-input"
                                   value="5" min="0.5" max="100" step="0.5" required>
                        </div>
                    </div>

                    <div class="zone-preview-bar om-mb-4">
                        <div class="zone-preview-fill" id="zonaPreviewFill"></div>
                        <span class="zone-preview-text" id="zonaPreviewText">0 - 5 km</span>
                    </div>

                    <div class="om-form-group">
                        <label class="om-label">Taxa de Entrega (R$) *</label>
                        <input type="number" name="fee" id="zonaFee" class="om-input"
                               value="5.00" min="0" max="999" step="0.50" required>
                        <small class="om-text-muted">Use 0 para entrega gratis</small>
                    </div>

                    <div class="om-form-group">
                        <label class="om-label">Tempo Estimado *</label>
                        <input type="text" name="estimated_time" id="zonaTime" class="om-input"
                               placeholder="Ex: 20-30 min" value="30-45 min" required maxlength="50">
                    </div>

                    <div id="zonaValidationError" class="om-alert om-alert-error" style="display: none;">
                        <div class="om-alert-content">
                            <div class="om-alert-message" id="zonaValidationMsg"></div>
                        </div>
                    </div>
                </div>

                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModalZona()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-primary" id="zonaSaveBtn">
                        <i class="lucide-save"></i> Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Configuracao Rapida -->
    <div id="modalQuickSetup" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModalQuickSetup()"></div>
        <div class="om-modal-content">
            <div class="om-modal-header">
                <h3 class="om-modal-title">
                    <i class="lucide-zap"></i> Configuracao Rapida
                </h3>
                <button class="om-modal-close" onclick="fecharModalQuickSetup()">
                    <i class="lucide-x"></i>
                </button>
            </div>
            <div class="om-modal-body">
                <?php if (!empty($zonas)): ?>
                <div class="om-alert om-alert-warning om-mb-4">
                    <div class="om-alert-content">
                        <div class="om-alert-message">
                            <strong>Atencao:</strong> Aplicar um template substituira todas as zonas existentes.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="quick-setup-grid">
                    <!-- Padrao -->
                    <div class="quick-setup-card">
                        <div class="quick-setup-header">
                            <h4>Padrao</h4>
                            <span class="om-badge om-badge-primary">Recomendado</span>
                        </div>
                        <p class="om-text-muted om-text-sm">Ideal para a maioria das lojas</p>
                        <div class="quick-setup-zones">
                            <div class="quick-zone"><span class="quick-zone-range">0-2 km</span><span class="quick-zone-fee om-text-success">Gratis</span></div>
                            <div class="quick-zone"><span class="quick-zone-range">2-5 km</span><span class="quick-zone-fee">R$ 5,00</span></div>
                            <div class="quick-zone"><span class="quick-zone-range">5-8 km</span><span class="quick-zone-fee">R$ 8,00</span></div>
                            <div class="quick-zone"><span class="quick-zone-range">8-12 km</span><span class="quick-zone-fee">R$ 12,00</span></div>
                        </div>
                        <form method="POST" onsubmit="return confirm('Substituir todas as zonas pelo template Padrao?')">
                            <input type="hidden" name="action" value="quick_setup">
                            <input type="hidden" name="template" value="padrao">
                            <button type="submit" class="om-btn om-btn-primary om-btn-block om-mt-3">
                                Aplicar Padrao
                            </button>
                        </form>
                    </div>

                    <!-- Compacto -->
                    <div class="quick-setup-card">
                        <div class="quick-setup-header">
                            <h4>Compacto</h4>
                            <span class="om-badge om-badge-neutral">Simples</span>
                        </div>
                        <p class="om-text-muted om-text-sm">3 zonas, facil de gerenciar</p>
                        <div class="quick-setup-zones">
                            <div class="quick-zone"><span class="quick-zone-range">0-3 km</span><span class="quick-zone-fee">R$ 3,00</span></div>
                            <div class="quick-zone"><span class="quick-zone-range">3-6 km</span><span class="quick-zone-fee">R$ 6,00</span></div>
                            <div class="quick-zone"><span class="quick-zone-range">6-10 km</span><span class="quick-zone-fee">R$ 10,00</span></div>
                        </div>
                        <form method="POST" onsubmit="return confirm('Substituir todas as zonas pelo template Compacto?')">
                            <input type="hidden" name="action" value="quick_setup">
                            <input type="hidden" name="template" value="compacto">
                            <button type="submit" class="om-btn om-btn-outline om-btn-block om-mt-3">
                                Aplicar Compacto
                            </button>
                        </form>
                    </div>

                    <!-- Premium -->
                    <div class="quick-setup-card">
                        <div class="quick-setup-header">
                            <h4>Premium</h4>
                            <span class="om-badge om-badge-warning">Detalhado</span>
                        </div>
                        <p class="om-text-muted om-text-sm">5 zonas com entrega gratis perto</p>
                        <div class="quick-setup-zones">
                            <div class="quick-zone"><span class="quick-zone-range">0-1 km</span><span class="quick-zone-fee om-text-success">Gratis</span></div>
                            <div class="quick-zone"><span class="quick-zone-range">1-3 km</span><span class="quick-zone-fee">R$ 4,00</span></div>
                            <div class="quick-zone"><span class="quick-zone-range">3-5 km</span><span class="quick-zone-fee">R$ 7,00</span></div>
                            <div class="quick-zone"><span class="quick-zone-range">5-8 km</span><span class="quick-zone-fee">R$ 10,00</span></div>
                            <div class="quick-zone"><span class="quick-zone-range">8-12 km</span><span class="quick-zone-fee">R$ 15,00</span></div>
                        </div>
                        <form method="POST" onsubmit="return confirm('Substituir todas as zonas pelo template Premium?')">
                            <input type="hidden" name="action" value="quick_setup">
                            <input type="hidden" name="template" value="premium">
                            <button type="submit" class="om-btn om-btn-outline om-btn-block om-mt-3">
                                Aplicar Premium
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="om-modal-footer">
                <button type="button" class="om-btn om-btn-outline" onclick="fecharModalQuickSetup()">Fechar</button>
            </div>
        </div>
    </div>

    <style>
    /* ─── Visual Distance Map ──────────────────────────────────────────────── */
    .zones-map-container {
        display: flex;
        justify-content: center;
        align-items: center;
        padding: var(--om-space-6) var(--om-space-4);
        overflow: hidden;
    }
    .zones-map {
        position: relative;
        width: 320px;
        height: 320px;
        display: flex;
        justify-content: center;
        align-items: center;
    }
    .zone-ring {
        position: absolute;
        border-radius: 50%;
        border: 2px solid;
        display: flex;
        justify-content: center;
        align-items: flex-start;
        padding-top: 8%;
    }
    .zone-ring-label {
        position: absolute;
        top: 8%;
        left: 50%;
        transform: translateX(-50%);
        text-align: center;
        font-size: 0.7rem;
        font-weight: 600;
        line-height: 1.3;
        white-space: nowrap;
        pointer-events: none;
    }
    .zone-ring-name {
        display: block;
        font-size: 0.75rem;
    }
    .zone-ring-fee {
        display: block;
        font-size: 0.85rem;
        font-weight: 700;
    }
    .zone-ring-km {
        display: block;
        font-size: 0.65rem;
        opacity: 0.7;
    }
    .zone-center {
        position: absolute;
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: var(--om-primary);
        color: white;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        font-size: 0.6rem;
        font-weight: 600;
        z-index: 10;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
    .zone-center i {
        font-size: 1.1rem;
        margin-bottom: 1px;
    }

    /* ─── Table Styles ─────────────────────────────────────────────────────── */
    .zone-color-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        display: inline-block;
        flex-shrink: 0;
    }
    .zona-inativa {
        opacity: 0.5;
    }
    .om-text-success {
        color: var(--om-success);
    }
    .om-text-error {
        color: var(--om-error);
    }
    .om-inline {
        display: inline;
    }

    /* ─── Zone Preview Bar ─────────────────────────────────────────────────── */
    .zone-preview-bar {
        height: 8px;
        background: var(--om-gray-100);
        border-radius: 4px;
        position: relative;
        overflow: hidden;
    }
    .zone-preview-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--om-success), var(--om-warning), var(--om-error));
        border-radius: 4px;
        transition: width 0.3s;
        width: 50%;
    }
    .zone-preview-text {
        position: absolute;
        top: -20px;
        right: 0;
        font-size: 0.75rem;
        color: var(--om-gray-500);
        font-weight: 500;
    }

    /* ─── Quick Setup ──────────────────────────────────────────────────────── */
    .quick-setup-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: var(--om-space-4);
    }
    .quick-setup-card {
        border: 1px solid var(--om-gray-200);
        border-radius: var(--om-radius-lg);
        padding: var(--om-space-4);
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .quick-setup-card:hover {
        border-color: var(--om-primary-300);
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    .quick-setup-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: var(--om-space-2);
    }
    .quick-setup-header h4 {
        font-size: 1.1rem;
        font-weight: 600;
        margin: 0;
    }
    .quick-setup-zones {
        display: flex;
        flex-direction: column;
        gap: 4px;
        margin-top: var(--om-space-3);
    }
    .quick-zone {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: var(--om-space-2) var(--om-space-3);
        background: var(--om-gray-50);
        border-radius: var(--om-radius-sm);
        font-size: 0.85rem;
    }
    .quick-zone-range {
        color: var(--om-gray-600);
    }
    .quick-zone-fee {
        font-weight: 600;
    }

    /* ─── Form ─────────────────────────────────────────────────────────────── */
    .om-form-row {
        display: flex;
        gap: var(--om-space-4);
    }
    .om-col-6 {
        flex: 1;
    }
    .om-btn-block {
        width: 100%;
    }

    /* ─── Grid ─────────────────────────────────────────────────────────────── */
    .om-grid {
        display: grid;
        gap: var(--om-space-4);
    }
    .om-grid-4 {
        grid-template-columns: repeat(4, 1fr);
    }

    /* ─── Responsive ───────────────────────────────────────────────────────── */
    @media (max-width: 1024px) {
        .om-grid-4 {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    @media (max-width: 768px) {
        .om-grid-4 {
            grid-template-columns: 1fr;
        }
        .quick-setup-grid {
            grid-template-columns: 1fr;
        }
        .zones-map {
            width: 260px;
            height: 260px;
        }
        .om-form-row {
            flex-direction: column;
            gap: 0;
        }
    }
    </style>

    <script>
    // ─── Modal Zona ──────────────────────────────────────────────────────────
    function abrirModalZona() {
        document.getElementById('modalZonaTitle').textContent = 'Adicionar Zona';
        document.getElementById('zonaAction').value = 'add_zone';
        document.getElementById('zonaId').value = '';
        document.getElementById('zonaLabel').value = '';
        document.getElementById('zonaMin').value = '0';
        document.getElementById('zonaMax').value = '5';
        document.getElementById('zonaFee').value = '5.00';
        document.getElementById('zonaTime').value = '30-45 min';
        document.getElementById('zonaSaveBtn').innerHTML = '<i class="lucide-save"></i> Salvar';
        hideValidationError();
        updatePreview();
        document.getElementById('modalZona').classList.add('open');
        setTimeout(() => document.getElementById('zonaLabel').focus(), 200);
    }

    function editarZona(zona) {
        document.getElementById('modalZonaTitle').textContent = 'Editar Zona';
        document.getElementById('zonaAction').value = 'update_zone';
        document.getElementById('zonaId').value = zona.id;
        document.getElementById('zonaLabel').value = zona.label;
        document.getElementById('zonaMin').value = parseFloat(zona.radius_min_km);
        document.getElementById('zonaMax').value = parseFloat(zona.radius_max_km);
        document.getElementById('zonaFee').value = parseFloat(zona.fee).toFixed(2);
        document.getElementById('zonaTime').value = zona.estimated_time;
        document.getElementById('zonaSaveBtn').innerHTML = '<i class="lucide-save"></i> Atualizar';
        hideValidationError();
        updatePreview();
        document.getElementById('modalZona').classList.add('open');
        setTimeout(() => document.getElementById('zonaLabel').focus(), 200);
    }

    function fecharModalZona() {
        document.getElementById('modalZona').classList.remove('open');
    }

    function validarZona() {
        var min = parseFloat(document.getElementById('zonaMin').value);
        var max = parseFloat(document.getElementById('zonaMax').value);
        var fee = parseFloat(document.getElementById('zonaFee').value);
        var label = document.getElementById('zonaLabel').value.trim();

        if (!label) {
            showValidationError('Informe o nome da zona');
            return false;
        }
        if (isNaN(min) || isNaN(max) || max <= min) {
            showValidationError('Raio maximo deve ser maior que o minimo');
            return false;
        }
        if (min < 0) {
            showValidationError('Raio minimo nao pode ser negativo');
            return false;
        }
        if (isNaN(fee) || fee < 0) {
            showValidationError('Taxa nao pode ser negativa');
            return false;
        }
        return true;
    }

    function showValidationError(msg) {
        document.getElementById('zonaValidationError').style.display = 'block';
        document.getElementById('zonaValidationMsg').textContent = msg;
    }

    function hideValidationError() {
        document.getElementById('zonaValidationError').style.display = 'none';
    }

    function updatePreview() {
        var min = parseFloat(document.getElementById('zonaMin').value) || 0;
        var max = parseFloat(document.getElementById('zonaMax').value) || 5;
        var pct = Math.min((max / 15) * 100, 100);
        document.getElementById('zonaPreviewFill').style.width = pct + '%';
        document.getElementById('zonaPreviewText').textContent = min.toFixed(1) + ' - ' + max.toFixed(1) + ' km';
    }

    // Listen for input changes on radius fields
    document.getElementById('zonaMin').addEventListener('input', updatePreview);
    document.getElementById('zonaMax').addEventListener('input', updatePreview);

    // ─── Modal Quick Setup ───────────────────────────────────────────────────
    function abrirModalQuickSetup() {
        document.getElementById('modalQuickSetup').classList.add('open');
    }

    function fecharModalQuickSetup() {
        document.getElementById('modalQuickSetup').classList.remove('open');
    }

    // ─── Keyboard ────────────────────────────────────────────────────────────
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            fecharModalZona();
            fecharModalQuickSetup();
        }
    });
    </script>
</body>
</html>
