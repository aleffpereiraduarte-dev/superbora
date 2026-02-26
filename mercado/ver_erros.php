<?php
$arquivo = __DIR__ . '/index.php';
$linhas = file($arquivo);

$erros = [17169, 17546, 17815, 18272];

echo "<h1>Linhas com erro</h1>";
echo "<pre style='background:#1e1e1e;color:#fff;padding:20px;font-size:12px;'>";

foreach ($erros as $linha) {
    echo "\n<b style='color:#ff6b6b'>═══ LINHA $linha ═══</b>\n";
    for ($i = max(0, $linha - 8); $i < min(count($linhas), $linha + 3); $i++) {
        $num = $i + 1;
        $cor = ($num == $linha) ? "background:#ff0;color:#000" : "";
        echo "<span style='color:#666'>" . str_pad($num, 5) . "</span> ";
        echo "<span style='$cor'>" . htmlspecialchars(rtrim($linhas[$i])) . "</span>\n";
    }
}

echo "</pre>";
