<?php
/**
 * Cron Configuration - OneMundo Mercado
 * Configurações para tarefas agendadas
 */

// Token de segurança para crons
define('CRON_TOKEN', 'ONEMUNDO_CRON_2024');

// Intervalo de execução (em segundos)
define('CRON_INTERVAL_METRICS', 300);      // 5 minutos
define('CRON_INTERVAL_CLEANUP', 3600);     // 1 hora
define('CRON_INTERVAL_OFFERS', 60);        // 1 minuto
define('CRON_INTERVAL_WAVES', 120);        // 2 minutos

// Limites
define('CRON_MAX_EXECUTION_TIME', 300);    // 5 minutos
define('CRON_MEMORY_LIMIT', '256M');

// Log
define('CRON_LOG_PATH', dirname(__DIR__) . '/logs/cron.log');

// Função para validar token do cron
function validateCronToken() {
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
    if ($token !== CRON_TOKEN) {
        http_response_code(403);
        die('Acesso negado');
    }
}

// Função para log do cron
function cronLog($message) {
    $logFile = CRON_LOG_PATH;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}
