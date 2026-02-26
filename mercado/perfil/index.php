<?php
/**
 * OneMundo Mercado - Customer Profile
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Carregar config do OpenCart
$_oc_root = dirname(dirname(__DIR__));
if (file_exists($_oc_root . '/config.php')) {
    require_once($_oc_root . '/config.php');
}

// Conexão
try {
    $pdo = new PDO(
        "mysql:host=" . (defined('DB_HOSTNAME') ? DB_HOSTNAME : 'localhost') . ";dbname=" . (defined('DB_DATABASE') ? DB_DATABASE : 'love1') . ";charset=utf8mb4",
        defined('DB_USERNAME') ? DB_USERNAME : 'love1',
        defined('DB_PASSWORD') ? DB_PASSWORD : DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    die("Serviço temporariamente indisponível");
}

$prefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';

// Verificar login
session_regenerate_id(true);
$customer_id = $_SESSION['mercado_customer_id'] ?? $_SESSION['customer_id'] ?? 0;

if (!$customer_id || !isset($_SESSION['user_ip']) || $_SESSION['user_ip'] !== $_SERVER['REMOTE_ADDR']) {
    session_destroy();
    header('Location: /mercado/login/');
    exit;
}

// Buscar dados completos do cliente com endereços em uma query
$stmt = $pdo->prepare("
    SELECT c.*, cg.name as customer_group_name,
           GROUP_CONCAT(CONCAT(a.address_id,'|',a.address_1,'|',a.city,'|',a.postcode,'|',z.code,'|',co.name) SEPARATOR ';;') as addresses
    FROM {$prefix}customer c
    LEFT JOIN {$prefix}customer_group_description cg ON c.customer_group_id = cg.customer_group_id AND cg.language_id = 2
    LEFT JOIN {$prefix}address a ON c.customer_id = a.customer_id
    LEFT JOIN {$prefix}zone z ON a.zone_id = z.zone_id
    LEFT JOIN {$prefix}country co ON a.country_id = co.country_id
    WHERE c.customer_id = ?
    GROUP BY c.customer_id
");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch();

if (!$customer) {
    // Cliente não encontrado, fazer logout
    session_destroy();
    header('Location: /mercado/login/');
    exit;
}

// Buscar endereços
$stmt = $pdo->prepare("
    SELECT a.*, z.name as zone_name, z.code as zone_code, co.name as country_name
    FROM {$prefix}address a
    LEFT JOIN {$prefix}zone z ON a.zone_id = z.zone_id
    LEFT JOIN {$prefix}country co ON a.country_id = co.country_id
    WHERE a.customer_id = ?
    ORDER BY a.address_id = ? DESC, a.address_id DESC
");
$stmt->execute([$customer_id, $customer['address_id']]);
$addresses = $stmt->fetchAll();

$primary_address = null;
foreach ($addresses as $addr) {
    if ($addr['address_id'] == $customer['address_id']) {
        $primary_address = $addr;
        break;
    }
}
if (!$primary_address && !empty($addresses)) {
    $primary_address = $addresses[0];
}

// Detectar mercado próximo pelo CEP
$mercado_proximo = null;
$mercado_disponivel = false;
$cep_cliente = '';

if ($primary_address && !empty($primary_address['postcode'])) {
    $cep_cliente = preg_replace('/\D/', '', $primary_address['postcode']);
    $prefixo_cep = substr($cep_cliente, 0, 5);
    
    try {
        // Buscar parceiro pelo CEP
        $stmt = $pdo->prepare("
            SELECT p.*, 
                   (SELECT COUNT(*) FROM om_market_products mp WHERE mp.partner_id = p.partner_id AND mp.status = '1') as total_produtos
            FROM om_market_partners p 
            WHERE p.status = '1' 
            AND p.cep_inicio <= ? 
            AND p.cep_fim >= ?
            ORDER BY p.partner_id 
            LIMIT 1
        ");
        $stmt->execute([$prefixo_cep, $prefixo_cep]);
        $mercado_proximo = $stmt->fetch();
        
        if ($mercado_proximo) {
            $mercado_disponivel = true;
            $_SESSION['market_partner_id'] = $mercado_proximo['partner_id'];
        }
    } catch (Exception $e) {
        // Tabela pode não existir
    }
}

// Buscar membership
$membership = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM om_membership WHERE customer_id = ? AND status = '1'");
    $stmt->execute([$customer_id]);
    $membership = $stmt->fetch();
} catch (Exception $e) {}

// Config membership
$membership_level = 'bronze';
$membership_icon = '🥉';
$membership_color = '#cd7f32';
$membership_benefits = [];

if ($membership) {
    $levels = [
        'bronze' => ['icon' => '🥉', 'color' => '#cd7f32', 'benefits' => ['5% em produtos selecionados']],
        'silver' => ['icon' => '🥈', 'color' => '#C0C0C0', 'benefits' => ['3% desconto', 'Frete grátis +R$80']],
        'prata' => ['icon' => '🥈', 'color' => '#C0C0C0', 'benefits' => ['3% desconto', 'Frete grátis +R$80']],
        'gold' => ['icon' => '🥇', 'color' => '#FFD700', 'benefits' => ['5% desconto', 'Frete grátis +R$50', 'Ofertas exclusivas']],
        'ouro' => ['icon' => '🥇', 'color' => '#FFD700', 'benefits' => ['5% desconto', 'Frete grátis +R$50', 'Ofertas exclusivas']],
        'platinum' => ['icon' => '💎', 'color' => '#E5E4E2', 'benefits' => ['8% desconto', 'Frete GRÁTIS', 'Acesso antecipado']],
        'platina' => ['icon' => '💎', 'color' => '#E5E4E2', 'benefits' => ['8% desconto', 'Frete GRÁTIS', 'Acesso antecipado']],
        'diamond' => ['icon' => '👑', 'color' => '#b9f2ff', 'benefits' => ['10% desconto', 'Frete GRÁTIS', 'Suporte VIP', 'Cashback 2%']],
        'diamante' => ['icon' => '👑', 'color' => '#b9f2ff', 'benefits' => ['10% desconto', 'Frete GRÁTIS', 'Suporte VIP', 'Cashback 2%']]
    ];
    
    $level = strtolower($membership['level'] ?? 'bronze');
    if (isset($levels[$level])) {
        $membership_level = $level;
        $membership_icon = $levels[$level]['icon'];
        $membership_color = $levels[$level]['color'];
        $membership_benefits = $levels[$level]['benefits'];
    }
}

// Buscar pedidos do mercado
$pedidos = [];
try {
    $stmt = $pdo->prepare("
        SELECT o.*, os.name as status_name
        FROM {$prefix}order o
        LEFT JOIN {$prefix}order_status os ON o.order_status_id = os.order_status_id AND os.language_id = 2
        WHERE o.customer_id = ? AND o.order_status_id > 0
        ORDER BY o.date_added DESC
        LIMIT 10
    ");
    $stmt->execute([$customer_id]);
    $pedidos = $stmt->fetchAll();
} catch (Exception $e) {}

// Estatísticas
$stats = [
    'total_pedidos' => 0,
    'total_gasto' => 0,
    'economia' => 0
];

$cache_key = 'customer_stats_' . $customer_id;
if (!($stats_data = apcu_fetch($cache_key))) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as valor
            FROM {$prefix}order
            WHERE customer_id = ? AND order_status_id > 0
        ");
        $stmt->execute([$customer_id]);
        $row = $stmt->fetch();
        $stats_data = ['total' => $row['total'], 'valor' => $row['valor']];
        apcu_store($cache_key, $stats_data, 300); // 5 min cache
    } catch (Exception $e) {
        $stats_data = ['total' => 0, 'valor' => 0];
    }
}
$stats['total_pedidos'] = $stats_data['total'];
$stats['total_gasto'] = $stats_data['valor'];

// Saudação
$hora = (int)date('H');
if ($hora >= 5 && $hora < 12) {
    $saudacao = 'Bom dia';
    $emoji = '☀️';
} elseif ($hora >= 12 && $hora < 18) {
    $saudacao = 'Boa tarde';
    $emoji = '🌤️';
} else {
    $saudacao = 'Boa noite';
    $emoji = '🌙';
}

// Logout
if (isset($_POST['logout']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    session_destroy();
    setcookie('OCSESSID', '', time() - 3600, '/', '', true, true);
    header('Location: /mercado/');
    exit;
}
?>
<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Meu Perfil - Mercado OneMundo</title>
    <link rel="icon" href="/image/catalog/cart.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/mercado/assets/css/profile.css">
        
    <style>
        :root {
            --primary: var(--primary);
            --primary-dark: var(--primary-dark);
            --primary-light: #d1fae5;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --red: var(--error);
            --orange: #f97316;
            --yellow: #eab308;
            --blue: var(--info);
            --purple: #8b5cf6;
        }
        
        html { scroll-behavior: smooth; -webkit-tap-highlight-color: transparent; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            min-height: 100vh;
            color: var(--gray-900);
            -webkit-font-smoothing: antialiased;
        }
        
        /* Header */
        .header {
            background: white;
            border-bottom: 1px solid var(--gray-200);
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-inner {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .logo-text {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--gray-900);
        }
        
        .logo-text span { color: var(--primary); }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .header-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .header-btn.primary {
            background: var(--primary);
            color: white;
        }
        
        .header-btn.primary:hover {
            background: var(--primary-dark);
        }
        
        .header-btn.outline {
            background: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
        }
        
        .header-btn.outline:hover {
            border-color: var(--gray-400);
            background: var(--gray-50);
        }
        
        /* Main */
        .main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 32px 24px;
        }
        
        /* Profile Header */
        .profile-header {
            background: white;
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .profile-top {
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 24px;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            color: white;
            text-transform: uppercase;
            flex-shrink: 0;
        }
        
        .profile-info h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--gray-900);
            margin-bottom: 4px;
        }
        
        .profile-greeting {
            font-size: 14px;
            color: var(--gray-500);
            margin-bottom: 8px;
        }
        
        .profile-member {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 700;
        }
        
        .profile-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            padding-top: 24px;
            border-top: 1px solid var(--gray-100);
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--gray-900);
        }
        
        .stat-label {
            font-size: 13px;
            color: var(--gray-500);
        }
        
        /* Mercado Status Card */
        .mercado-status {
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 24px;
        }
        
        .mercado-status.disponivel {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 2px solid #86efac;
        }
        
        .mercado-status.indisponivel {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 2px solid #fcd34d;
        }
        
        .mercado-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .mercado-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        
        .mercado-status.disponivel .mercado-icon {
            background: var(--primary);
        }
        
        .mercado-status.indisponivel .mercado-icon {
            background: var(--orange);
        }
        
        .mercado-info h2 {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 4px;
        }
        
        .mercado-status.disponivel .mercado-info h2 {
            color: #166534;
        }
        
        .mercado-status.indisponivel .mercado-info h2 {
            color: #92400e;
        }
        
        .mercado-info p {
            font-size: 14px;
        }
        
        .mercado-status.disponivel .mercado-info p {
            color: #15803d;
        }
        
        .mercado-status.indisponivel .mercado-info p {
            color: #a16207;
        }
        
        .mercado-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(0,0,0,0.1);
        }
        
        .mercado-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .mercado-status.disponivel .mercado-detail {
            color: #166534;
        }
        
        .mercado-status.indisponivel .mercado-detail {
            color: #92400e;
        }
        
        .mercado-cta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 24px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            margin-top: 16px;
            transition: all 0.3s;
        }
        
        .mercado-status.disponivel .mercado-cta {
            background: var(--primary);
            color: white;
        }
        
        .mercado-status.disponivel .mercado-cta:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .mercado-status.indisponivel .mercado-cta {
            background: var(--orange);
            color: white;
        }
        
        /* Indisponível message */
        .indisponivel-form {
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 12px;
        }
        
        .indisponivel-form h3 {
            font-size: 15px;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 12px;
        }
        
        .indisponivel-form p {
            font-size: 13px;
            color: var(--gray-600);
            margin-bottom: 16px;
        }
        
        .notify-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: var(--gray-800);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .notify-btn:hover {
            background: var(--gray-900);
        }
        
        /* Grid Layout */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--gray-900);
        }
        
        .card-title-icon {
            font-size: 24px;
        }
        
        .card-link {
            font-size: 14px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        
        .card-link:hover {
            text-decoration: underline;
        }
        
        /* Address Card */
        .address-item {
            padding: 16px;
            background: var(--gray-50);
            border-radius: 12px;
            margin-bottom: 12px;
            position: relative;
        }
        
        .address-item:last-child {
            margin-bottom: 0;
        }
        
        .address-item.primary {
            background: var(--primary-light);
            border: 2px solid var(--primary);
        }
        
        .address-badge {
            display: inline-block;
            padding: 4px 10px;
            background: var(--primary);
            color: white;
            font-size: 11px;
            font-weight: 700;
            border-radius: 50px;
            margin-bottom: 8px;
        }
        
        .address-text {
            font-size: 14px;
            color: var(--gray-700);
            line-height: 1.5;
        }
        
        .address-cep {
            font-size: 13px;
            color: var(--gray-500);
            margin-top: 8px;
        }
        
        /* Order Item */
        .order-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 0;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .order-info {
            flex: 1;
        }
        
        .order-number {
            font-size: 14px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
        }
        
        .order-date {
            font-size: 13px;
            color: var(--gray-500);
        }
        
        .order-status {
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 700;
        }
        
        .order-status.pendente {
            background: #fef3c7;
            color: #92400e;
        }
        
        .order-status.processando {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .order-status.enviado {
            background: #e0e7ff;
            color: #3730a3;
        }
        
        .order-status.entregue {
            background: #dcfce7;
            color: #166534;
        }
        
        .order-status.cancelado {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .order-total {
            font-size: 15px;
            font-weight: 700;
            color: var(--gray-900);
            margin-left: 16px;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 32px;
            color: var(--gray-400);
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 12px;
            opacity: 0.5;
        }
        
        .empty-state-text {
            font-size: 14px;
        }
        
        /* Benefits */
        .benefits-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .benefit-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: var(--gray-50);
            border-radius: 10px;
            font-size: 14px;
            color: var(--gray-700);
        }
        
        .benefit-icon {
            font-size: 18px;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 24px;
        }
        
        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 20px;
            background: var(--gray-50);
            border-radius: 16px;
            text-decoration: none;
            color: var(--gray-700);
            transition: all 0.3s;
        }
        
        .quick-action:hover {
            background: var(--primary-light);
            color: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .quick-action-icon {
            font-size: 28px;
        }
        
        .quick-action-text {
            font-size: 13px;
            font-weight: 600;
        }
        
        /* Responsive */
        @media (max-width: 900px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 640px) {
            .header {
                padding: 12px 16px;
            }
            
            .main {
                padding: 20px 16px;
            }
            
            .profile-header {
                padding: 24px 20px;
            }
            
            .profile-top {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-stats {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .stat-value {
                font-size: 1.25rem;
            }
            
            .header-actions {
                display: none;
            }
            
            .mercado-header {
                flex-direction: column;
                text-align: center;
            }
        }
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
    <!-- Header -->
    <header class="header">
        <div class="header-inner">
            <a href="/mercado/" class="logo">
                <div class="logo-icon">🛒</div>
                <div class="logo-text">One<span>Mundo</span></div>
            </a>
            <div class="header-actions">
                <a href="/mercado/" class="header-btn primary">
                    🛒 Ir às Compras
                </a>
                <a href="?logout=1" class="header-btn outline">
                    Sair
                </a>
            </div>
        </div>
    </header>
    
    <!-- Main -->
    <main class="main">
        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-top">
                <div class="profile-avatar">
                    <?= mb_substr($customer['firstname'], 0, 1) ?>
                </div>
                <div class="profile-info">
                    <p class="profile-greeting"><?= $emoji ?> <?= $saudacao ?>!</p>
                    <h1><?= htmlspecialchars($customer['firstname'] . ' ' . $customer['lastname']) ?></h1>
                    <div class="profile-member" style="background: <?= $membership_color ?>20; color: <?= $membership_color ?>;">
                        <?= $membership_icon ?> Membro <?= ucfirst($membership_level) ?>
                    </div>
                </div>
            </div>
            
            <div class="profile-stats">
                <div class="stat-item">
                    <div class="stat-value"><?= $stats['total_pedidos'] ?></div>
                    <div class="stat-label">Pedidos</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">R$ <?= number_format($stats['total_gasto'], 0, ',', '.') ?></div>
                    <div class="stat-label">Total gasto</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= count($addresses) ?></div>
                    <div class="stat-label">Endereços</div>
                </div>
            </div>
        </div>
        
        <!-- Mercado Status -->
        <?php if ($mercado_disponivel): ?>
        <div class="mercado-status disponivel">
            <div class="mercado-header">
                <div class="mercado-icon">✅</div>
                <div class="mercado-info">
                    <h2>🎉 Temos entrega na sua região!</h2>
                    <p>O Mercado OneMundo atende o seu endereço. Faça suas compras e receba em casa!</p>
                </div>
            </div>
            
            <div class="mercado-details">
                <div class="mercado-detail">
                    <span>🏪</span>
                    <span><?= htmlspecialchars($mercado_proximo['name'] ?? 'Mercado Parceiro', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="mercado-detail">
                    <span>📍</span>
                    <span>CEP: <?= substr($cep_cliente, 0, 5) ?>-<?= substr($cep_cliente, 5, 3) ?></span>
                </div>
                <div class="mercado-detail">
                    <span>📦</span>
                    <span><?= number_format($mercado_proximo['total_produtos'] ?? 0) ?> produtos disponíveis</span>
                </div>
                <div class="mercado-detail">
                    <span>🚚</span>
                    <span>Entrega em até 60 min</span>
                </div>
            </div>
            
            <a href="/mercado/" class="mercado-cta">
                🛒 Começar a Comprar
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg>
            </a>
        </div>
        <?php else: ?>
        <div class="mercado-status indisponivel">
            <div class="mercado-header">
                <div class="mercado-icon">🚧</div>
                <div class="mercado-info">
                    <h2>Ainda não atendemos sua região</h2>
                    <p>Estamos expandindo rapidamente! Em breve o Mercado OneMundo chegará no seu endereço.</p>
                </div>
            </div>
            
            <?php if ($primary_address): ?>
            <div class="mercado-details">
                <div class="mercado-detail">
                    <span>📍</span>
                    <span><?= htmlspecialchars($primary_address['city'] ?? '') ?>, <?= htmlspecialchars($primary_address['zone_code'] ?? '') ?></span>
                </div>
                <div class="mercado-detail">
                    <span>🏠</span>
                    <span>CEP: <?= !empty($cep_cliente) ? substr($cep_cliente, 0, 5) . '-' . substr($cep_cliente, 5, 3) : 'Não informado' ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="indisponivel-form">
                <h3>📬 Quer ser avisado quando chegarmos?</h3>
                <p>Cadastre-se na lista de espera e seja um dos primeiros a saber quando o Mercado OneMundo chegar na sua região!</p>
                <button class="notify-btn" onclick="cadastrarNotificacao()">
                    🔔 Me Avise Quando Chegar
                </button>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Endereços -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-title-icon">📍</span>
                        Meus Endereços
                    </h2>
                    <a href="/index.php?route=account/address" class="card-link">Gerenciar</a>
                </div>
                
                <?php if (!empty($addresses)): ?>
                    <?php foreach (array_slice($addresses, 0, 3) as $addr): ?>
                    <div class="address-item <?= $addr['address_id'] == $customer['address_id'] ? 'primary' : '' ?>">
                        <?php if ($addr['address_id'] == $customer['address_id']): ?>
                        <span class="address-badge">Principal</span>
                        <?php endif; ?>
                        <div class="address-text">
                            <?= htmlspecialchars($addr['address_1']) ?>
                            <?php if ($addr['address_2']): ?>, <?= htmlspecialchars($addr['address_2']) ?><?php endif; ?>
                            <br>
                            <?= htmlspecialchars($addr['city']) ?> - <?= htmlspecialchars($addr['zone_code'] ?? $addr['zone_name'] ?? '') ?>
                        </div>
                        <div class="address-cep">CEP: <?= htmlspecialchars($addr['postcode']) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📍</div>
                        <div class="empty-state-text">Nenhum endereço cadastrado</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Últimos Pedidos -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-title-icon">📦</span>
                        Últimos Pedidos
                    </h2>
                    <a href="/index.php?route=account/order" class="card-link">Ver todos</a>
                </div>
                
                <?php if (!empty($pedidos)): ?>
                    <?php foreach (array_slice($pedidos, 0, 5) as $pedido): 
                        $status_class = 'pendente';
                        $status_id = $pedido['order_status_id'];
                        if ($status_id >= 15) $status_class = 'entregue';
                        elseif ($status_id >= 3) $status_class = 'enviado';
                        elseif ($status_id >= 2) $status_class = 'processando';
                        elseif ($status_id == 0 || $status_id == 7) $status_class = 'cancelado';
                    ?>
                    <div class="order-item">
                        <div class="order-info">
                            <div class="order-number">#<?= $pedido['order_id'] ?></div>
                            <div class="order-date"><?= date('d/m/Y', strtotime($pedido['date_added'])) ?></div>
                        </div>
                        <span class="order-status <?= $status_class ?>"><?= htmlspecialchars($pedido['status_name'] ?: 'Pendente') ?></span>
                        <div class="order-total">R$ <?= number_format($pedido['total'], 2, ',', '.') ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📦</div>
                        <div class="empty-state-text">Nenhum pedido ainda</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Benefícios do Membership -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-title-icon"><?= $membership_icon ?></span>
                        Seus Benefícios
                    </h2>
                </div>
                
                <div class="benefits-list">
                    <?php foreach ($membership_benefits as $benefit): ?>
                    <div class="benefit-item">
                        <span class="benefit-icon">✅</span>
                        <span><?= htmlspecialchars($benefit) ?></span>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($membership_benefits)): ?>
                    <div class="benefit-item">
                        <span class="benefit-icon">🎁</span>
                        <span>Acumule pontos a cada compra</span>
                    </div>
                    <div class="benefit-item">
                        <span class="benefit-icon">⭐</span>
                        <span>Suba de nível e ganhe mais descontos</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Ações Rápidas -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <span class="card-title-icon">⚡</span>
                        Ações Rápidas
                    </h2>
                </div>
                
                <div class="quick-actions">
                    <a href="/mercado/" class="quick-action">
                        <span class="quick-action-icon">🛒</span>
                        <span class="quick-action-text">Ir às Compras</span>
                    </a>
                    <a href="/index.php?route=account/order" class="quick-action">
                        <span class="quick-action-icon">📦</span>
                        <span class="quick-action-text">Meus Pedidos</span>
                    </a>
                    <a href="/index.php?route=account/address" class="quick-action">
                        <span class="quick-action-icon">📍</span>
                        <span class="quick-action-text">Endereços</span>
                    </a>
                    <a href="/index.php?route=account/edit" class="quick-action">
                        <span class="quick-action-icon">👤</span>
                        <span class="quick-action-text">Editar Perfil</span>
                    </a>
                </div>
            </div>
        </div>
    </main>
    
    <script>
        function cadastrarNotificacao() {
            // Mostrar feedback
            alert('✅ Você será notificado quando chegarmos na sua região!\n\nObrigado pelo interesse no Mercado OneMundo!');
            
            // Aqui poderia salvar no banco
            // fetch('/mercado/api/notificar-regiao.php', { ... })
        }
    </script>

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
