<?php
require_once __DIR__ . '/config/database.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔧 Adicionar Coluna</h1>";
echo "<style>body{font-family:Arial;background:#1a1a2e;color:#fff;padding:20px;} .ok{color:#10b981;} .erro{color:#ef4444;}</style>";

try {
    $pdo = getPDO();
    echo "<p class='ok'>✅ Conectado ao VPS!</p>";
    
    // Verifica se coluna já existe
    $cols = $pdo->query("SHOW COLUMNS FROM om_one_brain_universal LIKE 'resposta_curta'")->fetch();
    
    if ($cols) {
        echo "<p class='ok'>✅ Coluna resposta_curta já existe!</p>";
    } else {
        $pdo->exec("ALTER TABLE om_one_brain_universal ADD COLUMN resposta_curta TEXT NULL AFTER resposta");
        echo "<p class='ok'>✅ Coluna resposta_curta adicionada!</p>";
    }
    
    // Verifica outras colunas necessárias
    $necessarias = ['pergunta_normalizada', 'palavras_chave', 'uso_count', 'prioridade'];
    
    foreach ($necessarias as $col) {
        $existe = $pdo->query("SHOW COLUMNS FROM om_one_brain_universal LIKE '$col'")->fetch();
        if ($existe) {
            echo "<p class='ok'>✅ $col existe</p>";
        } else {
            echo "<p class='erro'>❌ $col NÃO existe - adicionando...</p>";
            
            if ($col == 'pergunta_normalizada') {
                $pdo->exec("ALTER TABLE om_one_brain_universal ADD COLUMN pergunta_normalizada VARCHAR(500) NULL");
            } elseif ($col == 'palavras_chave') {
                $pdo->exec("ALTER TABLE om_one_brain_universal ADD COLUMN palavras_chave TEXT NULL");
            } elseif ($col == 'uso_count') {
                $pdo->exec("ALTER TABLE om_one_brain_universal ADD COLUMN uso_count INT DEFAULT 0");
            } elseif ($col == 'prioridade') {
                $pdo->exec("ALTER TABLE om_one_brain_universal ADD COLUMN prioridade INT DEFAULT 5");
            }
            
            echo "<p class='ok'>✅ $col adicionada!</p>";
        }
    }
    
    echo "<h2>✅ Tudo pronto!</h2>";
    echo "<p><a href='debug_one_api.php?q=oi' style='color:#10b981;'>Testar ONE agora</a></p>";
    
} catch (Exception $e) {
    echo "<p class='erro'>❌ Erro: " . $e->getMessage() . "</p>";
}
