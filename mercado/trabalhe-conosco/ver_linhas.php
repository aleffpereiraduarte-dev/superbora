<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$path = __DIR__ . '/includes/theme.php';
$lines = file($path);
$total = count($lines);

echo "<pre style='background:#000;color:#0f0;padding:20px;'>";
echo "Total linhas: $total\n\n";

// Mostrar linhas 700-750
echo "LINHAS 700-750:\n";
echo "================\n";
for ($i = 699; $i < 750 && $i < $total; $i++) {
    echo ($i+1) . ": " . htmlspecialchars($lines[$i]);
}

echo "\n\nLINHAS 1100-FIM:\n";
echo "================\n";
for ($i = 1099; $i < $total; $i++) {
    echo ($i+1) . ": " . htmlspecialchars($lines[$i]);
}
echo "</pre>";
