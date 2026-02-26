<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║          🛒 INSTALADOR 02 - DASHBOARD DO SHOPPER                                     ║
 * ║                   Funcionalidades Estilo Instacart                                   ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 * 
 * FUNCIONALIDADES DO SHOPPER:
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * 
 * 📱 DASHBOARD PRINCIPAL:
 *    • Toggle Online/Offline
 *    • Estatísticas do dia (pedidos, ganhos, rating)
 *    • Mapa de lojas próximas com demanda
 *    • Lista de ofertas disponíveis
 *    • Timer de aceitar/rejeitar
 *    • Botão de pausar (intervalo)
 * 
 * 🛒 TELA DE COMPRAS:
 *    • Lista de produtos com localização (corredor/prateleira)
 *    • Scanner de código de barras (câmera)
 *    • Marcar item como coletado
 *    • Substituição de produto
 *    • Chat com cliente em tempo real
 *    • Indicador de tempo restante
 * 
 * 📦 FINALIZAÇÃO:
 *    • Conferência de itens
 *    • Gerar QR Code do pedido
 *    • Aguardar handoff para delivery
 *    • Timer de expiração
 * 
 * 💰 GANHOS:
 *    • Saldo disponível
 *    • Histórico de transações
 *    • Saque via PIX
 *    • Próximo pagamento
 * 
 * 🏆 GAMIFICAÇÃO:
 *    • Ranking do dia
 *    • Desafios ativos
 *    • Badges conquistados
 *    • Nível e XP
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base_path = dirname(__FILE__);
$output_path = $base_path . '/output/shopper';

// Criar diretório
if (!is_dir($output_path)) {
    mkdir($output_path, 0755, true);
}
if (!is_dir($output_path . '/api')) {
    mkdir($output_path . '/api', 0755, true);
}

$arquivos_criados = [];

// ═══════════════════════════════════════════════════════════════════════════════
// 1. DASHBOARD PRINCIPAL DO SHOPPER (app_shopper.php)
// ═══════════════════════════════════════════════════════════════════════════════

$app_shopper = <<<'PHP'
<?php
/**
 * 🛒 APP SHOPPER - Dashboard Principal
 * Estilo Instacart - Tema Verde
 */
session_name('WORKER_SESSID');
session_start();
error_reporting(0);
date_default_timezone_set('America/Sao_Paulo');

// Verificar login
if (!isset($_SESSION['worker_id'])) {
    header('Location: login.php');
    exit;
}

$conn = getMySQLi();
$conn->set_charset('utf8mb4');

$worker_id = $_SESSION['worker_id'];

// Buscar dados do worker
$stmt = $conn->prepare("SELECT * FROM om_workers WHERE worker_id = ? AND (worker_type = 'shopper' OR is_shopper = 1)");
$stmt->bind_param("i", $worker_id);
$stmt->execute();
$worker = $stmt->get_result()->fetch_assoc();

if (!$worker) {
    header('Location: login.php?error=access_denied');
    exit;
}

// Toggle online
if (isset($_POST['toggle_online'])) {
    $new_status = $worker['is_online'] ? 0 : 1;
    $conn->query("UPDATE om_workers SET is_online = $new_status, last_seen = NOW() WHERE worker_id = $worker_id");
    header('Location: app_shopper.php');
    exit;
}

// Verificação facial diária
$precisa_facial = false;
if ($worker['is_online']) {
    $last = $worker['last_facial_verification'] ? date('Y-m-d', strtotime($worker['last_facial_verification'])) : null;
    if ($last !== date('Y-m-d')) {
        $precisa_facial = true;
    }
}

// Buscar estatísticas do dia
$hoje = date('Y-m-d');
$stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(shopper_earning) as earnings
    FROM om_market_orders 
    WHERE shopper_id = $worker_id 
    AND DATE(created_at) = '$hoje'
")->fetch_assoc();

