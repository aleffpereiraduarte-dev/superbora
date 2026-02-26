<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = getPDO();

echo "=== STATUS CRAWLER ===\n\n";

// Estado atual
$state = $pdo->query("SELECT * FROM om_crawler_completo_state WHERE id=1")->fetch(PDO::FETCH_ASSOC);
echo "Termo atual: {$state['termo_atual']}\n";
echo "Total inseridos: {$state['total_inseridos']}\n";
echo "Última exec: {$state['ultima_execucao']}\n\n";

// Verificar arquivo do crawler
$crawlerFile = $_SERVER['DOCUMENT_ROOT'] . '/mercado/cron_mega_crawler.php';
if (file_exists($crawlerFile)) {
    $conteudo = file_get_contents($crawlerFile);
    
    // Contar termos
    preg_match_all('/"([^"]+)"/', $conteudo, $matches);
    $termos = array_filter($matches[1], function($t) {
        return strlen($t) > 3 && strlen($t) < 50 && !strpos($t, 'http') && !strpos($t, '=');
    });
    
    echo "Total de termos no arquivo: " . count($termos) . "\n";
    
    // Mostrar últimos 10 termos
    echo "\nÚltimos 10 termos:\n";
    $ultimos = array_slice($termos, -10);
    foreach ($ultimos as $i => $t) {
        echo "  - $t\n";
    }
}

// Catálogo
$total = $pdo->query("SELECT COUNT(*) FROM om_market_products_base")->fetchColumn();
echo "\nTotal catálogo: $total\n";

// Ações
echo "\n=== AÇÕES ===\n";
if (isset($_GET['reset'])) {
    // Resetar para termo 0 (recomeçar)
    $pdo->exec("UPDATE om_crawler_completo_state SET termo_atual = 0, status = 'parado' WHERE id = 1");
    echo "✅ Resetado para termo 0!\n";
} elseif (isset($_GET['continuar'])) {
    // Continuar de onde parou mas resetar status
    $pdo->exec("UPDATE om_crawler_completo_state SET status = 'parado' WHERE id = 1");
    echo "✅ Status resetado, vai continuar do termo {$state['termo_atual']}\n";
} elseif (isset($_GET['pular'])) {
    // Pular para os novos termos (após 487)
    $pdo->exec("UPDATE om_crawler_completo_state SET termo_atual = 487, status = 'parado' WHERE id = 1");
    echo "✅ Pulou para termo 487 (novos termos)!\n";
} else {
    echo "?reset=1 - Recomeçar do zero\n";
    echo "?continuar=1 - Continuar de onde parou\n";
    echo "?pular=1 - Pular para os novos termos (487+)\n";
}

echo "\n=== FIM ===\n";
?>
