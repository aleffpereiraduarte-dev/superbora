<?php
/**
 * ==============================================================================
 * ONEMUNDO - CRON DE ESCALACAO AUTOMATICA DE DISPUTAS
 * Executa diariamente para escalar disputas sem resposta
 * ==============================================================================
 *
 * Regras de escalacao:
 * - Disputa aberta sem resposta do vendedor por 3 dias -> escala para mediacao
 * - Disputa em mediacao sem resolucao por 7 dias -> admin intervem
 * - Disputa aguardando cliente por 5 dias -> encerra automaticamente
 *
 * Crontab: 0 8 * * * php /var/www/html/mercado/cron/disputa-escalacao.php
 */

// Apenas CLI
if (php_sapi_name() !== 'cli' && !isset($_GET['key'])) {
    die('Acesso negado');
}

// Chave de seguranca para acesso via HTTP
$secret_key = 'om_escala_2024_secret';
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

// Configuracoes
define('DIAS_ESCALAR_VENDEDOR', 3);  // Dias sem resposta do vendedor
define('DIAS_ESCALAR_MEDIACAO', 7);  // Dias em mediacao sem resolucao
define('DIAS_ENCERRAR_CLIENTE', 5);  // Dias aguardando cliente

$log = [];

function logMsg($msg) {
    global $log;
    $timestamp = date('Y-m-d H:i:s');
    $log[] = "[$timestamp] $msg";
    echo "[$timestamp] $msg\n";
}

logMsg("=== INICIANDO ESCALACAO DE DISPUTAS ===");