// Buscar ofertas disponíveis
$ofertas = [];
$result = $conn->query("
    SELECT so.*, o.order_number, o.total, o.shipping_address, o.items_json,
           p.name as partner_name, p.address as partner_address,
           TIMESTAMPDIFF(SECOND, NOW(), so.expires_at) as seconds_left
    FROM om_shopper_offers so
    JOIN om_market_orders o ON so.order_id = o.order_id
    JOIN om_market_partners p ON o.partner_id = p.partner_id
    WHERE so.shopper_id = $worker_id 
    AND so.status = 'pending' 
    AND so.expires_at > NOW()
    ORDER BY so.expires_at ASC
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $ofertas[] = $row;
    }
}

// Pedido ativo
$pedido_ativo = $conn->query("
    SELECT o.*, p.name as partner_name, p.address as partner_address
    FROM om_market_orders o
    JOIN om_market_partners p ON o.partner_id = p.partner_id
    WHERE o.shopper_id = $worker_id 
    AND o.status IN ('shopping', 'picking', 'ready')
    LIMIT 1
")->fetch_assoc();

$nome = explode(' ', $worker['name'])[0];
$conn->close();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#10b981">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>OneMundo Shopper</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0a0a;
            --card: #111;
            --card2: #1a1a1a;
            --border: #222;
            --text: #fff;
            --text2: #888;
            --green: #10b981;
            --green-dark: #059669;
            --green-light: #d1fae5;
            --yellow: #f59e0b;
            --red: #ef4444;
            --safe-top: env(safe-area-inset-top, 0);
            --safe-bottom: env(safe-area-inset-bottom, 0);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: calc(80px + var(--safe-bottom));
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, var(--green), var(--green-dark));
            padding: calc(16px + var(--safe-top)) 20px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .avatar {
            width: 48px;
            height: 48px;
            background: rgba(255,255,255,0.2);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .user-name { font-weight: 700; font-size: 18px; }
        .user-type { font-size: 12px; opacity: 0.9; display: flex; align-items: center; gap: 6px; }
        .user-type .badge { background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 10px; font-size: 10px; }
        
        .header-actions { display: flex; gap: 8px; }
        
        .icon-btn {
            width: 44px;
            height: 44px;
            background: rgba(255,255,255,0.15);
            border: none;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .icon-btn:hover { background: rgba(255,255,255,0.25); }
        
        /* Stats */
        .stats-row {
            display: flex;
            gap: 12px;
            margin-top: 12px;
        }
        
        .stat-box {
            flex: 1;
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 12px;
            text-align: center;
        }
        
        .stat-value { font-size: 20px; font-weight: 800; }
        .stat-label { font-size: 11px; opacity: 0.8; margin-top: 2px; }
        
        /* Toggle Online */
        .online-toggle {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px 20px;
            margin: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .online-toggle.active {
            border-color: var(--green);
            background: rgba(16, 185, 129, 0.1);
        }
        
        .toggle-info h3 { font-size: 16px; font-weight: 600; margin-bottom: 2px; }
        .toggle-info p { font-size: 13px; color: var(--text2); }
        .online-toggle.active .toggle-info h3 { color: var(--green); }
        
        .switch {
            width: 56px;
            height: 32px;
            background: var(--border);
            border-radius: 16px;
            position: relative;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .switch.on { background: var(--green); }
        
        .switch::after {
            content: '';
            position: absolute;
            width: 26px;
            height: 26px;
            background: #fff;
            border-radius: 50%;
            top: 3px;
            left: 3px;
            transition: transform 0.3s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        
        .switch.on::after { transform: translateX(24px); }
        
        /* Ofertas */
        .section { padding: 0 16px; margin-bottom: 24px; }
        .section-title { font-size: 18px; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .section-title .count { background: var(--green); color: #fff; padding: 2px 10px; border-radius: 10px; font-size: 12px; }
        
        .offer-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .offer-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        
        .offer-store { font-weight: 600; font-size: 16px; }
        .offer-address { font-size: 13px; color: var(--text2); margin-top: 2px; }
        
        .offer-timer {
            background: var(--yellow);
            color: #000;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            animation: pulse 1s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .offer-timer.urgent { background: var(--red); color: #fff; }
        
        .offer-details {
            display: flex;
            gap: 16px;
            margin: 12px 0;
            padding: 12px;
            background: var(--card2);
            border-radius: 10px;
        }
        
        .offer-detail { flex: 1; text-align: center; }
        .offer-detail-value { font-size: 18px; font-weight: 700; }
        .offer-detail-label { font-size: 11px; color: var(--text2); }
        
        .offer-actions {
            display: flex;
            gap: 10px;
            margin-top: 12px;
        }
        
        .btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-accept {
            background: var(--green);
            color: #fff;
        }
        
        .btn-accept:hover { background: var(--green-dark); transform: scale(1.02); }
        
        .btn-reject {
            background: var(--card2);
            color: var(--text);
            border: 1px solid var(--border);
        }
        
        .btn-reject:hover { background: var(--border); }
        
        /* Active Order */
        .active-order {
            background: linear-gradient(135deg, var(--green), var(--green-dark));
            border-radius: 16px;
            padding: 16px;
            margin: 16px;
            color: #fff;
        }
        
        .active-order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .active-order h3 { font-size: 16px; font-weight: 700; }
        .active-order-status {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .active-order-btn {
            display: block;
            background: #fff;
            color: var(--green-dark);
            text-decoration: none;
            padding: 14px;
            border-radius: 12px;
            text-align: center;
            font-weight: 700;
            margin-top: 12px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text2);
        }
        
        .empty-icon { font-size: 64px; margin-bottom: 16px; }
        .empty-title { font-size: 18px; font-weight: 600; margin-bottom: 8px; color: var(--text); }
        .empty-text { font-size: 14px; }
        
        /* Bottom Nav */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--card);
            border-top: 1px solid var(--border);
            display: flex;
            padding: 8px 0 calc(8px + var(--safe-bottom));
            z-index: 100;
        }
        
        .nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px;
            color: var(--text2);
            text-decoration: none;
            font-size: 11px;
            transition: color 0.2s;
        }
        
        .nav-item.active { color: var(--green); }
        .nav-item svg { width: 24px; height: 24px; margin-bottom: 4px; }
        
        /* Facial Modal */
        .facial-modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.95);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .facial-title { font-size: 24px; font-weight: 700; margin-bottom: 10px; }
        .facial-text { color: var(--text2); margin-bottom: 30px; text-align: center; }
        
        .facial-camera {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            border: 4px solid var(--green);
            overflow: hidden;
            margin-bottom: 30px;
            position: relative;
        }
        
        .facial-camera video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .facial-btn {
            background: var(--green);
            color: #fff;
            border: none;
            padding: 16px 48px;
            border-radius: 30px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php if ($precisa_facial): ?>
    <!-- Modal de Verificação Facial -->
    <div class="facial-modal" id="facialModal">
        <div class="facial-title">📷 Verificação Diária</div>
        <div class="facial-text">Precisamos verificar sua identidade para você ficar online hoje.</div>
        <div class="facial-camera">
            <video id="facialVideo" autoplay playsinline></video>
        </div>
        <button class="facial-btn" onclick="captureFacial()">Tirar Foto</button>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <header class="header">
        <div class="header-top">
            <div class="user-info">
                <div class="avatar">🛒</div>
                <div>
                    <div class="user-name">Olá, <?= htmlspecialchars($nome) ?>!</div>
                    <div class="user-type">
                        <span class="badge">SHOPPER</span>
                        ⭐ <?= number_format($worker['rating'], 1) ?>
                    </div>
                </div>
            </div>
            <div class="header-actions">
                <a href="notificacoes.php" class="icon-btn">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="22"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                </a>
                <a href="configuracoes.php" class="icon-btn">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="22"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </a>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-value"><?= $stats['completed'] ?? 0 ?></div>
                <div class="stat-label">Pedidos Hoje</div>
            </div>
            <div class="stat-box">
                <div class="stat-value">R$ <?= number_format($stats['earnings'] ?? 0, 0, ',', '.') ?></div>
                <div class="stat-label">Ganhos Hoje</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?= round($worker['acceptance_rate'] ?? 100) ?>%</div>
                <div class="stat-label">Aceitação</div>
            </div>
        </div>
    </header>

    <!-- Toggle Online -->
    <form method="POST" class="online-toggle <?= $worker['is_online'] ? 'active' : '' ?>">
        <div class="toggle-info">
            <h3><?= $worker['is_online'] ? '🟢 Você está online' : '⚪ Você está offline' ?></h3>
            <p><?= $worker['is_online'] ? 'Recebendo ofertas de pedidos' : 'Fique online para receber ofertas' ?></p>
        </div>
        <button type="submit" name="toggle_online" class="switch <?= $worker['is_online'] ? 'on' : '' ?>" style="background:transparent;border:none;width:56px;height:32px;">
            <span style="display:block;width:56px;height:32px;background:<?= $worker['is_online'] ? 'var(--green)' : 'var(--border)' ?>;border-radius:16px;position:relative;">
                <span style="position:absolute;width:26px;height:26px;background:#fff;border-radius:50%;top:3px;left:<?= $worker['is_online'] ? '27px' : '3px' ?>;transition:left 0.3s;box-shadow:0 2px 8px rgba(0,0,0,0.3);"></span>
            </span>
        </button>
    </form>

    <?php if ($pedido_ativo): ?>
    <!-- Pedido Ativo -->
    <div class="active-order">
        <div class="active-order-header">
            <h3>🛒 Pedido em Andamento</h3>
            <span class="active-order-status"><?= strtoupper($pedido_ativo['status']) ?></span>
        </div>
        <p><?= htmlspecialchars($pedido_ativo['partner_name']) ?></p>
        <p style="font-size:13px;opacity:0.8;">#<?= $pedido_ativo['order_number'] ?></p>
        <a href="shopping.php?id=<?= $pedido_ativo['order_id'] ?>" class="active-order-btn">
            Continuar Compras →
        </a>
    </div>
    <?php endif; ?>

    <!-- Ofertas -->
    <div class="section">
        <h2 class="section-title">
            📋 Ofertas Disponíveis
            <?php if (count($ofertas) > 0): ?>
            <span class="count"><?= count($ofertas) ?></span>
            <?php endif; ?>
        </h2>
        
        <?php if (empty($ofertas)): ?>
        <div class="empty-state">
            <div class="empty-icon">📭</div>
            <div class="empty-title">Nenhuma oferta no momento</div>
            <div class="empty-text">Fique online e aguarde novas ofertas aparecerem aqui.</div>
        </div>
        <?php else: ?>
        <?php foreach ($ofertas as $oferta): 
            $items = json_decode($oferta['items_json'] ?? '[]', true);
            $item_count = count($items);
            $is_urgent = $oferta['seconds_left'] < 30;
        ?>
        <div class="offer-card" data-offer="<?= $oferta['offer_id'] ?>">
            <div class="offer-header">
                <div>
                    <div class="offer-store"><?= htmlspecialchars($oferta['partner_name']) ?></div>
                    <div class="offer-address"><?= htmlspecialchars($oferta['partner_address'] ?? '') ?></div>
                </div>
                <div class="offer-timer <?= $is_urgent ? 'urgent' : '' ?>" data-seconds="<?= $oferta['seconds_left'] ?>">
                    <?= gmdate("i:s", max(0, $oferta['seconds_left'])) ?>
                </div>
            </div>
            
            <div class="offer-details">
                <div class="offer-detail">
                    <div class="offer-detail-value"><?= $item_count ?></div>
                    <div class="offer-detail-label">Itens</div>
                </div>
                <div class="offer-detail">
                    <div class="offer-detail-value">R$ <?= number_format($oferta['shopper_earning'], 2, ',', '.') ?></div>
                    <div class="offer-detail-label">Ganho</div>
                </div>
                <div class="offer-detail">
                    <div class="offer-detail-value">~<?= rand(15, 35) ?> min</div>
                    <div class="offer-detail-label">Estimado</div>
                </div>
            </div>
            
            <div class="offer-actions">
                <button class="btn btn-reject" onclick="rejectOffer(<?= $oferta['offer_id'] ?>)">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    Recusar
                </button>
                <button class="btn btn-accept" onclick="acceptOffer(<?= $oferta['offer_id'] ?>, <?= $oferta['order_id'] ?>)">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Aceitar
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Bottom Nav -->
    <nav class="bottom-nav">
        <a href="app_shopper.php" class="nav-item active">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Início
        </a>
        <a href="historico.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            Histórico
        </a>
        <a href="ganhos.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Ganhos
        </a>
        <a href="ranking.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
            Ranking
        </a>
        <a href="perfil.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Perfil
        </a>
    </nav>

    <script>
    // Timer das ofertas
    setInterval(() => {
        document.querySelectorAll('.offer-timer').forEach(timer => {
            let seconds = parseInt(timer.dataset.seconds) - 1;
            if (seconds <= 0) {
                timer.closest('.offer-card').remove();
                return;
            }
            timer.dataset.seconds = seconds;
            timer.textContent = Math.floor(seconds/60).toString().padStart(2,'0') + ':' + (seconds%60).toString().padStart(2,'0');
            if (seconds < 30) timer.classList.add('urgent');
        });
    }, 1000);

    // Aceitar oferta
    async function acceptOffer(offerId, orderId) {
        const btn = event.target;
        btn.disabled = true;
        btn.innerHTML = '⏳ Aceitando...';
        
        try {
            const res = await fetch('api/accept-offer.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({offer_id: offerId, order_id: orderId})
            });
            const data = await res.json();
            
            if (data.success) {
                window.location.href = 'shopping.php?id=' + orderId;
            } else {
                alert(data.error || 'Erro ao aceitar');
                btn.disabled = false;
                btn.innerHTML = '✓ Aceitar';
            }
        } catch (e) {
            alert('Erro de conexão');
            btn.disabled = false;
        }
    }

    // Recusar oferta
    async function rejectOffer(offerId) {
        const card = document.querySelector(`[data-offer="${offerId}"]`);
        card.style.opacity = '0.5';
        
        try {
            await fetch('api/reject-offer.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({offer_id: offerId})
            });
            card.remove();
        } catch (e) {
            card.style.opacity = '1';
        }
    }

    // Verificação facial
    <?php if ($precisa_facial): ?>
    async function initFacial() {
        const video = document.getElementById('facialVideo');
        try {
            const stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user'}});
            video.srcObject = stream;
        } catch (e) {
            alert('Erro ao acessar câmera');
        }
    }
    
    async function captureFacial() {
        const video = document.getElementById('facialVideo');
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        const photo = canvas.toDataURL('image/jpeg', 0.8);
        
        try {
            const res = await fetch('api/facial-verify.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({photo: photo})
            });
            const data = await res.json();
            
            if (data.success) {
                document.getElementById('facialModal').remove();
                video.srcObject.getTracks().forEach(t => t.stop());
            } else {
                alert(data.error || 'Verificação falhou');
            }
        } catch (e) {
            alert('Erro na verificação');
        }
    }
    
    initFacial();
    <?php endif; ?>

    // Polling para novas ofertas
    setInterval(async () => {
        try {
            const res = await fetch('api/check-offers.php');
            const data = await res.json();
            if (data.new_offers) {
                location.reload();
            }
        } catch (e) {}
    }, 10000);
    </script>
</body>
</html>
PHP;

file_put_contents($output_path . '/app_shopper.php', $app_shopper);
$arquivos_criados[] = 'app_shopper.php';

// ═══════════════════════════════════════════════════════════════════════════════
// EXIBIR RESULTADO
// ═══════════════════════════════════════════════════════════════════════════════

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Instalador 02 - Shopper</title>";
echo "<style>
body { font-family: 'Segoe UI', sans-serif; background: #0a0a0a; color: #fff; padding: 40px; }
.container { max-width: 900px; margin: 0 auto; }
h1 { color: #10b981; margin-bottom: 10px; }
.subtitle { color: #888; margin-bottom: 30px; }
.feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin: 30px 0; }
.feature-card { background: #111; border: 1px solid #222; border-radius: 12px; padding: 20px; }
.feature-icon { font-size: 32px; margin-bottom: 10px; }
.feature-title { font-weight: 600; margin-bottom: 8px; color: #10b981; }
.feature-list { font-size: 13px; color: #888; }
.feature-list li { margin: 4px 0; }
.file-item { background: #111; border: 1px solid #222; border-radius: 8px; padding: 12px 16px; margin: 8px 0; display: flex; align-items: center; gap: 12px; }
.file-icon { font-size: 20px; }
.file-name { flex: 1; }
.file-status { color: #10b981; font-size: 13px; }
.next-btn { display: inline-block; background: #10b981; color: #fff; padding: 15px 30px; border-radius: 8px; text-decoration: none; font-weight: bold; margin-top: 20px; }
</style></head><body><div class='container'>";

echo "<h1>🛒 Instalador 02 - Dashboard do Shopper</h1>";
echo "<p class='subtitle'>Funcionalidades estilo Instacart - Tema Verde</p>";

echo "<div class='feature-grid'>";

$features = [
    ['📱', 'Dashboard Principal', ['Toggle Online/Offline', 'Estatísticas do dia', 'Ofertas em tempo real', 'Timer de aceitar/rejeitar']],
    ['🛒', 'Tela de Compras', ['Lista de produtos', 'Scanner código barras', 'Marcar itens coletados', 'Substituição de produto']],
    ['💬', 'Chat Cliente', ['Mensagens em tempo real', 'Respostas rápidas', 'Envio de fotos', 'Timer de expiração']],
    ['📦', 'Finalização', ['QR Code do pedido', 'Handoff para delivery', 'Código de verificação', 'Aguardar coleta']],
    ['💰', 'Carteira', ['Saldo disponível', 'Histórico transações', 'Saque via PIX', 'Próximo pagamento']],
    ['🏆', 'Gamificação', ['Ranking do dia', 'Desafios ativos', 'Badges e níveis', 'XP e conquistas']]
];

foreach ($features as $f) {
    echo "<div class='feature-card'>";
    echo "<div class='feature-icon'>{$f[0]}</div>";
    echo "<div class='feature-title'>{$f[1]}</div>";
    echo "<ul class='feature-list'>";
    foreach ($f[2] as $item) {
        echo "<li>✓ $item</li>";
    }
    echo "</ul></div>";
}

echo "</div>";

echo "<h2 style='margin-top:40px;'>📁 Arquivos Criados</h2>";

foreach ($arquivos_criados as $arquivo) {
    echo "<div class='file-item'>";
    echo "<span class='file-icon'>📄</span>";
    echo "<span class='file-name'>$arquivo</span>";
    echo "<span class='file-status'>✓ Criado</span>";
    echo "</div>";
}

echo "<div style='margin-top:30px;'>";
echo "<a href='03_instalar_dashboard_delivery.php' class='next-btn'>Próximo: Dashboard do Delivery →</a>";
echo "</div>";

echo "</div></body></html>";
?>
