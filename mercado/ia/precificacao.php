<?php
require_once dirname(__DIR__) . '/config/database.php';
// ONEMUNDO MERCADO - PRECIFICACAO IA v3
// /mercado/ia/precificacao.php

$conn = getMySQLi();
$conn->set_charset('utf8mb4');

$conn->query("CREATE TABLE IF NOT EXISTS om_market_products_sale (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    partner_id INT NOT NULL,
    cost_price DECIMAL(10,2),
    sale_price DECIMAL(10,2),
    margin_percent DECIMAL(5,2),
    profit_unit DECIMAL(10,2),
    calculated_at DATETIME,
    status TINYINT(1) DEFAULT 1
) ENGINE=InnoDB");

$msg = '';

if (isset($_GET['todos'])) {
    $res = $conn->query("SELECT partner_id FROM om_market_partners WHERE status = '1'");
    $total = 0;
    while ($m = $res->fetch_assoc()) {
        $pid = $m['partner_id'];
        $sql = "SELECT product_id, price FROM om_market_products_price WHERE partner_id = $pid AND status = '1' AND price > 0";
        $res2 = $conn->query($sql);
        while ($p = $res2->fetch_assoc()) {
            $custo = floatval($p['price']);
            $venda = round($custo * 1.22, 2);
            $lucro = round($venda - $custo, 2);
            $mp = round(($lucro / $custo) * 100, 2);
            $prodId = $p['product_id'];
            $conn->query("DELETE FROM om_market_products_sale WHERE product_id = $prodId AND partner_id = $pid");
            $conn->query("INSERT INTO om_market_products_sale (product_id, partner_id, cost_price, sale_price, margin_percent, profit_unit, calculated_at, status) VALUES ($prodId, $pid, $custo, $venda, $mp, $lucro, NOW(), 1)");
            $total++;
        }
    }
    $msg = "OK: $total produtos atualizados!";
}

if (isset($_GET['p'])) {
    $pid = intval($_GET['p']);
    $total = 0;
    $sql = "SELECT product_id, price FROM om_market_products_price WHERE partner_id = $pid AND status = '1' AND price > 0";
    $res = $conn->query($sql);
    while ($p = $res->fetch_assoc()) {
        $custo = floatval($p['price']);
        $venda = round($custo * 1.22, 2);
        $lucro = round($venda - $custo, 2);
        $mp = round(($lucro / $custo) * 100, 2);
        $prodId = $p['product_id'];
        $conn->query("DELETE FROM om_market_products_sale WHERE product_id = $prodId AND partner_id = $pid");
        $conn->query("INSERT INTO om_market_products_sale (product_id, partner_id, cost_price, sale_price, margin_percent, profit_unit, calculated_at, status) VALUES ($prodId, $pid, $custo, $venda, $mp, $lucro, NOW(), 1)");
        $total++;
    }
    $msg = "Mercado $pid: $total produtos!";
}

$totProd = 0;
$avgMarg = 0;
$res = $conn->query("SELECT COUNT(*) as t, AVG(margin_percent) as m FROM om_market_products_sale");
if ($row = $res->fetch_assoc()) {
    $totProd = intval($row['t']);
    $avgMarg = round(floatval($row['m']), 1);
}

$mercados = array();
$res = $conn->query("SELECT p.partner_id, p.name, COUNT(s.id) as prods FROM om_market_partners p LEFT JOIN om_market_products_sale s ON p.partner_id = s.partner_id WHERE p.status = '1' GROUP BY p.partner_id");
while ($row = $res->fetch_assoc()) {
    $mercados[] = $row;
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Precificacao IA</title>
<style>
body{font-family:Arial;margin:0;background:#f0f0f0}
.hd{background:#3498db;color:#fff;padding:15px}
.ct{max-width:800px;margin:20px auto;padding:10px}
.al{background:#d4edda;color:#155724;padding:15px;margin-bottom:15px;border-radius:5px;font-weight:bold}
.bx{background:#fff;padding:15px;margin-bottom:15px;border-radius:5px}
table{width:100%;border-collapse:collapse}
td,th{padding:10px;border-bottom:1px solid #ddd;text-align:left}
a.bt{background:#3498db;color:#fff;padding:8px 15px;text-decoration:none;border-radius:4px;display:inline-block}
a.bt.gr{background:#27ae60}
.st{font-size:24px;font-weight:bold}
</style>
</head>
<body>
<div class="hd"><b>OneMundo - Precificacao IA</b></div>
<div class="ct">
<?php if($msg): ?><div class="al"><?php echo $msg; ?></div><?php endif; ?>
<div class="bx">
<span class="st"><?php echo $totProd; ?></span> produtos | 
<span class="st"><?php echo $avgMarg; ?>%</span> margem media
<br><br>
<a href="?todos=1" class="bt gr">ATUALIZAR TODOS</a>
</div>
<div class="bx">
<h3>Mercados</h3>
<table>
<tr><th>Mercado</th><th>Produtos</th><th></th></tr>
<?php foreach($mercados as $m): ?>
<tr>
<td><?php echo $m['name']; ?></td>
<td><?php echo intval($m['prods']); ?></td>
<td><a href="?p=<?php echo $m['partner_id']; ?>" class="bt">Atualizar</a></td>
</tr>
<?php endforeach; ?>
</table>
</div>
</div>
</body>
</html>
