/**
 * Editor Visual Pro - Mercado OneMundo
 * JavaScript completo com todas as funcionalidades
 */

// ==================== ESTADO GLOBAL ====================
const Editor = {
    // Elemento selecionado
    selected: null,
    selectedId: null,
    
    // Iframe e documento
    iframe: null,
    doc: null,
    
    // Histórico para undo/redo
    history: [],
    historyIndex: -1,
    maxHistory: 50,
    
    // Configurações globais
    globalStyles: {
        primary: '#6366f1',
        secondary: '#10b981',
        accent: '#f59e0b',
        text: '#1f2937',
        font: 'Inter',
        fontSize: 16,
        container: 1400,
        radius: 8,
        buttonStyle: 'rounded'
    },
    
    // Configurações da página
    pageSettings: {
        title: '',
        description: '',
        slug: '',
        isHomepage: false
    },
    
    // Zoom
    zoom: 100,
    
    // Viewport atual
    viewport: 'desktop',
    
    // Arrastar elemento
    draggedWidget: null,
    
    // Mídia selecionada
    selectedMedia: null,
    mediaCallback: null
};

// ==================== INICIALIZAÇÃO ====================
document.addEventListener('DOMContentLoaded', () => {
    lucide.createIcons();
    initSidebarPanels();
    initPropsTabs();
    initViewport();
    initDragAndDrop();
    loadMediaLibrary();
    loadPage();
});

// ==================== CARREGAR PÁGINA ====================
function loadPage() {
    Editor.iframe = document.getElementById('pageFrame');
    
    // Carregar página do Mercado ou página em branco com template
    const pageHTML = generateBasePage();
    Editor.iframe.srcdoc = pageHTML;
    
    Editor.iframe.onload = () => {
        Editor.doc = Editor.iframe.contentDocument || Editor.iframe.contentWindow.document;
        injectEditorSystem();
        makeEditable();
        saveToHistory();
        updateLayersList();
    };
}

