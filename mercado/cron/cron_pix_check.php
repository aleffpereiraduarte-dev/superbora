<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ==============================================================================
 * CRON - VERIFICACAO DE PAGAMENTOS PIX
 * ==============================================================================
 *
 * Verifica pagamentos PIX pendentes e atualiza status
 *
 * CRON: (star)/2 * * * * php /var/www/html/mercado/cron/cron_pix_check.php
 *
 * FUNCOES:
 * 1. Buscar pagamentos PIX pendentes (>3 min)
 * 2. Consultar API Pagar.me para status atual
 * 3. Atualizar pedido se pago
 * 4. Expirar PIX antigos (>60 min)
 *
 * ==============================================================================
 */

$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
    header('Content-Type: application/json; charset=utf-8');
}

// Configuracoes
define('PAGARME_SECRET_KEY', 'sk_e95918f47a414bffaaf2848cd691783e');
define('PAGARME_API_URL', 'https://api.pagar.me/core/v5');
define('PIX_CHECK_AFTER_MINUTES', 3);    // Verificar PIX pendentes apos 3 min
define('PIX_EXPIRE_AFTER_MINUTES', 60);  // Expirar PIX apos 60 min

$db_host = '147.93.12.236';
$db_name = 'love1';
$db_user = 'love1';
$db_pass_local = 'Aleff2009@';

$start_time = microtime(true);
$log = [];

function cron_log($msg, $type = 'info') {
    global $log, $is_cli;
    $line = "[" . date('H:i:s') . "] $msg";
    $log[] = ['time' => date('H:i:s'), 'msg' => $msg, 'type' => $type];
    if ($is_cli) echo $line . "\n";
}

