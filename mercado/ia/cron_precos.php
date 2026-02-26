<?php
require_once dirname(__DIR__) . '/config/database.php';
// CRON PRECIFICACAO - OneMundo Mercado
// Rodar: php /var/www/html/mercado/ia/cron_precos.php
// Crontab: 0 4 * * * php /var/www/html/mercado/ia/cron_precos.php >> /var/www/html/mercado/ia/cron.log 2>&1

$conn = getMySQLi();
$conn->set_charset('utf8mb4');

echo date('Y-m-d H:i:s') . " - INICIO\n";

$res = $conn->query("SELECT partner_id, name FROM om_market_partners WHERE status = '1'");
$total = 0;

while ($m = $res->fetch_assoc()) {
    $pid = $m['partner_id'];
    $count = 0;
    
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
        $count++;
    }
    
    echo "  {$m['name']}: $count produtos\n";
    $total += $count;
}

echo date('Y-m-d H:i:s') . " - FIM: $total produtos\n\n";
$conn->close();
?>
