<?php
/**
 * CRON: Renovacao SuperBora+ (rodar diariamente)
 *
 * 1. Encontra assinaturas expiradas
 * 2. Tenta renovar (cobrar R$4,90)
 * 3. Se falhar, marca como expired
 *
 * Crontab: 0 6 * * * php /var/www/html/api/mercado/cron/superbora-plus-renew.php
 */

// ── ACCESS CONTROL: CLI or authenticated cron header only ────
$secret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($secret)) { http_response_code(503); echo json_encode(['error' => 'Cron secret not configured']); exit; }
if (php_sapi_name() !== 'cli' && (!isset($_SERVER['HTTP_X_CRON_KEY']) || !hash_equals($secret, $_SERVER['HTTP_X_CRON_KEY']))) {
    http_response_code(403);
    die('Acesso negado');
}

$envPath = "/var/www/html/.env";
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (strpos($line, "#") === 0) continue;
    if (strpos($line, "=") !== false) {
        $p = explode("=", $line, 2);
        $_ENV[trim($p[0])] = trim($p[1]);
    }
}
$dsn = "pgsql:host=" . $_ENV["DB_HOSTNAME"] . ";port=" . $_ENV["DB_PORT"] . ";dbname=" . $_ENV["DB_NAME"];
$db = new PDO($dsn, $_ENV["DB_USERNAME"], $_ENV["DB_PASSWORD"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

require_once dirname(__DIR__, 3) . '/includes/classes/OmPricing.php';

echo "[" . date('Y-m-d H:i:s') . "] SuperBora+ Renewal CRON\n";

// 1. Buscar assinaturas expiradas que ainda estao ativas
$stmt = $db->prepare("
    SELECT sp.*, c.firstname, c.email
    FROM om_superbora_plus sp
    LEFT JOIN om_customer c ON c.customer_id = sp.customer_id
    WHERE sp.status = 'active'
    AND sp.expires_at <= NOW()
");
$stmt->execute();
$expiradas = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Encontradas " . count($expiradas) . " assinaturas para renovar\n";

$renovadas = 0;
$expirou = 0;

foreach ($expiradas as $sub) {
    $customerId = (int)$sub['customer_id'];

    // Por enquanto, renovar automaticamente (cobrar via saldo cashback ou marcar para cobranca)
    // TODO: Integrar com gateway de pagamento real para cobranca recorrente
    $novaExpiracao = date('Y-m-d H:i:s', strtotime('+1 month'));

    // Checar se cliente tem cashback disponivel para pagar
    $stmtCb = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM om_cashback
        WHERE customer_id = ? AND type IN ('earned','bonus') AND status = 'available'
        AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmtCb->execute([$customerId]);
    $cbSaldo = (float)$stmtCb->fetchColumn();

    if ($cbSaldo >= OmPricing::SUPERBORA_PLUS_PRECO) {
        // Cobrar do cashback
        $remaining = OmPricing::SUPERBORA_PLUS_PRECO;
        $stmtCbList = $db->prepare("SELECT id, amount FROM om_cashback WHERE customer_id = ? AND type IN ('earned','bonus') AND status = 'available' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY expires_at ASC NULLS LAST");
        $stmtCbList->execute([$customerId]);
        foreach ($stmtCbList->fetchAll() as $cb) {
            if ($remaining <= 0) break;
            $use = min($remaining, (float)$cb['amount']);
            if ($use >= (float)$cb['amount']) {
                $db->prepare("UPDATE om_cashback SET status = 'used' WHERE id = ?")->execute([$cb['id']]);
            } else {
                $db->prepare("UPDATE om_cashback SET amount = amount - ? WHERE id = ?")->execute([$use, $cb['id']]);
            }
            $remaining -= $use;
        }

        // Renovar
        $db->prepare("UPDATE om_superbora_plus SET expires_at = ? WHERE customer_id = ?")
           ->execute([$novaExpiracao, $customerId]);

        echo "  [RENOVADO] Cliente #$customerId via cashback | expira $novaExpiracao\n";
        $renovadas++;
    } else {
        // Sem saldo: marcar como expirado (cliente precisa renovar manualmente)
        $db->prepare("UPDATE om_superbora_plus SET status = 'expired' WHERE customer_id = ?")
           ->execute([$customerId]);

        echo "  [EXPIRADO] Cliente #$customerId | sem saldo cashback\n";
        $expirou++;

        // Notificar cliente
        try {
            $db->prepare("
                INSERT INTO om_notifications (customer_id, title, message, url, created_at)
                VALUES (?, 'SuperBora+ expirou', 'Sua assinatura SuperBora+ expirou. Renove por R$4,90/mes e continue aproveitando os beneficios!', '/mercado/superbora-plus', NOW())
            ")->execute([$customerId]);
        } catch (Exception $e) {
            // ignore
        }
    }
}

echo "\nResumo: $renovadas renovadas, $expirou expiradas\n";
echo "[" . date('Y-m-d H:i:s') . "] Done\n";