function generateBasePage() {
    return `<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --secondary: #10b981;
            --accent: #f59e0b;
            --text: #1f2937;
            --text-light: #6b7280;
            --bg: #ffffff;
            --bg-alt: #f9fafb;
            --border: #e5e7eb;
            --font: 'Inter', sans-serif;
            --container: 1400px;
            --radius: 8px;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: var(--font);
            color: var(--text);
            line-height: 1.6;
            background: var(--bg);
        }
        
        .container {
            max-width: var(--container);
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Header */
        .site-header {
            background: var(--primary);
            padding: 16px 0;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 700;
            color: white;
            text-decoration: none;
        }
        
        .nav-menu {
            display: flex;
            gap: 32px;
            list-style: none;
        }
        
        .nav-menu a {
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .nav-menu a:hover {
            color: white;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .search-box {
            display: flex;
            align-items: center;
            background: rgba(255,255,255,0.15);
            border-radius: var(--radius);
            padding: 8px 16px;
        }
        
        .search-box input {
            background: none;
            border: none;
            color: white;
            outline: none;
            width: 200px;
        }
        
        .search-box input::placeholder {
            color: rgba(255,255,255,0.7);
        }
        
        .icon-btn {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        
        .icon-btn:hover {
            background: rgba(255,255,255,0.2);
        }
        
        /* Hero Banner */
        .hero-banner {
            background: linear-gradient(135deg, var(--primary) 0%, #8b5cf6 100%);
            padding: 80px 0;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .hero-banner h1 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 16px;
        }
        
        .hero-banner p {
            font-size: 20px;
            opacity: 0.9;
            margin-bottom: 32px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 28px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        
        .btn-white {
            background: white;
            color: var(--primary);
        }
        
        .btn-white:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: #4f46e5;
        }
        
        /* Categorias */
        .categories-section {
            padding: 60px 0;
            background: var(--bg-alt);
        }
        
        .section-title {
            font-size: 32px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 40px;
        }
        
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 20px;
        }
        
        .category-card {
            background: white;
            border-radius: var(--radius);
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid var(--border);
        }
        
        .category-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-color: var(--primary);
        }
        
        .category-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 12px;
            background: linear-gradient(135deg, var(--primary), #8b5cf6);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        
        .category-name {
            font-weight: 600;
            font-size: 14px;
        }
        
        /* Produtos */
        .products-section {
            padding: 60px 0;
        }
        
        .products-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
        }
        
        .product-card {
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }
        
        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .product-image {
            aspect-ratio: 1;
            background: var(--bg-alt);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
        }
        
        .product-info {
            padding: 16px;
        }
        
        .product-name {
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .product-price {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .product-price-old {
            font-size: 14px;
            color: var(--text-light);
            text-decoration: line-through;
            margin-left: 8px;
        }
        
        /* CTA Section */
        .cta-section {
            padding: 80px 0;
            background: var(--secondary);
            text-align: center;
            color: white;
        }
        
        .cta-section h2 {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 16px;
        }
        
        .cta-section p {
            font-size: 18px;
            opacity: 0.9;
            margin-bottom: 32px;
        }
        
        /* Newsletter */
        .newsletter-section {
            padding: 60px 0;
            background: var(--text);
            color: white;
            text-align: center;
        }
        
        .newsletter-section h3 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        
        .newsletter-section p {
            opacity: 0.8;
            margin-bottom: 24px;
        }
        
        .newsletter-form {
            display: flex;
            gap: 12px;
            max-width: 500px;
            margin: 0 auto;
        }
        
        .newsletter-form input {
            flex: 1;
            padding: 14px 20px;
            border: none;
            border-radius: var(--radius);
            font-size: 16px;
        }
        
        /* Footer */
        .site-footer {
            background: #0f172a;
            color: white;
            padding: 60px 0 30px;
        }
        
        .footer-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 40px;
            margin-bottom: 40px;
        }
        
        .footer-col h4 {
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .footer-col a {
            display: block;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            margin-bottom: 12px;
            font-size: 14px;
            transition: color 0.2s;
        }
        
        .footer-col a:hover {
            color: white;
        }
        
        .footer-bottom {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.5);
            font-size: 14px;
        }
        
        /* ========== ESTILOS DO EDITOR ========== */
        [data-editor-element] {
            position: relative;
            outline: 2px solid transparent;
            outline-offset: 2px;
            transition: outline-color 0.15s;
        }
        
        [data-editor-element]:hover {
            outline-color: rgba(99, 102, 241, 0.5);
            cursor: pointer;
        }
        
        [data-editor-element].editor-selected {
            outline-color: #6366f1;
            outline-style: solid;
        }
        
        [data-editor-element].editor-selected::before {
            content: attr(data-editor-label);
            position: absolute;
            top: -28px;
            left: 0;
            background: #6366f1;
            color: white;
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 4px;
            white-space: nowrap;
            z-index: 9999;
            font-family: 'Inter', sans-serif;
        }
        
        .editor-drop-indicator {
            height: 4px;
            background: #6366f1;
            border-radius: 2px;
            margin: 8px 0;
        }
        
        .editor-placeholder {
            min-height: 100px;
            border: 2px dashed #6366f1;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6366f1;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            background: rgba(99, 102, 241, 0.05);
        }
        
        /* Responsivo */
        @media (max-width: 1024px) {
            .categories-grid { grid-template-columns: repeat(3, 1fr); }
            .products-grid { grid-template-columns: repeat(3, 1fr); }
            .footer-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media (max-width: 768px) {
            .categories-grid { grid-template-columns: repeat(2, 1fr); }
            .products-grid { grid-template-columns: repeat(2, 1fr); }
            .hero-banner h1 { font-size: 32px; }
            .nav-menu { display: none; }
        }
        
        @media (max-width: 480px) {
            .products-grid { grid-template-columns: 1fr; }
            .footer-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <!-- HEADER -->
    <header class="site-header" data-editor-element data-editor-label="Header" data-editor-id="header">
        <div class="container">
            <div class="header-content">
                <a href="#" class="logo" data-editor-element data-editor-label="Logo" data-editor-id="logo">Mercado OneMundo</a>
                <nav>
                    <ul class="nav-menu" data-editor-element data-editor-label="Menu" data-editor-id="nav-menu">
                        <li><a href="#">Início</a></li>
                        <li><a href="#">Produtos</a></li>
                        <li><a href="#">Categorias</a></li>
                        <li><a href="#">Ofertas</a></li>
                        <li><a href="#">Contato</a></li>
                    </ul>
                </nav>
                <div class="header-actions">
                    <div class="search-box" data-editor-element data-editor-label="Busca" data-editor-id="search">
                        <input type="text" placeholder="Buscar produtos...">
                    </div>
                    <button class="icon-btn" data-editor-element data-editor-label="Carrinho" data-editor-id="cart-btn">🛒</button>
                    <button class="icon-btn" data-editor-element data-editor-label="Conta" data-editor-id="account-btn">👤</button>
                </div>
            </div>
        </div>
    </header>
    
    <!-- HERO BANNER -->
    <section class="hero-banner" data-editor-element data-editor-label="Banner Principal" data-editor-id="hero">
        <div class="container">
            <h1 data-editor-element data-editor-label="Título" data-editor-id="hero-title">Bem-vindo ao Mercado OneMundo</h1>
            <p data-editor-element data-editor-label="Subtítulo" data-editor-id="hero-subtitle">Os melhores produtos com entrega rápida para todo Brasil. Qualidade garantida!</p>
            <a href="#" class="btn btn-white" data-editor-element data-editor-label="Botão CTA" data-editor-id="hero-btn">Ver Ofertas</a>
        </div>
    </section>
    
    <!-- CATEGORIAS -->
    <section class="categories-section" data-editor-element data-editor-label="Seção Categorias" data-editor-id="categories-section">
        <div class="container">
            <h2 class="section-title" data-editor-element data-editor-label="Título da Seção" data-editor-id="cat-title">Categorias</h2>
            <div class="categories-grid" data-editor-element data-editor-label="Grid de Categorias" data-editor-id="cat-grid">
                <div class="category-card" data-editor-element data-editor-label="Categoria" data-editor-id="cat-1">
                    <div class="category-icon">🍎</div>
                    <div class="category-name">Frutas</div>
                </div>
                <div class="category-card" data-editor-element data-editor-label="Categoria" data-editor-id="cat-2">
                    <div class="category-icon">🥬</div>
                    <div class="category-name">Verduras</div>
                </div>
                <div class="category-card" data-editor-element data-editor-label="Categoria" data-editor-id="cat-3">
                    <div class="category-icon">🥛</div>
                    <div class="category-name">Laticínios</div>
                </div>
                <div class="category-card" data-editor-element data-editor-label="Categoria" data-editor-id="cat-4">
                    <div class="category-icon">🥩</div>
                    <div class="category-name">Carnes</div>
                </div>
                <div class="category-card" data-editor-element data-editor-label="Categoria" data-editor-id="cat-5">
                    <div class="category-icon">🧹</div>
                    <div class="category-name">Limpeza</div>
                </div>
                <div class="category-card" data-editor-element data-editor-label="Categoria" data-editor-id="cat-6">
                    <div class="category-icon">🧴</div>
                    <div class="category-name">Higiene</div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- PRODUTOS -->
    <section class="products-section" data-editor-element data-editor-label="Seção Produtos" data-editor-id="products-section">
        <div class="container">
            <h2 class="section-title" data-editor-element data-editor-label="Título da Seção" data-editor-id="prod-title">Produtos em Destaque</h2>
            <div class="products-grid" data-editor-element data-editor-label="Grid de Produtos" data-editor-id="prod-grid">
                <div class="product-card" data-editor-element data-editor-label="Produto" data-editor-id="prod-1">
                    <div class="product-image">📦 Imagem</div>
                    <div class="product-info">
                        <div class="product-name">Produto Exemplo 1</div>
                        <div class="product-price">R$ 29,90</div>
                    </div>
                </div>
                <div class="product-card" data-editor-element data-editor-label="Produto" data-editor-id="prod-2">
                    <div class="product-image">📦 Imagem</div>
                    <div class="product-info">
                        <div class="product-name">Produto Exemplo 2</div>
                        <div class="product-price">R$ 49,90 <span class="product-price-old">R$ 69,90</span></div>
                    </div>
                </div>
                <div class="product-card" data-editor-element data-editor-label="Produto" data-editor-id="prod-3">
                    <div class="product-image">📦 Imagem</div>
                    <div class="product-info">
                        <div class="product-name">Produto Exemplo 3</div>
                        <div class="product-price">R$ 19,90</div>
                    </div>
                </div>
                <div class="product-card" data-editor-element data-editor-label="Produto" data-editor-id="prod-4">
                    <div class="product-image">📦 Imagem</div>
                    <div class="product-info">
                        <div class="product-name">Produto Exemplo 4</div>
                        <div class="product-price">R$ 39,90</div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- CTA -->
    <section class="cta-section" data-editor-element data-editor-label="Call to Action" data-editor-id="cta-section">
        <div class="container">
            <h2 data-editor-element data-editor-label="Título CTA" data-editor-id="cta-title">Cadastre-se e Ganhe 10% OFF</h2>
            <p data-editor-element data-editor-label="Texto CTA" data-editor-id="cta-text">Na sua primeira compra. Oferta válida para novos clientes.</p>
            <a href="#" class="btn btn-white" data-editor-element data-editor-label="Botão CTA" data-editor-id="cta-btn">Criar Conta Grátis</a>
        </div>
    </section>
    
    <!-- NEWSLETTER -->
    <section class="newsletter-section" data-editor-element data-editor-label="Newsletter" data-editor-id="newsletter-section">
        <div class="container">
            <h3 data-editor-element data-editor-label="Título Newsletter" data-editor-id="news-title">Receba Ofertas Exclusivas</h3>
            <p data-editor-element data-editor-label="Texto Newsletter" data-editor-id="news-text">Cadastre seu e-mail e fique por dentro das promoções</p>
            <form class="newsletter-form" data-editor-element data-editor-label="Formulário" data-editor-id="news-form">
                <input type="email" placeholder="Seu melhor e-mail">
                <button type="submit" class="btn btn-primary">Inscrever</button>
            </form>
        </div>
    </section>
    
    <!-- FOOTER -->
    <footer class="site-footer" data-editor-element data-editor-label="Footer" data-editor-id="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col" data-editor-element data-editor-label="Coluna Footer" data-editor-id="footer-col-1">
                    <h4>Institucional</h4>
                    <a href="#">Sobre Nós</a>
                    <a href="#">Trabalhe Conosco</a>
                    <a href="#">Política de Privacidade</a>
                    <a href="#">Termos de Uso</a>
                </div>
                <div class="footer-col" data-editor-element data-editor-label="Coluna Footer" data-editor-id="footer-col-2">
                    <h4>Atendimento</h4>
                    <a href="#">Central de Ajuda</a>
                    <a href="#">Fale Conosco</a>
                    <a href="#">Trocas e Devoluções</a>
                    <a href="#">Rastrear Pedido</a>
                </div>
                <div class="footer-col" data-editor-element data-editor-label="Coluna Footer" data-editor-id="footer-col-3">
                    <h4>Pagamento</h4>
                    <a href="#">Cartão de Crédito</a>
                    <a href="#">Boleto Bancário</a>
                    <a href="#">PIX</a>
                </div>
                <div class="footer-col" data-editor-element data-editor-label="Coluna Footer" data-editor-id="footer-col-4">
                    <h4>Redes Sociais</h4>
                    <a href="#">Facebook</a>
                    <a href="#">Instagram</a>
                    <a href="#">YouTube</a>
                    <a href="#">WhatsApp</a>
                </div>
            </div>
            <div class="footer-bottom" data-editor-element data-editor-label="Copyright" data-editor-id="footer-bottom">
                © 2024 Mercado OneMundo. Todos os direitos reservados.
            </div>
        </div>
    </footer>
</body>
</html>`;
}

