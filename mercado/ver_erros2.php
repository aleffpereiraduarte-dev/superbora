<?php
$arquivo = __DIR__ . '/index.php';
$linhas = file($arquivo);

$erros = [17525, 17774, 18211, 14643];

echo "<h1>Linhas com erro</h1>";

foreach ($erros as $num) {
    echo "<h3>Linha $num</h3>";
    echo "<pre style='background:#1e1e1e;color:#fff;padding:15px;font-size:11px;'>";
    
    for ($i = max(0, $num - 10); $i < min(count($linhas), $num + 5); $i++) {
        $n = $i + 1;
        $cor = ($n == $num) ? "background:#ff0;color:#000" : "";
        echo "<span style='color:#666'>" . str_pad($n, 5) . "</span> <span style='$cor'>" . htmlspecialchars(rtrim($linhas[$i])) . "</span>\n";
    }
    
    echo "</pre>";
}

// Verificar balanço atual
$conteudo = file_get_contents($arquivo);
$abre = substr_count($conteudo, '{');
$fecha = substr_count($conteudo, '}');
echo "<p><b>Balanço { }:</b> $abre - $fecha = " . ($abre - $fecha) . "</p>";
