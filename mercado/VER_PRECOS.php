<?php
require_once __DIR__ . '/config/database.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_name('OCSESSID');
session_start();

$conn = getMySQLi();
$conn->set_charset('utf8mb4');

$partner_id = $_SESSION['market_partner_id'] ?? 100;

echo "<pre>";
echo "=== VERIFICAR PRECOS ===\n\n";

echo "Partner atual: #$partner_id\n\n";

// 1. Produtos com preco IA
$r = $conn->query("SELECT COUNT(*) as c FROM om_market_products_sale WHERE partner_id = $partner_id");
$total_ia = $r ? $r->fetch_assoc()['c'] : 0;
echo "1. Produtos com preco IA: $total_ia\n";

// 2. Produtos do parceiro
$r = $conn->query("SELECT COUNT(*) as c FROM om_market_products_price WHERE partner_id = $partner_id AND status=1 AND price>0");
$total_parceiro = $r ? $r->fetch_assoc()['c'] : 0;
echo "2. Produtos do parceiro: $total_parceiro\n\n";

// 3. Comparar 5 produtos
echo "3. COMPARACAO DE PRECOS:\n";
echo "------------------------\n";

$sql = "SELECT pb.name, pp.price as p_parceiro, ps.sale_price as p_ia
        FROM om_market_products_price pp
        JOIN om_market_products_base pb ON pp.product_id = pb.product_id
        LEFT JOIN om_market_products_sale ps ON pp.product_id = ps.product_id AND ps.partner_id = $partner_id
        WHERE pp.partner_id = $partner_id AND pp.status = '1' AND pp.price > 0
        LIMIT 5";

$r = $conn->query($sql);
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $nome = substr($row['name'], 0, 25);
        $parceiro = "R$ " . number_format($row['p_parceiro'], 2);
        $ia = $row['p_ia'] ? "R$ " . number_format($row['p_ia'], 2) : "SEM IA";
        echo "$nome | Parceiro: $parceiro | IA: $ia\n";
    }
}

echo "\n";

// 4. Resultado
echo "4. RESULTADO:\n";
echo "-------------\n";

if ($total_ia == 0) {
    echo "❌ PRECOS DA IA NAO CALCULADOS!\n";
    echo "   Cliente ve preco do parceiro (sem margem OneMundo)\n";
    echo "\n";
    echo "   SOLUCAO: Rodar CRON de precificacao\n";
} else {
    echo "✅ Precos da IA existem ($total_ia produtos)\n";
}

echo "\n=== FIM ===\n";
echo "</pre>";

echo "<br><a href='/mercado/'>Ir para Mercado</a>";
?>