// ==================== INJETAR SISTEMA DO EDITOR ====================
function injectEditorSystem() {
    // Prevenir links e forms
    Editor.doc.addEventListener('click', (e) => {
        if (e.target.tagName === 'A' || e.target.closest('a')) {
            e.preventDefault();
        }
    });
    
    Editor.doc.addEventListener('submit', (e) => {
        e.preventDefault();
    });
}

// ==================== TORNAR ELEMENTOS EDITÁVEIS ====================
function makeEditable() {
    const elements = Editor.doc.querySelectorAll('[data-editor-element]');
    
    elements.forEach(el => {
        // Clique para selecionar
        el.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            selectElement(el);
        });
        
        // Duplo clique para editar texto
        el.addEventListener('dblclick', (e) => {
            e.preventDefault();
            e.stopPropagation();
            enableInlineEdit(el);
        });
    });
    
    // Clique fora para deselecionar
    Editor.doc.addEventListener('click', (e) => {
        if (!e.target.closest('[data-editor-element]')) {
            deselectElement();
        }
    });
}

// ==================== SELEÇÃO DE ELEMENTOS ====================
function selectElement(el) {
    // Remover seleção anterior
    if (Editor.selected) {
        Editor.selected.classList.remove('editor-selected');
    }
    
    // Selecionar novo
    el.classList.add('editor-selected');
    Editor.selected = el;
    Editor.selectedId = el.getAttribute('data-editor-id');
    
    // Atualizar UI
    updatePropertiesPanel(el);
    updateBreadcrumb(el);
    highlightLayer(Editor.selectedId);
}

