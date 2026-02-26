<?php
require_once __DIR__ . '/config/database.php';
/**
 * 🌟 MINHA CONTA - ONEMUNDO MERCADO
 * VERSÃO DEFINITIVA - WORLD CLASS DESIGN
 * Inspirado em: Apple, Nubank, Uber, iFood, Rappi, Instacart
 */

session_start();

if (!isset($_SESSION['customer_id']) || $_SESSION['customer_id'] <= 0) {
    header('Location: mercado-login.php');
    exit;
}

$db_host = '147.93.12.236';
$db_name = 'love1';
$db_user = 'root';
// $db_pass loaded from central config

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão");
}

$customer_id = $_SESSION['customer_id'];
$toast_msg = '';
$toast_type = '';

// ═══════════════════════════════════════════════════════════════════════════
// PROCESSAR AÇÕES
// ═══════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao === 'atualizar_dados') {
        $nome = trim($_POST['firstname'] ?? '');
        $sobrenome = trim($_POST['lastname'] ?? '');
        $telefone = trim($_POST['telephone'] ?? '');
        
        if ($nome && $sobrenome) {
            try {
                $stmt = $pdo->prepare("UPDATE oc_customer SET firstname = ?, lastname = ?, telephone = ? WHERE customer_id = ?");
                $stmt->execute([$nome, $sobrenome, $telefone, $customer_id]);
                $_SESSION['customer_name'] = $nome;
                $toast_msg = 'Perfil atualizado com sucesso!';
                $toast_type = 'success';
            } catch (Exception $e) {
                $toast_msg = 'Erro ao atualizar dados';
                $toast_type = 'error';
            }
        }
    }
    
    if ($acao === 'trocar_senha') {
        $senha_atual = $_POST['senha_atual'] ?? '';
        $senha_nova = $_POST['senha_nova'] ?? '';
        $senha_confirma = $_POST['senha_confirma'] ?? '';
        
        if ($senha_atual && $senha_nova && $senha_confirma) {
            $stmt = $pdo->prepare("SELECT password, salt FROM oc_customer WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $customer_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $senha_valida = false;
            if (strlen($customer_data['password']) > 40) {
                $senha_valida = password_verify($senha_atual, $customer_data['password']);
            } else {
                $salt = $customer_data['salt'] ?? '';
                $hash_calculado = sha1($salt . sha1($salt . sha1($senha_atual)));
                $senha_valida = ($hash_calculado === $customer_data['password']);
            }
            
            if (!$senha_valida) {
                $toast_msg = 'Senha atual incorreta';
                $toast_type = 'error';
            } elseif ($senha_nova !== $senha_confirma) {
                $toast_msg = 'As senhas não coincidem';
                $toast_type = 'error';
            } elseif (strlen($senha_nova) < 6) {
                $toast_msg = 'Senha deve ter 6+ caracteres';
                $toast_type = 'error';
            } else {
                $novo_hash = password_hash($senha_nova, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE oc_customer SET password = ?, salt = '' WHERE customer_id = ?");
                $stmt->execute([$novo_hash, $customer_id]);
                $toast_msg = 'Senha alterada! Segurança atualizada para bcrypt.';
                $toast_type = 'success';
            }
        }
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// BUSCAR DADOS
// ═══════════════════════════════════════════════════════════════════════════

$stmt = $pdo->prepare("
    SELECT c.*, cgd.name as group_name
    FROM oc_customer c
    LEFT JOIN oc_customer_group cg ON c.customer_group_id = cg.customer_group_id
    LEFT JOIN oc_customer_group_description cgd ON cg.customer_group_id = cgd.customer_group_id AND cgd.language_id = 1
    WHERE c.customer_id = ?
");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    session_destroy();
    header('Location: mercado-login.php');
    exit;
}

// Endereços
$stmt = $pdo->prepare("
    SELECT a.*, z.name as zone_name, z.code as zone_code, c.name as country_name
    FROM oc_address a
    LEFT JOIN oc_zone z ON a.zone_id = z.zone_id
    LEFT JOIN oc_country c ON a.country_id = c.country_id
    WHERE a.customer_id = ?
    ORDER BY a.address_id = ? DESC, a.address_id DESC
");
$stmt->execute([$customer_id, $customer['address_id']]);
$enderecos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pedidos DO MERCADO com produtos e imagens
$stmt = $pdo->prepare("
    SELECT o.*, os.name as status_name,
           (SELECT COUNT(*) FROM oc_order_product op WHERE op.order_id = o.order_id) as total_produtos,
           (SELECT GROUP_CONCAT(
               CONCAT(op.name, ' (', op.quantity, 'x)') 
               SEPARATOR '|') 
            FROM oc_order_product op 
            WHERE op.order_id = o.order_id 
            LIMIT 5) as produtos_lista,
           (SELECT op.name FROM oc_order_product op WHERE op.order_id = o.order_id LIMIT 1) as primeiro_produto
    FROM oc_order o
    LEFT JOIN oc_order_status os ON o.order_status_id = os.order_status_id AND os.language_id = 1
    WHERE o.customer_id = ?
    AND (o.store_id = 0 OR o.comment LIKE '%mercado%' OR o.invoice_prefix LIKE '%MERC%')
    ORDER BY o.order_id DESC
    LIMIT 100
");
$stmt->execute([$customer_id]);
$pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_pedidos,
        COALESCE(SUM(total), 0) as total_gasto,
        COALESCE(AVG(total), 0) as ticket_medio,
        MAX(date_added) as ultima_compra,
        (SELECT COUNT(DISTINCT DATE(date_added)) FROM oc_order WHERE customer_id = ? AND order_status_id > 0) as dias_compra,
        (SELECT SUM(op.quantity) FROM oc_order o2 JOIN oc_order_product op ON o2.order_id = op.order_id WHERE o2.customer_id = ? AND o2.order_status_id > 0) as total_itens
    FROM oc_order
    WHERE customer_id = ? 
    AND order_status_id > 0
    AND (store_id = 0 OR comment LIKE '%mercado%' OR invoice_prefix LIKE '%MERC%')
");
$stmt->execute([$customer_id, $customer_id, $customer_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Pedidos por status
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN order_status_id = 5 THEN 1 ELSE 0 END) as concluidos,
        SUM(CASE WHEN order_status_id IN (1,2,3) THEN 1 ELSE 0 END) as em_andamento,
        SUM(CASE WHEN order_status_id = 7 THEN 1 ELSE 0 END) as cancelados
    FROM oc_order
    WHERE customer_id = ?
    AND (store_id = 0 OR comment LIKE '%mercado%')
");
$stmt->execute([$customer_id]);
$stats_status = $stmt->fetch(PDO::FETCH_ASSOC);

// Membership
$membership_config = [
    'bronze' => [
        'name' => 'Bronze', 
        'icon' => '🥉', 
        'color' => '#CD7F32',
        'gradient' => 'linear-gradient(135deg, #CD7F32 0%, #A85A1A 100%)',
        'next_value' => 500,
        'discount' => '5%',
        'benefits' => ['Cashback 1%', 'Frete grátis em R$ 150+', 'Suporte prioritário']
    ],
    'silver' => [
        'name' => 'Prata', 
        'icon' => '🥈', 
        'color' => '#C0C0C0',
        'gradient' => 'linear-gradient(135deg, #C0C0C0 0%, #A8A8A8 100%)',
        'next_value' => 1500,
        'discount' => '8%',
        'benefits' => ['Cashback 2%', 'Frete grátis em R$ 100+', 'Ofertas exclusivas', 'Aniversariante do mês']
    ],
    'gold' => [
        'name' => 'Ouro', 
        'icon' => '🥇', 
        'color' => '#FFD700',
        'gradient' => 'linear-gradient(135deg, #FFD700 0%, #FFA500 100%)',
        'next_value' => 3000,
        'discount' => '12%',
        'benefits' => ['Cashback 3%', 'Frete GRÁTIS', 'Personal Shopper', 'Acesso antecipado', 'Brindes mensais']
    ],
    'platinum' => [
        'name' => 'Platina', 
        'icon' => '💎', 
        'color' => '#E5E4E2',
        'gradient' => 'linear-gradient(135deg, #E5E4E2 0%, #B9F2FF 100%)',
        'next_value' => 5000,
        'discount' => '15%',
        'benefits' => ['Cashback 4%', 'Frete SEMPRE grátis', 'Suporte VIP 24/7', 'Eventos exclusivos', 'Degustações', 'Presentes especiais']
    ],
    'diamond' => [
        'name' => 'Diamante', 
        'icon' => '👑', 
        'color' => '#B9F2FF',
        'gradient' => 'linear-gradient(135deg, #00D9FF 0%, #0EA5E9 100%)',
        'next_value' => 99999,
        'discount' => '20%',
        'benefits' => ['Cashback 5%', 'Frete SEMPRE grátis', 'Concierge 24/7', 'Produtos exclusivos', 'Beta features', 'Eventos VIP', 'Viagens premiadas']
    ]
];

$group_map = [1 => 'bronze', 2 => 'silver', 3 => 'gold', 4 => 'platinum', 5 => 'diamond'];
$nivel = $group_map[$customer['customer_group_id']] ?? 'bronze';
$member = $membership_config[$nivel];

$niveis_ordem = ['bronze', 'silver', 'gold', 'platinum', 'diamond'];
$nivel_index = array_search($nivel, $niveis_ordem);
$proximo_nivel = $niveis_ordem[$nivel_index + 1] ?? null;
$progresso = $proximo_nivel ? min(100, ($stats['total_gasto'] / $member['next_value']) * 100) : 100;
$falta_gastar = $proximo_nivel ? max(0, $member['next_value'] - $stats['total_gasto']) : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#00C853">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Minha Conta • OneMundo</title>
    <link rel="icon" href="/image/catalog/cart.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        :root {
            --green: #00C853;
            --green-dark: #00A043;
            --green-light: #E8F5E9;
            --orange: #FF6B35;
            --blue: #00B8D4;
            --purple: #7C3AED;
            --red: #EF4444;
            
            --bg: #F8F9FA;
            --surface: #FFFFFF;
            --overlay: #F3F4F6;
            
            --text: #111827;
            --text-secondary: #6B7280;
            --text-tertiary: #9CA3AF;
            
            --border: #E5E7EB;
            --divider: #F3F4F6;
            
            --shadow-xs: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 8px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 16px rgba(0,0,0,0.1);
            --shadow-xl: 0 12px 24px rgba(0,0,0,0.12);
            
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-2xl: 24px;
            --radius-full: 9999px;
            
            --member-color: <?= $member['color'] ?>;
            --member-gradient: <?= $member['gradient'] ?>;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overflow-x: hidden;
            padding-bottom: env(safe-area-inset-bottom);
        }

        /* HEADER APP */
        .app-bar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .app-bar-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .app-bar-back {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
            background: var(--overlay);
            color: var(--text);
            text-decoration: none;
            font-size: 20px;
            font-weight: 600;
            transition: all 0.15s;
        }

        .app-bar-back:active {
            transform: scale(0.92);
            background: var(--border);
        }

        .app-bar-title {
            flex: 1;
            font-size: 17px;
            font-weight: 700;
        }

        .app-bar-logo {
            font-size: 24px;
        }

        /* PROFILE HERO */
        .hero {
            background: var(--member-gradient);
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: 
                radial-gradient(circle at 20% 20%, rgba(255,255,255,0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(0,0,0,0.1) 0%, transparent 50%);
        }

        .hero-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 16px;
            position: relative;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }

        .avatar {
            width: 80px;
            height: 80px;
            border-radius: 24px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: 900;
            color: white;
            border: 3px solid rgba(255,255,255,0.4);
            position: relative;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        }

        .avatar-badge {
            position: absolute;
            bottom: -6px;
            right: -6px;
            width: 32px;
            height: 32px;
            background: white;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .profile-info h1 {
            font-size: 26px;
            font-weight: 900;
            color: white;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }

        .profile-email {
            font-size: 15px;
            color: rgba(255,255,255,0.9);
            font-weight: 500;
            margin-bottom: 10px;
        }

        .level-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.25);
            backdrop-filter: blur(10px);
            padding: 8px 16px;
            border-radius: var(--radius-full);
            font-size: 14px;
            font-weight: 700;
            color: white;
            border: 1.5px solid rgba(255,255,255,0.3);
        }

        .level-discount {
            background: rgba(255,255,255,0.9);
            color: var(--member-color);
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 800;
            margin-left: 4px;
        }

        <?php if ($proximo_nivel): ?>
        .progress-box {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: var(--radius-lg);
            padding: 16px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .progress-text {
            font-size: 13px;
            color: rgba(255,255,255,0.95);
            font-weight: 600;
        }

        .progress-value {
            font-size: 13px;
            color: white;
            font-weight: 800;
        }

        .progress-track {
            height: 8px;
            background: rgba(255,255,255,0.2);
            border-radius: var(--radius-full);
            overflow: hidden;
            position: relative;
        }

        .progress-bar {
            height: 100%;
            background: white;
            border-radius: var(--radius-full);
            width: <?= round($progresso) ?>%;
            transition: width 1.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 0 8px rgba(255,255,255,0.5);
        }
        <?php endif; ?>

        /* STATS */
        .stats {
            max-width: 1200px;
            margin: -32px auto 0;
            padding: 0 16px;
            position: relative;
            z-index: 10;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .stat {
            background: var(--surface);
            border-radius: var(--radius-xl);
            padding: 20px 16px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background: var(--green-light);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 900;
            color: var(--text);
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 600;
        }

        /* CONTENT */
        .content {
            max-width: 1200px;
            margin: 24px auto 100px;
            padding: 0 16px;
        }

        /* SECTION */
        .section {
            background: var(--surface);
            border-radius: var(--radius-2xl);
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 800;
        }

        .section-icon {
            font-size: 22px;
        }

        .section-action {
            font-size: 14px;
            color: var(--green);
            font-weight: 700;
            text-decoration: none;
        }

        /* BENEFITS */
        .benefits-grid {
            display: grid;
            gap: 10px;
        }

        .benefit {
            background: var(--overlay);
            padding: 14px 16px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid var(--divider);
        }

        .benefit-icon {
            width: 36px;
            height: 36px;
            background: var(--green-light);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .benefit-text {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }

        /* ORDERS */
        .order {
            background: var(--overlay);
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 12px;
            border: 1px solid var(--divider);
            transition: all 0.15s;
        }

        .order:active {
            transform: scale(0.98);
            box-shadow: var(--shadow-sm);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .order-id {
            font-size: 16px;
            font-weight: 800;
            color: var(--text);
        }

        .order-date {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .order-status {
            padding: 6px 12px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 700;
        }

        .order-status.success {
            background: #D1FAE5;
            color: #065F46;
        }

        .order-status.pending {
            background: #FEF3C7;
            color: #92400E;
        }

        .order-status.cancelled {
            background: #FEE2E2;
            color: #991B1B;
        }

        .order-products {
            background: white;
            padding: 12px;
            border-radius: var(--radius-md);
            margin-bottom: 12px;
        }

        .product {
            padding: 8px 0;
            font-size: 14px;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--divider);
        }

        .product:last-child {
            border-bottom: none;
        }

        .order-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .order-total {
            font-size: 22px;
            font-weight: 900;
            color: var(--green);
            letter-spacing: -0.5px;
        }

        .order-items {
            font-size: 13px;
            color: var(--text-tertiary);
            font-weight: 600;
        }

        /* ADDRESS */
        .address {
            background: var(--overlay);
            border: 2px solid var(--divider);
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 12px;
        }

        .address.primary {
            background: var(--green-light);
            border-color: var(--green);
        }

        .address-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .address-name {
            font-size: 15px;
            font-weight: 800;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .address-badge {
            background: var(--green);
            color: white;
            font-size: 11px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: var(--radius-full);
        }

        .address-text {
            font-size: 14px;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        /* FORM */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 16px;
            border: 2px solid var(--border);
            border-radius: var(--radius-md);
            font-size: 16px;
            font-family: inherit;
            transition: all 0.2s;
            background: white;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--green);
            box-shadow: 0 0 0 4px var(--green-light);
        }

        .form-input:disabled {
            background: var(--overlay);
            cursor: not-allowed;
            opacity: 0.6;
        }

        /* BUTTONS */
        .btn {
            width: 100%;
            padding: 16px;
            border-radius: var(--radius-md);
            font-size: 16px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.15s;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn:active {
            transform: scale(0.97);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--green) 0%, var(--green-dark) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 200, 83, 0.3);
        }

        .btn-secondary {
            background: var(--overlay);
            color: var(--text);
            border: 2px solid var(--border);
        }

        .btn-danger {
            background: #FEE2E2;
            color: #DC2626;
            border: 2px solid #FECACA;
        }

        /* EMPTY */
        .empty {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-icon {
            font-size: 72px;
            margin-bottom: 16px;
            opacity: 0.25;
        }

        .empty-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 8px;
        }

        .empty-text {
            font-size: 14px;
            color: var(--text-secondary);
        }

        /* TOAST */
        .toast {
            position: fixed;
            top: 80px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--surface);
            padding: 16px 24px;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 1000;
            animation: toastIn 0.3s ease;
            border: 1px solid var(--border);
            max-width: 90%;
        }

        @keyframes toastIn {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }

        .toast-icon {
            font-size: 24px;
        }

        .toast-text {
            font-size: 15px;
            font-weight: 600;
            color: var(--text);
        }

        .toast.success .toast-icon {
            color: var(--green);
        }

        .toast.error .toast-icon {
            color: var(--red);
        }

        /* BOTTOM NAV */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--surface);
            border-top: 1px solid var(--border);
            padding: 8px 8px;
            padding-bottom: max(8px, env(safe-area-inset-bottom));
            z-index: 100;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .nav-grid {
            max-width: 500px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4px;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 10px 8px;
            border-radius: var(--radius-md);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
            transition: all 0.15s;
        }

        .nav-item:active {
            transform: scale(0.92);
        }

        .nav-item.active {
            color: var(--green);
            background: var(--green-light);
        }

        .nav-icon {
            font-size: 24px;
        }

        /* TAB CONTENT */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* RESPONSIVE */
        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .bottom-nav {
                display: none;
            }

            .content {
                margin-bottom: 40px;
            }
        }

        @media (max-width: 767px) {
            .hero-content {
                padding: 24px 16px;
            }

            .profile {
                flex-direction: column;
                text-align: center;
            }

            .profile-info h1 {
                font-size: 24px;
            }
        }

        /* ANIMATIONS */
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }

        .animate-pulse {
            animation: pulse 2s infinite;
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
    <!-- App Bar -->
    <header class="app-bar">
        <div class="app-bar-content">
            <a href="index.php" class="app-bar-back">←</a>
            <div class="app-bar-title">Minha Conta</div>
            <div class="app-bar-logo">🛒</div>
        </div>
    </header>

    <!-- Toast -->
    <?php if ($toast_msg): ?>
    <div class="toast <?= $toast_type ?>" id="toast">
        <div class="toast-icon"><?= $toast_type === 'success' ? '✓' : '⚠️' ?></div>
        <div class="toast-text"><?= htmlspecialchars($toast_msg) ?></div>
    </div>
    <script>
    setTimeout(() => {
        const toast = document.getElementById('toast');
        if (toast) {
            toast.style.animation = 'toastIn 0.3s ease reverse';
            setTimeout(() => toast.remove(), 300);
        }
    }, 3000);
    </script>
    <?php endif; ?>

    <!-- Hero -->
    <div class="hero">
        <div class="hero-content">
            <div class="profile">
                <div class="avatar">
                    <?= strtoupper(mb_substr($customer['firstname'], 0, 1)) ?>
                    <div class="avatar-badge"><?= $member['icon'] ?></div>
                </div>
                <div class="profile-info">
                    <h1><?= htmlspecialchars($customer['firstname'] . ' ' . $customer['lastname']) ?></h1>
                    <div class="profile-email"><?= htmlspecialchars($customer['email']) ?></div>
                    <div class="level-badge">
                        <span><?= $member['icon'] ?></span>
                        <span><?= $member['name'] ?></span>
                        <span class="level-discount"><?= $member['discount'] ?> OFF</span>
                    </div>
                </div>
            </div>

            <?php if ($proximo_nivel): ?>
            <div class="progress-box">
                <div class="progress-label">
                    <span class="progress-text">Faltam R$ <?= number_format($falta_gastar, 0, ',', '.') ?> para <?= $membership_config[$proximo_nivel]['name'] ?></span>
                    <span class="progress-value"><?= round($progresso) ?>%</span>
                </div>
                <div class="progress-track">
                    <div class="progress-bar"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats">
        <div class="stats-grid">
            <div class="stat">
                <div class="stat-icon">🛍️</div>
                <div class="stat-value"><?= $stats['total_pedidos'] ?></div>
                <div class="stat-label">Pedidos</div>
            </div>
            <div class="stat">
                <div class="stat-icon">💰</div>
                <div class="stat-value">R$ <?= number_format($stats['total_gasto'], 0, ',', '.') ?></div>
                <div class="stat-label">Total Gasto</div>
            </div>
            <div class="stat">
                <div class="stat-icon">📦</div>
                <div class="stat-value"><?= $stats['total_itens'] ?></div>
                <div class="stat-label">Itens Comprados</div>
            </div>
            <div class="stat">
                <div class="stat-icon">✓</div>
                <div class="stat-value"><?= $stats_status['concluidos'] ?></div>
                <div class="stat-label">Entregues</div>
            </div>
        </div>
    </div>

    <!-- Content -->
    <div class="content">
        <!-- Tab: Overview -->
        <div id="tab-overview" class="tab-content active">
            <!-- Benefícios -->
            <div class="section">
                <div class="section-header">
                    <div class="section-title">
                        <span class="section-icon">✨</span>
                        Seus Benefícios
                    </div>
                </div>
                <div class="benefits-grid">
                    <?php foreach ($member['benefits'] as $benefit): ?>
                    <div class="benefit">
                        <div class="benefit-icon">✓</div>
                        <div class="benefit-text"><?= $benefit ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Dados Pessoais -->
            <div class="section">
                <div class="section-header">
                    <div class="section-title">
                        <span class="section-icon">👤</span>
                        Dados Pessoais
                    </div>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="atualizar_dados">
                    <div class="form-group">
                        <label class="form-label">Nome</label>
                        <input type="text" name="firstname" class="form-input" value="<?= htmlspecialchars($customer['firstname']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sobrenome</label>
                        <input type="text" name="lastname" class="form-input" value="<?= htmlspecialchars($customer['lastname']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">E-mail</label>
                        <input type="email" class="form-input" value="<?= htmlspecialchars($customer['email']) ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Telefone</label>
                        <input type="tel" name="telephone" class="form-input" value="<?= htmlspecialchars($customer['telephone']) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <span>💾</span>
                        <span>Salvar Alterações</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- Tab: Pedidos -->
        <div id="tab-pedidos" class="tab-content">
            <div class="section">
                <div class="section-header">
                    <div class="section-title">
                        <span class="section-icon">🛍️</span>
                        Meus Pedidos
                    </div>
                </div>

                <?php if (count($pedidos) > 0): ?>
                    <?php foreach ($pedidos as $pedido): ?>
                    <div class="order">
                        <div class="order-header">
                            <div>
                                <div class="order-id">Pedido #<?= $pedido['order_id'] ?></div>
                                <div class="order-date"><?= date('d/m/Y • H:i', strtotime($pedido['date_added'])) ?></div>
                            </div>
                            <div class="order-status <?= $pedido['order_status_id'] == 5 ? 'success' : ($pedido['order_status_id'] == 7 ? 'cancelled' : 'pending') ?>">
                                <?= htmlspecialchars($pedido['status_name']) ?>
                            </div>
                        </div>

                        <?php if ($pedido['produtos_lista']): ?>
                        <div class="order-products">
                            <?php 
                            $produtos = explode('|', $pedido['produtos_lista']);
                            foreach ($produtos as $produto):
                            ?>
                            <div class="product"><?= htmlspecialchars($produto) ?></div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="order-footer">
                            <div class="order-total">R$ <?= number_format($pedido['total'], 2, ',', '.') ?></div>
                            <div class="order-items"><?= $pedido['total_produtos'] ?> itens</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon">🛒</div>
                        <div class="empty-title">Nenhum pedido ainda</div>
                        <div class="empty-text">Comece suas compras agora!</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab: Endereços -->
        <div id="tab-enderecos" class="tab-content">
            <div class="section">
                <div class="section-header">
                    <div class="section-title">
                        <span class="section-icon">📍</span>
                        Endereços
                    </div>
                </div>

                <?php if (count($enderecos) > 0): ?>
                    <?php foreach ($enderecos as $endereco): ?>
                    <div class="address <?= $endereco['address_id'] == $customer['address_id'] ? 'primary' : '' ?>">
                        <div class="address-header">
                            <div class="address-name">
                                📍 <?= htmlspecialchars($endereco['firstname'] . ' ' . $endereco['lastname']) ?>
                                <?php if ($endereco['address_id'] == $customer['address_id']): ?>
                                <span class="address-badge">Principal</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="address-text">
                            <?= htmlspecialchars($endereco['address_1']) ?>
                            <?php if ($endereco['address_2']): ?>, <?= htmlspecialchars($endereco['address_2']) ?><?php endif; ?>
                            <br>
                            <?= htmlspecialchars($endereco['city']) ?> - <?= htmlspecialchars($endereco['zone_code']) ?>
                            <br>
                            CEP: <?= htmlspecialchars($endereco['postcode']) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon">📍</div>
                        <div class="empty-title">Nenhum endereço</div>
                        <div class="empty-text">Adicione um endereço de entrega</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab: Configurações -->
        <div id="tab-config" class="tab-content">
            <!-- Alterar Senha -->
            <div class="section">
                <div class="section-header">
                    <div class="section-title">
                        <span class="section-icon">🔒</span>
                        Alterar Senha
                    </div>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="trocar_senha">
                    <div class="form-group">
                        <label class="form-label">Senha Atual</label>
                        <input type="password" name="senha_atual" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nova Senha</label>
                        <input type="password" name="senha_nova" class="form-input" minlength="6" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirmar Nova Senha</label>
                        <input type="password" name="senha_confirma" class="form-input" minlength="6" required>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <span>🔐</span>
                        <span>Alterar Senha</span>
                    </button>
                </form>
            </div>

            <!-- Sair -->
            <div class="section">
                <div class="section-header">
                    <div class="section-title">
                        <span class="section-icon">🚪</span>
                        Sair da Conta
                    </div>
                </div>
                <p style="color: var(--text-secondary); margin-bottom: 16px; font-size: 14px;">
                    Encerrar sua sessão no OneMundo
                </p>
                <a href="?logout=1" class="btn btn-danger" style="text-decoration: none;">
                    <span>👋</span>
                    <span>Sair</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Bottom Nav -->
    <nav class="bottom-nav">
        <div class="nav-grid">
            <a href="#" onclick="showTab('overview'); return false;" class="nav-item active" data-tab="overview">
                <span class="nav-icon">🏠</span>
                <span>Início</span>
            </a>
            <a href="#" onclick="showTab('pedidos'); return false;" class="nav-item" data-tab="pedidos">
                <span class="nav-icon">🛍️</span>
                <span>Pedidos</span>
            </a>
            <a href="#" onclick="showTab('enderecos'); return false;" class="nav-item" data-tab="enderecos">
                <span class="nav-icon">📍</span>
                <span>Endereços</span>
            </a>
            <a href="#" onclick="showTab('config'); return false;" class="nav-item" data-tab="config">
                <span class="nav-icon">⚙️</span>
                <span>Conta</span>
            </a>
        </div>
    </nav>

    <script>
    function showTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.getElementById('tab-' + tabName).classList.add('active');
        
        document.querySelectorAll('.nav-item').forEach(i => {
            i.classList.remove('active');
            if (i.dataset.tab === tabName) i.classList.add('active');
        });
        
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Haptic feedback simulation
    document.querySelectorAll('.btn, .nav-item, .order').forEach(el => {
        el.addEventListener('touchstart', () => {
            if (navigator.vibrate) navigator.vibrate(10);
        });
    });
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
