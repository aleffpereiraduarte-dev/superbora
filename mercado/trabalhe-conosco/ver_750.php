<?php
$path = __DIR__ . '/includes/theme.php';
$lines = file($path);

echo "<pre style='background:#000;color:#0f0;padding:20px;'>";
echo "LINHAS 750-820:\n";
echo "================\n";
for ($i = 749; $i < 820 && $i < count($lines); $i++) {
    echo ($i+1) . ": " . htmlspecialchars($lines[$i]);
}
echo "</pre>";
