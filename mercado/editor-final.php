<?php
/**
 * EDITOR VISUAL ULTIMATE - OneMundo
 * Todas as funcionalidades + Salvamento corrigido
 */

// Configuração do banco
$db_host = '147.93.12.236';
$DB_NAME = 'love1';
$DB_USER = 'root';
$DB_PASS = '';

$pdo = null;
$db_ok = false;

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db_ok = true;
    
    // Criar tabela
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_editor_pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        page_key VARCHAR(100) UNIQUE,
        html_content LONGTEXT,
        css_content LONGTEXT,
        theme_config JSON,
        status ENUM('draft','published') DEFAULT 'draft',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

// API Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        $data = $_POST;
    }
    
    $action = $data['action'] ?? '';
    $page_key = $data['page_key'] ?? 'index';
    
    if ($action === 'save' && $db_ok) {
        try {
            $html = $data['html'] ?? '';
            $css = $data['css'] ?? '';
            $theme = json_encode($data['theme'] ?? []);
            
            $stmt = $pdo->prepare("INSERT INTO om_editor_pages (page_key, html_content, css_content, theme_config) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE html_content=VALUES(html_content), css_content=VALUES(css_content), theme_config=VALUES(theme_config)");
            $stmt->execute([$page_key, $html, $css, $theme]);
            
            die(json_encode(['success' => true, 'message' => 'Salvo!']));
        } catch (Exception $e) {
            die(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }
    
    if ($action === 'load' && $db_ok) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM om_editor_pages WHERE page_key = ?");
            $stmt->execute([$page_key]);
            $page = $stmt->fetch(PDO::FETCH_ASSOC);
            die(json_encode(['success' => true, 'data' => $page]));
        } catch (Exception $e) {
            die(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }
    
    if ($action === 'publish' && $db_ok) {
        try {
            $stmt = $pdo->prepare("UPDATE om_editor_pages SET status='published' WHERE page_key=?");
            $stmt->execute([$page_key]);
            die(json_encode(['success' => true]));
        } catch (Exception $e) {
            die(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }
    
    die(json_encode(['success' => false, 'error' => 'Ação inválida ou banco desconectado']));
}

$page_key = $_GET['page'] ?? 'index';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor Ultimate - OneMundo</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #6366f1; --primary-dark: #4f46e5;
            --success: #10b981; --warning: #f59e0b; --danger: #ef4444;
            --dark: #0f172a; --dark2: #1e293b; --dark3: #334155;
            --gray: #64748b; --gray-light: #94a3b8; --white: #fff;
        }
        body { font-family: 'Inter', sans-serif; background: var(--dark); color: var(--white); height: 100vh; overflow: hidden; }
        
        /* TOOLBAR */
        .toolbar {
            height: 48px; background: var(--dark); border-bottom: 1px solid var(--dark3);
            display: flex; align-items: center; justify-content: space-between; padding: 0 12px;
            position: fixed; top: 0; left: 0; right: 0; z-index: 10000;
        }
        .toolbar-group { display: flex; align-items: center; gap: 6px; }
        .logo { display: flex; align-items: center; gap: 8px; padding-right: 12px; border-right: 1px solid var(--dark3); margin-right: 8px; }
        .logo-icon { width: 28px; height: 28px; background: linear-gradient(135deg, #6366f1, #ec4899); border-radius: 6px; display: flex; align-items: center; justify-content: center; }
        .logo span { font-weight: 700; font-size: 13px; background: linear-gradient(135deg, #818cf8, #f472b6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        
        .tb-btn { padding: 6px 10px; background: none; border: none; color: var(--gray-light); font-size: 12px; cursor: pointer; border-radius: 6px; display: flex; align-items: center; gap: 4px; transition: all 0.15s; }
        .tb-btn:hover { background: var(--dark3); color: var(--white); }
        .tb-divider { width: 1px; height: 20px; background: var(--dark3); margin: 0 4px; }
        
        .vp-group { display: flex; background: var(--dark3); border-radius: 6px; padding: 2px; }
        .vp-btn { width: 28px; height: 24px; background: none; border: none; color: var(--gray); cursor: pointer; border-radius: 4px; display: flex; align-items: center; justify-content: center; }
        .vp-btn:hover { color: var(--white); }
        .vp-btn.active { background: var(--primary); color: var(--white); }
        
        .btn { padding: 6px 14px; font-size: 12px; font-weight: 600; border-radius: 6px; cursor: pointer; border: none; display: flex; align-items: center; gap: 4px; transition: all 0.15s; }
        .btn-outline { background: none; border: 1px solid var(--dark3); color: var(--gray-light); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn-primary { background: var(--primary); color: var(--white); }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-success { background: var(--success); color: var(--white); }
        
        .db-status { font-size: 10px; padding: 4px 8px; border-radius: 4px; }
        .db-status.ok { background: rgba(16,185,129,0.2); color: var(--success); }
        .db-status.error { background: rgba(239,68,68,0.2); color: var(--danger); }
        
        /* LAYOUT */
        .layout { display: flex; height: calc(100vh - 48px); margin-top: 48px; }
        
        /* SIDEBAR */
        .sidebar { width: 280px; background: var(--dark2); border-right: 1px solid var(--dark3); display: flex; flex-direction: column; }
        .sidebar-tabs { display: flex; border-bottom: 1px solid var(--dark3); }
        .sidebar-tab { flex: 1; padding: 10px 6px; background: none; border: none; color: var(--gray); font-size: 10px; font-weight: 600; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 3px; border-bottom: 2px solid transparent; }
        .sidebar-tab:hover { color: var(--white); }
        .sidebar-tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .sidebar-body { flex: 1; overflow-y: auto; padding: 12px; }
        .panel { display: none; }
        .panel.active { display: block; }
        
        .section-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gray); margin: 16px 0 8px; display: flex; align-items: center; gap: 6px; }
        .section-title:first-child { margin-top: 0; }
        
        /* WIDGETS */
        .widgets { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
        .widget { background: var(--dark3); border: 1px solid transparent; border-radius: 6px; padding: 10px 4px; text-align: center; cursor: grab; transition: all 0.15s; }
        .widget:hover { border-color: var(--primary); background: rgba(99,102,241,0.1); transform: translateY(-1px); }
        .widget-icon { width: 20px; height: 20px; margin: 0 auto 4px; color: var(--gray-light); }
        .widget:hover .widget-icon { color: var(--primary); }
        .widget-name { font-size: 9px; font-weight: 500; color: var(--gray-light); }
        
        /* EMOJI GRID */
        .emoji-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 4px; }
        .emoji-btn { aspect-ratio: 1; background: var(--dark3); border: none; border-radius: 4px; font-size: 16px; cursor: pointer; transition: all 0.15s; }
        .emoji-btn:hover { background: var(--primary); transform: scale(1.1); }
        
        /* LAYERS */
        .layer { display: flex; align-items: center; gap: 6px; padding: 6px 8px; background: var(--dark3); border-radius: 4px; margin-bottom: 3px; cursor: pointer; font-size: 11px; }
        .layer:hover { background: var(--dark); }
        .layer.active { background: var(--primary); }
        .layer-icon { width: 14px; height: 14px; color: var(--gray-light); }
        .layer.active .layer-icon { color: var(--white); }
        .layer-name { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        /* CANVAS */
        .canvas-area { flex: 1; background: #1a1a2e; overflow: auto; padding: 16px; display: flex; justify-content: center; }
        .canvas-frame { background: var(--white); border-radius: 8px; box-shadow: 0 20px 50px rgba(0,0,0,0.5); overflow: hidden; width: 100%; max-width: 100%; transition: max-width 0.3s; }
        .canvas-frame.tablet { max-width: 768px; }
        .canvas-frame.mobile { max-width: 375px; }
        .canvas-frame iframe { width: 100%; height: calc(100vh - 80px); border: none; }
        
        /* PROPS PANEL */
        .props { width: 300px; background: var(--dark2); border-left: 1px solid var(--dark3); display: flex; flex-direction: column; }
        .props-header { padding: 10px 12px; border-bottom: 1px solid var(--dark3); display: flex; align-items: center; justify-content: space-between; }
        .props-title { font-size: 12px; font-weight: 700; }
        .props-badge { font-size: 9px; padding: 2px 6px; background: var(--primary); border-radius: 3px; }
        .props-tabs { display: flex; padding: 4px; gap: 2px; border-bottom: 1px solid var(--dark3); }
        .props-tab { flex: 1; padding: 6px; background: none; border: none; color: var(--gray); font-size: 10px; font-weight: 600; cursor: pointer; border-radius: 4px; }
        .props-tab:hover { background: var(--dark3); color: var(--white); }
        .props-tab.active { background: var(--primary); color: var(--white); }
        .props-body { flex: 1; overflow-y: auto; padding: 12px; }
        
        .props-section { margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--dark3); }
        .props-section:last-child { border-bottom: none; }
        
        /* FORM */
        .form-group { margin-bottom: 10px; }
        .form-label { display: block; font-size: 10px; font-weight: 500; color: var(--gray-light); margin-bottom: 4px; }
        .form-input { width: 100%; padding: 6px 10px; background: var(--dark3); border: 1px solid var(--dark3); border-radius: 4px; color: var(--white); font-size: 11px; }
        .form-input:focus { outline: none; border-color: var(--primary); }
        .form-select { width: 100%; padding: 6px 10px; background: var(--dark3); border: 1px solid var(--dark3); border-radius: 4px; color: var(--white); font-size: 11px; }
        
        /* COLOR */
        .color-row { display: flex; gap: 6px; align-items: center; }
        .color-swatch { width: 32px; height: 32px; border-radius: 6px; border: 2px solid var(--dark3); overflow: hidden; cursor: pointer; position: relative; flex-shrink: 0; }
        .color-swatch input { position: absolute; width: 50px; height: 50px; top: -9px; left: -9px; border: none; cursor: pointer; }
        .color-presets { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 6px; }
        .color-preset { width: 18px; height: 18px; border-radius: 3px; cursor: pointer; border: 2px solid transparent; transition: all 0.15s; }
        .color-preset:hover { transform: scale(1.15); border-color: var(--white); }
        
        .gradient-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-top: 6px; }
        .gradient-btn { height: 28px; border-radius: 4px; border: 2px solid transparent; cursor: pointer; transition: all 0.15s; }
        .gradient-btn:hover { border-color: var(--white); transform: scale(1.05); }
        
        /* RANGE */
        .range-row { display: flex; align-items: center; gap: 8px; }
        .range-slider { flex: 1; height: 4px; -webkit-appearance: none; background: var(--dark3); border-radius: 2px; }
        .range-slider::-webkit-slider-thumb { -webkit-appearance: none; width: 12px; height: 12px; background: var(--primary); border-radius: 50%; cursor: pointer; }
        .range-val { min-width: 36px; text-align: right; font-size: 10px; font-weight: 600; color: var(--gray-light); }
        
        /* SPACING */
        .spacing-box { display: grid; grid-template-columns: 1fr 1.5fr 1fr; grid-template-rows: auto auto auto; gap: 3px; padding: 6px; background: var(--dark); border-radius: 6px; }
        .spacing-input { padding: 4px; background: var(--dark3); border: 1px solid transparent; border-radius: 3px; color: var(--white); text-align: center; font-size: 10px; font-weight: 600; }
        .spacing-input:focus { border-color: var(--primary); outline: none; }
        .spacing-center { grid-column: 2; grid-row: 2; background: var(--dark2); border: 1px dashed var(--dark3); border-radius: 3px; height: 28px; display: flex; align-items: center; justify-content: center; font-size: 8px; color: var(--gray); }
        .spacing-top { grid-column: 2; grid-row: 1; }
        .spacing-right { grid-column: 3; grid-row: 2; }
        .spacing-bottom { grid-column: 2; grid-row: 3; }
        .spacing-left { grid-column: 1; grid-row: 2; }
        
        /* ALIGN */
        .align-row { display: flex; gap: 2px; }
        .align-btn { flex: 1; padding: 6px; background: var(--dark3); border: none; border-radius: 3px; color: var(--gray); cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .align-btn:hover { color: var(--white); }
        .align-btn.active { background: var(--primary); color: var(--white); }
        
        /* ACTIONS */
        .actions-row { display: flex; gap: 6px; margin-top: 8px; }
        .actions-row .btn { flex: 1; justify-content: center; font-size: 11px; }
        
        /* NO SELECTION */
        .no-selection { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; text-align: center; padding: 30px; }
        .no-selection-icon { width: 40px; height: 40px; color: var(--dark3); margin-bottom: 12px; }
        .no-selection-title { font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        .no-selection-text { font-size: 11px; color: var(--gray); }
        
        /* STATUS BAR */
        .status-bar { position: fixed; bottom: 0; left: 280px; right: 300px; height: 28px; background: var(--dark); border-top: 1px solid var(--dark3); display: flex; align-items: center; justify-content: space-between; padding: 0 12px; font-size: 10px; color: var(--gray); z-index: 9999; }
        
        /* TOAST */
        .toast-box { position: fixed; bottom: 40px; left: 50%; transform: translateX(-50%); z-index: 99999; }
        .toast { display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: var(--dark2); border: 1px solid var(--dark3); border-radius: 6px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); animation: toastIn 0.3s; margin-top: 6px; }
        .toast.success { border-color: var(--success); }
        .toast.error { border-color: var(--danger); }
        @keyframes toastIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* MODAL */
        .modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center; z-index: 100000; opacity: 0; visibility: hidden; transition: all 0.2s; }
        .modal-bg.show { opacity: 1; visibility: visible; }
        .modal { background: var(--dark2); border-radius: 10px; width: 90%; max-width: 500px; max-height: 80vh; overflow: hidden; transform: scale(0.95); transition: transform 0.2s; }
        .modal-bg.show .modal { transform: scale(1); }
        .modal-header { padding: 12px 16px; border-bottom: 1px solid var(--dark3); display: flex; align-items: center; justify-content: space-between; }
        .modal-title { font-size: 14px; font-weight: 700; }
        .modal-close { width: 28px; height: 28px; background: none; border: none; color: var(--gray); cursor: pointer; border-radius: 4px; display: flex; align-items: center; justify-content: center; }
        .modal-close:hover { background: var(--dark3); color: var(--white); }
        .modal-body { padding: 16px; overflow-y: auto; max-height: calc(80vh - 100px); }
        
        .emoji-lg { display: grid; grid-template-columns: repeat(10, 1fr); gap: 6px; }
        .emoji-lg-btn { aspect-ratio: 1; background: var(--dark3); border: none; border-radius: 6px; font-size: 22px; cursor: pointer; transition: all 0.15s; }
        .emoji-lg-btn:hover { background: var(--primary); transform: scale(1.1); }
        
        /* SCROLLBAR */
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: var(--dark); }
        ::-webkit-scrollbar-thumb { background: var(--dark3); border-radius: 3px; }
    </style>
</head>
<body>

<!-- TOOLBAR -->
<header class="toolbar">
    <div class="toolbar-group">
        <div class="logo">
            <div class="logo-icon"><i data-lucide="sparkles" style="width:14px;height:14px;color:#fff"></i></div>
            <span>OneMundo</span>
        </div>
        
        <button class="tb-btn" onclick="undo()" title="Ctrl+Z"><i data-lucide="undo-2" style="width:14px;height:14px"></i></button>
        <button class="tb-btn" onclick="redo()" title="Ctrl+Shift+Z"><i data-lucide="redo-2" style="width:14px;height:14px"></i></button>
        
        <div class="tb-divider"></div>
        
        <div class="vp-group">
            <button class="vp-btn active" data-vp="desktop"><i data-lucide="monitor" style="width:14px;height:14px"></i></button>
            <button class="vp-btn" data-vp="tablet"><i data-lucide="tablet" style="width:14px;height:14px"></i></button>
            <button class="vp-btn" data-vp="mobile"><i data-lucide="smartphone" style="width:14px;height:14px"></i></button>
        </div>
    </div>
    
    <div class="toolbar-group">
        <span class="db-status <?php echo $db_ok ? 'ok' : 'error'; ?>">
            <?php echo $db_ok ? '✓ BD Conectado' : '✗ Erro BD'; ?>
        </span>
        
        <div class="tb-divider"></div>
        
        <button class="tb-btn" onclick="preview()"><i data-lucide="eye" style="width:14px;height:14px"></i> Preview</button>
        <button class="btn btn-outline" onclick="save()"><i data-lucide="save" style="width:14px;height:14px"></i> Salvar</button>
        <button class="btn btn-success" onclick="publish()"><i data-lucide="rocket" style="width:14px;height:14px"></i> Publicar</button>
    </div>
</header>

<!-- LAYOUT -->
<div class="layout">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-tabs">
            <button class="sidebar-tab active" data-panel="elements"><i data-lucide="plus-square" style="width:14px;height:14px"></i> Elementos</button>
            <button class="sidebar-tab" data-panel="layers"><i data-lucide="layers" style="width:14px;height:14px"></i> Camadas</button>
            <button class="sidebar-tab" data-panel="theme"><i data-lucide="palette" style="width:14px;height:14px"></i> Tema</button>
        </div>
        
        <div class="sidebar-body">
            <!-- ELEMENTS -->
            <div class="panel active" id="panel-elements">
                <div class="section-title">Layout</div>
                <div class="widgets">
                    <div class="widget" data-type="section"><i data-lucide="square" class="widget-icon"></i><div class="widget-name">Seção</div></div>
                    <div class="widget" data-type="container"><i data-lucide="box" class="widget-icon"></i><div class="widget-name">Container</div></div>
                    <div class="widget" data-type="columns"><i data-lucide="columns" class="widget-icon"></i><div class="widget-name">Colunas</div></div>
                </div>
                
                <div class="section-title">Texto</div>
                <div class="widgets">
                    <div class="widget" data-type="heading"><i data-lucide="type" class="widget-icon"></i><div class="widget-name">Título</div></div>
                    <div class="widget" data-type="text"><i data-lucide="align-left" class="widget-icon"></i><div class="widget-name">Texto</div></div>
                    <div class="widget" data-type="button"><i data-lucide="mouse-pointer-click" class="widget-icon"></i><div class="widget-name">Botão</div></div>
                </div>
                
                <div class="section-title">Mídia</div>
                <div class="widgets">
                    <div class="widget" data-type="image"><i data-lucide="image" class="widget-icon"></i><div class="widget-name">Imagem</div></div>
                    <div class="widget" data-type="icon"><i data-lucide="star" class="widget-icon"></i><div class="widget-name">Ícone</div></div>
                    <div class="widget" data-type="video"><i data-lucide="play-circle" class="widget-icon"></i><div class="widget-name">Vídeo</div></div>
                </div>
                
                <div class="section-title">E-commerce</div>
                <div class="widgets">
                    <div class="widget" data-type="products"><i data-lucide="package" class="widget-icon"></i><div class="widget-name">Produtos</div></div>
                    <div class="widget" data-type="categories"><i data-lucide="folder" class="widget-icon"></i><div class="widget-name">Categorias</div></div>
                    <div class="widget" data-type="cart"><i data-lucide="shopping-cart" class="widget-icon"></i><div class="widget-name">Carrinho</div></div>
                </div>
                
                <div class="section-title">Emojis</div>
                <div class="emoji-grid">
                    <button class="emoji-btn" onclick="setEmoji('🛒')">🛒</button>
                    <button class="emoji-btn" onclick="setEmoji('🍎')">🍎</button>
                    <button class="emoji-btn" onclick="setEmoji('🥬')">🥬</button>
                    <button class="emoji-btn" onclick="setEmoji('🥛')">🥛</button>
                    <button class="emoji-btn" onclick="setEmoji('🥩')">🥩</button>
                    <button class="emoji-btn" onclick="setEmoji('🧹')">🧹</button>
                    <button class="emoji-btn" onclick="setEmoji('🧴')">🧴</button>
                    <button class="emoji-btn" onclick="setEmoji('📦')">📦</button>
                    <button class="emoji-btn" onclick="setEmoji('⭐')">⭐</button>
                    <button class="emoji-btn" onclick="setEmoji('🔥')">🔥</button>
                    <button class="emoji-btn" onclick="setEmoji('💰')">💰</button>
                    <button class="emoji-btn" onclick="setEmoji('🎁')">🎁</button>
                    <button class="emoji-btn" onclick="setEmoji('✅')">✅</button>
                    <button class="emoji-btn" onclick="setEmoji('❤️')">❤️</button>
                    <button class="emoji-btn" onclick="setEmoji('🚀')">🚀</button>
                    <button class="emoji-btn" onclick="setEmoji('✨')">✨</button>
                </div>
                <button class="btn btn-outline" style="width:100%;margin-top:8px" onclick="openEmojis()">Mais Emojis</button>
            </div>
            
            <!-- LAYERS -->
            <div class="panel" id="panel-layers">
                <div id="layers-list"></div>
            </div>
            
            <!-- THEME -->
            <div class="panel" id="panel-theme">
                <div class="section-title">Cores do Tema</div>
                <div class="form-group">
                    <label class="form-label">Primária</label>
                    <div class="color-row">
                        <div class="color-swatch" id="theme-primary" style="background:#6366f1">
                            <input type="color" value="#6366f1" onchange="setTheme('primary',this.value)">
                        </div>
                        <input type="text" class="form-input" value="#6366f1" onchange="setTheme('primary',this.value)">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Secundária</label>
                    <div class="color-row">
                        <div class="color-swatch" id="theme-secondary" style="background:#10b981">
                            <input type="color" value="#10b981" onchange="setTheme('secondary',this.value)">
                        </div>
                        <input type="text" class="form-input" value="#10b981" onchange="setTheme('secondary',this.value)">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Destaque</label>
                    <div class="color-row">
                        <div class="color-swatch" id="theme-accent" style="background:#f59e0b">
                            <input type="color" value="#f59e0b" onchange="setTheme('accent',this.value)">
                        </div>
                        <input type="text" class="form-input" value="#f59e0b" onchange="setTheme('accent',this.value)">
                    </div>
                </div>
                
                <div class="section-title">Tipografia</div>
                <div class="form-group">
                    <label class="form-label">Fonte</label>
                    <select class="form-select" onchange="setTheme('font',this.value)">
                        <option value="Inter">Inter</option>
                        <option value="Poppins">Poppins</option>
                        <option value="Roboto">Roboto</option>
                        <option value="Open Sans">Open Sans</option>
                        <option value="Montserrat">Montserrat</option>
                    </select>
                </div>
            </div>
        </div>
    </aside>
    
    <!-- CANVAS -->
    <main class="canvas-area">
        <div class="canvas-frame" id="canvas-frame">
            <iframe id="page-frame"></iframe>
        </div>
    </main>
    
    <!-- PROPS -->
    <aside class="props">
        <div class="props-header">
            <span class="props-title">Propriedades</span>
            <span class="props-badge" id="props-badge">Nenhum</span>
        </div>
        <div class="props-tabs">
            <button class="props-tab active">Estilo</button>
            <button class="props-tab">Posição</button>
            <button class="props-tab">Avançado</button>
        </div>
        <div class="props-body">
            <div class="no-selection" id="no-selection">
                <i data-lucide="mouse-pointer-click" class="no-selection-icon"></i>
                <div class="no-selection-title">Selecione um elemento</div>
                <div class="no-selection-text">Clique em qualquer elemento para editar</div>
            </div>
            <div id="props-form" style="display:none"></div>
        </div>
    </aside>
</div>

<!-- STATUS -->
<div class="status-bar">
    <span>Página: <strong><?php echo htmlspecialchars($page_key); ?></strong></span>
    <span>Última alteração: <span id="last-save">-</span></span>
</div>

<!-- TOAST -->
<div class="toast-box" id="toast-box"></div>

<!-- EMOJI MODAL -->
<div class="modal-bg" id="emoji-modal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">Emojis & Ícones</span>
            <button class="modal-close" onclick="closeEmojis()"><i data-lucide="x" style="width:16px;height:16px"></i></button>
        </div>
        <div class="modal-body">
            <div class="section-title">Mercado</div>
            <div class="emoji-lg">
                <button class="emoji-lg-btn" onclick="setEmoji('🛒')">🛒</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🛍️')">🛍️</button>
                <button class="emoji-lg-btn" onclick="setEmoji('📦')">📦</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🏪')">🏪</button>
                <button class="emoji-lg-btn" onclick="setEmoji('💳')">💳</button>
                <button class="emoji-lg-btn" onclick="setEmoji('💰')">💰</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🎁')">🎁</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🏷️')">🏷️</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🧾')">🧾</button>
                <button class="emoji-lg-btn" onclick="setEmoji('💵')">💵</button>
            </div>
            <div class="section-title">Alimentos</div>
            <div class="emoji-lg">
                <button class="emoji-lg-btn" onclick="setEmoji('🍎')">🍎</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🍊')">🍊</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🍋')">🍋</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🍌')">🍌</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🍇')">🍇</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🍓')">🍓</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🥬')">🥬</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🥕')">🥕</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🥩')">🥩</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🍗')">🍗</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🥛')">🥛</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🧀')">🧀</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🥚')">🥚</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🍞')">🍞</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🍕')">🍕</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🍔')">🍔</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🥤')">🥤</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🍺')">🍺</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🍷')">🍷</button>
                <button class="emoji-lg-btn" onclick="setEmoji('☕')">☕</button>
            </div>
            <div class="section-title">Casa</div>
            <div class="emoji-lg">
                <button class="emoji-lg-btn" onclick="setEmoji('🏠')">🏠</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🧹')">🧹</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🧽')">🧽</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🧴')">🧴</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🧼')">🧼</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🪣')">🪣</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🧺')">🧺</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🛁')">🛁</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🚿')">🚿</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🪥')">🪥</button>
            </div>
            <div class="section-title">Símbolos</div>
            <div class="emoji-lg">
                <button class="emoji-lg-btn" onclick="setEmoji('✅')">✅</button>
                <button class="emoji-lg-btn" onclick="setEmoji('❤️')">❤️</button>
                <button class="emoji-lg-btn" onclick="setEmoji('⭐')">⭐</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🔥')">🔥</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🚀')">🚀</button>
                <button class="emoji-lg-btn" onclick="setEmoji('💯')">💯</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🎯')">🎯</button>
                <button class="emoji-lg-btn" onclick="setEmoji('✨')">✨</button>
                <button class="emoji-lg-btn" onclick="setEmoji('📱')">📱</button>
                <button class="emoji-lg-btn" onclick="setEmoji('📧')">📧</button>
                <button class="emoji-lg-btn" onclick="setEmoji('📍')">📍</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🚚')">🚚</button>
                <button class="emoji-lg-btn" onclick="setEmoji('⏰')">⏰</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🔔')">🔔</button>
                <button class="emoji-lg-btn" onclick="setEmoji('👤')">👤</button>
                <button class="emoji-lg-btn" onclick="setEmoji('👥')">👥</button>
                <button class="emoji-lg-btn" onclick="setEmoji('💬')">💬</button>
                <button class="emoji-lg-btn" onclick="setEmoji('📞')">📞</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🌟')">🌟</button>
                <button class="emoji-lg-btn" onclick="setEmoji('🎉')">🎉</button>
            </div>
        </div>
    </div>
