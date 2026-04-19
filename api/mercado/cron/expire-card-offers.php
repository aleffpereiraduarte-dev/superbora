<?php
/**
 * CRON: Expire pending credit-card pre-approvals past their deadline.
 *
 *   0 5 * * * php /var/www/html/api/mercado/cron/expire-card-offers.php
 *
 * Sets status 'pre_approved' -> 'offer_expired' for every om_credit_cards row
 * where offer_expires_at < NOW(). Notifies the customer and logs the event.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/notify.php';
require_once dirname(__DIR__) . '/customer/card/_common.php';

$lockFile = '/tmp/superbora_cron_expire_card_offers.lock';
if (file_exists($lockFile) && filemtime($lockFile) < time() - 1800) {
    unlink($lockFile);
}
if (file_exists($lockFile)) {
    echo "[expire-card-offers] already running\n";
    exit(0);
}
touch($lockFile);

try {
    $db = getDB();
    ensureCardTables($db);

    $stmt = $db->query("
        SELECT cc.id, cc.customer_id, cc.credit_limit, cc.offer_expires_at,
               c.name, c.phone, c.email
        FROM om_credit_cards cc
        LEFT JOIN om_customers c ON c.customer_id = cc.customer_id
        WHERE cc.status = 'pre_approved'
          AND cc.offer_expires_at IS NOT NULL
          AND cc.offer_expires_at < NOW()
    ");
    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    foreach ($expired as $row) {
        $cardId = (int)$row['id'];
        $customerId = (int)$row['customer_id'];

        try {
            $db->prepare("UPDATE om_credit_cards SET status = 'offer_expired' WHERE id = ?")
               ->execute([$cardId]);

            logCardEvent($db, $cardId, $customerId, 'offer_expired', 'system', null, [
                'limit'       => (float)$row['credit_limit'],
                'expired_at'  => $row['offer_expires_at'],
            ]);

            try {
                notifyCustomer($db, $customerId,
                    'Sua oferta de cartao expirou',
                    'Nao foi possivel aceitar sua pre-aprovacao a tempo. Solicite uma nova avaliacao quando quiser.',
                    '/cartao',
                    ['type' => 'card_offer_expired', 'card_id' => $cardId]
                );
            } catch (Exception $e) {
                error_log('[expire-card-offers notify] ' . $e->getMessage());
            }

            try {
                $db->prepare("
                    INSERT INTO om_market_notifications (customer_id, title, message, type, data)
                    VALUES (?, ?, ?, 'card_offer_expired', ?)
                ")->execute([
                    $customerId,
                    'Sua oferta de cartao expirou',
                    'Nao foi possivel aceitar sua pre-aprovacao a tempo.',
                    json_encode(['card_id' => $cardId], JSON_UNESCAPED_UNICODE),
                ]);
            } catch (Exception $e) { /* table may not exist */ }

            $count++;
        } catch (Exception $e) {
            error_log('[expire-card-offers row] card_id=' . $cardId . ' ' . $e->getMessage());
        }
    }

    echo "[expire-card-offers] expired {$count} offers\n";
} catch (Exception $e) {
    echo "[expire-card-offers] FATAL " . $e->getMessage() . "\n";
    error_log('[expire-card-offers] FATAL ' . $e->getMessage());
} finally {
    @unlink($lockFile);
}
