<?php
/**
 * CRON: WhatsApp Proactive Messaging
 * Sends personalized WhatsApp messages to customers based on behavior patterns.
 *
 * Runs every 2 hours:
 *   crontab: 0 STAR/2 * * * php /var/www/html/api/mercado/cron/whatsapp-proactive.php
 *   (onde STAR = asterisco)
 *
 * Message types:
 * 1. Lunchtime suggestion (11:00-11:30) — nudge returning customers
 * 2. Dinner suggestion (17:30-18:00) — nudge returning customers
 * 3. Promo alerts (8:00-21:00) — products on sale at favorite stores
 * 4. Win-back (once per week) — customers inactive 15-30 days
 *
 * Safety limits:
 * - Max 50 messages per type per run
 * - Max 1 message per customer per type per day
 * - Max 1 promo per customer per day
 * - Max 1 win-back per customer per week
 * - Business hours only (8:00-22:00)
 * - Respects opt-out
 * - Every message includes unsubscribe instruction
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/zapi-whatsapp.php';

// ─── Concurrent execution guard ─────────────────────────────
$lockFile = '/tmp/superbora_cron_whatsapp_proactive.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('H:i:s') . "] Another proactive cron instance is running. Exiting.\n";
    exit(0);
}

$db = getDB();
$log = function (string $msg) { echo "[" . date('H:i:s') . "] $msg\n"; };

$log("=== WhatsApp proactive messaging started ===");

// ─── Business hours check (8:00-22:00 Sao Paulo) ────────────
$hour = (int) date('H');
$minute = (int) date('i');
$timeDecimal = $hour + ($minute / 60);

if ($hour < 8 || $hour >= 22) {
    $log("Outside business hours ($hour:$minute). Exiting.");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(0);
}

// ─── Ensure tables exist ─────────────────────────────────────
try {
    $db->query("SELECT 1 FROM om_whatsapp_proactive_log LIMIT 1");
} catch (Exception $e) {
    $log("Proactive log table does not exist. Run sql/045_whatsapp_proactive.sql first.");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(1);
}

// ─── Constants ───────────────────────────────────────────────
define('PROACTIVE_MAX_PER_RUN', 50);
define('PROACTIVE_SEND_DELAY_MS', 1500); // 1.5s between messages to avoid Z-API throttle
define('PROACTIVE_UNSUBSCRIBE_TEXT', "\n\n_Pra parar de receber essas msgs, responda *parar*_");

// ─── Helper: Check if customer opted out ─────────────────────
function isOptedOut(PDO $db, int $customerId): bool
{
    try {
        $stmt = $db->prepare("SELECT 1 FROM om_whatsapp_proactive_optout WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        return (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        // If table doesn't exist, treat as not opted out
        return false;
    }
}

// ─── Helper: Check if already sent today for this type ───────
function alreadySentToday(PDO $db, int $customerId, string $type): bool
{
    $stmt = $db->prepare("
        SELECT 1 FROM om_whatsapp_proactive_log
        WHERE customer_id = ? AND message_type = ? AND sent_at::date = CURRENT_DATE
        LIMIT 1
    ");
    $stmt->execute([$customerId, $type]);
    return (bool) $stmt->fetchColumn();
}

// ─── Helper: Check if sent this week (for winback) ───────────
function alreadySentThisWeek(PDO $db, int $customerId, string $type): bool
{
    $stmt = $db->prepare("
        SELECT 1 FROM om_whatsapp_proactive_log
        WHERE customer_id = ?
          AND message_type = ?
          AND sent_at >= NOW() - INTERVAL '7 days'
        LIMIT 1
    ");
    $stmt->execute([$customerId, $type]);
    return (bool) $stmt->fetchColumn();
}

// ─── Helper: Log a sent message ──────────────────────────────
function logProactiveMessage(PDO $db, int $customerId, string $phone, string $type, string $message): void
{
    $db->prepare("
        INSERT INTO om_whatsapp_proactive_log (customer_id, phone, message_type, message, sent_at)
        VALUES (?, ?, ?, ?, NOW())
        ON CONFLICT (customer_id, message_type, (sent_at::date)) DO NOTHING
    ")->execute([$customerId, $phone, $type, $message]);
}

// ─── Helper: Send with throttle and logging ──────────────────
function sendProactive(PDO $db, int $customerId, string $phone, string $type, string $message, callable $log): bool
{
    if (isOptedOut($db, $customerId)) {
        return false;
    }

    // Type-specific duplicate checks
    if ($type === 'winback') {
        if (alreadySentThisWeek($db, $customerId, 'winback')) {
            return false;
        }
    } else {
        if (alreadySentToday($db, $customerId, $type)) {
            return false;
        }
    }

    // For promo, also check if any promo was sent today
    if ($type === 'promo') {
        if (alreadySentToday($db, $customerId, 'promo')) {
            return false;
        }
    }

    // Append unsubscribe text
    $fullMessage = $message . PROACTIVE_UNSUBSCRIBE_TEXT;

    $result = sendWhatsApp($phone, $fullMessage);

    if ($result['success']) {
        logProactiveMessage($db, $customerId, $phone, $type, $message);
        $log("  Sent [{$type}] to customer #{$customerId} (phone ending " . substr($phone, -4) . ")");
        // Throttle to avoid Z-API rate limiting
        usleep(PROACTIVE_SEND_DELAY_MS * 1000);
        return true;
    } else {
        $log("  FAILED [{$type}] customer #{$customerId}: " . ($result['message'] ?? 'unknown'));
        return false;
    }
}

// ─── Helper: Get customer's favorite store ───────────────────
function getCustomerFavoriteStore(PDO $db, int $customerId): ?array
{
    // Find the store they ordered from most in the last 90 days
    $stmt = $db->prepare("
        SELECT
            o.partner_id,
            COALESCE(p.trade_name, p.name) as store_name,
            COUNT(*) as order_count,
            p.is_open
        FROM om_market_orders o
        JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.customer_id = ?
          AND o.status NOT IN ('cancelado', 'recusado', 'refunded')
          AND o.date_added >= NOW() - INTERVAL '90 days'
        GROUP BY o.partner_id, p.trade_name, p.name, p.is_open
        ORDER BY COUNT(*) DESC
        LIMIT 1
    ");
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ─── Helper: Get first name from full name ───────────────────
function firstName(string $name): string
{
    $parts = explode(' ', trim($name));
    $first = $parts[0] ?? '';
    return mb_convert_case($first, MB_CASE_TITLE, 'UTF-8');
}

$totalSent = 0;

// ═══════════════════════════════════════════════════════════════
// 1. LUNCHTIME SUGGESTION (11:00-11:30)
// ═══════════════════════════════════════════════════════════════
if ($timeDecimal >= 11.0 && $timeDecimal < 11.5) {
    $log("--- Lunchtime suggestions ---");
    $sentCount = 0;

    try {
        // Find customers who ordered in the last 30 days but not today
        $stmt = $db->prepare("
            SELECT DISTINCT
                c.customer_id,
                c.phone,
                c.name
            FROM om_market_customers c
            JOIN om_market_orders o ON o.customer_id = c.customer_id
            WHERE c.phone IS NOT NULL
              AND c.phone != ''
              AND o.status NOT IN ('cancelado', 'recusado', 'refunded')
              AND o.date_added >= NOW() - INTERVAL '30 days'
              AND c.customer_id NOT IN (
                  SELECT customer_id FROM om_market_orders
                  WHERE date_added::date = CURRENT_DATE
                    AND status NOT IN ('cancelado', 'recusado')
              )
              AND c.customer_id NOT IN (
                  SELECT customer_id FROM om_whatsapp_proactive_optout
              )
              AND c.customer_id NOT IN (
                  SELECT customer_id FROM om_whatsapp_proactive_log
                  WHERE message_type = 'lunch' AND sent_at::date = CURRENT_DATE
              )
            ORDER BY o.date_added DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', PROACTIVE_MAX_PER_RUN, PDO::PARAM_INT);
        $stmt->execute();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $log("  Found " . count($candidates) . " lunch candidates");

        foreach ($candidates as $customer) {
            if ($sentCount >= PROACTIVE_MAX_PER_RUN) break;

            $name = firstName($customer['name'] ?? 'Amigo');
            $favStore = getCustomerFavoriteStore($db, (int) $customer['customer_id']);

            if ($favStore && $favStore['store_name']) {
                $storeName = $favStore['store_name'];
                $messages = [
                    "Opa {$name}! Ta chegando hora do almoco! Que tal pedir da {$storeName} hoje? \xF0\x9F\x98\x8B",
                    "E ai {$name}! Bateu a fome? A {$storeName} ta aberta e esperando seu pedido! \xF0\x9F\x8D\xBD",
                    "Fala {$name}! Hora do rango! Bora pedir da {$storeName}? \xF0\x9F\x98\x84",
                ];
            } else {
                $messages = [
                    "Opa {$name}! Ta chegando hora do almoco! Bora pedir pelo SuperBora? \xF0\x9F\x98\x8B",
                    "E ai {$name}! Bateu a fome? Da uma olhada nas lojas abertas no SuperBora! \xF0\x9F\x8D\xBD",
                ];
            }

            $message = $messages[array_rand($messages)];

            if (sendProactive($db, (int) $customer['customer_id'], $customer['phone'], 'lunch', $message, $log)) {
                $sentCount++;
            }
        }

        $log("  Lunch messages sent: {$sentCount}");
        $totalSent += $sentCount;
    } catch (Exception $e) {
        $log("  ERROR lunch: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════
// 2. DINNER SUGGESTION (17:30-18:00)
// ═══════════════════════════════════════════════════════════════
if ($timeDecimal >= 17.5 && $timeDecimal < 18.0) {
    $log("--- Dinner suggestions ---");
    $sentCount = 0;

    try {
        $stmt = $db->prepare("
            SELECT DISTINCT
                c.customer_id,
                c.phone,
                c.name
            FROM om_market_customers c
            JOIN om_market_orders o ON o.customer_id = c.customer_id
            WHERE c.phone IS NOT NULL
              AND c.phone != ''
              AND o.status NOT IN ('cancelado', 'recusado', 'refunded')
              AND o.date_added >= NOW() - INTERVAL '30 days'
              AND c.customer_id NOT IN (
                  SELECT customer_id FROM om_market_orders
                  WHERE date_added::date = CURRENT_DATE
                    AND date_added::time >= '15:00:00'
                    AND status NOT IN ('cancelado', 'recusado')
              )
              AND c.customer_id NOT IN (
                  SELECT customer_id FROM om_whatsapp_proactive_optout
              )
              AND c.customer_id NOT IN (
                  SELECT customer_id FROM om_whatsapp_proactive_log
                  WHERE message_type = 'dinner' AND sent_at::date = CURRENT_DATE
              )
            ORDER BY o.date_added DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', PROACTIVE_MAX_PER_RUN, PDO::PARAM_INT);
        $stmt->execute();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $log("  Found " . count($candidates) . " dinner candidates");

        foreach ($candidates as $customer) {
            if ($sentCount >= PROACTIVE_MAX_PER_RUN) break;

            $name = firstName($customer['name'] ?? 'Amigo');
            $favStore = getCustomerFavoriteStore($db, (int) $customer['customer_id']);

            if ($favStore && $favStore['store_name']) {
                $storeName = $favStore['store_name'];
                $messages = [
                    "E ai {$name}! Ja pensou no jantar? A {$storeName} ta aberta e com entregas rapidas! \xF0\x9F\x8D\x95",
                    "Fala {$name}! Bora jantar? Que tal pedir da {$storeName} hoje? \xF0\x9F\x98\x8B",
                    "Opa {$name}! Noite de delivery! A {$storeName} ta esperando seu pedido \xF0\x9F\x8C\x99",
                ];
            } else {
                $messages = [
                    "E ai {$name}! Ja pensou no jantar? Tem varias lojas abertas no SuperBora! \xF0\x9F\x8D\x95",
                    "Fala {$name}! Bora jantar? Da uma olhada no que tem de bom no SuperBora! \xF0\x9F\x98\x8B",
                ];
            }

            $message = $messages[array_rand($messages)];

            if (sendProactive($db, (int) $customer['customer_id'], $customer['phone'], 'dinner', $message, $log)) {
                $sentCount++;
            }
        }

        $log("  Dinner messages sent: {$sentCount}");
        $totalSent += $sentCount;
    } catch (Exception $e) {
        $log("  ERROR dinner: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════
// 3. PROMO ALERTS (any time 8:00-21:00)
// ═══════════════════════════════════════════════════════════════
if ($hour >= 8 && $hour < 21) {
    $log("--- Promo alerts ---");
    $sentCount = 0;

    try {
        // Find products currently on sale (special_price set and lower than price)
        $promoStmt = $db->prepare("
            SELECT
                p.product_id,
                p.name as product_name,
                p.price,
                p.special_price,
                p.partner_id,
                COALESCE(pa.trade_name, pa.name) as store_name
            FROM om_market_products p
            JOIN om_market_partners pa ON pa.partner_id = p.partner_id
            WHERE p.special_price IS NOT NULL
              AND p.special_price > 0
              AND p.special_price < p.price
              AND p.status::text = '1'
              AND pa.status::text = '1'
              AND pa.is_open = 1
            ORDER BY (p.price - p.special_price) DESC
            LIMIT 20
        ");
        $promoStmt->execute();
        $promos = $promoStmt->fetchAll(PDO::FETCH_ASSOC);

        $log("  Found " . count($promos) . " products on sale");

        foreach ($promos as $promo) {
            if ($sentCount >= PROACTIVE_MAX_PER_RUN) break;

            // Find customers who ordered from this store before
            $custStmt = $db->prepare("
                SELECT DISTINCT
                    c.customer_id,
                    c.phone,
                    c.name
                FROM om_market_customers c
                JOIN om_market_orders o ON o.customer_id = c.customer_id
                WHERE o.partner_id = ?
                  AND o.status NOT IN ('cancelado', 'recusado', 'refunded')
                  AND c.phone IS NOT NULL
                  AND c.phone != ''
                  AND c.customer_id NOT IN (
                      SELECT customer_id FROM om_whatsapp_proactive_optout
                  )
                  AND c.customer_id NOT IN (
                      SELECT customer_id FROM om_whatsapp_proactive_log
                      WHERE message_type = 'promo' AND sent_at::date = CURRENT_DATE
                  )
                ORDER BY o.date_added DESC
                LIMIT 10
            ");
            $custStmt->execute([$promo['partner_id']]);
            $customers = $custStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($customers as $customer) {
                if ($sentCount >= PROACTIVE_MAX_PER_RUN) break;

                $priceFmt = number_format((float) $promo['price'], 2, ',', '.');
                $specialFmt = number_format((float) $promo['special_price'], 2, ',', '.');
                $storeName = $promo['store_name'];
                $productName = $promo['product_name'];

                $messages = [
                    "\xF0\x9F\x94\xA5 *Promo na {$storeName}!* {$productName} de R\${$priceFmt} por *R\${$specialFmt}*! Aproveita!",
                    "\xE2\x9A\xA1 *Oferta {$storeName}!* {$productName} saiu de R\${$priceFmt} pra *R\${$specialFmt}*! Corre!",
                    "\xF0\x9F\x8E\x89 *Desconto na {$storeName}!* {$productName} por apenas *R\${$specialFmt}* (era R\${$priceFmt}). Bora pedir?",
                ];

                $message = $messages[array_rand($messages)];

                if (sendProactive($db, (int) $customer['customer_id'], $customer['phone'], 'promo', $message, $log)) {
                    $sentCount++;
                }
            }
        }

        $log("  Promo messages sent: {$sentCount}");
        $totalSent += $sentCount;
    } catch (Exception $e) {
        $log("  ERROR promo: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════
// 4. WIN-BACK (any time, once per week per customer)
// ═══════════════════════════════════════════════════════════════
if ($hour >= 10 && $hour < 20) {
    $log("--- Win-back messages ---");
    $sentCount = 0;

    try {
        // Find customers who haven't ordered in 15-30 days
        $stmt = $db->prepare("
            SELECT
                c.customer_id,
                c.phone,
                c.name,
                MAX(o.date_added) as last_order
            FROM om_market_customers c
            JOIN om_market_orders o ON o.customer_id = c.customer_id
            WHERE c.phone IS NOT NULL
              AND c.phone != ''
              AND o.status NOT IN ('cancelado', 'recusado', 'refunded')
              AND c.customer_id NOT IN (
                  SELECT customer_id FROM om_whatsapp_proactive_optout
              )
              AND c.customer_id NOT IN (
                  SELECT customer_id FROM om_whatsapp_proactive_log
                  WHERE message_type = 'winback'
                    AND sent_at >= NOW() - INTERVAL '7 days'
              )
            GROUP BY c.customer_id, c.phone, c.name
            HAVING MAX(o.date_added) BETWEEN NOW() - INTERVAL '30 days' AND NOW() - INTERVAL '15 days'
            ORDER BY MAX(o.date_added) ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', PROACTIVE_MAX_PER_RUN, PDO::PARAM_INT);
        $stmt->execute();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $log("  Found " . count($candidates) . " win-back candidates");

        foreach ($candidates as $customer) {
            if ($sentCount >= PROACTIVE_MAX_PER_RUN) break;

            $name = firstName($customer['name'] ?? 'Amigo');
            $daysSince = (int) round((time() - strtotime($customer['last_order'])) / 86400);

            $messages = [
                "Oi {$name}! Faz tempo que voce nao pede pela SuperBora! Sentimos sua falta \xF0\x9F\x98\xA2 Que tal dar uma olhada nas novidades?",
                "E ai {$name}! Sumiu, hein? Tem coisa nova no SuperBora! Bora dar uma olhada? \xF0\x9F\x91\x80",
                "Fala {$name}! Faz {$daysSince} dias que a gente nao se fala! Tem muita coisa boa te esperando no SuperBora \xF0\x9F\x98\x8A",
            ];

            $message = $messages[array_rand($messages)];

            if (sendProactive($db, (int) $customer['customer_id'], $customer['phone'], 'winback', $message, $log)) {
                $sentCount++;
            }
        }

        $log("  Win-back messages sent: {$sentCount}");
        $totalSent += $sentCount;
    } catch (Exception $e) {
        $log("  ERROR winback: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════
// CLEANUP: Delete old log entries (> 90 days)
// ═══════════════════════════════════════════════════════════════
try {
    $stmt = $db->prepare("
        DELETE FROM om_whatsapp_proactive_log
        WHERE sent_at < NOW() - INTERVAL '90 days'
    ");
    $stmt->execute();
    $cleaned = $stmt->rowCount();
    if ($cleaned > 0) {
        $log("Cleaned {$cleaned} old log entries");
    }
} catch (Exception $e) {
    // Non-critical
}

$log("=== Total proactive messages sent: {$totalSent} ===");
$log("=== WhatsApp proactive messaging finished ===\n");

// Release file lock
flock($lockFp, LOCK_UN);
fclose($lockFp);