</div>

<script>
// ============ ESTADO ============
const App = {
    sel: null,
    iframe: null,
    doc: null,
    history: [],
    historyIdx: -1,
    theme: { primary: '#6366f1', secondary: '#10b981', accent: '#f59e0b', font: 'Inter' },
    pageKey: '<?php echo $page_key; ?>'
};

// ============ INIT ============
document.addEventListener('DOMContentLoaded', () => {
    lucide.createIcons();
    initTabs();
    initViewport();
    loadPage();
});

function initTabs() {
    document.querySelectorAll('.sidebar-tab').forEach(t => {
        t.onclick = () => {
            document.querySelectorAll('.sidebar-tab').forEach(x => x.classList.remove('active'));
            t.classList.add('active');
            document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
            document.getElementById('panel-' + t.dataset.panel).classList.add('active');
        };
    });
}

function initViewport() {
    document.querySelectorAll('.vp-btn').forEach(b => {
        b.onclick = () => {
            document.querySelectorAll('.vp-btn').forEach(x => x.classList.remove('active'));
            b.classList.add('active');
            document.getElementById('canvas-frame').className = 'canvas-frame ' + b.dataset.vp;
        };
    });
}

// ============ LOAD PAGE ============
function loadPage() {
    App.iframe = document.getElementById('page-frame');
    App.iframe.srcdoc = getPageHTML();
    App.iframe.onload = () => {
        App.doc = App.iframe.contentDocument;
        setupEditor();
        saveHistory();
        updateLayers();
    };
}

