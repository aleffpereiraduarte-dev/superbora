<?php
/**
 * CRON: Atualização Automática de Estatísticas do Dashboard
 * Execute via crontab: * / 1 * * * * /usr/bin/php /var/www/html/api/cron-update-dashboard.php
 * (remova os espaços em "* / 1" para "asterisco-barra-1")
 * Ou via URL com key: https://onemundo.com.br/api/cron-update-dashboard.php?key=onemundo_dash_2026
 */

// Verificar chave de segurança se acessado via HTTP
if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? '';
    if ($key !== 'onemundo_dash_2026') {
        http_response_code(403);
        die('Acesso negado');
    }
}

require_once dirname(__DIR__) . '/includes/env_loader.php';

// Conexão com banco
try {
    $pdo = new PDO(
        "mysql:host=" . env('DB_HOSTNAME', 'localhost') . ";dbname=" . env('DB_DATABASE', '') . ";charset=utf8mb4",
        env('DB_USERNAME', ''),
        env('DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log("[CRON Dashboard] Erro de conexão: " . $e->getMessage());
    die("Erro de conexão");
}

// Incluir funções do dashboard
require_once __DIR__ . '/dashboard-stats-all.php';

// Função para salvar no cache
function updateCache($pdo, $key, $data, $ttl = 60) {
    $stmt = $pdo->prepare("
        INSERT INTO om_statistics_cache (cache_key, cache_value, last_updated, ttl_seconds)
        VALUES (?, ?, NOW(), ?)
        ON CONFLICT (cache_key) DO UPDATE SET
            cache_value = EXCLUDED.cache_value,
            last_updated = NOW(),
            ttl_seconds = EXCLUDED.ttl_seconds
    ");
    $stmt->execute([$key, json_encode($data), $ttl]);
}

$start = microtime(true);

try {
    // Atualizar estatísticas gerais (TTL 60 segundos)
    $stats = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'cached' => true,
        'colaboradores' => getColaboradoresStats($pdo),
        'ponto' => getPontoStats($pdo),
        'ferias' => getFeriasStats($pdo),
        'treinamentos' => getTreinamentosStats($pdo),
        'avaliacoes' => getAvaliacoesStats($pdo),
        'recrutamento' => getRecrutamentoStats($pdo),
        'folha' => getFolhaStats($pdo),
        'bidding' => getBiddingStats($pdo),
        'escalas' => getEscalasStats($pdo),
        'emails' => getEmailsStats($pdo),
        'chat' => getChatStats($pdo),
        'relatorios' => getRelatoriosStats($pdo),
        'documentos' => getDocumentosStats($pdo),
        'score' => getScoreStats($pdo),
        'seguranca' => getSegurancaStats($pdo),
        'atividade' => getAtividadeRecente($pdo),
        'charts' => getChartsData($pdo)
    ];

    updateCache($pdo, 'dashboard_all_stats', $stats, 60);

    $elapsed = round((microtime(true) - $start) * 1000, 2);

    $msg = "[CRON Dashboard] Cache atualizado em {$elapsed}ms";
    error_log($msg);

    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Cache atualizado',
            'elapsed_ms' => $elapsed
        ]);
    } else {
        echo $msg . "\n";
    }

} catch (Exception $e) {
    error_log("[CRON Dashboard] Erro: " . $e->getMessage());
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
    } else {
        echo "Erro: " . $e->getMessage() . "\n";
    }
}
