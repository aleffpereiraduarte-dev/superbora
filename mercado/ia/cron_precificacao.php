<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ╔══════════════════════════════════════════════════════════════════════════════════════╗
 * ║  🕐 ONEMUNDO MERCADO - CRON DE PRECIFICAÇÃO                                          ║
 * ║  Executa a IA de precificação para todos os mercados ativos                          ║
 * ╠══════════════════════════════════════════════════════════════════════════════════════╣
 * ║  Recomendado: Rodar 1x por dia às 3h da manhã                                        ║
 * ║  Crontab: 0 3 * * * php /caminho/para/cron_precificacao.php                          ║
 * ╚══════════════════════════════════════════════════════════════════════════════════════╝
 */

// Configurar para execução longa
set_time_limit(0);
ini_set('memory_limit', '512M');

// Incluir a classe de precificação
require_once __DIR__ . '/PrecificacaoInteligente.php';

// Log
function logMsg($msg) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $msg\n";
    
    // Salvar em arquivo de log
    $logFile = __DIR__ . '/logs/precificacao_' . date('Y-m-d') . '.log';
    @mkdir(__DIR__ . '/logs', 0755, true);
    file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}

logMsg("=== INÍCIO DO CRON DE PRECIFICAÇÃO ===");

try {
    // Conectar ao banco
    $conn = getMySQLi();
    $conn->set_charset('utf8mb4');
    
    if ($conn->connect_error) {
        throw new Exception("Erro de conexão: " . $conn->connect_error);
    }
    
    logMsg("✅ Conectado ao banco de dados");
    
    // Instanciar IA
    $ia = new PrecificacaoInteligente($conn);
    
    // Buscar todos os mercados ativos
    $sql = "SELECT partner_id, name FROM om_market_partners WHERE status = '1' ORDER BY partner_id";
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("Erro ao buscar parceiros: " . $conn->error);
    }
    
    $totalMercados = $result->num_rows;
    logMsg("📦 Encontrados $totalMercados mercados ativos");
    
    $estatisticas = [
        'mercados_processados' => 0,
        'total_produtos' => 0,
        'total_atualizados' => 0,
        'total_erros' => 0,
        'margem_media_geral' => 0,
        'lucro_estimado_total' => 0
    ];
    
    $somaMargens = 0;
    
    while ($mercado = $result->fetch_assoc()) {
        $partner_id = $mercado['partner_id'];
        $nome = $mercado['name'];
        
        logMsg("🏪 Processando: $nome (ID: $partner_id)");
        
        $inicio = microtime(true);
        $resultado = $ia->processarMercado($partner_id);
        $tempo = round(microtime(true) - $inicio, 2);
        
        logMsg("   ├── Produtos: {$resultado['total_produtos']}");
        logMsg("   ├── Atualizados: {$resultado['atualizados']}");
        logMsg("   ├── Erros: {$resultado['erros']}");
        logMsg("   ├── Margem média: {$resultado['margem_media']}%");
        logMsg("   └── Tempo: {$tempo}s");
        
        $estatisticas['mercados_processados']++;
        $estatisticas['total_produtos'] += $resultado['total_produtos'];
        $estatisticas['total_atualizados'] += $resultado['atualizados'];
        $estatisticas['total_erros'] += $resultado['erros'];
        $estatisticas['lucro_estimado_total'] += $resultado['lucro_total_estimado'];
        $somaMargens += $resultado['margem_media'];
        
        // Pequena pausa entre mercados
        usleep(100000); // 0.1 segundo
    }
    
    // Calcular média geral
    if ($estatisticas['mercados_processados'] > 0) {
        $estatisticas['margem_media_geral'] = round($somaMargens / $estatisticas['mercados_processados'], 2);
    }
    
    // Resumo final
    logMsg("");
    logMsg("=== RESUMO FINAL ===");
    logMsg("📊 Mercados processados: {$estatisticas['mercados_processados']}");
    logMsg("📦 Total de produtos: {$estatisticas['total_produtos']}");
    logMsg("✅ Atualizados: {$estatisticas['total_atualizados']}");
    logMsg("❌ Erros: {$estatisticas['total_erros']}");
    logMsg("📈 Margem média geral: {$estatisticas['margem_media_geral']}%");
    logMsg("💰 Lucro estimado total: R$ " . number_format($estatisticas['lucro_estimado_total'], 2, ',', '.'));
    logMsg("=== FIM DO CRON ===");
    
    // Salvar estatísticas no banco
    $sql = "
        INSERT INTO om_market_pricing_log 
        (executed_at, markets_processed, products_total, products_updated, errors, avg_margin, estimated_profit)
        VALUES (NOW(), ?, ?, ?, ?, ?, ?)
    ";
    
    // Criar tabela de log se não existir
    $conn->query("
        CREATE TABLE IF NOT EXISTS om_market_pricing_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            executed_at DATETIME,
            markets_processed INT,
            products_total INT,
            products_updated INT,
            errors INT,
            avg_margin DECIMAL(5,2),
            estimated_profit DECIMAL(12,2)
        )
    ");
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("iiiidd", 
            $estatisticas['mercados_processados'],
            $estatisticas['total_produtos'],
            $estatisticas['total_atualizados'],
            $estatisticas['total_erros'],
            $estatisticas['margem_media_geral'],
            $estatisticas['lucro_estimado_total']
        );
        $stmt->execute();
    }
    
    $conn->close();
    
} catch (Exception $e) {
    logMsg("❌ ERRO FATAL: " . $e->getMessage());
    exit(1);
}

exit(0);
?>
