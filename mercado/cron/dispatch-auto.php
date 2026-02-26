<?php
/**
 * ==============================================================================
 * ONEMUNDO - CRON DE DISPATCH AUTOMATICO
 * ==============================================================================
 *
 * Este cron executa a cada minuto para:
 * 1. Despachar automaticamente a etapa 2 quando etapa 1 e concluida
 * 2. Fazer retry de dispatches sem motorista apos X minutos
 * 3. Atualizar status sincronizando com BoraUm
 *
 * Crontab: * * * * * php /var/www/html/mercado/cron/dispatch-auto.php
 */

// Apenas CLI
if (php_sapi_name() !== 'cli' && !isset($_GET['key'])) {
    die('Acesso negado');
}

// Chave de seguranca para acesso HTTP
$secret_key = 'om_dispatch_2024_auto';
if (isset($_GET['key']) && $_GET['key'] !== $secret_key) {
    die('Chave invalida');
}

$_oc_root = dirname(__DIR__, 2);
if (file_exists($_oc_root . '/config.php')) {
    require_once $_oc_root . '/config.php';
}

try {
    $pdo = new PDO(
        "pgsql:host=147.93.12.236;port=5432;dbname=love1",
        'love1', 'Aleff2009@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    logMsg("ERRO: Falha na conexao - " . $e->getMessage());
    exit(1);
}

// URL da API
define('DISPATCH_API_URL', 'http://localhost/mercado/api/dispatch-ponto-apoio.php');
define('BORAUM_API_URL', 'http://localhost/boraum/api_entrega.php');

// Configuracoes
define('TEMPO_RETRY_MINUTOS', 5);  // Tempo para retry sem motorista

$log = [];

function logMsg($msg) {
    global $log;
    $timestamp = date('Y-m-d H:i:s');
    $log[] = "[$timestamp] $msg";
    echo "[$timestamp] $msg\n";
}

function callApi($url, $action, $data = []) {
    $fullUrl = $url . '?action=' . $action;

    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true) ?? ['success' => false];
}

logMsg("=== INICIANDO DISPATCH AUTOMATICO ===");

// ================================================================================
// 1. Auto-dispatch etapa 2 quando etapa 1 foi concluida
// ================================================================================
$stmt = $pdo->query("
    SELECT d.*
    FROM om_dispatch_ponto_apoio d
    WHERE d.etapa1_status = 'entregue'
      AND d.etapa2_status = 'pendente'
      AND d.etapa2_boraum_id IS NULL
");
$pendentes_etapa2 = $stmt->fetchAll();

$count_etapa2 = 0;
foreach ($pendentes_etapa2 as $dispatch) {
    try {
        $result = callApi(DISPATCH_API_URL, 'dispatch_etapa2', ['order_id' => $dispatch['order_id']]);

        if ($result['success'] ?? false) {
            $count_etapa2++;
            logMsg("Etapa 2 despachada para pedido #{$dispatch['order_id']}");
        } else {
            logMsg("ERRO ao despachar etapa 2 pedido #{$dispatch['order_id']}: " . ($result['error'] ?? 'Erro desconhecido'));
        }
    } catch (Exception $e) {
        logMsg("ERRO exception pedido #{$dispatch['order_id']}: " . $e->getMessage());
    }
}

logMsg("Etapa 2 despachada para $count_etapa2 pedidos");

// ================================================================================
// 2. Retry de dispatches buscando motorista ha muito tempo
// ================================================================================
$tempo_retry = TEMPO_RETRY_MINUTOS;
$interval_retry = "{$tempo_retry} minutes";

// Retry etapa 1
$stmt = $pdo->prepare("
    SELECT d.*
    FROM om_dispatch_ponto_apoio d
    WHERE d.etapa1_status = 'buscando'
      AND d.etapa1_dispatched_at < NOW() - INTERVAL '{$interval_retry}'
      AND d.etapa1_motorista_id IS NULL
");
$stmt->execute();
$retry_etapa1 = $stmt->fetchAll();

$count_retry1 = 0;
foreach ($retry_etapa1 as $dispatch) {
    try {
        $result = callApi(BORAUM_API_URL, 'retry_dispatch', ['pedido_id' => $dispatch['etapa1_boraum_id']]);

        if ($result['ok'] ?? false) {
            $count_retry1++;
            logMsg("Retry etapa 1 pedido #{$dispatch['order_id']}");
        }
    } catch (Exception $e) {
        logMsg("ERRO retry etapa 1 pedido #{$dispatch['order_id']}: " . $e->getMessage());
    }
}

// Retry etapa 2
$stmt = $pdo->prepare("
    SELECT d.*
    FROM om_dispatch_ponto_apoio d
    WHERE d.etapa2_status = 'buscando'
      AND d.etapa2_dispatched_at < NOW() - INTERVAL '{$interval_retry}'
      AND d.etapa2_motorista_id IS NULL
");
$stmt->execute();
$retry_etapa2 = $stmt->fetchAll();

$count_retry2 = 0;
foreach ($retry_etapa2 as $dispatch) {
    try {
        $result = callApi(BORAUM_API_URL, 'retry_dispatch', ['pedido_id' => $dispatch['etapa2_boraum_id']]);

        if ($result['ok'] ?? false) {
            $count_retry2++;
            logMsg("Retry etapa 2 pedido #{$dispatch['order_id']}");
        }
    } catch (Exception $e) {
        logMsg("ERRO retry etapa 2 pedido #{$dispatch['order_id']}: " . $e->getMessage());
    }
}

logMsg("Retries: etapa1=$count_retry1, etapa2=$count_retry2");

// ================================================================================
// 3. Sincronizar status com BoraUm
// ================================================================================
$stmt = $pdo->query("
    SELECT d.*
    FROM om_dispatch_ponto_apoio d
    WHERE (d.etapa1_status IN ('buscando', 'aceito', 'coletando', 'entregando')
           OR d.etapa2_status IN ('buscando', 'aceito', 'coletando', 'entregando'))
    LIMIT 50
");
$dispatches_ativos = $stmt->fetchAll();

$count_sync = 0;
foreach ($dispatches_ativos as $dispatch) {
    // Usar a API de status para sincronizar (ela faz a sincronizacao internamente)
    callApi(DISPATCH_API_URL, 'status', ['order_id' => $dispatch['order_id']]);
    $count_sync++;
}

logMsg("Sincronizados $count_sync dispatches ativos");

// ================================================================================
// RESUMO
// ================================================================================
logMsg("=== DISPATCH AUTOMATICO CONCLUIDO ===");
logMsg("Etapa 2 despachados: $count_etapa2");
logMsg("Retries etapa 1: $count_retry1");
logMsg("Retries etapa 2: $count_retry2");
logMsg("Sincronizados: $count_sync");

// Salvar log
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
$log_file = $log_dir . '/dispatch_auto_' . date('Y-m-d') . '.log';
file_put_contents($log_file, implode("\n", $log) . "\n", FILE_APPEND);
