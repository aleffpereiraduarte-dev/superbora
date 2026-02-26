<?php
if (session_status() === PHP_SESSION_NONE) session_start();
try {
    $pdo = new PDO("mysql:host=".DB_HOSTNAME.";dbname=".DB_DATABASE.";charset=utf8mb4", DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) { error_log('Database connection failed: ' . $e->getMessage()); die('Serviço temporariamente indisponível'); }

// Buscar customer do OpenCart
$customer_id = 0; $customer = null; $is_logged = false;
$ocsessid = filter_input(INPUT_COOKIE, 'OCSESSID', FILTER_SANITIZE_STRING) ?? ''; if (!preg_match('/^[a-zA-Z0-9]{26,40}$/', $ocsessid)) { $ocsessid = ''; }
if ($ocsessid) {
    $stmt = $pdo->prepare("SELECT data FROM oc_session WHERE session_id = ?");
    $stmt->execute([$ocsessid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row["data"]) {
        $sd = json_decode($row["data"], true); if (json_last_error() !== JSON_ERROR_NONE) { $sd = []; }
        $customer_id = (int)($sd["customer_id"] ?? 0);
    }
}
if ($customer_id > 0) {
    $stmt = $pdo->prepare("SELECT c.*, a.address_1, a.address_2, a.city, a.postcode, z.name as zone_name FROM oc_customer c LEFT JOIN oc_address a ON c.customer_id = a.customer_id LEFT JOIN oc_zone z ON a.zone_id = z.zone_id WHERE c.customer_id = ? ORDER BY a.address_id DESC LIMIT 1");
    $stmt->execute([$customer_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $customer = $result;
        $is_logged = true;
        if ($result['address_1']) {
            $endereco = $result;
        }
    }
}

// Estatísticas do mercado
$stats = ["pedidos" => 0, "total_gasto" => 0, "favoritos" => 0, "cupons" => 0];
if ($customer_id > 0) {
    $cache_key = 'user_stats_' . $customer_id;
    $cached_stats = apcu_fetch($cache_key);
    if ($cached_stats === false) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as t, COALESCE(SUM(total),0) as v FROM om_market_orders WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats["pedidos"] = (int)($r["t"] ?? 0);
            $stats["total_gasto"] = (float)($r["v"] ?? 0);
            apcu_store($cache_key, $stats, 300); // 5 min cache
        } catch (Exception $e) {}
    } else {
        $stats = array_merge($stats, $cached_stats);
    }
}

$cart = $_SESSION["market_cart"] ?? [];
$cartCount = 0; foreach ($cart as $item) $cartCount += (int)($item["qty"] ?? 1);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Minha Conta - Mercado OneMundo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/account.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Inter,sans-serif;background:var(--g100);padding-bottom:100px}
.profile-header{background:linear-gradient(135deg,#065f46 0%,var(--primary-dark) 50%,var(--primary) 100%);padding:20px 16px 90px;position:relative}
.profile-header::after{content:"";position:absolute;bottom:0;left:0;right:0;height:40px;background:var(--g100);border-radius:24px 24px 0 0}
.header-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.btn-back{width:40px;height:40px;border:none;background:rgba(255,255,255,.15);border-radius:var(--radius-md);cursor:pointer;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(10px)}
.btn-back svg{width:22px;height:22px;color:var(--white)}
.header-title{font-size:18px;font-weight:700;color:var(--white)}
.btn-settings{width:40px;height:40px;border:none;background:rgba(255,255,255,.15);border-radius:var(--radius-md);cursor:pointer;display:flex;align-items:center;justify-content:center;text-decoration:none}
.btn-settings svg{width:22px;height:22px;color:var(--white)}
.profile-card{background:var(--white);margin:-70px 16px 0;border-radius:var(--radius-xl);padding:24px;box-shadow:0 4px 24px rgba(0,0,0,.08);position:relative;z-index:10}
.profile-main{display:flex;align-items:center;gap:16px;margin-bottom:20px}
.profile-avatar{width:72px;height:72px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;color:var(--white);font-size:26px;font-weight:800;flex-shrink:0;box-shadow:0 4px 16px rgba(16,185,129,.3)}
.profile-info{flex:1;min-width:0}
.profile-name{font-size:20px;font-weight:800;color:var(--g900);margin-bottom:4px}
.profile-email{font-size:13px;color:var(--g500);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.profile-phone{font-size:13px;color:var(--g500);margin-top:2px;display:flex;align-items:center;gap:6px}
.profile-badge{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#fef3c7,#fde68a);padding:4px 12px;border-radius:var(--radius-xl);font-size:11px;font-weight:700;color:#92400e;margin-top:8px}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:20px}
.stat-item{text-align:center;padding:14px 8px;background:var(--g50);border-radius:var(--radius-lg)}
.stat-value{font-size:20px;font-weight:800;color:var(--primary)}
.stat-label{font-size:11px;color:var(--g500);margin-top:4px}
.login-card{background:var(--white);margin:-70px 16px 0;border-radius:var(--radius-xl);padding:32px 24px;box-shadow:0 4px 24px rgba(0,0,0,.08);position:relative;z-index:10;text-align:center}
.login-icon{width:88px;height:88px;background:var(--g100);border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
.login-icon svg{width:44px;height:44px;color:var(--g400)}
.login-title{font-size:22px;font-weight:800;color:var(--g900);margin-bottom:8px}
.login-text{font-size:14px;color:var(--g500);margin-bottom:24px;line-height:1.5}
.btn-login{display:block;width:100%;padding:16px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:var(--white);text-decoration:none;font-size:16px;font-weight:700;border-radius:var(--radius-lg);margin-bottom:12px;box-shadow:0 4px 16px rgba(16,185,129,.3)}
.btn-register{display:block;width:100%;padding:16px;background:var(--white);color:var(--primary);text-decoration:none;font-size:16px;font-weight:700;border-radius:var(--radius-lg);border:2px solid var(--g200)}
.address-card{background:var(--white);margin:16px;border-radius:var(--radius-lg);padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.address-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.address-title{font-size:14px;font-weight:700;color:var(--g800);display:flex;align-items:center;gap:8px}
.address-edit{font-size:13px;font-weight:600;color:var(--primary);text-decoration:none}
.address-text{font-size:14px;color:var(--g600);line-height:1.5}
.menu{background:var(--white);margin:16px;border-radius:var(--radius-lg);overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.menu-title{font-size:12px;font-weight:700;color:var(--g400);text-transform:uppercase;letter-spacing:.05em;padding:16px 16px 8px}
.menu-item{display:flex;align-items:center;gap:14px;padding:14px 16px;text-decoration:none;border-bottom:1px solid var(--g100);transition:background .15s}
.menu-item:last-child{border-bottom:none}
.menu-item:hover{background:var(--g50)}
.menu-icon{width:44px;height:44px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.menu-info{flex:1;min-width:0}
.menu-name{font-size:15px;font-weight:600;color:var(--g800)}
.menu-desc{font-size:12px;color:var(--g500);margin-top:2px}
.menu-badge{background:var(--red);color:var(--white);font-size:11px;font-weight:700;padding:2px 8px;border-radius:var(--radius-md)}
.menu-arrow svg{width:20px;height:20px;color:var(--g400)}
.btn-logout{display:flex;align-items:center;justify-content:center;gap:10px;width:calc(100% - 32px);margin:24px 16px;padding:16px;background:var(--white);color:var(--red);font-size:15px;font-weight:700;border:none;border-radius:var(--radius-lg);cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.btn-logout:hover{background:#fee2e2}
.benefits{margin:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px}
.benefit{background:var(--white);border-radius:var(--radius-lg);padding:20px 16px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.benefit-icon{font-size:32px;margin-bottom:10px}
.benefit-title{font-size:14px;font-weight:700;color:var(--g800);margin-bottom:4px}
.benefit-desc{font-size:12px;color:var(--g500)}
.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:var(--white);border-top:1px solid var(--g200);padding:8px 0;padding-bottom:calc(8px + env(safe-area-inset-bottom));z-index:1000;display:flex;justify-content:space-around}
.nav-item{display:flex;flex-direction:column;align-items:center;gap:4px;text-decoration:none;color:var(--g400);font-size:11px;font-weight:600}
.nav-item.active{color:var(--primary)}
.nav-item svg{width:24px;height:24px}
</style>

<style>

/* 🎨 OneMundo Design System v2.0 - Injected Styles */
:root {
    --primary: var(--primary);
    --primary-dark: var(--primary-dark);
    --primary-light: var(--primary-light);
    --primary-50: #ecfdf5;
    --primary-100: #d1fae5;
    --primary-glow: rgba(16, 185, 129, 0.15);
    --accent: #8b5cf6;
    --success: #22c55e;
    --warning: var(--warning);
    --error: var(--error);
    --info: var(--info);
    --white: #ffffff;
    --gray-50: #f8fafc;
    --gray-100: #f1f5f9;
    --gray-200: #e2e8f0;
    --gray-300: #cbd5e1;
    --gray-400: #94a3b8;
    --gray-500: #64748b;
    --gray-600: #475569;
    --gray-700: #334155;
    --gray-800: #1e293b;
    --gray-900: #0f172a;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --radius-xl: 20px;
    --radius-full: 9999px;
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
    --transition-fast: 150ms ease;
    --transition-base: 200ms ease;
}

/* Melhorias globais */
body {
    font-family: "Inter", -apple-system, BlinkMacSystemFont, sans-serif !important;
    -webkit-font-smoothing: antialiased;
}

/* Headers melhorados */
.header, [class*="header"] {
    background: rgba(255,255,255,0.9) !important;
    backdrop-filter: blur(20px) saturate(180%);
    -webkit-backdrop-filter: blur(20px) saturate(180%);
    border-bottom: 1px solid rgba(0,0,0,0.05) !important;
    box-shadow: none !important;
}

/* Botões melhorados */
button, .btn, [class*="btn-"] {
    transition: all var(--transition-base) !important;
    border-radius: var(--radius-md) !important;
}

button:hover, .btn:hover, [class*="btn-"]:hover {
    transform: translateY(-2px);
}

/* Botões primários */
.btn-primary, .btn-checkout, [class*="btn-green"], [class*="btn-success"] {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark)) !important;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35) !important;
    border: none !important;
}

.btn-primary:hover, .btn-checkout:hover {
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4) !important;
}

/* Cards melhorados */
.card, [class*="card"], .item, [class*="-item"] {
    border-radius: var(--radius-lg) !important;
    box-shadow: var(--shadow-md) !important;
    transition: all var(--transition-base) !important;
    border: none !important;
}

.card:hover, [class*="card"]:hover {
    box-shadow: var(--shadow-lg) !important;
    transform: translateY(-4px);
}

/* Inputs melhorados */
input, textarea, select {
    border-radius: var(--radius-md) !important;
    border: 2px solid var(--gray-200) !important;
    transition: all var(--transition-base) !important;
}

input:focus, textarea:focus, select:focus {
    border-color: var(--primary) !important;
    box-shadow: 0 0 0 4px var(--primary-glow) !important;
    outline: none !important;
}

/* Badges melhorados */
.badge, [class*="badge"] {
    border-radius: var(--radius-full) !important;
    font-weight: 700 !important;
    padding: 6px 12px !important;
}

/* Bottom bar melhorado */
.bottom-bar, [class*="bottom-bar"], [class*="bottombar"] {
    background: var(--white) !important;
    border-top: 1px solid var(--gray-200) !important;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.08) !important;
    border-radius: 24px 24px 0 0 !important;
}

/* Preços */
[class*="price"], [class*="preco"], [class*="valor"] {
    font-weight: 800 !important;
}

/* Links */
a {
    transition: color var(--transition-fast) !important;
}

/* Imagens de produto */
.item-img img, .product-img img, [class*="produto"] img {
    border-radius: var(--radius-md) !important;
    transition: transform var(--transition-base) !important;
}

.item-img:hover img, .product-img:hover img {
    transform: scale(1.05);
}

/* Animações suaves */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.animate-fade { animation: fadeIn 0.3s ease forwards; }
.animate-up { animation: slideUp 0.4s ease forwards; }

/* Scrollbar bonita */
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: var(--gray-100); }
::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: var(--gray-400); }

/* Selection */
::selection {
    background: var(--primary-100);
    color: var(--primary-dark);
}

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
</head>
<body>
<div class="profile-header">
<div class="header-top">
<button class="btn-back" data-href="/mercado/" onclick="handleRedirect(this)"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></button>
<span class="header-title">Minha Conta</span>
<?php if($is_logged): ?><a href="/index.php?route=account/edit" class="btn-settings"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg></a><?php else: ?><div style="width:40px"></div><?php endif; ?>
</div>
</div>

<?php if ($is_logged): ?>
<div class="profile-card">
<div class="profile-main">
<div class="profile-avatar"><?=strtoupper(mb_substr($customer["firstname"],0,1))?><?=strtoupper(mb_substr($customer["lastname"],0,1))?></div>
<div class="profile-info">
<div class="profile-name"><?=htmlspecialchars($customer["firstname"]." ".$customer["lastname"])?></div>
<div class="profile-email"><?=htmlspecialchars($customer["email"])?></div>
<?php if(!empty($customer["telephone"])): ?><div class="profile-phone"><svg class="phone-icon" width="16" height="16" viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg> <?=htmlspecialchars($customer["telephone"])?></div><?php endif; ?>
<div class="profile-badge">⭐ Cliente desde <?=date("M/Y", strtotime($customer["date_added"]))?></div>
</div>
</div>
<div class="stats-grid">
<div class="stat-item"><div class="stat-value"><?=$stats["pedidos"]?></div><div class="stat-label">Pedidos</div></div>
<div class="stat-item"><div class="stat-value"><?=$stats["favoritos"]?></div><div class="stat-label">Favoritos</div></div>
<div class="stat-item"><div class="stat-value">R$<?=number_format($stats["total_gasto"],0,",",".")?></div><div class="stat-label">Gasto</div></div>
</div>
</div>

<?php if($endereco): ?>
<div class="address-card">
<div class="address-header">
<span class="address-title">📍 Endereço de Entrega</span>
<a href="/index.php?route=account/address" class="address-edit">Alterar</a>
</div>
<div class="address-text">
<?=htmlspecialchars($endereco["address_1"])?><?php if(!empty($endereco["address_2"])): ?>, <?=htmlspecialchars($endereco["address_2"])?><?php endif; ?><br>
<?=htmlspecialchars($endereco["city"])?> - <?=htmlspecialchars($endereco["zone_name"] ?? "")?><br>
CEP: <?=htmlspecialchars($endereco["postcode"])?>
</div>
</div>
<?php endif; ?>

<div class="menu">
<div class="menu-title">Meu Mercado</div>
<a href="/mercado/pedidos/" class="menu-item">
<div class="menu-icon" style="background:var(--primary)15">📦</div>
<div class="menu-info"><div class="menu-name">Meus Pedidos</div><div class="menu-desc">Acompanhe suas compras</div></div>
<?php if($stats["pedidos"]>0): ?><span class="menu-badge"><?=$stats["pedidos"]?></span><?php endif; ?>
<div class="menu-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div>
</a>
<a href="/index.php?route=account/address" class="menu-item">
<div class="menu-icon" style="background:var(--info)15">📍</div>
<div class="menu-info"><div class="menu-name">Meus Endereços</div><div class="menu-desc">Gerencie locais de entrega</div></div>
<div class="menu-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div>
</a>
<a href="/mercado/favoritos/" class="menu-item">
<div class="menu-icon" style="background:var(--error)15">❤️</div>
<div class="menu-info"><div class="menu-name">Meus Favoritos</div><div class="menu-desc">Produtos que você ama</div></div>
<div class="menu-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div>
</a>
<a href="/mercado/cupons/" class="menu-item">
<div class="menu-icon" style="background:var(--warning)15">🎟️</div>
<div class="menu-info"><div class="menu-name">Meus Cupons</div><div class="menu-desc">Descontos disponíveis</div></div>
<div class="menu-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div>
</a>
</div>

<div class="menu">
<div class="menu-title">Configurações</div>
<a href="/index.php?route=account/edit" class="menu-item">
<div class="menu-icon" style="background:#64748b15">⚙️</div>
<div class="menu-info"><div class="menu-name">Editar Perfil</div><div class="menu-desc">Dados pessoais</div></div>
<div class="menu-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div>
</a>
<a href="/index.php?route=account/password" class="menu-item">
<div class="menu-icon" style="background:#64748b15">🔒</div>
<div class="menu-info"><div class="menu-name">Alterar Senha</div><div class="menu-desc">Segurança da conta</div></div>
<div class="menu-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div>
</a>
<a href="/index.php?route=information/contact" class="menu-item">
<div class="menu-icon" style="background:#64748b15">❓</div>
<div class="menu-info"><div class="menu-name">Ajuda</div><div class="menu-desc">Central de atendimento</div></div>
<div class="menu-arrow"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></div>
</a>
</div>

<button class="btn-logout" onclick="if(confirm('Deseja sair da sua conta?'))location.href='/index.php?route=account/logout'">🚪 Sair da conta</button>

<?php else: ?>
<div class="login-card">
<div class="login-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></div>
<h2 class="login-title">Entre na sua conta</h2>
<p class="login-text">Faça login para acessar seus pedidos, favoritos, cupons e muito mais!</p>
<a href="/index.php?route=account/login" class="btn-login">Entrar</a>
<a href="/index.php?route=account/register" class="btn-register">Criar conta grátis</a>
</div>

<div class="benefits">
<div class="benefit"><div class="benefit-icon">📦</div><div class="benefit-title">Acompanhe Pedidos</div><div class="benefit-desc">Status em tempo real</div></div>
<div class="benefit"><div class="benefit-icon">❤️</div><div class="benefit-title">Salve Favoritos</div><div class="benefit-desc">Produtos que você ama</div></div>
<div class="benefit"><div class="benefit-icon">🎟️</div><div class="benefit-title">Cupons Exclusivos</div><div class="benefit-desc">Descontos só pra você</div></div>
<div class="benefit"><div class="benefit-icon">⚡</div><div class="benefit-title">Compra Rápida</div><div class="benefit-desc">Checkout agilizado</div></div>
</div>
<?php endif; ?>

<nav class="bottom-nav">
<a href="/mercado/" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg><span>Início</span></a>
<a href="/mercado/ofertas/" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg><span>Ofertas</span></a>
<a href="/mercado/categorias/" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg><span>Categorias</span></a>
<a href="/mercado/carrinho/" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg><span>Carrinho</span></a>
<a href="/mercado/conta/" class="nav-item active"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg><span>Conta</span></a>
</nav>

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
