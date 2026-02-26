<?php
require_once __DIR__ . '/config/database.php';
/**
 * ⚡ CONFIGURAR MARIA COMO FUNCIONÁRIA
 */

$conn = getMySQLi();
$conn->set_charset('utf8mb4');

echo "<h1>⚡ Configurando Maria</h1>";
echo "<style>body{font-family:sans-serif;background:#0a0a0a;color:#fff;padding:20px;max-width:800px;margin:auto}.ok{color:#10b981}.box{background:#151515;padding:20px;border-radius:12px;margin:15px 0}</style>";

echo "<div class='box'>";

// Configurar Maria como funcionária
$sql = "UPDATE om_market_shoppers SET 
    is_employee = 1, 
    hourly_rate = 0, 
    commission_rate = 0,
    status = 'online',
    is_available = 1
    WHERE email = 'maria@onemundo.com'";

if ($conn->query($sql)) {
    echo "<p class='ok'>✅ Maria configurada como funcionária fixa (sem comissão)</p>";
} else {
    echo "<p style='color:#ef4444'>❌ Erro: " . $conn->error . "</p>";
}

// Mostrar shoppers atualizados
echo "<h3>👥 Shoppers Atualizados:</h3>";
$result = $conn->query("SELECT shopper_id, name, email, is_employee, commission_rate, status FROM om_market_shoppers");
echo "<table style='width:100%;border-collapse:collapse'>";
echo "<tr style='background:#1a1a1a'><th style='padding:10px;text-align:left'>ID</th><th>Nome</th><th>Email</th><th>Funcionário?</th><th>Comissão</th><th>Status</th></tr>";
while ($s = $result->fetch_assoc()) {
    $func = $s['is_employee'] ? '✅ Sim' : '❌ Não';
    $com = $s['is_employee'] ? 'N/A' : $s['commission_rate'] . '%';
    echo "<tr style='border-bottom:1px solid #333'>";
    echo "<td style='padding:10px'>{$s['shopper_id']}</td>";
    echo "<td>{$s['name']}</td>";
    echo "<td>{$s['email']}</td>";
    echo "<td>{$func}</td>";
    echo "<td>{$com}</td>";
    echo "<td>{$s['status']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>🔗 Próximos Passos:</h3>";
echo "<p><a href='shopper/login.php' style='color:#10b981'>→ Testar login da Maria</a></p>";
echo "<p><a href='gerenciar_shoppers.php' style='color:#10b981'>→ Gerenciar Shoppers</a></p>";

echo "</div>";
$conn->close();
?>
