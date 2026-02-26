<?php
/**
 * CRON: LGPD Compliance - Data Cleanup & Anonymization
 * Run daily at 4am: 0 4 * * * php /var/www/html/mercado/cron/cron_lgpd_cleanup.php
 *
 * 1. CASCADE ANONYMIZATION: deleted customers (30-day grace) → anonymize personal data
 * 2. DATA RETENTION: purge expired OTP codes, abandoned carts, old notifications
 * 3. CONSENT TRACKING: ensure om_customer_consents table exists
 * 4. DATA EXPORT: ensure om_data_export_requests table exists
 */

$isCli = php_sapi_name() === 'cli';

function cron_log($msg) {
    global $isCli;
    $line = "[" . date('Y-m-d H:i:s') . "] [lgpd-cleanup] {$msg}";
    if ($isCli) echo $line . "\n";
    error_log($line);
}

try {
    $db = new PDO(
        "pgsql:host=147.93.12.236;port=5432;dbname=love1",
        'love1', 'Aleff2009@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $stats = [
        'customers_anonymized' => 0,
        'otp_purged' => 0,
        'carts_purged' => 0,
        'notifications_purged' => 0,
        'errors' => 0,
    ];

    // ============================================================
    // 1. CASCADE ANONYMIZATION FOR DELETED CUSTOMERS (30-DAY GRACE)
    // ============================================================
    cron_log("--- Anonymizing deleted customers (30-day grace expired) ---");

    $stmtDeleted = $db->query("
        SELECT customer_id, name, email, phone
        FROM om_customers
        WHERE is_active = 0
          AND updated_at < NOW() - INTERVAL '30 days'
          AND (cpf IS NOT NULL OR foto IS NOT NULL OR name NOT LIKE 'Anonimo LGPD%')
        ORDER BY updated_at ASC
        LIMIT 200
    ");
    $deletedCustomers = $stmtDeleted->fetchAll();

    cron_log("Encontrados " . count($deletedCustomers) . " clientes deletados para anonimizar");

    foreach ($deletedCustomers as $customer) {
        $customerId = (int)$customer['customer_id'];

        try {
            $db->beginTransaction();

            // 1a. Anonymize orders
            $stmtOrders = $db->prepare("
                UPDATE om_market_orders
                SET customer_name = 'Cliente Removido',
                    customer_phone = NULL,
                    delivery_address = 'Endereco removido - LGPD',
                    notes = NULL
                WHERE customer_id = ?
            ");
            $stmtOrders->execute([$customerId]);
            $ordersAnon = $stmtOrders->rowCount();

            // 1b. Anonymize reviews (keep rating for partner stats)
            $stmtReviews = $db->prepare("
                UPDATE om_market_reviews
                SET comment = 'Avaliacao removida - conta excluida'
                WHERE customer_id = ?
            ");
            $stmtReviews->execute([$customerId]);
            $reviewsAnon = $stmtReviews->rowCount();

            // 1c. Anonymize wallet transactions
            $stmtWallet = $db->prepare("
                UPDATE om_wallet_transactions
                SET description = 'Transacao anonimizada - LGPD'
                WHERE customer_id = ?
            ");
            $stmtWallet->execute([$customerId]);
            $walletAnon = $stmtWallet->rowCount();

            // 1d. Addresses: delete inactive, anonymize active
            $stmtDelAddr = $db->prepare("
                DELETE FROM om_customer_addresses
                WHERE customer_id = ? AND is_active = 0
            ");
            $stmtDelAddr->execute([$customerId]);
            $addrDeleted = $stmtDelAddr->rowCount();

            $stmtAnonAddr = $db->prepare("
                UPDATE om_customer_addresses
                SET label = 'Removido',
                    street = 'LGPD',
                    number = '0',
                    complement = NULL,
                    neighborhood = 'Removido',
                    city = 'Removido',
                    zip_code = '00000000'
                WHERE customer_id = ? AND is_active = 1
            ");
            $stmtAnonAddr->execute([$customerId]);
            $addrAnon = $stmtAnonAddr->rowCount();

            // 1e. Delete old notifications for this customer
            $stmtDelNotif = $db->prepare("
                DELETE FROM om_notifications
                WHERE user_id = ?
                  AND user_type = 'customer'
                  AND created_at < NOW() - INTERVAL '90 days'
            ");
            $stmtDelNotif->execute([$customerId]);
            $notifDeleted = $stmtDelNotif->rowCount();

            // 1f. Clear remaining PII from customer record
            $anonName = "Anonimo LGPD #{$customerId}";
            $anonEmail = "removed_{$customerId}@lgpd.local";
            $stmtClearCustomer = $db->prepare("
                UPDATE om_customers
                SET name = ?,
                    email = ?,
                    phone = NULL,
                    cpf = NULL,
                    foto = NULL,
                    password_hash = NULL
                WHERE customer_id = ?
            ");
            $stmtClearCustomer->execute([$anonName, $anonEmail, $customerId]);

            $db->commit();
            $stats['customers_anonymized']++;
            cron_log("ANONYMIZED cliente #{$customerId}: {$ordersAnon} pedidos, {$reviewsAnon} avaliacoes, {$walletAnon} transacoes, {$addrDeleted} enderecos removidos, {$addrAnon} enderecos anonimizados, {$notifDeleted} notificacoes removidas");

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $stats['errors']++;
            cron_log("ERRO anonimizando cliente #{$customerId}: " . $e->getMessage());
        }
    }

    // ============================================================
    // 2. DATA RETENTION - PURGE OLD DATA
    // ============================================================

    // 2a. Old OTP codes (>7 days)
    cron_log("--- Purging expired OTP codes ---");
    try {
        $stmtOtp = $db->query("
            DELETE FROM om_market_otp_codes
            WHERE created_at < NOW() - INTERVAL '7 days'
        ");
        $stats['otp_purged'] = $stmtOtp->rowCount();
        cron_log("OTP codes removidos: {$stats['otp_purged']}");
    } catch (Exception $e) {
        $stats['errors']++;
        cron_log("ERRO purging OTP: " . $e->getMessage());
    }

    // 2b. Abandoned carts (>30 days)
    cron_log("--- Purging abandoned carts ---");
    try {
        $stmtCarts = $db->query("
            DELETE FROM om_market_cart
            WHERE created_at < NOW() - INTERVAL '30 days'
        ");
        $stats['carts_purged'] = $stmtCarts->rowCount();
        cron_log("Carrinhos abandonados removidos: {$stats['carts_purged']}");
    } catch (Exception $e) {
        $stats['errors']++;
        cron_log("ERRO purging carts: " . $e->getMessage());
    }

    // 2c. Old read notifications (>90 days) + very old notifications (>1 year)
    cron_log("--- Purging old notifications ---");
    try {
        $notifTotal = 0;

        // Read notifications older than 90 days
        $stmtReadNotif = $db->query("
            DELETE FROM om_notifications
            WHERE is_read = 1
              AND created_at < NOW() - INTERVAL '90 days'
        ");
        $readPurged = $stmtReadNotif->rowCount();
        $notifTotal += $readPurged;
        cron_log("Notificacoes lidas removidas (>90d): {$readPurged}");

        // Any notification older than 1 year
        $stmtOldNotif = $db->query("
            DELETE FROM om_notifications
            WHERE created_at < NOW() - INTERVAL '1 year'
        ");
        $oldPurged = $stmtOldNotif->rowCount();
        $notifTotal += $oldPurged;
        cron_log("Notificacoes antigas removidas (>1 ano): {$oldPurged}");

        $stats['notifications_purged'] = $notifTotal;
    } catch (Exception $e) {
        $stats['errors']++;
        cron_log("ERRO purging notifications: " . $e->getMessage());
    }

    // ============================================================
    // 3. CONSENT TRACKING TABLE
    // ============================================================
    cron_log("--- Ensuring consent tracking table exists ---");
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS om_customer_consents (
                consent_id SERIAL PRIMARY KEY,
                customer_id INT NOT NULL,
                consent_type VARCHAR(50) NOT NULL,
                consented BOOLEAN NOT NULL DEFAULT FALSE,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
        cron_log("Tabela om_customer_consents verificada/criada");
    } catch (Exception $e) {
        $stats['errors']++;
        cron_log("ERRO criando tabela consents: " . $e->getMessage());
    }

    // ============================================================
    // 4. DATA EXPORT REQUESTS TABLE
    // ============================================================
    cron_log("--- Ensuring data export requests table exists ---");
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS om_data_export_requests (
                request_id SERIAL PRIMARY KEY,
                customer_id INT NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                file_path TEXT,
                requested_at TIMESTAMP DEFAULT NOW(),
                completed_at TIMESTAMP,
                expires_at TIMESTAMP
            )
        ");
        cron_log("Tabela om_data_export_requests verificada/criada");
    } catch (Exception $e) {
        $stats['errors']++;
        cron_log("ERRO criando tabela export requests: " . $e->getMessage());
    }

    // ============================================================
    // ADMIN NOTIFICATION (if any customers were anonymized)
    // ============================================================
    if ($stats['customers_anonymized'] > 0) {
        try {
            $db->prepare("
                INSERT INTO om_notifications (user_id, user_type, title, body, type, data, created_at)
                VALUES (1, 'admin', 'LGPD: Limpeza diaria concluida', ?, 'lgpd_cleanup', ?::jsonb, NOW())
            ")->execute([
                "Anonimizados: {$stats['customers_anonymized']} clientes. OTP removidos: {$stats['otp_purged']}. Carrinhos removidos: {$stats['carts_purged']}. Notificacoes removidas: {$stats['notifications_purged']}. Erros: {$stats['errors']}.",
                json_encode(['reference_type' => 'lgpd_cleanup', 'reference_id' => date('Y-m-d')])
            ]);
            cron_log("Admin notificado sobre limpeza LGPD");
        } catch (Exception $e) {
            $stats['errors']++;
            cron_log("ERRO notificando admin: " . $e->getMessage());
        }
    }

    // ============================================================
    // SUMMARY
    // ============================================================
    cron_log("=== RESUMO ===");
    cron_log("Clientes anonimizados: {$stats['customers_anonymized']}");
    cron_log("OTP codes removidos: {$stats['otp_purged']}");
    cron_log("Carrinhos abandonados removidos: {$stats['carts_purged']}");
    cron_log("Notificacoes removidas: {$stats['notifications_purged']}");
    cron_log("Erros: {$stats['errors']}");

    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'stats' => $stats, 'timestamp' => date('c')]);
    }

} catch (Exception $e) {
    cron_log("ERRO FATAL: " . $e->getMessage());
    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit(1);
}
