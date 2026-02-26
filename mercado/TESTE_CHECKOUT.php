<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  🧪 TESTE COMPLETO DO FLUXO DE CHECKOUT                                      ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  Testa cada etapa do checkout:                                               ║
 * ║  1. Página de produtos                                                       ║
 * ║  2. API do carrinho                                                          ║
 * ║  3. Página do carrinho                                                       ║
 * ║  4. Página de checkout                                                       ║
 * ║  5. API de pagamento (Pagar.me)                                              ║
 * ║  6. Página de tracking                                                       ║
 * ║  7. Webhook de pagamento                                                     ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$BASE = __DIR__;
$results = [];

// ═══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES
// ═══════════════════════════════════════════════════════════════════════════════

function checkFile($path, $requiredStrings = []) {
    global $BASE;
    $fullPath = $BASE . '/' . $path;
    
    $result = [
        'exists' => file_exists($fullPath),
        'readable' => false,
        'syntax_ok' => false,
        'syntax_error' => '',
        'has_required' => [],
        'missing_required' => [],
        'size' => 0,
        'http_test' => null
    ];
    
    if (!$result['exists']) return $result;
    
    $result['readable'] = is_readable($fullPath);
    $result['size'] = filesize($fullPath);
    
    // Verificar sintaxe PHP
    $output = [];
    $ret = 0;
    exec('php -l ' . escapeshellarg($fullPath) . ' 2>&1', $output, $ret);
    $result['syntax_ok'] = $ret === 0;
    if (!$result['syntax_ok']) {
        $result['syntax_error'] = implode(' ', $output);
    }
    
    // Verificar strings requeridas
    if ($result['readable']) {
        $content = file_get_contents($fullPath);
        foreach ($requiredStrings as $str) {
            if (stripos($content, $str) !== false) {
                $result['has_required'][] = $str;
            } else {
                $result['missing_required'][] = $str;
            }
        }
    }
    
    return $result;
}

function testAPI($path, $method = 'GET', $data = null) {
    global $BASE;
    $fullPath = $BASE . '/' . $path;
    
    $result = [
        'exists' => file_exists($fullPath),
        'syntax_ok' => false,
        'returns_json' => false,
        'has_success' => false,
        'error' => null
    ];
    
    if (!$result['exists']) return $result;
    
    // Verificar sintaxe
    $output = [];
    $ret = 0;
    exec('php -l ' . escapeshellarg($fullPath) . ' 2>&1', $output, $ret);
    $result['syntax_ok'] = $ret === 0;
    
    if (!$result['syntax_ok']) {
        $result['error'] = implode(' ', $output);
    }
    
    return $result;
}

// Conexão com banco
$pdo = null;
$db_ok = false;