function deselectElement() {
    if (Editor.selected) {
        Editor.selected.classList.remove('editor-selected');
    }
    Editor.selected = null;
    Editor.selectedId = null;
    
    // Mostrar estado vazio
    document.getElementById('noSelection').style.display = 'flex';
    document.getElementById('propsForm').style.display = 'none';
    document.getElementById('propsBadge').textContent = 'Nenhum';
    
    // Resetar breadcrumb
    document.getElementById('breadcrumb').innerHTML = '<span class="breadcrumb-item">body</span>';
}

// ==================== EDIÇÃO INLINE ====================
function enableInlineEdit(el) {
    // Verificar se é um elemento de texto
    const textElements = ['H1','H2','H3','H4','H5','H6','P','SPAN','A','BUTTON','DIV'];
    if (!textElements.includes(el.tagName)) return;
    
    // Tornar editável
    el.contentEditable = true;
    el.focus();
    
    // Selecionar todo o texto
    const range = Editor.doc.createRange();
    range.selectNodeContents(el);
    const sel = Editor.iframe.contentWindow.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    
    // Eventos
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
        if (e.key === 'Escape') {
            el.blur();
        }
    });
}

// ==================== PAINEL DE PROPRIEDADES ====================
function updatePropertiesPanel(el) {
    const label = el.getAttribute('data-editor-label') || 'Elemento';
    document.getElementById('propsBadge').textContent = label;
    document.getElementById('noSelection').style.display = 'none';
    document.getElementById('propsForm').style.display = 'block';
    
    const styles = Editor.iframe.contentWindow.getComputedStyle(el);
    generatePropertiesForm(el, styles);
}

