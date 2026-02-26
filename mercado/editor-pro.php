<?php
/**
 * Editor Visual Pro - Mercado OneMundo
 * Arquivo principal do editor
 * 
 * Acesso: /mercado/admin/editor-pro.php
 */

// Verificar autenticação (ajuste conforme seu sistema)
session_start();
// if (!isset($_SESSION['admin_logged'])) {
//     header('Location: /mercado/admin/login.php');
//     exit;
// }

// Configurações
$page_to_edit = $_GET['page'] ?? 'index';
$mercado_url = '/mercado/index.php';

// Carregar página salva do banco se existir
$saved_page = null;
try {
    $pdo = new PDO("mysql:host=localhost;dbname=love1;charset=utf8mb4", 'root', '');
    $stmt = $pdo->prepare("SELECT * FROM om_editor_pages WHERE page_key = ?");
    $stmt->execute([$page_to_edit]);
    $saved_page = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignorar se não conseguir conectar
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor Visual Pro - Mercado OneMundo</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #818cf8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #0f172a;
            --dark-2: #1e293b;
            --dark-3: #334155;
            --gray: #64748b;
            --gray-light: #94a3b8;
            --light: #f1f5f9;
            --light-2: #e2e8f0;
            --white: #ffffff;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 25px -5px rgba(0,0,0,0.15);
            --shadow-xl: 0 25px 50px -12px rgba(0,0,0,0.25);
            --radius-sm: 4px;
            --radius: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
            --transition: all 0.2s ease;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--dark);
            color: var(--white);
            overflow: hidden;
            height: 100vh;
        }
        
        /* Toolbar */
        .toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 52px;
            background: var(--dark);
            border-bottom: 1px solid var(--dark-3);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 12px;
            z-index: 10000;
        }
        
        .toolbar-section {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .toolbar-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-right: 16px;
            border-right: 1px solid var(--dark-3);
            margin-right: 8px;
        }
        
        .toolbar-logo-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary), #ec4899);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .toolbar-logo span {
            font-weight: 700;
            font-size: 14px;
            background: linear-gradient(135deg, var(--primary-light), #f472b6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .toolbar-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 12px;
            background: transparent;
            border: none;
            color: var(--gray-light);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border-radius: var(--radius);
            transition: var(--transition);
        }
        
        .toolbar-btn:hover {
            background: var(--dark-2);
            color: var(--white);
        }
        
        .toolbar-btn.active {
            background: var(--primary);
            color: var(--white);
        }
        
        .toolbar-divider {
            width: 1px;
            height: 24px;
            background: var(--dark-3);
            margin: 0 4px;
        }
        
        .viewport-group {
            display: flex;
            background: var(--dark-2);
            border-radius: var(--radius);
            padding: 2px;
        }
        
        .viewport-btn {
            width: 32px;
            height: 28px;
            border: none;
            background: transparent;
            color: var(--gray);
            cursor: pointer;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }
        
        .viewport-btn:hover { color: var(--white); }
        .viewport-btn.active { background: var(--primary); color: var(--white); }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            border: none;
        }
        
        .btn-ghost { background: transparent; color: var(--gray-light); }
        .btn-ghost:hover { background: var(--dark-2); color: var(--white); }
        .btn-outline { background: transparent; border: 1px solid var(--dark-3); color: var(--gray-light); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn-primary { background: var(--primary); color: var(--white); }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-success { background: var(--success); color: var(--white); }
        .btn-success:hover { background: #059669; }
        
        /* Layout */
        .editor-layout {
            display: flex;
            height: calc(100vh - 52px);
            margin-top: 52px;
        }
        
        /* Sidebar Esquerda */
        .sidebar-left {
            width: 280px;
            background: var(--dark-2);
            border-right: 1px solid var(--dark-3);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .sidebar-nav {
            display: flex;
            border-bottom: 1px solid var(--dark-3);
        }
        
        .sidebar-nav-btn {
            flex: 1;
            padding: 14px 8px;
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            font-weight: 500;
            transition: var(--transition);
            border-bottom: 2px solid transparent;
        }
        
        .sidebar-nav-btn:hover { color: var(--white); background: var(--dark-3); }
        .sidebar-nav-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
        
        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }
        
        .sidebar-panel { display: none; }
        .sidebar-panel.active { display: block; }
        
        .panel-section { margin-bottom: 20px; }
        
        .panel-section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray);
            margin-bottom: 12px;
        }
        
        .widgets-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
        
        .widget-item {
            background: var(--dark-3);
            border: 1px solid transparent;
            border-radius: var(--radius);
            padding: 12px 8px;
            text-align: center;
            cursor: grab;
            transition: var(--transition);
        }
        
        .widget-item:hover {
            border-color: var(--primary);
            background: rgba(99, 102, 241, 0.1);
        }
        
        .widget-icon {
            width: 28px;
            height: 28px;
            margin: 0 auto 6px;
            color: var(--gray-light);
        }
        
        .widget-item:hover .widget-icon { color: var(--primary); }
        
        .widget-name {
            font-size: 11px;
            font-weight: 500;
            color: var(--gray-light);
        }
        
        /* Canvas */
        .canvas-wrapper {
            flex: 1;
            background: var(--dark);
            overflow: auto;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 24px;
        }
        
        .canvas-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
            transition: width 0.3s ease;
            width: 100%;
            max-width: 100%;
        }
        
        .canvas-container.tablet { max-width: 768px; }
        .canvas-container.mobile { max-width: 375px; }
        
        .canvas-iframe {
            width: 100%;
            height: calc(100vh - 100px);
            border: none;
            background: var(--white);
        }
        
        /* Sidebar Direita */
        .sidebar-right {
            width: 320px;
            background: var(--dark-2);
            border-left: 1px solid var(--dark-3);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .props-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--dark-3);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .props-title { font-size: 13px; font-weight: 700; }
        
        .props-badge {
            font-size: 11px;
            padding: 3px 8px;
            background: var(--primary);
            border-radius: var(--radius-sm);
        }
        
        .props-tabs {
            display: flex;
            padding: 8px;
            gap: 4px;
            border-bottom: 1px solid var(--dark-3);
        }
        
        .props-tab {
            flex: 1;
            padding: 8px 12px;
            background: none;
            border: none;
            color: var(--gray);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border-radius: var(--radius);
            transition: var(--transition);
        }
        
        .props-tab:hover { background: var(--dark-3); color: var(--white); }
        .props-tab.active { background: var(--primary); color: var(--white); }
        
        .props-content {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }
        
        .props-section { margin-bottom: 20px; }
        
        .props-section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .form-group { margin-bottom: 14px; }
        
        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 500;
            color: var(--gray-light);
            margin-bottom: 6px;
        }
        
        .form-input {
            width: 100%;
            padding: 10px 12px;
            background: var(--dark-3);
            border: 1px solid var(--dark-3);
            border-radius: var(--radius);
            color: var(--white);
            font-size: 13px;
            transition: var(--transition);
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            background: var(--dark);
        }
        
        .form-select {
            width: 100%;
            padding: 10px 12px;
            background: var(--dark-3);
            border: 1px solid var(--dark-3);
            border-radius: var(--radius);
            color: var(--white);
            font-size: 13px;
            cursor: pointer;
        }
        
        /* Color Picker */
        .color-picker-row {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .color-swatch {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            border: 2px solid var(--dark-3);
            overflow: hidden;
            cursor: pointer;
            flex-shrink: 0;
            position: relative;
        }
        
        .color-swatch input[type="color"] {
            position: absolute;
            width: 60px;
            height: 60px;
            top: -10px;
            left: -10px;
            border: none;
            cursor: pointer;
        }
        
        .color-presets {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }
        
        .color-preset {
            width: 24px;
            height: 24px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            border: 2px solid transparent;
            transition: var(--transition);
        }
        
        .color-preset:hover {
            transform: scale(1.15);
            border-color: var(--white);
        }
        
        /* Range Slider */
        .range-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .range-slider {
            flex: 1;
            height: 6px;
            -webkit-appearance: none;
            background: var(--dark-3);
            border-radius: 3px;
            outline: none;
        }
        
        .range-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            background: var(--primary);
            border-radius: 50%;
            cursor: pointer;
        }
        
        .range-value {
            min-width: 45px;
            text-align: right;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-light);
        }
        
        /* Spacing Control */
        .spacing-control {
            display: grid;
            grid-template-columns: 1fr 2fr 1fr;
            grid-template-rows: auto auto auto;
            gap: 4px;
        }
        
        .spacing-input {
            width: 100%;
            padding: 6px;
            background: var(--dark-3);
            border: 1px solid var(--dark-3);
            border-radius: var(--radius-sm);
            color: var(--white);
            text-align: center;
            font-size: 11px;
        }
        
        .spacing-input:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        .spacing-center {
            grid-column: 2;
            grid-row: 2;
            background: var(--dark);
            border: 1px dashed var(--dark-3);
            border-radius: var(--radius-sm);
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: var(--gray);
        }
        
        .spacing-top { grid-column: 2; grid-row: 1; }
        .spacing-right { grid-column: 3; grid-row: 2; }
        .spacing-bottom { grid-column: 2; grid-row: 3; }
        .spacing-left { grid-column: 1; grid-row: 2; }
        
        /* Alignment Buttons */
        .align-buttons {
            display: flex;
            gap: 2px;
            background: var(--dark-3);
            border-radius: var(--radius);
            padding: 2px;
        }
        
        .align-btn {
            flex: 1;
            padding: 8px;
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }
        
        .align-btn:hover { color: var(--white); }
        .align-btn.active { background: var(--primary); color: var(--white); }
        
        /* No Selection */
        .no-selection {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            text-align: center;
            padding: 40px 20px;
        }
        
        .no-selection-icon {
            width: 64px;
            height: 64px;
            color: var(--dark-3);
            margin-bottom: 16px;
        }
        
        .no-selection-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .no-selection-text {
            font-size: 13px;
            color: var(--gray);
            line-height: 1.5;
        }
        
        /* Bottom Bar */
        .bottom-bar {
            position: fixed;
            bottom: 0;
            left: 280px;
            right: 320px;
            height: 36px;
            background: var(--dark-2);
            border-top: 1px solid var(--dark-3);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 9999;
            font-size: 12px;
            color: var(--gray);
        }
        
        /* Toast */
        .toast-container {
            position: fixed;
            bottom: 60px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 999999;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .toast {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            background: var(--dark-2);
            border: 1px solid var(--dark-3);
            color: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            animation: toastIn 0.3s ease;
        }
        
        .toast.success { border-color: var(--success); }
        .toast.error { border-color: var(--danger); }
        
        @keyframes toastIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--dark); }
        ::-webkit-scrollbar-thumb { background: var(--dark-3); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--gray); }
        
        /* Layers */
        .layers-list {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .layer-item {
            display: flex;
            align-items: center;
            padding: 8px 10px;
            background: var(--dark-3);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
            gap: 8px;
        }
        
        .layer-item:hover { background: var(--dark); }
        .layer-item.selected { background: var(--primary); }
        
        .layer-name {
            flex: 1;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Global Styles */
        .global-colors-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        .global-color-card {
            background: var(--dark-3);
            border-radius: var(--radius);
            padding: 12px;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .global-color-card:hover { background: var(--dark); }
        
        .global-color-preview {
            height: 40px;
            border-radius: var(--radius-sm);
            margin-bottom: 8px;
        }
        
        .global-color-label {
            font-size: 11px;
            color: var(--gray);
        }
    </style>
</head>
<body>
    <!-- TOOLBAR -->
    <header class="toolbar">
        <div class="toolbar-section">
            <div class="toolbar-logo">
                <div class="toolbar-logo-icon">
                    <i data-lucide="sparkles" style="width:18px;height:18px;color:white"></i>
                </div>
                <span>OneMundo Editor</span>
            </div>
            
            <button class="toolbar-btn" onclick="undo()" title="Desfazer (Ctrl+Z)">
                <i data-lucide="undo-2" style="width:18px;height:18px"></i>
            </button>
            <button class="toolbar-btn" onclick="redo()" title="Refazer">
                <i data-lucide="redo-2" style="width:18px;height:18px"></i>
            </button>
            
            <div class="toolbar-divider"></div>
            
            <div class="viewport-group">
                <button class="viewport-btn active" data-viewport="desktop" title="Desktop">
                    <i data-lucide="monitor" style="width:16px;height:16px"></i>
                </button>
                <button class="viewport-btn" data-viewport="tablet" title="Tablet">
                    <i data-lucide="tablet" style="width:16px;height:16px"></i>
                </button>
                <button class="viewport-btn" data-viewport="mobile" title="Mobile">
                    <i data-lucide="smartphone" style="width:16px;height:16px"></i>
                </button>
            </div>
        </div>
        
        <div class="toolbar-section">
            <button class="toolbar-btn" onclick="previewPage()">
                <i data-lucide="eye" style="width:16px;height:16px"></i>
                Preview
            </button>
            
            <div class="toolbar-divider"></div>
            
            <button class="btn btn-outline" onclick="saveDraft()">
                <i data-lucide="save" style="width:16px;height:16px"></i>
                Salvar
            </button>
            <button class="btn btn-success" onclick="publishPage()">
                <i data-lucide="rocket" style="width:16px;height:16px"></i>
                Publicar
            </button>
        </div>
    </header>
    
    <!-- LAYOUT -->
    <div class="editor-layout">
        <!-- SIDEBAR ESQUERDA -->
        <aside class="sidebar-left">
            <nav class="sidebar-nav">
                <button class="sidebar-nav-btn active" data-panel="widgets">
                    <i data-lucide="plus-square" style="width:18px;height:18px"></i>
                    Adicionar
                </button>
                <button class="sidebar-nav-btn" data-panel="layers">
                    <i data-lucide="layers" style="width:18px;height:18px"></i>
                    Camadas
                </button>
                <button class="sidebar-nav-btn" data-panel="global">
                    <i data-lucide="palette" style="width:18px;height:18px"></i>
                    Global
                </button>
            </nav>
            
            <div class="sidebar-content">
                <!-- WIDGETS -->
                <div class="sidebar-panel active" id="panelWidgets">
                    <div class="panel-section">
                        <div class="panel-section-title">Layout</div>
                        <div class="widgets-grid">
                            <div class="widget-item" draggable="true" data-widget="section">
                                <i data-lucide="square" class="widget-icon"></i>
                                <div class="widget-name">Seção</div>
                            </div>
                            <div class="widget-item" draggable="true" data-widget="container">
                                <i data-lucide="box" class="widget-icon"></i>
                                <div class="widget-name">Container</div>
                            </div>
                            <div class="widget-item" draggable="true" data-widget="columns">
                                <i data-lucide="columns" class="widget-icon"></i>
                                <div class="widget-name">Colunas</div>
                            </div>
                            <div class="widget-item" draggable="true" data-widget="spacer">
                                <i data-lucide="move-vertical" class="widget-icon"></i>
                                <div class="widget-name">Espaçador</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="panel-section">
                        <div class="panel-section-title">Básico</div>
                        <div class="widgets-grid">
                            <div class="widget-item" draggable="true" data-widget="heading">
                                <i data-lucide="type" class="widget-icon"></i>
                                <div class="widget-name">Título</div>
                            </div>
                            <div class="widget-item" draggable="true" data-widget="text">
                                <i data-lucide="align-left" class="widget-icon"></i>
                                <div class="widget-name">Texto</div>
                            </div>
                            <div class="widget-item" draggable="true" data-widget="button">
                                <i data-lucide="mouse-pointer-click" class="widget-icon"></i>
                                <div class="widget-name">Botão</div>
                            </div>
                            <div class="widget-item" draggable="true" data-widget="image">
                                <i data-lucide="image" class="widget-icon"></i>
                                <div class="widget-name">Imagem</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="panel-section">
                        <div class="panel-section-title">E-commerce</div>
                        <div class="widgets-grid">
                            <div class="widget-item" draggable="true" data-widget="products">
                                <i data-lucide="package" class="widget-icon"></i>
                                <div class="widget-name">Produtos</div>
                            </div>
                            <div class="widget-item" draggable="true" data-widget="categories">
                                <i data-lucide="folder" class="widget-icon"></i>
                                <div class="widget-name">Categorias</div>
                            </div>
                            <div class="widget-item" draggable="true" data-widget="cart">
                                <i data-lucide="shopping-cart" class="widget-icon"></i>
                                <div class="widget-name">Carrinho</div>
                            </div>
                            <div class="widget-item" draggable="true" data-widget="search">
                                <i data-lucide="search" class="widget-icon"></i>
                                <div class="widget-name">Busca</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="panel-section">
                        <div class="panel-section-title">Marketing</div>
                        <div class="widgets-grid">
                            <div class="widget-item" draggable="true" data-widget="banner">
                                <i data-lucide="image-plus" class="widget-icon"></i>
                                <div class="widget-name">Banner</div>
                            </div>
                            <div class="widget-item" draggable="true" data-widget="carousel">
                                <i data-lucide="gallery-horizontal" class="widget-icon"></i>
                                <div class="widget-name">Carrossel</div>
                            </div>
                            <div class="widget-item" draggable="true" data-widget="countdown">
                                <i data-lucide="timer" class="widget-icon"></i>
                                <div class="widget-name">Countdown</div>
                            </div>
                            <div class="widget-item" draggable="true" data-widget="newsletter">
                                <i data-lucide="mail" class="widget-icon"></i>
                                <div class="widget-name">Newsletter</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- LAYERS -->
                <div class="sidebar-panel" id="panelLayers">
                    <div class="layers-list" id="layersList"></div>
                </div>
                
                <!-- GLOBAL -->
                <div class="sidebar-panel" id="panelGlobal">
                    <div class="panel-section">
                        <div class="panel-section-title">Cores do Tema</div>
                        <div class="global-colors-grid">
                            <div class="global-color-card" onclick="editGlobalColor('primary')">
                                <div class="global-color-preview" id="globalPrimary" style="background:#6366f1"></div>
                                <div class="global-color-label">Primária</div>
                            </div>
                            <div class="global-color-card" onclick="editGlobalColor('secondary')">
                                <div class="global-color-preview" id="globalSecondary" style="background:#10b981"></div>
                                <div class="global-color-label">Secundária</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="panel-section">
                        <div class="panel-section-title">Tipografia</div>
                        <div class="form-group">
                            <label class="form-label">Fonte Principal</label>
                            <select class="form-select" id="globalFont" onchange="updateGlobalFont(this.value)">
                                <option value="Inter">Inter</option>
                                <option value="Poppins">Poppins</option>
                                <option value="Roboto">Roboto</option>
                                <option value="Open Sans">Open Sans</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
        
        <!-- CANVAS -->
        <main class="canvas-wrapper">
            <div class="canvas-container" id="canvasContainer">
                <iframe id="pageFrame" class="canvas-iframe" src="<?php echo $mercado_url; ?>?editor_mode=1"></iframe>
            </div>
        </main>
        
        <!-- SIDEBAR DIREITA -->
        <aside class="sidebar-right">
            <div class="props-header">
                <span class="props-title">Propriedades</span>
                <span class="props-badge" id="propsBadge">Nenhum</span>
            </div>
            
            <div class="props-tabs">
                <button class="props-tab active" data-tab="style">Estilo</button>
                <button class="props-tab" data-tab="content">Conteúdo</button>
                <button class="props-tab" data-tab="advanced">Avançado</button>
            </div>
            
            <div class="props-content" id="propsContent">
                <div class="no-selection" id="noSelection">
                    <i data-lucide="mouse-pointer-click" class="no-selection-icon"></i>
                    <div class="no-selection-title">Selecione um elemento</div>
                    <div class="no-selection-text">Clique em qualquer elemento na página para editar</div>
                </div>
                <div id="propsForm" style="display:none"></div>
            </div>
        </aside>
    </div>
    
    <!-- BOTTOM BAR -->
    <div class="bottom-bar">
        <div>Página: <strong><?php echo htmlspecialchars($page_to_edit); ?></strong></div>
        <div>Última alteração: <span id="lastSaved">Não salvo</span></div>
    </div>
    
    <!-- TOAST -->
    <div class="toast-container" id="toastContainer"></div>
    
    <script>
        // Estado
        const Editor = {
            selected: null,
            iframe: null,
            doc: null,
            history: [],
            historyIndex: -1,
            globalStyles: {
                primary: '#6366f1',
                secondary: '#10b981'
            }
        };
        
        // Init
        document.addEventListener('DOMContentLoaded', () => {
            lucide.createIcons();
            initPanels();
            initViewport();
            initIframe();
        });
        
        function initIframe() {
            Editor.iframe = document.getElementById('pageFrame');
            Editor.iframe.onload = () => {
                Editor.doc = Editor.iframe.contentDocument;
                injectEditorStyles();
                makeEditable();
                saveToHistory();
                updateLayersList();
            };
        }
        
        function injectEditorStyles() {
            const style = Editor.doc.createElement('style');
            style.textContent = `
                [data-editable] {
                    outline: 2px solid transparent;
                    outline-offset: 2px;
                    transition: outline 0.15s;
                    cursor: pointer;
                }
                [data-editable]:hover {
                    outline-color: rgba(99, 102, 241, 0.5);
                }
                [data-editable].selected {
                    outline-color: #6366f1;
                }
                [data-editable].selected::before {
                    content: attr(data-label);
                    position: absolute;
                    top: -26px;
                    left: 0;
                    background: #6366f1;
                    color: white;
                    font-size: 11px;
                    font-weight: 600;
                    padding: 3px 8px;
                    border-radius: 4px;
                    z-index: 9999;
                }
            `;
            Editor.doc.head.appendChild(style);
        }
        
        function makeEditable() {
            const selectors = 'header,footer,section,nav,div[class],h1,h2,h3,h4,p,a,button,img,.banner,.product,.category';
            Editor.doc.querySelectorAll(selectors).forEach((el, i) => {
                if (!el.hasAttribute('data-editable')) {
                    el.setAttribute('data-editable', 'true');
                    el.setAttribute('data-id', 'el-' + i);
                    el.setAttribute('data-label', getLabel(el));
                    
                    el.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        selectElement(el);
                    });
                    
                    el.addEventListener('dblclick', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        enableInlineEdit(el);
                    });
                }
            });
            
            Editor.doc.addEventListener('click', (e) => {
                if (!e.target.closest('[data-editable]')) {
                    deselectElement();
                }
            });
        }
        
        function getLabel(el) {
            const tag = el.tagName.toLowerCase();
            const cls = el.className.split(' ')[0];
            const labels = {
                header: 'Header', footer: 'Footer', section: 'Seção',
                nav: 'Navegação', h1: 'Título H1', h2: 'Título H2',
                h3: 'Título H3', p: 'Parágrafo', a: 'Link',
                button: 'Botão', img: 'Imagem'
            };
            return labels[tag] || cls || tag;
        }
        
        function selectElement(el) {
            if (Editor.selected) {
                Editor.selected.classList.remove('selected');
            }
            el.classList.add('selected');
            Editor.selected = el;
            showProperties(el);
        }
        
        function deselectElement() {
            if (Editor.selected) {
                Editor.selected.classList.remove('selected');
            }
            Editor.selected = null;
            document.getElementById('noSelection').style.display = 'flex';
            document.getElementById('propsForm').style.display = 'none';
            document.getElementById('propsBadge').textContent = 'Nenhum';
        }
        
        function enableInlineEdit(el) {
            if (!['H1','H2','H3','H4','P','SPAN','A','BUTTON'].includes(el.tagName)) return;
            
            el.contentEditable = true;
            el.focus();
            
            const range = Editor.doc.createRange();
            range.selectNodeContents(el);
            const sel = Editor.iframe.contentWindow.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            
            el.addEventListener('blur', function onBlur() {
                el.contentEditable = false;
                saveToHistory();
                el.removeEventListener('blur', onBlur);
            });
            
            el.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    el.blur();
                }
            });
        }
        
        function showProperties(el) {
            const label = el.getAttribute('data-label') || 'Elemento';
            document.getElementById('propsBadge').textContent = label;
            document.getElementById('noSelection').style.display = 'none';
            document.getElementById('propsForm').style.display = 'block';
            
            const styles = Editor.iframe.contentWindow.getComputedStyle(el);
            generatePropsForm(el, styles);
        }
        
        function generatePropsForm(el, styles) {
            const bgColor = rgbToHex(styles.backgroundColor);
            const textColor = rgbToHex(styles.color);
            const fontSize = parseInt(styles.fontSize);
            const paddingTop = parseInt(styles.paddingTop) || 0;
            const paddingRight = parseInt(styles.paddingRight) || 0;
            const paddingBottom = parseInt(styles.paddingBottom) || 0;
            const paddingLeft = parseInt(styles.paddingLeft) || 0;
            const borderRadius = parseInt(styles.borderRadius) || 0;
            
            document.getElementById('propsForm').innerHTML = `
                <div class="props-section">
                    <div class="props-section-title">
                        <i data-lucide="palette" style="width:14px;height:14px"></i>
                        Cores
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cor de Fundo</label>
                        <div class="color-picker-row">
                            <div class="color-swatch" style="background:${bgColor}">
                                <input type="color" value="${bgColor}" onchange="updateStyle('backgroundColor', this.value)">
                            </div>
                            <input type="text" class="form-input" value="${bgColor}" onchange="updateStyle('backgroundColor', this.value)">
                        </div>
                        <div class="color-presets">
                            <div class="color-preset" style="background:#ffffff" onclick="updateStyle('backgroundColor','#ffffff')"></div>
                            <div class="color-preset" style="background:#f9fafb" onclick="updateStyle('backgroundColor','#f9fafb')"></div>
                            <div class="color-preset" style="background:#1f2937" onclick="updateStyle('backgroundColor','#1f2937')"></div>
                            <div class="color-preset" style="background:#6366f1" onclick="updateStyle('backgroundColor','#6366f1')"></div>
                            <div class="color-preset" style="background:#10b981" onclick="updateStyle('backgroundColor','#10b981')"></div>
                            <div class="color-preset" style="background:#f59e0b" onclick="updateStyle('backgroundColor','#f59e0b')"></div>
                            <div class="color-preset" style="background:#ef4444" onclick="updateStyle('backgroundColor','#ef4444')"></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cor do Texto</label>
                        <div class="color-picker-row">
                            <div class="color-swatch" style="background:${textColor}">
                                <input type="color" value="${textColor}" onchange="updateStyle('color', this.value)">
                            </div>
                            <input type="text" class="form-input" value="${textColor}" onchange="updateStyle('color', this.value)">
                        </div>
                    </div>
                </div>
                
                <div class="props-section">
                    <div class="props-section-title">
                        <i data-lucide="type" style="width:14px;height:14px"></i>
                        Tipografia
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tamanho</label>
                        <div class="range-row">
                            <input type="range" class="range-slider" min="10" max="72" value="${fontSize}" 
                                oninput="updateStyle('fontSize', this.value+'px'); this.nextElementSibling.textContent=this.value+'px'">
                            <span class="range-value">${fontSize}px</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Alinhamento</label>
                        <div class="align-buttons">
                            <button class="align-btn ${styles.textAlign=='left'?'active':''}" onclick="updateStyle('textAlign','left')">
                                <i data-lucide="align-left" style="width:16px;height:16px"></i>
                            </button>
                            <button class="align-btn ${styles.textAlign=='center'?'active':''}" onclick="updateStyle('textAlign','center')">
                                <i data-lucide="align-center" style="width:16px;height:16px"></i>
                            </button>
                            <button class="align-btn ${styles.textAlign=='right'?'active':''}" onclick="updateStyle('textAlign','right')">
                                <i data-lucide="align-right" style="width:16px;height:16px"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="props-section">
                    <div class="props-section-title">
                        <i data-lucide="move" style="width:14px;height:14px"></i>
                        Espaçamento
                    </div>
                    <div class="spacing-control">
                        <input type="number" class="spacing-input spacing-top" value="${paddingTop}" 
                            onchange="updateStyle('paddingTop', this.value+'px')">
                        <input type="number" class="spacing-input spacing-left" value="${paddingLeft}" 
                            onchange="updateStyle('paddingLeft', this.value+'px')">
                        <div class="spacing-center">Elemento</div>
                        <input type="number" class="spacing-input spacing-right" value="${paddingRight}" 
                            onchange="updateStyle('paddingRight', this.value+'px')">
                        <input type="number" class="spacing-input spacing-bottom" value="${paddingBottom}" 
                            onchange="updateStyle('paddingBottom', this.value+'px')">
                    </div>
                </div>
                
                <div class="props-section">
                    <div class="props-section-title">
                        <i data-lucide="square" style="width:14px;height:14px"></i>
                        Borda
                    </div>
                    <div class="form-group">
                        <label class="form-label">Arredondamento</label>
                        <div class="range-row">
                            <input type="range" class="range-slider" min="0" max="50" value="${borderRadius}" 
                                oninput="updateStyle('borderRadius', this.value+'px'); this.nextElementSibling.textContent=this.value+'px'">
                            <span class="range-value">${borderRadius}px</span>
                        </div>
                    </div>
                </div>
                
                <div class="props-section">
                    <div class="props-section-title">Ações</div>
                    <div style="display:flex;gap:8px">
                        <button class="btn btn-outline" style="flex:1" onclick="duplicateElement()">
                            <i data-lucide="copy" style="width:14px;height:14px"></i>
                            Duplicar
                        </button>
                        <button class="btn" style="flex:1;background:var(--danger);color:white" onclick="deleteElement()">
                            <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                            Excluir
                        </button>
                    </div>
                </div>
            `;
            lucide.createIcons();
        }
        
        function updateStyle(prop, value) {
            if (Editor.selected) {
                Editor.selected.style[prop] = value;
                saveToHistory();
            }
        }
        
        function duplicateElement() {
            if (!Editor.selected) return;
            const clone = Editor.selected.cloneNode(true);
            clone.setAttribute('data-id', 'el-' + Date.now());
            clone.classList.remove('selected');
            Editor.selected.parentNode.insertBefore(clone, Editor.selected.nextSibling);
            makeEditable();
            selectElement(clone);
            saveToHistory();
            showToast('Duplicado!', 'success');
        }
        
        function deleteElement() {
            if (!Editor.selected) return;
            if (confirm('Excluir?')) {
                Editor.selected.remove();
                deselectElement();
                saveToHistory();
                updateLayersList();
                showToast('Excluído!');
            }
        }
        
        // Histórico
        function saveToHistory() {
            const html = Editor.doc.body.innerHTML;
            Editor.history = Editor.history.slice(0, Editor.historyIndex + 1);
            Editor.history.push(html);
            Editor.historyIndex = Editor.history.length - 1;
            document.getElementById('lastSaved').textContent = new Date().toLocaleTimeString();
        }
        
        function undo() {
            if (Editor.historyIndex > 0) {
                Editor.historyIndex--;
                Editor.doc.body.innerHTML = Editor.history[Editor.historyIndex];
                makeEditable();
                deselectElement();
                updateLayersList();
            }
        }
        
        function redo() {
            if (Editor.historyIndex < Editor.history.length - 1) {
                Editor.historyIndex++;
                Editor.doc.body.innerHTML = Editor.history[Editor.historyIndex];
                makeEditable();
                deselectElement();
                updateLayersList();
            }
        }
        
        // Salvar
        function saveDraft() {
            const html = Editor.doc.body.innerHTML;
            
            fetch('api/editor-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save',
                    page: '<?php echo $page_to_edit; ?>',
                    html: html,
                    globalStyles: Editor.globalStyles
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast('Salvo!', 'success');
                } else {
                    showToast('Erro ao salvar', 'error');
                }
            })
            .catch(() => {
                localStorage.setItem('editor_draft', html);
                showToast('Salvo localmente!', 'success');
            });
        }
        
        function publishPage() {
            saveDraft();
            
            fetch('api/editor-save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'publish',
                    page: '<?php echo $page_to_edit; ?>'
                })
            })
            .then(r => r.json())
            .then(data => {
                showToast('Publicado!', 'success');
            });
        }
        
        function previewPage() {
            window.open('<?php echo $mercado_url; ?>', '_blank');
        }
        
        // Layers
        function updateLayersList() {
            const elements = Editor.doc.querySelectorAll('[data-editable]');
            const list = document.getElementById('layersList');
            let html = '';
            elements.forEach(el => {
                const id = el.getAttribute('data-id');
                const label = el.getAttribute('data-label');
                html += `<div class="layer-item" onclick="selectById('${id}')">
                    <i data-lucide="square" style="width:14px;height:14px;color:var(--gray)"></i>
                    <span class="layer-name">${label}</span>
                </div>`;
            });
            list.innerHTML = html;
            lucide.createIcons();
        }
        
        function selectById(id) {
            const el = Editor.doc.querySelector('[data-id="' + id + '"]');
            if (el) {
                selectElement(el);
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
        
        // Painéis
        function initPanels() {
            document.querySelectorAll('.sidebar-nav-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const panel = 'panel' + this.dataset.panel.charAt(0).toUpperCase() + this.dataset.panel.slice(1);
                    document.querySelectorAll('.sidebar-nav-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    document.querySelectorAll('.sidebar-panel').forEach(p => p.classList.remove('active'));
                    document.getElementById(panel).classList.add('active');
                });
            });
        }
        
        // Viewport
        function initViewport() {
            document.querySelectorAll('.viewport-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const v = this.dataset.viewport;
                    document.querySelectorAll('.viewport-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    document.getElementById('canvasContainer').className = 'canvas-container ' + v;
                });
            });
        }
        
        // Global
        function editGlobalColor(type) {
            const color = prompt('Cor (hex):', Editor.globalStyles[type]);
            if (color) {
                Editor.globalStyles[type] = color;
                document.getElementById('global' + type.charAt(0).toUpperCase() + type.slice(1)).style.background = color;
                applyGlobalStyles();
            }
        }
        
        function updateGlobalFont(font) {
            Editor.globalStyles.font = font;
            applyGlobalStyles();
        }
        
        function applyGlobalStyles() {
            const root = Editor.doc.documentElement;
            root.style.setProperty('--primary', Editor.globalStyles.primary);
            root.style.setProperty('--secondary', Editor.globalStyles.secondary);
            saveToHistory();
        }
        
        // Utils
        function rgbToHex(rgb) {
            if (!rgb || rgb === 'transparent' || rgb === 'rgba(0, 0, 0, 0)') return '#ffffff';
            const result = rgb.match(/\d+/g);
            if (!result || result.length < 3) return '#ffffff';
            return '#' + result.slice(0, 3).map(x => {
                const hex = parseInt(x).toString(16);
                return hex.length === 1 ? '0' + hex : hex;
            }).join('');
        }
        
        function showToast(msg, type = 'info') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            toast.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'info'}" style="width:18px;height:18px"></i><span>${msg}</span>`;
            container.appendChild(toast);
            lucide.createIcons();
            setTimeout(() => toast.remove(), 3000);
        }
        
        // Atalhos
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
            if ((e.ctrlKey || e.metaKey) && e.key === 'z' && e.shiftKey) { e.preventDefault(); redo(); }
            if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); saveDraft(); }
            if (e.key === 'Delete' && Editor.selected) deleteElement();
        });
    </script>
</body>
</html>
