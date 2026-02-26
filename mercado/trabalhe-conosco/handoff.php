<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * Handoff (QR Code) - Trabalhe Conosco
 * /mercado/trabalhe-conosco/handoff.php
 * 
 * Shopper terminou as compras, mostra QR para driver escanear
 */
session_start();

if (!isset($_SESSION["worker_id"])) {
    header("Location: login.php");
    exit;
}

$workerId = $_SESSION["worker_id"];
$orderId = $_GET["order"] ?? 0;

if (!$orderId) {
    header("Location: app.php");
    exit;
}

try {
    $pdo = getPDO();
    
    $stmt = $pdo->prepare("SELECT o.*, w.name as worker_name, w.worker_type
                           FROM om_market_orders o
                           LEFT JOIN om_market_workers w ON o.worker_id = w.worker_id
                           WHERE o.order_id = ? AND o.worker_id = ?");
    $stmt->execute([$orderId, $workerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header("Location: app.php?error=nao_encontrado");
        exit;
    }
    
    // Gerar código de handoff se não existir
    if (empty($order["handoff_code"])) {
        $handoffCode = strtoupper(substr(md5($orderId . time()), 0, 8));
        $stmt = $pdo->prepare("UPDATE om_market_orders SET handoff_code = ? WHERE order_id = ?");
        $stmt->execute([$handoffCode, $orderId]);
        $order["handoff_code"] = $handoffCode;
    }
    
} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}

// QR Code data
$qrData = json_encode([
    "type" => "handoff",
    "order_id" => $orderId,
    "code" => $order["handoff_code"],
    "ts" => time()
]);
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . urlencode($qrData);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Handoff - Pedido #<?= $orderId ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #00b894 0%, #00cec9 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            text-align: center;
            color: #fff;
        }
        .icon { font-size: 4rem; margin-bottom: 20px; }
        h1 { font-size: 1.5rem; margin-bottom: 10px; }
        p { opacity: 0.9; margin-bottom: 30px; }
        .qr-container {
            background: #fff;
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .qr-container img { display: block; }
        .code {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 5px;
            background: rgba(255,255,255,0.2);
            padding: 15px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        .waiting {
            display: flex;
            align-items: center;
            gap: 10px;
            opacity: 0.9;
        }
        .spinner {
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .info-box {
            background: rgba(255,255,255,0.15);
            padding: 20px;
            border-radius: 12px;
            margin-top: 30px;
            max-width: 350px;
        }
        .info-box h4 { margin-bottom: 10px; }
        .info-box p { font-size: 0.9rem; opacity: 0.9; margin: 0; }
        .complete-btn {
            margin-top: 30px;
            padding: 16px 40px;
            background: #fff;
            color: #00b894;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: none;
        }
        .complete-btn.show { display: block; }
    </style>
</head>
<body>
    <div class="icon">✅</div>
    <h1>Compras Finalizadas!</h1>
    <p>Mostre este QR Code para o entregador</p>
    
    <div class="qr-container">
        <img src="<?= $qrUrl ?>" alt="QR Code" width="250" height="250">
    </div>
    
    <div class="code"><?= $order["handoff_code"] ?></div>
    
    <div class="waiting" id="waitingMsg">
        <div class="spinner"></div>
        <span>Aguardando entregador escanear...</span>
    </div>
    
    <div class="info-box">
        <h4>📋 Pedido #<?= $orderId ?></h4>
        <p>O entregador escaneará este código para confirmar o recebimento das compras.</p>
    </div>
    
    <button class="complete-btn" id="completeBtn" onclick="goToApp()">
        ✅ Concluído - Voltar ao Início
    </button>
    
    <script>
    // Verificar se foi escaneado a cada 3 segundos
    async function checkStatus() {
        try {
            const res = await fetch("api/check-handoff.php?order=<?= $orderId ?>");
            const data = await res.json();
            
            if (data.handed_off) {
                document.getElementById("waitingMsg").innerHTML = "✅ Entregador recebeu as compras!";
                document.getElementById("completeBtn").classList.add("show");
            } else {
                setTimeout(checkStatus, 3000);
            }
        } catch (e) {
            setTimeout(checkStatus, 5000);
        }
    }
    
    checkStatus();
    
    function goToApp() {
        window.location.href = "app.php?completed=1";
    }
    </script>
</body>
</html>