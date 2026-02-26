<?php
require_once dirname(__DIR__) . '/config/database.php';
session_start();
if (!isset($_SESSION["worker_id"])) { header("Location: login.php"); exit; }

$pdo = getPDO();
$workerId = $_SESSION["worker_id"];

$worker = $pdo->query("SELECT * FROM om_market_workers WHERE worker_id = $workerId")->fetch(PDO::FETCH_ASSOC);
$saldo = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM om_market_worker_earnings WHERE worker_id = $workerId AND status = 'available'")->fetchColumn();
$pendente = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM om_market_worker_earnings WHERE worker_id = $workerId AND status = 'pending'")->fetchColumn();
$ganhos = $pdo->query("SELECT * FROM om_market_worker_earnings WHERE worker_id = $workerId ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💰 Wallet - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui; background: #0f172a; color: #fff; min-height: 100vh; }
        .header { background: linear-gradient(135deg, #10b981, #059669); padding: 30px 20px; }
        .balance { font-size: 2.5rem; font-weight: 700; }
        .container { padding: 20px; }
        .card { background: #1e293b; border-radius: 16px; padding: 20px; margin-bottom: 15px; }
        .card h3 { margin-bottom: 15px; color: #94a3b8; font-size: 0.9rem; }
        .earning { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #334155; }
        .earning:last-child { border: none; }
        .earning .value { color: #10b981; font-weight: 600; }
        .btn { width: 100%; padding: 16px; background: #10b981; color: #fff; border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
        .stat { background: #1e293b; padding: 15px; border-radius: 12px; text-align: center; }
        .stat .value { font-size: 1.3rem; font-weight: 700; color: #10b981; }
        .stat .label { font-size: 0.8rem; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="header">
        <div style="font-size: 0.9rem; opacity: 0.8;">Saldo Disponível</div>
        <div class="balance">R$ <?= number_format($saldo, 2, ",", ".") ?></div>
    </div>
    
    <div class="container">
        <div class="stats">
            <div class="stat">
                <div class="value">R$ <?= number_format($pendente, 2, ",", ".") ?></div>
                <div class="label">Pendente</div>
            </div>
            <div class="stat">
                <div class="value">R$ <?= number_format($worker["total_earned"] ?? 0, 2, ",", ".") ?></div>
                <div class="label">Total Ganho</div>
            </div>
        </div>
        
        <button class="btn" onclick="alert('Saque solicitado! Será processado em até 24h.')">💸 Solicitar Saque</button>
        
        <div class="card" style="margin-top: 20px;">
            <h3>📊 Últimos Ganhos</h3>
            <?php foreach ($ganhos as $g): ?>
            <div class="earning">
                <div>
                    <div style="font-weight: 500;"><?= $g["description"] ?: ucfirst($g["type"]) ?></div>
                    <div style="font-size: 0.8rem; color: #64748b;"><?= date("d/m H:i", strtotime($g["created_at"])) ?></div>
                </div>
                <div class="value">+ R$ <?= number_format($g["amount"], 2, ",", ".") ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>