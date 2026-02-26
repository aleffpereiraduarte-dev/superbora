<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== DESBLOQUEAR TABELA ===\n\n";

$pdo = getPDO();

// Ver processos ativos
echo "1. Processos MySQL:\n";
$procs = $pdo->query("SHOW PROCESSLIST")->fetchAll(PDO::FETCH_ASSOC);
foreach ($procs as $p) {
    echo "   ID:{$p['Id']} User:{$p['User']} Time:{$p['Time']}s State:{$p['State']} Query:" . substr($p['Info'] ?? '', 0, 50) . "\n";
}

// Matar processos travados (mais de 60s)
echo "\n2. Matando processos travados...\n";
foreach ($procs as $p) {
    if ($p['Time'] > 60 && $p['User'] == 'love1') {
        try {
            $pdo->exec("KILL {$p['Id']}");
            echo "   Matou ID {$p['Id']}\n";
        } catch (Exception $e) {
            echo "   Erro ao matar {$p['Id']}: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n3. Desbloqueando tabelas...\n";
$pdo->exec("UNLOCK TABLES");
echo "   OK\n";

echo "\n4. Testando SELECT...\n";
$r = $pdo->query("SELECT status FROM om_crawler_completo_state WHERE id=1")->fetch();
echo "   Status: " . $r['status'] . "\n";

echo "\n=== FIM ===\n";
?>