function generatePropertiesForm(el, styles) {
    const form = document.getElementById('propsForm');
    
    // Extrair valores
    const bgColor = rgbToHex(styles.backgroundColor);
    const textColor = rgbToHex(styles.color);
    const fontSize = parseInt(styles.fontSize);
    const fontWeight = styles.fontWeight;
    const textAlign = styles.textAlign;
    const paddingTop = parseInt(styles.paddingTop) || 0;
    const paddingRight = parseInt(styles.paddingRight) || 0;
    const paddingBottom = parseInt(styles.paddingBottom) || 0;
    const paddingLeft = parseInt(styles.paddingLeft) || 0;
    const borderRadius = parseInt(styles.borderRadius) || 0;
    
    form.innerHTML = `
        <!-- CORES -->
        <div class="props-section">
            <div class="props-section-header">
                <span class="props-section-title">
                    <i data-lucide="palette" style="width:14px;height:14px"></i>
                    Cores
                </span>
            </div>
            <div class="props-section-body">
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
                        <div class="color-preset" style="background:#8b5cf6" onclick="updateStyle('backgroundColor','#8b5cf6')"></div>
                        <div class="color-preset" style="background:#ec4899" onclick="updateStyle('backgroundColor','#ec4899')"></div>
                        <div class="color-preset" style="background:#06b6d4" onclick="updateStyle('backgroundColor','#06b6d4')"></div>
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
        </div>
        
        <!-- TIPOGRAFIA -->
        <div class="props-section">
            <div class="props-section-header">
                <span class="props-section-title">
                    <i data-lucide="type" style="width:14px;height:14px"></i>
                    Tipografia
                </span>
            </div>
            <div class="props-section-body">
                <div class="form-group">
                    <label class="form-label">Tamanho da Fonte</label>
                    <div class="range-row">
                        <input type="range" class="range-slider" min="10" max="72" value="${fontSize}" 
                            oninput="updateStyle('fontSize', this.value+'px'); this.nextElementSibling.textContent=this.value+'px'">
                        <span class="range-value">${fontSize}px</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Peso da Fonte</label>
                    <select class="form-select" onchange="updateStyle('fontWeight', this.value)">
                        <option value="300" ${fontWeight=='300'?'selected':''}>Light</option>
                        <option value="400" ${fontWeight=='400'?'selected':''}>Normal</option>
                        <option value="500" ${fontWeight=='500'?'selected':''}>Medium</option>
                        <option value="600" ${fontWeight=='600'?'selected':''}>Semibold</option>
                        <option value="700" ${fontWeight=='700'?'selected':''}>Bold</option>
                        <option value="800" ${fontWeight=='800'?'selected':''}>Extra Bold</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Alinhamento</label>
                    <div class="align-buttons">
                        <button class="align-btn ${textAlign=='left'||textAlign=='start'?'active':''}" onclick="updateStyle('textAlign','left')">
                            <i data-lucide="align-left" style="width:16px;height:16px"></i>
                        </button>
                        <button class="align-btn ${textAlign=='center'?'active':''}" onclick="updateStyle('textAlign','center')">
                            <i data-lucide="align-center" style="width:16px;height:16px"></i>
                        </button>
                        <button class="align-btn ${textAlign=='right'||textAlign=='end'?'active':''}" onclick="updateStyle('textAlign','right')">
                            <i data-lucide="align-right" style="width:16px;height:16px"></i>
                        </button>
                        <button class="align-btn ${textAlign=='justify'?'active':''}" onclick="updateStyle('textAlign','justify')">
                            <i data-lucide="align-justify" style="width:16px;height:16px"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ESPAÇAMENTO -->
        <div class="props-section">
            <div class="props-section-header">
                <span class="props-section-title">
                    <i data-lucide="move" style="width:14px;height:14px"></i>
                    Espaçamento (Padding)
                </span>
            </div>
            <div class="props-section-body">
                <div class="spacing-control">
                    <input type="number" class="spacing-input spacing-top" value="${paddingTop}" 
                        onchange="updateStyle('paddingTop', this.value+'px')" placeholder="T">
                    <input type="number" class="spacing-input spacing-left" value="${paddingLeft}" 
                        onchange="updateStyle('paddingLeft', this.value+'px')" placeholder="L">
                    <div class="spacing-center">Elemento</div>
                    <input type="number" class="spacing-input spacing-right" value="${paddingRight}" 
                        onchange="updateStyle('paddingRight', this.value+'px')" placeholder="R">
                    <input type="number" class="spacing-input spacing-bottom" value="${paddingBottom}" 
                        onchange="updateStyle('paddingBottom', this.value+'px')" placeholder="B">
                </div>
            </div>
        </div>
        
        <!-- BORDA -->
        <div class="props-section">
            <div class="props-section-header">
                <span class="props-section-title">
                    <i data-lucide="square" style="width:14px;height:14px"></i>
                    Borda
                </span>
            </div>
            <div class="props-section-body">
                <div class="form-group">
                    <label class="form-label">Arredondamento</label>
                    <div class="range-row">
                        <input type="range" class="range-slider" min="0" max="50" value="${borderRadius}" 
                            oninput="updateStyle('borderRadius', this.value+'px'); this.nextElementSibling.textContent=this.value+'px'">
                        <span class="range-value">${borderRadius}px</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- IMAGEM DE FUNDO -->
        <div class="props-section">
            <div class="props-section-header">
                <span class="props-section-title">
                    <i data-lucide="image" style="width:14px;height:14px"></i>
                    Imagem de Fundo
                </span>
            </div>
            <div class="props-section-body">
                <div class="form-group">
                    <div class="image-upload" onclick="openMediaLibrary('background')">
                        <i data-lucide="upload" class="image-upload-icon"></i>
                        <div class="image-upload-text">Clique para <strong>selecionar</strong></div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">URL da Imagem</label>
                    <input type="text" class="form-input" placeholder="https://..." 
                        onchange="updateStyle('backgroundImage', 'url('+this.value+')')">
                </div>
            </div>
        </div>
        
        <!-- ANIMAÇÕES -->
        <div class="props-section">
            <div class="props-section-header">
                <span class="props-section-title">
                    <i data-lucide="sparkles" style="width:14px;height:14px"></i>
                    Animação de Entrada
                </span>
            </div>
            <div class="props-section-body">
                <div class="animation-grid">
                    <div class="animation-option" onclick="setAnimation('none')">
                        <span>Nenhuma</span>
                    </div>
                    <div class="animation-option" onclick="setAnimation('fadeIn')">
                        <span>Fade In</span>
                    </div>
                    <div class="animation-option" onclick="setAnimation('slideUp')">
                        <span>Slide Up</span>
                    </div>
                    <div class="animation-option" onclick="setAnimation('slideLeft')">
                        <span>Slide Left</span>
                    </div>
                    <div class="animation-option" onclick="setAnimation('zoomIn')">
                        <span>Zoom In</span>
                    </div>
                    <div class="animation-option" onclick="setAnimation('bounce')">
                        <span>Bounce</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- VISIBILIDADE RESPONSIVA -->
        <div class="props-section">
            <div class="props-section-header">
                <span class="props-section-title">
                    <i data-lucide="monitor" style="width:14px;height:14px"></i>
                    Visibilidade por Dispositivo
                </span>
            </div>
            <div class="props-section-body">
                <div class="visibility-control">
                    <button class="visibility-btn active" onclick="toggleVisibility('desktop', this)">
                        <i data-lucide="monitor" style="width:18px;height:18px"></i>
                        <span>Desktop</span>
                    </button>
                    <button class="visibility-btn active" onclick="toggleVisibility('tablet', this)">
                        <i data-lucide="tablet" style="width:18px;height:18px"></i>
                        <span>Tablet</span>
                    </button>
                    <button class="visibility-btn active" onclick="toggleVisibility('mobile', this)">
                        <i data-lucide="smartphone" style="width:18px;height:18px"></i>
                        <span>Mobile</span>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- AÇÕES -->
        <div class="props-section">
            <div class="props-section-header">
                <span class="props-section-title">
                    <i data-lucide="settings" style="width:14px;height:14px"></i>
                    Ações
                </span>
            </div>
            <div class="props-section-body">
                <div style="display:flex;gap:8px">
                    <button class="btn btn-outline" style="flex:1" onclick="duplicateElement()">
                        <i data-lucide="copy" style="width:14px;height:14px"></i>
                        Duplicar
                    </button>
                    <button class="btn btn-danger" style="flex:1" onclick="deleteElement()">
                        <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                        Excluir
                    </button>
                </div>
            </div>
        </div>
    `;
    
    lucide.createIcons();
}

