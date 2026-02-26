<?php
/**
 * Editor Visual - Mercado OneMundo
 * Carrega a página real do Mercado com recursos de edição inline
 */

// Verificar se está logado como admin (ajuste conforme seu sistema de auth)
session_start();

// Configurações
define('MERCADO_URL', '/mercado/index.php');
define('EDITOR_MODE', true);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor Visual - Mercado OneMundo</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1f2937;
            --gray: #6b7280;
            --light: #f3f4f6;
            --white: #ffffff;
            --border: #e5e7eb;
            --shadow: 0 4px 20px rgba(0,0,0,0.15);
            --radius: 8px;
        }
        
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: #e5e5e5;
            overflow: hidden;
        }
        
        /* Toolbar */
        .editor-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 56px;
            background: var(--dark);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 10000;
            box-shadow: var(--shadow);
        }
        
        .toolbar-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .toolbar-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
            font-weight: 700;
            font-size: 15px;
        }
        
        .toolbar-logo-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary), var(--success));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .toolbar-divider {
            width: 1px;
            height: 28px;
            background: rgba(255,255,255,0.2);
        }
        
        .toolbar-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.7);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .toolbar-btn:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .toolbar-btn.active {
            background: var(--primary);
            color: white;
        }
        
        .viewport-btns {
            display: flex;
            background: rgba(255,255,255,0.1);
            border-radius: 6px;
            padding: 2px;
        }
        
        .viewport-btn {
            width: 36px;
            height: 32px;
            border: none;
            background: transparent;
            color: rgba(255,255,255,0.5);
            cursor: pointer;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .viewport-btn:hover { color: white; }
        .viewport-btn.active { background: var(--primary); color: white; }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        
        .btn-ghost { background: transparent; color: rgba(255,255,255,0.7); }
        .btn-ghost:hover { background: rgba(255,255,255,0.1); color: white; }
        .btn-secondary { background: rgba(255,255,255,0.15); color: white; }
        .btn-secondary:hover { background: rgba(255,255,255,0.25); }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #059669; }
        
        /* Sidebar */
        .editor-sidebar {
            position: fixed;
            top: 56px;
            right: 0;
            width: 340px;
            height: calc(100vh - 56px);
            background: white;
            border-left: 1px solid var(--border);
            z-index: 9999;
            display: flex;
            flex-direction: column;
            transform: translateX(0);
            transition: transform 0.3s ease;
            overflow: hidden;
        }
        
        .editor-sidebar.hidden {
            transform: translateX(100%);
        }
        
        .sidebar-header {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .sidebar-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .sidebar-badge {
            font-size: 11px;
            background: var(--light);
            padding: 4px 8px;
            border-radius: 4px;
            color: var(--gray);
        }
        
        .sidebar-tabs {
            display: flex;
            border-bottom: 1px solid var(--border);
        }
        
        .sidebar-tab {
            flex: 1;
            padding: 12px;
            background: none;
            border: none;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .sidebar-tab:hover { color: var(--dark); background: var(--light); }
        .sidebar-tab.active { color: var(--primary); border-bottom: 2px solid var(--primary); margin-bottom: -1px; }
        
        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }
        
        .prop-section {
            margin-bottom: 20px;
        }
        
        .prop-section-title {
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
        
        .prop-group {
            margin-bottom: 14px;
        }
        
        .prop-label {
            display: block;
            font-size: 12px;
            font-weight: 500;
            color: var(--dark);
            margin-bottom: 6px;
        }
        
        .prop-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        .prop-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        
        .prop-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            background: white;
            cursor: pointer;
        }
        
        .prop-textarea {
            min-height: 80px;
            resize: vertical;
        }
        
        /* Color Picker */
        .color-row {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .color-swatch {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 2px solid var(--border);
            cursor: pointer;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .color-swatch input {
            width: 60px;
            height: 60px;
            margin: -10px;
            border: none;
            cursor: pointer;
        }
        
        .color-presets {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        
        .color-preset {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s;
        }
        
        .color-preset:hover {
            transform: scale(1.15);
            border-color: var(--dark);
        }
        
        /* Range */
        .range-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .range-slider {
            flex: 1;
            height: 6px;
            -webkit-appearance: none;
            background: var(--border);
            border-radius: 3px;
        }
        
        .range-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            background: var(--primary);
            border-radius: 50%;
            cursor: pointer;
        }
        
        .range-value {
            min-width: 50px;
            text-align: right;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray);
        }
        
        /* Toggle */
        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .toggle {
            position: relative;
            width: 44px;
            height: 24px;
        }
        
        .toggle input { opacity: 0; width: 0; height: 0; }
        
        .toggle-track {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: var(--border);
            border-radius: 24px;
            transition: 0.3s;
        }
        
        .toggle-track:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.3s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        
        .toggle input:checked + .toggle-track { background: var(--primary); }
        .toggle input:checked + .toggle-track:before { transform: translateX(20px); }
        
        /* Spacing */
        .spacing-grid {
            display: grid;
            grid-template-columns: 1fr 2fr 1fr;
            grid-template-rows: auto auto auto;
            gap: 4px;
        }
        
        .spacing-input {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 4px;
            text-align: center;
            font-size: 12px;
        }
        
        .spacing-center {
            grid-column: 2;
            grid-row: 2;
            background: var(--light);
            border: 1px dashed var(--border);
            border-radius: 4px;
            height: 40px;
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
        
        /* Alignment */
        .align-row {
            display: flex;
            gap: 4px;
        }
        
        .align-btn {
            flex: 1;
            padding: 10px;
            border: 1px solid var(--border);
            background: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .align-btn:first-child { border-radius: 6px 0 0 6px; }
        .align-btn:last-child { border-radius: 0 6px 6px 0; }
        .align-btn:hover { background: var(--light); }
        .align-btn.active { background: var(--primary); border-color: var(--primary); color: white; }
        
        /* Image Upload */
        .image-upload {
            border: 2px dashed var(--border);
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .image-upload:hover {
            border-color: var(--primary);
            background: rgba(37,99,235,0.05);
        }
        
        .image-upload-icon {
            width: 32px;
            height: 32px;
            color: var(--gray);
            margin: 0 auto 8px;
        }
        
        .image-upload-text {
            font-size: 12px;
            color: var(--gray);
        }
        
        .image-upload-text strong {
            color: var(--primary);
        }
        
        /* No Selection */
        .no-selection {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            text-align: center;
            padding: 40px;
        }
        
        .no-selection-icon {
            width: 64px;
            height: 64px;
            color: var(--border);
            margin-bottom: 20px;
        }
        
        .no-selection-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 8px;
        }
        
        .no-selection-text {
            font-size: 13px;
            color: var(--gray);
            line-height: 1.5;
        }
        
        /* Canvas */
        .editor-canvas {
            position: fixed;
            top: 56px;
            left: 0;
            right: 340px;
            bottom: 0;
            background: #e5e5e5;
            overflow: auto;
            display: flex;
            justify-content: center;
            padding: 20px;
            transition: right 0.3s ease;
        }
        
        .editor-canvas.sidebar-hidden {
            right: 0;
        }
        
        .canvas-frame {
            background: white;
            box-shadow: var(--shadow);
            border-radius: var(--radius);
            overflow: hidden;
            width: 100%;
            max-width: 100%;
            transition: max-width 0.3s ease;
        }
        
        .canvas-frame.tablet { max-width: 768px; }
        .canvas-frame.mobile { max-width: 375px; }
        
        .canvas-frame iframe {
            width: 100%;
            height: calc(100vh - 96px);
            border: none;
        }
        
        /* Toast */
        .toast-container {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .toast {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 20px;
            background: var(--dark);
            color: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            animation: toastIn 0.3s ease;
        }
        
        .toast.success { background: var(--success); }
        .toast.error { background: var(--danger); }
        
        @keyframes toastIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Actions Bar */
        .actions-bar {
            padding: 12px 16px;
            border-top: 1px solid var(--border);
            background: var(--light);
            display: flex;
            gap: 8px;
        }
        
        .actions-bar .btn {
            flex: 1;
            justify-content: center;
        }
    </style>
</head>
<body>
    <!-- Toolbar -->
    <header class="editor-toolbar">
        <div class="toolbar-section">
            <div class="toolbar-logo">
                <div class="toolbar-logo-icon">
                    <i data-lucide="edit-3" style="width:18px;height:18px;color:white"></i>
                </div>
                <span>Editor Visual</span>
            </div>
            <div class="toolbar-divider"></div>
            <button class="toolbar-btn" onclick="undo()" title="Desfazer (Ctrl+Z)">
                <i data-lucide="undo-2" style="width:18px;height:18px"></i>
            </button>
            <button class="toolbar-btn" onclick="redo()" title="Refazer">
                <i data-lucide="redo-2" style="width:18px;height:18px"></i>
            </button>
        </div>
        
        <div class="toolbar-section">
            <div class="viewport-btns">
                <button class="viewport-btn active" data-viewport="desktop" title="Desktop">
                    <i data-lucide="monitor" style="width:18px;height:18px"></i>
                </button>
                <button class="viewport-btn" data-viewport="tablet" title="Tablet">
                    <i data-lucide="tablet" style="width:18px;height:18px"></i>
                </button>
                <button class="viewport-btn" data-viewport="mobile" title="Mobile">
                    <i data-lucide="smartphone" style="width:18px;height:18px"></i>
                </button>
            </div>
        </div>
        
        <div class="toolbar-section">
            <button class="toolbar-btn" onclick="toggleSidebar()">
                <i data-lucide="panel-right" style="width:18px;height:18px"></i>
            </button>
            <div class="toolbar-divider"></div>
            <button class="btn btn-ghost" onclick="previewPage()">
                <i data-lucide="external-link" style="width:16px;height:16px"></i>
                Preview
            </button>
            <button class="btn btn-secondary" onclick="savePage()">
                <i data-lucide="save" style="width:16px;height:16px"></i>
                Salvar
            </button>
            <button class="btn btn-success" onclick="publishPage()">
                <i data-lucide="rocket" style="width:16px;height:16px"></i>
                Publicar
            </button>
        </div>
    </header>
    
    <!-- Sidebar -->
    <aside class="editor-sidebar" id="sidebar">
        <div class="sidebar-header">
            <span class="sidebar-title">Propriedades</span>
            <span class="sidebar-badge" id="elementBadge">Nenhum</span>
        </div>
        
        <div class="sidebar-tabs">
            <button class="sidebar-tab active" data-tab="style">
                <i data-lucide="palette" style="width:14px;height:14px"></i>
                Estilo
            </button>
            <button class="sidebar-tab" data-tab="content">
                <i data-lucide="type" style="width:14px;height:14px"></i>
                Conteúdo
            </button>
            <button class="sidebar-tab" data-tab="layout">
                <i data-lucide="layout" style="width:14px;height:14px"></i>
                Layout
            </button>
        </div>
        
        <div class="sidebar-content" id="sidebarContent">
            <!-- Estado inicial: nenhum elemento selecionado -->
            <div class="no-selection" id="noSelection">
                <i data-lucide="mouse-pointer-click" class="no-selection-icon"></i>
                <div class="no-selection-title">Clique em um elemento</div>
                <div class="no-selection-text">Selecione qualquer elemento na página para editar suas propriedades</div>
            </div>
            
            <!-- Painel de propriedades (dinâmico) -->
            <div id="propsPanel" style="display:none"></div>
        </div>
        
        <div class="actions-bar" id="actionsBar" style="display:none">
            <button class="btn btn-secondary" onclick="duplicateElement()">
                <i data-lucide="copy" style="width:14px;height:14px"></i>
                Duplicar
            </button>
            <button class="btn" style="background:var(--danger);color:white" onclick="deleteElement()">
                <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                Excluir
            </button>
        </div>
    </aside>
    
    <!-- Canvas -->
    <main class="editor-canvas" id="canvas">
        <div class="canvas-frame" id="canvasFrame">
            <iframe id="pageFrame" src="<?php echo MERCADO_URL; ?>?editor_mode=1"></iframe>
        </div>
    </main>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <script>
        // ==================== ESTADO ====================
        const Editor = {
            selectedElement: null,
            history: [],
            historyIndex: -1,
            iframe: null,
            doc: null
        };
        
        // ==================== INIT ====================
        document.addEventListener('DOMContentLoaded', () => {
            lucide.createIcons();
            initViewport();
            initTabs();
            initIframe();
        });
        
        function initIframe() {
            Editor.iframe = document.getElementById('pageFrame');
            
            Editor.iframe.onload = function() {
                Editor.doc = Editor.iframe.contentDocument || Editor.iframe.contentWindow.document;
                injectEditorStyles();
                makeElementsEditable();
                saveToHistory();
            };
        }
        
        // ==================== INJETAR ESTILOS NO IFRAME ====================
        function injectEditorStyles() {
            const style = Editor.doc.createElement('style');
            style.textContent = `
                [data-editable] {
                    position: relative;
                    cursor: pointer;
                    transition: outline 0.15s, box-shadow 0.15s;
                }
                [data-editable]:hover {
                    outline: 2px dashed #2563eb !important;
                    outline-offset: 2px;
                }
                [data-editable].editor-selected {
                    outline: 2px solid #2563eb !important;
                    outline-offset: 2px;
                    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.2) !important;
                }
                [data-editable].editor-selected::before {
                    content: attr(data-label);
                    position: absolute;
                    top: -28px;
                    left: 0;
                    background: #2563eb;
                    color: white;
                    font-size: 11px;
                    font-weight: 600;
                    padding: 4px 10px;
                    border-radius: 4px;
                    font-family: 'Inter', sans-serif;
                    z-index: 9999;
                    white-space: nowrap;
                }
                .editor-drop-zone {
                    min-height: 100px;
                    border: 2px dashed #2563eb;
                    background: rgba(37, 99, 235, 0.05);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #2563eb;
                    font-family: 'Inter', sans-serif;
                }
            `;
            Editor.doc.head.appendChild(style);
        }
        
        // ==================== TORNAR ELEMENTOS EDITÁVEIS ====================
        function makeElementsEditable() {
            // Selecionar elementos principais para edição
            const selectors = [
                // Header
                'header, .header, #header, [class*="header"]',
                // Navigation
                'nav, .nav, .menu, .navbar',
                // Banner
                '.banner, .hero, .slider, .carousel, [class*="banner"]',
                // Sections
                'section, .section',
                // Titles
                'h1, h2, h3, h4, h5, h6',
                // Text
                'p, span, .text, .description',
                // Links & Buttons
                'a, button, .btn, .button',
                // Images
                'img, .image, [class*="image"]',
                // Products
                '.product, .product-card, [class*="product"]',
                // Categories
                '.category, .category-card, [class*="category"]',
                // Footer
                'footer, .footer, #footer',
                // Containers
                '.container, .wrapper, .content',
                // Cards
                '.card, [class*="card"]',
                // Forms
                'form, input, textarea, select',
                // Divs com classes relevantes
                '[class*="cta"], [class*="newsletter"], [class*="social"]'
            ];
            
            // Adicionar data-editable aos elementos
            selectors.forEach(selector => {
                try {
                    Editor.doc.querySelectorAll(selector).forEach(el => {
                        if (!el.closest('[data-editable]') || el.matches('h1,h2,h3,h4,h5,h6,p,span,a,button,img')) {
                            el.setAttribute('data-editable', 'true');
                            el.setAttribute('data-label', getElementLabel(el));
                            el.setAttribute('data-id', 'el-' + Math.random().toString(36).substr(2, 9));
                            
                            el.addEventListener('click', handleElementClick);
                            el.addEventListener('dblclick', handleElementDblClick);
                        }
                    });
                } catch(e) {}
            });
            
            // Clique fora deseleciona
            Editor.doc.addEventListener('click', (e) => {
                if (!e.target.closest('[data-editable]')) {
                    deselectElement();
                }
            });
        }
        
        function getElementLabel(el) {
            const tag = el.tagName.toLowerCase();
            const labels = {
                'header': 'Header',
                'footer': 'Footer',
                'nav': 'Navegação',
                'section': 'Seção',
                'div': el.className.split(' ')[0] || 'Div',
                'h1': 'Título H1',
                'h2': 'Título H2',
                'h3': 'Título H3',
                'h4': 'Título H4',
                'p': 'Parágrafo',
                'span': 'Texto',
                'a': 'Link',
                'button': 'Botão',
                'img': 'Imagem',
                'input': 'Campo',
                'form': 'Formulário'
            };
            return labels[tag] || el.className.split(' ')[0] || tag;
        }
        
        // ==================== EVENTOS DE CLIQUE ====================
        function handleElementClick(e) {
            e.preventDefault();
            e.stopPropagation();
            selectElement(this);
        }
        
        function handleElementDblClick(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const el = this;
            if (['H1','H2','H3','H4','H5','H6','P','SPAN','A','BUTTON'].includes(el.tagName)) {
                el.contentEditable = true;
                el.focus();
                
                // Selecionar texto
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
                
                el.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        el.blur();
                    }
                    if (e.key === 'Escape') {
                        el.blur();
                    }
                });
            }
        }
        
        // ==================== SELEÇÃO ====================
        function selectElement(el) {
            // Remover seleção anterior
            Editor.doc.querySelectorAll('.editor-selected').forEach(e => {
                e.classList.remove('editor-selected');
            });
            
            // Selecionar novo
            el.classList.add('editor-selected');
            Editor.selectedElement = el;
            
            // Atualizar sidebar
            showProperties(el);
        }
        
        function deselectElement() {
            if (Editor.selectedElement) {
                Editor.selectedElement.classList.remove('editor-selected');
            }
            Editor.selectedElement = null;
            
            document.getElementById('noSelection').style.display = 'flex';
            document.getElementById('propsPanel').style.display = 'none';
            document.getElementById('actionsBar').style.display = 'none';
            document.getElementById('elementBadge').textContent = 'Nenhum';
        }
        
        // ==================== PROPRIEDADES ====================
        function showProperties(el) {
            document.getElementById('noSelection').style.display = 'none';
            document.getElementById('propsPanel').style.display = 'block';
            document.getElementById('actionsBar').style.display = 'flex';
            document.getElementById('elementBadge').textContent = el.getAttribute('data-label');
            
            const styles = Editor.iframe.contentWindow.getComputedStyle(el);
            const panel = document.getElementById('propsPanel');
            
            // Gerar HTML das propriedades
            panel.innerHTML = generatePropsHTML(el, styles);
            lucide.createIcons();
        }
        
        function generatePropsHTML(el, styles) {
            const bgColor = rgbToHex(styles.backgroundColor);
            const textColor = rgbToHex(styles.color);
            
            return `
                <!-- Cores -->
                <div class="prop-section">
                    <div class="prop-section-title">
                        <i data-lucide="palette" style="width:14px;height:14px"></i>
                        Cores
                    </div>
                    
                    <div class="prop-group">
                        <label class="prop-label">Cor de Fundo</label>
                        <div class="color-row">
                            <div class="color-swatch" style="background:${bgColor}">
                                <input type="color" value="${bgColor}" onchange="updateStyle('backgroundColor', this.value)">
                            </div>
                            <input type="text" class="prop-input" value="${bgColor}" onchange="updateStyle('backgroundColor', this.value)">
                        </div>
                        <div class="color-presets">
                            <div class="color-preset" style="background:#ffffff" onclick="updateStyle('backgroundColor','#ffffff')"></div>
                            <div class="color-preset" style="background:#f3f4f6" onclick="updateStyle('backgroundColor','#f3f4f6')"></div>
                            <div class="color-preset" style="background:#1f2937" onclick="updateStyle('backgroundColor','#1f2937')"></div>
                            <div class="color-preset" style="background:#2563eb" onclick="updateStyle('backgroundColor','#2563eb')"></div>
                            <div class="color-preset" style="background:#10b981" onclick="updateStyle('backgroundColor','#10b981')"></div>
                            <div class="color-preset" style="background:#f59e0b" onclick="updateStyle('backgroundColor','#f59e0b')"></div>
                            <div class="color-preset" style="background:#ef4444" onclick="updateStyle('backgroundColor','#ef4444')"></div>
                            <div class="color-preset" style="background:#8b5cf6" onclick="updateStyle('backgroundColor','#8b5cf6')"></div>
                        </div>
                    </div>
                    
                    <div class="prop-group">
                        <label class="prop-label">Cor do Texto</label>
                        <div class="color-row">
                            <div class="color-swatch" style="background:${textColor}">
                                <input type="color" value="${textColor}" onchange="updateStyle('color', this.value)">
                            </div>
                            <input type="text" class="prop-input" value="${textColor}" onchange="updateStyle('color', this.value)">
                        </div>
                    </div>
                </div>
                
                <!-- Tipografia -->
                <div class="prop-section">
                    <div class="prop-section-title">
                        <i data-lucide="type" style="width:14px;height:14px"></i>
                        Tipografia
                    </div>
                    
                    <div class="prop-group">
                        <label class="prop-label">Tamanho</label>
                        <div class="range-row">
                            <input type="range" class="range-slider" min="10" max="72" value="${parseInt(styles.fontSize)}" 
                                oninput="updateStyle('fontSize', this.value+'px'); this.nextElementSibling.textContent=this.value+'px'">
                            <span class="range-value">${parseInt(styles.fontSize)}px</span>
                        </div>
                    </div>
                    
                    <div class="prop-group">
                        <label class="prop-label">Peso</label>
                        <select class="prop-select" onchange="updateStyle('fontWeight', this.value)">
                            <option value="400" ${styles.fontWeight=='400'?'selected':''}>Normal</option>
                            <option value="500" ${styles.fontWeight=='500'?'selected':''}>Medium</option>
                            <option value="600" ${styles.fontWeight=='600'?'selected':''}>Semibold</option>
                            <option value="700" ${styles.fontWeight=='700'?'selected':''}>Bold</option>
                        </select>
                    </div>
                    
                    <div class="prop-group">
                        <label class="prop-label">Alinhamento</label>
                        <div class="align-row">
                            <button class="align-btn ${styles.textAlign=='left'||styles.textAlign=='start'?'active':''}" onclick="updateStyle('textAlign','left')">
                                <i data-lucide="align-left" style="width:16px;height:16px"></i>
                            </button>
                            <button class="align-btn ${styles.textAlign=='center'?'active':''}" onclick="updateStyle('textAlign','center')">
                                <i data-lucide="align-center" style="width:16px;height:16px"></i>
                            </button>
                            <button class="align-btn ${styles.textAlign=='right'||styles.textAlign=='end'?'active':''}" onclick="updateStyle('textAlign','right')">
                                <i data-lucide="align-right" style="width:16px;height:16px"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Espaçamento -->
                <div class="prop-section">
                    <div class="prop-section-title">
                        <i data-lucide="move" style="width:14px;height:14px"></i>
                        Espaçamento (Padding)
                    </div>
                    
                    <div class="prop-group">
                        <div class="spacing-grid">
                            <input type="number" class="spacing-input spacing-top" value="${parseInt(styles.paddingTop)||0}" 
                                onchange="updateStyle('paddingTop', this.value+'px')" placeholder="T">
                            <input type="number" class="spacing-input spacing-left" value="${parseInt(styles.paddingLeft)||0}" 
                                onchange="updateStyle('paddingLeft', this.value+'px')" placeholder="L">
                            <div class="spacing-center">Elemento</div>
                            <input type="number" class="spacing-input spacing-right" value="${parseInt(styles.paddingRight)||0}" 
                                onchange="updateStyle('paddingRight', this.value+'px')" placeholder="R">
                            <input type="number" class="spacing-input spacing-bottom" value="${parseInt(styles.paddingBottom)||0}" 
                                onchange="updateStyle('paddingBottom', this.value+'px')" placeholder="B">
                        </div>
                    </div>
                </div>
                
                <!-- Borda -->
                <div class="prop-section">
                    <div class="prop-section-title">
                        <i data-lucide="square" style="width:14px;height:14px"></i>
                        Borda
                    </div>
                    
                    <div class="prop-group">
                        <label class="prop-label">Arredondamento</label>
                        <div class="range-row">
                            <input type="range" class="range-slider" min="0" max="50" value="${parseInt(styles.borderRadius)||0}" 
                                oninput="updateStyle('borderRadius', this.value+'px'); this.nextElementSibling.textContent=this.value+'px'">
                            <span class="range-value">${parseInt(styles.borderRadius)||0}px</span>
                        </div>
                    </div>
                </div>
                
                <!-- Imagem de Fundo -->
                ${el.tagName === 'DIV' || el.tagName === 'SECTION' || el.tagName === 'HEADER' ? `
                <div class="prop-section">
                    <div class="prop-section-title">
                        <i data-lucide="image" style="width:14px;height:14px"></i>
                        Imagem de Fundo
                    </div>
                    
                    <div class="prop-group">
                        <div class="image-upload" onclick="selectBackgroundImage()">
                            <i data-lucide="upload" class="image-upload-icon"></i>
                            <div class="image-upload-text">Clique para <strong>selecionar</strong></div>
                        </div>
                    </div>
                    
                    <div class="prop-group">
                        <label class="prop-label">URL da Imagem</label>
                        <input type="text" class="prop-input" placeholder="https://..." 
                            onchange="updateStyle('backgroundImage', 'url('+this.value+')')">
                    </div>
                </div>
                ` : ''}
            `;
        }
        
        // ==================== ATUALIZAR ESTILOS ====================
        function updateStyle(prop, value) {
            if (Editor.selectedElement) {
                Editor.selectedElement.style[prop] = value;
                saveToHistory();
            }
        }
        
        function duplicateElement() {
            if (Editor.selectedElement) {
                const clone = Editor.selectedElement.cloneNode(true);
                clone.setAttribute('data-id', 'el-' + Math.random().toString(36).substr(2, 9));
                clone.classList.remove('editor-selected');
                
                Editor.selectedElement.parentNode.insertBefore(clone, Editor.selectedElement.nextSibling);
                
                // Reconfigurar eventos
                clone.addEventListener('click', handleElementClick);
                clone.addEventListener('dblclick', handleElementDblClick);
                
                selectElement(clone);
                saveToHistory();
                showToast('Elemento duplicado!', 'success');
            }
        }
        
        function deleteElement() {
            if (Editor.selectedElement && confirm('Excluir este elemento?')) {
                Editor.selectedElement.remove();
                deselectElement();
                saveToHistory();
                showToast('Elemento excluído!');
            }
        }
        
        // ==================== HISTÓRICO ====================
        function saveToHistory() {
            const html = Editor.doc.body.innerHTML;
            Editor.history = Editor.history.slice(0, Editor.historyIndex + 1);
            Editor.history.push(html);
            Editor.historyIndex = Editor.history.length - 1;
        }
        
        function undo() {
            if (Editor.historyIndex > 0) {
                Editor.historyIndex--;
                Editor.doc.body.innerHTML = Editor.history[Editor.historyIndex];
                makeElementsEditable();
                deselectElement();
            }
        }
        
        function redo() {
            if (Editor.historyIndex < Editor.history.length - 1) {
                Editor.historyIndex++;
                Editor.doc.body.innerHTML = Editor.history[Editor.historyIndex];
                makeElementsEditable();
                deselectElement();
            }
        }
        
        // Atalhos
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                if (e.key === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); }
                if (e.key === 's') { e.preventDefault(); savePage(); }
            }
            if (e.key === 'Delete' && Editor.selectedElement) {
                deleteElement();
            }
        });
        
        // ==================== SALVAR/PUBLICAR ====================
        function savePage() {
            const html = Editor.doc.body.innerHTML;
            
            // Enviar via AJAX
            fetch('/mercado/admin/api/page_builder.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save',
                    page: 'index',
                    html: html
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast('Página salva com sucesso!', 'success');
                } else {
                    showToast('Erro ao salvar', 'error');
                }
            })
            .catch(() => {
                // Salvar local se API não disponível
                localStorage.setItem('mercado_page_index', html);
                showToast('Salvo localmente!', 'success');
            });
        }
        
        function publishPage() {
            savePage();
            showToast('Página publicada!', 'success');
        }
        
        function previewPage() {
            window.open('<?php echo MERCADO_URL; ?>', '_blank');
        }
        
        // ==================== VIEWPORT ====================
        function initViewport() {
            document.querySelectorAll('.viewport-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const viewport = this.dataset.viewport;
                    
                    document.querySelectorAll('.viewport-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    const frame = document.getElementById('canvasFrame');
                    frame.classList.remove('tablet', 'mobile');
                    if (viewport !== 'desktop') frame.classList.add(viewport);
                });
            });
        }
        
        // ==================== TABS ====================
        function initTabs() {
            document.querySelectorAll('.sidebar-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.sidebar-tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                });
            });
        }
        
        // ==================== SIDEBAR ====================
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('hidden');
            document.getElementById('canvas').classList.toggle('sidebar-hidden');
        }
        
        // ==================== UTILS ====================
        function rgbToHex(rgb) {
            if (!rgb || rgb === 'transparent' || rgb === 'rgba(0, 0, 0, 0)') return '#ffffff';
            const result = rgb.match(/\d+/g);
            if (!result || result.length < 3) return '#ffffff';
            return '#' + result.slice(0, 3).map(x => {
                const hex = parseInt(x).toString(16);
                return hex.length === 1 ? '0' + hex : hex;
            }).join('');
        }
        
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <i data-lucide="${type==='success'?'check-circle':'info'}" style="width:18px;height:18px"></i>
                <span>${message}</span>
            `;
            container.appendChild(toast);
            lucide.createIcons();
            setTimeout(() => toast.remove(), 3000);
        }
        
        function selectBackgroundImage() {
            const url = prompt('Cole a URL da imagem:');
            if (url) {
                updateStyle('backgroundImage', `url('${url}')`);
                updateStyle('backgroundSize', 'cover');
                updateStyle('backgroundPosition', 'center');
            }
        }
    </script>
</body>
</html>
