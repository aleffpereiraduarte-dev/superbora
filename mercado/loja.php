<?php
/**
 * LOJA - Página do Mercado Específico
 * /mercado/loja.php?id=1 ou /mercado/loja.php?slug=mercado-teste
 */
session_name('OCSESSID');
session_start();
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

// Usar database config do mercado para conexão
require_once __DIR__ . '/config/database.php';
$conn = getMySQLi();

// Pegar mercado por ID ou slug
$partner = null;
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $r = $conn->query("SELECT * FROM om_market_partners WHERE partner_id = $id AND status = '1'");
    $partner = $r->fetch_assoc();
} elseif (isset($_GET['slug'])) {
    $slug = $conn->real_escape_string($_GET['slug']);
    $r = $conn->query("SELECT * FROM om_market_partners WHERE slug = '$slug' AND status = '1'");
    $partner = $r->fetch_assoc();
}

if (!$partner) {
    header('Location: index.php');
    exit;
}

$partner_id = $partner['partner_id'];

// Definir sessão do mercado atual (para carrinho e pedidos)
$_SESSION['market_partner_id'] = $partner_id;
$_SESSION['market_name'] = $partner['name'];

// Buscar categorias com produtos deste mercado
$stmt = $conn->prepare("
    SELECT DISTINCT c.* 
    FROM om_market_categories c
    INNER JOIN om_market_products_base p ON p.category_id = c.category_id
    INNER JOIN om_market_products_price pp ON pp.product_id = p.product_id AND pp.partner_id = ?
    WHERE pp.is_available = 1
    ORDER BY c.sort_order, c.name
");
$stmt->bind_param('i', $partner_id);
$stmt->execute();
$categories = $stmt->get_result();

// Categoria selecionada
$cat_id = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;

// Buscar produtos
$where = "pp.partner_id = $partner_id AND pp.is_available = 1";
if ($cat_id > 0) {
    $where .= " AND p.category_id = $cat_id";
}

// Busca por texto
$search = '';
if (!empty($_GET['q'])) {
    $search = $conn->real_escape_string($_GET['q']);
    $where .= " AND (p.name LIKE '%$search%' OR p.brand LIKE '%$search%')";
}

$products = $conn->query("
    SELECT p.*, pp.price, pp.price_promo, pp.stock,
           COALESCE(pp.price_promo, pp.price) as final_price
    FROM om_market_products_base p
    INNER JOIN om_market_products_price pp ON pp.product_id = p.product_id
    WHERE $where
    ORDER BY p.name
    LIMIT 100
");

// Verificar horário de funcionamento
$today = date('w'); // 0=Dom, 1=Seg...
$hours = $conn->query("SELECT * FROM om_market_partner_hours WHERE partner_id = $partner_id AND day_of_week = $today")->fetch_assoc();
$is_open = true;
$open_msg = '';
if ($hours) {
    if ($hours['is_closed']) {
        $is_open = false;
        $open_msg = 'Fechado hoje';
    } else {
        $now = date('H:i:s');
        if ($now < $hours['open_time'] || $now > $hours['close_time']) {
            $is_open = false;
            $open_msg = 'Abre às ' . substr($hours['open_time'], 0, 5);
        }
    }
}

// Customer ID
$customer_id = isset($_SESSION['customer_id']) ? $_SESSION['customer_id'] : 0;

// Contar itens no carrinho
$cart_count = 0;
if ($customer_id) {
    // Mover para uma query JOIN com os produtos principais
$stmt = $conn->prepare("SELECT SUM(quantity) as total FROM om_market_cart WHERE customer_id = ? AND partner_id = ?");
$stmt->bind_param('ii', $customer_id, $partner_id);
$stmt->execute();
$r = $stmt->get_result();
    $cart_count = $r->fetch_assoc()['total'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' fonts.googleapis.com cdnjs.cloudflare.com; font-src fonts.gstatic.com; img-src 'self' data:;">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($partner['name']) ?> - SuperBora</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', sans-serif; background: #f5f5f5; }

/* Header da Loja */
.store-header {
    background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 100%);
    color: #fff;
    padding: 20px;
}
.store-header .container {
    max-width: 1200px;
    margin: 0 auto;
}
.store-banner {
    height: 150px;
    background: linear-gradient(45deg, #3b82f6, #8b5cf6);
    border-radius: 16px;
    margin-bottom: 15px;
    background-size: cover;
    background-position: center;
}
.store-info {
    display: flex;
    align-items: center;
    gap: 15px;
}
.store-logo {
    width: 80px;
    height: 80px;
    background: #fff;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    margin-top: -40px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}
.store-details h1 { font-size: 24px; margin-bottom: 5px; }
.store-meta { display: flex; gap: 15px; font-size: 14px; color: #94a3b8; }
.store-meta span { display: flex; align-items: center; gap: 5px; }
.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.status-open { background: #22c55e; color: #fff; }
.status-closed { background: #ef4444; color: #fff; }

/* Search */
.search-bar {
    background: #fff;
    padding: 15px 20px;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.search-bar .container {
    max-width: 1200px;
    margin: 0 auto;
    display: flex;
    gap: 15px;
}
.search-input {
    flex: 1;
    display: flex;
    align-items: center;
    background: #f1f5f9;
    border-radius: 12px;
    padding: 0 15px;
}
.search-input input {
    flex: 1;
    border: none;
    background: none;
    padding: 12px;
    font-size: 15px;
    outline: none;
}
.search-input i { color: #64748b; }
.cart-btn {
    background: #22c55e;
    color: #fff;
    border: none;
    padding: 12px 20px;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}
.cart-btn .badge {
    background: #fff;
    color: #22c55e;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
}

/* Layout */
.main-content {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    display: grid;
    grid-template-columns: 250px 1fr;
    gap: 20px;
}

/* Sidebar Categorias */
.sidebar {
    background: #fff;
    border-radius: 16px;
    padding: 20px;
    height: fit-content;
    position: sticky;
    top: 80px;
}
.sidebar h3 {
    font-size: 14px;
    color: #64748b;
    margin-bottom: 15px;
    text-transform: uppercase;
}
.cat-list { list-style: none; }
.cat-list li a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    color: #334155;
    text-decoration: none;
    border-radius: 10px;
    margin-bottom: 5px;
    transition: all 0.2s;
}
.cat-list li a:hover, .cat-list li a.active {
    background: #f1f5f9;
    color: #3b82f6;
}
.cat-list li a .icon { font-size: 18px; width: 24px; text-align: center; }

/* Products Grid */
.products-section h2 {
    font-size: 20px;
    margin-bottom: 20px;
    color: #1e293b;
}
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 20px;
}
.product-card {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    transition: all 0.2s;
}
.product-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}
.product-img {
    height: 150px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
}
.product-img img {
    max-width: 80%;
    max-height: 80%;
    object-fit: contain;
}
.product-info {
    padding: 15px;
}
.product-name {
    font-size: 14px;
    color: #334155;
    margin-bottom: 5px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    height: 40px;
}
.product-weight {
    font-size: 12px;
    color: #94a3b8;
    margin-bottom: 10px;
}
.product-price {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.price {
    font-size: 18px;
    font-weight: 700;
    color: #22c55e;
}
.price-old {
    font-size: 12px;
    color: #94a3b8;
    text-decoration: line-through;
}
.add-btn {
    width: 36px;
    height: 36px;
    background: #22c55e;
    color: #fff;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-size: 18px;
    transition: all 0.2s;
}
.add-btn:hover { background: #16a34a; transform: scale(1.1); }

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #64748b;
}
.empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }

/* Mobile */
@media (max-width: 768px) {
    .main-content { grid-template-columns: 1fr; }
    .sidebar { display: none; }
    .store-banner { height: 100px; }
    .store-logo { width: 60px; height: 60px; font-size: 24px; margin-top: -30px; }
    .store-details h1 { font-size: 18px; }
    .products-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
}
</style>

<!-- HEADER PREMIUM v3.0 -->
<style>

/* ══════════════════════════════════════════════════════════════════════════════
   🎨 HEADER PREMIUM v3.0 - SuperBora
   ══════════════════════════════════════════════════════════════════════════════ */

/* Variáveis do Header */
:root {
    --header-bg: rgba(255, 255, 255, 0.92);
    --header-bg-scrolled: rgba(255, 255, 255, 0.98);
    --header-blur: 20px;
    --header-shadow: 0 4px 30px rgba(0, 0, 0, 0.08);
    --header-border: rgba(0, 0, 0, 0.04);
    --header-height: 72px;
    --header-height-mobile: 64px;
}

/* Header Principal */
.header, .site-header, [class*="header-main"] {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 1000 !important;
    background: var(--header-bg) !important;
    backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    -webkit-backdrop-filter: blur(var(--header-blur)) saturate(180%) !important;
    border-bottom: 1px solid var(--header-border) !important;
    box-shadow: none !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    height: auto !important;
    min-height: var(--header-height) !important;
}

.header.scrolled, .site-header.scrolled {
    background: var(--header-bg-scrolled) !important;
    box-shadow: var(--header-shadow) !important;
}

/* Container do Header */
.header-inner, .header-content, .header > div:first-child {
    max-width: 1400px !important;
    margin: 0 auto !important;
    padding: 12px 24px !important;
    display: flex !important;
    align-items: center !important;
    gap: 20px !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOCALIZAÇÃO - Estilo Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.location-btn, .endereco, [class*="location"], [class*="endereco"], [class*="address"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 10px 18px !important;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(16, 185, 129, 0.04)) !important;
    border: 1px solid rgba(16, 185, 129, 0.15) !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    min-width: 200px !important;
    max-width: 320px !important;
}

.location-btn:hover, .endereco:hover, [class*="location"]:hover {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.06)) !important;
    border-color: rgba(16, 185, 129, 0.25) !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15) !important;
}

/* Ícone de localização */
.location-btn svg, .location-btn i, [class*="location"] svg {
    width: 22px !important;
    height: 22px !important;
    color: #10b981 !important;
    flex-shrink: 0 !important;
}

/* Texto da localização */
.location-text, .endereco-text {
    flex: 1 !important;
    min-width: 0 !important;
}

.location-label, .entregar-em {
    font-size: 11px !important;
    font-weight: 500 !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    margin-bottom: 2px !important;
}

.location-address, .endereco-rua {
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* Seta da localização */
.location-arrow, .location-btn > svg:last-child {
    width: 16px !important;
    height: 16px !important;
    color: #94a3b8 !important;
    transition: transform 0.2s ease !important;
}

.location-btn:hover .location-arrow {
    transform: translateX(3px) !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   TEMPO DE ENTREGA - Badge Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.delivery-time, .tempo-entrega, [class*="delivery-time"], [class*="tempo"] {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 10px 16px !important;
    background: linear-gradient(135deg, #0f172a, #1e293b) !important;
    border-radius: 12px !important;
    color: white !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.2) !important;
    transition: all 0.3s ease !important;
}

.delivery-time:hover, .tempo-entrega:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.25) !important;
}

.delivery-time svg, .tempo-entrega svg, .delivery-time i {
    width: 18px !important;
    height: 18px !important;
    color: #10b981 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   LOGO - Design Moderno
   ══════════════════════════════════════════════════════════════════════════════ */

.logo, .site-logo, [class*="logo"] {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    text-decoration: none !important;
    transition: transform 0.3s ease !important;
}

.logo:hover {
    transform: scale(1.02) !important;
}

.logo-icon, .logo img, .logo svg {
    width: 48px !important;
    height: 48px !important;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
    border-radius: 14px !important;
    padding: 10px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.logo:hover .logo-icon, .logo:hover img {
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4) !important;
    transform: rotate(-3deg) !important;
}

.logo-text, .logo span, .site-title {
    font-size: 1.5rem !important;
    font-weight: 800 !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    -webkit-background-clip: text !important;
    -webkit-text-fill-color: transparent !important;
    background-clip: text !important;
    letter-spacing: -0.02em !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   BUSCA - Search Bar Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.search-container, .search-box, [class*="search"], .busca {
    flex: 1 !important;
    max-width: 600px !important;
    position: relative !important;
}

.search-input, input[type="search"], input[name*="search"], input[name*="busca"], .busca input {
    width: 100% !important;
    padding: 14px 20px 14px 52px !important;
    background: #f1f5f9 !important;
    border: 2px solid transparent !important;
    border-radius: 16px !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
}

.search-input:hover, input[type="search"]:hover {
    background: #e2e8f0 !important;
}

.search-input:focus, input[type="search"]:focus {
    background: #ffffff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12), inset 0 2px 4px rgba(0, 0, 0, 0.02) !important;
    outline: none !important;
}

.search-input::placeholder {
    color: #94a3b8 !important;
    font-weight: 400 !important;
}

/* Ícone da busca */
.search-icon, .search-container svg, .busca svg {
    position: absolute !important;
    left: 18px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 22px !important;
    height: 22px !important;
    color: #94a3b8 !important;
    pointer-events: none !important;
    transition: color 0.3s ease !important;
}

.search-input:focus + .search-icon,
.search-container:focus-within svg {
    color: #10b981 !important;
}

/* Botão de busca por voz (opcional) */
.search-voice-btn {
    position: absolute !important;
    right: 12px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 36px !important;
    height: 36px !important;
    background: transparent !important;
    border: none !important;
    border-radius: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.search-voice-btn:hover {
    background: rgba(16, 185, 129, 0.1) !important;
}

.search-voice-btn svg {
    width: 20px !important;
    height: 20px !important;
    color: #64748b !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   CARRINHO - Cart Button Premium
   ══════════════════════════════════════════════════════════════════════════════ */

.cart-btn, .carrinho-btn, [class*="cart"], [class*="carrinho"], a[href*="cart"], a[href*="carrinho"] {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 52px !important;
    height: 52px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border: none !important;
    border-radius: 16px !important;
    cursor: pointer !important;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
}

.cart-btn:hover, .carrinho-btn:hover, [class*="cart"]:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4) !important;
}

.cart-btn:active {
    transform: translateY(-1px) scale(0.98) !important;
}

.cart-btn svg, .carrinho-btn svg, [class*="cart"] svg {
    width: 26px !important;
    height: 26px !important;
    color: white !important;
}

/* Badge do carrinho */
.cart-badge, .carrinho-badge, [class*="cart-count"], [class*="badge"] {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 24px !important;
    height: 24px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    color: white !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 6px !important;
    border: 3px solid white !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: badge-pulse 2s ease-in-out infinite !important;
}

@keyframes badge-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

/* ══════════════════════════════════════════════════════════════════════════════
   MENU MOBILE
   ══════════════════════════════════════════════════════════════════════════════ */

.menu-btn, .hamburger, [class*="menu-toggle"] {
    display: none !important;
    width: 44px !important;
    height: 44px !important;
    background: #f1f5f9 !important;
    border: none !important;
    border-radius: 12px !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}

.menu-btn:hover {
    background: #e2e8f0 !important;
}

.menu-btn svg {
    width: 24px !important;
    height: 24px !important;
    color: #475569 !important;
}

/* ══════════════════════════════════════════════════════════════════════════════
   RESPONSIVO
   ══════════════════════════════════════════════════════════════════════════════ */

@media (max-width: 1024px) {
    .search-container, .search-box {
        max-width: 400px !important;
    }
    
    .location-btn, .endereco {
        max-width: 250px !important;
    }
}

@media (max-width: 768px) {
    :root {
        --header-height: var(--header-height-mobile);
    }
    
    .header-inner, .header-content {
        padding: 10px 16px !important;
        gap: 12px !important;
    }
    
    /* Esconder busca no header mobile - mover para baixo */
    .search-container, .search-box, [class*="search"]:not(.search-icon) {
        position: absolute !important;
        top: 100% !important;
        left: 0 !important;
        right: 0 !important;
        max-width: 100% !important;
        padding: 12px 16px !important;
        background: white !important;
        border-top: 1px solid #e2e8f0 !important;
        display: none !important;
    }
    
    .search-container.active {
        display: block !important;
    }
    
    /* Logo menor */
    .logo-icon, .logo img {
        width: 42px !important;
        height: 42px !important;
        border-radius: 12px !important;
    }
    
    .logo-text {
        display: none !important;
    }
    
    /* Localização compacta */
    .location-btn, .endereco {
        min-width: auto !important;
        max-width: 180px !important;
        padding: 8px 12px !important;
    }
    
    .location-label, .entregar-em {
        display: none !important;
    }
    
    .location-address {
        font-size: 13px !important;
    }
    
    /* Tempo de entrega menor */
    .delivery-time, .tempo-entrega {
        padding: 8px 12px !important;
        font-size: 12px !important;
    }
    
    /* Carrinho menor */
    .cart-btn, .carrinho-btn {
        width: 46px !important;
        height: 46px !important;
        border-radius: 14px !important;
    }
    
    .cart-btn svg {
        width: 22px !important;
        height: 22px !important;
    }
    
    /* Mostrar menu button */
    .menu-btn, .hamburger {
        display: flex !important;
    }
}

@media (max-width: 480px) {
    .location-btn, .endereco {
        max-width: 140px !important;
    }
    
    .delivery-time, .tempo-entrega {
        display: none !important;
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
   ANIMAÇÕES DE ENTRADA
   ══════════════════════════════════════════════════════════════════════════════ */

@keyframes headerSlideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.header, .site-header {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *, .header-content > * {
    animation: headerSlideDown 0.5s ease forwards !important;
}

.header-inner > *:nth-child(1) { animation-delay: 0.05s !important; }
.header-inner > *:nth-child(2) { animation-delay: 0.1s !important; }
.header-inner > *:nth-child(3) { animation-delay: 0.15s !important; }
.header-inner > *:nth-child(4) { animation-delay: 0.2s !important; }
.header-inner > *:nth-child(5) { animation-delay: 0.25s !important; }

/* ══════════════════════════════════════════════════════════════════════════════
   AJUSTES DE BODY PARA HEADER FIXED
   ══════════════════════════════════════════════════════════════════════════════ */

body {
    padding-top: calc(var(--header-height) + 10px) !important;
}

@media (max-width: 768px) {
    body {
        padding-top: calc(var(--header-height-mobile) + 10px) !important;
    }
}

</style>
</head>
<body>

<!-- Header da Loja -->
<div class="store-header">
    <div class="container">
        <?php if ($partner['banner']): ?>
        <div class="store-banner" style="background-image: url('<?= $partner['banner'] ?>')"></div>
        <?php else: ?>
        <div class="store-banner"></div>
        <?php endif; ?>
        
        <div class="store-info">
            <div class="store-logo">
                <?php if ($partner['logo']): ?>
                    <img src="<?= $partner['logo'] ?>" style="max-width:100%;max-height:100%;border-radius:12px">
                <?php else: ?>
                    🏪
                <?php endif; ?>
            </div>
            <div class="store-details">
                <h1><?= htmlspecialchars($partner['name']) ?></h1>
                <div class="store-meta">
                    <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($partner['address']) ?></span>
                    <span><i class="fas fa-clock"></i> ~<?= $partner['avg_prep_time'] ?>min</span>
                    <span class="status-badge <?= $is_open ? 'status-open' : 'status-closed' ?>">
                        <?= $is_open ? 'Aberto' : $open_msg ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Search Bar -->
<div class="search-bar">
    <div class="container">
        <form class="search-input" method="get">
<?php if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); ?>
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="id" value="<?= $partner_id ?>">
            <i class="fas fa-search"></i>
            <input type="text" name="q" placeholder="Buscar produtos..." value="<?= htmlspecialchars($search) ?>">
        </form>
        <a href="carrinho.php?partner=<?= $partner_id ?>" class="cart-btn">
            <i class="fas fa-shopping-cart"></i>
            Carrinho
            <?php if ($cart_count > 0): ?>
            <span class="badge"><?= $cart_count ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">
    <!-- Sidebar -->
    <aside class="sidebar">
        <h3>Categorias</h3>
        <ul class="cat-list">
            <li>
                <a href="?id=<?= $partner_id ?>" class="<?= $cat_id == 0 ? 'active' : '' ?>">
                    <span class="icon">🏠</span> Todos os Produtos
                </a>
            </li>
            <?php while ($cat = $categories->fetch_assoc()): ?>
            <li>
                <a href="?id=<?= $partner_id ?>&cat=<?= $cat['category_id'] ?>" 
                   class="<?= $cat_id == $cat['category_id'] ? 'active' : '' ?>">
                    <span class="icon"><?= $cat['icon'] ?: '📦' ?></span>
                    <?= htmlspecialchars($cat['name']) ?>
                </a>
            </li>
            <?php endwhile; ?>
        </ul>
    </aside>

    <!-- Products -->
    <section class="products-section">
        <h2>
            <?php if ($search): ?>
                Resultados para "<?= htmlspecialchars($search) ?>"
            <?php elseif ($cat_id): ?>
                <?= htmlspecialchars($categories->data_seek(0) ? '' : 'Categoria') ?>
            <?php else: ?>
                Todos os Produtos
            <?php endif; ?>
        </h2>

        <?php if ($products->num_rows > 0): ?>
        <div class="products-grid">
            <?php while ($prod = $products->fetch_assoc()): ?>
            <div class="product-card">
                <div class="product-img">
                    <?php if ($prod['image']): ?>
                        <img src="/image/<?= htmlspecialchars($prod['image']) ?>" alt="<?= htmlspecialchars($prod['name']) ?>" loading="lazy" width="150" height="150">
                    <?php else: ?>
                        <i class="fas fa-box" style="font-size:48px;color:#cbd5e1"></i>
                    <?php endif; ?>
                </div>
                <div class="product-info">
                    <div class="product-name"><?= htmlspecialchars($prod['name']) ?></div>
                    <div class="product-weight"><?= htmlspecialchars($prod['weight']) ?></div>
                    <div class="product-price">
                        <div>
                            <?php if ($prod['price_promo'] && $prod['price_promo'] < $prod['price']): ?>
                                <span class="price-old">R$ <?= number_format($prod['price'], 2, ',', '.') ?></span><br>
                            <?php endif; ?>
                            <span class="price">R$ <?= number_format($prod['final_price'], 2, ',', '.') ?></span>
                        </div>
                        <button class="add-btn" onclick="addToCart(<?= $prod['product_id'] ?>, <?= $partner_id ?>)">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-search"></i>
            <h3>Nenhum produto encontrado</h3>
            <p>Tente buscar por outro termo</p>
        </div>
        <?php endif; ?>
    </section>
</div>

<script>
function addToCart(productId, partnerId) {
    fetch('api/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'add',
            product_id: productId,
            partner_id: partnerId,
            quantity: 1
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Atualizar badge do carrinho
            const badge = document.querySelector('.cart-btn .badge');
            if (badge) {
                badge.textContent = data.cart_count;
            } else {
                document.querySelector('.cart-btn').innerHTML += `<span class="badge">${data.cart_count}</span>`;
            }
            
            // Feedback visual
            event.target.closest('.add-btn').style.background = '#16a34a';
            setTimeout(() => {
                event.target.closest('.add-btn').style.background = '#22c55e';
            }, 300);
        } else {
            alert(data.message || 'Erro ao adicionar');
        }
    });
}
</script>


<?php include __DIR__ . '/components/order-banner.php'; ?>

<?php include __DIR__ . '/components/order-chat.php'; ?>

<script>
// Header scroll effect
(function() {
    const header = document.querySelector('.header, .site-header, [class*="header-main"]');
    if (!header) return;
    
    let lastScroll = 0;
    let ticking = false;
    
    function updateHeader() {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
        
        // Hide/show on scroll (opcional)
        /*
        if (currentScroll > lastScroll && currentScroll > 100) {
            header.style.transform = 'translateY(-100%)';
        } else {
            header.style.transform = 'translateY(0)';
        }
        */
        
        lastScroll = currentScroll;
        ticking = false;
    }
    
    window.addEventListener('scroll', function() {
        if (!ticking) {
            requestAnimationFrame(updateHeader);
            ticking = true;
        }
    });
    
    // Cart badge animation
    window.animateCartBadge = function() {
        const badge = document.querySelector('.cart-badge, .carrinho-badge, [class*="cart-count"]');
        if (badge) {
            badge.style.transform = 'scale(1.3)';
            setTimeout(() => {
                badge.style.transform = 'scale(1)';
            }, 200);
        }
    };
    
    // Mobile search toggle
    const searchToggle = document.querySelector('.search-toggle, [class*="search-btn"]');
    const searchContainer = document.querySelector('.search-container, .search-box');
    
    if (searchToggle && searchContainer) {
        searchToggle.addEventListener('click', function() {
            searchContainer.classList.toggle('active');
        });
    }
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     🎨 ONEMUNDO HEADER PREMIUM v3.0 - CSS FINAL UNIFICADO
═══════════════════════════════════════════════════════════════════════════════ -->
<style id="om-header-final">
/* RESET */
.mkt-header, .mkt-header-row, .mkt-logo, .mkt-logo-box, .mkt-logo-text,
.mkt-user, .mkt-user-avatar, .mkt-guest, .mkt-cart, .mkt-cart-count, .mkt-search,
.om-topbar, .om-topbar-main, .om-topbar-icon, .om-topbar-content,
.om-topbar-label, .om-topbar-address, .om-topbar-arrow, .om-topbar-time {
    all: revert;
}

/* TOPBAR VERDE */
.om-topbar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 14px 20px !important;
    background: linear-gradient(135deg, #047857 0%, #059669 40%, #10b981 100%) !important;
    color: #fff !important;
    cursor: pointer !important;
    transition: all 0.3s ease !important;
    position: relative !important;
    overflow: hidden !important;
}

.om-topbar::before {
    content: '' !important;
    position: absolute !important;
    top: 0 !important;
    left: -100% !important;
    width: 100% !important;
    height: 100% !important;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent) !important;
    transition: left 0.6s ease !important;
}

.om-topbar:hover::before { left: 100% !important; }
.om-topbar:hover { background: linear-gradient(135deg, #065f46 0%, #047857 40%, #059669 100%) !important; }

.om-topbar-main {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex: 1 !important;
    min-width: 0 !important;
}

.om-topbar-icon {
    width: 40px !important;
    height: 40px !important;
    background: rgba(255,255,255,0.18) !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar:hover .om-topbar-icon {
    background: rgba(255,255,255,0.25) !important;
    transform: scale(1.05) !important;
}

.om-topbar-icon svg { width: 20px !important; height: 20px !important; color: #fff !important; }

.om-topbar-content { flex: 1 !important; min-width: 0 !important; }

.om-topbar-label {
    font-size: 11px !important;
    font-weight: 500 !important;
    opacity: 0.85 !important;
    margin-bottom: 2px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: block !important;
}

.om-topbar-address {
    font-size: 14px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-width: 220px !important;
}

.om-topbar-arrow {
    width: 32px !important;
    height: 32px !important;
    background: rgba(255,255,255,0.12) !important;
    border-radius: 8px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    transition: all 0.3s ease !important;
    margin-right: 12px !important;
}

.om-topbar:hover .om-topbar-arrow {
    background: rgba(255,255,255,0.2) !important;
    transform: translateX(3px) !important;
}

.om-topbar-arrow svg { width: 16px !important; height: 16px !important; color: #fff !important; }

.om-topbar-time {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 8px 14px !important;
    background: rgba(0,0,0,0.2) !important;
    border-radius: 50px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    flex-shrink: 0 !important;
    backdrop-filter: blur(10px) !important;
    transition: all 0.3s ease !important;
}

.om-topbar-time:hover { background: rgba(0,0,0,0.3) !important; transform: scale(1.02) !important; }
.om-topbar-time svg { width: 16px !important; height: 16px !important; color: #34d399 !important; }

/* HEADER BRANCO */
.mkt-header {
    background: #ffffff !important;
    padding: 0 !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 9999 !important;
    box-shadow: 0 2px 20px rgba(0,0,0,0.08) !important;
    border-bottom: none !important;
}

.mkt-header-row {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 14px 20px !important;
    margin-bottom: 0 !important;
    background: #fff !important;
    border-bottom: 1px solid rgba(0,0,0,0.06) !important;
}

/* LOGO */
.mkt-logo {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-decoration: none !important;
    flex-shrink: 0 !important;
}

.mkt-logo-box {
    width: 44px !important;
    height: 44px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 14px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 22px !important;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.35) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-logo:hover .mkt-logo-box {
    transform: scale(1.05) rotate(-3deg) !important;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45) !important;
}

.mkt-logo-text {
    font-size: 20px !important;
    font-weight: 800 !important;
    color: #10b981 !important;
    letter-spacing: -0.02em !important;
}

/* USER */
.mkt-user { margin-left: auto !important; text-decoration: none !important; }

.mkt-user-avatar {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: linear-gradient(135deg, #10b981, #059669) !important;
    border-radius: 50% !important;
    color: #fff !important;
    font-weight: 700 !important;
    font-size: 16px !important;
    box-shadow: 0 3px 12px rgba(16, 185, 129, 0.3) !important;
    transition: all 0.3s ease !important;
}

.mkt-user-avatar:hover {
    transform: scale(1.08) !important;
    box-shadow: 0 5px 18px rgba(16, 185, 129, 0.4) !important;
}

.mkt-user.mkt-guest {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    background: #f1f5f9 !important;
    border-radius: 12px !important;
    transition: all 0.3s ease !important;
}

.mkt-user.mkt-guest:hover { background: #e2e8f0 !important; }
.mkt-user.mkt-guest svg { width: 24px !important; height: 24px !important; color: #64748b !important; }

/* CARRINHO */
.mkt-cart {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 46px !important;
    height: 46px !important;
    background: linear-gradient(135deg, #1e293b, #0f172a) !important;
    border: none !important;
    border-radius: 14px !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    box-shadow: 0 4px 15px rgba(15, 23, 42, 0.25) !important;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}

.mkt-cart:hover {
    transform: translateY(-3px) scale(1.02) !important;
    box-shadow: 0 8px 25px rgba(15, 23, 42, 0.3) !important;
}

.mkt-cart:active { transform: translateY(-1px) scale(0.98) !important; }
.mkt-cart svg { width: 22px !important; height: 22px !important; color: #fff !important; }

.mkt-cart-count {
    position: absolute !important;
    top: -6px !important;
    right: -6px !important;
    min-width: 22px !important;
    height: 22px !important;
    padding: 0 6px !important;
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    border-radius: 11px !important;
    color: #fff !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 2px solid #fff !important;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4) !important;
    animation: cartPulse 2s ease-in-out infinite !important;
}

@keyframes cartPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }

/* BUSCA */
.mkt-search {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    background: #f1f5f9 !important;
    border-radius: 14px !important;
    padding: 0 16px !important;
    margin: 0 16px 16px !important;
    border: 2px solid transparent !important;
    transition: all 0.3s ease !important;
}

.mkt-search:focus-within {
    background: #fff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1) !important;
}

.mkt-search svg {
    width: 20px !important;
    height: 20px !important;
    color: #94a3b8 !important;
    flex-shrink: 0 !important;
    transition: color 0.3s ease !important;
}

.mkt-search:focus-within svg { color: #10b981 !important; }

.mkt-search input {
    flex: 1 !important;
    border: none !important;
    background: transparent !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    outline: none !important;
    padding: 14px 0 !important;
    width: 100% !important;
}

.mkt-search input::placeholder { color: #94a3b8 !important; }

/* RESPONSIVO */
@media (max-width: 480px) {
    .om-topbar { padding: 12px 16px !important; }
    .om-topbar-icon { width: 36px !important; height: 36px !important; }
    .om-topbar-address { max-width: 150px !important; font-size: 13px !important; }
    .om-topbar-arrow { display: none !important; }
    .om-topbar-time { padding: 6px 10px !important; font-size: 11px !important; }
    .mkt-header-row { padding: 12px 16px !important; }
    .mkt-logo-box { width: 40px !important; height: 40px !important; font-size: 18px !important; }
    .mkt-logo-text { font-size: 18px !important; }
    .mkt-cart { width: 42px !important; height: 42px !important; }
    .mkt-search { margin: 0 12px 12px !important; }
    .mkt-search input { font-size: 14px !important; padding: 12px 0 !important; }
}

/* ANIMAÇÕES */
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.mkt-header { animation: slideDown 0.4s ease !important; }

::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
::selection { background: rgba(16, 185, 129, 0.2); color: #047857; }
</style>

<script>
(function() {
    var h = document.querySelector('.mkt-header');
    if (h && !document.querySelector('.om-topbar')) {
        var t = document.createElement('div');
        t.className = 'om-topbar';
        t.innerHTML = '<div class="om-topbar-main"><div class="om-topbar-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg></div><div class="om-topbar-content"><div class="om-topbar-label">Entregar em</div><div class="om-topbar-address" id="omAddrFinal">Carregando...</div></div><div class="om-topbar-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div></div><div class="om-topbar-time"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>25-35 min</div>';
        h.insertBefore(t, h.firstChild);
        fetch('/mercado/api/address.php?action=list').then(r=>r.json()).then(d=>{var el=document.getElementById('omAddrFinal');if(el&&d.current)el.textContent=d.current.address_1||'Selecionar';}).catch(()=>{});
    }
    var l = document.querySelector('.mkt-logo');
    if (l && !l.querySelector('.mkt-logo-text')) {
        var s = document.createElement('span');
        s.className = 'mkt-logo-text';
        s.textContent = 'Mercado';
        l.appendChild(s);
    }
})();
</script>
</body>
</html>
<?php $conn->close(); ?>