function getPageHTML() {
    return `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root { --primary:#6366f1; --secondary:#10b981; --accent:#f59e0b; --text:#1f2937; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; color:var(--text); }

[data-e] { position:relative; outline:2px solid transparent; outline-offset:2px; transition:outline 0.15s; cursor:pointer; min-height:20px; }
[data-e]:hover { outline-color:rgba(99,102,241,0.4); }
[data-e].sel { outline-color:#6366f1; }
[data-e].sel::before { content:attr(data-label); position:absolute; top:-22px; left:0; background:#6366f1; color:#fff; font-size:9px; font-weight:600; padding:2px 6px; border-radius:3px; z-index:9999; white-space:nowrap; }
.drag-over { outline-color:#10b981 !important; outline-style:dashed !important; }

.container { max-width:1400px; margin:0 auto; padding:0 20px; }

.header { background:var(--primary); padding:14px 0; }
.header-inner { display:flex; align-items:center; justify-content:space-between; }
.logo { font-size:22px; font-weight:800; color:#fff; display:flex; align-items:center; gap:8px; }
.nav { display:flex; gap:20px; }
.nav a { color:rgba(255,255,255,0.9); text-decoration:none; font-weight:500; font-size:14px; }
.header-actions { display:flex; gap:10px; align-items:center; }
.search-box { display:flex; align-items:center; background:rgba(255,255,255,0.15); border-radius:6px; padding:6px 12px; }
.search-box input { background:none; border:none; color:#fff; outline:none; width:180px; font-size:13px; }
.search-box input::placeholder { color:rgba(255,255,255,0.6); }
.icon-btn { width:36px; height:36px; border-radius:6px; background:rgba(255,255,255,0.1); border:none; color:#fff; cursor:pointer; font-size:16px; display:flex; align-items:center; justify-content:center; }

.banner { background:linear-gradient(135deg,var(--primary),#8b5cf6); padding:70px 0; text-align:center; color:#fff; }
.banner h1 { font-size:42px; font-weight:800; margin-bottom:14px; }
.banner p { font-size:18px; opacity:0.9; margin-bottom:28px; max-width:600px; margin-left:auto; margin-right:auto; }
.btn { display:inline-flex; align-items:center; gap:6px; padding:12px 24px; font-size:15px; font-weight:600; text-decoration:none; border-radius:6px; cursor:pointer; border:none; transition:all 0.2s; }
.btn-white { background:#fff; color:var(--primary); }
.btn-white:hover { transform:translateY(-2px); box-shadow:0 8px 20px rgba(0,0,0,0.2); }

.categories { padding:50px 0; background:#f9fafb; }
.section-title { font-size:28px; font-weight:700; text-align:center; margin-bottom:36px; }
.cat-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:16px; }
.cat-card { background:#fff; border-radius:8px; padding:20px; text-align:center; border:1px solid #e5e7eb; transition:all 0.3s; cursor:pointer; }
.cat-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,0.1); border-color:var(--primary); }
.cat-icon { width:56px; height:56px; margin:0 auto 10px; background:linear-gradient(135deg,var(--primary),#8b5cf6); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:24px; }
.cat-name { font-weight:600; font-size:13px; }

.products { padding:50px 0; }
.prod-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:20px; }
.prod-card { background:#fff; border-radius:8px; overflow:hidden; border:1px solid #e5e7eb; transition:all 0.3s; }
.prod-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,0.1); }
.prod-img { aspect-ratio:1; background:#f3f4f6; display:flex; align-items:center; justify-content:center; font-size:40px; }
.prod-info { padding:14px; }
.prod-name { font-weight:600; margin-bottom:6px; font-size:13px; }
.prod-price { font-size:18px; font-weight:700; color:var(--primary); }

.cta { padding:70px 0; background:var(--secondary); text-align:center; color:#fff; }
.cta h2 { font-size:32px; font-weight:700; margin-bottom:14px; }
.cta p { font-size:16px; opacity:0.9; margin-bottom:28px; }

.footer { background:#0f172a; color:#fff; padding:50px 0 24px; }
.footer-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:36px; margin-bottom:36px; }
.footer-col h4 { font-weight:700; margin-bottom:14px; font-size:14px; }
.footer-col a { display:block; color:rgba(255,255,255,0.7); text-decoration:none; margin-bottom:8px; font-size:13px; }
.footer-col a:hover { color:#fff; }
.footer-bottom { text-align:center; padding-top:20px; border-top:1px solid rgba(255,255,255,0.1); color:rgba(255,255,255,0.5); font-size:12px; }

@media(max-width:1024px) { .cat-grid{grid-template-columns:repeat(3,1fr)} .prod-grid{grid-template-columns:repeat(3,1fr)} }
@media(max-width:768px) { .cat-grid{grid-template-columns:repeat(2,1fr)} .prod-grid{grid-template-columns:repeat(2,1fr)} .banner h1{font-size:28px} .nav{display:none} }
</style>
</head>
<body>
<header class="header" data-e data-label="Header" data-id="header">
    <div class="container">
        <div class="header-inner">
            <div class="logo" data-e data-label="Logo" data-id="logo">🛒 OneMundo</div>
            <nav class="nav" data-e data-label="Menu" data-id="nav">
                <a href="#">Início</a>
                <a href="#">Produtos</a>
                <a href="#">Categorias</a>
                <a href="#">Ofertas</a>
                <a href="#">Contato</a>
            </nav>
            <div class="header-actions">
                <div class="search-box" data-e data-label="Busca" data-id="search">
                    <input type="text" placeholder="Buscar produtos...">
                </div>
                <button class="icon-btn" data-e data-label="Carrinho" data-id="cart-btn">🛒</button>
                <button class="icon-btn" data-e data-label="Conta" data-id="acc-btn">👤</button>
            </div>
        </div>
    </div>
</header>

<section class="banner" data-e data-label="Banner" data-id="banner">
    <div class="container">
        <h1 data-e data-label="Título" data-id="banner-h1">Bem-vindo ao OneMundo</h1>
        <p data-e data-label="Subtítulo" data-id="banner-p">Os melhores produtos com entrega rápida para todo Brasil!</p>
        <a href="#" class="btn btn-white" data-e data-label="Botão" data-id="banner-btn">🔥 Ver Ofertas</a>
    </div>
</section>

<section class="categories" data-e data-label="Categorias" data-id="cats">
    <div class="container">
        <h2 class="section-title" data-e data-label="Título" data-id="cats-title">Categorias</h2>
        <div class="cat-grid" data-e data-label="Grid" data-id="cats-grid">
            <div class="cat-card" data-e data-label="Card" data-id="cat1"><div class="cat-icon" data-e data-label="Ícone" data-id="cat1-icon">🍎</div><div class="cat-name" data-e data-label="Nome" data-id="cat1-name">Frutas</div></div>
            <div class="cat-card" data-e data-label="Card" data-id="cat2"><div class="cat-icon" data-e data-label="Ícone" data-id="cat2-icon">🥬</div><div class="cat-name" data-e data-label="Nome" data-id="cat2-name">Verduras</div></div>
            <div class="cat-card" data-e data-label="Card" data-id="cat3"><div class="cat-icon" data-e data-label="Ícone" data-id="cat3-icon">🥛</div><div class="cat-name" data-e data-label="Nome" data-id="cat3-name">Laticínios</div></div>
            <div class="cat-card" data-e data-label="Card" data-id="cat4"><div class="cat-icon" data-e data-label="Ícone" data-id="cat4-icon">🥩</div><div class="cat-name" data-e data-label="Nome" data-id="cat4-name">Carnes</div></div>
            <div class="cat-card" data-e data-label="Card" data-id="cat5"><div class="cat-icon" data-e data-label="Ícone" data-id="cat5-icon">🧹</div><div class="cat-name" data-e data-label="Nome" data-id="cat5-name">Limpeza</div></div>
            <div class="cat-card" data-e data-label="Card" data-id="cat6"><div class="cat-icon" data-e data-label="Ícone" data-id="cat6-icon">🧴</div><div class="cat-name" data-e data-label="Nome" data-id="cat6-name">Higiene</div></div>
        </div>
    </div>
</section>

<section class="products" data-e data-label="Produtos" data-id="prods">
    <div class="container">
        <h2 class="section-title" data-e data-label="Título" data-id="prods-title">🔥 Produtos em Destaque</h2>
        <div class="prod-grid" data-e data-label="Grid" data-id="prods-grid">
            <div class="prod-card" data-e data-label="Produto" data-id="prod1"><div class="prod-img" data-e data-label="Imagem" data-id="prod1-img">📦</div><div class="prod-info"><div class="prod-name" data-e data-label="Nome" data-id="prod1-name">Produto 1</div><div class="prod-price" data-e data-label="Preço" data-id="prod1-price">R$ 29,90</div></div></div>
            <div class="prod-card" data-e data-label="Produto" data-id="prod2"><div class="prod-img" data-e data-label="Imagem" data-id="prod2-img">📦</div><div class="prod-info"><div class="prod-name" data-e data-label="Nome" data-id="prod2-name">Produto 2</div><div class="prod-price" data-e data-label="Preço" data-id="prod2-price">R$ 49,90</div></div></div>
            <div class="prod-card" data-e data-label="Produto" data-id="prod3"><div class="prod-img" data-e data-label="Imagem" data-id="prod3-img">📦</div><div class="prod-info"><div class="prod-name" data-e data-label="Nome" data-id="prod3-name">Produto 3</div><div class="prod-price" data-e data-label="Preço" data-id="prod3-price">R$ 19,90</div></div></div>
            <div class="prod-card" data-e data-label="Produto" data-id="prod4"><div class="prod-img" data-e data-label="Imagem" data-id="prod4-img">📦</div><div class="prod-info"><div class="prod-name" data-e data-label="Nome" data-id="prod4-name">Produto 4</div><div class="prod-price" data-e data-label="Preço" data-id="prod4-price">R$ 39,90</div></div></div>
        </div>
    </div>
</section>

<section class="cta" data-e data-label="CTA" data-id="cta">
    <div class="container">
        <h2 data-e data-label="Título" data-id="cta-h2">🎁 Cadastre-se e Ganhe 10% OFF</h2>
        <p data-e data-label="Texto" data-id="cta-p">Na sua primeira compra. Oferta para novos clientes.</p>
        <a href="#" class="btn btn-white" data-e data-label="Botão" data-id="cta-btn">✅ Criar Conta Grátis</a>
    </div>
</section>

<footer class="footer" data-e data-label="Footer" data-id="footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-col" data-e data-label="Coluna" data-id="footer1"><h4>Institucional</h4><a href="#">Sobre Nós</a><a href="#">Trabalhe Conosco</a><a href="#">Privacidade</a></div>
            <div class="footer-col" data-e data-label="Coluna" data-id="footer2"><h4>Atendimento</h4><a href="#">Central de Ajuda</a><a href="#">Fale Conosco</a><a href="#">Trocas</a></div>
            <div class="footer-col" data-e data-label="Coluna" data-id="footer3"><h4>Pagamento</h4><a href="#">💳 Cartão</a><a href="#">📄 Boleto</a><a href="#">📱 PIX</a></div>
            <div class="footer-col" data-e data-label="Coluna" data-id="footer4"><h4>Redes Sociais</h4><a href="#">📘 Facebook</a><a href="#">📸 Instagram</a><a href="#">💬 WhatsApp</a></div>
        </div>
        <div class="footer-bottom" data-e data-label="Copyright" data-id="footer-bottom">© 2024 OneMundo. Todos os direitos reservados.</div>
    </div>
</footer>
</body>
</html>`;
}

