<?php
/**
 * ============================================================================
 * CRON: physical-card-tracking.php (daily at 8 AM)
 * ============================================================================
 * Schedule: 0 8 * * *
 *
 * For every physical card order currently in status 'shipped' or 'in_transit',
 * query the Correios tracking API and update the local status accordingly.
 *
 * When CORREIOS_API_KEY is not configured, the cron runs in MOCK mode:
 *   - After 1 day shipped  -> transitions to 'in_transit'
 *   - After 4 days shipped -> transitions to 'delivered' (and flips card
 *     virtual=false, marking the physical card as active).
 *
 * Each status transition:
 *   1. Persists the new status + last_tracking_event JSON
 *   2. Logs an entry in om_credit_card_events
 *   3. Sends a push notification to the customer
 *
 * Safe to run multiple times a day: status transitions are idempotent because
 * we only update rows whose current state differs from the computed state.
 * ============================================================================
 */

ini_set('memory_limit', '256M');

$secret = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
if (empty($secret)) { http_response_code(503); die('no cron secret'); }
if (php_sapi_name() !== 'cli' && (!isset($_SERVER['HTTP_X_CRON_KEY']) || !hash_equals($secret, $_SERVER['HTTP_X_CRON_KEY']))) {
    http_response_code(403); die('denied');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/customer/card/_common.php';
require_once dirname(__DIR__) . '/helpers/notify.php';

$lockFile = sys_get_temp_dir() . '/superbora_physical_card_tracking.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) exit(0);

function pll(string $m): void { fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] [pcard] ' . $m . "\n"); }

$db = getDB();

// Ensure table
$db->exec("
    CREATE TABLE IF NOT EXISTS om_physical_card_orders (
        id SERIAL PRIMARY KEY,
        card_id INTEGER NOT NULL,
        customer_id INTEGER NOT NULL,
        shipping_address JSONB NOT NULL,
        tracking_code VARCHAR(50),
        carrier VARCHAR(50) DEFAULT 'correios',
        status VARCHAR(30) DEFAULT 'requested',
        fee_amount NUMERIC(12,2) DEFAULT 0,
        delivery_notes TEXT,
        last_tracking_event JSONB,
        requested_at TIMESTAMP DEFAULT NOW(),
        produced_at TIMESTAMP,
        shipped_at TIMESTAMP,
        delivered_at TIMESTAMP,
        returned_at TIMESTAMP,
        updated_at TIMESTAMP DEFAULT NOW()
    )
");
$db->exec("ALTER TABLE om_physical_card_orders ADD COLUMN IF NOT EXISTS last_tracking_event JSONB");
$db->exec("ALTER TABLE om_physical_card_orders ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW()");

$correiosKey = $_ENV['CORREIOS_API_KEY']  ?? getenv('CORREIOS_API_KEY')  ?: '';
$correiosUrl = $_ENV['CORREIOS_API_URL']  ?? getenv('CORREIOS_API_URL')  ?: 'https://api.correios.com.br/srorastro/v1/objetos';
$mockMode = empty($correiosKey);
pll('mode: ' . ($mockMode ? 'MOCK (no CORREIOS_API_KEY)' : 'PRODUCTION'));

// Pull every order still in transit
$stmt = $db->query("
    SELECT id, customer_id, card_id, status, tracking_code, shipped_at, carrier
    FROM om_physical_card_orders
    WHERE status IN ('shipped', 'in_transit', 'producing')
      AND carrier = 'correios'
    ORDER BY requested_at ASC
    LIMIT 500
");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

pll('found ' . count($orders) . ' orders to process');

$transitioned = 0;
$errors = 0;

foreach ($orders as $o) {
    try {
        $event = $mockMode
            ? computeMockEvent($o)
            : fetchCorreiosEvent($correiosKey, $correiosUrl, (string)$o['tracking_code']);

        if (!$event) continue;

        $newStatus = $event['mapped_status'];
        if (!$newStatus || $newStatus === $o['status']) continue;

        $db->beginTransaction();

        $cols = ['status = ?', 'last_tracking_event = ?::jsonb', 'updated_at = NOW()'];
        $vals = [$newStatus, json_encode($event, JSON_UNESCAPED_UNICODE)];

        if ($newStatus === 'delivered') { $cols[] = 'delivered_at = NOW()'; }
        if ($newStatus === 'in_transit' && empty($o['shipped_at'])) {
            $cols[] = 'shipped_at = NOW()';
        }

        $vals[] = (int)$o['id'];
        $sql = 'UPDATE om_physical_card_orders SET ' . implode(', ', $cols) . ' WHERE id = ?';
        $db->prepare($sql)->execute($vals);

        // On delivered, convert card to physical
        if ($newStatus === 'delivered') {
            $db->prepare("UPDATE om_credit_cards SET virtual = false WHERE id = ?")
               ->execute([(int)$o['card_id']]);
        }

        // Audit
        logCardEvent($db, (int)$o['card_id'], (int)$o['customer_id'], 'physical_tracking_' . $newStatus, 'system', null, [
            'order_id' => (int)$o['id'],
            'tracking_code' => $o['tracking_code'],
            'event' => $event,
        ], $event['description'] ?? null);

        $db->commit();

        // Notify customer
        try {
            $body = trackingNotifyBody($newStatus, $event);
            if ($body) {
                notifyCustomer($db, (int)$o['customer_id'],
                    'Cartao fisico: ' . trackingStatusLabel($newStatus),
                    $body,
                    '/cartao',
                    ['physical_order_id' => (int)$o['id'], 'status' => $newStatus, 'deep_link' => '/cartao']
                );
            }
        } catch (Exception $e) {
            pll('notify error order=' . $o['id'] . ': ' . $e->getMessage());
        }

        $transitioned++;
        pll("order={$o['id']} tracking={$o['tracking_code']} {$o['status']} -> {$newStatus}");
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $errors++;
        pll("error order={$o['id']}: " . $e->getMessage());
    }
}

pll("done: transitioned={$transitioned} errors={$errors}");

// --------------------------------------------------------------------
// Helpers
// --------------------------------------------------------------------

/**
 * Deterministic mock: transitions based on elapsed time since shipped_at
 */
function computeMockEvent(array $order): ?array {
    $shippedAt = $order['shipped_at'] ?? null;
    $status = $order['status'];

    // producing -> auto shipped after 2 days (mock)
    if ($status === 'producing') {
        return null; // producing stays until admin manually marks shipped
    }

    if (!$shippedAt) return null;

    $days = (time() - strtotime($shippedAt)) / 86400;
    if ($days >= 4 && $status !== 'delivered') {
        return [
            'source'        => 'mock',
            'mapped_status' => 'delivered',
            'description'   => 'Objeto entregue ao destinatario (mock)',
            'city'          => 'Destino',
            'occurred_at'   => date('c'),
        ];
    }
    if ($days >= 1 && $status === 'shipped') {
        return [
            'source'        => 'mock',
            'mapped_status' => 'in_transit',
            'description'   => 'Objeto em transito - por favor aguarde (mock)',
            'city'          => 'Centro de distribuicao',
            'occurred_at'   => date('c'),
        ];
    }
    return null;
}

/**
 * Real Correios call. Expected response shape (API changed several times — we
 * defensively map a few common keys):
 *   { "objetos": [ { "eventos": [ { "descricao": "...", "unidade": {...}, "dtHrCriado": "..." } ] } ] }
 */
function fetchCorreiosEvent(string $apiKey, string $apiUrl, string $tracking): ?array {
    if (!$tracking) return null;
    $url = rtrim($apiUrl, '/') . '/' . urlencode($tracking);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status >= 400) return null;
    $parsed = json_decode((string)$body, true);
    if (!is_array($parsed)) return null;
    $eventos = $parsed['objetos'][0]['eventos'] ?? [];
    if (!is_array($eventos) || empty($eventos)) return null;
    $last = $eventos[0];
    $desc = strtolower((string)($last['descricao'] ?? ''));

    $mapped = null;
    if (str_contains($desc, 'entregue'))                                    $mapped = 'delivered';
    elseif (str_contains($desc, 'devolucao') || str_contains($desc, 'devolvido')) $mapped = 'returned';
    elseif (str_contains($desc, 'em transito') || str_contains($desc, 'transferencia') || str_contains($desc, 'em rota')) $mapped = 'in_transit';
    elseif (str_contains($desc, 'postado')  || str_contains($desc, 'coletado') || str_contains($desc, 'aceito')) $mapped = 'shipped';

    return [
        'source'        => 'correios',
        'mapped_status' => $mapped,
        'description'   => $last['descricao'] ?? '',
        'city'          => ($last['unidade']['endereco']['cidade'] ?? '') . '/' . ($last['unidade']['endereco']['uf'] ?? ''),
        'occurred_at'   => $last['dtHrCriado'] ?? date('c'),
    ];
}

function trackingStatusLabel(string $s): string {
    static $map = [
        'requested'  => 'Pedido recebido',
        'producing'  => 'Em producao',
        'shipped'    => 'Postado',
        'in_transit' => 'Em transito',
        'delivered'  => 'Entregue',
        'returned'   => 'Retornado',
    ];
    return $map[$s] ?? ucfirst($s);
}

function trackingNotifyBody(string $status, array $event): string {
    switch ($status) {
        case 'shipped':    return 'Seu cartao foi postado! ' . ($event['description'] ?? '');
        case 'in_transit': return 'Seu cartao esta em transito. ' . ($event['city'] ? 'Local: ' . $event['city'] : '');
        case 'delivered':  return 'Seu cartao fisico chegou! Ative no app.';
        case 'returned':   return 'Seu cartao retornou ao remetente. Entre em contato com o suporte.';
        default:           return '';
    }
}
