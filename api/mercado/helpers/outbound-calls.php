<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * OUTBOUND CALLS — SuperBora AI Calling System
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Initiates, schedules, and manages outbound phone calls via Twilio REST API.
 *
 * Features:
 *   - Single call initiation (order confirm, delivery update, survey, promo, reengagement)
 *   - Scheduled calls with queue table + cron pickup
 *   - Bulk campaign with rate limiting (1 call / 5 seconds)
 *   - Smart reengagement target finder (win-back, abandoned cart, birthday, habits)
 *   - Opt-out tracking (LGPD compliant)
 *
 * Usage:
 *   require_once __DIR__ . '/outbound-calls.php';
 *   $sid = initiateOutboundCall($db, '+5519999999999', 'order_confirm', ['order_id' => 123, ...]);
 *   scheduleOutboundCall($db, '+5519999999999', 'promo', ['promo' => '20% off'], '2026-03-07 10:00:00');
 *
 * SQL (run once):
 *
 * CREATE TABLE IF NOT EXISTS om_outbound_calls (
 *     id SERIAL PRIMARY KEY,
 *     twilio_call_sid VARCHAR(64) UNIQUE,
 *     phone VARCHAR(20) NOT NULL,
 *     customer_id INT,
 *     customer_name VARCHAR(100),
 *     call_type VARCHAR(30) NOT NULL CHECK (call_type IN (
 *         'order_confirm', 'delivery_update', 'reengagement', 'survey', 'promo', 'custom'
 *     )),
 *     call_data JSONB DEFAULT '{}',
 *     campaign_id INT,
 *     status VARCHAR(20) DEFAULT 'initiated' CHECK (status IN (
 *         'initiated', 'ringing', 'answered', 'completed', 'no_answer', 'busy', 'failed', 'voicemail', 'opt_out'
 *     )),
 *     outcome VARCHAR(30) CHECK (outcome IS NULL OR outcome IN (
 *         'confirmed', 'ordered', 'declined', 'opt_out', 'callback', 'voicemail', 'no_interaction', 'survey_completed'
 *     )),
 *     outcome_data JSONB DEFAULT '{}',
 *     duration_seconds INT,
 *     recording_url TEXT,
 *     attempts INT DEFAULT 1,
 *     last_attempt_at TIMESTAMPTZ DEFAULT NOW(),
 *     created_at TIMESTAMPTZ DEFAULT NOW(),
 *     updated_at TIMESTAMPTZ DEFAULT NOW()
 * );
 *
 * CREATE TABLE IF NOT EXISTS om_outbound_call_queue (
 *     id SERIAL PRIMARY KEY,
 *     phone VARCHAR(20) NOT NULL,
 *     customer_id INT,
 *     customer_name VARCHAR(100),
 *     call_type VARCHAR(30) NOT NULL,
 *     call_data JSONB DEFAULT '{}',
 *     campaign_id INT,
 *     scheduled_at TIMESTAMPTZ NOT NULL,
 *     priority INT DEFAULT 5 CHECK (priority BETWEEN 1 AND 10),
 *     status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'completed', 'failed', 'cancelled')),
 *     outbound_call_id INT REFERENCES om_outbound_calls(id),
 *     error_message TEXT,
 *     created_at TIMESTAMPTZ DEFAULT NOW(),
 *     processed_at TIMESTAMPTZ
 * );
 *
 * CREATE TABLE IF NOT EXISTS om_outbound_campaigns (
 *     id SERIAL PRIMARY KEY,
 *     name VARCHAR(200) NOT NULL,
 *     call_type VARCHAR(30) NOT NULL,
 *     call_data JSONB DEFAULT '{}',
 *     total_targets INT DEFAULT 0,
 *     calls_made INT DEFAULT 0,
 *     calls_answered INT DEFAULT 0,
 *     calls_opted_out INT DEFAULT 0,
 *     calls_ordered INT DEFAULT 0,
 *     status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'paused', 'completed', 'cancelled')),
 *     created_at TIMESTAMPTZ DEFAULT NOW(),
 *     completed_at TIMESTAMPTZ
 * );
 *
 * CREATE TABLE IF NOT EXISTS om_outbound_opt_outs (
 *     id SERIAL PRIMARY KEY,
 *     phone VARCHAR(20) NOT NULL UNIQUE,
 *     customer_id INT,
 *     reason TEXT,
 *     opted_out_at TIMESTAMPTZ DEFAULT NOW()
 * );
 *
 * CREATE INDEX IF NOT EXISTS idx_outbound_calls_phone ON om_outbound_calls(phone);
 * CREATE INDEX IF NOT EXISTS idx_outbound_calls_type ON om_outbound_calls(call_type);
 * CREATE INDEX IF NOT EXISTS idx_outbound_calls_status ON om_outbound_calls(status);
 * CREATE INDEX IF NOT EXISTS idx_outbound_calls_campaign ON om_outbound_calls(campaign_id);
 * CREATE INDEX IF NOT EXISTS idx_outbound_calls_created ON om_outbound_calls(created_at DESC);
 * CREATE INDEX IF NOT EXISTS idx_outbound_queue_pending ON om_outbound_call_queue(scheduled_at)
 *     WHERE status = 'pending';
 * CREATE INDEX IF NOT EXISTS idx_outbound_queue_status ON om_outbound_call_queue(status);
 * CREATE INDEX IF NOT EXISTS idx_outbound_opt_outs_phone ON om_outbound_opt_outs(phone);
 * CREATE INDEX IF NOT EXISTS idx_outbound_campaigns_status ON om_outbound_campaigns(status);
 */