// ============ SETUP EDITOR ============
function setupEditor() {
    App.doc.querySelectorAll('[data-e]').forEach(el => {
        el.onclick = e => { e.preventDefault(); e.stopPropagation(); select(el); };
        el.ondblclick = e => { e.preventDefault(); e.stopPropagation(); editInline(el); };
        
        // Drag
        el.draggable = true;
        el.ondragstart = e => { e.dataTransfer.setData('text', el.dataset.id); el.style.opacity = '0.4'; };
        el.ondragend = () => { el.style.opacity = ''; };
        el.ondragover = e => { e.preventDefault(); el.classList.add('drag-over'); };
        el.ondragleave = () => { el.classList.remove('drag-over'); };
        el.ondrop = e => {
            e.preventDefault();
            el.classList.remove('drag-over');
            const srcId = e.dataTransfer.getData('text');
            const srcEl = App.doc.querySelector(`[data-id="${srcId}"]`);
            if (srcEl && srcEl !== el) {
                el.parentNode.insertBefore(srcEl, el);
                saveHistory();
                updateLayers();
                toast('Elemento movido!', 'success');
            }
        };
    });
    
    App.doc.onclick = e => { if (!e.target.closest('[data-e]')) deselect(); };
    
    // Sortable para grids
    App.doc.querySelectorAll('.cat-grid, .prod-grid, .nav, .footer-grid').forEach(grid => {
        new Sortable(grid, { animation: 150, ghostClass: 'drag-over', onEnd: () => { saveHistory(); updateLayers(); } });
    });
}

