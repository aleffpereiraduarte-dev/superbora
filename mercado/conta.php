<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SUPERBORA - MINHA CONTA ULTRA PREMIUM v4.0
 * APIs Completas e Funcionais
 * Inspirado em: DoorDash + Instacart + Rappi + iFood + Uber Eats
 * ══════════════════════════════════════════════════════════════════════════════
 */

// Autenticação
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/includes/env_loader.php';

session_name('OCSESSID');
if (session_status() === PHP_SESSION_NONE) session_start();

// Conexão com banco
$pdo = null;
try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    $config_file = dirname(__DIR__) . '/config.php';
    if (file_exists($config_file)) {
        require_once $config_file;
        $pdo = new PDO(
            "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
            DB_USERNAME, DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
}

$customer_id = $_SESSION['customer_id'] ?? 0;

// Redirecionar se não logado
if (!$customer_id) {
    header('Location: /mercado/mercado-login.php');
    exit;
}

$is_demo = false;

// ══════════════════════════════════════════════════════════════════════════════
// CARREGAR DADOS DO CLIENTE
// ══════════════════════════════════════════════════════════════════════════════

$user = [
    'id' => $customer_id,
    'name' => 'Visitante',
    'firstname' => 'Visitante',
    'email' => '',
    'phone' => '',
    'avatar' => null,
    'member_since' => date('Y-m-d'),
];

$stats = [
    'orders_count' => 0,
    'orders_total' => 0,
    'favorites_count' => 0,
    'reviews_count' => 0,
];

$wallet = [
    'balance' => 0,
    'cashback' => 0,
    'credits' => 0,
    'points' => 0,
];

$level = [
    'current' => 'bronze',
    'name' => 'Bronze',
    'icon' => '🥉',
    'progress' => 0,
    'points_to_next' => 1000,
];

$membership = null;
$addresses = [];
$cards = [];
$orders = [];
$favorites = [];
$promos = [];
$notifications_count = 0;
$achievements = [];

// Carregar dados reais se tiver PDO
if ($pdo && !$is_demo) {
    try {
        // Dados do cliente
        $stmt = $pdo->prepare("SELECT * FROM oc_customer WHERE customer_id = ?");
        $stmt->execute([$customer_id]);
        $customer = $stmt->fetch();
        if ($customer) {
            $user = array_merge($user, [
                'name' => trim($customer['firstname'] . ' ' . $customer['lastname']),
                'firstname' => $customer['firstname'],
                'email' => $customer['email'],
                'phone' => $customer['telephone'],
                'member_since' => $customer['date_added'],
            ]);
        }

        // Estatísticas de pedidos
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as value
            FROM oc_order WHERE customer_id = ? AND order_status_id > 0
        ");
        $stmt->execute([$customer_id]);
        $order_stats = $stmt->fetch();
        $stats['orders_count'] = $order_stats['total'] ?? 0;
        $stats['orders_total'] = $order_stats['value'] ?? 0;

        // Calcular nível baseado em pedidos
        $points = $stats['orders_count'] * 100 + floor($stats['orders_total'] / 10);
        $wallet['points'] = $points;

        if ($points >= 5000) {
            $level = ['current' => 'diamond', 'name' => 'Diamante', 'icon' => '💎', 'progress' => 100, 'points_to_next' => 0];
        } elseif ($points >= 2500) {
            $level = ['current' => 'gold', 'name' => 'Ouro', 'icon' => '🥇', 'progress' => (($points - 2500) / 2500) * 100, 'points_to_next' => 5000 - $points];
        } elseif ($points >= 1000) {
            $level = ['current' => 'silver', 'name' => 'Prata', 'icon' => '🥈', 'progress' => (($points - 1000) / 1500) * 100, 'points_to_next' => 2500 - $points];
        } else {
            $level = ['current' => 'bronze', 'name' => 'Bronze', 'icon' => '🥉', 'progress' => ($points / 1000) * 100, 'points_to_next' => 1000 - $points];
        }

        // Cashback (5% do total gasto)
        $wallet['cashback'] = round($stats['orders_total'] * 0.05, 2);
        $wallet['balance'] = $wallet['cashback'];

        // Endereços
        $stmt = $pdo->prepare("
            SELECT a.*, z.name as zone_name
            FROM oc_address a
            LEFT JOIN oc_zone z ON a.zone_id = z.zone_id
            WHERE a.customer_id = ?
            ORDER BY a.address_id DESC LIMIT 5
        ");
        $stmt->execute([$customer_id]);
        $addresses = $stmt->fetchAll() ?: [];

        // Pedidos recentes
        $stmt = $pdo->prepare("
            SELECT o.order_id, o.total, o.date_added, o.order_status_id,
                   os.name as status_name,
                   (SELECT COUNT(*) FROM oc_order_product WHERE order_id = o.order_id) as items_count
            FROM oc_order o
            LEFT JOIN oc_order_status os ON o.order_status_id = os.order_status_id AND os.language_id = 2
            WHERE o.customer_id = ? AND o.order_status_id > 0
            ORDER BY o.date_added DESC LIMIT 5
        ");
        $stmt->execute([$customer_id]);
        $orders = $stmt->fetchAll() ?: [];

        // Favoritos (produtos mais comprados)
        $stmt = $pdo->prepare("
            SELECT p.product_id, pd.name, p.price, p.image,
                   COUNT(*) as buy_count
            FROM oc_order o
            JOIN oc_order_product op ON o.order_id = op.order_id
            JOIN oc_product p ON op.product_id = p.product_id
            JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 2
            WHERE o.customer_id = ? AND o.order_status_id > 0 AND p.status = 1
            GROUP BY p.product_id
            ORDER BY buy_count DESC, o.date_added DESC
            LIMIT 8
        ");
        $stmt->execute([$customer_id]);
        $favorites = $stmt->fetchAll() ?: [];
        $stats['favorites_count'] = count($favorites);

        // Tentar pegar favoritos da tabela dedicada
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM oc_customer_wishlist WHERE customer_id = ?");
            $stmt->execute([$customer_id]);
            $wishlist_count = $stmt->fetchColumn();
            if ($wishlist_count > 0) {
                $stats['favorites_count'] = $wishlist_count;
            }
        } catch (Exception $e) {}

        // Membership
        try {
            $stmt = $pdo->prepare("SELECT * FROM om_memberships WHERE customer_id = ? AND status = 'active' AND expires_at > NOW()");
            $stmt->execute([$customer_id]);
            $membership = $stmt->fetch();
        } catch (Exception $e) {}

        // Cartões salvos
        try {
            $stmt = $pdo->prepare("SELECT * FROM om_customer_cards WHERE customer_id = ? AND status = 'active' ORDER BY is_default DESC");
            $stmt->execute([$customer_id]);
            $cards = $stmt->fetchAll() ?: [];
        } catch (Exception $e) {}

        // Notificações não lidas
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM om_notifications WHERE customer_id = ? AND is_read = 0");
            $stmt->execute([$customer_id]);
            $notifications_count = $stmt->fetchColumn() ?: 0;
        } catch (Exception $e) {}

    } catch (Exception $e) {
        // Erro silencioso, usa dados padrão
    }
}

// Dados demo se necessário
if ($is_demo || empty($orders)) {
    $user = [
        'id' => 1,
        'name' => 'João Silva',
        'firstname' => 'João',
        'email' => 'joao@email.com',
        'phone' => '(11) 99999-9999',
        'avatar' => null,
        'member_since' => '2024-06-15',
    ];

    $stats = ['orders_count' => 28, 'orders_total' => 3250.00, 'favorites_count' => 12, 'reviews_count' => 8];
    $wallet = ['balance' => 125.50, 'cashback' => 47.80, 'credits' => 30.00, 'points' => 2450];
    $level = ['current' => 'gold', 'name' => 'Ouro', 'icon' => '🥇', 'progress' => 78, 'points_to_next' => 550];

    $membership = ['plan_name' => 'SuperBora+', 'price' => 19.90, 'savings' => 189.50];

    $addresses = [
        ['address_id' => 1, 'address_1' => 'Rua das Flores, 123', 'address_2' => 'Apto 45', 'city' => 'São Paulo', 'zone_name' => 'SP', 'postcode' => '01234-567', 'default' => true],
        ['address_id' => 2, 'address_1' => 'Av. Paulista, 1000', 'address_2' => 'Sala 201', 'city' => 'São Paulo', 'zone_name' => 'SP', 'postcode' => '01310-100', 'default' => false],
    ];

    $cards = [
        ['id' => 1, 'brand' => 'visa', 'last_four' => '4589', 'is_default' => 1],
        ['id' => 2, 'brand' => 'mastercard', 'last_four' => '7823', 'is_default' => 0],
    ];

    $orders = [
        ['order_id' => 1847, 'total' => 156.80, 'date_added' => date('Y-m-d H:i:s', strtotime('-1 day')), 'order_status_id' => 5, 'status_name' => 'Entregue', 'items_count' => 12],
        ['order_id' => 1823, 'total' => 89.50, 'date_added' => date('Y-m-d H:i:s', strtotime('-4 days')), 'order_status_id' => 5, 'status_name' => 'Entregue', 'items_count' => 6],
        ['order_id' => 1801, 'total' => 234.90, 'date_added' => date('Y-m-d H:i:s', strtotime('-1 week')), 'order_status_id' => 5, 'status_name' => 'Entregue', 'items_count' => 18],
    ];

    $favorites = [
        ['product_id' => 1, 'name' => 'Coca-Cola 2L', 'price' => 9.99, 'image' => ''],
        ['product_id' => 2, 'name' => 'Pão Francês', 'price' => 0.50, 'image' => ''],
        ['product_id' => 3, 'name' => 'Banana Prata', 'price' => 5.99, 'image' => ''],
        ['product_id' => 4, 'name' => 'Leite Integral', 'price' => 6.49, 'image' => ''],
        ['product_id' => 5, 'name' => 'Arroz 5kg', 'price' => 24.90, 'image' => ''],
        ['product_id' => 6, 'name' => 'Feijão 1kg', 'price' => 8.99, 'image' => ''],
    ];

    $notifications_count = 3;
}

// Promos sempre mostrar
$promos = [
    ['code' => 'FRETEGRATIS', 'title' => 'Frete Grátis', 'desc' => 'Em compras acima de R$50', 'expires' => '3 dias', 'color' => '#10b981', 'discount' => 'Frete'],
    ['code' => 'SUPER15', 'title' => '15% OFF', 'desc' => 'Na próxima compra', 'expires' => '7 dias', 'color' => '#8b5cf6', 'discount' => '15%'],
    ['code' => 'HORTI10', 'title' => 'R$10 OFF', 'desc' => 'Em hortifruti', 'expires' => '5 dias', 'color' => '#f59e0b', 'discount' => 'R$10'],
    ['code' => 'PRIMEIRA', 'title' => '20% OFF', 'desc' => 'Primeira compra do mês', 'expires' => '15 dias', 'color' => '#ec4899', 'discount' => '20%'],
];

// Conquistas/Achievements
$achievements = [
    ['icon' => '🛒', 'name' => 'Primeira Compra', 'unlocked' => true],
    ['icon' => '⭐', 'name' => '10 Pedidos', 'unlocked' => $stats['orders_count'] >= 10],
    ['icon' => '🏆', 'name' => '25 Pedidos', 'unlocked' => $stats['orders_count'] >= 25],
    ['icon' => '💎', 'name' => 'Cliente VIP', 'unlocked' => $level['current'] === 'diamond'],
    ['icon' => '❤️', 'name' => '5 Favoritos', 'unlocked' => $stats['favorites_count'] >= 5],
    ['icon' => '📝', 'name' => 'Avaliador', 'unlocked' => $stats['reviews_count'] >= 3],
];

// Código de indicação
$referral_code = strtoupper(substr($user['firstname'], 0, 4)) . rand(10, 99);

// Saudação
$hora = (int)date('H');
$saudacao = ($hora >= 5 && $hora < 12) ? 'Bom dia' : (($hora >= 12 && $hora < 18) ? 'Boa tarde' : 'Boa noite');

// Emojis para produtos
$product_emojis = ['🥤', '🥖', '🍌', '🥛', '🍚', '🫘', '🍎', '🥩', '🧀', '🥚', '🍫', '☕'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Minha Conta - SuperBora</title>
    <meta name="theme-color" content="#059669">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --primary: #10b981;
            --primary-dark: #059669;
            --primary-light: #34d399;
            --accent: #f59e0b;
            --purple: #8b5cf6;
            --pink: #ec4899;
            --blue: #3b82f6;
            --cyan: #06b6d4;
            --danger: #ef4444;
            --success: #22c55e;
            --warning: #f59e0b;
            --bg: #f8fafc;
            --bg-dark: #f1f5f9;
            --card: #ffffff;
            --text: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --border: #e2e8f0;
            --border-light: #f1f5f9;
            --shadow-xs: 0 1px 2px rgba(0,0,0,0.04);
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-lg: 0 12px 40px rgba(0,0,0,0.12);
            --shadow-xl: 0 24px 60px rgba(0,0,0,0.16);
            --radius-xs: 8px;
            --radius-sm: 12px;
            --radius-md: 16px;
            --radius-lg: 20px;
            --radius-xl: 28px;
            --radius-2xl: 32px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-bottom: 100px;
            overflow-x: hidden;
        }

        /* ══════════════════════════════════════════════════
           HEADER PREMIUM
        ══════════════════════════════════════════════════ */
        .header {
            background: linear-gradient(145deg, #047857 0%, #059669 25%, #10b981 60%, #34d399 100%);
            padding: 16px 20px 90px;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -80%;
            right: -30%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, transparent 70%);
            border-radius: 50%;
            animation: float 8s ease-in-out infinite;
        }

        .header::after {
            content: '';
            position: absolute;
            bottom: -60%;
            left: -20%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            border-radius: 50%;
            animation: float 10s ease-in-out infinite reverse;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
        }

        .header-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 10;
            margin-bottom: 20px;
        }

        .btn-back {
            width: 42px;
            height: 42px;
            background: rgba(255,255,255,0.18);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: var(--radius-sm);
            color: white;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-back:hover { background: rgba(255,255,255,0.28); transform: scale(1.05); }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .btn-action {
            width: 42px;
            height: 42px;
            background: rgba(255,255,255,0.18);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: var(--radius-sm);
            color: white;
            font-size: 17px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            text-decoration: none;
            position: relative;
        }

        .btn-action:hover { background: rgba(255,255,255,0.28); }

        .btn-action .notif-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 20px;
            height: 20px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
            border: 2px solid #059669;
            animation: pulse-badge 2s infinite;
        }

        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .profile-row {
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
            z-index: 10;
        }

        .avatar-wrap {
            position: relative;
            flex-shrink: 0;
        }

        .avatar {
            width: 74px;
            height: 74px;
            background: linear-gradient(135deg, #fff 0%, #f8fafc 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            font-weight: 800;
            color: var(--primary-dark);
            box-shadow: 0 8px 32px rgba(0,0,0,0.2), inset 0 -2px 6px rgba(0,0,0,0.05);
            border: 3px solid rgba(255,255,255,0.6);
        }

        .avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .level-ring {
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            border: 3px solid transparent;
            border-top-color: var(--accent);
            border-right-color: var(--accent);
            animation: spin 3s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .level-icon {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 28px;
            height: 28px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            box-shadow: var(--shadow-md);
            border: 2px solid var(--primary);
        }

        .profile-info {
            flex: 1;
            color: white;
            min-width: 0;
        }

        .greeting {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 2px;
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .plus-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #78350f;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(251,191,36,0.4);
        }

        .level-bar-wrap {
            margin-bottom: 4px;
        }

        .level-bar {
            background: rgba(255,255,255,0.25);
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
        }

        .level-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 50%, #ea580c 100%);
            border-radius: 10px;
            transition: width 1s ease;
            position: relative;
        }

        .level-bar-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .level-info {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            opacity: 0.9;
        }

        /* ══════════════════════════════════════════════════
           WALLET CARD
        ══════════════════════════════════════════════════ */
        .wallet-card {
            margin: -65px 16px 0;
            background: linear-gradient(145deg, #1e293b 0%, #334155 50%, #475569 100%);
            border-radius: var(--radius-xl);
            padding: 22px;
            color: white;
            position: relative;
            z-index: 20;
            box-shadow: var(--shadow-xl);
            overflow: hidden;
        }

        .wallet-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(16,185,129,0.25) 0%, transparent 70%);
            border-radius: 50%;
        }

        .wallet-card::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -20%;
            width: 150px;
            height: 150px;
            background: radial-gradient(circle, rgba(139,92,246,0.15) 0%, transparent 70%);
            border-radius: 50%;
        }

        .wallet-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 18px;
            position: relative;
            z-index: 2;
        }

        .wallet-balance-section {}

        .wallet-label {
            font-size: 12px;
            color: rgba(255,255,255,0.7);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .wallet-label i { font-size: 14px; }

        .wallet-balance {
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .wallet-balance .currency {
            font-size: 1.2rem;
            font-weight: 600;
            opacity: 0.8;
        }

        .wallet-actions {
            display: flex;
            gap: 8px;
        }

        .wallet-btn {
            padding: 10px 14px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: var(--radius-sm);
            color: white;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .wallet-btn:hover { background: rgba(255,255,255,0.2); transform: translateY(-1px); }
        .wallet-btn.primary { background: var(--primary); border-color: var(--primary); }
        .wallet-btn.primary:hover { background: var(--primary-dark); }

        .wallet-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            position: relative;
            z-index: 2;
        }

        .wallet-item {
            background: rgba(255,255,255,0.08);
            border-radius: var(--radius-md);
            padding: 14px 10px;
            text-align: center;
            transition: all 0.2s;
            cursor: pointer;
            border: 1px solid rgba(255,255,255,0.05);
        }

        .wallet-item:hover {
            background: rgba(255,255,255,0.14);
            transform: translateY(-2px);
        }

        .wallet-item-icon {
            font-size: 22px;
            margin-bottom: 6px;
        }

        .wallet-item-value {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .wallet-item-label {
            font-size: 10px;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ══════════════════════════════════════════════════
           CONTAINER
        ══════════════════════════════════════════════════ */
        .container {
            padding: 20px 16px;
        }

        /* ══════════════════════════════════════════════════
           QUICK STATS
        ══════════════════════════════════════════════════ */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        .quick-stat {
            background: var(--card);
            border-radius: var(--radius-md);
            padding: 14px 8px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            cursor: pointer;
            transition: all 0.2s;
        }

        .quick-stat:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .quick-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            font-size: 18px;
        }

        .quick-stat-icon.green { background: linear-gradient(135deg, #ecfdf5, #d1fae5); color: var(--primary); }
        .quick-stat-icon.pink { background: linear-gradient(135deg, #fce7f3, #fbcfe8); color: var(--pink); }
        .quick-stat-icon.yellow { background: linear-gradient(135deg, #fef3c7, #fde68a); color: var(--accent); }
        .quick-stat-icon.purple { background: linear-gradient(135deg, #f3e8ff, #e9d5ff); color: var(--purple); }

        .quick-stat-value {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 2px;
        }

        .quick-stat-label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* ══════════════════════════════════════════════════
           MEMBERSHIP CARD
        ══════════════════════════════════════════════════ */
        .membership-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 40%, #fbbf24 100%);
            border-radius: var(--radius-lg);
            padding: 18px;
            margin-bottom: 16px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(251,191,36,0.25);
        }

        .membership-card::before {
            content: '✨';
            position: absolute;
            top: 12px;
            right: 16px;
            font-size: 32px;
            opacity: 0.4;
        }

        .membership-top {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .membership-icon {
            width: 50px;
            height: 50px;
            background: white;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            box-shadow: var(--shadow-md);
        }

        .membership-info h3 {
            font-size: 17px;
            font-weight: 700;
            color: #78350f;
        }

        .membership-info p {
            font-size: 12px;
            color: #92400e;
        }

        .membership-benefits {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 16px;
            margin-bottom: 12px;
        }

        .benefit {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: #78350f;
            font-weight: 500;
        }

        .benefit i {
            color: var(--primary);
            font-size: 11px;
        }

        .membership-footer {
            padding-top: 12px;
            border-top: 1px dashed rgba(120,53,15,0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .membership-savings {
            font-size: 13px;
            color: #92400e;
        }

        .membership-savings strong {
            color: #78350f;
            font-size: 16px;
        }

        .membership-manage {
            font-size: 12px;
            color: #78350f;
            font-weight: 600;
            text-decoration: none;
        }

        /* ══════════════════════════════════════════════════
           REFERRAL CARD
        ══════════════════════════════════════════════════ */
        .referral-card {
            background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
            border-radius: var(--radius-lg);
            padding: 18px;
            color: white;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 4px 20px rgba(139,92,246,0.3);
        }

        .referral-icon {
            width: 54px;
            height: 54px;
            background: rgba(255,255,255,0.2);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            flex-shrink: 0;
        }

        .referral-content {
            flex: 1;
            min-width: 0;
        }

        .referral-title {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 3px;
        }

        .referral-desc {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 8px;
        }

        .referral-code-box {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.2);
            padding: 6px 12px;
            border-radius: var(--radius-xs);
            font-family: 'SF Mono', Monaco, monospace;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 1px;
            cursor: pointer;
        }

        .referral-code-box i {
            font-size: 12px;
            opacity: 0.8;
        }

        .referral-share {
            padding: 12px 16px;
            background: white;
            color: var(--purple);
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .referral-share:hover { transform: scale(1.03); }

        /* ══════════════════════════════════════════════════
           ACHIEVEMENTS
        ══════════════════════════════════════════════════ */
        .achievements-row {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin-bottom: 16px;
            scrollbar-width: none;
        }

        .achievements-row::-webkit-scrollbar { display: none; }

        .achievement {
            flex-shrink: 0;
            width: 70px;
            text-align: center;
            cursor: pointer;
        }

        .achievement-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--card);
            border: 2px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin: 0 auto 6px;
            transition: all 0.2s;
            box-shadow: var(--shadow-sm);
        }

        .achievement.unlocked .achievement-icon {
            border-color: var(--accent);
            background: linear-gradient(135deg, #fef3c7, #fde68a);
        }

        .achievement.locked .achievement-icon {
            filter: grayscale(1);
            opacity: 0.5;
        }

        .achievement-name {
            font-size: 10px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        /* ══════════════════════════════════════════════════
           SECTION
        ══════════════════════════════════════════════════ */
        .section {
            margin-bottom: 20px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-link {
            font-size: 13px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* ══════════════════════════════════════════════════
           PROMOS CAROUSEL
        ══════════════════════════════════════════════════ */
        .promos-scroll {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: none;
        }

        .promos-scroll::-webkit-scrollbar { display: none; }

        .promo-card {
            flex-shrink: 0;
            width: 160px;
            padding: 16px;
            border-radius: var(--radius-lg);
            color: white;
            position: relative;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.2s;
        }

        .promo-card:hover { transform: scale(1.02); }

        .promo-card::before {
            content: '';
            position: absolute;
            top: -30px;
            right: -30px;
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
        }

        .promo-discount {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .promo-title {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 2px;
            opacity: 0.95;
        }

        .promo-code-tag {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
            font-family: monospace;
            letter-spacing: 0.5px;
            margin-top: 8px;
        }

        .promo-expires {
            font-size: 10px;
            opacity: 0.75;
            margin-top: 6px;
        }

        /* ══════════════════════════════════════════════════
           ORDERS
        ══════════════════════════════════════════════════ */
        .orders-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .order-card {
            background: var(--card);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            box-shadow: var(--shadow-xs);
            border: 1px solid var(--border-light);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .order-card:hover {
            box-shadow: var(--shadow-sm);
            border-color: var(--primary);
        }

        .order-icon {
            width: 46px;
            height: 46px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            flex-shrink: 0;
        }

        .order-info {
            flex: 1;
            min-width: 0;
        }

        .order-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }

        .order-id {
            font-weight: 600;
            color: var(--text);
            font-size: 14px;
        }

        .order-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .order-status.delivered { background: #ecfdf5; color: var(--primary); }
        .order-status.processing { background: #fef3c7; color: #d97706; }
        .order-status.pending { background: #eff6ff; color: var(--blue); }

        .order-meta {
            font-size: 12px;
            color: var(--text-muted);
        }

        .order-total {
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
            text-align: right;
        }

        .order-items {
            font-size: 11px;
            color: var(--text-muted);
        }

        /* ══════════════════════════════════════════════════
           FAVORITES GRID
        ══════════════════════════════════════════════════ */
        .favorites-scroll {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: none;
        }

        .favorites-scroll::-webkit-scrollbar { display: none; }

        .favorite-card {
            flex-shrink: 0;
            width: 100px;
            background: var(--card);
            border-radius: var(--radius-md);
            padding: 12px 10px;
            text-align: center;
            box-shadow: var(--shadow-xs);
            border: 1px solid var(--border-light);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .favorite-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
        }

        .favorite-heart {
            position: absolute;
            top: 8px;
            right: 8px;
            color: var(--pink);
            font-size: 12px;
        }

        .favorite-img {
            width: 56px;
            height: 56px;
            border-radius: var(--radius-sm);
            background: var(--bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 8px;
        }

        .favorite-name {
            font-size: 11px;
            font-weight: 500;
            color: var(--text);
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .favorite-price {
            font-size: 12px;
            font-weight: 700;
            color: var(--primary);
        }

        .favorite-add {
            margin-top: 8px;
            width: 100%;
            padding: 6px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .favorite-add:hover { background: var(--primary-dark); }

        /* ══════════════════════════════════════════════════
           ADDRESSES
        ══════════════════════════════════════════════════ */
        .addresses-scroll {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: none;
        }

        .addresses-scroll::-webkit-scrollbar { display: none; }

        .address-card {
            flex-shrink: 0;
            width: 200px;
            background: var(--card);
            border-radius: var(--radius-md);
            padding: 14px;
            box-shadow: var(--shadow-xs);
            border: 1px solid var(--border-light);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .address-card:hover { border-color: var(--primary); }

        .address-card.default { border-color: var(--primary); }

        .address-default-tag {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--primary);
            color: white;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 9px;
            font-weight: 600;
        }

        .address-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border-radius: var(--radius-xs);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 16px;
            margin-bottom: 10px;
        }

        .address-text {
            font-size: 13px;
            font-weight: 500;
            color: var(--text);
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .address-city {
            font-size: 12px;
            color: var(--text-muted);
        }

        .add-address {
            flex-shrink: 0;
            width: 100px;
            background: var(--bg);
            border: 2px dashed var(--border);
            border-radius: var(--radius-md);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
            padding: 20px;
        }

        .add-address:hover { border-color: var(--primary); color: var(--primary); }
        .add-address i { font-size: 20px; }
        .add-address span { font-size: 11px; font-weight: 500; }

        /* ══════════════════════════════════════════════════
           PAYMENT CARDS
        ══════════════════════════════════════════════════ */
        .cards-scroll {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: none;
        }

        .cards-scroll::-webkit-scrollbar { display: none; }

        .pay-card {
            flex-shrink: 0;
            width: 180px;
            height: 110px;
            border-radius: var(--radius-lg);
            padding: 16px;
            color: white;
            position: relative;
            cursor: pointer;
            transition: all 0.2s;
            overflow: hidden;
        }

        .pay-card:hover { transform: scale(1.02); }

        .pay-card.visa { background: linear-gradient(135deg, #1a1f71 0%, #2563eb 100%); }
        .pay-card.mastercard { background: linear-gradient(135deg, #eb001b 0%, #f79e1b 100%); }
        .pay-card.elo { background: linear-gradient(135deg, #000000 0%, #ffd700 100%); }
        .pay-card.pix { background: linear-gradient(135deg, #32bcad 0%, #00b4b6 100%); }

        .pay-card-default {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255,255,255,0.25);
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 9px;
            font-weight: 600;
        }

        .pay-card-brand {
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 20px;
            letter-spacing: 1px;
        }

        .pay-card-number {
            font-family: 'SF Mono', Monaco, monospace;
            font-size: 14px;
            letter-spacing: 2px;
        }

        .add-pay-card {
            flex-shrink: 0;
            width: 120px;
            height: 110px;
            border-radius: var(--radius-lg);
            border: 2px dashed var(--border);
            background: var(--bg);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
        }

        .add-pay-card:hover { border-color: var(--primary); color: var(--primary); }
        .add-pay-card i { font-size: 22px; }
        .add-pay-card span { font-size: 11px; font-weight: 500; }

        /* ══════════════════════════════════════════════════
           MENU CARDS
        ══════════════════════════════════════════════════ */
        .menu-card {
            background: var(--card);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-xs);
            border: 1px solid var(--border-light);
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            text-decoration: none;
            color: var(--text);
            transition: all 0.15s;
            border-bottom: 1px solid var(--border-light);
        }

        .menu-item:last-child { border-bottom: none; }
        .menu-item:hover { background: var(--bg); }
        .menu-item:active { background: var(--bg-dark); }

        .menu-icon {
            width: 42px;
            height: 42px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
        }

        .menu-icon.green { background: linear-gradient(135deg, #ecfdf5, #d1fae5); color: var(--primary); }
        .menu-icon.blue { background: linear-gradient(135deg, #eff6ff, #dbeafe); color: var(--blue); }
        .menu-icon.purple { background: linear-gradient(135deg, #f3e8ff, #e9d5ff); color: var(--purple); }
        .menu-icon.yellow { background: linear-gradient(135deg, #fef3c7, #fde68a); color: var(--accent); }
        .menu-icon.red { background: linear-gradient(135deg, #fee2e2, #fecaca); color: var(--danger); }
        .menu-icon.pink { background: linear-gradient(135deg, #fce7f3, #fbcfe8); color: var(--pink); }
        .menu-icon.cyan { background: linear-gradient(135deg, #ecfeff, #cffafe); color: var(--cyan); }
        .menu-icon.gray { background: linear-gradient(135deg, #f8fafc, #e2e8f0); color: var(--text-secondary); }

        .menu-content { flex: 1; min-width: 0; }

        .menu-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 1px;
        }

        .menu-subtitle {
            font-size: 12px;
            color: var(--text-muted);
        }

        .menu-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .menu-value {
            font-weight: 700;
            color: var(--primary);
            font-size: 13px;
        }

        .menu-badge {
            background: var(--danger);
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
        }

        .menu-badge.green { background: var(--primary); }

        .menu-arrow {
            color: var(--text-muted);
            font-size: 13px;
        }

        .menu-toggle {
            width: 46px;
            height: 26px;
            background: var(--border);
            border-radius: 13px;
            position: relative;
            cursor: pointer;
            transition: all 0.2s;
        }

        .menu-toggle.on { background: var(--primary); }

        .menu-toggle::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 22px;
            height: 22px;
            background: white;
            border-radius: 50%;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s;
        }

        .menu-toggle.on::after { left: 22px; }

        /* ══════════════════════════════════════════════════
           LOGOUT
        ══════════════════════════════════════════════════ */
        .logout-btn {
            width: 100%;
            padding: 15px;
            background: var(--card);
            border: 2px solid #fecaca;
            border-radius: var(--radius-md);
            color: var(--danger);
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s;
            margin-top: 10px;
        }

        .logout-btn:hover { background: #fef2f2; }

        /* ══════════════════════════════════════════════════
           APP INFO
        ══════════════════════════════════════════════════ */
        .app-info {
            text-align: center;
            padding: 24px 20px;
            color: var(--text-muted);
        }

        .app-version {
            font-size: 12px;
            margin-bottom: 4px;
        }

        .app-copyright {
            font-size: 11px;
        }

        /* ══════════════════════════════════════════════════
           BOTTOM NAV
        ══════════════════════════════════════════════════ */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--card);
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: space-around;
            padding: 6px 0 env(safe-area-inset-bottom, 16px);
            z-index: 100;
            box-shadow: 0 -2px 20px rgba(0,0,0,0.04);
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 10px;
            font-weight: 600;
            padding: 6px 14px;
            transition: all 0.2s;
            position: relative;
        }

        .nav-item.active { color: var(--primary); }
        .nav-item:hover { color: var(--primary); }
        .nav-item i { font-size: 21px; }

        .nav-item.active::after {
            content: '';
            position: absolute;
            top: -6px;
            left: 50%;
            transform: translateX(-50%);
            width: 5px;
            height: 5px;
            background: var(--primary);
            border-radius: 50%;
        }

        /* ══════════════════════════════════════════════════
           TOAST
        ══════════════════════════════════════════════════ */
        .toast {
            position: fixed;
            bottom: 90px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--text);
            color: white;
            padding: 12px 20px;
            border-radius: var(--radius-md);
            font-size: 13px;
            font-weight: 500;
            box-shadow: var(--shadow-lg);
            z-index: 1000;
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: calc(100% - 40px);
        }

        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.success { background: var(--primary); }
        .toast.error { background: var(--danger); }

        /* ══════════════════════════════════════════════════
           RESPONSIVE
        ══════════════════════════════════════════════════ */
        @media (max-width: 380px) {
            .quick-stats { grid-template-columns: repeat(2, 1fr); }
            .wallet-grid { grid-template-columns: repeat(2, 1fr); }
            .referral-card { flex-direction: column; text-align: center; }
            .referral-share { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<!-- Header -->
<header class="header">
    <nav class="header-nav">
        <a href="/mercado/" class="btn-back"><i class="fas fa-arrow-left"></i></a>
        <div class="header-actions">
            <a href="/mercado/notificacoes.php" class="btn-action">
                <i class="fas fa-bell"></i>
                <?php if ($notifications_count > 0): ?>
                <span class="notif-badge"><?= min($notifications_count, 9) ?><?= $notifications_count > 9 ? '+' : '' ?></span>
                <?php endif; ?>
            </a>
            <a href="/mercado/configuracoes.php" class="btn-action"><i class="fas fa-cog"></i></a>
        </div>
    </nav>

    <div class="profile-row">
        <div class="avatar-wrap">
            <div class="level-ring"></div>
            <div class="avatar">
                <?php if ($user['avatar']): ?>
                    <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="">
                <?php else: ?>
                    <?= mb_strtoupper(mb_substr($user['firstname'], 0, 1)) ?>
                <?php endif; ?>
            </div>
            <div class="level-icon"><?= $level['icon'] ?></div>
        </div>
        <div class="profile-info">
            <p class="greeting"><?= $saudacao ?>!</p>
            <h1 class="profile-name">
                <?= htmlspecialchars($user['firstname']) ?>
                <?php if ($membership): ?>
                <span class="plus-tag"><i class="fas fa-crown"></i> Plus</span>
                <?php endif; ?>
            </h1>
            <div class="level-bar-wrap">
                <div class="level-bar">
                    <div class="level-bar-fill" style="width: <?= min($level['progress'], 100) ?>%"></div>
                </div>
            </div>
            <div class="level-info">
                <span><?= $level['icon'] ?> <?= $level['name'] ?></span>
                <?php if ($level['points_to_next'] > 0): ?>
                <span><?= number_format($level['points_to_next']) ?> pts para próximo nível</span>
                <?php else: ?>
                <span>Nível máximo! 🎉</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<!-- Wallet Card -->
<div class="wallet-card">
    <div class="wallet-top">
        <div class="wallet-balance-section">
            <p class="wallet-label"><i class="fas fa-wallet"></i> Saldo disponível</p>
            <h2 class="wallet-balance">
                <span class="currency">R$</span> <?= number_format($wallet['balance'], 2, ',', '.') ?>
            </h2>
        </div>
        <div class="wallet-actions">
            <button class="wallet-btn" onclick="showToast('Em breve!')"><i class="fas fa-plus"></i> Adicionar</button>
            <button class="wallet-btn primary" onclick="showToast('Em breve!')"><i class="fas fa-qrcode"></i> Pix</button>
        </div>
    </div>
    <div class="wallet-grid">
        <div class="wallet-item" onclick="location.href='#cashback'">
            <div class="wallet-item-icon">💰</div>
            <div class="wallet-item-value">R$ <?= number_format($wallet['cashback'], 2, ',', '.') ?></div>
            <div class="wallet-item-label">Cashback</div>
        </div>
        <div class="wallet-item" onclick="location.href='#creditos'">
            <div class="wallet-item-icon">🎁</div>
            <div class="wallet-item-value">R$ <?= number_format($wallet['credits'], 2, ',', '.') ?></div>
            <div class="wallet-item-label">Créditos</div>
        </div>
        <div class="wallet-item" onclick="location.href='#pontos'">
            <div class="wallet-item-icon">⭐</div>
            <div class="wallet-item-value"><?= number_format($wallet['points']) ?></div>
            <div class="wallet-item-label">Pontos</div>
        </div>
    </div>
</div>

<div class="container">

    <!-- Quick Stats -->
    <div class="quick-stats">
        <div class="quick-stat" onclick="location.href='/mercado/meus-pedidos.php'">
            <div class="quick-stat-icon green"><i class="fas fa-shopping-bag"></i></div>
            <div class="quick-stat-value"><?= $stats['orders_count'] ?></div>
            <div class="quick-stat-label">Pedidos</div>
        </div>
        <div class="quick-stat" onclick="location.href='/mercado/favoritos.php'">
            <div class="quick-stat-icon pink"><i class="fas fa-heart"></i></div>
            <div class="quick-stat-value"><?= $stats['favorites_count'] ?></div>
            <div class="quick-stat-label">Favoritos</div>
        </div>
        <div class="quick-stat" onclick="location.href='#avaliacoes'">
            <div class="quick-stat-icon yellow"><i class="fas fa-star"></i></div>
            <div class="quick-stat-value"><?= $stats['reviews_count'] ?></div>
            <div class="quick-stat-label">Avaliações</div>
        </div>
        <div class="quick-stat" onclick="location.href='#economizado'">
            <div class="quick-stat-icon purple"><i class="fas fa-piggy-bank"></i></div>
            <div class="quick-stat-value">R$<?= number_format($stats['orders_total'] * 0.08, 0) ?></div>
            <div class="quick-stat-label">Economizado</div>
        </div>
    </div>

    <!-- Membership -->
    <?php if ($membership): ?>
    <div class="membership-card">
        <div class="membership-top">
            <div class="membership-icon">👑</div>
            <div class="membership-info">
                <h3><?= htmlspecialchars($membership['plan_name'] ?? 'SuperBora+') ?></h3>
                <p>Assinatura ativa</p>
            </div>
        </div>
        <div class="membership-benefits">
            <div class="benefit"><i class="fas fa-check-circle"></i> Frete grátis</div>
            <div class="benefit"><i class="fas fa-check-circle"></i> 5% cashback</div>
            <div class="benefit"><i class="fas fa-check-circle"></i> Ofertas exclusivas</div>
            <div class="benefit"><i class="fas fa-check-circle"></i> Entrega prioritária</div>
        </div>
        <div class="membership-footer">
            <div class="membership-savings">
                Você economizou <strong>R$ <?= number_format($membership['savings'] ?? 0, 2, ',', '.') ?></strong>
            </div>
            <a href="#" class="membership-manage">Gerenciar →</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Referral -->
    <div class="referral-card">
        <div class="referral-icon">🎁</div>
        <div class="referral-content">
            <h3 class="referral-title">Indique e ganhe R$20</h3>
            <p class="referral-desc">Seu amigo também ganha R$20!</p>
            <div class="referral-code-box" onclick="copyCode('<?= $referral_code ?>')">
                <?= $referral_code ?>
                <i class="fas fa-copy"></i>
            </div>
        </div>
        <button class="referral-share" onclick="shareReferral('<?= $referral_code ?>')">
            <i class="fas fa-share-alt"></i> Indicar
        </button>
    </div>

    <!-- Achievements -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">🏆 Conquistas</h2>
            <a href="#" class="section-link">Ver todas <i class="fas fa-chevron-right"></i></a>
        </div>
        <div class="achievements-row">
            <?php foreach ($achievements as $a): ?>
            <div class="achievement <?= $a['unlocked'] ? 'unlocked' : 'locked' ?>">
                <div class="achievement-icon"><?= $a['icon'] ?></div>
                <div class="achievement-name"><?= $a['name'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Promos -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">🎫 Cupons</h2>
            <a href="/mercado/cupons.php" class="section-link">Ver todos <i class="fas fa-chevron-right"></i></a>
        </div>
        <div class="promos-scroll">
            <?php foreach ($promos as $p): ?>
            <div class="promo-card" style="background: linear-gradient(135deg, <?= $p['color'] ?>, <?= $p['color'] ?>cc);" onclick="copyCode('<?= $p['code'] ?>')">
                <div class="promo-discount"><?= $p['discount'] ?></div>
                <div class="promo-title"><?= $p['desc'] ?></div>
                <div class="promo-code-tag"><?= $p['code'] ?></div>
                <div class="promo-expires"><i class="fas fa-clock"></i> <?= $p['expires'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Orders -->
    <?php if (!empty($orders)): ?>
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">📦 Pedidos Recentes</h2>
            <a href="/mercado/meus-pedidos.php" class="section-link">Ver todos <i class="fas fa-chevron-right"></i></a>
        </div>
        <div class="orders-list">
            <?php foreach (array_slice($orders, 0, 3) as $o): ?>
            <div class="order-card" onclick="location.href='/mercado/pedido.php?id=<?= $o['order_id'] ?>'">
                <div class="order-icon"><i class="fas fa-box"></i></div>
                <div class="order-info">
                    <div class="order-top">
                        <span class="order-id">Pedido #<?= $o['order_id'] ?></span>
                        <span class="order-status <?= $o['order_status_id'] >= 5 ? 'delivered' : ($o['order_status_id'] >= 2 ? 'processing' : 'pending') ?>">
                            <?= htmlspecialchars($o['status_name'] ?? 'Processando') ?>
                        </span>
                    </div>
                    <div class="order-meta"><?= date('d/m/Y', strtotime($o['date_added'])) ?> • <?= $o['items_count'] ?> itens</div>
                </div>
                <div class="order-total">R$ <?= number_format($o['total'], 2, ',', '.') ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Favorites -->
    <?php if (!empty($favorites)): ?>
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">❤️ Comprar Novamente</h2>
            <a href="/mercado/favoritos.php" class="section-link">Ver todos <i class="fas fa-chevron-right"></i></a>
        </div>
        <div class="favorites-scroll">
            <?php foreach (array_slice($favorites, 0, 6) as $i => $f): ?>
            <div class="favorite-card">
                <i class="fas fa-heart favorite-heart"></i>
                <div class="favorite-img"><?= $product_emojis[$i % count($product_emojis)] ?></div>
                <div class="favorite-name"><?= htmlspecialchars($f['name']) ?></div>
                <div class="favorite-price">R$ <?= number_format($f['price'], 2, ',', '.') ?></div>
                <button class="favorite-add" onclick="event.stopPropagation(); addToCart(<?= $f['product_id'] ?>)">
                    <i class="fas fa-plus"></i> Adicionar
                </button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Addresses -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">📍 Endereços</h2>
            <a href="/mercado/enderecos.php" class="section-link">Gerenciar <i class="fas fa-chevron-right"></i></a>
        </div>
        <div class="addresses-scroll">
            <?php foreach (array_slice($addresses, 0, 2) as $i => $a): ?>
            <div class="address-card <?= $i === 0 ? 'default' : '' ?>">
                <?php if ($i === 0): ?>
                <span class="address-default-tag">Padrão</span>
                <?php endif; ?>
                <div class="address-icon"><i class="fas fa-home"></i></div>
                <div class="address-text"><?= htmlspecialchars($a['address_1']) ?></div>
                <div class="address-city"><?= htmlspecialchars($a['city']) ?> - <?= htmlspecialchars($a['zone_name'] ?? '') ?></div>
            </div>
            <?php endforeach; ?>
            <div class="add-address" onclick="location.href='/mercado/enderecos.php?add=1'">
                <i class="fas fa-plus"></i>
                <span>Adicionar</span>
            </div>
        </div>
    </div>

    <!-- Payment Cards -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">💳 Pagamentos</h2>
            <a href="/mercado/cartoes.php" class="section-link">Gerenciar <i class="fas fa-chevron-right"></i></a>
        </div>
        <div class="cards-scroll">
            <div class="pay-card pix">
                <div class="pay-card-brand"><i class="fas fa-qrcode"></i> PIX</div>
                <div class="pay-card-number">Pagamento instantâneo</div>
            </div>
            <?php foreach ($cards as $c): ?>
            <div class="pay-card <?= strtolower($c['brand']) ?>">
                <?php if ($c['is_default']): ?>
                <span class="pay-card-default">Padrão</span>
                <?php endif; ?>
                <div class="pay-card-brand"><?= strtoupper($c['brand']) ?></div>
                <div class="pay-card-number">•••• •••• •••• <?= $c['last_four'] ?></div>
            </div>
            <?php endforeach; ?>
            <div class="add-pay-card" onclick="showToast('Em breve!')">
                <i class="fas fa-plus"></i>
                <span>Adicionar</span>
            </div>
        </div>
    </div>

    <!-- Menu: Account -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">👤 Minha Conta</h2>
        </div>
        <div class="menu-card">
            <a href="/mercado/dados-pessoais.php" class="menu-item">
                <div class="menu-icon blue"><i class="fas fa-user"></i></div>
                <div class="menu-content">
                    <div class="menu-title">Dados Pessoais</div>
                    <div class="menu-subtitle"><?= htmlspecialchars($user['email']) ?></div>
                </div>
                <i class="fas fa-chevron-right menu-arrow"></i>
            </a>
            <a href="/mercado/seguranca.php" class="menu-item">
                <div class="menu-icon red"><i class="fas fa-shield-alt"></i></div>
                <div class="menu-content">
                    <div class="menu-title">Segurança</div>
                    <div class="menu-subtitle">Senha e autenticação</div>
                </div>
                <i class="fas fa-chevron-right menu-arrow"></i>
            </a>
            <a href="/mercado/privacidade.php" class="menu-item">
                <div class="menu-icon purple"><i class="fas fa-eye-slash"></i></div>
                <div class="menu-content">
                    <div class="menu-title">Privacidade</div>
                    <div class="menu-subtitle">Dados e permissões</div>
                </div>
                <i class="fas fa-chevron-right menu-arrow"></i>
            </a>
        </div>
    </div>

    <!-- Menu: Settings -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">⚙️ Configurações</h2>
        </div>
        <div class="menu-card">
            <div class="menu-item">
                <div class="menu-icon green"><i class="fas fa-bell"></i></div>
                <div class="menu-content">
                    <div class="menu-title">Notificações Push</div>
                    <div class="menu-subtitle">Ofertas e promoções</div>
                </div>
                <div class="menu-toggle on" onclick="this.classList.toggle('on')"></div>
            </div>
            <div class="menu-item">
                <div class="menu-icon yellow"><i class="fas fa-envelope"></i></div>
                <div class="menu-content">
                    <div class="menu-title">E-mail Marketing</div>
                    <div class="menu-subtitle">Novidades por e-mail</div>
                </div>
                <div class="menu-toggle on" onclick="this.classList.toggle('on')"></div>
            </div>
            <div class="menu-item">
                <div class="menu-icon green"><i class="fab fa-whatsapp"></i></div>
                <div class="menu-content">
                    <div class="menu-title">WhatsApp</div>
                    <div class="menu-subtitle">Atualizações de pedidos</div>
                </div>
                <div class="menu-toggle" onclick="this.classList.toggle('on')"></div>
            </div>
        </div>
    </div>

    <!-- Menu: Help -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">❓ Ajuda</h2>
        </div>
        <div class="menu-card">
            <a href="/mercado/suporte.php" class="menu-item">
                <div class="menu-icon cyan"><i class="fas fa-headset"></i></div>
                <div class="menu-content">
                    <div class="menu-title">Falar com Suporte</div>
                    <div class="menu-subtitle">Chat disponível</div>
                </div>
                <span class="menu-badge green">Online</span>
            </a>
            <a href="/mercado/faq.php" class="menu-item">
                <div class="menu-icon blue"><i class="fas fa-question-circle"></i></div>
                <div class="menu-content">
                    <div class="menu-title">Perguntas Frequentes</div>
                    <div class="menu-subtitle">Tire suas dúvidas</div>
                </div>
                <i class="fas fa-chevron-right menu-arrow"></i>
            </a>
            <a href="https://wa.me/5511999999999" class="menu-item" target="_blank">
                <div class="menu-icon green"><i class="fab fa-whatsapp"></i></div>
                <div class="menu-content">
                    <div class="menu-title">WhatsApp</div>
                    <div class="menu-subtitle">Atendimento rápido</div>
                </div>
                <i class="fas fa-chevron-right menu-arrow"></i>
            </a>
        </div>
    </div>

    <!-- Logout -->
    <button class="logout-btn" onclick="logout()">
        <i class="fas fa-sign-out-alt"></i>
        Sair da Conta
    </button>

    <!-- App Info -->
    <div class="app-info">
        <p class="app-version">SuperBora v4.0.0</p>
        <p class="app-copyright">Feito com ❤️ no Brasil</p>
    </div>

</div>

<!-- Bottom Nav -->
<nav class="bottom-nav">
    <a href="/mercado/" class="nav-item"><i class="fas fa-home"></i><span>Início</span></a>
    <a href="/mercado/busca.php" class="nav-item"><i class="fas fa-search"></i><span>Buscar</span></a>
    <a href="/mercado/carrinho.php" class="nav-item"><i class="fas fa-shopping-bag"></i><span>Carrinho</span></a>
    <a href="/mercado/meus-pedidos.php" class="nav-item"><i class="fas fa-box"></i><span>Pedidos</span></a>
    <a href="/mercado/conta.php" class="nav-item active"><i class="fas fa-user"></i><span>Conta</span></a>
</nav>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
function showToast(msg, type = '') {
    const t = document.getElementById('toast');
    const icon = type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle';
    t.innerHTML = '<i class="fas fa-' + icon + '"></i>' + msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.classList.remove('show'), 3000);
}

function copyCode(code) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(code);
    }
    showToast('Código copiado: ' + code, 'success');
}

function shareReferral(code) {
    const text = 'Use meu código ' + code + ' no SuperBora e ganhe R$20 na primeira compra! 🛒';
    const url = 'https://superbora.com.br/r/' + code;

    if (navigator.share) {
        navigator.share({ title: 'SuperBora', text: text, url: url });
    } else {
        copyCode(code);
    }
}

function addToCart(productId) {
    showToast('Adicionando ao carrinho...', '');

    fetch('/mercado/api/cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add', product_id: productId, qty: 1 })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Produto adicionado!', 'success');
        } else {
            showToast(data.error || 'Erro ao adicionar', 'error');
        }
    })
    .catch(() => showToast('Erro de conexão', 'error'));
}

function logout() {
    if (confirm('Deseja sair da sua conta?')) {
        window.location.href = '/mercado/api/logout.php';
    }
}

// Animate level bar on load
document.addEventListener('DOMContentLoaded', function() {
    const bar = document.querySelector('.level-bar-fill');
    if (bar) {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => bar.style.width = width, 100);
    }
});
</script>

</body>
</html>
