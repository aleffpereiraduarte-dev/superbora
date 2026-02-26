<?php
$path = __DIR__ . '/includes/theme.php';
$lines = file($path);

echo "<pre style='background:#000;color:#0f0;padding:20px;font-size:12px;'>";

echo "LINHAS 1-30 (início):\n";
echo "================\n";
for ($i = 0; $i < 30 && $i < count($lines); $i++) {
    echo ($i+1) . ": " . htmlspecialchars($lines[$i]);
}

echo "\n\nLINHAS 200-230 (pageStart):\n";
echo "================\n";
for ($i = 199; $i < 230 && $i < count($lines); $i++) {
    echo ($i+1) . ": " . htmlspecialchars($lines[$i]);
}

echo "\n\nLINHAS 80-120 (themeCSS):\n";
echo "================\n";
for ($i = 79; $i < 120 && $i < count($lines); $i++) {
    echo ($i+1) . ": " . htmlspecialchars($lines[$i]);
}

echo "</pre>";