// ============ SELECT ============
function select(el) {
    if (App.sel) App.sel.classList.remove('sel');
    el.classList.add('sel');
    App.sel = el;
    showProps(el);
    highlightLayer(el.dataset.id);
}

function deselect() {
    if (App.sel) App.sel.classList.remove('sel');
    App.sel = null;
    document.getElementById('no-selection').style.display = 'flex';
    document.getElementById('props-form').style.display = 'none';
    document.getElementById('props-badge').textContent = 'Nenhum';
}

// ============ INLINE EDIT ============
function editInline(el) {
    el.contentEditable = true;
    el.focus();
    const range = App.doc.createRange();
    range.selectNodeContents(el);
    const sel = App.iframe.contentWindow.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    
    el.onblur = () => { el.contentEditable = false; saveHistory(); };
    el.onkeydown = e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); el.blur(); } };
}

// ============ PROPS ============
function showProps(el) {
    document.getElementById('props-badge').textContent = el.dataset.label || 'Elemento';
    document.getElementById('no-selection').style.display = 'none';
    document.getElementById('props-form').style.display = 'block';
    
    const s = App.iframe.contentWindow.getComputedStyle(el);
    document.getElementById('props-form').innerHTML = genProps(el, s);
    lucide.createIcons();
}

function genProps(el, s) {
    const bg = rgb2hex(s.backgroundColor);
    const color = rgb2hex(s.color);
    const fs = parseInt(s.fontSize);
    const pt = parseInt(s.paddingTop)||0, pr = parseInt(s.paddingRight)||0, pb = parseInt(s.paddingBottom)||0, pl = parseInt(s.paddingLeft)||0;
    const rad = parseInt(s.borderRadius)||0;
    
    return `
    <div class="props-section">
        <div class="section-title"><i data-lucide="palette" style="width:10px;height:10px"></i> Cores</div>
        <div class="form-group">
            <label class="form-label">Fundo</label>
            <div class="color-row">
                <div class="color-swatch" style="background:${bg}"><input type="color" value="${bg}" onchange="css('backgroundColor',this.value)"></div>
                <input type="text" class="form-input" value="${bg}" onchange="css('backgroundColor',this.value)">
            </div>
            <div class="color-presets">
                <div class="color-preset" style="background:#fff" onclick="css('backgroundColor','#fff')"></div>
                <div class="color-preset" style="background:#f9fafb" onclick="css('backgroundColor','#f9fafb')"></div>
                <div class="color-preset" style="background:#1f2937" onclick="css('backgroundColor','#1f2937')"></div>
                <div class="color-preset" style="background:#6366f1" onclick="css('backgroundColor','#6366f1')"></div>
                <div class="color-preset" style="background:#10b981" onclick="css('backgroundColor','#10b981')"></div>
                <div class="color-preset" style="background:#f59e0b" onclick="css('backgroundColor','#f59e0b')"></div>
                <div class="color-preset" style="background:#ef4444" onclick="css('backgroundColor','#ef4444')"></div>
                <div class="color-preset" style="background:#8b5cf6" onclick="css('backgroundColor','#8b5cf6')"></div>
                <div class="color-preset" style="background:#ec4899" onclick="css('backgroundColor','#ec4899')"></div>
                <div class="color-preset" style="background:#0ea5e9" onclick="css('backgroundColor','#0ea5e9')"></div>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Gradiente</label>
            <div class="gradient-grid">
                <div class="gradient-btn" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)" onclick="css('background','linear-gradient(135deg,#6366f1,#8b5cf6)')"></div>
                <div class="gradient-btn" style="background:linear-gradient(135deg,#10b981,#06b6d4)" onclick="css('background','linear-gradient(135deg,#10b981,#06b6d4)')"></div>
                <div class="gradient-btn" style="background:linear-gradient(135deg,#f59e0b,#ef4444)" onclick="css('background','linear-gradient(135deg,#f59e0b,#ef4444)')"></div>
                <div class="gradient-btn" style="background:linear-gradient(135deg,#ec4899,#8b5cf6)" onclick="css('background','linear-gradient(135deg,#ec4899,#8b5cf6)')"></div>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Texto</label>
            <div class="color-row">
                <div class="color-swatch" style="background:${color}"><input type="color" value="${color}" onchange="css('color',this.value)"></div>
                <input type="text" class="form-input" value="${color}" onchange="css('color',this.value)">
            </div>
        </div>
    </div>
    
    <div class="props-section">
        <div class="section-title"><i data-lucide="type" style="width:10px;height:10px"></i> Tipografia</div>
        <div class="form-group">
            <label class="form-label">Tamanho</label>
            <div class="range-row">
                <input type="range" class="range-slider" min="8" max="72" value="${fs}" oninput="css('fontSize',this.value+'px');this.nextElementSibling.textContent=this.value+'px'">
                <span class="range-val">${fs}px</span>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Peso</label>
            <select class="form-select" onchange="css('fontWeight',this.value)">
                <option value="300" ${s.fontWeight=='300'?'selected':''}>Light</option>
                <option value="400" ${s.fontWeight=='400'?'selected':''}>Normal</option>
                <option value="500" ${s.fontWeight=='500'?'selected':''}>Medium</option>
                <option value="600" ${s.fontWeight=='600'?'selected':''}>Semibold</option>
                <option value="700" ${s.fontWeight=='700'?'selected':''}>Bold</option>
                <option value="800" ${s.fontWeight=='800'?'selected':''}>Extra Bold</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Alinhamento</label>
            <div class="align-row">
                <button class="align-btn ${s.textAlign=='left'||s.textAlign=='start'?'active':''}" onclick="css('textAlign','left')"><i data-lucide="align-left" style="width:14px;height:14px"></i></button>
                <button class="align-btn ${s.textAlign=='center'?'active':''}" onclick="css('textAlign','center')"><i data-lucide="align-center" style="width:14px;height:14px"></i></button>
                <button class="align-btn ${s.textAlign=='right'?'active':''}" onclick="css('textAlign','right')"><i data-lucide="align-right" style="width:14px;height:14px"></i></button>
            </div>
        </div>
    </div>
    
    <div class="props-section">
        <div class="section-title"><i data-lucide="move" style="width:10px;height:10px"></i> Espaçamento</div>
        <div class="spacing-box">
            <input type="number" class="spacing-input spacing-top" value="${pt}" onchange="css('paddingTop',this.value+'px')">
            <input type="number" class="spacing-input spacing-left" value="${pl}" onchange="css('paddingLeft',this.value+'px')">
            <div class="spacing-center">Padding</div>
            <input type="number" class="spacing-input spacing-right" value="${pr}" onchange="css('paddingRight',this.value+'px')">
            <input type="number" class="spacing-input spacing-bottom" value="${pb}" onchange="css('paddingBottom',this.value+'px')">
        </div>
    </div>
    
    <div class="props-section">
        <div class="section-title"><i data-lucide="square" style="width:10px;height:10px"></i> Borda</div>
        <div class="form-group">
            <label class="form-label">Arredondamento</label>
            <div class="range-row">
                <input type="range" class="range-slider" min="0" max="50" value="${rad}" oninput="css('borderRadius',this.value+'px');this.nextElementSibling.textContent=this.value+'px'">
                <span class="range-val">${rad}px</span>
            </div>
        </div>
    </div>
    
    <div class="props-section">
        <div class="section-title"><i data-lucide="move-vertical" style="width:10px;height:10px"></i> Posição</div>
        <div class="actions-row">
            <button class="btn btn-outline" onclick="moveUp()"><i data-lucide="arrow-up" style="width:12px;height:12px"></i> Subir</button>
            <button class="btn btn-outline" onclick="moveDown()"><i data-lucide="arrow-down" style="width:12px;height:12px"></i> Descer</button>
        </div>
    </div>
    
    <div class="props-section">
        <div class="section-title"><i data-lucide="settings" style="width:10px;height:10px"></i> Ações</div>
        <div class="actions-row">
            <button class="btn btn-outline" onclick="duplicate()"><i data-lucide="copy" style="width:12px;height:12px"></i> Duplicar</button>
            <button class="btn" style="background:var(--danger);color:#fff" onclick="remove()"><i data-lucide="trash-2" style="width:12px;height:12px"></i> Excluir</button>
        </div>
    </div>
    `;
}

