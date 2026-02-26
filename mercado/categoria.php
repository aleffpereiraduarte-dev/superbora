<?php
/**
 * 🛒 ONEMUNDO MERCADO - CATEGORIA
 * Listagem de produtos por categoria/busca
 */

require_once 'config.php';

$customer = getOpenCartCustomer();
$customer_id = $customer['id'] ?? 0;
$customer_cep = $customer['postcode'] ?? '';

if (!$customer || !$customer_cep) {
    header('Location: index.php');
    exit;
}

$mercados = getMercadosDisponiveis($customer_cep);
if (empty($mercados)) {
    header('Location: index.php');
    exit;
}

$mercado_atual = $mercados[0];
$market_id = $mercado_atual['partner_id'];

$categoria_slug = $_GET['cat'] ?? '';
$busca = $_GET['q'] ?? '';
$ordenar = $_GET['sort'] ?? 'relevancia';
$pagina = max(1, intval($_GET['page'] ?? 1));
$por_pagina = 24;

$categorias = getCategorias();
$categoria_atual = null;
$categoria_id = null;

if ($categoria_slug) {
    foreach ($categorias as $cat) {
        if ($cat['slug'] === $categoria_slug) {
            $categoria_atual = $cat;
            $categoria_id = $cat['category_id'];
            break;
        }
    }
}

if ($busca) {
    $page_title = "Busca: " . htmlspecialchars($busca);
    $page_icon = "🔍";
} elseif ($categoria_atual) {
    $page_title = $categoria_atual['name'];
    $page_icon = $categoria_atual['icon'];
} else {
    $page_title = "Todos os Produtos";
    $page_icon = "🛍️";
}

$produtos = getProdutos($market_id, $categoria_id, $busca, 200);

if ($ordenar === 'menor_preco') {
    usort($produtos, fn($a, $b) => $a['preco_final'] <=> $b['preco_final']);
} elseif ($ordenar === 'maior_preco') {
    usort($produtos, fn($a, $b) => $b['preco_final'] <=> $a['preco_final']);
} elseif ($ordenar === 'nome') {
    usort($produtos, fn($a, $b) => strcmp($a['name'], $b['name']));
} elseif ($ordenar === 'promocao') {
    usort($produtos, fn($a, $b) => $b['em_promocao'] <=> $a['em_promocao']);
}

$total_produtos = count($produtos);
$total_paginas = ceil($total_produtos / $por_pagina);
$produtos = array_slice($produtos, ($pagina - 1) * $por_pagina, $por_pagina);