// ================================================================================
// 1. Escalar disputas abertas/aguardando_vendedor para mediacao
// ================================================================================
$dias_vendedor = DIAS_ESCALAR_VENDEDOR;
$interval_vendedor = "{$dias_vendedor} days";
$stmt = $pdo->query("
    SELECT d.*
    FROM om_disputes d
    WHERE d.status IN ('aberta', 'aguardando_vendedor')
      AND d.data_abertura < NOW() - INTERVAL '{$interval_vendedor}'
      AND NOT EXISTS (
          SELECT 1 FROM om_dispute_messages m
          WHERE m.dispute_id = d.id
            AND m.sender_type = 'seller'
            AND m.created_at > NOW() - INTERVAL '{$interval_vendedor}'
      )
");
$disputas_escalar = $stmt->fetchAll();

$count_escaladas = 0;
foreach ($disputas_escalar as $disputa) {
    try {
        // Atualizar status
        $pdo->prepare("
            UPDATE om_disputes
            SET status = 'mediacao', escalado_em = NOW(), escalado_motivo = 'Vendedor nao respondeu em {$dias_vendedor} dias'
            WHERE id = ?
        ")->execute([$disputa['id']]);

        // Adicionar mensagem automatica
        $pdo->prepare("
            INSERT INTO om_dispute_messages (dispute_id, sender_type, sender_id, mensagem, created_at)
            VALUES (?, 'system', 0, ?, NOW())
        ")->execute([
            $disputa['id'],
            "[SISTEMA] Esta disputa foi escalada para mediacao pois o vendedor nao respondeu em {$dias_vendedor} dias. Um mediador ira analisar o caso."
        ]);

        $count_escaladas++;
        logMsg("Disputa #{$disputa['id']} escalada para mediacao (vendedor nao respondeu)");

        // TODO: Enviar email para admin e partes envolvidas

    } catch (Exception $e) {
        logMsg("ERRO ao escalar disputa #{$disputa['id']}: " . $e->getMessage());
    }
}

logMsg("Total de disputas escaladas para mediacao: $count_escaladas");

// ================================================================================
// 2. Alertar admin sobre disputas em mediacao ha muito tempo
// ================================================================================
$dias_mediacao = DIAS_ESCALAR_MEDIACAO;
$interval_mediacao = "{$dias_mediacao} days";
$stmt = $pdo->query("
    SELECT d.*, c.email as cliente_email, c.firstname as cliente_nome
    FROM om_disputes d
    JOIN om_customers c ON d.customer_id = c.customer_id
    WHERE d.status = 'mediacao'
      AND d.escalado_em < NOW() - INTERVAL '{$interval_mediacao}'
      AND (d.admin_alerta_enviado IS NULL OR d.admin_alerta_enviado < NOW() - INTERVAL '3 days')
");
$disputas_urgentes = $stmt->fetchAll();

$count_alertas = 0;
foreach ($disputas_urgentes as $disputa) {
    try {
        // Marcar alerta enviado
        $pdo->prepare("UPDATE om_disputes SET admin_alerta_enviado = NOW() WHERE id = ?")->execute([$disputa['id']]);

        // Adicionar mensagem
        $pdo->prepare("
            INSERT INTO om_dispute_messages (dispute_id, sender_type, sender_id, mensagem, created_at)
            VALUES (?, 'system', 0, ?, NOW())
        ")->execute([
            $disputa['id'],
            "[SISTEMA] ALERTA: Esta disputa esta em mediacao ha mais de {$dias_mediacao} dias. Priorizando para resolucao."
        ]);

        $count_alertas++;
        logMsg("ALERTA: Disputa #{$disputa['id']} em mediacao ha muito tempo");

        // TODO: Enviar email urgente para admin

    } catch (Exception $e) {
        logMsg("ERRO ao alertar disputa #{$disputa['id']}: " . $e->getMessage());
    }
}

logMsg("Alertas enviados para admin: $count_alertas");

// ================================================================================
// 3. Encerrar disputas aguardando cliente sem resposta
// ================================================================================
$dias_cliente = DIAS_ENCERRAR_CLIENTE;
$interval_cliente = "{$dias_cliente} days";
$stmt = $pdo->query("
    SELECT d.*
    FROM om_disputes d
    WHERE d.status = 'aguardando_cliente'
      AND NOT EXISTS (
          SELECT 1 FROM om_dispute_messages m
          WHERE m.dispute_id = d.id
            AND m.sender_type = 'customer'
            AND m.created_at > NOW() - INTERVAL '{$interval_cliente}'
      )
      AND (
          SELECT MAX(created_at) FROM om_dispute_messages
          WHERE dispute_id = d.id
      ) < NOW() - INTERVAL '{$interval_cliente}'
");
$disputas_encerrar = $stmt->fetchAll();

$count_encerradas = 0;
foreach ($disputas_encerrar as $disputa) {
    try {
        $pdo->prepare("
            UPDATE om_disputes
            SET status = 'encerrada', encerrado_em = NOW(), encerrado_motivo = 'Cliente nao respondeu em {$dias_cliente} dias'
            WHERE id = ?
        ")->execute([$disputa['id']]);

        $pdo->prepare("
            INSERT INTO om_dispute_messages (dispute_id, sender_type, sender_id, mensagem, created_at)
            VALUES (?, 'system', 0, ?, NOW())
        ")->execute([
            $disputa['id'],
            "[SISTEMA] Esta disputa foi encerrada automaticamente pois o cliente nao respondeu em {$dias_cliente} dias."
        ]);

        $count_encerradas++;
        logMsg("Disputa #{$disputa['id']} encerrada (cliente nao respondeu)");

    } catch (Exception $e) {
        logMsg("ERRO ao encerrar disputa #{$disputa['id']}: " . $e->getMessage());
    }
}

logMsg("Total de disputas encerradas: $count_encerradas");

// ================================================================================
// 4. Expirar janelas de agrupamento antigas
// ================================================================================
$stmt = $pdo->prepare("
    UPDATE om_order_grouping_window
    SET status = 'expirada'
    WHERE status = 'ativa' AND expires_at < NOW()
");
$stmt->execute();
$count_janelas = $stmt->rowCount();

logMsg("Janelas de agrupamento expiradas: $count_janelas");

// ================================================================================
// RESUMO
// ================================================================================
logMsg("=== ESCALACAO CONCLUIDA ===");
logMsg("Disputas escaladas: $count_escaladas");
logMsg("Alertas admin: $count_alertas");
logMsg("Disputas encerradas: $count_encerradas");
logMsg("Janelas expiradas: $count_janelas");

// Salvar log
$log_file = __DIR__ . '/logs/escalacao_' . date('Y-m-d') . '.log';
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}
file_put_contents($log_file, implode("\n", $log) . "\n", FILE_APPEND);
