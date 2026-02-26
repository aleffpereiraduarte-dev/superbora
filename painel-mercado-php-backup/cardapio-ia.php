<?php
/**
 * Cardapio Inteligente - IA extrai itens de fotos do cardapio
 * Similar ao iFood "Catalogos Inteligentes"
 */
session_start();
require_once dirname(__DIR__, 2) . '/database.php';

$db = getDB();

if (!isset($_SESSION['mercado_id'])) {
    header('Location: login.php');
    exit;
}

$mercado_id = $_SESSION['mercado_id'];

$stmt = $db->prepare("SELECT * FROM om_market_partners WHERE partner_id = ?");
$stmt->execute([$mercado_id]);
$mercado = $stmt->fetch();

if (!$mercado) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Contar produtos existentes
$stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_products WHERE partner_id = ? AND status = 1");
$stmtCount->execute([$mercado_id]);
$totalProdutos = (int)$stmtCount->fetchColumn();

// Categorias existentes
$stmtCat = $db->prepare("SELECT category_id, name FROM om_market_categories WHERE partner_id = ? AND status = 1 ORDER BY name");
$stmtCat->execute([$mercado_id]);
$categorias = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

// Pedidos em andamento (para badge)
$hoje = date('Y-m-d');
$stmtPed = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE partner_id = ? AND status IN ('pendente','aceito','coletando','em_entrega') AND DATE(date_added) = ?");
$stmtPed->execute([$mercado_id, $hoje]);
$pedidos_andamento = (int)$stmtPed->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cardapio Inteligente - <?= htmlspecialchars($mercado['name'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/frontend/src/styles/design-system.css">
    <link rel="stylesheet" href="/frontend/src/styles/components.css">
    <style>
        /* Upload Zone */
        .ia-upload-zone {
            border: 3px dashed #d1d5db;
            border-radius: 16px;
            padding: 48px 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #fafafa;
            position: relative;
        }
        .ia-upload-zone:hover, .ia-upload-zone.dragover {
            border-color: #8b5cf6;
            background: #f5f3ff;
        }
        .ia-upload-zone.has-files {
            border-color: #22c55e;
            background: #f0fdf4;
        }
        .ia-upload-icon {
            width: 80px; height: 80px;
            margin: 0 auto 16px;
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ia-upload-icon svg { width: 40px; height: 40px; color: white; }
        .ia-upload-title {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }
        .ia-upload-subtitle {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 16px;
        }
        .ia-upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 28px;
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .ia-upload-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.3);
        }
        .ia-upload-formats {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 12px;
        }

        /* Preview Grid */
        .ia-preview-grid {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 20px;
            justify-content: center;
        }
        .ia-preview-item {
            position: relative;
            width: 120px;
            height: 120px;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid #e5e7eb;
        }
        .ia-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .ia-preview-remove {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 24px;
            height: 24px;
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 50%;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Processing Animation */
        .ia-processing {
            display: none;
            text-align: center;
            padding: 48px 24px;
        }
        .ia-processing.active { display: block; }
        .ia-brain-animation {
            width: 120px; height: 120px;
            margin: 0 auto 24px;
            position: relative;
        }
        .ia-brain-core {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            border-radius: 50%;
            position: absolute;
            top: 20px; left: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: brainPulse 2s ease-in-out infinite;
        }
        .ia-brain-core svg { width: 40px; height: 40px; color: white; }
        .ia-brain-ring {
            width: 120px; height: 120px;
            border: 3px solid transparent;
            border-top-color: #8b5cf6;
            border-right-color: #6366f1;
            border-radius: 50%;
            position: absolute;
            top: 0; left: 0;
            animation: brainSpin 1.5s linear infinite;
        }
        @keyframes brainPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.08); }
        }
        @keyframes brainSpin {
            to { transform: rotate(360deg); }
        }
        .ia-processing-title {
            font-size: 22px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }
        .ia-processing-subtitle {
            font-size: 14px;
            color: #6b7280;
        }
        .ia-processing-steps {
            max-width: 320px;
            margin: 24px auto 0;
            text-align: left;
        }
        .ia-step {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            font-size: 14px;
            color: #9ca3af;
            transition: color 0.3s;
        }
        .ia-step.active { color: #6366f1; font-weight: 600; }
        .ia-step.done { color: #22c55e; }
        .ia-step-dot {
            width: 24px; height: 24px;
            border-radius: 50%;
            border: 2px solid #d1d5db;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 12px;
        }
        .ia-step.active .ia-step-dot {
            border-color: #6366f1;
            background: #ede9fe;
        }
        .ia-step.done .ia-step-dot {
            border-color: #22c55e;
            background: #dcfce7;
        }

        /* Results */
        .ia-results { display: none; }
        .ia-results.active { display: block; }
        .ia-results-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .ia-results-count {
            font-size: 14px;
            color: #6b7280;
        }
        .ia-results-count strong {
            color: #8b5cf6;
            font-size: 24px;
        }
        .ia-results-actions {
            display: flex;
            gap: 8px;
        }

        /* Item Card */
        .ia-item-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 16px;
        }
        .ia-item-card {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 16px;
            padding: 20px;
            transition: border-color 0.3s, box-shadow 0.3s;
            position: relative;
        }
        .ia-item-card.selected {
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        .ia-item-card.editing {
            border-color: #f59e0b;
        }
        .ia-item-check {
            position: absolute;
            top: 12px;
            right: 12px;
        }
        .ia-item-check input[type="checkbox"] {
            width: 22px;
            height: 22px;
            accent-color: #8b5cf6;
            cursor: pointer;
        }
        .ia-item-header {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }
        .ia-item-emoji {
            width: 48px; height: 48px;
            background: #f3f4f6;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }
        .ia-item-title-wrap { flex: 1; min-width: 0; padding-right: 32px; }
        .ia-item-name {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
            line-height: 1.3;
        }
        .ia-item-category {
            display: inline-flex;
            padding: 2px 10px;
            background: #ede9fe;
            color: #7c3aed;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .ia-item-desc {
            font-size: 13px;
            color: #6b7280;
            line-height: 1.5;
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .ia-item-ingredients {
            font-size: 12px;
            color: #9ca3af;
            margin-bottom: 12px;
        }
        .ia-item-ingredients strong { color: #6b7280; }
        .ia-item-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 1px solid #f3f4f6;
            padding-top: 12px;
        }
        .ia-item-price {
            font-size: 22px;
            font-weight: 800;
            color: #059669;
        }
        .ia-item-price.no-price {
            color: #f59e0b;
            font-size: 14px;
        }
        .ia-item-actions {
            display: flex;
            gap: 6px;
        }
        .ia-item-btn {
            padding: 6px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: white;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .ia-item-btn:hover {
            background: #f3f4f6;
        }
        .ia-item-btn.edit { color: #f59e0b; border-color: #f59e0b; }
        .ia-item-btn.edit:hover { background: #fffbeb; }

        /* Edit Mode */
        .ia-edit-form { display: none; }
        .ia-item-card.editing .ia-edit-form { display: block; }
        .ia-item-card.editing .ia-item-display { display: none; }
        .ia-edit-field {
            margin-bottom: 10px;
        }
        .ia-edit-field label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 3px;
            text-transform: uppercase;
        }
        .ia-edit-field input,
        .ia-edit-field textarea,
        .ia-edit-field select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        .ia-edit-field textarea { resize: vertical; min-height: 60px; }

        /* Sizes Tags */
        .ia-sizes { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
        .ia-size-tag {
            padding: 3px 8px;
            background: #f3f4f6;
            border-radius: 6px;
            font-size: 11px;
            color: #4b5563;
        }

        /* Observacoes tags */
        .ia-obs-tags { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 6px; }
        .ia-obs-tag {
            padding: 2px 8px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }

        /* Success state */
        .ia-success {
            display: none;
            text-align: center;
            padding: 48px 24px;
        }
        .ia-success.active { display: block; }
        .ia-success-icon {
            width: 100px; height: 100px;
            margin: 0 auto 20px;
            background: #dcfce7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .ia-success-icon svg { width: 50px; height: 50px; color: #22c55e; }

        /* How it works */
        .ia-how-it-works {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin: 24px 0;
        }
        .ia-how-step {
            text-align: center;
            padding: 20px 12px;
        }
        .ia-how-number {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            margin: 0 auto 12px;
        }
        .ia-how-title {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 4px;
            color: #1f2937;
        }
        .ia-how-desc {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.4;
        }

        /* Select All bar */
        .ia-select-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 20px;
            margin-bottom: 16px;
        }
        .ia-select-bar label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .ia-select-bar input[type="checkbox"] {
            width: 20px; height: 20px;
            accent-color: #8b5cf6;
        }

        /* Import progress */
        .ia-import-progress {
            display: none;
            text-align: center;
            padding: 24px;
        }
        .ia-import-progress.active { display: block; }
        .ia-progress-bar {
            width: 100%;
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin: 16px 0;
        }
        .ia-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #8b5cf6, #6366f1);
            border-radius: 4px;
            transition: width 0.5s;
            width: 0%;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .ia-item-grid {
                grid-template-columns: 1fr;
            }
            .ia-results-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .ia-how-it-works {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 480px) {
            .ia-upload-zone { padding: 32px 16px; }
            .ia-how-it-works { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="om-app-layout">
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
                <a href="index.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    <span class="om-sidebar-link-text">Dashboard</span>
                </a>
                <a href="pedidos.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg>
                    <span class="om-sidebar-link-text">Pedidos</span>
                    <?php if ($pedidos_andamento > 0): ?>
                    <span class="om-sidebar-link-badge"><?= $pedidos_andamento ?></span>
                    <?php endif; ?>
                </a>
                <a href="produtos.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
                    <span class="om-sidebar-link-text">Produtos</span>
                </a>
                <a href="cardapio-ia.php" class="om-sidebar-link active">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path><path d="M12 18v4"></path><path d="M8 22h8"></path></svg>
                    <span class="om-sidebar-link-text">Cardapio IA</span>
                    <span style="background:#8b5cf6;color:#fff;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:700;">NOVO</span>
                </a>
                <a href="categorias.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                    <span class="om-sidebar-link-text">Categorias</span>
                </a>
            </div>
            <div class="om-sidebar-section">
                <div class="om-sidebar-section-title">Financeiro</div>
                <a href="faturamento.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    <span class="om-sidebar-link-text">Faturamento</span>
                </a>
                <a href="repasses.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                    <span class="om-sidebar-link-text">Repasses</span>
                </a>
                <a href="avaliacoes.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                    <span class="om-sidebar-link-text">Avaliacoes</span>
                </a>
            </div>
            <div class="om-sidebar-section">
                <div class="om-sidebar-section-title">Configuracoes</div>
                <a href="perfil.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    <span class="om-sidebar-link-text">Perfil da Loja</span>
                </a>
                <a href="horarios.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span class="om-sidebar-link-text">Horarios</span>
                </a>
                <a href="equipe.php" class="om-sidebar-link">
                    <svg class="om-sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
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
                    <div class="om-sidebar-user-name"><?= htmlspecialchars($mercado['name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="om-sidebar-user-role">Mercado</div>
                </div>
            </div>
        </div>
    </aside>

    <div class="om-sidebar-overlay" id="sidebarOverlay"></div>

    <header class="om-topbar">
        <button class="om-topbar-toggle" id="sidebarToggle">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
        </button>
        <h1 class="om-topbar-title">
            Cardapio Inteligente
            <span style="background:linear-gradient(135deg,#8b5cf6,#6366f1);color:#fff;font-size:10px;padding:3px 10px;border-radius:20px;font-weight:700;margin-left:8px;">IA</span>
        </h1>
    </header>

    <main class="om-main">
        <div style="max-width:1100px;margin:0 auto;">

            <!-- How it works -->
            <div class="om-card om-mb-4" id="howItWorks">
                <div class="om-card-body">
                    <h3 style="text-align:center;font-size:18px;font-weight:700;margin-bottom:4px;">Digitalize seu cardapio em minutos</h3>
                    <p style="text-align:center;font-size:14px;color:#6b7280;margin-bottom:0;">A IA analisa suas fotos e extrai todos os itens automaticamente</p>
                    <div class="ia-how-it-works">
                        <div class="ia-how-step">
                            <div class="ia-how-number">1</div>
                            <div class="ia-how-title">Fotografe o Cardapio</div>
                            <div class="ia-how-desc">Tire fotos do cardapio impresso, do quadro, ou da tela do computador</div>
                        </div>
                        <div class="ia-how-step">
                            <div class="ia-how-number">2</div>
                            <div class="ia-how-title">IA Analisa</div>
                            <div class="ia-how-desc">Claude Vision le cada item, preco, descricao e ingredientes automaticamente</div>
                        </div>
                        <div class="ia-how-step">
                            <div class="ia-how-number">3</div>
                            <div class="ia-how-title">Revise e Edite</div>
                            <div class="ia-how-desc">Confira os dados extraidos, edite o que precisar e ajuste precos</div>
                        </div>
                        <div class="ia-how-step">
                            <div class="ia-how-number">4</div>
                            <div class="ia-how-title">Importe</div>
                            <div class="ia-how-desc">Com um clique, todos os itens sao adicionados ao seu catalogo</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upload Zone -->
            <div class="om-card om-mb-4" id="uploadCard">
                <div class="om-card-body">
                    <div class="ia-upload-zone" id="uploadZone">
                        <div class="ia-upload-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                        </div>
                        <div class="ia-upload-title">Envie fotos do seu cardapio</div>
                        <div class="ia-upload-subtitle">Arraste as imagens aqui ou clique para selecionar</div>
                        <button type="button" class="ia-upload-btn" onclick="document.getElementById('fileInput').click()">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                            Selecionar Imagens
                        </button>
                        <div class="ia-upload-formats">JPG, PNG, WebP - Ate 10MB por imagem - Maximo 10 fotos</div>
                        <input type="file" id="fileInput" multiple accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">
                        <div class="ia-preview-grid" id="previewGrid"></div>
                    </div>
                    <div style="text-align:center;margin-top:16px;display:none;" id="processBtn">
                        <button type="button" class="ia-upload-btn" onclick="processarCardapio()" style="padding:14px 40px;font-size:16px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path></svg>
                            Analisar com IA
                        </button>
                        <p style="font-size:12px;color:#9ca3af;margin-top:8px;">A analise leva de 10 a 60 segundos dependendo da quantidade de imagens</p>
                    </div>
                </div>
            </div>

            <!-- Processing Animation -->
            <div class="om-card om-mb-4">
                <div class="om-card-body">
                    <div class="ia-processing" id="processing">
                        <div class="ia-brain-animation">
                            <div class="ia-brain-ring"></div>
                            <div class="ia-brain-core">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.1-.9 2-2 2h-4a2 2 0 0 1-2-2 4 4 0 0 1 4-4z"></path><path d="M8.5 8a6.5 6.5 0 1 0 7 0"></path></svg>
                            </div>
                        </div>
                        <div class="ia-processing-title">Claude esta analisando seu cardapio...</div>
                        <div class="ia-processing-subtitle" id="processingSubtitle">Isso pode levar alguns segundos</div>
                        <div class="ia-processing-steps">
                            <div class="ia-step active" id="step1">
                                <div class="ia-step-dot">1</div>
                                <span>Enviando imagens...</span>
                            </div>
                            <div class="ia-step" id="step2">
                                <div class="ia-step-dot">2</div>
                                <span>IA analisando texto e precos...</span>
                            </div>
                            <div class="ia-step" id="step3">
                                <div class="ia-step-dot">3</div>
                                <span>Extraindo itens do cardapio...</span>
                            </div>
                            <div class="ia-step" id="step4">
                                <div class="ia-step-dot">4</div>
                                <span>Organizando categorias...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results -->
            <div class="ia-results" id="results">
                <div class="ia-results-header">
                    <div>
                        <h2 style="font-size:20px;font-weight:700;margin-bottom:4px;">Itens Encontrados</h2>
                        <div class="ia-results-count">
                            <strong id="resultCount">0</strong> itens extraidos do cardapio
                        </div>
                    </div>
                    <div class="ia-results-actions">
                        <button class="om-btn om-btn-outline" onclick="voltarUpload()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg>
                            Nova Analise
                        </button>
                        <button class="om-btn om-btn-primary" onclick="importarSelecionados()" id="importBtn" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            Importar Selecionados (<span id="selectedCount">0</span>)
                        </button>
                    </div>
                </div>

                <div class="ia-select-bar">
                    <label>
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this.checked)">
                        Selecionar todos
                    </label>
                    <span id="selectionInfo" style="font-size:13px;color:#6b7280;"></span>
                </div>

                <div class="ia-item-grid" id="itemGrid"></div>

                <!-- Import Progress -->
                <div class="ia-import-progress" id="importProgress">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" style="animation:brainSpin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
                    <p style="font-weight:600;margin-top:12px;">Importando produtos...</p>
                    <div class="ia-progress-bar"><div class="ia-progress-fill" id="progressFill"></div></div>
                </div>
            </div>

            <!-- Success -->
            <div class="ia-success" id="successState">
                <div class="om-card">
                    <div class="om-card-body" style="padding:48px;">
                        <div class="ia-success-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </div>
                        <h2 style="font-size:24px;font-weight:700;margin-bottom:8px;">Produtos importados com sucesso!</h2>
                        <p style="color:#6b7280;margin-bottom:24px;" id="successMessage"></p>
                        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                            <a href="produtos.php" class="om-btn om-btn-primary" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">Ver Meus Produtos</a>
                            <button class="om-btn om-btn-outline" onclick="voltarUpload()">Analisar Outro Cardapio</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
    // Sidebar toggle
    document.getElementById('sidebarToggle').addEventListener('click', () => {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('show');
    });
    document.getElementById('sidebarOverlay').addEventListener('click', () => {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('show');
    });

    // State
    let selectedFiles = [];
    let extractedItems = [];
    let categoriasExistentes = <?= json_encode($categorias) ?>;

    // File input
    const fileInput = document.getElementById('fileInput');
    const uploadZone = document.getElementById('uploadZone');
    const previewGrid = document.getElementById('previewGrid');
    const processBtn = document.getElementById('processBtn');

    fileInput.addEventListener('change', handleFiles);

    // Drag & Drop
    uploadZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadZone.classList.add('dragover');
    });
    uploadZone.addEventListener('dragleave', () => {
        uploadZone.classList.remove('dragover');
    });
    uploadZone.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadZone.classList.remove('dragover');
        const dt = e.dataTransfer;
        const files = dt.files;
        addFiles(files);
    });

    function handleFiles(e) {
        addFiles(e.target.files);
    }

    function addFiles(fileList) {
        for (let f of fileList) {
            if (selectedFiles.length >= 10) break;
            if (!f.type.startsWith('image/')) continue;
            if (f.size > 10 * 1024 * 1024) {
                alert('Imagem ' + f.name + ' excede 10MB');
                continue;
            }
            selectedFiles.push(f);
        }
        renderPreviews();
    }

    function renderPreviews() {
        previewGrid.innerHTML = '';
        selectedFiles.forEach((f, i) => {
            const div = document.createElement('div');
            div.className = 'ia-preview-item';
            const img = document.createElement('img');
            img.src = URL.createObjectURL(f);
            const btn = document.createElement('button');
            btn.className = 'ia-preview-remove';
            btn.innerHTML = '&times;';
            btn.onclick = (e) => {
                e.stopPropagation();
                selectedFiles.splice(i, 1);
                renderPreviews();
            };
            div.appendChild(img);
            div.appendChild(btn);
            previewGrid.appendChild(div);
        });

        if (selectedFiles.length > 0) {
            uploadZone.classList.add('has-files');
            processBtn.style.display = 'block';
        } else {
            uploadZone.classList.remove('has-files');
            processBtn.style.display = 'none';
        }
    }

    // Process with AI
    async function processarCardapio() {
        if (selectedFiles.length === 0) return;

        // Show processing
        document.getElementById('uploadCard').style.display = 'none';
        document.getElementById('howItWorks').style.display = 'none';
        document.getElementById('processing').classList.add('active');

        // Animate steps
        const steps = ['step1', 'step2', 'step3', 'step4'];
        let currentStep = 0;
        const stepInterval = setInterval(() => {
            if (currentStep > 0) {
                document.getElementById(steps[currentStep - 1]).classList.remove('active');
                document.getElementById(steps[currentStep - 1]).classList.add('done');
                document.getElementById(steps[currentStep - 1]).querySelector('.ia-step-dot').innerHTML = '&#10003;';
            }
            if (currentStep < steps.length) {
                document.getElementById(steps[currentStep]).classList.add('active');
                currentStep++;
            }
        }, 3000);

        // Build FormData
        const formData = new FormData();
        selectedFiles.forEach(f => formData.append('images[]', f));

        try {
            const response = await fetch('ajax/processar-cardapio-ia.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const data = await response.json();
            clearInterval(stepInterval);

            if (!data.success) {
                alert('Erro: ' + data.message);
                voltarUpload();
                return;
            }

            // Mark all steps done
            steps.forEach(s => {
                const el = document.getElementById(s);
                el.classList.remove('active');
                el.classList.add('done');
                el.querySelector('.ia-step-dot').innerHTML = '&#10003;';
            });

            extractedItems = data.data.items || [];
            categoriasExistentes = data.categorias_existentes || categoriasExistentes;

            setTimeout(() => {
                document.getElementById('processing').classList.remove('active');
                showResults();
            }, 1000);

        } catch (err) {
            clearInterval(stepInterval);
            console.error(err);
            alert('Erro de conexao. Verifique sua internet e tente novamente.');
            voltarUpload();
        }
    }

    // Show results
    function showResults() {
        document.getElementById('results').classList.add('active');
        document.getElementById('resultCount').textContent = extractedItems.length;
        renderItems();
        updateSelectionCount();
    }

    function getCategoryEmoji(cat) {
        const map = {
            'entradas': '🥗', 'saladas': '🥗', 'aperitivos': '🧆',
            'pratos principais': '🍽️', 'pratos': '🍽️', 'carnes': '🥩',
            'frango': '🍗', 'peixes': '🐟', 'massas': '🍝', 'risotos': '🍚',
            'bebidas': '🥤', 'drinks': '🍹', 'sucos': '🧃', 'cervejas': '🍺',
            'sobremesas': '🍰', 'doces': '🍫', 'sorvetes': '🍦',
            'lanches': '🍔', 'hamburgueres': '🍔', 'sanduiches': '🥪',
            'pizzas': '🍕', 'pasteis': '🥟', 'esfihas': '🥟',
            'acompanhamentos': '🍟', 'porcoes': '🍟',
            'cafe': '☕', 'cafes': '☕', 'cha': '🍵',
            'combos': '🎁', 'promocoes': '🏷️', 'kids': '👶',
        };
        const lower = (cat || '').toLowerCase();
        for (const [key, emoji] of Object.entries(map)) {
            if (lower.includes(key)) return emoji;
        }
        return '🍴';
    }

    function renderItems() {
        const grid = document.getElementById('itemGrid');
        grid.innerHTML = '';

        extractedItems.forEach((item, idx) => {
            const priceDisplay = item.preco > 0
                ? `R$ ${item.preco.toFixed(2).replace('.', ',')}`
                : 'Definir preco';
            const priceClass = item.preco > 0 ? '' : 'no-price';

            let sizesHtml = '';
            if (item.tamanhos && item.tamanhos.length > 0) {
                sizesHtml = '<div class="ia-sizes">' + item.tamanhos.map(t =>
                    `<span class="ia-size-tag">${esc(t.nome)}: R$ ${parseFloat(t.preco).toFixed(2).replace('.', ',')}</span>`
                ).join('') + '</div>';
            }

            let obsHtml = '';
            if (item.observacoes) {
                const tags = item.observacoes.split(/[,;]/).map(t => t.trim()).filter(Boolean);
                obsHtml = '<div class="ia-obs-tags">' + tags.map(t =>
                    `<span class="ia-obs-tag">${esc(t)}</span>`
                ).join('') + '</div>';
            }

            // Build category options
            let catOptions = '<option value="">Selecionar...</option>';
            const allCats = [...new Set([
                ...categoriasExistentes.map(c => c.name),
                ...(extractedItems.map(i => i.categoria).filter(Boolean))
            ])];
            allCats.forEach(c => {
                const sel = (c.toLowerCase() === (item.categoria || '').toLowerCase()) ? 'selected' : '';
                catOptions += `<option value="${esc(c)}" ${sel}>${esc(c)}</option>`;
            });

            const card = document.createElement('div');
            card.className = 'ia-item-card selected';
            card.dataset.index = idx;
            card.innerHTML = `
                <div class="ia-item-check">
                    <input type="checkbox" checked onchange="updateSelectionCount()" data-idx="${idx}">
                </div>
                <div class="ia-item-display">
                    <div class="ia-item-header">
                        <div class="ia-item-emoji">${getCategoryEmoji(item.categoria)}</div>
                        <div class="ia-item-title-wrap">
                            <div class="ia-item-name">${esc(item.nome)}</div>
                            <span class="ia-item-category">${esc(item.categoria || 'Sem categoria')}</span>
                        </div>
                    </div>
                    <div class="ia-item-desc">${esc(item.descricao)}</div>
                    ${item.ingredientes ? `<div class="ia-item-ingredients"><strong>Ingredientes:</strong> ${esc(item.ingredientes)}</div>` : ''}
                    ${sizesHtml}
                    ${obsHtml}
                    <div class="ia-item-footer">
                        <div class="ia-item-price ${priceClass}">${priceDisplay}</div>
                        <div class="ia-item-actions">
                            <button class="ia-item-btn edit" onclick="toggleEdit(${idx})">Editar</button>
                        </div>
                    </div>
                </div>
                <div class="ia-edit-form">
                    <div class="ia-edit-field">
                        <label>Nome</label>
                        <input type="text" value="${escAttr(item.nome)}" onchange="extractedItems[${idx}].nome=this.value">
                    </div>
                    <div class="ia-edit-field">
                        <label>Descricao</label>
                        <textarea onchange="extractedItems[${idx}].descricao=this.value">${esc(item.descricao)}</textarea>
                    </div>
                    <div class="ia-edit-field">
                        <label>Ingredientes</label>
                        <input type="text" value="${escAttr(item.ingredientes || '')}" onchange="extractedItems[${idx}].ingredientes=this.value">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div class="ia-edit-field">
                            <label>Preco (R$)</label>
                            <input type="number" step="0.01" min="0" value="${item.preco}" onchange="extractedItems[${idx}].preco=parseFloat(this.value)||0">
                        </div>
                        <div class="ia-edit-field">
                            <label>Categoria</label>
                            <select onchange="extractedItems[${idx}].categoria=this.value">${catOptions}</select>
                        </div>
                    </div>
                    <div class="ia-edit-field">
                        <label>Observacoes</label>
                        <input type="text" value="${escAttr(item.observacoes || '')}" onchange="extractedItems[${idx}].observacoes=this.value">
                    </div>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button class="om-btn om-btn-sm om-btn-primary" onclick="toggleEdit(${idx})" style="background:#8b5cf6;">Salvar</button>
                        <button class="om-btn om-btn-sm om-btn-outline" onclick="toggleEdit(${idx})">Cancelar</button>
                    </div>
                </div>
            `;
            grid.appendChild(card);
        });
    }

    function toggleEdit(idx) {
        const card = document.querySelector(`.ia-item-card[data-index="${idx}"]`);
        card.classList.toggle('editing');
        if (!card.classList.contains('editing')) {
            renderItems(); // Refresh display with updated data
        }
    }

    function toggleSelectAll(checked) {
        document.querySelectorAll('.ia-item-check input[type="checkbox"]').forEach(cb => {
            cb.checked = checked;
            cb.closest('.ia-item-card').classList.toggle('selected', checked);
        });
        updateSelectionCount();
    }

    function updateSelectionCount() {
        const checkboxes = document.querySelectorAll('.ia-item-check input[type="checkbox"]');
        let count = 0;
        checkboxes.forEach(cb => {
            const card = cb.closest('.ia-item-card');
            if (cb.checked) {
                card.classList.add('selected');
                count++;
            } else {
                card.classList.remove('selected');
            }
        });
        document.getElementById('selectedCount').textContent = count;
        document.getElementById('selectionInfo').textContent = count + ' de ' + extractedItems.length + ' selecionados';
        document.getElementById('selectAll').checked = count === extractedItems.length;
        document.getElementById('importBtn').disabled = count === 0;
    }

    // Import selected items
    async function importarSelecionados() {
        const checkboxes = document.querySelectorAll('.ia-item-check input[type="checkbox"]');
        const itemsToImport = [];
        checkboxes.forEach(cb => {
            if (cb.checked) {
                const idx = parseInt(cb.dataset.idx);
                const item = extractedItems[idx];
                // Find category_id
                let catId = 0;
                categoriasExistentes.forEach(c => {
                    if (c.name.toLowerCase() === (item.categoria || '').toLowerCase()) {
                        catId = c.category_id;
                    }
                });
                itemsToImport.push({...item, categoria_id: catId});
            }
        });

        if (itemsToImport.length === 0) {
            alert('Selecione pelo menos um item para importar');
            return;
        }

        if (!confirm(`Importar ${itemsToImport.length} produto(s) para seu catalogo?`)) return;

        // Show progress
        document.getElementById('results').querySelector('.ia-results-header').style.display = 'none';
        document.getElementById('results').querySelector('.ia-select-bar').style.display = 'none';
        document.getElementById('itemGrid').style.display = 'none';
        document.getElementById('importProgress').classList.add('active');

        const fill = document.getElementById('progressFill');
        fill.style.width = '30%';

        try {
            const response = await fetch('ajax/importar-cardapio-ia.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ items: itemsToImport })
            });

            fill.style.width = '90%';
            const data = await response.json();

            setTimeout(() => {
                fill.style.width = '100%';
                setTimeout(() => {
                    document.getElementById('importProgress').classList.remove('active');
                    document.getElementById('results').classList.remove('active');

                    if (data.success) {
                        document.getElementById('successMessage').textContent = data.message;
                        if (data.data.categories_created && data.data.categories_created.length > 0) {
                            document.getElementById('successMessage').textContent +=
                                ' Categorias criadas: ' + data.data.categories_created.join(', ') + '.';
                        }
                        document.getElementById('successState').classList.add('active');
                    } else {
                        alert('Erro: ' + data.message);
                        voltarUpload();
                    }
                }, 500);
            }, 500);
        } catch (err) {
            console.error(err);
            alert('Erro de conexao ao importar.');
            voltarUpload();
        }
    }

    function voltarUpload() {
        // Reset all states
        selectedFiles = [];
        extractedItems = [];
        previewGrid.innerHTML = '';
        uploadZone.classList.remove('has-files');
        processBtn.style.display = 'none';
        fileInput.value = '';

        document.getElementById('uploadCard').style.display = '';
        document.getElementById('howItWorks').style.display = '';
        document.getElementById('processing').classList.remove('active');
        document.getElementById('results').classList.remove('active');
        document.getElementById('successState').classList.remove('active');
        document.getElementById('importProgress').classList.remove('active');

        // Reset steps
        ['step1','step2','step3','step4'].forEach(s => {
            const el = document.getElementById(s);
            el.classList.remove('active', 'done');
            el.querySelector('.ia-step-dot').textContent = s.replace('step','');
        });

        // Reset results UI
        const rh = document.getElementById('results').querySelector('.ia-results-header');
        const sb = document.getElementById('results').querySelector('.ia-select-bar');
        const ig = document.getElementById('itemGrid');
        if (rh) rh.style.display = '';
        if (sb) sb.style.display = '';
        if (ig) ig.style.display = '';
    }

    function esc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escAttr(str) {
        if (!str) return '';
        return str.replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    </script>
</body>
</html>
