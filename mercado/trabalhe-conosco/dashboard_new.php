<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * 📱 DASHBOARD PREMIUM - OneMundo Shopper App
 * Funciona standalone!
 */

session_start();

// Verificar login - suporta ambas as sessões
$workerId = $_SESSION['worker_id'] ?? null;
if (!$workerId) {
    header('Location: login.php');
    exit;
}

// Conexão direta
try {
    $pdo = getPDO();
} catch (PDOException $e) {
    die("Erro de conexão");
}

// Buscar worker - tentar om_market_workers primeiro, depois om_workers
$worker = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch();
} catch (Exception $e) {}

if (!$worker) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM om_workers WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
    } catch (Exception $e) {}
}

if (!$worker) {
    header('Location: login.php');
    exit;
}

// Buscar/criar shopper
$shopper_id = $_SESSION['shopper_id'] ?? null;
$shopper = null;

if ($shopper_id) {
    $stmt = $pdo->prepare("SELECT * FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $shopper = $stmt->fetch();
}

if (!$shopper && !empty($worker['email'])) {
    $stmt = $pdo->prepare("SELECT * FROM om_market_shoppers WHERE email = ?");
    $stmt->execute([$worker['email']]);
    $shopper = $stmt->fetch();
    if ($shopper) {
        $_SESSION['shopper_id'] = $shopper['shopper_id'];
        $shopper_id = $shopper['shopper_id'];
    }
}

// Toggle online via POST
if (isset($_POST['toggle_online']) && $shopper) {
    $current = $shopper['is_online'] ?? 0;
    $new_status = $current ? 0 : 1;
    $pdo->prepare("UPDATE om_market_shoppers SET is_online = ?, last_seen = NOW() WHERE shopper_id = ?")
        ->execute([$new_status, $shopper_id]);
    header('Location: dashboard_new.php');
    exit;
}

// AUTO-CRIAR TABELAS NECESSÁRIAS
function tblExists($pdo, $t) {
    try { $pdo->query("SELECT 1 FROM $t LIMIT 1"); return true; } catch (Exception $e) { return false; }
}

if (!tblExists($pdo, 'om_worker_tiers')) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_worker_tiers (tier_id INT AUTO_INCREMENT PRIMARY KEY, tier_name VARCHAR(50), tier_slug VARCHAR(30), tier_level INT DEFAULT 1, icon VARCHAR(10) DEFAULT '⭐', color VARCHAR(7) DEFAULT '#FFD700', min_deliveries INT DEFAULT 0, earnings_bonus_percent DECIMAL(5,2) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO om_worker_tiers (tier_name, tier_slug, tier_level, icon, color, min_deliveries, earnings_bonus_percent) VALUES ('Bronze','bronze',1,'🥉','#CD7F32',0,0),('Prata','silver',2,'🥈','#C0C0C0',50,5),('Ouro','gold',3,'🥇','#FFD700',200,10),('Platina','platinum',4,'💎','#E5E4E2',500,15),('Diamante','diamond',5,'👑','#B9F2FF',1000,30)");
}

if (!tblExists($pdo, 'om_daily_goals')) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_daily_goals (goal_id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(100), required_deliveries INT, guaranteed_amount DECIMAL(10,2), is_active TINYINT(1) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO om_daily_goals (title, required_deliveries, guaranteed_amount) VALUES ('Meta Básica',10,100),('Meta Shopper',15,150),('Super Meta',20,250)");
}

if (!tblExists($pdo, 'om_hotspots')) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_hotspots (hotspot_id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), demand_level VARCHAR(20) DEFAULT 'medium', estimated_wait_minutes INT DEFAULT 15) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO om_hotspots (name, demand_level, estimated_wait_minutes) VALUES ('Centro','high',10),('Shopping','very_high',5),('Zona Sul','medium',15),('Zona Norte','high',8)");
}