$cart_count = 0;
$cart_total = 0;
if ($customer_id) {
    $cart = getCarrinho($customer_id);
    if ($cart) {
        $cart_count = $cart['items_count'] ?? 0;
        $cart_total = $cart['subtotal'] ?? 0;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title><?= htmlspecialchars($page_title) ?> - Mercado OneMundo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    :root{--black:#000;--white:#fff;--gray-50:#fafafa;--gray-100:#f4f4f5;--gray-200:#e4e4e7;--gray-300:#d4d4d8;--gray-400:#a1a1aa;--gray-500:#71717a;--gray-600:#52525b;--gray-700:#3f3f46;--gray-800:#27272a;--gray-900:#18181b;--brand-50:#ecfdf5;--brand-100:#d1fae5;--brand-400:#34d399;--brand-500:#10b981;--brand-600:#059669;--rose:#f43f5e;--gradient-brand:linear-gradient(135deg,#10b981 0%,#059669 100%);--shadow-sm:0 2px 4px rgba(0,0,0,0.04);--shadow-lg:0 12px 24px rgba(0,0,0,0.06);--shadow-xl:0 24px 48px rgba(0,0,0,0.08);--radius-sm:6px;--radius-md:10px;--radius-lg:14px;--radius-xl:18px;--radius-2xl:24px;--radius-full:9999px;--font:'Outfit',-apple-system,sans-serif;--ease:cubic-bezier(0.4,0,0.2,1);--ease-bounce:cubic-bezier(0.34,1.56,0.64,1);--safe-top:env(safe-area-inset-top,0px);--safe-bottom:env(safe-area-inset-bottom,0px)}
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    html{scroll-behavior:smooth;-webkit-text-size-adjust:100%;-webkit-tap-highlight-color:transparent}
    body{font-family:var(--font);background:var(--gray-50);color:var(--gray-900);line-height:1.5;-webkit-font-smoothing:antialiased;overflow-x:hidden;padding-top:var(--safe-top)}
    a{text-decoration:none;color:inherit}button{font-family:inherit;cursor:pointer;border:none;background:none}img{max-width:100%;height:auto;display:block}::-webkit-scrollbar{display:none}*{scrollbar-width:none}
    @keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
    @keyframes pop{0%{transform:scale(0)}50%{transform:scale(1.1)}100%{transform:scale(1)}}
    @keyframes spin{to{transform:rotate(360deg)}}
    .stagger>*{opacity:0;animation:fadeInUp .4s var(--ease) forwards}.stagger>*:nth-child(1){animation-delay:.02s}.stagger>*:nth-child(2){animation-delay:.04s}.stagger>*:nth-child(3){animation-delay:.06s}.stagger>*:nth-child(4){animation-delay:.08s}.stagger>*:nth-child(5){animation-delay:.1s}.stagger>*:nth-child(6){animation-delay:.12s}.stagger>*:nth-child(7){animation-delay:.14s}.stagger>*:nth-child(8){animation-delay:.16s}
    .header{position:sticky;top:0;z-index:200;background:rgba(255,255,255,0.92);backdrop-filter:blur(20px) saturate(180%);-webkit-backdrop-filter:blur(20px) saturate(180%);border-bottom:1px solid rgba(0,0,0,0.05);padding:0 12px}@media(min-width:640px){.header{padding:0 16px}}
    .headerInner{max-width:1400px;margin:0 auto;display:flex;align-items:center;gap:10px;height:56px}@media(min-width:640px){.headerInner{height:64px;gap:16px}}
    .backBtn{width:40px;height:40px;display:flex;align-items:center;justify-content:center;border-radius:var(--radius-md);color:var(--gray-600);flex-shrink:0}.backBtn:active{background:var(--gray-100)}
    .searchWrapper{flex:1;position:relative;min-width:0}
    .searchBox{display:flex;align-items:center;background:var(--gray-100);border-radius:var(--radius-lg);padding:0 12px;height:40px;border:2px solid transparent;transition:all .2s}@media(min-width:640px){.searchBox{height:44px;border-radius:var(--radius-xl);padding:0 4px 0 16px}}.searchBox:focus-within{background:var(--white);border-color:var(--brand-400);box-shadow:0 0 0 3px rgba(16,185,129,0.1)}.searchBox .sIcon{color:var(--gray-400);flex-shrink:0;width:18px;height:18px}.searchBox:focus-within .sIcon{color:var(--brand-500)}.searchInput{flex:1;padding:10px 8px;border:none;background:transparent;font-size:14px;font-weight:500;color:var(--gray-900);min-width:0}@media(min-width:640px){.searchInput{font-size:15px;padding:10px 12px}}.searchInput::placeholder{color:var(--gray-400)}.searchInput:focus{outline:none}.searchBtn{display:none;padding:8px 16px;background:var(--gradient-brand);color:var(--white);font-size:14px;font-weight:600;border-radius:var(--radius-md)}@media(min-width:640px){.searchBtn{display:block}}
    .headerActions{display:flex;align-items:center;gap:4px;flex-shrink:0}.headerBtn{position:relative;width:40px;height:40px;display:flex;align-items:center;justify-content:center;background:transparent;border-radius:var(--radius-md);color:var(--gray-600)}.headerBtn:active{background:var(--gray-100);transform:scale(0.95)}.headerBtn.cart{background:var(--gray-900);color:var(--white)}.headerBtn .badge{position:absolute;top:-2px;right:-2px;min-width:18px;height:18px;background:var(--rose);color:var(--white);font-size:10px;font-weight:700;border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;border:2px solid var(--white);animation:pop .3s var(--ease-bounce)}
    .main{max-width:1400px;margin:0 auto;padding:0 12px calc(80px + var(--safe-bottom))}@media(min-width:640px){.main{padding:0 16px 100px}}@media(min-width:768px){.main{padding:0 24px 80px}}
    .pageHeader{padding:16px 0;display:flex;flex-direction:column;gap:12px}@media(min-width:640px){.pageHeader{padding:24px 0;gap:16px}}
    .pageTitle{display:flex;align-items:center;gap:10px}.pageTitle .icon{font-size:28px}@media(min-width:640px){.pageTitle .icon{font-size:36px}}.pageTitle h1{font-size:20px;font-weight:800;color:var(--gray-900);letter-spacing:-0.02em}@media(min-width:640px){.pageTitle h1{font-size:26px}}.pageTitle .count{font-size:14px;font-weight:500;color:var(--gray-400);margin-left:4px}
    .filters{display:flex;gap:8px;overflow-x:auto;padding:4px 0;margin:0 -12px;padding-left:12px;padding-right:12px}@media(min-width:640px){.filters{margin:0;padding-left:0;padding-right:0;flex-wrap:wrap}}
    .filterBtn{display:flex;align-items:center;gap:6px;padding:8px 14px;background:var(--white);border:1px solid var(--gray-200);border-radius:var(--radius-full);font-size:13px;font-weight:600;color:var(--gray-600);white-space:nowrap;min-height:36px;flex-shrink:0}.filterBtn:active{background:var(--gray-100)}.filterBtn.active{background:var(--gray-900);color:var(--white);border-color:var(--gray-900)}.filterBtn svg{width:14px;height:14px}
    .categories{display:flex;gap:8px;overflow-x:auto;padding:8px 0;margin:0 -12px;padding-left:12px;padding-right:12px}@media(min-width:1024px){.categories{flex-wrap:wrap;margin:0;padding-left:0;padding-right:0}}
    .catPill{display:flex;align-items:center;gap:6px;padding:8px 12px;background:var(--white);border:1px solid var(--gray-200);border-radius:var(--radius-full);font-size:12px;font-weight:600;color:var(--gray-600);white-space:nowrap;flex-shrink:0}.catPill:active{background:var(--gray-100)}.catPill.active{background:var(--brand-500);color:var(--white);border-color:var(--brand-500)}.catPill .cIcon{font-size:14px}
    .productsGrid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}@media(min-width:480px){.productsGrid{gap:12px}}@media(min-width:640px){.productsGrid{grid-template-columns:repeat(3,1fr);gap:14px}}@media(min-width:768px){.productsGrid{grid-template-columns:repeat(4,1fr)}}@media(min-width:1024px){.productsGrid{grid-template-columns:repeat(5,1fr);gap:16px}}@media(min-width:1280px){.productsGrid{grid-template-columns:repeat(6,1fr)}}
    .product{background:var(--white);border-radius:var(--radius-xl);overflow:hidden;transition:all .2s;border:1px solid var(--gray-100);position:relative}.product:active{transform:scale(0.98)}@media(hover:hover){.product:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg);border-color:transparent}}
    .productImgWrap{position:relative;height:110px;background:var(--gray-50);display:flex;align-items:center;justify-content:center;padding:12px;overflow:hidden}@media(min-width:640px){.productImgWrap{height:130px;padding:16px}}.productImgWrap img{max-width:100%;max-height:100%;object-fit:contain}.productImgWrap .emoji{font-size:48px}@media(min-width:640px){.productImgWrap .emoji{font-size:56px}}
    .productBadge{position:absolute;top:8px;left:8px;padding:4px 8px;background:var(--rose);color:var(--white);font-size:10px;font-weight:700;border-radius:var(--radius-sm)}
    .productFav{position:absolute;top:8px;right:8px;width:32px;height:32px;background:var(--white);border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s;box-shadow:var(--shadow-sm);color:var(--gray-400)}.product:hover .productFav,.product:active .productFav{opacity:1}.productFav:active{color:var(--rose)}.productFav svg{width:16px;height:16px}
    .productInfo{padding:12px}@media(min-width:640px){.productInfo{padding:14px}}
    .productBrand{font-size:10px;font-weight:600;color:var(--gray-400);text-transform:uppercase;letter-spacing:.3px;margin-bottom:3px}
    .productName{font-size:13px;font-weight:600;color:var(--gray-900);line-height:1.3;height:34px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;margin-bottom:3px}@media(min-width:640px){.productName{font-size:14px;height:36px}}
    .productWeight{font-size:11px;color:var(--gray-400);margin-bottom:8px}
    .productPrices{display:flex;align-items:baseline;gap:6px;margin-bottom:10px}.productPrice{font-size:17px;font-weight:800;color:var(--gray-900);letter-spacing:-0.02em}@media(min-width:640px){.productPrice{font-size:19px}}.productPriceOld{font-size:12px;color:var(--gray-400);text-decoration:line-through}
    .productAdd{width:100%;padding:10px;background:var(--gray-100);color:var(--gray-700);font-size:12px;font-weight:700;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;gap:5px;min-height:40px}@media(min-width:640px){.productAdd{padding:12px;font-size:13px;min-height:44px}}.productAdd:active{background:var(--gray-900);color:var(--white)}@media(hover:hover){.productAdd:hover{background:var(--gray-900);color:var(--white)}}.productAdd.loading{pointer-events:none;background:var(--gray-200)}.productAdd.added{background:var(--brand-500);color:var(--white)}.productAdd svg{width:14px;height:14px}
    .empty{text-align:center;padding:60px 20px}.empty .icon{font-size:64px;margin-bottom:16px;opacity:.5}.empty h3{font-size:18px;font-weight:700;color:var(--gray-900);margin-bottom:8px}.empty p{font-size:14px;color:var(--gray-500);margin-bottom:24px}.empty a{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;background:var(--gray-900);color:var(--white);font-size:14px;font-weight:600;border-radius:var(--radius-lg)}
    .pagination{display:flex;justify-content:center;align-items:center;gap:6px;margin-top:32px;padding:16px 0}.pageBtn{min-width:36px;height:36px;display:flex;align-items:center;justify-content:center;background:var(--white);border:1px solid var(--gray-200);border-radius:var(--radius-md);font-size:13px;font-weight:600;color:var(--gray-600)}.pageBtn:active{background:var(--gray-100)}.pageBtn.active{background:var(--gray-900);color:var(--white);border-color:var(--gray-900)}.pageBtn:disabled{opacity:.5;pointer-events:none}
    .cartFloat{position:fixed;bottom:calc(70px + var(--safe-bottom));left:12px;right:12px;max-width:400px;margin:0 auto;background:var(--gray-900);border-radius:var(--radius-xl);padding:12px 16px;display:flex;justify-content:space-between;align-items:center;z-index:250;transform:translateY(120px);opacity:0;transition:all .3s var(--ease-bounce);box-shadow:var(--shadow-xl)}@media(min-width:768px){.cartFloat{bottom:24px}}.cartFloat.show{transform:translateY(0);opacity:1}.cartFloatLeft{display:flex;align-items:center;gap:12px}.cartFloatIcon{width:42px;height:42px;background:var(--brand-500);border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;position:relative}.cartFloatIcon svg{color:var(--white);width:20px;height:20px}.cartFloatIcon .count{position:absolute;top:-5px;right:-5px;min-width:20px;height:20px;background:var(--rose);color:var(--white);font-size:10px;font-weight:700;border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;border:2px solid var(--gray-900)}.cartFloatText{color:var(--white)}.cartFloatText .label{font-size:11px;color:var(--gray-400)}.cartFloatText .value{font-size:13px;font-weight:600}.cartFloatTotal{font-size:18px;font-weight:800;color:var(--brand-400)}
    .bottomNav{position:fixed;bottom:0;left:0;right:0;background:var(--white);border-top:1px solid var(--gray-100);padding:6px 0 calc(6px + var(--safe-bottom));display:flex;justify-content:space-around;z-index:300}@media(min-width:768px){.bottomNav{display:none}}.bottomNavItem{display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 12px;color:var(--gray-400);font-size:10px;font-weight:600;position:relative;min-width:56px}.bottomNavItem.active{color:var(--gray-900)}.bottomNavItem .bnIcon{width:22px;height:22px}.bottomNavItem .badge{position:absolute;top:2px;right:6px;min-width:16px;height:16px;background:var(--rose);color:var(--white);font-size:9px;font-weight:700;border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center}
    .toast{position:fixed;top:calc(70px + var(--safe-top));left:12px;right:12px;max-width:320px;margin:0 auto;background:var(--gray-900);color:var(--white);padding:12px 20px;border-radius:var(--radius-xl);font-size:14px;font-weight:600;text-align:center;z-index:500;opacity:0;pointer-events:none;transform:translateY(-16px);transition:all .3s var(--ease-bounce);box-shadow:var(--shadow-xl)}.toast.show{opacity:1;transform:translateY(0)}.toast.success{background:var(--brand-600)}.toast.error{background:var(--rose)}
    .spinner{width:16px;height:16px;border:2px solid var(--gray-300);border-top-color:var(--brand-500);border-radius:50%;animation:spin .6s linear infinite}
    </style>