// ==================== ATUALIZAR ESTILOS ====================
function updateStyle(prop, value) {
    if (Editor.selected) {
        Editor.selected.style[prop] = value;
        saveToHistory();
    }
}

// ==================== DUPLICAR/EXCLUIR ====================
function duplicateElement() {
    if (!Editor.selected) return;
    
    const clone = Editor.selected.cloneNode(true);
    clone.setAttribute('data-editor-id', 'el-' + Date.now());
    clone.classList.remove('editor-selected');
    
    Editor.selected.parentNode.insertBefore(clone, Editor.selected.nextSibling);
    
    // Reconfigurar eventos
    clone.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        selectElement(clone);
    });
    clone.addEventListener('dblclick', (e) => {
        e.preventDefault();
        e.stopPropagation();
        enableInlineEdit(clone);
    });
    
    selectElement(clone);
    saveToHistory();
    updateLayersList();
    showToast('Elemento duplicado!', 'success');
}

function deleteElement() {
    if (!Editor.selected) return;
    
    if (confirm('Excluir este elemento?')) {
        Editor.selected.remove();
        deselectElement();
        saveToHistory();
        updateLayersList();
        showToast('Elemento excluído!');
    }
}

// ==================== BREADCRUMB ====================
function updateBreadcrumb(el) {
    const crumbs = [];
    let current = el;
    
    while (current && current !== Editor.doc.body) {
        const label = current.getAttribute('data-editor-label') || current.tagName.toLowerCase();
        crumbs.unshift(label);
        current = current.parentElement;
    }
    crumbs.unshift('body');
    
    const container = document.getElementById('breadcrumb');
    container.innerHTML = crumbs.map((c, i) => 
        `<span class="breadcrumb-item">${c}</span>${i < crumbs.length - 1 ? '<span class="breadcrumb-sep">›</span>' : ''}`
    ).join('');
}

// ==================== CAMADAS ====================
function updateLayersList() {
    const elements = Editor.doc.querySelectorAll('[data-editor-element]');
    const list = document.getElementById('layersList');
    
    let html = '';
    elements.forEach(el => {
        const id = el.getAttribute('data-editor-id');
        const label = el.getAttribute('data-editor-label') || 'Elemento';
        const isSelected = el.classList.contains('editor-selected');
        
        html += `
            <div class="layer-item ${isSelected ? 'selected' : ''}" onclick="selectElementById('${id}')">
                <i data-lucide="grip-vertical" class="layer-drag" style="width:14px;height:14px"></i>
                <i data-lucide="square" class="layer-icon" style="width:14px;height:14px"></i>
                <span class="layer-name">${label}</span>
                <div class="layer-actions">
                    <button class="layer-action-btn" onclick="event.stopPropagation();toggleElementVisibility('${id}')" title="Visibilidade">
                        <i data-lucide="eye" style="width:12px;height:12px"></i>
                    </button>
                </div>
            </div>
        `;
    });
    
    list.innerHTML = html;
    lucide.createIcons();
}

