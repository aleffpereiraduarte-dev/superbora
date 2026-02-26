<?php
require_once dirname(__DIR__) . '/config/database.php';
session_name("OCSESSID");
session_start();

try {
    $pdo = getPDO();
} catch (Exception $e) {
    die("Erro DB: " . $e->getMessage());
}

$partnerId = (int)($_SESSION["market_partner_id"] ?? 4);

$stmt = $pdo->prepare("
    SELECT pb.product_id, pb.name, pb.image, pp.price, pp.price_promo 
    FROM om_market_products_base pb 
    JOIN om_market_products_price pp ON pb.product_id = pp.product_id 
    WHERE pp.partner_id = ? AND pp.price_promo > 0 AND pp.price_promo < pp.price 
    ORDER BY (pp.price - pp.price_promo) DESC LIMIT 50
");
$stmt->execute([$partnerId]);
$ofertas = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ofertas - OneMundo</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;background:#f5f5f5;padding:20px}
h1{color:#10b981;margin-bottom:20px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}
.card{background:#fff;border-radius:12px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,0.1)}
.card img{width:100%;height:120px;object-fit:contain;margin-bottom:12px}
.card h3{font-size:14px;margin-bottom:8px;color:#333}
.prices{display:flex;gap:8px;align-items:center}
.old{text-decoration:line-through;color:#999;font-size:13px}
.new{color:#10b981;font-weight:700;font-size:18px}
.empty{text-align:center;padding:60px;color:#666}
</style>
</head>
<body>
<h1>🔥 Ofertas</h1>
<?php if (empty($ofertas)): ?>
<div class="empty">Nenhuma oferta no momento</div>
<?php else: ?>
<div class="grid">
<?php foreach ($ofertas as $p): ?>
<div class="card">
<img src="<?= htmlspecialchars($p["image"]) ?>" alt="" onerror="this.src='https://via.placeholder.com/150'">
<h3><?= htmlspecialchars($p["name"]) ?></h3>
<div class="prices">
<span class="old">R$ <?= number_format($p["price"], 2, ",", ".") ?></span>
<span class="new">R$ <?= number_format($p["price_promo"], 2, ",", ".") ?></span>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</body>
</html>