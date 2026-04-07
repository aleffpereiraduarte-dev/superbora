<?php
/**
 * Cron: Encrypt sensitive PII at rest.
 *
 * Runs in batches of 200. Processes any rows where the encrypted column is NULL
 * but the plaintext column is not. Once a row is encrypted, the plaintext column
 * is overwritten with NULL (or kept for backward compat — see KEEP_PLAINTEXT below).
 *
 * Schedule: every 15 min via crontab -- run: php /var/www/html/api/mercado/cron/encrypt-pii.php
 *
 * Run manually: php cron/encrypt-pii.php [batch_size]
 */
if (PHP_SAPI !== 'cli' && empty($_SERVER['REQUEST_URI'])) {
    // Allow CLI execution
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/encryption.php';

// SAFETY: keep plaintext for the first 30 days (rollback window).
// Set to false after you're sure all rows are encrypted and reads work.
const KEEP_PLAINTEXT = true;

if (getEncryptionKey() === null) {
    fwrite(STDERR, "[encrypt-pii] ENCRYPTION_KEY not configured; aborting.\n");
    exit(1);
}

$batchSize = (int)($argv[1] ?? 200);
$db = getDB();
$total = 0;

function logAudit(PDO $db, string $table, string $col, int $rowId, string $action, ?string $err = null): void {
    try {
        $stmt = $db->prepare(
            "INSERT INTO om_encryption_audit (table_name, column_name, row_id, action, actor, error_message)
             VALUES (:t, :c, :r, :a, 'cron', :e)"
        );
        $stmt->execute([':t' => $table, ':c' => $col, ':r' => $rowId, ':a' => $action, ':e' => $err]);
    } catch (Exception $e) {
        // ignore audit failures
    }
}

// ========== Customers: cpf, birth_date (rg column doesn't exist) ==========
// birth_date is DATE type so we can't use <> '' on it
$customerCols = [
    'cpf'        => ['enc' => 'cpf_enc', 'hash' => 'cpf_hash', 'is_text' => true],
    'birth_date' => ['enc' => 'birth_date_enc', 'hash' => null, 'is_text' => false],
];

foreach ($customerCols as $col => $cfg) {
    $encCol = $cfg['enc'];
    $hashCol = $cfg['hash'];
    $emptyCheck = $cfg['is_text'] ? "AND $col <> ''" : '';
    try {
        $stmt = $db->prepare(
            "SELECT customer_id, $col FROM om_customers
             WHERE $col IS NOT NULL $emptyCheck AND $encCol IS NULL
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $plain = (string)$r[$col];
            $cipher = encryptData($plain);
            if (strpos($cipher, 'v1:') !== 0) {
                logAudit($db, 'om_customers', $col, (int)$r['customer_id'], 'fail', 'encryptData returned plaintext');
                continue;
            }

            try {
                if ($hashCol && $col === 'cpf') {
                    $hash = hashCpf($plain);
                    $upd = $db->prepare("UPDATE om_customers SET $encCol = :e, $hashCol = :h WHERE customer_id = :id");
                    $upd->execute([':e' => $cipher, ':h' => $hash, ':id' => $r['customer_id']]);
                } else {
                    $upd = $db->prepare("UPDATE om_customers SET $encCol = :e WHERE customer_id = :id");
                    $upd->execute([':e' => $cipher, ':id' => $r['customer_id']]);
                }
                if (!KEEP_PLAINTEXT) {
                    $clr = $db->prepare("UPDATE om_customers SET $col = NULL WHERE customer_id = :id");
                    $clr->execute([':id' => $r['customer_id']]);
                }
                logAudit($db, 'om_customers', $col, (int)$r['customer_id'], 'encrypt');
                $total++;
            } catch (Exception $e) {
                logAudit($db, 'om_customers', $col, (int)$r['customer_id'], 'fail', $e->getMessage());
            }
        }
    } catch (Exception $e) {
        fwrite(STDERR, "[encrypt-pii] error on $col: " . $e->getMessage() . "\n");
    }
}

// ========== Addresses ==========
$addressCols = [
    'street'     => 'street_enc',
    'number'     => 'number_enc',
    'complement' => 'complement_enc',
];
foreach ($addressCols as $col => $encCol) {
    try {
        $stmt = $db->prepare(
            "SELECT id, $col FROM om_customer_addresses
             WHERE $col IS NOT NULL AND $col <> '' AND $encCol IS NULL
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $cipher = encryptData((string)$r[$col]);
            if (strpos($cipher, 'v1:') !== 0) continue;
            try {
                $upd = $db->prepare("UPDATE om_customer_addresses SET $encCol = :e WHERE id = :id");
                $upd->execute([':e' => $cipher, ':id' => $r['id']]);
                if (!KEEP_PLAINTEXT) {
                    $clr = $db->prepare("UPDATE om_customer_addresses SET $col = NULL WHERE id = :id");
                    $clr->execute([':id' => $r['id']]);
                }
                logAudit($db, 'om_customer_addresses', $col, (int)$r['id'], 'encrypt');
                $total++;
            } catch (Exception $e) {
                logAudit($db, 'om_customer_addresses', $col, (int)$r['id'], 'fail', $e->getMessage());
            }
        }
    } catch (Exception $e) {
        // table might not have these columns yet — that's OK
    }
}

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "[encrypt-pii] Encrypted $total values.\n");
}
