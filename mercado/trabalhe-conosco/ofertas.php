<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * Lista de Ofertas - Trabalhe Conosco
 * /mercado/trabalhe-conosco/ofertas.php
 */
session_start();

if (!isset($_SESSION["worker_id"])) {
    header("Location: login.php");
    exit;
}

$workerId = $_SESSION["worker_id"];

try {
    $pdo = getPDO();
    
    // Buscar worker
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$worker || !$worker["is_online"]) {
        header("Location: app.php?msg=offline");
        exit;
    }
    
    // Buscar ofertas disponíveis
    $workerType = $worker["worker_type"];
    $workMode = $worker["work_mode"] ?? "both";
    $lat = $worker["current_lat"];
    $lng = $worker["current_lng"];
    $maxDistance = $worker["max_distance_km"] ?? 10;
    
    // Determinar quais status buscar baseado no tipo/modo
    $validStatuses = [];
    if ($workerType === "shopper" || ($workerType === "full_service" && in_array($workMode, ["shopping", "both"]))) {
        $validStatuses[] = "paid"; // Aguardando shopper
    }
    if ($workerType === "driver" || ($workerType === "full_service" && in_array($workMode, ["delivery", "both"]))) {
        $validStatuses[] = "ready_for_pickup"; // Aguardando driver
    }
    
    $statusPlaceholders = implode(",", array_fill(0, count($validStatuses), "?"));
    
    $sql = "SELECT o.*, 
                   p.store_name,
                   (6371 * acos(cos(radians(?)) * cos(radians(store_lat)) * 
                    cos(radians(store_lng) - radians(?)) + sin(radians(?)) * 
                    sin(radians(store_lat)))) AS distance
            FROM om_market_orders o
            LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
            WHERE o.status IN ($statusPlaceholders)
            AND o.worker_id IS NULL
            HAVING distance <= ?
            ORDER BY distance ASC, o.created_at ASC
            LIMIT 20";
    
    $params = array_merge([$lat, $lng, $lat], $validStatuses, [$maxDistance]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $offers = [];
    $error = $e->getMessage();
}

$typeColors = ["shopper" => "#00b894", "driver" => "#e17055", "full_service" => "#6c5ce7"];
$workerColor = $typeColors[$worker["worker_type"]] ?? "#667eea";
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ofertas - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding-bottom: 80px;
        }
        .header {
            background: <?= $workerColor ?>;
            color: #fff;
            padding: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header h1 { font-size: 1.3rem; }
        .header p { opacity: 0.9; font-size: 0.9rem; }
        .container { padding: 15px; }
        .offer-card {
            background: #fff;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .offer-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        .offer-store { font-weight: 600; font-size: 1.1rem; }
        .offer-distance { 
            background: #f0f0f0; 
            padding: 5px 12px; 
            border-radius: 20px; 
            font-size: 0.85rem;
            color: #666;
        }
        .offer-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }
        .offer-info-item {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 10px;
        }
        .offer-info-label { font-size: 0.75rem; color: #999; margin-bottom: 3px; }
        .offer-info-value { font-weight: 600; color: #1a1a2e; }
        .offer-earnings {
            background: linear-gradient(135deg, <?= $workerColor ?>22, <?= $workerColor ?>11);
            padding: 15px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .offer-earnings-label { color: #666; }
        .offer-earnings-value { font-size: 1.5rem; font-weight: 700; color: <?= $workerColor ?>; }
        .offer-type {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 15px;
        }
        .type-shopping { background: #00b89422; color: #00b894; }
        .type-delivery { background: #e1705522; color: #e17055; }
        .type-full { background: #6c5ce722; color: #6c5ce7; }
        .accept-btn {
            width: 100%;
            padding: 16px;
            background: <?= $workerColor ?>;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        .accept-btn:active { transform: scale(0.98); }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .empty-state .icon { font-size: 4rem; margin-bottom: 20px; }
        .nav-bottom {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            padding: 10px 20px;
            display: flex;
            justify-content: space-around;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }
        .nav-item {
            text-align: center;
            color: #999;
            text-decoration: none;
            padding: 10px;
        }
        .nav-item.active { color: <?= $workerColor ?>; }
        .nav-item .icon { font-size: 1.5rem; }
        .nav-item .label { font-size: 0.75rem; }
    </style>
</head>
<body>
    <div class="header">
        <h1>📋 Ofertas Disponíveis</h1>
        <p><?= count($offers) ?> ofertas na sua região</p>
    </div>
    
    <div class="container">
        <?php if (empty($offers)): ?>
            <div class="empty-state">
                <div class="icon">🔍</div>
                <h3>Nenhuma oferta no momento</h3>
                <p>Novas ofertas aparecerão aqui automaticamente</p>
            </div>
        <?php else: ?>
            <?php foreach ($offers as $offer): 
                $isDelivery = $offer["status"] === "ready_for_pickup";
                $isShopping = $offer["status"] === "paid";
            ?>
            <div class="offer-card">
                <div class="offer-header">
                    <div class="offer-store"><?= htmlspecialchars($offer["store_name"] ?? "Mercado") ?></div>
                    <div class="offer-distance">📍 <?= number_format($offer["distance"], 1) ?> km</div>
                </div>
                
                <span class="offer-type <?= $isShopping ? "type-shopping" : "type-delivery" ?>">
                    <?= $isShopping ? "🛒 Compras" : "🚴 Entrega" ?>
                </span>
                
                <div class="offer-info">
                    <div class="offer-info-item">
                        <div class="offer-info-label">Itens</div>
                        <div class="offer-info-value"><?= $offer["total_items"] ?? "?" ?> produtos</div>
                    </div>
                    <div class="offer-info-item">
                        <div class="offer-info-label">Tempo estimado</div>
                        <div class="offer-info-value"><?= $isShopping ? "30-45 min" : "15-25 min" ?></div>
                    </div>
                </div>
                
                <div class="offer-earnings">
                    <span class="offer-earnings-label">Você ganha:</span>
                    <span class="offer-earnings-value">R$ <?= number_format($offer["worker_fee"] ?? 15, 2, ",", ".") ?></span>
                </div>
                
                <button class="accept-btn" onclick="acceptOffer(<?= $offer["order_id"] ?>)">
                    Aceitar Oferta
                </button>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <nav class="nav-bottom">
        <a href="app.php" class="nav-item"><div class="icon">🏠</div><div class="label">Início</div></a>
        <a href="ofertas.php" class="nav-item active"><div class="icon">📋</div><div class="label">Ofertas</div></a>
        <a href="ganhos.php" class="nav-item"><div class="icon">💰</div><div class="label">Ganhos</div></a>
        <a href="perfil.php" class="nav-item"><div class="icon">👤</div><div class="label">Perfil</div></a>
    </nav>
    
    <script>
    async function acceptOffer(orderId) {
        if (!confirm("Aceitar esta oferta?")) return;
        
        try {
            const res = await fetch("api/accept-offer.php", {
                method: "POST",
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify({order_id: orderId})
            });
            const data = await res.json();
            
            if (data.success) {
                window.location.href = "pedido.php?id=" + orderId;
            } else {
                alert("Erro: " + (data.error || "Não foi possível aceitar"));
            }
        } catch (e) {
            alert("Erro de conexão");
        }
    }
    
    // Auto-refresh a cada 15 segundos
    setTimeout(() => location.reload(), 15000);
    </script>
</body>
</html>