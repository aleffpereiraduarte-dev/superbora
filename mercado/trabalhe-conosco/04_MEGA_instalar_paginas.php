<?php
/**
 * ╔═══════════════════════════════════════════════════════════════════════════════╗
 * ║  04-10 MEGA INSTALADOR - TODAS AS PÁGINAS                                    ║
 * ║  OneMundo Shopper App                                                         ║
 * ╚═══════════════════════════════════════════════════════════════════════════════╝
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(120);

$baseDir = __DIR__;

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>MEGA Instalador</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;padding:20px}
.container{max-width:900px;margin:0 auto}
h1{color:#10b981;margin-bottom:20px}
.card{background:#1e293b;border-radius:12px;padding:20px;margin-bottom:16px}
.success{color:#10b981}.error{color:#ef4444}.warning{color:#f59e0b}
.log{font-family:monospace;font-size:13px;padding:8px 0;border-bottom:1px solid #334155}
.btn{display:inline-block;padding:12px 24px;background:#10b981;color:#fff;text-decoration:none;border-radius:8px;margin:5px}
.progress{background:#334155;height:8px;border-radius:4px;margin:10px 0}
.progress-bar{background:#10b981;height:100%;border-radius:4px;transition:width 0.3s}
</style></head><body><div class='container'>";

echo "<h1>🚀 MEGA INSTALADOR - Todas as Páginas</h1>";

$arquivos = [];
$success = 0;
$total = 0;

function salvar($path, $content) {
    global $success, $total;
    $total++;
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (file_put_contents($path, $content)) {
        $success++;
        return true;
    }
    return false;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 1. DASHBOARD.PHP
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='card'><h3>📊 Dashboard</h3>";

$dashboard = '<?php
session_start();
require_once __DIR__ . "/includes/config.php";
require_once __DIR__ . "/includes/functions.php";

requireLogin();
$worker = getWorker();
$firstName = explode(" ", $worker["name"])[0];

$todayEarnings = getEarningsToday($worker["worker_id"]);
$todayOrders = getOrdersToday($worker["worker_id"]);
$weekEarnings = getEarningsWeek($worker["worker_id"]);
$weekOrders = getOrdersWeek($worker["worker_id"]);
$balance = getBalance($worker["worker_id"]);

$hour = date("H");
$greeting = $hour < 12 ? "Bom dia" : ($hour < 18 ? "Boa tarde" : "Boa noite");
$level = $worker["level"] ?? 1;
$rating = $worker["rating"] ?? 5.0;
$isOnline = $worker["is_online"] ?? 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Dashboard - OneMundo Shopper</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--brand:#00C853;--brand-dark:#00A844;--brand-light:#E8F5E9;--orange:#FF9100;--blue:#2979FF;--purple:#7C4DFF;--dark:#1A1A2E;--gray:#6B7280;--gray-light:#F3F4F6;--white:#FFF;--safe-top:env(safe-area-inset-top);--safe-bottom:env(safe-area-inset-bottom)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Plus Jakarta Sans",sans-serif;background:#F8FAFC;min-height:100vh;padding-bottom:calc(80px + var(--safe-bottom))}
        .header{background:linear-gradient(135deg,var(--brand),#00E676);padding:calc(20px + var(--safe-top)) 20px 100px;position:relative;overflow:hidden}
        .header::before{content:"";position:absolute;top:-100px;right:-100px;width:300px;height:300px;background:rgba(255,255,255,0.1);border-radius:50%}
        .header-top{display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1}
        .user-info{display:flex;align-items:center;gap:12px}
        .user-avatar{width:48px;height:48px;background:var(--white);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:var(--brand);box-shadow:0 4px 12px rgba(0,0,0,0.1)}
        .user-text h1{font-size:18px;font-weight:700;color:var(--white)}
        .user-text p{font-size:13px;color:rgba(255,255,255,0.85)}
        .header-btn{width:44px;height:44px;background:rgba(255,255,255,0.2);border:none;border-radius:12px;display:flex;align-items:center;justify-content:center;cursor:pointer}
        .header-btn svg{width:22px;height:22px;color:var(--white)}
        
        .online-card{background:var(--white);border-radius:20px;padding:20px;margin:-70px 20px 20px;box-shadow:0 8px 32px rgba(0,0,0,0.1);position:relative;z-index:10}
        .online-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
        .online-info h2{font-size:18px;font-weight:700}
        .online-info p{font-size:13px;color:var(--gray)}
        .toggle{position:relative;width:60px;height:32px}
        .toggle input{opacity:0;width:0;height:0}
        .toggle-slider{position:absolute;cursor:pointer;inset:0;background:#E5E7EB;border-radius:32px;transition:0.3s}
        .toggle-slider::before{position:absolute;content:"";height:24px;width:24px;left:4px;bottom:4px;background:white;border-radius:50%;transition:0.3s;box-shadow:0 2px 8px rgba(0,0,0,0.15)}
        input:checked+.toggle-slider{background:var(--brand)}
        input:checked+.toggle-slider::before{transform:translateX(28px)}
        .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
        .stat{text-align:center;padding:12px;background:var(--gray-light);border-radius:12px}
        .stat.active{background:var(--brand-light)}
        .stat-value{font-size:20px;font-weight:800}
        .stat.active .stat-value{color:var(--brand)}
        .stat-label{font-size:11px;color:var(--gray);margin-top:2px}
        
        .earnings-card{background:linear-gradient(135deg,#1A1A2E,#2D2D44);border-radius:20px;padding:24px;margin:0 20px 20px;color:var(--white)}
        .earnings-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
        .earnings-title{font-size:14px;color:rgba(255,255,255,0.7)}
        .earnings-badge{background:rgba(0,200,83,0.2);padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600;color:var(--brand)}
        .earnings-value{font-size:36px;font-weight:800;margin-bottom:4px}
        .earnings-value span{font-size:20px}
        .earnings-sub{font-size:13px;color:rgba(255,255,255,0.6);margin-bottom:16px}
        .earnings-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
        .earnings-item{background:rgba(255,255,255,0.08);border-radius:12px;padding:14px}
        .earnings-item-label{font-size:12px;color:rgba(255,255,255,0.6);margin-bottom:4px}
        .earnings-item-value{font-size:18px;font-weight:700}
        
        .actions{padding:0 20px;margin-bottom:24px}
        .section-title{font-size:18px;font-weight:700;margin-bottom:16px}
        .actions-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
        .action-btn{background:var(--white);border-radius:16px;padding:16px 8px;text-align:center;text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
        .action-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 10px}
        .action-icon svg{width:24px;height:24px}
        .action-icon.green{background:var(--brand-light);color:var(--brand)}
        .action-icon.orange{background:#FFF3E0;color:var(--orange)}
        .action-icon.blue{background:#E3F2FD;color:var(--blue)}
        .action-icon.purple{background:#EDE7F6;color:var(--purple)}
        .action-btn span{font-size:12px;font-weight:600;color:var(--dark)}
        
        .bottom-nav{position:fixed;bottom:0;left:0;right:0;background:var(--white);padding:12px 20px calc(12px + var(--safe-bottom));display:flex;justify-content:space-around;box-shadow:0 -4px 20px rgba(0,0,0,0.08);z-index:100}
        .nav-item{display:flex;flex-direction:column;align-items:center;text-decoration:none;padding:8px 16px;border-radius:12px}
        .nav-item.active{background:var(--brand-light)}
        .nav-item svg{width:24px;height:24px;color:var(--gray);margin-bottom:4px}
        .nav-item.active svg{color:var(--brand)}
        .nav-item span{font-size:11px;font-weight:600;color:var(--gray)}
        .nav-item.active span{color:var(--brand)}
    </style>
</head>
<body>
    <header class="header">
        <div class="header-top">
            <div class="user-info">
                <div class="user-avatar"><?= strtoupper(substr($firstName, 0, 1)) ?></div>
                <div class="user-text">
                    <h1><?= $greeting ?>, <?= htmlspecialchars($firstName) ?>!</h1>
                    <p>Nível <?= $level ?> • Shopper</p>
                </div>
            </div>
            <button class="header-btn" onclick="location.href=\'perfil.php\'">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </button>
        </div>
    </header>
    
    <div class="online-card">
        <div class="online-header">
            <div class="online-info">
                <h2 id="online-status"><?= $isOnline ? "Você está online" : "Você está offline" ?></h2>
                <p>Recebendo pedidos da região</p>
            </div>
            <label class="toggle">
                <input type="checkbox" id="online-toggle" <?= $isOnline ? "checked" : "" ?> onchange="toggleOnline()">
                <span class="toggle-slider"></span>
            </label>
        </div>
        <div class="stats-row">
            <div class="stat active">
                <div class="stat-value"><?= rand(3, 12) ?></div>
                <div class="stat-label">Disponíveis</div>
            </div>
            <div class="stat">
                <div class="stat-value"><?= $todayOrders ?></div>
                <div class="stat-label">Hoje</div>
            </div>
            <div class="stat">
                <div class="stat-value"><?= number_format($worker["acceptance_rate"] ?? 100, 0) ?>%</div>
                <div class="stat-label">Aceitação</div>
            </div>
        </div>
    </div>
    
    <div class="earnings-card">
        <div class="earnings-header">
            <span class="earnings-title">Ganhos de Hoje</span>
            <span class="earnings-badge">⚡ Ativo</span>
        </div>
        <div class="earnings-value"><span>R$</span> <?= number_format($todayEarnings, 2, ",", ".") ?></div>
        <p class="earnings-sub"><?= $todayOrders ?> pedidos completados</p>
        <div class="earnings-grid">
            <div class="earnings-item">
                <div class="earnings-item-label">Esta semana</div>
                <div class="earnings-item-value">R$ <?= number_format($weekEarnings, 2, ",", ".") ?></div>
            </div>
            <div class="earnings-item">
                <div class="earnings-item-label">Saldo disponível</div>
                <div class="earnings-item-value">R$ <?= number_format($balance, 2, ",", ".") ?></div>
            </div>
        </div>
    </div>
    
    <div class="actions">
        <h2 class="section-title">Ações Rápidas</h2>
        <div class="actions-grid">
            <a href="carteira.php" class="action-btn">
                <div class="action-icon green"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg></div>
                <span>Carteira</span>
            </a>
            <a href="extrato.php" class="action-btn">
                <div class="action-icon orange"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div>
                <span>Extrato</span>
            </a>
            <a href="historico.php" class="action-btn">
                <div class="action-icon blue"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                <span>Histórico</span>
            </a>
            <a href="score.php" class="action-btn">
                <div class="action-icon purple"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg></div>
                <span>Score</span>
            </a>
        </div>
    </div>
    
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item active">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <span>Início</span>
        </a>
        <a href="pedidos.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            <span>Pedidos</span>
        </a>
        <a href="carteira.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            <span>Carteira</span>
        </a>
        <a href="perfil.php" class="nav-item">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            <span>Perfil</span>
        </a>
    </nav>
    
    <script>
    function toggleOnline() {
        const isOn = document.getElementById("online-toggle").checked;
        document.getElementById("online-status").textContent = isOn ? "Você está online" : "Você está offline";
        fetch("api/toggle-online.php", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify({online: isOn})
        });
    }
    </script>
</body>
</html>';

if (salvar($baseDir . '/dashboard.php', $dashboard)) {
    echo "<div class='log success'>✅ dashboard.php</div>";
}
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// 2. CARTEIRA.PHP (simplificada)
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='card'><h3>💰 Carteira</h3>";

$carteira = '<?php
session_start();
require_once __DIR__ . "/includes/config.php";
require_once __DIR__ . "/includes/functions.php";
requireLogin();
$worker = getWorker();
$balance = getBalance($worker["worker_id"]);
$pending = getPendingBalance($worker["worker_id"]);
$todayEarnings = getEarningsToday($worker["worker_id"]);
$weekEarnings = getEarningsWeek($worker["worker_id"]);
$monthEarnings = getEarningsMonth($worker["worker_id"]);
$withdrawals = getWithdrawals($worker["worker_id"], 5);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Carteira - OneMundo</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--brand:#00C853;--brand-light:#E8F5E9;--dark:#1A1A2E;--gray:#6B7280;--gray-light:#F3F4F6;--white:#FFF;--safe-top:env(safe-area-inset-top);--safe-bottom:env(safe-area-inset-bottom)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Plus Jakarta Sans",sans-serif;background:#F8FAFC;min-height:100vh;padding-bottom:calc(100px + var(--safe-bottom))}
        .header{background:var(--white);padding:calc(16px + var(--safe-top)) 20px 16px;display:flex;align-items:center;gap:16px;border-bottom:1px solid var(--gray-light)}
        .back-btn{width:40px;height:40px;background:var(--gray-light);border-radius:12px;display:flex;align-items:center;justify-content:center;text-decoration:none;color:var(--dark)}
        .back-btn svg{width:20px;height:20px}
        .header h1{font-size:20px;font-weight:700}
        
        .balance-card{background:linear-gradient(135deg,var(--brand),#00E676);margin:20px;border-radius:20px;padding:24px;color:var(--white)}
        .balance-label{font-size:14px;opacity:0.9}
        .balance-value{font-size:40px;font-weight:800;margin:8px 0}
        .balance-pending{font-size:14px;opacity:0.8}
        
        .btn-group{display:flex;gap:12px;padding:0 20px;margin-bottom:24px}
        .btn{flex:1;padding:14px;border-radius:14px;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;border:none}
        .btn-primary{background:var(--brand);color:var(--white)}
        .btn-secondary{background:var(--gray-light);color:var(--dark)}
        .btn svg{width:18px;height:18px}
        
        .section{padding:0 20px;margin-bottom:24px}
        .section-title{font-size:16px;font-weight:700;margin-bottom:12px}
        .stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
        .stat-card{background:var(--white);border-radius:16px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
        .stat-value{font-size:24px;font-weight:800;color:var(--brand)}
        .stat-label{font-size:12px;color:var(--gray);margin-top:4px}
        
        .list-item{display:flex;align-items:center;gap:14px;background:var(--white);padding:16px;border-radius:14px;margin-bottom:8px}
        .list-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center}
        .list-icon.green{background:var(--brand-light);color:var(--brand)}
        .list-icon.orange{background:#FFF3E0;color:#FF9100}
        .list-icon svg{width:20px;height:20px}
        .list-body{flex:1}
        .list-title{font-size:14px;font-weight:600}
        .list-subtitle{font-size:12px;color:var(--gray)}
        .list-value{font-size:16px;font-weight:700}
        
        .empty{text-align:center;padding:40px;color:var(--gray)}
    </style>
</head>
<body>
    <header class="header">
        <a href="dashboard.php" class="back-btn"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></a>
        <h1>Carteira</h1>
    </header>
    
    <div class="balance-card">
        <div class="balance-label">Saldo disponível</div>
        <div class="balance-value">R$ <?= number_format($balance, 2, ",", ".") ?></div>
        <div class="balance-pending">R$ <?= number_format($pending, 2, ",", ".") ?> pendente</div>
    </div>
    
    <div class="btn-group">
        <button class="btn btn-primary" onclick="location.href=\'saque.php\'">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Sacar PIX
        </button>
        <button class="btn btn-secondary" onclick="location.href=\'extrato.php\'">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Extrato
        </button>
    </div>
    
    <section class="section">
        <h3 class="section-title">Resumo</h3>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($todayEarnings, 0) ?></div>
                <div class="stat-label">Hoje</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($weekEarnings, 0) ?></div>
                <div class="stat-label">Esta semana</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?= number_format($monthEarnings, 0) ?></div>
                <div class="stat-label">Este mês</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ 0</div>
                <div class="stat-label">Bônus</div>
            </div>
        </div>
    </section>
    
    <section class="section">
        <h3 class="section-title">Últimos Saques</h3>
        <?php if (empty($withdrawals)): ?>
            <div class="empty">Nenhum saque realizado ainda</div>
        <?php else: ?>
            <?php foreach ($withdrawals as $w): ?>
            <div class="list-item">
                <div class="list-icon <?= $w["status"] === "completed" ? "green" : "orange" ?>">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $w["status"] === "completed" ? "M5 13l4 4L19 7" : "M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" ?>"/></svg>
                </div>
                <div class="list-body">
                    <div class="list-title">Saque PIX</div>
                    <div class="list-subtitle"><?= date("d/m/Y H:i", strtotime($w["created_at"])) ?></div>
                </div>
                <div class="list-value">R$ <?= number_format($w["amount"], 2, ",", ".") ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</body>
</html>';

if (salvar($baseDir . '/carteira.php', $carteira)) {
    echo "<div class='log success'>✅ carteira.php</div>";
}
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// 3. PERFIL.PHP
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='card'><h3>👤 Perfil</h3>";

$perfil = '<?php
session_start();
require_once __DIR__ . "/includes/config.php";
require_once __DIR__ . "/includes/functions.php";
requireLogin();
$worker = getWorker();
$firstName = explode(" ", $worker["name"])[0];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Perfil - OneMundo</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--brand:#00C853;--brand-light:#E8F5E9;--dark:#1A1A2E;--gray:#6B7280;--gray-light:#F3F4F6;--white:#FFF;--red:#FF5252;--safe-top:env(safe-area-inset-top);--safe-bottom:env(safe-area-inset-bottom)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Plus Jakarta Sans",sans-serif;background:#F8FAFC;min-height:100vh;padding-bottom:calc(100px + var(--safe-bottom))}
        .header{background:linear-gradient(135deg,var(--brand),#00E676);padding:calc(20px + var(--safe-top)) 20px 80px;text-align:center}
        .header::before{content:"";position:absolute;bottom:0;left:0;right:0;height:40px;background:#F8FAFC;border-radius:40px 40px 0 0}
        .avatar-section{position:relative;z-index:10;margin-top:-60px;text-align:center;padding:0 20px}
        .avatar{width:100px;height:100px;background:var(--white);border-radius:28px;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:800;color:var(--brand);box-shadow:0 8px 32px rgba(0,0,0,0.15);border:4px solid var(--white)}
        .user-name{font-size:24px;font-weight:800;color:var(--dark)}
        .user-email{font-size:14px;color:var(--gray);margin-top:4px}
        .user-badge{display:inline-flex;align-items:center;gap:6px;background:var(--brand-light);color:var(--brand);padding:8px 16px;border-radius:20px;font-size:13px;font-weight:600;margin-top:12px}
        .stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:24px 20px}
        .stat-card{background:var(--white);border-radius:16px;padding:16px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
        .stat-value{font-size:24px;font-weight:800}
        .stat-label{font-size:12px;color:var(--gray);margin-top:4px}
        .menu-section{padding:0 20px 20px}
        .menu-title{font-size:13px;font-weight:600;color:var(--gray);text-transform:uppercase;margin-bottom:12px;padding-left:4px}
        .menu-card{background:var(--white);border-radius:20px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
        .menu-item{display:flex;align-items:center;gap:14px;padding:16px 20px;text-decoration:none;color:var(--dark);border-bottom:1px solid var(--gray-light)}
        .menu-item:last-child{border-bottom:none}
        .menu-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center}
        .menu-icon svg{width:22px;height:22px}
        .menu-icon.green{background:var(--brand-light);color:var(--brand)}
        .menu-icon.blue{background:#E3F2FD;color:#2979FF}
        .menu-icon.orange{background:#FFF3E0;color:#FF9100}
        .menu-icon.red{background:#FFEBEE;color:var(--red)}
        .menu-text{flex:1}
        .menu-text h3{font-size:15px;font-weight:600}
        .menu-text p{font-size:12px;color:var(--gray)}
        .logout-btn{display:block;width:calc(100% - 40px);margin:20px auto;padding:16px;background:var(--red);color:var(--white);border:none;border-radius:14px;font-family:inherit;font-size:15px;font-weight:600;text-align:center;text-decoration:none;cursor:pointer}
    </style>
</head>
<body>
    <header class="header"></header>
    
    <div class="avatar-section">
        <div class="avatar"><?= strtoupper(substr($firstName, 0, 1)) ?></div>
        <h1 class="user-name"><?= htmlspecialchars($worker["name"]) ?></h1>
        <p class="user-email"><?= htmlspecialchars($worker["email"]) ?></p>
        <div class="user-badge">⭐ Nível <?= $worker["level"] ?? 1 ?> • <?= ucfirst($worker["worker_type"]) ?></div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $worker["total_orders"] ?? 0 ?></div>
            <div class="stat-label">Pedidos</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($worker["rating"] ?? 5, 1) ?></div>
            <div class="stat-label">Avaliação</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($worker["completion_rate"] ?? 100, 0) ?>%</div>
            <div class="stat-label">Conclusão</div>
        </div>
    </div>
    
    <div class="menu-section">
        <div class="menu-title">Conta</div>
        <div class="menu-card">
            <a href="editar-perfil.php" class="menu-item">
                <div class="menu-icon green"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></div>
                <div class="menu-text"><h3>Editar Perfil</h3><p>Nome, foto, telefone</p></div>
            </a>
            <a href="dados-bancarios.php" class="menu-item">
                <div class="menu-icon blue"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg></div>
                <div class="menu-text"><h3>Dados Bancários</h3><p>Chave PIX para receber</p></div>
            </a>
            <a href="score.php" class="menu-item">
                <div class="menu-icon orange"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg></div>
                <div class="menu-text"><h3>Seu Score</h3><p>Desempenho e indicadores</p></div>
            </a>
        </div>
    </div>
    
    <a href="logout.php" class="logout-btn">Sair da Conta</a>
</body>
</html>';

if (salvar($baseDir . '/perfil.php', $perfil)) {
    echo "<div class='log success'>✅ perfil.php</div>";
}
echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// 4. APIs
// ═══════════════════════════════════════════════════════════════════════════════

echo "<div class='card'><h3>📡 APIs</h3>";

// toggle-online.php
$toggleOnline = '<?php
session_start();
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/functions.php";

header("Content-Type: application/json");

if (!isLoggedIn()) {
    jsonResponse(false, "Não autenticado");
}

$data = json_decode(file_get_contents("php://input"), true);
$isOnline = $data["online"] ?? false;

setOnlineStatus(getWorkerId(), $isOnline);
jsonResponse(true, $isOnline ? "Online" : "Offline");';

if (salvar($baseDir . '/api/toggle-online.php', $toggleOnline)) {
    echo "<div class='log success'>✅ api/toggle-online.php</div>";
}

// withdraw.php
$withdraw = '<?php
session_start();
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/functions.php";

header("Content-Type: application/json");

if (!isLoggedIn()) {
    jsonResponse(false, "Não autenticado");
}

$data = json_decode(file_get_contents("php://input"), true);
$amount = floatval($data["amount"] ?? 0);

$result = requestWithdrawal(getWorkerId(), $amount);
jsonResponse($result["success"], $result["message"]);';

if (salvar($baseDir . '/api/withdraw.php', $withdraw)) {
    echo "<div class='log success'>✅ api/withdraw.php</div>";
}

// update-location.php
$updateLocation = '<?php
session_start();
require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/functions.php";

header("Content-Type: application/json");

if (!isLoggedIn()) {
    jsonResponse(false, "Não autenticado");
}

$data = json_decode(file_get_contents("php://input"), true);
$lat = floatval($data["lat"] ?? 0);
$lng = floatval($data["lng"] ?? 0);

if ($lat && $lng) {
    updateLocation(getWorkerId(), $lat, $lng);
    jsonResponse(true, "Localização atualizada");
}

jsonResponse(false, "Dados inválidos");';

if (salvar($baseDir . '/api/update-location.php', $updateLocation)) {
    echo "<div class='log success'>✅ api/update-location.php</div>";
}

echo "</div>";

// ═══════════════════════════════════════════════════════════════════════════════
// RESUMO FINAL
// ═══════════════════════════════════════════════════════════════════════════════

$percent = $total > 0 ? round(($success / $total) * 100) : 0;

echo "<div class='card'>";
echo "<h3>📊 Resumo Final</h3>";
echo "<div class='progress'><div class='progress-bar' style='width:{$percent}%'></div></div>";
echo "<p><span class='success'>✅ $success arquivos criados</span> de $total</p>";
echo "</div>";

echo "<div class='card'>";
echo "<h3>🎯 Arquivos Instalados:</h3>";
echo "<ul style='margin-left:20px;line-height:2'>";
echo "<li>✅ dashboard.php - Painel principal</li>";
echo "<li>✅ carteira.php - Carteira/Wallet</li>";
echo "<li>✅ perfil.php - Perfil do usuário</li>";
echo "<li>✅ api/toggle-online.php</li>";
echo "<li>✅ api/withdraw.php</li>";
echo "<li>✅ api/update-location.php</li>";
echo "</ul>";
echo "</div>";

echo "<div class='card' style='background:#10b981;text-align:center'>";
echo "<h2 style='margin-bottom:10px'>🎉 INSTALAÇÃO COMPLETA!</h2>";
echo "<p>Acesse: <a href='login.php' style='color:#fff;font-weight:bold'>login.php</a></p>";
echo "<p style='margin-top:10px'>📧 Email: shopper@teste.com<br>🔑 Senha: 123456</p>";
echo "</div>";

echo "<div style='text-align:center;margin-top:20px'>";
echo "<a href='01_criar_banco.php' class='btn' style='background:#64748b'>← Voltar ao Início</a>";
echo "<a href='login.php' class='btn'>Testar Login →</a>";
echo "</div>";

echo "</div></body></html>";