if (!tblExists($pdo, 'om_batches')) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS om_batches (batch_id INT AUTO_INCREMENT PRIMARY KEY, batch_code VARCHAR(20), status VARCHAR(20) DEFAULT 'available', total_items INT DEFAULT 0, total_distance_km DECIMAL(10,2) DEFAULT 0, estimated_time_minutes INT DEFAULT 30, total_earnings DECIMAL(10,2) DEFAULT 0, total_tips DECIMAL(10,2) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO om_batches (batch_code, total_items, total_distance_km, estimated_time_minutes, total_earnings, total_tips) VALUES ('OM".date('ymd')."001',15,3.5,45,20,5),('OM".date('ymd')."002',8,2,30,15,3),('OM".date('ymd')."003',25,5,60,28,8)");
}

// Determinar tipo de worker
$tipo = 'Shopper';
if (($worker['is_driver'] ?? $worker['is_delivery'] ?? 0) && ($worker['is_shopper'] ?? 0)) $tipo = 'Full Service';
elseif ($worker['is_driver'] ?? $worker['is_delivery'] ?? 0) $tipo = 'Entregador';

// Tier
$tierName = 'Bronze'; $tierIcon = '🥉'; $tierBonus = 0;
try {
    $stmt = $pdo->query("SELECT * FROM om_worker_tiers WHERE tier_level = 1 LIMIT 1");
    $tier = $stmt->fetch();
    if ($tier) { $tierName = $tier['tier_name']; $tierIcon = $tier['icon']; }
} catch (Exception $e) {}

// Stats de hoje
$todayEarnings = 0;
$todayDeliveries = 0;
if ($shopper_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_orders, COALESCE(SUM(shopper_earning), 0) as total_earnings FROM om_market_orders WHERE shopper_id = ? AND status = 'delivered' AND DATE(delivered_at) = CURRENT_DATE");
        $stmt->execute([$shopper_id]);
        $stats = $stmt->fetch();
        $todayEarnings = $stats['total_earnings'] ?? 0;
        $todayDeliveries = $stats['total_orders'] ?? 0;
    } catch (Exception $e) {}
}