// Conexao
try {
    $pdo = new PDO("pgsql:host=$db_host;port=5432;dbname=$db_name", $db_user, $db_pass_local, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    cron_log("ERRO DB: " . $e->getMessage(), 'error');
    if (!$is_cli) echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit(1);
}

cron_log("================================================================");
cron_log("CRON PIX CHECK - INICIANDO");
cron_log("================================================================");

$stats = [
    'checked' => 0,
    'paid' => 0,
    'expired' => 0,
    'errors' => 0
];

// ==============================================================================
// 1. BUSCAR PAGAMENTOS PIX PENDENTES
// ==============================================================================

cron_log("1. Buscando pagamentos PIX pendentes...");

$pix_expire_plus5 = PIX_EXPIRE_AFTER_MINUTES + 5;
$pix_check_after = PIX_CHECK_AFTER_MINUTES;

try {
    // Buscar transacoes PIX pendentes criadas ha mais de 3 minutos
    $stmt = $pdo->query("
        SELECT
            t.id, t.charge_id, t.order_id, t.pedido_id, t.valor, t.status,
            t.created_at, o.order_number,
            EXTRACT(EPOCH FROM (NOW() - t.created_at))/60 as minutes_old
        FROM om_pagarme_transacoes t
        LEFT JOIN om_market_orders o ON (o.order_id = t.order_id OR o.order_id = t.pedido_id)
        WHERE t.tipo = 'pix'
        AND t.status IN ('pending', 'waiting_payment', 'processing')
        AND t.created_at > NOW() - INTERVAL '{$pix_expire_plus5} minutes'
        AND t.created_at < NOW() - INTERVAL '{$pix_check_after} minutes'
        ORDER BY t.created_at ASC
        LIMIT 30
    ");

    $transactions = $stmt->fetchAll();
    cron_log("   " . count($transactions) . " transacao(es) para verificar");

} catch (Exception $e) {
    cron_log("   ERRO: " . $e->getMessage(), 'error');
    $transactions = [];
}

// ==============================================================================
// 2. VERIFICAR CADA TRANSACAO NA API PAGARME
// ==============================================================================

cron_log("2. Verificando status na API Pagar.me...");

foreach ($transactions as $tx) {
    $charge_id = $tx['charge_id'];
    $order_id = $tx['order_id'] ?? $tx['pedido_id'];
    $minutes = $tx['minutes_old'];

    cron_log("   -> Charge: {$charge_id} ({$minutes} min)");
    $stats['checked']++;

    // Verificar se PIX expirou (>60 min)
    if ($minutes >= PIX_EXPIRE_AFTER_MINUTES) {
        cron_log("      PIX EXPIRADO ({$minutes} min)", 'warning');

        // Atualizar transacao
        $pdo->prepare("UPDATE om_pagarme_transacoes SET status = 'expired', updated_at = NOW() WHERE id = ?")
            ->execute([$tx['id']]);

        // Atualizar pedido
        if ($order_id) {
            $pdo->prepare("UPDATE om_market_orders SET payment_status = 'failed', status = 'cancelled', updated_at = NOW() WHERE order_id = ?")
                ->execute([$order_id]);

            // Notificar no chat
            try {
                $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, message, created_at) VALUES (?, 'system', 0, ?, NOW())")
                    ->execute([$order_id, 'O tempo para pagamento via PIX expirou. Por favor, tente novamente.']);
            } catch (Exception $e) {}
        }

        $stats['expired']++;
        continue;
    }

    // Consultar API Pagar.me
    $ch = curl_init(PAGARME_API_URL . "/charges/{$charge_id}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(PAGARME_SECRET_KEY . ':')
        ]
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        cron_log("      ERRO API: HTTP {$http_code}", 'error');
        $stats['errors']++;
        continue;
    }

    $charge = json_decode($response, true);
    $new_status = $charge['status'] ?? null;

    cron_log("      Status: {$new_status}");

    // Se foi pago, atualizar tudo
    if ($new_status === 'paid') {
        cron_log("      PIX PAGO!", 'success');

        // Atualizar transacao
        $pdo->prepare("UPDATE om_pagarme_transacoes SET status = 'paid', updated_at = NOW() WHERE id = ?")
            ->execute([$tx['id']]);

        // Atualizar pedido
        if ($order_id) {
            $pdo->prepare("
                UPDATE om_market_orders
                SET payment_status = 'paid',
                    status = CASE WHEN status IN ('pending', 'awaiting_payment') THEN 'paid' ELSE status END,
                    paid_at = NOW(),
                    updated_at = NOW()
                WHERE order_id = ? OR id = ?
            ")->execute([$order_id, $order_id]);

            // Registrar timeline
            try {
                $pdo->prepare("INSERT INTO om_order_timeline (order_id, action, details, created_at) VALUES (?, 'payment_confirmed', 'Pagamento PIX confirmado via cron', NOW())")
                    ->execute([$order_id]);
            } catch (Exception $e) {}

            // Notificar no chat
            try {
                $pdo->prepare("INSERT INTO om_market_chat (order_id, sender_type, sender_id, message, created_at) VALUES (?, 'system', 0, ?, NOW())")
                    ->execute([$order_id, 'Pagamento PIX confirmado! Seu pedido esta sendo preparado.']);
            } catch (Exception $e) {}
        }

        $stats['paid']++;
    }

    // Pequeno delay entre requests
    usleep(300000); // 0.3s
}

// ==============================================================================
// 3. EXPIRAR PIX ANTIGOS SEM CHARGE_ID
// ==============================================================================

cron_log("3. Verificando pedidos PIX sem confirmacao...");

$pix_expire_minutes = PIX_EXPIRE_AFTER_MINUTES;

try {
    // Pedidos PIX que nunca foram pagos e passaram do tempo
    $stmt = $pdo->query("
        SELECT order_id, order_number
        FROM om_market_orders
        WHERE payment_method = 'pix'
        AND payment_status IN ('pending', 'awaiting_payment', 'processing')
        AND created_at < NOW() - INTERVAL '{$pix_expire_minutes} minutes'
        AND created_at > NOW() - INTERVAL '24 hours'
        LIMIT 20
    ");

    $old_pix = $stmt->fetchAll();

    foreach ($old_pix as $order) {
        cron_log("   -> Pedido #{$order['order_id']} PIX expirado", 'warning');

        $pdo->prepare("UPDATE om_market_orders SET payment_status = 'failed', status = 'cancelled', updated_at = NOW() WHERE order_id = ?")
            ->execute([$order['order_id']]);

        $stats['expired']++;
    }

    if (empty($old_pix)) {
        cron_log("   Nenhum PIX antigo encontrado");
    }

} catch (Exception $e) {
    cron_log("   ERRO: " . $e->getMessage(), 'error');
}

// ==============================================================================
// RESULTADO
// ==============================================================================

$duration = round((microtime(true) - $start_time) * 1000);

cron_log("================================================================");
cron_log("ESTATISTICAS:");
cron_log("   Verificados: {$stats['checked']}");
cron_log("   Pagos: {$stats['paid']}");
cron_log("   Expirados: {$stats['expired']}");
cron_log("   Erros: {$stats['errors']}");
cron_log("   Duracao: {$duration}ms");
cron_log("================================================================");
cron_log("CRON PIX CHECK FINALIZADO");

if (!$is_cli) {
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'duration_ms' => $duration,
        'log' => $log
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