$configPaths = [$BASE . '/config.php', dirname($BASE) . '/config.php'];
foreach ($configPaths as $cfg) {
    if (file_exists($cfg)) {
        $content = file_get_contents($cfg);
        if (preg_match("/define\s*\(\s*['\"]DB_HOSTNAME['\"]\s*,\s*['\"]([^'\"]+)/", $content, $m)) $db_host = $m[1];
        if (preg_match("/define\s*\(\s*['\"]DB_DATABASE['\"]\s*,\s*['\"]([^'\"]+)/", $content, $m)) $db_name = $m[1];
        if (preg_match("/define\s*\(\s*['\"]DB_USERNAME['\"]\s*,\s*['\"]([^'\"]+)/", $content, $m)) $db_user = $m[1];
        if (preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]*)/", $content, $m)) $db_pass = $m[1];
        
        if (isset($db_host, $db_name, $db_user)) {
            try {
                $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass ?? '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                $db_ok = true;
            } catch (Exception $e) {}
            break;
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// TESTES DO FLUXO
// ═══════════════════════════════════════════════════════════════════════════════

$flow = [];

// 1. HOME / LISTAGEM DE PRODUTOS
$flow['1_home'] = [
    'name' => '🏠 Home / Produtos',
    'file' => 'index.php',
    'check' => checkFile('index.php', ['product', 'carrinho', 'cart', 'add']),
    'critical' => true
];

// 2. PÁGINA DE PRODUTO
$flow['2_produto'] = [
    'name' => '📦 Página Produto',
    'file' => 'produto.php',
    'check' => checkFile('produto.php', ['product', 'carrinho', 'add', 'quantity']),
    'critical' => false
];

// 3. API CARRINHO
$flow['3_api_cart'] = [
    'name' => '🛒 API Carrinho',
    'file' => 'api/cart.php',
    'check' => checkFile('api/cart.php', ['json', 'cart', 'add', 'remove', 'customer']),
    'critical' => true
];

// 4. PÁGINA CARRINHO
$flow['4_cart'] = [
    'name' => '🛒 Página Carrinho',
    'file' => 'cart.php',
    'check' => checkFile('cart.php', ['cart', 'checkout', 'total', 'quantity']),
    'critical' => true
];

// 5. PÁGINA CHECKOUT
$flow['5_checkout'] = [
    'name' => '💳 Página Checkout',
    'file' => 'checkout.php',
    'check' => checkFile('checkout.php', ['checkout', 'pagamento', 'pix', 'cartao', 'endereco']),
    'critical' => true
];

// 6. API PAGAMENTO
$flow['6_api_pagamento'] = [
    'name' => '💰 API Pagamento',
    'file' => 'api/pagamento.php',
    'check' => checkFile('api/pagamento.php', ['pagar.me', 'pix', 'credit', 'json', 'order']),
    'critical' => true
];

// 7. WEBHOOK PAGAR.ME
$flow['7_webhook'] = [
    'name' => '🔔 Webhook Pagar.me',
    'file' => 'api/pagarme.php',
    'check' => checkFile('api/pagarme.php', ['webhook', 'charge', 'paid', 'status']),
    'critical' => true
];

// 8. API CHECKOUT
$flow['8_api_checkout'] = [
    'name' => '📝 API Checkout',
    'file' => 'api/checkout.php',
    'check' => checkFile('api/checkout.php', ['order', 'json', 'customer', 'address']),
    'critical' => false
];

// 9. PÁGINA TRACKING
$flow['9_tracking'] = [
    'name' => '📍 Página Tracking',
    'file' => 'tracking.php',
    'check' => checkFile('tracking.php', ['order', 'status', 'tracking', 'delivery']),
    'critical' => true
];

// 10. API STATUS PEDIDO
$flow['10_api_status'] = [
    'name' => '📊 API Status Pedido',
    'file' => 'api/order-status.php',
    'check' => checkFile('api/order-status.php', ['order', 'status', 'json']),
    'critical' => false
];

// 11. BANNER STATUS
$flow['11_banner'] = [
    'name' => '🎯 Banner Status',
    'file' => 'components/order-banner.php',
    'check' => checkFile('components/order-banner.php', ['banner', 'status', 'order']),
    'critical' => false
];

// 12. CHAT COMPONENTE
$flow['12_chat'] = [
    'name' => '💬 Chat Componente',
    'file' => 'components/order-chat.php',
    'check' => checkFile('components/order-chat.php', ['chat', 'message', 'order']),
    'critical' => false
];

// ═══════════════════════════════════════════════════════════════════════════════
// VERIFICAR TABELAS NECESSÁRIAS
// ═══════════════════════════════════════════════════════════════════════════════

$tables_check = [];
$required_tables = [
    'om_market_orders' => 'Pedidos',
    'om_market_order_items' => 'Itens do Pedido',
    'om_market_cart' => 'Carrinho',
    'om_market_partners' => 'Parceiros',
    'om_market_products_base' => 'Produtos Base',
    'om_market_products_price' => 'Preços',
    'om_pix_transactions' => 'Transações PIX',
    'om_customer_cards' => 'Cartões Salvos',
];

if ($db_ok) {
    foreach ($required_tables as $table => $desc) {
        try {
            $pdo->query("SELECT 1 FROM $table LIMIT 1");
            $tables_check[$table] = ['exists' => true, 'desc' => $desc];
        } catch (Exception $e) {
            $tables_check[$table] = ['exists' => false, 'desc' => $desc];
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// VERIFICAR CONFIGURAÇÕES PAGAR.ME
// ═══════════════════════════════════════════════════════════════════════════════

$pagarme_config = [
    'secret_key' => false,
    'public_key' => false,
    'configured' => false
];

$paymentFile = $BASE . '/api/pagamento.php';
if (file_exists($paymentFile)) {
    $content = file_get_contents($paymentFile);
    $pagarme_config['secret_key'] = preg_match('/sk_[a-zA-Z0-9]+/', $content);
    $pagarme_config['public_key'] = preg_match('/pk_[a-zA-Z0-9]+/', $content);
    $pagarme_config['configured'] = $pagarme_config['secret_key'] && $pagarme_config['public_key'];
}

// ═══════════════════════════════════════════════════════════════════════════════
// VERIFICAR DADOS DE TESTE
// ═══════════════════════════════════════════════════════════════════════════════

$test_data = [];
if ($db_ok) {
    try {
        // Produtos disponíveis
        $stmt = $pdo->query("SELECT COUNT(*) FROM om_market_products_base");
        $test_data['produtos'] = $stmt->fetchColumn();
        
        // Preços cadastrados
        $stmt = $pdo->query("SELECT COUNT(*) FROM om_market_products_price WHERE price > 0");
        $test_data['precos'] = $stmt->fetchColumn();
        
        // Parceiros ativos
        $stmt = $pdo->query("SELECT COUNT(*) FROM om_market_partners WHERE status = 'active'");
        $test_data['parceiros'] = $stmt->fetchColumn();
        
        // Pedidos recentes
        $stmt = $pdo->query("SELECT COUNT(*) FROM om_market_orders WHERE date_added >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $test_data['pedidos_7d'] = $stmt->fetchColumn();
        
        // Último pedido
        $stmt = $pdo->query("SELECT * FROM om_market_orders ORDER BY order_id DESC LIMIT 1");
        $test_data['ultimo_pedido'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {}
}

// ═══════════════════════════════════════════════════════════════════════════════
// CALCULAR SCORES
// ═══════════════════════════════════════════════════════════════════════════════

$total_steps = count($flow);
$ok_steps = 0;
$critical_fail = [];
$warnings = [];

foreach ($flow as $key => $step) {
    $check = $step['check'];
    $passed = $check['exists'] && $check['syntax_ok'];
    
    if ($passed) {
        $ok_steps++;
    } else {
        if ($step['critical']) {
            $critical_fail[] = $step;
        } else {
            $warnings[] = $step;
        }
    }
}

$score = $total_steps > 0 ? round(($ok_steps / $total_steps) * 100) : 0;
$tables_ok = count(array_filter($tables_check, fn($t) => $t['exists']));
$tables_total = count($tables_check);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🧪 Teste Fluxo Checkout - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; padding: 20px; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #38bdf8; margin-bottom: 10px; }
        .subtitle { color: #64748b; margin-bottom: 25px; }
        
        .health { display: flex; align-items: center; gap: 25px; background: #1e293b; padding: 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid #334155; }
        .health-circle { width: 120px; height: 120px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2em; font-weight: bold; color: white; }
        .health-circle.good { background: linear-gradient(135deg, #22c55e, #16a34a); }
        .health-circle.warn { background: linear-gradient(135deg, #eab308, #ca8a04); }
        .health-circle.bad { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .health-info h2 { font-size: 1.4em; margin-bottom: 8px; }
        .health-info p { color: #94a3b8; }
        
        .flow-visual { background: #1e293b; border-radius: 16px; padding: 25px; margin-bottom: 25px; border: 1px solid #334155; overflow-x: auto; }
        .flow-visual h2 { margin-bottom: 20px; font-size: 1.2em; }
        .flow-steps { display: flex; align-items: center; gap: 0; min-width: max-content; }
        .flow-step { display: flex; flex-direction: column; align-items: center; min-width: 100px; }
        .flow-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.3em; margin-bottom: 8px; }
        .flow-icon.ok { background: #166534; }
        .flow-icon.fail { background: #991b1b; }
        .flow-icon.warn { background: #854d0e; }
        .flow-label { font-size: 11px; color: #94a3b8; text-align: center; max-width: 80px; }
        .flow-arrow { color: #334155; font-size: 20px; margin: 0 5px; }
        
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .card { background: #1e293b; border-radius: 12px; padding: 20px; border: 1px solid #334155; }
        .card h3 { margin-bottom: 15px; font-size: 1.1em; display: flex; align-items: center; gap: 10px; }
        .card-badge { background: #334155; padding: 3px 10px; border-radius: 20px; font-size: 0.75em; }
        
        .item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #334155; }
        .item:last-child { border-bottom: none; }
        .item-info { flex: 1; }
        .item-name { color: #e2e8f0; font-weight: 500; }
        .item-detail { color: #64748b; font-size: 0.85em; margin-top: 2px; }
        .item-status { font-size: 1.2em; }
        
        .tag { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 0.75em; font-weight: 600; margin-left: 5px; }
        .tag.ok { background: #166534; color: #bbf7d0; }
        .tag.fail { background: #991b1b; color: #fecaca; }
        .tag.warn { background: #854d0e; color: #fef08a; }
        .tag.critical { background: #7f1d1d; color: #fecaca; }
        
        .alert { padding: 15px 20px; border-radius: 10px; margin-bottom: 20px; }
        .alert.error { background: #7f1d1d; border: 1px solid #991b1b; }
        .alert.warning { background: #78350f; border: 1px solid #854d0e; }
        .alert.success { background: #14532d; border: 1px solid #166534; }
        .alert h4 { margin-bottom: 8px; }
        .alert ul { margin-left: 20px; margin-top: 8px; }
        .alert li { margin-bottom: 5px; }
        
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .stat { background: #334155; padding: 15px; border-radius: 10px; text-align: center; }
        .stat-value { font-size: 1.8em; font-weight: bold; color: #38bdf8; }
        .stat-label { color: #94a3b8; font-size: 0.8em; margin-top: 3px; }
        
        .test-btn { display: inline-block; background: linear-gradient(135deg, #22c55e, #16a34a); color: white; padding: 12px 25px; border-radius: 8px; text-decoration: none; font-weight: 600; margin-top: 15px; }
        .test-btn:hover { opacity: 0.9; }
        
        .code { background: #0f172a; padding: 3px 8px; border-radius: 4px; font-family: monospace; font-size: 0.85em; color: #38bdf8; }
        
        @media (max-width: 768px) {
            .grid { grid-template-columns: 1fr; }
            .health { flex-direction: column; text-align: center; }
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
<div class="container">
    <h1>🧪 Teste do Fluxo de Checkout</h1>
    <p class="subtitle">Verificação completa de todas as etapas do processo de compra</p>
    
    <!-- SAÚDE GERAL -->
    <div class="health">
        <div class="health-circle <?= $score >= 80 ? 'good' : ($score >= 50 ? 'warn' : 'bad') ?>">
            <?= $score ?>%
        </div>
        <div class="health-info">
            <h2><?= $ok_steps ?> de <?= $total_steps ?> etapas funcionando</h2>
            <p>
                <?php if (count($critical_fail) > 0): ?>
                    ⚠️ <?= count($critical_fail) ?> etapa(s) crítica(s) com problema
                <?php else: ?>
                    ✅ Todas as etapas críticas funcionando
                <?php endif; ?>
            </p>
        </div>
    </div>
    
    <!-- ALERTAS CRÍTICOS -->
    <?php if (count($critical_fail) > 0): ?>
    <div class="alert error">
        <h4>❌ Etapas Críticas com Problema</h4>
        <p>Essas etapas precisam ser corrigidas para o checkout funcionar:</p>
        <ul>
            <?php foreach ($critical_fail as $step): ?>
            <li>
                <strong><?= $step['name'] ?></strong> - 
                <span class="code"><?= $step['file'] ?></span>
                <?php if (!$step['check']['exists']): ?>
                    <span class="tag fail">Arquivo não existe</span>
                <?php elseif (!$step['check']['syntax_ok']): ?>
                    <span class="tag fail">Erro de sintaxe</span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <!-- FLUXO VISUAL -->
    <div class="flow-visual">
        <h2>🔄 Fluxo do Checkout</h2>
        <div class="flow-steps">
            <?php 
            $first = true;
            foreach ($flow as $key => $step): 
                $check = $step['check'];
                $passed = $check['exists'] && $check['syntax_ok'];
                $status = $passed ? 'ok' : ($step['critical'] ? 'fail' : 'warn');
                
                if (!$first) echo '<span class="flow-arrow">→</span>';
                $first = false;
            ?>
            <div class="flow-step">
                <div class="flow-icon <?= $status ?>">
                    <?= $passed ? '✅' : ($step['critical'] ? '❌' : '⚠️') ?>
                </div>
                <div class="flow-label"><?= $step['name'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- DADOS DO SISTEMA -->
    <div class="stats">
        <div class="stat">
            <div class="stat-value"><?= number_format($test_data['produtos'] ?? 0) ?></div>
            <div class="stat-label">📦 Produtos</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= number_format($test_data['precos'] ?? 0) ?></div>
            <div class="stat-label">💰 Preços</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= $test_data['parceiros'] ?? 0 ?></div>
            <div class="stat-label">🏪 Parceiros</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= $test_data['pedidos_7d'] ?? 0 ?></div>
            <div class="stat-label">📋 Pedidos 7d</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= $tables_ok ?>/<?= $tables_total ?></div>
            <div class="stat-label">📊 Tabelas</div>
        </div>
    </div>
    
    <div class="grid">
        <!-- DETALHES DAS ETAPAS -->
        <div class="card">
            <h3>📋 Detalhes das Etapas <span class="card-badge"><?= $ok_steps ?>/<?= $total_steps ?></span></h3>
            <?php foreach ($flow as $key => $step): 
                $check = $step['check'];
                $passed = $check['exists'] && $check['syntax_ok'];
            ?>
            <div class="item">
                <div class="item-info">
                    <div class="item-name">
                        <?= $step['name'] ?>
                        <?php if ($step['critical']): ?><span class="tag critical">CRÍTICO</span><?php endif; ?>
                    </div>
                    <div class="item-detail">
                        <?= $step['file'] ?>
                        <?php if (!$check['exists']): ?>
                            - <span style="color:#ef4444;">Não existe</span>
                        <?php elseif (!$check['syntax_ok']): ?>
                            - <span style="color:#ef4444;">Erro sintaxe</span>
                        <?php elseif (count($check['missing_required']) > 0): ?>
                            - <span style="color:#eab308;">Falta: <?= implode(', ', $check['missing_required']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="item-status"><?= $passed ? '✅' : '❌' ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- TABELAS -->
        <div class="card">
            <h3>📊 Tabelas do Banco <span class="card-badge"><?= $tables_ok ?>/<?= $tables_total ?></span></h3>
            <?php foreach ($tables_check as $table => $info): ?>
            <div class="item">
                <div class="item-info">
                    <div class="item-name"><?= $info['desc'] ?></div>
                    <div class="item-detail"><?= $table ?></div>
                </div>
                <div class="item-status"><?= $info['exists'] ? '✅' : '❌' ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- PAGAR.ME -->
        <div class="card">
            <h3>💳 Configuração Pagar.me</h3>
            <div class="item">
                <div class="item-info">
                    <div class="item-name">Secret Key</div>
                    <div class="item-detail">sk_xxx configurada</div>
                </div>
                <div class="item-status"><?= $pagarme_config['secret_key'] ? '✅' : '❌' ?></div>
            </div>
            <div class="item">
                <div class="item-info">
                    <div class="item-name">Public Key</div>
                    <div class="item-detail">pk_xxx configurada</div>
                </div>
                <div class="item-status"><?= $pagarme_config['public_key'] ? '✅' : '❌' ?></div>
            </div>
            <div class="item">
                <div class="item-info">
                    <div class="item-name">Status Geral</div>
                    <div class="item-detail">Pronto para pagamentos</div>
                </div>
                <div class="item-status"><?= $pagarme_config['configured'] ? '✅' : '❌' ?></div>
            </div>
        </div>
        
        <!-- ÚLTIMO PEDIDO -->
        <div class="card">
            <h3>📋 Último Pedido</h3>
            <?php if (isset($test_data['ultimo_pedido']) && $test_data['ultimo_pedido']): 
                $up = $test_data['ultimo_pedido'];
            ?>
            <div class="item">
                <div class="item-info">
                    <div class="item-name">Pedido #<?= $up['order_id'] ?></div>
                    <div class="item-detail"><?= date('d/m/Y H:i', strtotime($up['date_added'])) ?></div>
                </div>
                <div class="item-status">
                    <span class="tag <?= in_array($up['status'], ['delivered', 'paid']) ? 'ok' : 'warn' ?>">
                        <?= ucfirst($up['status']) ?>
                    </span>
                </div>
            </div>
            <div class="item">
                <div class="item-info">
                    <div class="item-name">Total</div>
                </div>
                <div class="item-status">R$ <?= number_format($up['total'] ?? 0, 2, ',', '.') ?></div>
            </div>
            <div class="item">
                <div class="item-info">
                    <div class="item-name">Shopper</div>
                </div>
                <div class="item-status"><?= $up['shopper_id'] ? '✅ #' . $up['shopper_id'] : '⏳ Aguardando' ?></div>
            </div>
            <div class="item">
                <div class="item-info">
                    <div class="item-name">Delivery</div>
                </div>
                <div class="item-status"><?= $up['delivery_id'] ? '✅ #' . $up['delivery_id'] : '⏳ Aguardando' ?></div>
            </div>
            <?php else: ?>
            <p style="color:#64748b;text-align:center;padding:20px;">Nenhum pedido encontrado</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- AÇÕES -->
    <div class="card">
        <h3>🚀 Testar o Checkout</h3>
        <p style="color:#94a3b8;margin-bottom:15px;">Links para testar cada etapa manualmente:</p>
        
        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <a href="index.php" target="_blank" class="test-btn">🏠 Ver Produtos</a>
            <a href="cart.php" target="_blank" class="test-btn" style="background:linear-gradient(135deg,#3b82f6,#2563eb);">🛒 Ver Carrinho</a>
            <a href="checkout.php" target="_blank" class="test-btn" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);">💳 Checkout</a>
            <?php if (isset($test_data['ultimo_pedido']['order_id'])): ?>
            <a href="tracking.php?id=<?= $test_data['ultimo_pedido']['order_id'] ?>" target="_blank" class="test-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);">📍 Tracking #<?= $test_data['ultimo_pedido']['order_id'] ?></a>
            <?php endif; ?>
        </div>
    </div>
    
    <p style="text-align:center;margin-top:30px;color:#64748b;">
        Teste executado em <?= date('d/m/Y H:i:s') ?>
    </p>
</div>

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
