<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== ATUALIZADOR DE CHAVE SERPER ===\n\n";

$chaveNova = "4782ed433bf6ca6177a0f74e3bbc1cd1cbb2a731";
$chaveAntiga = "e18a06f9c82e7acf29ce997de755b75834ccecc0";

$arquivos = [
    $_SERVER['DOCUMENT_ROOT'] . '/mercado/cron_mega_crawler.php',
    $_SERVER['DOCUMENT_ROOT'] . '/mercado/cron_mega_completo.php',
    $_SERVER['DOCUMENT_ROOT'] . '/mercado/mega_crawler_50k.php',
];

$atualizados = 0;

foreach ($arquivos as $arquivo) {
    if (!file_exists($arquivo)) {
        echo "❌ Não existe: $arquivo\n";
        continue;
    }
    
    $conteudo = file_get_contents($arquivo);
    
    if (strpos($conteudo, $chaveAntiga) !== false) {
        $novoConteudo = str_replace($chaveAntiga, $chaveNova, $conteudo);
        
        if (isset($_GET['atualizar'])) {
            file_put_contents($arquivo, $novoConteudo);
            echo "✅ ATUALIZADO: " . basename($arquivo) . "\n";
            $atualizados++;
        } else {
            echo "⚠️ PRECISA ATUALIZAR: " . basename($arquivo) . "\n";
        }
    } else if (strpos($conteudo, $chaveNova) !== false) {
        echo "✅ Já tem chave nova: " . basename($arquivo) . "\n";
    } else {
        echo "🔍 Chave não encontrada: " . basename($arquivo) . "\n";
    }
}

echo "\n";

if (!isset($_GET['atualizar'])) {
    echo "👉 Use ?atualizar=1 para aplicar as mudanças\n";
} else {
    echo "✅ $atualizados arquivo(s) atualizado(s)!\n";
    
    // Testar crawler
    echo "\n=== TESTANDO CRAWLER ===\n";
    $url = "https://" . $_SERVER['HTTP_HOST'] . "/mercado/cron_mega_crawler.php?run=1";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    
    echo substr($result, 0, 500) . "\n";
}

echo "\n=== FIM ===\n";
?>