function css(prop, val) {
    if (App.sel) { App.sel.style[prop] = val; saveHistory(); }
}

// ============ ACTIONS ============
function moveUp() {
    if (App.sel && App.sel.previousElementSibling) {
        App.sel.parentNode.insertBefore(App.sel, App.sel.previousElementSibling);
        saveHistory(); updateLayers(); toast('Movido!', 'success');
    }
}

function moveDown() {
    if (App.sel && App.sel.nextElementSibling) {
        App.sel.parentNode.insertBefore(App.sel.nextElementSibling, App.sel);
        saveHistory(); updateLayers(); toast('Movido!', 'success');
    }
}

function duplicate() {
    if (!App.sel) return;
    const clone = App.sel.cloneNode(true);
    clone.dataset.id = 'el' + Date.now();
    clone.classList.remove('sel');
    App.sel.parentNode.insertBefore(clone, App.sel.nextSibling);
    setupEditor(); select(clone); saveHistory(); updateLayers();
    toast('Duplicado!', 'success');
}

function remove() {
    if (!App.sel) return;
    if (confirm('Excluir?')) {
        App.sel.remove(); deselect(); saveHistory(); updateLayers();
        toast('Excluído!');
    }
}

// ============ EMOJI ============
function setEmoji(emoji) {
    if (App.sel) { App.sel.textContent = emoji; saveHistory(); toast('Emoji alterado!', 'success'); }
    else { toast('Selecione um elemento', 'error'); }
    closeEmojis();
}

