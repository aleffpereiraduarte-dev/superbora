<?php
/**
 * API: Phone call proxy - returns contact info for direct tel: link
 * GET /mercado/api/entregador/ligar.php?order_id=X&caller_type=customer|driver
 *
 * Auth: Bearer token (customer or motorista)
 *
 * If caller is customer -> returns driver phone + name
 * If caller is driver   -> returns customer phone + name
 *
 * Also logs the call attempt in notifications.
 *
 * Returns: { success: true, phone: "+55...", name: "Nome" }
 */

// CORS and headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo nao permitido']);
    exit;
}

// Load config
$oc_root = dirname(dirname(dirname(__DIR__)));
require_once($oc_root . '/config.php');
require_once __DIR__ . '/config.php';

// Load auth classes
require_once dirname(__DIR__, 2) . '/../includes/classes/OmAuth.php';

$pdo = getDB();
OmAuth::getInstance()->setDb($pdo);

// ══════════════════════════════════════════════════════════════════════════════
// AUTHENTICATE (customer or motorista)
// ══════════════════════════════════════════════════════════════════════════════
$token = om_auth()->getTokenFromRequest();
if (!$token) {
    jsonResponse(['success' => false, 'error' => 'Autenticacao necessaria'], 401);
}

$payload = om_auth()->validateToken($token);
if (!$payload) {
    jsonResponse(['success' => false, 'error' => 'Token invalido ou expirado'], 401);
}

$auth_type = $payload['type']; // 'customer' or 'motorista'
$auth_uid  = (int)$payload['uid'];

// ══════════════════════════════════════════════════════════════════════════════
// INPUT VALIDATION
// ══════════════════════════════════════════════════════════════════════════════
$order_id    = (int)($_GET['order_id'] ?? 0);
$caller_type = $_GET['caller_type'] ?? '';

if (!$order_id) {
    jsonResponse(['success' => false, 'error' => 'order_id obrigatorio'], 400);
}

if (!in_array($caller_type, ['customer', 'driver'])) {
    jsonResponse(['success' => false, 'error' => 'caller_type deve ser "customer" ou "driver"'], 400);
}

// Validate caller_type matches auth type
if ($caller_type === 'customer' && $auth_type !== 'customer') {
    jsonResponse(['success' => false, 'error' => 'caller_type=customer requer autenticacao de cliente'], 403);
}
if ($caller_type === 'driver' && $auth_type !== 'motorista') {
    jsonResponse(['success' => false, 'error' => 'caller_type=driver requer autenticacao de motorista'], 403);
}

// ══════════════════════════════════════════════════════════════════════════════
// FETCH ORDER
// ══════════════════════════════════════════════════════════════════════════════
$stmt = $pdo->prepare("SELECT * FROM om_market_orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    jsonResponse(['success' => false, 'error' => 'Pedido nao encontrado'], 404);
}

// ══════════════════════════════════════════════════════════════════════════════
// AUTHORIZATION: Verify caller is part of this order
// ══════════════════════════════════════════════════════════════════════════════
if ($caller_type === 'customer') {
    // Verify this customer owns the order
    if ((int)($order['customer_id'] ?? 0) !== $auth_uid) {
        jsonResponse(['success' => false, 'error' => 'Este pedido nao pertence a voce'], 403);
    }
} else {
    // caller_type === 'driver'
    // Verify this driver is assigned to the order
    if ((int)($order['delivery_driver_id'] ?? 0) !== $auth_uid) {
        jsonResponse(['success' => false, 'error' => 'Voce nao e o entregador deste pedido'], 403);
    }
}

// Order must be in an active delivery status
$active_statuses = ['delivering', 'purchased', 'awaiting_delivery', 'pronto_coleta', 'collected'];
if (!in_array($order['status'], $active_statuses)) {
    jsonResponse(['success' => false, 'error' => 'Pedido nao esta em andamento'], 400);
}

// ══════════════════════════════════════════════════════════════════════════════
// RESOLVE CONTACT INFO
// ══════════════════════════════════════════════════════════════════════════════
$phone = '';
$name  = '';

