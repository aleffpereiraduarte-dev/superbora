<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * Detalhes do Pedido - Trabalhe Conosco
 * /mercado/trabalhe-conosco/pedido.php
 */
session_start();

if (!isset($_SESSION["worker_id"])) {
    header("Location: login.php");
    exit;
}

$workerId = $_SESSION["worker_id"];
$orderId = $_GET["id"] ?? 0;

if (!$orderId) {
    header("Location: app.php");
    exit;
}

try {
    $pdo = getPDO();
    
    // Buscar pedido
    $stmt = $pdo->prepare("SELECT o.*, p.store_name, p.store_address, p.store_lat, p.store_lng,
                                  c.firstname, c.lastname, c.telephone as customer_phone
                           FROM om_market_orders o
                           LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                           LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
                           WHERE o.order_id = ? AND o.worker_id = ?");
    $stmt->execute([$orderId, $workerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header("Location: app.php?error=pedido_nao_encontrado");
        exit;
    }
    
    // Buscar itens
    $stmt = $pdo->prepare("SELECT * FROM om_market_order_items WHERE order_id = ? ORDER BY scanned_at IS NULL DESC, name ASC");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar worker
    $stmt = $pdo->prepare("SELECT * FROM om_market_workers WHERE worker_id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    die("Erro: " . $e->getMessage());
}

$isShopping = in_array($order["status"], ["paid", "shopping"]);
$isDelivery = in_array($order["status"], ["ready_for_pickup", "delivering"]);

$scannedCount = 0;
foreach ($items as $item) {
    if (!empty($item["scanned_at"])) $scannedCount++;
}
$progress = count($items) > 0 ? ($scannedCount / count($items)) * 100 : 0;

$typeColors = ["shopper" => "#00b894", "driver" => "#e17055", "full_service" => "#6c5ce7"];
$workerColor = $typeColors[$worker["worker_type"]] ?? "#667eea";
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido #<?= $orderId ?> - OneMundo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding-bottom: 100px;
        }
        .header {
            background: <?= $workerColor ?>;
            color: #fff;
            padding: 20px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .header h1 { font-size: 1.2rem; }
        .header .back { color: #fff; text-decoration: none; font-size: 1.5rem; }
        .progress-bar { background: rgba(255,255,255,0.3); height: 8px; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: #fff; transition: width 0.3s; }
        .progress-text { font-size: 0.85rem; margin-top: 8px; opacity: 0.9; }
        .container { padding: 15px; }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .card-title { font-weight: 600; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; }
        .store-info h3 { font-size: 1.1rem; margin-bottom: 5px; }
        .store-info p { color: #666; font-size: 0.9rem; }
        .customer-info { display: flex; align-items: center; gap: 15px; }
        .customer-avatar { width: 50px; height: 50px; background: #f0f0f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .customer-details h4 { font-size: 1rem; margin-bottom: 3px; }
        .customer-details p { color: #666; font-size: 0.85rem; }
        .action-btns { display: flex; gap: 10px; margin-top: 15px; }
        .action-btn { flex: 1; padding: 12px; border: none; border-radius: 10px; font-size: 0.9rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-call { background: #e0f7fa; color: #00838f; }
        .btn-chat { background: #e8f5e9; color: #2e7d32; }
        .btn-map { background: #fff3e0; color: #e65100; }
        .items-list { margin-top: 10px; }
        .item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .item:last-child { border-bottom: none; }
        .item-check {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 2px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .item-check.scanned { background: #00b894; border-color: #00b894; color: #fff; }
        .item-info { flex: 1; }
        .item-name { font-weight: 500; }
        .item-qty { font-size: 0.85rem; color: #666; }
        .item.scanned .item-name { text-decoration: line-through; opacity: 0.6; }
        .scan-btn {
            position: fixed;
            bottom: 20px;
            left: 20px;
            right: 20px;
            padding: 18px;
            background: <?= $workerColor ?>;
            color: #fff;
            border: none;
            border-radius: 16px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 5px 20px <?= $workerColor ?>66;
        }
        .complete-btn {
            background: linear-gradient(135deg, #00b894, #00cec9);
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-top">
            <a href="app.php" class="back">←</a>
            <h1>Pedido #<?= $orderId ?></h1>
            <span></span>
        </div>
        <?php if ($isShopping): ?>
        <div class="progress-bar">
            <div class="progress-fill" style="width: <?= $progress ?>%"></div>
        </div>
        <div class="progress-text"><?= $scannedCount ?> de <?= count($items) ?> itens coletados</div>
        <?php endif; ?>
    </div>
    
    <div class="container">
        <!-- Mercado -->
        <div class="card">
            <div class="card-title">🏪 Mercado</div>
            <div class="store-info">
                <h3><?= htmlspecialchars($order["store_name"] ?? "Mercado") ?></h3>
                <p><?= htmlspecialchars($order["store_address"] ?? "") ?></p>
            </div>
            <div class="action-btns">
                <button class="action-btn btn-map" onclick="openMap(<?= $order["store_lat"] ?>, <?= $order["store_lng"] ?>)">📍 Mapa</button>
            </div>
        </div>
        
        <!-- Cliente -->
        <div class="card">
            <div class="card-title">👤 Cliente</div>
            <div class="customer-info">
                <div class="customer-avatar">👤</div>
                <div class="customer-details">
                    <h4><?= htmlspecialchars(($order["firstname"] ?? "") . " " . ($order["lastname"] ?? "")) ?></h4>
                    <p><?= htmlspecialchars($order["delivery_address"] ?? "") ?></p>
                </div>
            </div>
            <div class="action-btns">
                <a href="tel:<?= $order["customer_phone"] ?>" class="action-btn btn-call">📞 Ligar</a>
                <a href="chat.php?order=<?= $orderId ?>" class="action-btn btn-chat">💬 Chat</a>
            </div>
        </div>
        
        <?php if ($isShopping): ?>
        <!-- Lista de Itens -->
        <div class="card">
            <div class="card-title">🛒 Itens (<?= count($items) ?>)</div>
            <div class="items-list">
                <?php foreach ($items as $item): 
                    $scanned = !empty($item["scanned_at"]);
                ?>
                <div class="item <?= $scanned ? "scanned" : "" ?>" data-id="<?= $item["item_id"] ?>">
                    <div class="item-check <?= $scanned ? "scanned" : "" ?>" onclick="toggleItem(<?= $item["item_id"] ?>)">
                        <?= $scanned ? "✓" : "" ?>
                    </div>
                    <div class="item-info">
                        <div class="item-name"><?= htmlspecialchars($item["name"]) ?></div>
                        <div class="item-qty">Qtd: <?= $item["quantity"] ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if ($isShopping): ?>
    <button class="scan-btn" onclick="openScanner()">📷 Escanear Produto</button>
    <?php elseif ($isDelivery): ?>
    <button class="scan-btn complete-btn" onclick="location.href='delivery.php?order=<?= $orderId ?>'">🚴 Iniciar Entrega</button>
    <?php endif; ?>
    
    <script>
    function openMap(lat, lng) {
        window.open("https://www.google.com/maps/dir/?api=1&destination=" + lat + "," + lng, "_blank");
    }
    
    function openScanner() {
        window.location.href = "shopping.php?order=<?= $orderId ?>";
    }
    
    async function toggleItem(itemId) {
        try {
            const res = await fetch("api/scan-item.php", {
                method: "POST",
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify({item_id: itemId, order_id: <?= $orderId ?>})
            });
            const data = await res.json();
            if (data.success) {
                location.reload();
            }
        } catch (e) {
            console.error(e);
        }
    }
    </script>
</body>
</html>