function openEmojis() { document.getElementById('emoji-modal').classList.add('show'); }
function closeEmojis() { document.getElementById('emoji-modal').classList.remove('show'); }

// ============ LAYERS ============
function updateLayers() {
    const list = document.getElementById('layers-list');
    let html = '';
    App.doc.querySelectorAll('[data-e]').forEach(el => {
        const active = el.classList.contains('sel') ? 'active' : '';
        html += `<div class="layer ${active}" onclick="selectById('${el.dataset.id}')">
            <i data-lucide="square" class="layer-icon"></i>
            <span class="layer-name">${el.dataset.label}</span>
        </div>`;
    });
    list.innerHTML = html;
    lucide.createIcons();
}

function selectById(id) {
    const el = App.doc.querySelector(`[data-id="${id}"]`);
    if (el) { select(el); el.scrollIntoView({behavior:'smooth',block:'center'}); }
}

function highlightLayer(id) {
    document.querySelectorAll('.layer').forEach(l => l.classList.remove('active'));
}

// ============ THEME ============
function setTheme(key, val) {
    App.theme[key] = val;
    if (key === 'font') {
        App.doc.body.style.fontFamily = `'${val}', sans-serif`;
    } else {
        App.doc.documentElement.style.setProperty('--' + key, val);
    }
    document.getElementById('theme-' + key).style.background = val;
    saveHistory();
}

