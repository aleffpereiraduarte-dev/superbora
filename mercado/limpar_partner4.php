<?php
require_once __DIR__ . '/config/database.php';
/**
 * LIMPAR REGISTROS DE LIXO - PARTNER 4
 */

header('Content-Type: text/html; charset=utf-8');

$conn = getMySQLi();
if ($conn->connect_error) die("Erro: " . $conn->connect_error);
$conn->set_charset('utf8mb4');

echo "<html><head><meta charset='UTF-8'><title>Limpar Lixo</title>";
echo "<style>
body { font-family: Arial, sans-serif; background: #1a1a2e; color: #fff; padding: 40px; max-width: 600px; margin: 0 auto; }
h1 { color: #00d4aa; }
.box { background: #16213e; padding: 20px; border-radius: 10px; margin: 20px 0; }
.ok { color: #00d4aa; font-size: 24px; }
.erro { color: #ff6b6b; }
.btn { display: inline-block; padding: 15px 30px; background: #ff6b6b; color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 10px 0; }
.btn:hover { background: #ff5252; }
pre { background: #0f3460; padding: 15px; border-radius: 8px; }
</style></head><body>";

$acao = $_GET['acao'] ?? 'ver';

if ($acao == 'ver') {
    // Mostrar o que vai ser apagado
    echo "<h1>🧹 Limpar Lixo - Partner 4</h1>";
    
    $r = $conn->query("SELECT COUNT(*) as t FROM om_market_products_price WHERE partner_id = 4");
    $total = $r->fetch_assoc()['t'];
    
    echo "<div class='box'>";
    echo "<p>Serão apagados: <strong style='color:#ff6b6b; font-size:24px;'>$total registros</strong></p>";
    echo "<p>Partner ID: 4 (não existe)</p>";
    echo "<p>São dados sem nome, marca ou código de barras.</p>";
    echo "</div>";
    
    echo "<div class='box'>";
    echo "<p>⚠️ <strong>Isso é irreversível!</strong></p>";
    echo "<a href='?acao=apagar' class='btn' onclick=\"return confirm('Tem certeza? Vai apagar $total registros!')\">🗑️ APAGAR TUDO</a>";
    echo "</div>";
    
    // Mostrar o que vai ficar
    echo "<div class='box'>";
    echo "<h3>📋 Depois da limpeza vai ficar:</h3>";
    
    $r = $conn->query("
        SELECT partner_id, COUNT(*) as t 
        FROM om_market_products_price 
        WHERE partner_id != 4
        GROUP BY partner_id
    ");
    
    echo "<pre>";
    while ($row = $r->fetch_assoc()) {
        echo "Partner {$row['partner_id']}: {$row['t']} produtos\n";
    }
    echo "</pre>";
    echo "</div>";
    
} elseif ($acao == 'apagar') {
    echo "<h1>🧹 Limpando...</h1>";
    
    // Contar antes
    $r = $conn->query("SELECT COUNT(*) as t FROM om_market_products_price WHERE partner_id = 4");
    $antes = $r->fetch_assoc()['t'];
    
    // Apagar
    $conn->query("DELETE FROM om_market_products_price WHERE partner_id = 4");
    $apagados = $conn->affected_rows;
    
    // Contar depois
    $r = $conn->query("SELECT COUNT(*) as t FROM om_market_products_price");
    $depois = $r->fetch_assoc()['t'];
    
    echo "<div class='box'>";
    echo "<p class='ok'>✅ LIMPEZA CONCLUÍDA!</p>";
    echo "<p>Registros apagados: <strong>$apagados</strong></p>";
    echo "<p>Registros restantes: <strong>$depois</strong></p>";
    echo "</div>";
    
    // Mostrar o que ficou
    echo "<div class='box'>";
    echo "<h3>📋 Situação atual:</h3>";
    
    $r = $conn->query("
        SELECT pp.partner_id, p.name as parceiro, COUNT(*) as produtos
        FROM om_market_products_price pp
        LEFT JOIN om_market_partners p ON pp.partner_id = p.partner_id
        GROUP BY pp.partner_id
    ");
    
    echo "<pre>";
    if ($r->num_rows == 0) {
        echo "Nenhum registro de preço restante.\n";
        echo "Parceiros precisam cadastrar produtos pelo painel.\n";
    } else {
        while ($row = $r->fetch_assoc()) {
            $parceiro = $row['parceiro'] ?: '(não existe)';
            echo "Partner {$row['partner_id']} ({$parceiro}): {$row['produtos']} produtos\n";
        }
    }
    echo "</pre>";
    echo "</div>";
    
    echo "<p><a href='/mercado/diagnostico_mercado_cep.php'>Ver Diagnóstico Completo</a></p>";
}

$conn->close();
echo "</body></html>";