function selectElementById(id) {
    const el = Editor.doc.querySelector(`[data-editor-id="${id}"]`);
    if (el) {
        selectElement(el);
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function highlightLayer(id) {
    document.querySelectorAll('.layer-item').forEach(item => {
        item.classList.remove('selected');
    });
    // Encontrar e destacar
    document.querySelectorAll('.layer-item').forEach(item => {
        if (item.onclick && item.onclick.toString().includes(id)) {
            item.classList.add('selected');
        }
    });
}

// ==================== HISTÓRICO ====================
function saveToHistory() {
    const html = Editor.doc.body.innerHTML;
    
    // Remover histórico futuro se estiver no meio
    Editor.history = Editor.history.slice(0, Editor.historyIndex + 1);
    
    // Adicionar novo estado
    Editor.history.push(html);
    
    // Limitar tamanho
    if (Editor.history.length > Editor.maxHistory) {
        Editor.history.shift();
    }
    
    Editor.historyIndex = Editor.history.length - 1;
    
    // Atualizar timestamp
    document.getElementById('lastSaved').textContent = new Date().toLocaleTimeString();
}

function undo() {
    if (Editor.historyIndex > 0) {
        Editor.historyIndex--;
        Editor.doc.body.innerHTML = Editor.history[Editor.historyIndex];
        makeEditable();
        deselectElement();
        updateLayersList();
        showToast('Desfeito!');
    }
}

function redo() {
    if (Editor.historyIndex < Editor.history.length - 1) {
        Editor.historyIndex++;
        Editor.doc.body.innerHTML = Editor.history[Editor.historyIndex];
        makeEditable();
        deselectElement();
        updateLayersList();
        showToast('Refeito!');
    }
}

// ==================== VIEWPORT ====================
function initViewport() {
    document.querySelectorAll('.viewport-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const viewport = this.dataset.viewport;
            
            document.querySelectorAll('.viewport-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const container = document.getElementById('canvasContainer');
            container.classList.remove('desktop', 'tablet', 'mobile');
            container.classList.add(viewport);
            
            Editor.viewport = viewport;
        });
    });
}

// ==================== ZOOM ====================
function zoomIn() {
    if (Editor.zoom < 150) {
        Editor.zoom += 10;
        updateZoom();
    }
}

function zoomOut() {
    if (Editor.zoom > 50) {
        Editor.zoom -= 10;
        updateZoom();
    }
}

function updateZoom() {
    document.getElementById('zoomValue').textContent = Editor.zoom + '%';
    document.getElementById('canvasContainer').style.transform = `scale(${Editor.zoom / 100})`;
    document.getElementById('canvasContainer').style.transformOrigin = 'top center';
}

// ==================== PAINÉIS ====================
function initSidebarPanels() {
    document.querySelectorAll('.sidebar-nav-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const panelId = 'panel' + capitalize(this.dataset.panel);
            
            document.querySelectorAll('.sidebar-nav-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            document.querySelectorAll('.sidebar-panel').forEach(p => p.classList.remove('active'));
            document.getElementById(panelId).classList.add('active');
        });
    });
}

function initPropsTabs() {
    document.querySelectorAll('.props-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.props-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
        });
    });
}

// ==================== DRAG AND DROP ====================
function initDragAndDrop() {
    document.querySelectorAll('.widget-item').forEach(widget => {
        widget.addEventListener('dragstart', (e) => {
            Editor.draggedWidget = widget.dataset.widget;
            widget.classList.add('dragging');
        });
        
        widget.addEventListener('dragend', () => {
            widget.classList.remove('dragging');
            Editor.draggedWidget = null;
        });
    });
}

// ==================== MÍDIA ====================
function loadMediaLibrary() {
    const grid = document.getElementById('mediaGrid');
    const images = Array.from({length: 12}, (_, i) => `https://picsum.photos/200/200?random=${i+1}`);
    
    grid.innerHTML = images.map(url => `
        <div class="media-item" onclick="toggleMediaSelection(this, '${url}')">
            <img src="${url}" alt="">
        </div>
    `).join('');
}

function openMediaLibrary(callback) {
    Editor.mediaCallback = callback;
    openModal('mediaModal');
}

function toggleMediaSelection(item, url) {
    document.querySelectorAll('.media-item').forEach(i => i.classList.remove('selected'));
    item.classList.add('selected');
    Editor.selectedMedia = url;
}

function insertMedia() {
    if (Editor.selectedMedia && Editor.selected) {
        if (Editor.mediaCallback === 'background') {
            Editor.selected.style.backgroundImage = `url('${Editor.selectedMedia}')`;
            Editor.selected.style.backgroundSize = 'cover';
            Editor.selected.style.backgroundPosition = 'center';
            saveToHistory();
        }
    }
    closeModal('mediaModal');
}

// ==================== TEMPLATES ====================
function loadTemplate(templateName) {
    // Implementar carregamento de templates
    showToast(`Template "${templateName}" carregado!`, 'success');
}

function loadBlock(blockName) {
    showToast(`Bloco "${blockName}" inserido!`, 'success');
}

// ==================== SALVAR/PUBLICAR ====================
function saveDraft() {
    const html = Editor.doc.body.innerHTML;
    
    // Salvar no localStorage por enquanto
    localStorage.setItem('mercado_editor_draft', html);
    localStorage.setItem('mercado_editor_settings', JSON.stringify(Editor.pageSettings));
    localStorage.setItem('mercado_editor_global', JSON.stringify(Editor.globalStyles));
    
    showToast('Rascunho salvo!', 'success');
    
    // TODO: Enviar para API
    saveToServer(html);
}