// ============ HISTORY ============
function saveHistory() {
    const html = App.doc.body.innerHTML;
    App.history = App.history.slice(0, App.historyIdx + 1);
    App.history.push(html);
    if (App.history.length > 50) App.history.shift();
    App.historyIdx = App.history.length - 1;
    document.getElementById('last-save').textContent = new Date().toLocaleTimeString();
}

function undo() {
    if (App.historyIdx > 0) {
        App.historyIdx--;
        App.doc.body.innerHTML = App.history[App.historyIdx];
        setupEditor(); deselect(); updateLayers();
        toast('Desfeito!');
    }
}

function redo() {
    if (App.historyIdx < App.history.length - 1) {
        App.historyIdx++;
        App.doc.body.innerHTML = App.history[App.historyIdx];
        setupEditor(); deselect(); updateLayers();
        toast('Refeito!');
    }
}

// ============ SAVE ============
function save() {
    const html = App.doc.body.innerHTML;
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'save',
            page_key: App.pageKey,
            html: html,
            theme: App.theme
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            toast('Salvo com sucesso!', 'success');
        } else {
            toast('Erro: ' + (data.error || 'Desconhecido'), 'error');
            // Fallback local
            localStorage.setItem('onemundo_' + App.pageKey, html);
        }
    })
    .catch(err => {
        localStorage.setItem('onemundo_' + App.pageKey, html);
        toast('Salvo localmente!', 'success');
    });
}

function publish() {
    save();
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'publish', page_key: App.pageKey })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) toast('Publicado!', 'success');
    });
}

function preview() {
    const html = App.doc.documentElement.outerHTML;
    const win = window.open('', '_blank');
    win.document.write(html);
    win.document.close();
}

// ============ UTILS ============
function rgb2hex(rgb) {
    if (!rgb || rgb === 'transparent' || rgb === 'rgba(0, 0, 0, 0)') return '#ffffff';
    const m = rgb.match(/\d+/g);
    if (!m || m.length < 3) return '#ffffff';
    return '#' + m.slice(0,3).map(x => parseInt(x).toString(16).padStart(2,'0')).join('');
}

function toast(msg, type = 'info') {
    const box = document.getElementById('toast-box');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.innerHTML = `<i data-lucide="${type==='success'?'check-circle':type==='error'?'x-circle':'info'}" style="width:16px;height:16px"></i><span style="font-size:12px">${msg}</span>`;
    box.appendChild(t);
    lucide.createIcons();
    setTimeout(() => t.remove(), 3000);
}

// ============ SHORTCUTS ============
document.addEventListener('keydown', e => {
    if ((e.ctrlKey||e.metaKey) && e.key === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
    if ((e.ctrlKey||e.metaKey) && e.key === 'z' && e.shiftKey) { e.preventDefault(); redo(); }
    if ((e.ctrlKey||e.metaKey) && e.key === 's') { e.preventDefault(); save(); }
    if (e.key === 'Delete' && App.sel) remove();
    if (e.key === 'Escape') deselect();
});
</script>
</body>
</html>