<!-- HEADER PREMIUM v3.0 -->
<style>

/* ══════════════════════════════════════════════════════════════════════════════
   🎨 HEADER PREMIUM v3.0 - OneMundo Mercado
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
<link rel="stylesheet" href="/mercado/assets/css/mercado-premium.css">
<link rel="stylesheet" href="/mercado/assets/css/mobile-responsive-fixes.css">
</head>
<body>
<header class="header">
    <div class="headerInner">
        <a href="index.php" class="backBtn"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg></a>
        <div class="searchWrapper">
            <form action="categoria.php" method="GET" class="searchBox">
                <span class="sIcon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
                <input type="text" name="q" class="searchInput" value="<?= htmlspecialchars($busca) ?>" placeholder="Buscar produtos...">
                <button type="submit" class="searchBtn">Buscar</button>
            </form>
        </div>
        <div class="headerActions">
            <a href="carrinho.php" class="headerBtn cart" id="headerCart"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg><?php if ($cart_count > 0): ?><span class="badge" id="headerBadge"><?= $cart_count ?></span><?php endif; ?></a>
        </div>
    </div>
</header>

<main class="main">
    <div class="pageHeader">
        <div class="pageTitle">
            <span class="icon"><?= $page_icon ?></span>
            <h1><?= htmlspecialchars($page_title) ?><span class="count">(<?= $total_produtos ?>)</span></h1>
        </div>
        <div class="categories">
            <a href="categoria.php" class="catPill <?= !$categoria_slug && !$busca ? 'active' : '' ?>"><span class="cIcon">🛍️</span>Todos</a>
            <?php foreach (array_slice($categorias, 0, 10) as $cat): ?>
            <a href="categoria.php?cat=<?= urlencode($cat['slug']) ?>" class="catPill <?= $categoria_slug === $cat['slug'] ? 'active' : '' ?>"><span class="cIcon"><?= $cat['icon'] ?></span><?= htmlspecialchars($cat['name']) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="filters">
            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'relevancia'])) ?>" class="filterBtn <?= $ordenar === 'relevancia' ? 'active' : '' ?>">Relevância</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'menor_preco'])) ?>" class="filterBtn <?= $ordenar === 'menor_preco' ? 'active' : '' ?>">Menor preço</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'maior_preco'])) ?>" class="filterBtn <?= $ordenar === 'maior_preco' ? 'active' : '' ?>">Maior preço</a>
            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'promocao'])) ?>" class="filterBtn <?= $ordenar === 'promocao' ? 'active' : '' ?>">🔥 Promoções</a>
        </div>
    </div>
    
    <?php if (empty($produtos)): ?>
    <div class="empty">
        <div class="icon">🔍</div>
        <h3>Nenhum produto encontrado</h3>
        <p>Tente buscar por outro termo ou categoria</p>
        <a href="index.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>Voltar ao início</a>
    </div>
    <?php else: ?>
    <div class="productsGrid stagger">
        <?php $emojis=['🍌','🥛','🥖','🍗','🍚','🫘','🥤','🧴','🍬','☕','🥬','🥩']; foreach ($produtos as $i => $prod): ?>
        <div class="product">
            <div class="productImgWrap"><?php if ($prod['em_promocao']): ?><span class="productBadge">-<?= $prod['desconto'] ?>%</span><?php endif; ?><button class="productFav"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></button><?php if ($prod['image']): ?><img src="/image/<?= $prod['image'] ?>" alt=""><?php else: ?><span class="emoji"><?= $emojis[$i % count($emojis)] ?></span><?php endif; ?></div>
            <div class="productInfo"><?php if ($prod['brand']): ?><div class="productBrand"><?= htmlspecialchars($prod['brand']) ?></div><?php endif; ?><h3 class="productName"><?= htmlspecialchars($prod['name']) ?></h3><?php if ($prod['weight']): ?><div class="productWeight"><?= htmlspecialchars($prod['weight']) ?></div><?php endif; ?><div class="productPrices"><span class="productPrice"><?= formatMoney($prod['preco_final']) ?></span><?php if ($prod['em_promocao']): ?><span class="productPriceOld"><?= formatMoney($prod['price']) ?></span><?php endif; ?></div><button class="productAdd" onclick="addToCart(<?= $prod['product_id'] ?>, <?= $prod['partner_id'] ?>)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add</button></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($total_paginas > 1): ?>
    <div class="pagination">
        <?php if ($pagina > 1): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $pagina - 1])) ?>" class="pageBtn"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg></a><?php endif; ?>
        <?php for ($p = max(1, $pagina - 2); $p <= min($total_paginas, $pagina + 2); $p++): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>" class="pageBtn <?= $p === $pagina ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pagina < $total_paginas): ?><a href="?<?= http_build_query(array_merge($_GET, ['page' => $pagina + 1])) ?>" class="pageBtn"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg></a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</main>

<a href="carrinho.php" class="cartFloat <?= $cart_count > 0 ? 'show' : '' ?>" id="cartFloat">
    <div class="cartFloatLeft"><div class="cartFloatIcon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg><span class="count" id="cartFloatCount"><?= $cart_count ?></span></div><div class="cartFloatText"><div class="label">Ver carrinho</div><div class="value" id="cartFloatItems"><?= $cart_count ?> <?= $cart_count == 1 ? 'item' : 'itens' ?></div></div></div>
    <span class="cartFloatTotal" id="cartFloatTotal"><?= formatMoney($cart_total) ?></span>
</a>

<nav class="bottomNav">
    <a href="index.php" class="bottomNavItem"><svg class="bnIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg><span>Início</span></a>
    <a href="categoria.php?cat=ofertas" class="bottomNavItem active"><svg class="bnIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg><span>Ofertas</span></a>
    <a href="carrinho.php" class="bottomNavItem"><svg class="bnIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg><?php if ($cart_count > 0): ?><span class="badge"><?= $cart_count ?></span><?php endif; ?><span>Sacola</span></a>
    <a href="pedidos.php" class="bottomNavItem"><svg class="bnIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><span>Pedidos</span></a>
    <a href="/index.php?route=account/account" class="bottomNavItem"><svg class="bnIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><span>Conta</span></a>
</nav>

<div class="toast" id="toast"></div>

<script>
let cartCount=<?=$cart_count?>,cartTotal=<?=$cart_total?>;
function showToast(m,t='success'){const e=document.getElementById('toast');e.textContent=m;e.className='toast show '+t;setTimeout(()=>e.classList.remove('show'),2500)}
function addToCart(p,r){const b=event.currentTarget,o=b.innerHTML;b.classList.add('loading');b.innerHTML='<div class="spinner"></div>';fetch('api/cart.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'add',product_id:p,partner_id:r,quantity:1})}).then(r=>r.json()).then(d=>{if(d.success){cartCount=d.cart_count||cartCount+1;cartTotal=d.cart_total||cartTotal;updateCartUI();showToast('✓ Adicionado!');b.classList.remove('loading');b.classList.add('added');b.innerHTML='✓';setTimeout(()=>{b.classList.remove('added');b.innerHTML=o},1500)}else{showToast('Erro','error');b.classList.remove('loading');b.innerHTML=o}}).catch(()=>{showToast('Erro','error');b.classList.remove('loading');b.innerHTML=o})}
function updateCartUI(){const f=document.getElementById('cartFloat'),c=document.getElementById('cartFloatCount'),i=document.getElementById('cartFloatItems'),t=document.getElementById('cartFloatTotal');if(f)f.classList.toggle('show',cartCount>0);if(c)c.textContent=cartCount;if(i)i.textContent=cartCount+(cartCount==1?' item':' itens');if(t)t.textContent=formatMoney(cartTotal);let h=document.getElementById('headerBadge');const hc=document.getElementById('headerCart');if(h)h.textContent=cartCount;else if(cartCount>0&&hc){h=document.createElement('span');h.className='badge';h.id='headerBadge';h.textContent=cartCount;hc.appendChild(h)}}
function formatMoney(v){return'R$ '+parseFloat(v).toFixed(2).replace('.',',')}
</script>


<!-- ══════════════════════════════════════════════════════════════════════════════ -->
<!-- ONEMUNDO: Sistema de Acompanhamento de Pedidos -->
<!-- ══════════════════════════════════════════════════════════════════════════════ -->
<?php 
// Banner de status do pedido (aparece no topo quando tem pedido ativo)
if (file_exists(__DIR__ . '/components/order-banner.php')) {
    include __DIR__ . '/components/order-banner.php';
}

// Chat flutuante do pedido
if (file_exists(__DIR__ . '/components/order-chat.php')) {
    include __DIR__ . '/components/order-chat.php';
}
?>

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
<script src="/mercado/assets/js/mercado-app.js"></script>
</body>
</html>