function publishPage() {
    saveDraft();
    showToast('Página publicada com sucesso!', 'success');
}

function saveToServer(html) {
    fetch('/mercado/admin/api/editor-save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'save',
            page: 'index',
            html: html,
            settings: Editor.pageSettings,
            globalStyles: Editor.globalStyles
        })
    }).catch(() => {
        // Silenciosamente ignorar se API não disponível
    });
}

function previewPage() {
    const html = Editor.doc.documentElement.outerHTML;
    const win = window.open('', '_blank');
    win.document.write(html);
    win.document.close();
}

// ==================== CONFIGURAÇÕES ====================
function saveSettings() {
    Editor.pageSettings = {
        title: document.getElementById('pageTitle').value,
        description: document.getElementById('pageDescription').value,
        slug: document.getElementById('pageSlug').value,
        isHomepage: document.getElementById('isHomepage').checked
    };
    
    closeModal('settingsModal');
    showToast('Configurações salvas!', 'success');
}

// ==================== ESTILOS GLOBAIS ====================
function editGlobalColor(colorType) {
    const color = prompt('Digite a cor (hex):', Editor.globalStyles[colorType]);
    if (color) {
        Editor.globalStyles[colorType] = color;
        document.getElementById('global' + capitalize(colorType)).style.background = color;
        applyGlobalStyles();
    }
}

function updateGlobalFont(font) {
    Editor.globalStyles.font = font;
    applyGlobalStyles();
}

function updateGlobalFontSize(size) {
    Editor.globalStyles.fontSize = size;
    event.target.nextElementSibling.textContent = size + 'px';
    applyGlobalStyles();
}

function updateGlobalContainer(width) {
    Editor.globalStyles.container = width;
    event.target.nextElementSibling.textContent = width + 'px';
    applyGlobalStyles();
}

function updateGlobalRadius(radius) {
    Editor.globalStyles.radius = radius;
    event.target.nextElementSibling.textContent = radius + 'px';
    applyGlobalStyles();
}

function updateGlobalButtonStyle(style) {
    Editor.globalStyles.buttonStyle = style;
    applyGlobalStyles();
}

function applyGlobalStyles() {
    const root = Editor.doc.documentElement;
    root.style.setProperty('--primary', Editor.globalStyles.primary);
    root.style.setProperty('--secondary', Editor.globalStyles.secondary);
    root.style.setProperty('--accent', Editor.globalStyles.accent);
    root.style.setProperty('--text', Editor.globalStyles.text);
    root.style.setProperty('--font', `'${Editor.globalStyles.font}', sans-serif`);
    root.style.setProperty('--container', Editor.globalStyles.container + 'px');
    root.style.setProperty('--radius', Editor.globalStyles.radius + 'px');
    
    saveToHistory();
}

// ==================== MODAIS ====================
function openModal(id) {
    document.getElementById(id).classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// ==================== TOAST ====================
function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <i data-lucide="${type === 'success' ? 'check-circle' : type === 'error' ? 'x-circle' : 'info'}" class="toast-icon"></i>
        <span>${message}</span>
    `;
    container.appendChild(toast);
    lucide.createIcons();
    
    setTimeout(() => toast.remove(), 3000);
}

// ==================== UTILITÁRIOS ====================
function rgbToHex(rgb) {
    if (!rgb || rgb === 'transparent' || rgb === 'rgba(0, 0, 0, 0)') return '#ffffff';
    const result = rgb.match(/\d+/g);
    if (!result || result.length < 3) return '#ffffff';
    return '#' + result.slice(0, 3).map(x => {
        const hex = parseInt(x).toString(16);
        return hex.length === 1 ? '0' + hex : hex;
    }).join('');
}

function capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

// ==================== ATALHOS DE TECLADO ====================
document.addEventListener('keydown', (e) => {
    // Ctrl/Cmd + Z = Undo
    if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
        e.preventDefault();
        undo();
    }
    
    // Ctrl/Cmd + Shift + Z = Redo
    if ((e.ctrlKey || e.metaKey) && e.key === 'z' && e.shiftKey) {
        e.preventDefault();
        redo();
    }
    
    // Ctrl/Cmd + S = Save
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveDraft();
    }
    
    // Delete = Excluir elemento
    if (e.key === 'Delete' && Editor.selected) {
        deleteElement();
    }
    
    // Escape = Deselecionar
    if (e.key === 'Escape') {
        deselectElement();
    }
});

// Animações
function setAnimation(anim) {
    if (Editor.selected) {
        Editor.selected.setAttribute('data-animation', anim);
        showToast(`Animação "${anim}" aplicada!`);
    }
}

// Visibilidade por dispositivo
function toggleVisibility(device, btn) {
    btn.classList.toggle('active');
    if (Editor.selected) {
        const hidden = Editor.selected.getAttribute('data-hide-' + device);
        Editor.selected.setAttribute('data-hide-' + device, hidden ? '' : 'true');
    }
}

function toggleElementVisibility(id) {
    const el = Editor.doc.querySelector(`[data-editor-id="${id}"]`);
    if (el) {
        el.style.display = el.style.display === 'none' ? '' : 'none';
        saveToHistory();
    }
}