require_once __DIR__ . '/twilio-sms.php';

// ─── Twilio Credentials ─────────────────────────────────────────────────────

function _getOutboundTwilioSid(): string {
    return $_ENV['TWILIO_SID'] ?? getenv('TWILIO_SID') ?: '';
}

function _getOutboundTwilioToken(): string {
    return $_ENV['TWILIO_TOKEN'] ?? getenv('TWILIO_TOKEN') ?: '';
}

function _getOutboundTwilioPhone(): string {
    return $_ENV['TWILIO_CALLCENTER_PHONE'] ?? getenv('TWILIO_CALLCENTER_PHONE')
        ?: ($_ENV['TWILIO_PHONE'] ?? getenv('TWILIO_PHONE') ?: '');
}

// ─── Core: Initiate Outbound Call ────────────────────────────────────────────

/**
 * Initiate an outbound call via Twilio REST API.
 *
 * @param PDO    $db    Database connection
 * @param string $phone Phone number (E.164 format preferred, e.g. +5519999999999)
 * @param string $type  Call type: order_confirm, delivery_update, reengagement, survey, promo, custom
 * @param array  $data  Context data for the call script (order_id, promo text, etc.)
 * @return array{success: bool, call_sid?: string, outbound_id?: int, error?: string}
 */
function initiateOutboundCall(PDO $db, string $phone, string $type, array $data = []): array
{
    $sid = _getOutboundTwilioSid();
    $token = _getOutboundTwilioToken();
    $fromPhone = _getOutboundTwilioPhone();

    if (empty($sid) || empty($token) || empty($fromPhone)) {
        error_log("[outbound-calls] Twilio credentials not configured");
        return ['success' => false, 'error' => 'Twilio credentials not configured'];
    }

    // Format phone
    $phone = formatPhoneForTwilio($phone);
    if (empty($phone)) {
        return ['success' => false, 'error' => 'Número de telefone inválido'];
    }

    // Validate call type
    $validTypes = ['order_confirm', 'delivery_update', 'reengagement', 'survey', 'promo', 'custom'];
    if (!in_array($type, $validTypes, true)) {
        return ['success' => false, 'error' => 'Tipo de ligação inválido: ' . $type];
    }

    // Check opt-out
    if (isPhoneOptedOut($db, $phone)) {
        error_log("[outbound-calls] Phone opted out: " . substr($phone, 0, 6) . '***');
        return ['success' => false, 'error' => 'Número optou por não receber ligações'];
    }

    // Rate limit: don't call the same number more than once per hour
    $recentCall = $db->prepare("
        SELECT id FROM om_outbound_calls
        WHERE phone = ? AND created_at > NOW() - INTERVAL '1 hour'
        LIMIT 1
    ");
    $recentCall->execute([$phone]);
    if ($recentCall->fetch()) {
        error_log("[outbound-calls] Rate limited: recently called " . substr($phone, 0, 6) . '***');
        return ['success' => false, 'error' => 'Ligação recente para este número (aguarde 1 hora)'];
    }

    // Look up customer info
    $customerInfo = lookupCustomerByPhone($db, $phone);
    $customerId = $customerInfo['customer_id'] ?? null;
    $customerName = $customerInfo['name'] ?? ($data['customer_name'] ?? null);

    // Build webhook URL
    $webhookBase = $_ENV['TWILIO_WEBHOOK_URL']
        ?? getenv('TWILIO_WEBHOOK_URL')
        ?: 'https://superbora.com.br/api/mercado/webhooks';
    $webhookUrl = $webhookBase . '/twilio-voice-outbound.php';

    // Encode call context as query params so the webhook knows what to say
    $webhookParams = http_build_query([
        'call_type'     => $type,
        'call_data_b64' => base64_encode(json_encode($data)),
        'customer_name' => $customerName ?? '',
        'customer_id'   => $customerId ?? '',
    ]);
    $webhookUrlFull = $webhookUrl . '?' . $webhookParams;

    // Status callback URL (reuse twilio-status.php or a custom one)
    $statusCallbackUrl = $webhookBase . '/twilio-voice-outbound.php?status_callback=1';

    // Insert call record first (so we have the ID)
    $stmt = $db->prepare("
        INSERT INTO om_outbound_calls
            (phone, customer_id, customer_name, call_type, call_data, status, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, 'initiated', NOW(), NOW())
        RETURNING id
    ");
    $stmt->execute([
        $phone,
        $customerId,
        $customerName,
        $type,
        json_encode($data, JSON_UNESCAPED_UNICODE),
    ]);
    $outboundId = (int)$stmt->fetchColumn();

    // Make the Twilio REST API call
    $twilioUrl = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Calls.json";

    $postFields = [
        'From'               => $fromPhone,
        'To'                 => $phone,
        'Url'                => $webhookUrlFull,
        'StatusCallback'     => $statusCallbackUrl,
        'StatusCallbackEvent' => 'initiated ringing answered completed',
        'MachineDetection'   => 'DetectMessageEnd',        // Detect voicemail
        'MachineDetectionTimeout' => 5,
        'Timeout'            => 30,                          // Ring for 30 seconds
        'Record'             => 'false',
    ];

    $ch = curl_init($twilioUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postFields),
        CURLOPT_USERPWD        => $sid . ':' . $token,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("[outbound-calls] cURL error: {$curlError}");
        $db->prepare("UPDATE om_outbound_calls SET status = 'failed', updated_at = NOW() WHERE id = ?")
           ->execute([$outboundId]);
        return ['success' => false, 'error' => 'Erro de conexão com Twilio: ' . $curlError];
    }

    $result = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && !empty($result['sid'])) {
        $callSid = $result['sid'];

        // Update with Twilio SID
        $db->prepare("
            UPDATE om_outbound_calls
            SET twilio_call_sid = ?, status = 'ringing', updated_at = NOW()
            WHERE id = ?
        ")->execute([$callSid, $outboundId]);

        // Also create a record in om_callcenter_calls for unified tracking
        try {
            $db->prepare("
                INSERT INTO om_callcenter_calls
                    (twilio_call_sid, customer_phone, customer_id, customer_name, direction, status, started_at)
                VALUES (?, ?, ?, ?, 'outbound', 'ai_handling', NOW())
                ON CONFLICT (twilio_call_sid) DO NOTHING
            ")->execute([$callSid, $phone, $customerId, $customerName]);
        } catch (\Exception $e) {
            error_log("[outbound-calls] Failed to create callcenter record (non-fatal): " . $e->getMessage());
        }

        error_log("[outbound-calls] Call initiated: SID={$callSid} type={$type} to=" . substr($phone, 0, 6) . '***');
        return ['success' => true, 'call_sid' => $callSid, 'outbound_id' => $outboundId];
    }

    $errMsg = $result['message'] ?? "HTTP {$httpCode}";
    error_log("[outbound-calls] Twilio API error: {$errMsg} | HTTP {$httpCode}");
    $db->prepare("UPDATE om_outbound_calls SET status = 'failed', updated_at = NOW() WHERE id = ?")
       ->execute([$outboundId]);

    return ['success' => false, 'error' => 'Twilio error: ' . $errMsg];
}

// ─── Schedule Outbound Call ──────────────────────────────────────────────────

/**
 * Schedule an outbound call for later execution.
 * A cron job should call processScheduledCalls() periodically.
 *
 * @param PDO    $db          Database connection
 * @param string $phone       Phone number
 * @param string $type        Call type
 * @param array  $data        Call context data
 * @param string $scheduledAt When to make the call (Y-m-d H:i:s, America/Sao_Paulo)
 * @param int    $priority    1 (highest) to 10 (lowest), default 5
 * @return array{success: bool, queue_id?: int, error?: string}
 */
function scheduleOutboundCall(
    PDO $db,
    string $phone,
    string $type,
    array $data,
    string $scheduledAt,
    int $priority = 5
): array {
    $phone = formatPhoneForTwilio($phone);
    if (empty($phone)) {
        return ['success' => false, 'error' => 'Número de telefone inválido'];
    }

    if (isPhoneOptedOut($db, $phone)) {
        return ['success' => false, 'error' => 'Número optou por não receber ligações'];
    }

    // Look up customer
    $customerInfo = lookupCustomerByPhone($db, $phone);

    $stmt = $db->prepare("
        INSERT INTO om_outbound_call_queue
            (phone, customer_id, customer_name, call_type, call_data, scheduled_at, priority, status, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        RETURNING id
    ");
    $stmt->execute([
        $phone,
        $customerInfo['customer_id'] ?? null,
        $customerInfo['name'] ?? ($data['customer_name'] ?? null),
        $type,
        json_encode($data, JSON_UNESCAPED_UNICODE),
        $scheduledAt,
        max(1, min(10, $priority)),
    ]);
    $queueId = (int)$stmt->fetchColumn();

    error_log("[outbound-calls] Scheduled call: queue_id={$queueId} type={$type} at={$scheduledAt}");
    return ['success' => true, 'queue_id' => $queueId];
}

/**
 * Process scheduled calls that are due.
 * Call this from a cron job every minute:
 *   * * * * * php /var/www/html/api/mercado/cron/process-outbound-queue.php
 *
 * @param PDO $db
 * @param int $batchSize Max calls to process per run
 * @return array{processed: int, succeeded: int, failed: int}
 */
function processScheduledCalls(PDO $db, int $batchSize = 10): array
{
    $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];

    // Fetch due calls ordered by priority and scheduled time
    $stmt = $db->prepare("
        SELECT id, phone, customer_id, customer_name, call_type, call_data, campaign_id
        FROM om_outbound_call_queue
        WHERE status = 'pending'
          AND scheduled_at <= NOW()
        ORDER BY priority ASC, scheduled_at ASC
        LIMIT ?
    ");
    $stmt->execute([$batchSize]);
    $queue = $stmt->fetchAll();

    foreach ($queue as $item) {
        $queueId = (int)$item['id'];

        // Mark as processing
        $db->prepare("UPDATE om_outbound_call_queue SET status = 'processing', processed_at = NOW() WHERE id = ?")
           ->execute([$queueId]);

        $callData = json_decode($item['call_data'] ?: '{}', true) ?: [];
        if (!empty($item['campaign_id'])) {
            $callData['campaign_id'] = (int)$item['campaign_id'];
        }

        $result = initiateOutboundCall($db, $item['phone'], $item['call_type'], $callData);

        if ($result['success']) {
            $db->prepare("
                UPDATE om_outbound_call_queue
                SET status = 'completed', outbound_call_id = ?
                WHERE id = ?
            ")->execute([$result['outbound_id'], $queueId]);

            // Link campaign if present
            if (!empty($item['campaign_id'])) {
                $db->prepare("
                    UPDATE om_outbound_calls SET campaign_id = ? WHERE id = ?
                ")->execute([(int)$item['campaign_id'], $result['outbound_id']]);
            }

            $stats['succeeded']++;
        } else {
            $db->prepare("
                UPDATE om_outbound_call_queue
                SET status = 'failed', error_message = ?
                WHERE id = ?
            ")->execute([$result['error'] ?? 'Unknown error', $queueId]);
            $stats['failed']++;
        }

        $stats['processed']++;

        // Rate limit: 5 seconds between calls to avoid Twilio throttling
        if ($stats['processed'] < count($queue)) {
            sleep(5);
        }
    }

    return $stats;
}

// ─── Bulk Campaign ───────────────────────────────────────────────────────────

/**
 * Create a bulk outbound campaign and schedule calls for multiple numbers.
 *
 * @param PDO    $db           Database connection
 * @param string $campaignName Campaign identifier
 * @param array  $phones       Array of phone numbers (or arrays with 'phone', 'name', 'data')
 * @param string $type         Call type for all calls
 * @param array  $data         Shared call data (merged with per-phone data)
 * @return array{success: bool, campaign_id?: int, queued?: int, skipped?: int, error?: string}
 */
function bulkOutboundCampaign(
    PDO $db,
    string $campaignName,
    array $phones,
    string $type,
    array $data = []
): array {
    if (empty($phones)) {
        return ['success' => false, 'error' => 'Nenhum número fornecido'];
    }

    // Create campaign record
    $stmt = $db->prepare("
        INSERT INTO om_outbound_campaigns (name, call_type, call_data, total_targets, status, created_at)
        VALUES (?, ?, ?, ?, 'active', NOW())
        RETURNING id
    ");
    $stmt->execute([
        $campaignName,
        $type,
        json_encode($data, JSON_UNESCAPED_UNICODE),
        count($phones),
    ]);
    $campaignId = (int)$stmt->fetchColumn();

    $queued = 0;
    $skipped = 0;

    // Stagger calls: 1 call per 5 seconds = 12 per minute
    $delay = 0;
    $now = new \DateTime('now', new \DateTimeZone('America/Sao_Paulo'));

    foreach ($phones as $entry) {
        // Normalize entry format
        if (is_string($entry)) {
            $phone = $entry;
            $perPhoneData = [];
        } elseif (is_array($entry)) {
            $phone = $entry['phone'] ?? '';
            $perPhoneData = $entry['data'] ?? [];
            if (!empty($entry['name'])) {
                $perPhoneData['customer_name'] = $entry['name'];
            }
        } else {
            $skipped++;
            continue;
        }

        $formatted = formatPhoneForTwilio($phone);
        if (empty($formatted)) {
            $skipped++;
            continue;
        }

        // Check opt-out
        if (isPhoneOptedOut($db, $formatted)) {
            $skipped++;
            continue;
        }

        // Schedule with 5-second stagger
        $scheduledAt = clone $now;
        $scheduledAt->modify("+{$delay} seconds");

        $mergedData = array_merge($data, $perPhoneData, ['campaign_id' => $campaignId]);

        $customerInfo = lookupCustomerByPhone($db, $formatted);

        $db->prepare("
            INSERT INTO om_outbound_call_queue
                (phone, customer_id, customer_name, call_type, call_data, campaign_id, scheduled_at, priority, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 5, 'pending', NOW())
        ")->execute([
            $formatted,
            $customerInfo['customer_id'] ?? null,
            $customerInfo['name'] ?? ($perPhoneData['customer_name'] ?? null),
            $type,
            json_encode($mergedData, JSON_UNESCAPED_UNICODE),
            $campaignId,
            $scheduledAt->format('Y-m-d H:i:s'),
        ]);

        $queued++;
        $delay += 5; // 5-second gap between each call
    }

    error_log("[outbound-calls] Campaign created: id={$campaignId} name={$campaignName} queued={$queued} skipped={$skipped}");

    return [
        'success' => true,
        'campaign_id' => $campaignId,
        'queued' => $queued,
        'skipped' => $skipped,
    ];
}

// ─── Smart Reengagement Targets ──────────────────────────────────────────────

/**
 * Find customers eligible for reengagement calls.
 *
 * Segments:
 *   1. Win-back: Haven't ordered in 14-30 days
 *   2. Abandoned cart: Items in cart but no order in last 24h
 *   3. Birthday: Birthday is today
 *   4. Habit match: Ordered at same day/time last week
 *
 * @param PDO $db
 * @param int $limit Max targets to return
 * @return array Array of targets with phone, name, reason, suggested_script, data
 */
function findReengagementTargets(PDO $db, int $limit = 50): array
{
    $targets = [];

    // ── 1. Win-back: Last order 14-30 days ago ──────────────────────────────
    $winback = $db->query("
        SELECT
            c.customer_id,
            c.name,
            c.phone,
            MAX(o.created_at) AS last_order_at,
            COUNT(o.order_id) AS total_orders,
            ROUND(AVG(o.total)::numeric, 2) AS avg_ticket
        FROM om_market_customers c
        JOIN om_market_orders o ON o.customer_id = c.customer_id
            AND o.status IN ('entregue', 'delivered', 'finalizado')
        WHERE c.phone IS NOT NULL
          AND c.phone != ''
        GROUP BY c.customer_id, c.name, c.phone
        HAVING MAX(o.created_at) BETWEEN NOW() - INTERVAL '30 days' AND NOW() - INTERVAL '14 days'
        ORDER BY COUNT(o.order_id) DESC
        LIMIT {$limit}
    ")->fetchAll();

    foreach ($winback as $row) {
        $daysSince = (int)round((time() - strtotime($row['last_order_at'])) / 86400);
        $targets[] = [
            'phone'       => $row['phone'],
            'name'        => $row['name'],
            'customer_id' => (int)$row['customer_id'],
            'reason'      => 'win_back',
            'reason_label' => "Não pede há {$daysSince} dias ({$row['total_orders']} pedidos anteriores)",
            'suggested_script' => "Faz um tempinho que você não pede, né? Tô com saudade! Tem umas promoções legais rolando.",
            'data' => [
                'days_since_order' => $daysSince,
                'total_orders'     => (int)$row['total_orders'],
                'avg_ticket'       => (float)$row['avg_ticket'],
            ],
        ];
    }

    // ── 2. Abandoned Cart: Items in cart, no order in 24h ───────────────────
    $abandoned = $db->query("
        SELECT
            c.customer_id,
            cu.name,
            cu.phone,
            COUNT(c.cart_id) AS cart_items,
            SUM(c.price * c.quantity) AS cart_total,
            MAX(c.created_at) AS cart_updated_at
        FROM om_market_cart c
        JOIN om_market_customers cu ON cu.customer_id = c.customer_id
        WHERE c.customer_id IS NOT NULL
          AND cu.phone IS NOT NULL
          AND cu.phone != ''
          AND c.created_at > NOW() - INTERVAL '24 hours'
          AND c.customer_id NOT IN (
              SELECT DISTINCT customer_id FROM om_market_orders
              WHERE created_at > NOW() - INTERVAL '24 hours'
              AND customer_id IS NOT NULL
          )
        GROUP BY c.customer_id, cu.name, cu.phone
        HAVING SUM(c.price * c.quantity) > 10
        ORDER BY SUM(c.price * c.quantity) DESC
        LIMIT {$limit}
    ")->fetchAll();

    foreach ($abandoned as $row) {
        $cartTotal = number_format((float)$row['cart_total'], 2, ',', '.');
        $targets[] = [
            'phone'       => $row['phone'],
            'name'        => $row['name'],
            'customer_id' => (int)$row['customer_id'],
            'reason'      => 'abandoned_cart',
            'reason_label' => "Carrinho abandonado: {$row['cart_items']} itens, R\$ {$cartTotal}",
            'suggested_script' => "Vi que você montou um carrinho mas não finalizou. Quer uma mãozinha pra completar o pedido?",
            'data' => [
                'cart_items' => (int)$row['cart_items'],
                'cart_total' => (float)$row['cart_total'],
            ],
        ];
    }

    // ── 3. Birthday Today ───────────────────────────────────────────────────
    $birthdays = $db->query("
        SELECT customer_id, name, phone
        FROM om_market_customers
        WHERE phone IS NOT NULL
          AND phone != ''
          AND birthday IS NOT NULL
          AND EXTRACT(MONTH FROM birthday) = EXTRACT(MONTH FROM CURRENT_DATE)
          AND EXTRACT(DAY FROM birthday) = EXTRACT(DAY FROM CURRENT_DATE)
        LIMIT {$limit}
    ")->fetchAll();

    foreach ($birthdays as $row) {
        $targets[] = [
            'phone'       => $row['phone'],
            'name'        => $row['name'],
            'customer_id' => (int)$row['customer_id'],
            'reason'      => 'birthday',
            'reason_label' => 'Aniversário hoje!',
            'suggested_script' => "Parabéns pelo seu dia! A gente preparou um desconto especial de aniversário pra você.",
            'data' => [
                'birthday' => true,
            ],
        ];
    }

    // ── 4. Habit Match: Ordered same day/time last week ─────────────────────
    $currentDow = (int)date('w'); // 0=Sun, 6=Sat
    $currentHour = (int)date('H');

    $habits = $db->prepare("
        SELECT
            c.customer_id,
            c.name,
            c.phone,
            COUNT(*) AS matching_orders,
            MAX(o.created_at) AS last_matching_order
        FROM om_market_customers c
        JOIN om_market_orders o ON o.customer_id = c.customer_id
            AND o.status IN ('entregue', 'delivered', 'finalizado')
        WHERE c.phone IS NOT NULL
          AND c.phone != ''
          AND EXTRACT(DOW FROM o.created_at) = ?
          AND EXTRACT(HOUR FROM o.created_at) BETWEEN ? AND ?
          AND o.created_at < NOW() - INTERVAL '5 days'
          AND c.customer_id NOT IN (
              SELECT DISTINCT customer_id FROM om_market_orders
              WHERE created_at > NOW() - INTERVAL '5 days'
              AND customer_id IS NOT NULL
          )
        GROUP BY c.customer_id, c.name, c.phone
        HAVING COUNT(*) >= 2
        ORDER BY COUNT(*) DESC
        LIMIT ?
    ");
    $habits->execute([$currentDow, max(0, $currentHour - 1), min(23, $currentHour + 1), $limit]);

    $dayNames = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
    $dayName = $dayNames[$currentDow] ?? 'hoje';

    foreach ($habits->fetchAll() as $row) {
        $targets[] = [
            'phone'       => $row['phone'],
            'name'        => $row['name'],
            'customer_id' => (int)$row['customer_id'],
            'reason'      => 'habit_match',
            'reason_label' => "Costuma pedir {$dayName} nesse horário ({$row['matching_orders']}x)",
            'suggested_script' => "Toda {$dayName} por volta dessa hora você costuma pedir, né? Tô ligando pra facilitar!",
            'data' => [
                'matching_orders' => (int)$row['matching_orders'],
                'day_of_week'     => $currentDow,
                'hour'            => $currentHour,
            ],
        ];
    }

    // Filter out opted-out phones
    $targets = array_filter($targets, function ($t) use ($db) {
        return !isPhoneOptedOut($db, formatPhoneForTwilio($t['phone']));
    });

    // Deduplicate by phone
    $seen = [];
    $unique = [];
    foreach ($targets as $t) {
        $normalized = preg_replace('/\D/', '', $t['phone']);
        if (!isset($seen[$normalized])) {
            $seen[$normalized] = true;
            $unique[] = $t;
        }
    }

    return array_slice($unique, 0, $limit);
}

// ─── Opt-Out Management ──────────────────────────────────────────────────────

/**
 * Check if a phone number has opted out of calls.
 */
function isPhoneOptedOut(PDO $db, string $phone): bool
{
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 10) return false;

    // Check exact match and without country code
    $withoutCountry = $digits;
    if (strlen($digits) === 13 && str_starts_with($digits, '55')) {
        $withoutCountry = substr($digits, 2);
    }

    $stmt = $db->prepare("
        SELECT 1 FROM om_outbound_opt_outs
        WHERE phone IN (?, ?, ?)
        LIMIT 1
    ");
    $stmt->execute(['+' . $digits, '+55' . $withoutCountry, $phone]);
    return (bool)$stmt->fetch();
}

/**
 * Record a phone opt-out.
 */
function recordOptOut(PDO $db, string $phone, ?int $customerId = null, ?string $reason = null): void
{
    $phone = formatPhoneForTwilio($phone);
    if (empty($phone)) return;

    $db->prepare("
        INSERT INTO om_outbound_opt_outs (phone, customer_id, reason, opted_out_at)
        VALUES (?, ?, ?, NOW())
        ON CONFLICT (phone) DO UPDATE SET
            customer_id = COALESCE(EXCLUDED.customer_id, om_outbound_opt_outs.customer_id),
            reason = COALESCE(EXCLUDED.reason, om_outbound_opt_outs.reason)
    ")->execute([$phone, $customerId, $reason]);

    error_log("[outbound-calls] Opt-out recorded: " . substr($phone, 0, 6) . '***');
}

// ─── Call Outcome Tracking ───────────────────────────────────────────────────

/**
 * Update the outcome of an outbound call.
 */
function updateOutboundCallOutcome(
    PDO $db,
    string $callSid,
    string $status,
    ?string $outcome = null,
    array $outcomeData = [],
    ?int $duration = null
): void {
    $validStatuses = ['initiated', 'ringing', 'answered', 'completed', 'no_answer', 'busy', 'failed', 'voicemail', 'opt_out'];
    if (!in_array($status, $validStatuses, true)) {
        $status = 'completed';
    }

    $sets = ['status = ?', 'updated_at = NOW()'];
    $params = [$status];

    if ($outcome !== null) {
        $sets[] = 'outcome = ?';
        $params[] = $outcome;
    }
    if (!empty($outcomeData)) {
        $sets[] = 'outcome_data = ?';
        $params[] = json_encode($outcomeData, JSON_UNESCAPED_UNICODE);
    }
    if ($duration !== null) {
        $sets[] = 'duration_seconds = ?';
        $params[] = $duration;
    }

    $params[] = $callSid;
    $sql = "UPDATE om_outbound_calls SET " . implode(', ', $sets) . " WHERE twilio_call_sid = ?";
    $db->prepare($sql)->execute($params);

    // Update campaign stats if linked
    if ($outcome === 'opt_out') {
        $db->prepare("
            UPDATE om_outbound_campaigns SET calls_opted_out = calls_opted_out + 1
            WHERE id = (SELECT campaign_id FROM om_outbound_calls WHERE twilio_call_sid = ? AND campaign_id IS NOT NULL)
        ")->execute([$callSid]);
    } elseif ($outcome === 'ordered') {
        $db->prepare("
            UPDATE om_outbound_campaigns SET calls_ordered = calls_ordered + 1
            WHERE id = (SELECT campaign_id FROM om_outbound_calls WHERE twilio_call_sid = ? AND campaign_id IS NOT NULL)
        ")->execute([$callSid]);
    }

    if ($status === 'answered') {
        $db->prepare("
            UPDATE om_outbound_campaigns SET calls_answered = calls_answered + 1
            WHERE id = (SELECT campaign_id FROM om_outbound_calls WHERE twilio_call_sid = ? AND campaign_id IS NOT NULL)
        ")->execute([$callSid]);
    }

    if (in_array($status, ['completed', 'no_answer', 'busy', 'failed', 'voicemail'], true)) {
        $db->prepare("
            UPDATE om_outbound_campaigns SET calls_made = calls_made + 1
            WHERE id = (SELECT campaign_id FROM om_outbound_calls WHERE twilio_call_sid = ? AND campaign_id IS NOT NULL)
        ")->execute([$callSid]);
    }
}

// ─── Helper: Lookup Customer by Phone ────────────────────────────────────────

/**
 * Look up a customer by phone number.
 * Returns ['customer_id' => int, 'name' => string] or empty array.
 */
function lookupCustomerByPhone(PDO $db, string $phone): array
{
    $digits = preg_replace('/\D/', '', $phone);

    // Try multiple formats
    $variants = [$digits];
    if (strlen($digits) === 13 && str_starts_with($digits, '55')) {
        $variants[] = substr($digits, 2); // Without country code
    }
    if (strlen($digits) === 11) {
        $variants[] = '55' . $digits; // With country code
    }

    foreach ($variants as $v) {
        $stmt = $db->prepare("
            SELECT customer_id, name, phone
            FROM om_market_customers
            WHERE phone LIKE ?
            LIMIT 1
        ");
        $stmt->execute(['%' . $v]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'customer_id' => (int)$row['customer_id'],
                'name'        => $row['name'],
                'phone'       => $row['phone'],
            ];
        }
    }

    return [];
}

/**
 * Get call-hours window.
 * Returns true if current time is within acceptable calling hours (9h-21h São Paulo).
 * Use this to avoid calling at inappropriate times.
 */
function isWithinCallingHours(): bool
{
    $hour = (int)date('H'); // Already São Paulo via date_default_timezone_set
    return $hour >= 9 && $hour < 21;
}
