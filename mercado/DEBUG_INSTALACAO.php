<?php
require_once __DIR__ . '/config/database.php';
/**
 * DEBUG - Por que não instalou?
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$BASE = __DIR__;

echo "<html><head><style>
body { font-family: monospace; background: #1e293b; color: #e2e8f0; padding: 20px; }
.ok { color: #10b981; }
.fail { color: #ef4444; }
.warn { color: #f59e0b; }
pre { background: #0f172a; padding: 10px; border-radius: 8px; overflow-x: auto; }
</style></head><body>";

echo "<h1>🔍 Debug - Instalação Batching</h1>";

// 1. Verificar pastas
echo "<h2>1. Pastas</h2>";

$folders = ['api', 'components', 'cron'];
foreach ($folders as $f) {
    $path = $BASE . '/' . $f;
    if (is_dir($path)) {
        echo "<p class='ok'>✅ Pasta /{$f}/ existe</p>";
        if (is_writable($path)) {
            echo "<p class='ok'>   ✅ Tem permissão de escrita</p>";
        } else {
            echo "<p class='fail'>   ❌ SEM permissão de escrita!</p>";
        }
    } else {
        echo "<p class='fail'>❌ Pasta /{$f}/ NÃO existe</p>";
    }
}

// 2. Verificar arquivos críticos
echo "<h2>2. Arquivos que deveriam ter sido criados</h2>";

$files = [
    'api/route_optimizer.php' => 'Otimização de rota',
    'api/driver_penalty.php' => 'Penalidade driver',
    'api/driver_arrived.php' => 'Driver chegou',
    'api/scan_progress.php' => 'Progresso scan',
    'components/desistencia-driver.php' => 'Componente desistir',
    'cron/dispatch_driver.php' => 'CRON dispatch'
];

foreach ($files as $file => $desc) {
    $path = $BASE . '/' . $file;
    if (file_exists($path)) {
        $size = filesize($path);
        echo "<p class='ok'>✅ {$file} existe ({$size} bytes) - {$desc}</p>";
    } else {
        echo "<p class='fail'>❌ {$file} NÃO EXISTE - {$desc}</p>";
    }
}

// 3. Listar o que tem na pasta api/
echo "<h2>3. Conteúdo da pasta /api/</h2>";

$apiPath = $BASE . '/api';
if (is_dir($apiPath)) {
    $files = scandir($apiPath);
    echo "<pre>";
    foreach ($files as $f) {
        if ($f != '.' && $f != '..') {
            $fullPath = $apiPath . '/' . $f;
            $size = is_file($fullPath) ? filesize($fullPath) : 'DIR';
            echo "{$f} - {$size}\n";
        }
    }
    echo "</pre>";
} else {
    echo "<p class='fail'>Pasta /api/ não existe!</p>";
}

// 4. Listar o que tem na pasta components/
echo "<h2>4. Conteúdo da pasta /components/</h2>";

$compPath = $BASE . '/components';
if (is_dir($compPath)) {
    $files = scandir($compPath);
    echo "<pre>";
    foreach ($files as $f) {
        if ($f != '.' && $f != '..') {
            $fullPath = $compPath . '/' . $f;
            $size = is_file($fullPath) ? filesize($fullPath) : 'DIR';
            echo "{$f} - {$size}\n";
        }
    }
    echo "</pre>";
} else {
    echo "<p class='fail'>Pasta /components/ não existe!</p>";
}

// 5. Tentar criar um arquivo de teste
echo "<h2>5. Teste de escrita</h2>";

$testFile = $BASE . '/api/TEST_WRITE.txt';
$result = @file_put_contents($testFile, 'teste ' . date('Y-m-d H:i:s'));

if ($result) {
    echo "<p class='ok'>✅ Consegui criar arquivo de teste em /api/</p>";
    @unlink($testFile);
} else {
    echo "<p class='fail'>❌ NÃO consegui criar arquivo em /api/ - PROBLEMA DE PERMISSÃO!</p>";
    echo "<p class='warn'>Solução: No cPanel, vá em File Manager, clique com botão direito na pasta /api/ e mude permissões para 755 ou 777</p>";
}

// 6. Verificar o instalador
echo "<h2>6. Instalador existe?</h2>";

if (file_exists($BASE . '/INSTALAR_BATCHING_AVANCADO.php')) {
    echo "<p class='ok'>✅ INSTALAR_BATCHING_AVANCADO.php existe</p>";
    echo "<p><a href='INSTALAR_BATCHING_AVANCADO.php' style='color: #60a5fa;'>👉 Clique aqui para rodar o instalador</a></p>";
} else {
    echo "<p class='fail'>❌ INSTALAR_BATCHING_AVANCADO.php NÃO existe - precisa fazer upload!</p>";
}

// 7. Info do servidor
echo "<h2>7. Info do Servidor</h2>";
echo "<pre>";
echo "PHP Version: " . phpversion() . "\n";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "Script Path: " . __FILE__ . "\n";
echo "Base Dir: " . $BASE . "\n";
echo "User: " . get_current_user() . "\n";
echo "</pre>";

// 8. Verificar banco
echo "<h2>8. Tabelas do Banco</h2>";

try {
    $pdo = getPDO();
    
    $tables = ['om_dispatch_config', 'om_driver_batches', 'om_dispatch_log', 'om_driver_penalties'];
    foreach ($tables as $t) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            echo "<p class='ok'>✅ {$t} existe ({$count} registros)</p>";
        } catch (Exception $e) {
            echo "<p class='fail'>❌ {$t} NÃO existe</p>";
        }
    }
} catch (Exception $e) {
    echo "<p class='fail'>❌ Erro conexão: " . $e->getMessage() . "</p>";
}

echo "<hr><p>Debug executado em " . date('d/m/Y H:i:s') . "</p>";
echo "</body></html>";
