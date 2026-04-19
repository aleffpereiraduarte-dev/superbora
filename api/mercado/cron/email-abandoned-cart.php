<?php
/**
 * CRON: Abandoned Cart Email Reminder
 *
 * Finds customers with cart items older than 4 hours who haven't ordered since,
 * and sends them a reminder email with their cart contents.
 *
 * Run every 30 minutes:
 *   crontab: STAR/30 * * * * php /var/www/html/api/mercado/cron/email-abandoned-cart.php
 *   (where STAR = asterisk)
 *
 * Constraints:
 * - Cart items must be > 4 hours old
 * - Customer must NOT have placed an order since cart was last updated
 * - Max 1 abandoned cart email per customer per 48 hours
 * - Max 50 emails per cron run
 * - Excludes unsubscribed customers
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/email.php';
require_once dirname(__DIR__) . '/helpers/email-templates.php';
require_once dirname(__DIR__) . '/helpers/claude-client.php';

// ─── Concurrent execution guard ─────────────────────────────
$lockFile = '/tmp/superbora_cron_email_abandoned_cart.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Another instance is running. Exiting.\n";
    exit(0);
}

$db = getDB();
$log = function(string $msg) { echo "[" . date('H:i:s') . "] $msg\n"; };

$log("=== Abandoned cart email cron started ===");

// ─── Ensure tables exist ────────────────────────────────────
$db->exec("
    CREATE TABLE IF NOT EXISTS om_email_campaigns (
        id SERIAL PRIMARY KEY,
        subject VARCHAR(255) NOT NULL,
        body_html TEXT NOT NULL,
        segment_type VARCHAR(50) NOT NULL DEFAULT 'all',
        segment_config JSONB DEFAULT '{}',
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        scheduled_at TIMESTAMP NULL,
        sent_at TIMESTAMP NULL,
        total_sent INT NOT NULL DEFAULT 0,
        total_opened INT NOT NULL DEFAULT 0,
        total_clicked INT NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW()
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS om_email_campaign_sends (
        id SERIAL PRIMARY KEY,
        campaign_id INT NOT NULL,
        customer_id INT NULL,
        email VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        sent_at TIMESTAMP NULL,
        opened_at TIMESTAMP NULL,
        clicked_at TIMESTAMP NULL,
        error_message TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
    )
");

$db->exec("CREATE INDEX IF NOT EXISTS idx_email_campaign_sends_campaign ON om_email_campaign_sends(campaign_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_email_campaign_sends_email ON om_email_campaign_sends(email)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_email_campaign_sends_customer ON om_email_campaign_sends(customer_id)");

$db->exec("
    CREATE TABLE IF NOT EXISTS om_email_unsubscribes (
        id SERIAL PRIMARY KEY,
        customer_id INT NULL,
        email VARCHAR(255) NOT NULL,
        reason VARCHAR(255) NULL,
        unsubscribed_at TIMESTAMP NOT NULL DEFAULT NOW(),
        UNIQUE(email)
    )
");

// ═══════════════════════════════════════════════════════════════
// Find customers with abandoned carts
// ═══════════════════════════════════════════════════════════════
//
// Criteria:
// 1. Has items in om_market_cart with created_at/updated_at > 4 hours ago
// 2. Is an authenticated customer (customer_id set)
// 3. Has a valid email
// 4. Has NOT placed any order since the cart was last modified
// 5. Has NOT received an abandoned cart email in the last 48 hours
// 6. Is not unsubscribed
//
try {
    // Use CTE to first compute per-customer cart stats, then filter
    $stmt = $db->prepare("
        WITH cart_stats AS (
            SELECT
                c.customer_id,
                MIN(COALESCE(c.updated_at, c.created_at)) as oldest_item,
                MAX(COALESCE(c.updated_at, c.created_at)) as newest_item,
                COUNT(c.cart_id) as item_count
            FROM om_market_cart c
            WHERE c.customer_id IS NOT NULL
              AND c.customer_id > 0
              -- At least one cart item older than 4 hours
              AND COALESCE(c.updated_at, c.created_at) < NOW() - INTERVAL '4 hours'
            GROUP BY c.customer_id
            HAVING COUNT(c.cart_id) > 0
        )
        SELECT
            cs.customer_id,
            cust.name,
            cust.email,
            cs.oldest_item as cart_last_modified,
            cs.item_count
        FROM cart_stats cs
        INNER JOIN om_customers cust ON cust.customer_id = cs.customer_id
        WHERE cust.email IS NOT NULL
          AND cust.email != ''
          AND cust.is_active = 1
          -- Exclude unsubscribed
          AND cust.email NOT IN (SELECT email FROM om_email_unsubscribes)
          -- No abandoned cart email sent in last 48 hours (via campaign_sends)
          AND cs.customer_id NOT IN (
              SELECT DISTINCT ecs.customer_id
              FROM om_email_campaign_sends ecs
              WHERE ecs.customer_id IS NOT NULL
                AND ecs.status = 'sent'
                AND ecs.created_at > NOW() - INTERVAL '48 hours'
                AND ecs.campaign_id = 0
          )
          -- No abandoned cart email sent in last 48 hours (via email_logs)
          AND cs.customer_id NOT IN (
              SELECT DISTINCT el.customer_id
              FROM om_email_logs el
              WHERE el.customer_id IS NOT NULL
                AND el.template = 'abandoned_cart'
                AND el.status = 'sent'
                AND el.sent_at > NOW() - INTERVAL '48 hours'
          )
          -- No order placed since the newest cart item was added
          AND NOT EXISTS (
              SELECT 1 FROM om_market_orders o
              WHERE o.customer_id = cs.customer_id
                AND o.created_at > cs.newest_item
          )
        ORDER BY cs.oldest_item ASC
        LIMIT 50
    ");
    $stmt->execute();
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $log("Found " . count($candidates) . " abandoned cart(s) to email");

    if (empty($candidates)) {
        $log("No abandoned carts to process. Done.");
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        exit(0);
    }

    $sent = 0;
    $failed = 0;

    foreach ($candidates as $candidate) {
        $customerId = (int)$candidate['customer_id'];
        $email = $candidate['email'];
        $name = $candidate['name'] ?: 'Cliente';

        // Fetch cart items for this customer
        $stmtItems = $db->prepare("
            SELECT c.quantity, p.price, p.name,
                   pr.name as partner_name
            FROM om_market_cart c
            INNER JOIN om_market_products p ON c.product_id = p.product_id
            LEFT JOIN om_market_partners pr ON c.partner_id = pr.partner_id
            WHERE c.customer_id = ?
            ORDER BY c.cart_id ASC
        ");
        $stmtItems->execute([$customerId]);
        $cartItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cartItems)) {
            $log("  Customer #{$customerId}: cart is now empty, skipping");
            continue;
        }

        // Build template data
        $items = [];
        $storeNames = [];
        foreach ($cartItems as $ci) {
            $items[] = [
                'name' => $ci['name'],
                'quantity' => (int)$ci['quantity'],
                'price' => (float)$ci['price'],
            ];
            if (!empty($ci['partner_name']) && !in_array($ci['partner_name'], $storeNames, true)) {
                $storeNames[] = $ci['partner_name'];
            }
        }

        if (empty($storeNames)) {
            $storeNames = ['SuperBora'];
        }

        // ── AI-personalized subject + intro (replaces static template) ──
        $aiSubject = '';
        $aiBody = '';
        try {
            $itemList = implode(', ', array_map(fn($i) => $i['quantity'] . 'x ' . $i['name'], array_slice($items, 0, 5)));
            $hour = (int)date('G');
            $period = $hour < 12 ? 'manha' : ($hour < 18 ? 'tarde' : 'noite');

            $sysPrompt = "Voce eh growth hacker brasileiro escrevendo email de carrinho abandonado. " .
                         "Tom: caloroso, levemente persuasivo, NUNCA invente precos especificos.";
            $userPrompt = "Cliente: {$name} (primeiro nome)\n" .
                          "Periodo: {$period}\n" .
                          "Carrinho: {$itemList}\n" .
                          "Lojas: " . implode(', ', $storeNames) . "\n\n" .
                          "Responda APENAS JSON: {\"subject\":\"max 60 chars\",\"intro\":\"max 200 chars, frase introdutoria caloroso\"}";

            $aiResp = ClaudeClient::sendFastJson($userPrompt, $sysPrompt, 400);
            if (is_array($aiResp)) {
                $aiSubject = trim($aiResp['subject'] ?? '');
                $aiBody = trim($aiResp['intro'] ?? '');
            }
        } catch (Exception $e) {
            error_log("[email-abandoned-cart] AI personalization failed: " . $e->getMessage());
        }

        // Render email — fall back to static template if AI failed
        $html = emailTemplate_abandoned_cart($name, $items, $storeNames);
        if ($aiBody !== '') {
            // Inject AI intro into the rendered template (before the items list)
            $html = str_replace('<!-- AI_INTRO_PLACEHOLDER -->', '<p style="color:#444;font-size:15px;line-height:1.5;margin:16px 0;">' . htmlspecialchars($aiBody, ENT_QUOTES, 'UTF-8') . '</p>', $html);
        }
        $subject = $aiSubject !== ''
            ? $aiSubject
            : "{$name}, seus produtos estao esperando no carrinho!";

        // Send email
        $success = sendEmail($email, $subject, $html, $db, $customerId, 'abandoned_cart');

        // Log to campaign sends (campaign_id = 0 for automated emails)
        try {
            $db->prepare("
                INSERT INTO om_email_campaign_sends (campaign_id, customer_id, email, status, sent_at, created_at)
                VALUES (0, ?, ?, ?, ?, NOW())
            ")->execute([
                $customerId,
                $email,
                $success ? 'sent' : 'failed',
                $success ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (Exception $e) {
            error_log("[email-abandoned-cart] Failed to log send: " . $e->getMessage());
        }

        if ($success) {
            $sent++;
            $log("  Sent to {$name} <" . substr($email, 0, 3) . "***> ({$candidate['item_count']} items)");
        } else {
            $failed++;
            $log("  FAILED for customer #{$customerId}");
        }

        // Rate limit: 100ms between sends
        usleep(100000);
    }

    $log("=== Done: {$sent} sent, {$failed} failed ===");

} catch (PDOException $e) {
    error_log("[email-abandoned-cart] DB error: " . $e->getMessage());
    $log("ERROR: " . $e->getMessage());
} catch (Exception $e) {
    error_log("[email-abandoned-cart] Error: " . $e->getMessage());
    $log("ERROR: " . $e->getMessage());
}

// Release lock
flock($lockFp, LOCK_UN);
fclose($lockFp);
