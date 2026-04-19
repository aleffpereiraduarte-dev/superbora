<?php
/**
 * /admin/cards/physical-orders.php
 *
 * Admin management of physical card orders:
 *
 * GET                              - list orders with filters
 *   ?status=requested|producing|shipped|in_transit|delivered|returned|all
 *   ?search=nome|cpf|tracking
 *   ?page=1&per_page=25
 *
 * POST action=mark_producing       - mark order as producing (body: order_id)
 * POST action=mark_shipped         - mark shipped + set tracking_code (body: order_id, tracking_code)
 * POST action=mark_in_transit      - mark in transit (body: order_id)
 * POST action=mark_delivered       - mark delivered + convert card virtual=false (body: order_id)
 * POST action=mark_returned        - mark returned (body: order_id, reason)
 * POST action=update_tracking      - update only tracking_code (body: order_id, tracking_code)
 * POST action=shipping_label       - generate a print-ready HTML shipping label
 *
 * All state transitions are logged in om_credit_card_events for audit.
 */

require_once __DIR__ . "/../../customer/card/_common.php";
require_once __DIR__ . "/../../helpers/notify.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

function ensurePhysicalCardTableAdmin(PDO $db): void
{
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
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pcard_customer ON om_physical_card_orders(customer_id, requested_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pcard_status ON om_physical_card_orders(status)");
    $db->exec("ALTER TABLE om_physical_card_orders ADD COLUMN IF NOT EXISTS delivery_notes TEXT");
    $db->exec("ALTER TABLE om_physical_card_orders ADD COLUMN IF NOT EXISTS last_tracking_event JSONB");
    $db->exec("ALTER TABLE om_physical_card_orders ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW()");
}

function adminPhysicalStatusLabel(?string $s): string {
    static $map = [
        'requested'  => 'Pedido recebido',
        'producing'  => 'Em producao',
        'shipped'    => 'Postado',
        'in_transit' => 'Em transito',
        'delivered'  => 'Entregue',
        'returned'   => 'Retornado',
        'cancelled'  => 'Cancelado',
    ];
    return $map[$s ?? ''] ?? ucfirst((string)$s);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $adminPayload = om_auth()->requireAdmin();
    $adminId = (int)($adminPayload['uid'] ?? $adminPayload['user_id'] ?? 0);
    ensureCardTables($db);
    ensurePhysicalCardTableAdmin($db);

    $method = $_SERVER['REQUEST_METHOD'];

    // ---------------- GET: list ----------------
    if ($method === 'GET') {
        // Special sub-action: shipping label (GET with action=shipping_label&order_id=N)
        if (($_GET['action'] ?? '') === 'shipping_label') {
            renderShippingLabel($db, (int)($_GET['order_id'] ?? 0));
            return;
        }

        $status   = $_GET['status']   ?? 'all';
        $search   = trim((string)($_GET['search']   ?? ''));
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $perPage  = min(100, max(5, (int)($_GET['per_page'] ?? 25)));
        $offset   = ($page - 1) * $perPage;

        $validStatuses = ['requested', 'producing', 'shipped', 'in_transit', 'delivered', 'returned', 'cancelled', 'all'];
        if (!in_array($status, $validStatuses, true)) $status = 'all';

        $where = ['1=1'];
        $params = [];
        if ($status !== 'all') {
            $where[] = 'po.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(c.name ILIKE ? OR c.email ILIKE ? OR c.cpf ILIKE ? OR po.tracking_code ILIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);

        // Stats (unfiltered)
        $stats = $db->query("
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'requested')  AS pending,
                COUNT(*) FILTER (WHERE status = 'producing')  AS producing,
                COUNT(*) FILTER (WHERE status = 'shipped')    AS shipped,
                COUNT(*) FILTER (WHERE status = 'in_transit') AS in_transit,
                COUNT(*) FILTER (WHERE status = 'delivered')  AS delivered,
                COUNT(*) FILTER (WHERE status = 'returned')   AS returned,
                COALESCE(SUM(fee_amount), 0) AS total_fees
            FROM om_physical_card_orders
        ")->fetch(PDO::FETCH_ASSOC);

        // Count filtered
        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM om_physical_card_orders po
            LEFT JOIN om_customers c ON c.customer_id = po.customer_id
            WHERE {$whereSql}
        ");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch rows
        $sql = "
            SELECT po.*, c.name AS customer_name, c.email, c.phone, c.cpf,
                   cc.card_last4, cc.card_brand
            FROM om_physical_card_orders po
            LEFT JOIN om_customers c ON c.customer_id = po.customer_id
            LEFT JOIN om_credit_cards cc ON cc.id = po.card_id
            WHERE {$whereSql}
            ORDER BY po.requested_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $orders = array_map(function ($r) {
            $addr = $r['shipping_address'] ? (is_string($r['shipping_address']) ? json_decode($r['shipping_address'], true) : $r['shipping_address']) : null;
            $evt  = $r['last_tracking_event'] ? (is_string($r['last_tracking_event']) ? json_decode($r['last_tracking_event'], true) : $r['last_tracking_event']) : null;
            $cpf = preg_replace('/\D/', '', (string)($r['cpf'] ?? ''));
            $cpfMasked = strlen($cpf) === 11
                ? '***.***.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2)
                : null;
            return [
                'id'                  => (int)$r['id'],
                'card_id'             => (int)$r['card_id'],
                'customer_id'         => (int)$r['customer_id'],
                'customer_name'       => $r['customer_name'],
                'email'               => $r['email'],
                'phone'               => $r['phone'],
                'cpf_masked'          => $cpfMasked,
                'card_brand'          => $r['card_brand'],
                'card_last4'          => $r['card_last4'],
                'status'              => $r['status'],
                'status_label'        => adminPhysicalStatusLabel($r['status']),
                'fee_amount'          => (float)$r['fee_amount'],
                'carrier'             => $r['carrier'],
                'tracking_code'       => $r['tracking_code'],
                'shipping_address'    => $addr,
                'delivery_notes'      => $r['delivery_notes'],
                'last_tracking_event' => $evt,
                'requested_at'        => $r['requested_at'],
                'produced_at'         => $r['produced_at'],
                'shipped_at'          => $r['shipped_at'],
                'delivered_at'        => $r['delivered_at'],
                'returned_at'         => $r['returned_at'],
            ];
        }, $rows);

        response(true, [
            'stats' => [
                'total'      => (int)($stats['total']      ?? 0),
                'pending'    => (int)($stats['pending']    ?? 0),
                'producing'  => (int)($stats['producing']  ?? 0),
                'shipped'    => (int)($stats['shipped']    ?? 0),
                'in_transit' => (int)($stats['in_transit'] ?? 0),
                'delivered'  => (int)($stats['delivered']  ?? 0),
                'returned'   => (int)($stats['returned']   ?? 0),
                'total_fees' => (float)($stats['total_fees'] ?? 0),
            ],
            'orders'   => $orders,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    // ---------------- POST: actions ----------------
    if ($method !== 'POST') response(false, null, 'Metodo nao permitido', 405);

    $input = getInput();
    $action   = (string)($input['action']   ?? '');
    $orderId  = (int)($input['order_id']    ?? 0);
    $tracking = trim((string)($input['tracking_code'] ?? ''));
    $reason   = trim((string)($input['reason'] ?? ''));

    if ($orderId <= 0 && $action !== 'shipping_label') response(false, null, 'order_id obrigatorio', 400);

    $validActions = ['mark_producing', 'mark_shipped', 'mark_in_transit', 'mark_delivered', 'mark_returned', 'update_tracking', 'cancel', 'shipping_label'];
    if (!in_array($action, $validActions, true)) {
        response(false, null, 'Acao invalida', 400);
    }

    if ($action === 'shipping_label') {
        renderShippingLabel($db, $orderId);
        return;
    }

    $db->beginTransaction();

    $stmt = $db->prepare("SELECT * FROM om_physical_card_orders WHERE id = ? FOR UPDATE");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        $db->rollBack();
        response(false, null, 'Pedido nao encontrado', 404);
    }

    $customerId = (int)$order['customer_id'];
    $cardId     = (int)$order['card_id'];
    $now = date('Y-m-d H:i:s');

    $newStatus = null;
    $updates = ['updated_at' => $now];
    $payload = [];

    switch ($action) {
        case 'mark_producing':
            if (!in_array($order['status'], ['requested'], true)) {
                $db->rollBack();
                response(false, null, 'Transicao invalida: ' . $order['status'] . ' -> producing', 400);
            }
            $newStatus = 'producing';
            $updates['produced_at'] = $now;
            break;

        case 'mark_shipped':
            if (!in_array($order['status'], ['requested', 'producing'], true)) {
                $db->rollBack();
                response(false, null, 'Transicao invalida', 400);
            }
            if ($tracking === '' || strlen($tracking) < 6) {
                $db->rollBack();
                response(false, null, 'tracking_code obrigatorio', 400);
            }
            $newStatus = 'shipped';
            $updates['shipped_at']    = $now;
            $updates['tracking_code'] = $tracking;
            if (empty($order['produced_at'])) $updates['produced_at'] = $now;
            $payload['tracking_code'] = $tracking;
            break;

        case 'mark_in_transit':
            if (!in_array($order['status'], ['shipped', 'in_transit'], true)) {
                $db->rollBack();
                response(false, null, 'Transicao invalida', 400);
            }
            $newStatus = 'in_transit';
            break;

        case 'mark_delivered':
            if (!in_array($order['status'], ['shipped', 'in_transit'], true)) {
                $db->rollBack();
                response(false, null, 'Transicao invalida', 400);
            }
            $newStatus = 'delivered';
            $updates['delivered_at'] = $now;
            // Convert the card from virtual-only to physical
            $db->prepare("UPDATE om_credit_cards SET virtual = false WHERE id = ?")->execute([$cardId]);
            $payload['virtual_converted'] = true;
            break;

        case 'mark_returned':
            if ($order['status'] === 'delivered') {
                $db->rollBack();
                response(false, null, 'Pedido ja entregue, nao pode ser retornado', 400);
            }
            $newStatus = 'returned';
            $updates['returned_at'] = $now;
            $payload['reason'] = $reason ?: 'sem_motivo_informado';
            break;

        case 'update_tracking':
            if ($tracking === '') {
                $db->rollBack();
                response(false, null, 'tracking_code obrigatorio', 400);
            }
            $updates['tracking_code'] = $tracking;
            $payload['tracking_code'] = $tracking;
            break;

        case 'cancel':
            if (in_array($order['status'], ['delivered', 'returned'], true)) {
                $db->rollBack();
                response(false, null, 'Nao e possivel cancelar um pedido ja finalizado', 400);
            }
            $newStatus = 'cancelled';
            $payload['reason'] = $reason ?: 'admin_cancel';
            break;
    }

    if ($newStatus) $updates['status'] = $newStatus;

    $setParts = [];
    $bindValues = [];
    foreach ($updates as $col => $val) {
        $setParts[] = "{$col} = ?";
        $bindValues[] = $val;
    }
    $bindValues[] = $orderId;
    $sql = "UPDATE om_physical_card_orders SET " . implode(', ', $setParts) . " WHERE id = ?";
    $db->prepare($sql)->execute($bindValues);

    // Audit log
    logCardEvent($db, $cardId, $customerId, 'physical_' . $action, 'admin', $adminId, array_merge($payload, [
        'order_id'  => $orderId,
        'new_status'=> $newStatus ?: $order['status'],
    ]));

    $db->commit();

    // Best-effort notification
    try {
        $notifyTitle = 'Atualizacao do cartao fisico';
        $notifyBody  = '';
        switch ($newStatus) {
            case 'producing':  $notifyBody = 'Seu cartao esta em producao. Vamos te avisar quando ele for postado.'; break;
            case 'shipped':    $notifyBody = 'Seu cartao foi postado! Acompanhe: ' . ($tracking ?: 'codigo em breve'); break;
            case 'in_transit': $notifyBody = 'Seu cartao esta a caminho.'; break;
            case 'delivered':  $notifyBody = 'Seu cartao fisico foi entregue! Ative no app.'; break;
            case 'returned':   $notifyBody = 'Seu cartao retornou ao remetente. Entre em contato com o suporte.'; break;
            case 'cancelled':  $notifyBody = 'Seu pedido de cartao fisico foi cancelado.'; break;
        }
        if ($notifyBody !== '') {
            notifyCustomer($db, $customerId, $notifyTitle, $notifyBody, '/cartao', [
                'physical_order_id' => $orderId,
                'status'            => $newStatus,
                'deep_link'         => '/cartao',
            ]);
        }
    } catch (Exception $e) {
        error_log('[admin-physical-orders] notify failed: ' . $e->getMessage());
    }

    response(true, [
        'order_id' => $orderId,
        'status'   => $newStatus ?: $order['status'],
        'status_label' => adminPhysicalStatusLabel($newStatus ?: $order['status']),
    ]);
} catch (Exception $e) {
    if (!empty($db) && $db->inTransaction()) $db->rollBack();
    error_log('[admin-physical-orders] ' . $e->getMessage());
    response(false, null, 'Erro ao processar pedido fisico', 500);
}

// --------------------------------------------------------------------
// Shipping label (HTML/PDF-print endpoint)
// --------------------------------------------------------------------

function renderShippingLabel(PDO $db, int $orderId): void {
    if ($orderId <= 0) {
        http_response_code(400);
        echo 'order_id obrigatorio';
        exit;
    }
    $stmt = $db->prepare("
        SELECT po.*, c.name AS customer_name, c.phone, c.cpf
        FROM om_physical_card_orders po
        LEFT JOIN om_customers c ON c.customer_id = po.customer_id
        WHERE po.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        echo 'Pedido nao encontrado';
        exit;
    }
    $addr = $order['shipping_address'] ? (is_string($order['shipping_address']) ? json_decode($order['shipping_address'], true) : $order['shipping_address']) : [];
    $cep  = (string)($addr['zipcode'] ?? '');
    $cepFmt = strlen($cep) === 8 ? substr($cep, 0, 5) . '-' . substr($cep, 5) : $cep;

    $street = htmlspecialchars((string)($addr['street'] ?? ''));
    $number = htmlspecialchars((string)($addr['number'] ?? ''));
    $complement = htmlspecialchars((string)($addr['complement'] ?? ''));
    $neighborhood = htmlspecialchars((string)($addr['neighborhood'] ?? ''));
    $city = htmlspecialchars((string)($addr['city'] ?? ''));
    $state = htmlspecialchars((string)($addr['state'] ?? ''));
    $reference = htmlspecialchars((string)($addr['reference'] ?? ''));
    $customerName = htmlspecialchars((string)($order['customer_name'] ?? 'Cliente SuperBora'));
    $tracking = htmlspecialchars((string)($order['tracking_code'] ?? '(a preencher)'));
    $orderIdEsc = (int)$order['id'];

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html lang="pt-br"><head>
<meta charset="utf-8">
<title>Etiqueta de envio - Pedido #{$orderIdEsc}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Helvetica Neue', Arial, sans-serif; margin: 0; padding: 20px; background: #f3f4f6; }
  .label {
    width: 400px; background: #fff; padding: 24px; margin: 0 auto;
    border: 2px dashed #111; border-radius: 6px;
  }
  .brand { font-weight: 800; font-size: 18px; color: #047857; letter-spacing: 1px; }
  h1 { font-size: 14px; text-transform: uppercase; margin: 16px 0 4px; letter-spacing: 1px; color: #6b7280; }
  .field { font-size: 14px; margin-bottom: 2px; color: #111; }
  .addr { font-size: 15px; line-height: 1.5; color: #111; margin-top: 4px; }
  .cep { font-size: 20px; font-weight: 800; letter-spacing: 2px; margin-top: 6px; }
  .tracking { margin-top: 14px; padding: 10px; background: #111; color: #fff; text-align: center;
              font-family: 'Courier New', monospace; letter-spacing: 2px; border-radius: 4px; font-size: 14px; }
  .footer { margin-top: 16px; font-size: 10px; color: #6b7280; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
  @media print {
    body { background: #fff; padding: 0; }
    .label { border: 2px solid #000; }
    .no-print { display: none; }
  }
  .print-btn { display: block; margin: 14px auto; padding: 10px 18px; background: #047857; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font-weight: 700; }
</style>
</head><body>
  <button class="print-btn no-print" onclick="window.print()">Imprimir etiqueta</button>
  <div class="label">
    <div class="brand">SUPERBORA BANK</div>
    <h1>Destinatario</h1>
    <div class="field"><strong>{$customerName}</strong></div>
    <div class="addr">
      {$street}, {$number} {$complement}<br>
      {$neighborhood}<br>
      {$city} / {$state}
    </div>
    <div class="cep">CEP {$cepFmt}</div>
    <div class="field" style="font-size: 11px; color: #6b7280; margin-top: 8px;">Ref: {$reference}</div>

    <h1>Remetente</h1>
    <div class="addr">
      SuperBora Operacao LTDA<br>
      Av. Paulista, 1000<br>
      Bela Vista - Sao Paulo / SP<br>
      CEP 01310-100
    </div>

    <div class="tracking">{$tracking}</div>
    <div class="footer">Pedido interno #{$orderIdEsc} - Transportadora: Correios PAC</div>
  </div>
</body></html>
HTML;
    exit;
}