if ($caller_type === 'customer') {
    // Customer wants to call the driver
    $driver_id = (int)($order['delivery_driver_id'] ?? 0);
    if (!$driver_id) {
        jsonResponse(['success' => false, 'error' => 'Nenhum entregador atribuido a este pedido'], 400);
    }

    // Fetch driver info from om_boraum_drivers
    $driver = validateDriver($driver_id);
    if (!$driver) {
        jsonResponse(['success' => false, 'error' => 'Entregador nao encontrado'], 404);
    }

    $phone = $driver['phone'] ?? $driver['whatsapp'] ?? '';
    $name  = $driver['name'] ?? 'Entregador';

} else {
    // Driver wants to call the customer
    $phone = $order['customer_phone'] ?? '';
    $name  = $order['customer_name'] ?? 'Cliente';

    // If phone not in order, try to fetch from customer profile
    if (empty($phone)) {
        $customer_id = (int)($order['customer_id'] ?? 0);
        if ($customer_id) {
            try {
                // Try om_market_customers table
                $cust_stmt = $pdo->prepare("SELECT phone, name FROM om_market_customers WHERE customer_id = ?");
                $cust_stmt->execute([$customer_id]);
                $customer = $cust_stmt->fetch();
                if ($customer) {
                    $phone = $customer['phone'] ?? '';
                    $name  = $customer['name'] ?? $name;
                }
            } catch (Exception $e) {
                // Try alternative table
                try {
                    $cust_stmt = $pdo->prepare("SELECT telephone, CONCAT(firstname, ' ', lastname) as name FROM oc_customer WHERE customer_id = ?");
                    $cust_stmt->execute([$customer_id]);
                    $customer = $cust_stmt->fetch();
                    if ($customer) {
                        $phone = $customer['telephone'] ?? '';
                        $name  = $customer['name'] ?? $name;
                    }
                } catch (Exception $e2) {}
            }
        }
    }
}

if (empty($phone)) {
    jsonResponse(['success' => false, 'error' => 'Telefone nao disponivel'], 404);
}

// ══════════════════════════════════════════════════════════════════════════════
// FORMAT PHONE NUMBER (Brazilian format)
// ══════════════════════════════════════════════════════════════════════════════
$phone_clean = preg_replace('/[^0-9]/', '', $phone);

// Ensure it has country code
if (strlen($phone_clean) === 10 || strlen($phone_clean) === 11) {
    $phone_clean = '55' . $phone_clean;
}

$phone_formatted = '+' . $phone_clean;

// ══════════════════════════════════════════════════════════════════════════════
// LOG CALL ATTEMPT
// ══════════════════════════════════════════════════════════════════════════════
try {
    // Log in notifications for audit trail
    $log_title = $caller_type === 'customer'
        ? 'Cliente ligou para entregador'
        : 'Entregador ligou para cliente';

    $log_body = $caller_type === 'customer'
        ? 'Pedido #' . $order_id . ': cliente tentou contato com entregador'
        : 'Pedido #' . $order_id . ': entregador tentou contato com cliente';

    $pdo->prepare("
        INSERT INTO om_market_notifications (user_id, user_type, title, body, data, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, 1, NOW())
    ")->execute([
        $auth_uid,
        $auth_type,
        $log_title,
        $log_body,
        json_encode([
            'type' => 'call_attempt',
            'order_id' => $order_id,
            'caller_type' => $caller_type,
            'caller_id' => $auth_uid,
            'called_phone' => substr($phone_formatted, 0, 8) . '****' // Masked for log
        ])
    ]);
} catch (Exception $e) {
    error_log("[ligar] Erro ao logar tentativa de chamada: " . $e->getMessage());
}

// Also log in order history
try {
    $pdo->prepare("
        INSERT INTO om_market_order_history (order_id, status, comment, created_at)
        VALUES (?, 'call_attempt', ?, NOW())
    ")->execute([
        $order_id,
        $log_title . ' - ' . $log_body
    ]);
} catch (Exception $e) {}

// ══════════════════════════════════════════════════════════════════════════════
// RESPONSE
// ══════════════════════════════════════════════════════════════════════════════
jsonResponse([
    'success' => true,
    'phone'   => $phone_formatted,
    'name'    => $name,
    'order_id' => $order_id,
    'caller_type' => $caller_type
]);
