<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * PAINEL DO MERCADO - Promoções Agendadas
 * Sistema inteligente de promoções com data início/fim
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

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $promo_id = intval($_POST['promo_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $product_id = intval($_POST['product_id'] ?? 0) ?: null;
        $category_id = intval($_POST['category_id'] ?? 0) ?: null;
        $discount_type = $_POST['discount_type'] ?? 'percent';
        $discount_value = floatval(str_replace(['.', ','], ['', '.'], $_POST['discount_value'] ?? 0));
        $min_order = floatval(str_replace(['.', ','], ['', '.'], $_POST['min_order'] ?? 0));
        $start_date = $_POST['start_date'] ?? '';
        $start_time = $_POST['start_time'] ?? '00:00';
        $end_date = $_POST['end_date'] ?? '';
        $end_time = $_POST['end_time'] ?? '23:59';
        $max_uses = intval($_POST['max_uses'] ?? 0) ?: null;

        if ($title && $discount_value > 0 && $start_date && $end_date) {
            $start_datetime = "$start_date $start_time:00";
            $end_datetime = "$end_date $end_time:59";

            if ($action === 'create') {
                $stmt = $db->prepare("
                    INSERT INTO om_partner_promotions
                    (partner_id, product_id, category_id, title, description,
                     discount_type, discount_value, min_order_value, max_uses,
                     start_date, end_date, active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $mercado_id, $product_id, $category_id, $title, $description,
                    $discount_type, $discount_value, $min_order, $max_uses,
                    $start_datetime, $end_datetime
                ]);
                $message = "Promoção '$title' criada com sucesso!";
            } else {
                $stmt = $db->prepare("
                    UPDATE om_partner_promotions SET
                        product_id = ?, category_id = ?, title = ?, description = ?,
                        discount_type = ?, discount_value = ?, min_order_value = ?,
                        max_uses = ?, start_date = ?, end_date = ?
                    WHERE id = ? AND partner_id = ?
                ");
                $stmt->execute([
                    $product_id, $category_id, $title, $description,
                    $discount_type, $discount_value, $min_order, $max_uses,
                    $start_datetime, $end_datetime,
                    $promo_id, $mercado_id
                ]);
                $message = "Promoção atualizada!";
            }
        } else {
            $error = 'Preencha todos os campos obrigatórios';
        }
    }

    if ($action === 'toggle') {
        $promo_id = intval($_POST['promo_id'] ?? 0);
        $stmt = $db->prepare("UPDATE om_partner_promotions SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ? AND partner_id = ?");
        $stmt->execute([$promo_id, $mercado_id]);
        $message = 'Status atualizado';
    }

    if ($action === 'delete') {
        $promo_id = intval($_POST['promo_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM om_partner_promotions WHERE id = ? AND partner_id = ?");
        $stmt->execute([$promo_id, $mercado_id]);
        $message = 'Promoção removida';
    }
}

// Buscar promoções
$stmt = $db->prepare("
    SELECT p.*, pr.name as product_name, c.name as category_name
    FROM om_partner_promotions p
    LEFT JOIN om_market_products pr ON p.product_id = pr.product_id
    LEFT JOIN om_market_categories c ON p.category_id = c.category_id
    WHERE p.partner_id = ?
    ORDER BY
        CASE
            WHEN NOW() BETWEEN p.start_date AND p.end_date THEN 0
            WHEN p.start_date > NOW() THEN 1
            ELSE 2
        END,
        p.start_date DESC
");
$stmt->execute([$mercado_id]);
$promocoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$ativas = 0;
$agendadas = 0;
$encerradas = 0;
$agora = date('Y-m-d H:i:s');

foreach ($promocoes as $p) {
    if ($p['start_date'] <= $agora && $p['end_date'] >= $agora && $p['active']) {
        $ativas++;
    } elseif ($p['start_date'] > $agora && $p['active']) {
        $agendadas++;
    } else {
        $encerradas++;
    }
}

// Buscar produtos para select
$stmt = $db->prepare("SELECT product_id, name FROM om_market_products WHERE partner_id = ? AND status = 1 ORDER BY name LIMIT 200");
$stmt->execute([$mercado_id]);
$produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar categorias
$stmt = $db->query("SELECT category_id, name FROM om_market_categories WHERE status = 1 ORDER BY name");
$categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Promoções - Painel do Mercado</title>
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
            <a href="index.php" class="om-sidebar-link"><i class="lucide-layout-dashboard"></i><span>Dashboard</span></a>
            <a href="pedidos.php" class="om-sidebar-link"><i class="lucide-shopping-bag"></i><span>Pedidos</span></a>
            <a href="produtos.php" class="om-sidebar-link"><i class="lucide-package"></i><span>Produtos</span></a>
                <a href="cardapio-ia.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path><path d="M12 18v4"></path><path d="M8 22h8"></path></svg>
                    <span class="om-sidebar-link-text">Cardapio IA</span>
                    <span style="background:#8b5cf6;color:#fff;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:700;">NOVO</span>
                </a>
            <a href="promocoes.php" class="om-sidebar-link active"><i class="lucide-percent"></i><span>Promoções</span></a>
            <a href="categorias.php" class="om-sidebar-link"><i class="lucide-tags"></i><span>Categorias</span></a>
            <a href="faturamento.php" class="om-sidebar-link"><i class="lucide-bar-chart-3"></i><span>Faturamento</span></a>
            <a href="chat.php" class="om-sidebar-link"><i class="lucide-message-circle"></i><span>Mensagens</span></a>
            <a href="avaliacoes.php" class="om-sidebar-link"><i class="lucide-star"></i><span>Avaliacoes</span></a>
            <a href="horarios.php" class="om-sidebar-link"><i class="lucide-clock"></i><span>Horários</span></a>
            <a href="perfil.php" class="om-sidebar-link"><i class="lucide-settings"></i><span>Configurações</span></a>
        </nav>

        <div class="om-sidebar-footer">
            <a href="logout.php" class="om-sidebar-link"><i class="lucide-log-out"></i><span>Sair</span></a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="om-main-content">
        <header class="om-topbar">
            <button class="om-sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                <i class="lucide-menu"></i>
            </button>
            <h1 class="om-topbar-title">Promoções</h1>
            <div class="om-topbar-actions">
                <button class="om-btn om-btn-primary" onclick="abrirModal()">
                    <i class="lucide-plus"></i> Nova Promoção
                </button>
                <div class="om-user-menu">
                    <span class="om-user-name"><?= htmlspecialchars($mercado_nome) ?></span>
                    <div class="om-avatar om-avatar-sm"><?= strtoupper(substr($mercado_nome, 0, 2)) ?></div>
                </div>
            </div>
        </header>

        <div class="om-page-content">
            <?php if ($message): ?>
            <div class="om-alert om-alert-success om-mb-4">
                <i class="lucide-check-circle"></i>
                <div class="om-alert-content"><div class="om-alert-message"><?= htmlspecialchars($message) ?></div></div>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="om-alert om-alert-error om-mb-4">
                <i class="lucide-alert-circle"></i>
                <div class="om-alert-content"><div class="om-alert-message"><?= htmlspecialchars($error) ?></div></div>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="om-stats-grid om-mb-6">
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-success-light"><i class="lucide-zap"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $ativas ?></span>
                        <span class="om-stat-label">Ativas Agora</span>
                    </div>
                </div>
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-info-light"><i class="lucide-calendar-clock"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $agendadas ?></span>
                        <span class="om-stat-label">Agendadas</span>
                    </div>
                </div>
                <div class="om-stat-card">
                    <div class="om-stat-icon om-bg-gray-light"><i class="lucide-calendar-x"></i></div>
                    <div class="om-stat-content">
                        <span class="om-stat-value"><?= $encerradas ?></span>
                        <span class="om-stat-label">Encerradas</span>
                    </div>
                </div>
            </div>

            <!-- Lista de Promoções -->
            <div class="om-card">
                <div class="om-card-header">
                    <h3 class="om-card-title">Suas Promoções</h3>
                </div>

                <?php if (empty($promocoes)): ?>
                <div class="om-card-body om-text-center om-py-8">
                    <i class="lucide-percent om-text-4xl om-text-muted"></i>
                    <p class="om-mt-2">Nenhuma promoção criada</p>
                    <button class="om-btn om-btn-primary om-mt-4" onclick="abrirModal()">
                        <i class="lucide-plus"></i> Criar Primeira Promoção
                    </button>
                </div>
                <?php else: ?>
                <div class="om-promo-grid">
                    <?php foreach ($promocoes as $promo):
                        $is_ativa = $promo['start_date'] <= $agora && $promo['end_date'] >= $agora && $promo['active'];
                        $is_agendada = $promo['start_date'] > $agora && $promo['active'];
                        $is_encerrada = $promo['end_date'] < $agora || !$promo['active'];
                    ?>
                    <div class="om-promo-card <?= $is_ativa ? 'ativa' : ($is_agendada ? 'agendada' : 'encerrada') ?>">
                        <div class="om-promo-header">
                            <div class="om-promo-badge">
                                <?php if ($is_ativa): ?>
                                <span class="om-badge om-badge-success"><i class="lucide-zap"></i> Ativa</span>
                                <?php elseif ($is_agendada): ?>
                                <span class="om-badge om-badge-info"><i class="lucide-clock"></i> Agendada</span>
                                <?php else: ?>
                                <span class="om-badge om-badge-neutral">Encerrada</span>
                                <?php endif; ?>
                            </div>
                            <div class="om-promo-discount">
                                <?php if ($promo['discount_type'] === 'percent'): ?>
                                <span class="om-promo-value"><?= intval($promo['discount_value']) ?>%</span>
                                <span class="om-promo-label">OFF</span>
                                <?php else: ?>
                                <span class="om-promo-value">R$ <?= number_format($promo['discount_value'], 0, ',', '.') ?></span>
                                <span class="om-promo-label">OFF</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="om-promo-body">
                            <h4 class="om-promo-title"><?= htmlspecialchars($promo['title']) ?></h4>
                            <?php if ($promo['product_name']): ?>
                            <p class="om-text-sm om-text-muted">Produto: <?= htmlspecialchars($promo['product_name']) ?></p>
                            <?php elseif ($promo['category_name']): ?>
                            <p class="om-text-sm om-text-muted">Categoria: <?= htmlspecialchars($promo['category_name']) ?></p>
                            <?php else: ?>
                            <p class="om-text-sm om-text-muted">Toda a loja</p>
                            <?php endif; ?>

                            <div class="om-promo-dates">
                                <i class="lucide-calendar"></i>
                                <?= date('d/m H:i', strtotime($promo['start_date'])) ?>
                                <i class="lucide-arrow-right"></i>
                                <?= date('d/m H:i', strtotime($promo['end_date'])) ?>
                            </div>

                            <?php if ($promo['uses_count'] > 0): ?>
                            <div class="om-promo-uses">
                                <i class="lucide-users"></i>
                                <?= $promo['uses_count'] ?> usos
                                <?= $promo['max_uses'] ? "de {$promo['max_uses']}" : '' ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="om-promo-footer">
                            <form method="POST" class="om-inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="promo_id" value="<?= $promo['id'] ?>">
                                <button type="submit" class="om-btn om-btn-sm om-btn-ghost" title="<?= $promo['active'] ? 'Desativar' : 'Ativar' ?>">
                                    <i class="lucide-<?= $promo['active'] ? 'pause' : 'play' ?>"></i>
                                </button>
                            </form>
                            <button class="om-btn om-btn-sm om-btn-ghost" onclick="editarPromo(<?= htmlspecialchars(json_encode($promo)) ?>)">
                                <i class="lucide-pencil"></i>
                            </button>
                            <form method="POST" class="om-inline" onsubmit="return confirm('Remover esta promoção?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="promo_id" value="<?= $promo['id'] ?>">
                                <button type="submit" class="om-btn om-btn-sm om-btn-ghost om-text-error">
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
    </main>

    <!-- Modal Promoção -->
    <div id="modalPromo" class="om-modal">
        <div class="om-modal-backdrop" onclick="fecharModal()"></div>
        <div class="om-modal-content om-modal-lg">
            <div class="om-modal-header">
                <h3 class="om-modal-title" id="modalTitle">Nova Promoção</h3>
                <button class="om-modal-close" onclick="fecharModal()"><i class="lucide-x"></i></button>
            </div>
            <form method="POST" id="formPromo">
                <input type="hidden" name="action" id="promoAction" value="create">
                <input type="hidden" name="promo_id" id="promoId" value="">

                <div class="om-modal-body">
                    <div class="om-form-row">
                        <div class="om-form-group om-col-md-8">
                            <label class="om-label">Nome da Promoção *</label>
                            <input type="text" name="title" id="promoTitle" class="om-input" required placeholder="Ex: Black Friday, Semana do Cliente">
                        </div>
                        <div class="om-form-group om-col-md-4">
                            <label class="om-label">Tipo de Desconto</label>
                            <select name="discount_type" id="promoType" class="om-select">
                                <option value="percent">Porcentagem (%)</option>
                                <option value="fixed">Valor Fixo (R$)</option>
                            </select>
                        </div>
                    </div>

                    <div class="om-form-row">
                        <div class="om-form-group om-col-md-4">
                            <label class="om-label">Valor do Desconto *</label>
                            <div class="om-input-group">
                                <span class="om-input-prefix" id="discountPrefix">%</span>
                                <input type="number" name="discount_value" id="promoValue" class="om-input" required min="1" step="0.01">
                            </div>
                        </div>
                        <div class="om-form-group om-col-md-4">
                            <label class="om-label">Pedido Mínimo</label>
                            <div class="om-input-group">
                                <span class="om-input-prefix">R$</span>
                                <input type="text" name="min_order" id="promoMinOrder" class="om-input" placeholder="0,00">
                            </div>
                        </div>
                        <div class="om-form-group om-col-md-4">
                            <label class="om-label">Limite de Usos</label>
                            <input type="number" name="max_uses" id="promoMaxUses" class="om-input" min="0" placeholder="Ilimitado">
                        </div>
                    </div>

                    <div class="om-form-row">
                        <div class="om-form-group om-col-md-6">
                            <label class="om-label">Aplicar em</label>
                            <select id="promoApplyTo" class="om-select" onchange="toggleApplyTo()">
                                <option value="all">Toda a loja</option>
                                <option value="product">Produto específico</option>
                                <option value="category">Categoria</option>
                            </select>
                        </div>
                        <div class="om-form-group om-col-md-6" id="selectProduto" style="display:none">
                            <label class="om-label">Produto</label>
                            <select name="product_id" id="promoProduct" class="om-select">
                                <option value="">Selecione...</option>
                                <?php foreach ($produtos as $p): ?>
                                <option value="<?= $p['product_id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="om-form-group om-col-md-6" id="selectCategoria" style="display:none">
                            <label class="om-label">Categoria</label>
                            <select name="category_id" id="promoCategory" class="om-select">
                                <option value="">Selecione...</option>
                                <?php foreach ($categorias as $c): ?>
                                <option value="<?= $c['category_id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="om-divider om-my-4"><span>Período da Promoção</span></div>

                    <div class="om-form-row">
                        <div class="om-form-group om-col-md-3">
                            <label class="om-label">Data Início *</label>
                            <input type="date" name="start_date" id="promoStartDate" class="om-input" required>
                        </div>
                        <div class="om-form-group om-col-md-3">
                            <label class="om-label">Hora Início</label>
                            <input type="time" name="start_time" id="promoStartTime" class="om-input" value="00:00">
                        </div>
                        <div class="om-form-group om-col-md-3">
                            <label class="om-label">Data Fim *</label>
                            <input type="date" name="end_date" id="promoEndDate" class="om-input" required>
                        </div>
                        <div class="om-form-group om-col-md-3">
                            <label class="om-label">Hora Fim</label>
                            <input type="time" name="end_time" id="promoEndTime" class="om-input" value="23:59">
                        </div>
                    </div>

                    <div class="om-form-group">
                        <label class="om-label">Descrição (opcional)</label>
                        <textarea name="description" id="promoDesc" class="om-input" rows="2" placeholder="Detalhes da promoção..."></textarea>
                    </div>
                </div>

                <div class="om-modal-footer">
                    <button type="button" class="om-btn om-btn-outline" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="om-btn om-btn-primary"><i class="lucide-check"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <style>
    .om-promo-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: var(--om-space-4);
        padding: var(--om-space-4);
    }
    .om-promo-card {
        background: white;
        border-radius: var(--om-radius-lg);
        border: 1px solid var(--om-gray-200);
        overflow: hidden;
        transition: all 0.2s;
    }
    .om-promo-card:hover {
        box-shadow: var(--om-shadow-md);
        transform: translateY(-2px);
    }
    .om-promo-card.ativa {
        border-color: var(--om-success);
    }
    .om-promo-card.agendada {
        border-color: var(--om-info);
    }
    .om-promo-header {
        background: linear-gradient(135deg, var(--om-primary), var(--om-primary-dark));
        padding: var(--om-space-4);
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
    }
    .om-promo-card.ativa .om-promo-header {
        background: linear-gradient(135deg, var(--om-success), #1a7f4e);
    }
    .om-promo-card.agendada .om-promo-header {
        background: linear-gradient(135deg, var(--om-info), #1a5fa0);
    }
    .om-promo-card.encerrada .om-promo-header {
        background: linear-gradient(135deg, var(--om-gray-400), var(--om-gray-500));
    }
    .om-promo-discount {
        text-align: right;
        color: white;
    }
    .om-promo-value {
        display: block;
        font-size: 2rem;
        font-weight: 700;
        line-height: 1;
    }
    .om-promo-label {
        font-size: 0.875rem;
        opacity: 0.9;
    }
    .om-promo-body {
        padding: var(--om-space-4);
    }
    .om-promo-title {
        font-size: 1.125rem;
        font-weight: 600;
        margin-bottom: var(--om-space-2);
    }
    .om-promo-dates {
        display: flex;
        align-items: center;
        gap: var(--om-space-2);
        font-size: 0.875rem;
        color: var(--om-gray-600);
        margin-top: var(--om-space-3);
    }
    .om-promo-dates i {
        font-size: 14px;
    }
    .om-promo-uses {
        display: flex;
        align-items: center;
        gap: var(--om-space-1);
        font-size: 0.75rem;
        color: var(--om-gray-500);
        margin-top: var(--om-space-2);
    }
    .om-promo-footer {
        display: flex;
        justify-content: flex-end;
        gap: var(--om-space-1);
        padding: var(--om-space-3);
        border-top: 1px solid var(--om-gray-100);
        background: var(--om-gray-50);
    }
    .om-inline { display: inline; }
    .om-divider {
        display: flex;
        align-items: center;
        text-align: center;
        color: var(--om-gray-500);
        font-size: 0.875rem;
    }
    .om-divider::before, .om-divider::after {
        content: '';
        flex: 1;
        border-bottom: 1px solid var(--om-gray-200);
    }
    .om-divider span { padding: 0 12px; }
    .om-bg-gray-light { background: var(--om-gray-100); }
    </style>

    <script>
    function abrirModal() {
        document.getElementById('modalTitle').textContent = 'Nova Promoção';
        document.getElementById('promoAction').value = 'create';
        document.getElementById('formPromo').reset();
        document.getElementById('promoId').value = '';
        document.getElementById('promoStartDate').value = new Date().toISOString().split('T')[0];
        document.getElementById('promoEndDate').value = new Date(Date.now() + 7*24*60*60*1000).toISOString().split('T')[0];
        toggleApplyTo();
        document.getElementById('modalPromo').classList.add('open');
    }

    function fecharModal() {
        document.getElementById('modalPromo').classList.remove('open');
    }

    function editarPromo(promo) {
        document.getElementById('modalTitle').textContent = 'Editar Promoção';
        document.getElementById('promoAction').value = 'update';
        document.getElementById('promoId').value = promo.id;
        document.getElementById('promoTitle').value = promo.title;
        document.getElementById('promoType').value = promo.discount_type;
        document.getElementById('promoValue').value = promo.discount_value;
        document.getElementById('promoMinOrder').value = promo.min_order_value || '';
        document.getElementById('promoMaxUses').value = promo.max_uses || '';
        document.getElementById('promoDesc').value = promo.description || '';

        const startDate = promo.start_date.split(' ');
        document.getElementById('promoStartDate').value = startDate[0];
        document.getElementById('promoStartTime').value = startDate[1] ? startDate[1].substring(0, 5) : '00:00';

        const endDate = promo.end_date.split(' ');
        document.getElementById('promoEndDate').value = endDate[0];
        document.getElementById('promoEndTime').value = endDate[1] ? endDate[1].substring(0, 5) : '23:59';

        if (promo.product_id) {
            document.getElementById('promoApplyTo').value = 'product';
            document.getElementById('promoProduct').value = promo.product_id;
        } else if (promo.category_id) {
            document.getElementById('promoApplyTo').value = 'category';
            document.getElementById('promoCategory').value = promo.category_id;
        } else {
            document.getElementById('promoApplyTo').value = 'all';
        }

        toggleApplyTo();
        updateDiscountPrefix();
        document.getElementById('modalPromo').classList.add('open');
    }

    function toggleApplyTo() {
        const val = document.getElementById('promoApplyTo').value;
        document.getElementById('selectProduto').style.display = val === 'product' ? 'block' : 'none';
        document.getElementById('selectCategoria').style.display = val === 'category' ? 'block' : 'none';
    }

    function updateDiscountPrefix() {
        const type = document.getElementById('promoType').value;
        document.getElementById('discountPrefix').textContent = type === 'percent' ? '%' : 'R$';
    }

    document.getElementById('promoType').addEventListener('change', updateDiscountPrefix);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharModal(); });
    </script>
</body>
</html>
