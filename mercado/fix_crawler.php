<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain');

$pdo = getPDO();

echo "=== FIX CRAWLER ===\n\n";

// 1. Ver estado atual
$state = $pdo->query("SELECT * FROM om_crawler_completo_state WHERE id=1")->fetch(PDO::FETCH_ASSOC);
echo "Status atual: {$state['status']}\n";
echo "Última exec: {$state['ultima_execucao']}\n";
echo "Termo: {$state['termo_atual']}/487\n\n";

// 2. Resetar status para parado
$pdo->exec("UPDATE om_crawler_completo_state SET status = 'parado' WHERE id = 1");
echo "✅ Status resetado para 'parado'\n\n";

// 3. Rodar crawler
echo "🚀 Executando crawler...\n\n";

$ch = curl_init("https://" . $_SERVER['HTTP_HOST'] . "/mercado/cron_mega_crawler.php?run=1");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120
]);
$result = curl_exec($ch);
curl_close($ch);

echo $result;

// 4. Ver novo estado
echo "\n\n=== NOVO ESTADO ===\n";
$newState = $pdo->query("SELECT termo_atual, total_inseridos, ultima_execucao, status FROM om_crawler_completo_state WHERE id=1")->fetch(PDO::FETCH_ASSOC);
print_r($newState);
?>
