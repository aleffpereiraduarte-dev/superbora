<?php
/**
 * 🔍 CAPTURAR ERRO 500
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<h1>🔍 Capturar Erro 500</h1>";
echo "<style>body{font-family:sans-serif;background:#1e293b;color:#e2e8f0;padding:40px;} .ok{color:#10b981;} .erro{color:#ef4444;} pre{background:#0f172a;padding:15px;border-radius:8px;overflow-x:auto;white-space:pre-wrap;}</style>";

$msg = $_GET['msg'] ?? 'voce ta bem';
echo "<p>Testando: <strong>\"$msg\"</strong></p>";

// Verificar log de erros do PHP
echo "<h2>📋 Últimos Erros do PHP</h2>";

$errorLog = ini_get('error_log');
echo "<p>Error log: $errorLog</p>";

// Tentar ler error_log do Apache/PHP
$possibleLogs = [
    '/home/tt7vfgytpo9h/logs/error.log',
    '/var/www/html/error_log',
    __DIR__ . '/error_log',
    '/var/log/apache2/error.log',
    '/var/log/httpd/error_log'
];

foreach ($possibleLogs as $logFile) {
    if (file_exists($logFile) && is_readable($logFile)) {
        echo "<h3>📄 $logFile</h3>";
        $lines = file($logFile);
        $lastLines = array_slice($lines, -20);
        echo "<pre>" . htmlspecialchars(implode("", $lastLines)) . "</pre>";
        break;
    }
}

// Agora vamos incluir o one.php com tratamento de erro
echo "<h2>🧪 Executar one.php com Debug</h2>";

// Configurar handler de erro
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo "<p class='erro'>❌ PHP Error [$errno]: $errstr</p>";
    echo "<p>Arquivo: $errfile:$errline</p>";
    return true;
});

set_exception_handler(function($e) {
    echo "<p class='erro'>❌ Exception: " . $e->getMessage() . "</p>";
    echo "<p>Arquivo: " . $e->getFile() . ":" . $e->getLine() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<p class='erro'>❌ Fatal Error: " . $error['message'] . "</p>";
        echo "<p>Arquivo: " . $error['file'] . ":" . $error['line'] . "</p>";
    }
});

// Simular a requisição
$_GET['action'] = 'send';
$_GET['message'] = $msg;
$_POST = [];

echo "<p>Incluindo one.php...</p>";
echo "<pre>";

ob_start();

try {
    // Verificar se session já está ativa
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Incluir one.php
    $onePath = __DIR__ . '/one.php';
    
    if (!file_exists($onePath)) {
        throw new Exception("one.php não encontrado em: $onePath");
    }
    
    include($onePath);
    
} catch (Throwable $e) {
    echo "\n\n❌ ERRO CAPTURADO:\n";
    echo "Tipo: " . get_class($e) . "\n";
    echo "Mensagem: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString();
}

$output = ob_get_clean();
echo htmlspecialchars($output);
echo "</pre>";

// Verificar se houve output JSON válido
if ($output) {
    $json = json_decode($output, true);
    if ($json) {
        echo "<h3>✅ Resposta JSON válida:</h3>";
        echo "<pre>" . print_r($json, true) . "</pre>";
    }
}