// Ofertas disponíveis
$ofertas = [];
if ($shopper_id && $shopper) {
    try {
        $partner_id = $shopper['partner_id'] ?? 1;
        $stmt = $pdo->prepare("
            SELECT so.*, o.order_number, o.total, o.shipping_address,
                   p.name as partner_name
            FROM om_shopper_offers so
            JOIN om_market_orders o ON so.order_id = o.order_id
            JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE so.status = 'pending' AND so.expires_at > NOW()
              AND o.partner_id = ? AND o.shopper_id IS NULL
            ORDER BY so.shopper_earning DESC LIMIT 10
        ");
        $stmt->execute([$partner_id]);
        $ofertas = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// Meta diária
$dailyGoal = null;
try {
    $stmt = $pdo->query("SELECT * FROM om_daily_goals WHERE is_active = 1 ORDER BY guaranteed_amount ASC LIMIT 1");
    $dailyGoal = $stmt->fetch();
} catch (Exception $e) {}

// Hotspots
$hotspots = [];
try {
    $stmt = $pdo->query("SELECT * FROM om_hotspots WHERE demand_level IN ('high','very_high') LIMIT 4");
    $hotspots = $stmt->fetchAll();
} catch (Exception $e) {}

// Batches
$batches = [];
try {
    $stmt = $pdo->query("SELECT * FROM om_batches WHERE status = 'available' ORDER BY total_earnings DESC LIMIT 5");
    $batches = $stmt->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#108910">
<title>Dashboard - OneMundo <?= $tipo ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--green:#108910;--green-dark:#0D6B0D;--green-light:#E8F5E8;--orange:#FF5500;--orange-light:#FFF3ED;--red:#dc2626;--blue:#3B82F6;--blue-light:#EFF6FF;--purple:#8B5CF6;--purple-light:#F3E8FF;--yellow:#F59E0B;--yellow-light:#FEF3C7;--gray-900:#1C1C1C;--gray-500:#6B7280;--gray-400:#9CA3AF;--gray-200:#E5E7EB;--gray-100:#F3F4F6;--white:#FFF;--safe-top:env(safe-area-inset-top,0);--safe-bottom:env(safe-area-inset-bottom,0)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',-apple-system,sans-serif;background:var(--gray-100);min-height:100vh;padding-bottom:calc(80px + var(--safe-bottom));color:var(--gray-900)}
.header{background:linear-gradient(135deg,var(--green),var(--green-dark));padding:16px 20px;padding-top:calc(16px + var(--safe-top));color:#fff}
.header-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.header-left{display:flex;align-items:center;gap:12px}
.avatar{width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700}
.greeting{font-size:14px;opacity:.9}
.worker-name{font-size:18px;font-weight:700}
.tier-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(255,255,255,.2);padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;margin-top:4px}
.header-btn{width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.15);border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none}
.header-btn svg{width:22px;height:22px;color:#fff}
.online-toggle{display:flex;align-items:center;gap:12px;background:rgba(255,255,255,.1);padding:12px 16px;border-radius:14px}
.toggle-label{flex:1}
.toggle-status{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:600}
.status-dot{width:10px;height:10px;border-radius:50%;background:#4ade80}
.status-dot.offline{background:var(--gray-400)}
.toggle-sub{font-size:12px;opacity:.8;margin-top:2px}
.toggle-switch{width:52px;height:28px;background:rgba(255,255,255,.2);border-radius:14px;position:relative;cursor:pointer}
.toggle-switch.active{background:#4ade80}
.toggle-switch::after{content:'';position:absolute;width:24px;height:24px;background:#fff;border-radius:50%;top:2px;left:2px;transition:.3s}
.toggle-switch.active::after{transform:translateX(24px)}
.card{background:#fff;margin:16px;border-radius:20px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,.06)}
.earnings-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.earnings-title{font-size:14px;color:var(--gray-500)}
.earnings-value{font-size:48px;font-weight:800;margin-bottom:16px}
.earnings-value small{font-size:24px}
.earnings-breakdown{display:flex;gap:24px}
.breakdown-item{display:flex;align-items:center;gap:8px}
.breakdown-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px}
.breakdown-icon.green{background:var(--green-light)}
.breakdown-icon.yellow{background:var(--yellow-light)}
.breakdown-icon.purple{background:var(--purple-light)}
.breakdown-label{font-size:12px;color:var(--gray-500)}
.breakdown-value{font-size:15px;font-weight:700}
.goal-card{background:linear-gradient(135deg,#667eea,#764ba2);margin:0 16px 16px;border-radius:16px;padding:20px;color:#fff}
.goal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.goal-title{font-size:16px;font-weight:700}
.goal-reward{background:rgba(255,255,255,.2);padding:6px 14px;border-radius:20px;font-size:14px;font-weight:700}
.goal-progress{height:10px;background:rgba(255,255,255,.2);border-radius:5px;overflow:hidden;margin-bottom:12px}
.goal-fill{height:100%;background:#fff;border-radius:5px}
.goal-stats{display:flex;justify-content:space-between;font-size:14px}
.quick-actions{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:0 16px;margin-bottom:16px}
.quick-action{display:flex;flex-direction:column;align-items:center;gap:8px;padding:16px 8px;background:#fff;border-radius:16px;text-decoration:none;color:var(--gray-900)}
.quick-action-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px}
.quick-action-icon.green{background:var(--green-light)}
.quick-action-icon.orange{background:var(--orange-light)}
.quick-action-icon.purple{background:var(--purple-light)}
.quick-action-icon.blue{background:var(--blue-light)}
.quick-action-label{font-size:12px;font-weight:600}
.section{padding:0 16px;margin-bottom:20px}
.section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.section-title{font-size:18px;font-weight:700}
.section-link{font-size:14px;color:var(--green);font-weight:600;text-decoration:none}
.batches-scroll{display:flex;gap:12px;overflow-x:auto;padding-bottom:8px;scrollbar-width:none}
.batches-scroll::-webkit-scrollbar{display:none}
.batch-card{flex-shrink:0;width:280px;background:#fff;border-radius:16px;padding:16px}
.batch-header{display:flex;justify-content:space-between;margin-bottom:12px}
.batch-store{display:flex;align-items:center;gap:10px}
.batch-store-icon{width:40px;height:40px;background:var(--green-light);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px}
.batch-store-name{font-size:14px;font-weight:600}
.batch-store-sub{font-size:12px;color:var(--gray-500)}
.batch-total{font-size:20px;font-weight:800;color:var(--green)}
.batch-tip{font-size:11px;color:var(--gray-500)}
.batch-details{display:flex;gap:16px;margin-bottom:12px;font-size:13px;color:#4B5563}
.batch-accept{width:100%;padding:12px;background:var(--green);color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer}
.hotspots-scroll{display:flex;gap:8px;overflow-x:auto;scrollbar-width:none}
.hotspots-scroll::-webkit-scrollbar{display:none}
.hotspot-chip{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#fff;border-radius:12px;white-space:nowrap;flex-shrink:0}
.hotspot-dot{width:10px;height:10px;border-radius:50%}
.hotspot-dot.very_high{background:var(--red);animation:pulse 1.5s infinite}
.hotspot-dot.high{background:var(--orange)}
.hotspot-dot.medium{background:var(--yellow)}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.3)}}
.hotspot-name{font-size:13px;font-weight:600}
.hotspot-wait{font-size:11px;color:var(--gray-500)}
.offers-card{background:#fff;border-radius:16px;padding:16px;margin-bottom:12px}
.offer-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.offer-title{font-weight:600}
.offer-value{font-size:18px;font-weight:800;color:var(--green)}
.offer-details{font-size:13px;color:var(--gray-500);margin-bottom:12px}
.offer-btn{width:100%;padding:10px;background:var(--green);color:#fff;border:none;border-radius:10px;font-weight:600;cursor:pointer}
.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:#fff;padding:8px 16px;padding-bottom:calc(8px + var(--safe-bottom));display:flex;justify-content:space-around;border-top:1px solid var(--gray-200);z-index:100}
.nav-item{display:flex;flex-direction:column;align-items:center;gap:4px;text-decoration:none;color:var(--gray-400);padding:8px 16px;border-radius:12px}
.nav-item.active{color:var(--green);background:var(--green-light)}
.nav-item svg{width:24px;height:24px}
.nav-item span{font-size:11px;font-weight:600}
</style>
</head>
<body>
<header class="header">
<div class="header-top">
<div class="header-left">
<div class="avatar"><?=strtoupper(substr($worker['name']??'U',0,1))?></div>
<div>
<div class="greeting">Olá,</div>
<div class="worker-name"><?=htmlspecialchars($worker['name']??'Usuário')?></div>
<div class="tier-badge"><?=$tierIcon?> <?=$tierName?> • <?=$tipo?></div>
</div>
</div>
<a href="notificacoes.php" class="header-btn"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg></a>
</div>
<form method="POST" class="online-toggle">
<input type="hidden" name="toggle_online" value="1">
<div class="toggle-label">
<div class="toggle-status"><span class="status-dot <?=($shopper['is_online']??0)?'':'offline'?>"></span><span><?=($shopper['is_online']??0)?'Online':'Offline'?></span></div>
<div class="toggle-sub"><?=($shopper['is_online']??0)?'Recebendo pedidos':'Você está invisível'?></div>
</div>
<button type="submit" class="toggle-switch <?=($shopper['is_online']??0)?'active':''?>" style="border:none"></button>
</form>
</header>

<div class="card">
<div class="earnings-header"><span class="earnings-title">Ganhos de Hoje</span></div>
<div class="earnings-value"><small>R$</small><?=number_format($todayEarnings,2,',','.')?></div>
<div class="earnings-breakdown">
<div class="breakdown-item"><div class="breakdown-icon green">📦</div><div><div class="breakdown-label">Pedidos</div><div class="breakdown-value"><?=$todayDeliveries?></div></div></div>
<div class="breakdown-item"><div class="breakdown-icon purple">⭐</div><div><div class="breakdown-label">Avaliação</div><div class="breakdown-value"><?=number_format($shopper['rating']??5,1)?></div></div></div>
</div>
</div>

<?php if($dailyGoal):$gp=$dailyGoal['required_deliveries']>0?min(100,($todayDeliveries/$dailyGoal['required_deliveries'])*100):0;?>
<div class="goal-card">
<div class="goal-header"><span class="goal-title">🎯 <?=htmlspecialchars($dailyGoal['title'])?></span><span class="goal-reward">R$<?=number_format($dailyGoal['guaranteed_amount'],0)?></span></div>
<div class="goal-progress"><div class="goal-fill" style="width:<?=$gp?>%"></div></div>
<div class="goal-stats"><span><?=$todayDeliveries?>/<?=$dailyGoal['required_deliveries']?> pedidos</span><span>Faltam <?=max(0,$dailyGoal['required_deliveries']-$todayDeliveries)?></span></div>
</div>
<?php endif;?>

<div class="quick-actions">
<a href="ofertas.php" class="quick-action"><div class="quick-action-icon green">📋</div><span class="quick-action-label">Ofertas</span></a>
<a href="ganhos.php" class="quick-action"><div class="quick-action-icon orange">💰</div><span class="quick-action-label">Ganhos</span></a>
<a href="desafios.php" class="quick-action"><div class="quick-action-icon purple">🏆</div><span class="quick-action-label">Desafios</span></a>
<a href="perfil.php" class="quick-action"><div class="quick-action-icon blue">👤</div><span class="quick-action-label">Perfil</span></a>
</div>

<?php if(!empty($ofertas)):?>
<div class="section">
<div class="section-header"><h2 class="section-title">🔥 Ofertas Disponíveis</h2><a href="ofertas.php" class="section-link">Ver todas →</a></div>
<?php foreach(array_slice($ofertas,0,3) as $o):?>
<div class="offers-card">
<div class="offer-header">
<span class="offer-title"><?=htmlspecialchars($o['partner_name']??'Pedido')?> #<?=$o['order_number']??''?></span>
<span class="offer-value">R$<?=number_format($o['shopper_earning']??0,2,',','.')?></span>
</div>
<div class="offer-details">📍 <?=htmlspecialchars(substr($o['shipping_address']??'',0,50))?>...</div>
<form method="POST" action="api/accept-offer.php"><input type="hidden" name="offer_id" value="<?=$o['offer_id']??''?>"><button type="submit" class="offer-btn">✓ Aceitar Oferta</button></form>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<?php if(!empty($batches)):?>
<div class="section">
<div class="section-header"><h2 class="section-title">📦 Pedidos Agrupados</h2></div>
<div class="batches-scroll">
<?php foreach($batches as $b):?>
<div class="batch-card">
<div class="batch-header">
<div class="batch-store"><div class="batch-store-icon">🛒</div><div><div class="batch-store-name">#<?=$b['batch_code']?></div><div class="batch-store-sub"><?=$b['total_items']?> itens</div></div></div>
<div><div class="batch-total">R$<?=number_format($b['total_earnings'],2,',','.')?></div><?php if($b['total_tips']>0):?><div class="batch-tip">+R$<?=number_format($b['total_tips'],2)?> gorjeta</div><?php endif;?></div>
</div>
<div class="batch-details"><span>📍 <?=number_format($b['total_distance_km'],1)?>km</span><span>⏱️ ~<?=$b['estimated_time_minutes']?>min</span></div>
<button class="batch-accept" onclick="alert('✅ Funcionalidade em breve!')">✓ Aceitar</button>
</div>
<?php endforeach;?>
</div>
</div>
<?php endif;?>

<?php if(!empty($hotspots)):?>
<div class="section">
<div class="section-header"><h2 class="section-title">🔥 Áreas em Alta</h2><a href="mapa-calor.php" class="section-link">Ver mapa →</a></div>
<div class="hotspots-scroll">
<?php foreach($hotspots as $h):?>
<div class="hotspot-chip"><span class="hotspot-dot <?=$h['demand_level']?>"></span><div><div class="hotspot-name"><?=htmlspecialchars($h['name'])?></div><div class="hotspot-wait">~<?=$h['estimated_wait_minutes']?>min</div></div></div>
<?php endforeach;?>
</div>
</div>
<?php endif;?>

<nav class="bottom-nav">
<a href="dashboard_new.php" class="nav-item active"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg><span>Início</span></a>
<a href="ofertas.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg><span>Ofertas</span></a>
<a href="ganhos.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span>Ganhos</span></a>
<a href="perfil.php" class="nav-item"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg><span>Perfil</span></a>
</nav>
</body>
</html>
