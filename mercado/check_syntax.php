<?php
echo "<h1>Verificando index.php</h1>";

$arquivo = __DIR__ . '/index.php';

// Verificar sintaxe com php -l
$output = shell_exec("php -l " . escapeshellarg($arquivo) . " 2>&1");
echo "<pre>" . htmlspecialchars($output) . "</pre>";

// Se tiver erro, mostrar a linha
if (preg_match('/line (\d+)/', $output, $m)) {
    $linha = (int)$m[1];
    $linhas = file($arquivo);
    
    echo "<h2>Contexto do erro (linha $linha):</h2>";
    echo "<pre>";
    for ($i = max(0, $linha - 5); $i < min(count($linhas), $linha + 5); $i++) {
        $num = $i + 1;
        $destaque = ($num == $linha) ? " style='background:#ff0;color:#000'" : "";
        echo "<span$destaque>" . str_pad($num, 5, ' ', STR_PAD_LEFT) . ": " . htmlspecialchars($linhas[$i]) . "</span>";
    }
    echo "</pre>";
}

echo "<p><a href='/mercado/'>Voltar</a></p>";
