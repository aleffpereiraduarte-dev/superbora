<?php
session_start();
require_once __DIR__ . "/includes/functions.php";
requireLogin();
$worker = getWorker();

// Buscar pedidos do worker
$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE shopper_id = ? OR delivery_id = ? ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$worker["worker_id"], $worker["worker_id"]]);
$pedidos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Meus Pedidos</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--brand:#00C853;--brand-light:#E8F5E9;--dark:#1A1A2E;--gray:#6B7280;--gray-light:#F3F4F6;--white:#FFF;--safe-top:env(safe-area-inset-top);--safe-bottom:env(safe-area-inset-bottom)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Plus Jakarta Sans",sans-serif;background:#F8FAFC;min-height:100vh;padding-bottom:calc(80px + var(--safe-bottom))}
        .header{background:var(--white);padding:calc(16px + var(--safe-top)) 20px 16px;display:flex;align-items:center;gap:16px;border-bottom:1px solid var(--gray-light);position:sticky;top:0;z-index:100}
        .back-btn{width:40px;height:40px;background:var(--gray-light);border-radius:12px;display:flex;align-items:center;justify-content:center;text-decoration:none;color:var(--dark)}
        .back-btn svg{width:20px;height:20px}
        .header h1{font-size:20px;font-weight:700}
        .tabs{display:flex;gap:8px;padding:16px 20px;overflow-x:auto}
        .tab{padding:10px 20px;background:var(--gray-light);border-radius:20px;font-size:14px;font-weight:600;color:var(--gray);white-space:nowrap;border:none;cursor:pointer}
        .tab.active{background:var(--brand);color:var(--white)}
        .pedidos{padding:0 20px}
        .pedido-card{background:var(--white);border-radius:16px;padding:16px;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,0.04)}
        .pedido-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
        .pedido-id{font-weight:700;color:var(--dark)}
        .pedido-status{padding:6px 12px;border-radius:20px;font-size:12px;font-weight:600}
        .status-completed{background:var(--brand-light);color:var(--brand)}
        .status-pending{background:#FFF3E0;color:#FF9100}
        .status-cancelled{background:#FFEBEE;color:#EF4444}
        .pedido-info{display:flex;justify-content:space-between;color:var(--gray);font-size:14px}
        .pedido-value{font-size:18px;font-weight:700;color:var(--brand);margin-top:12px}
        .empty{text-align:center;padding:60px 20px;color:var(--gray)}
        .empty-icon{font-size:48px;margin-bottom:16px}
        .bottom-nav{position:fixed;bottom:0;left:0;right:0;background:var(--white);padding:12px 20px calc(12px + var(--safe-bottom));display:flex;justify-content:space-around;box-shadow:0 -4px 20px rgba(0,0,0,0.08)}
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
        <a href="dashboard.php" class="back-btn"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></a>
        <h1>Meus Pedidos</h1>
    </header>
    
    <div class="tabs">
        <button class="tab active">Todos</button>
        <button class="tab">Em andamento</button>
        <button class="tab">Concluídos</button>
        <button class="tab">Cancelados</button>
    </div>
    
    <div class="pedidos">
        <?php if (empty($pedidos)): ?>
        <div class="empty">
            <div class="empty-icon">📦</div>
            <h3>Nenhum pedido ainda</h3>
            <p>Fique online para receber ofertas!</p>
        </div>
        <?php else: ?>
            <?php foreach ($pedidos as $p): ?>
            <div class="pedido-card">
                <div class="pedido-header">
                    <span class="pedido-id">#<?= $p["order_number"] ?? $p["order_id"] ?></span>
                    <span class="pedido-status status-<?= $p["status"] === "completed" ? "completed" : ($p["status"] === "cancelled" ? "cancelled" : "pending") ?>">
                        <?= ucfirst($p["status"]) ?>
                    </span>
                </div>
                <div class="pedido-info">
                    <span><?= $p["partner_name"] ?? "Loja" ?></span>
                    <span><?= date("d/m H:i", strtotime($p["created_at"])) ?></span>
                </div>
                <div class="pedido-value">R$ <?= number_format($p["shopper_earnings"] ?? 0, 2, ",", ".") ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg><span>Início</span></a>
        <a href="pedidos.php" class="nav-item active"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg><span>Pedidos</span></a>
        <a href="carteira.php" class="nav-item"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg><span>Carteira</span></a>
        <a href="perfil.php" class="nav-item"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg><span>Perfil</span></a>
    </nav>
</body>
</html>