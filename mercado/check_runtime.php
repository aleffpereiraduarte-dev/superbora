<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Testando index.php</h1>";

// Tentar incluir e capturar erro
try {
    ob_start();
    
    // Definir variáveis que podem estar faltando
    $_SESSION = $_SESSION ?? [];
    
    // Incluir com timeout
    set_time_limit(10);
    
    include __DIR__ . '/index.php';
    
    $output = ob_get_clean();
    echo "<p>✅ Executou sem erro fatal</p>";
    echo "<p>Tamanho output: " . strlen($output) . " bytes</p>";
    
} catch (Throwable $e) {
    ob_end_clean();
    echo "<h2>❌ ERRO:</h2>";
    echo "<p><strong>" . get_class($e) . "</strong></p>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Arquivo: " . $e->getFile() . "</p>";
    echo "<p>Linha: " . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
