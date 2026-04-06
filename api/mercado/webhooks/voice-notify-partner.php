<?php
/**
 * POST /api/mercado/webhooks/voice-notify-partner.php
 * Internal endpoint — notifies partner about new voice AI order
 * Sends push notification (Expo) + in-app notification
 * Protected by X-Internal-Key header
 */

if (file_exists(__DIR__ . '/../../../.env')) {
    $envFile = file(__DIR__ . '/../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envFile as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim(trim($value), '"\'');
        }
    }
}

header('Content-Type: application/json');

if (($_SERVER['HTTP_X_INTERNAL_KEY'] ?? '') !== 'superbora-voice-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../helpers/notify.php';
require_once __DIR__ . '/../config/database.php';

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: $_POST;

$partnerId = (int)($input['partner_id'] ?? 0);
$orderNumber = strip_tags($input['order_number'] ?? '');
$customerName = strip_tags($input['customer_name'] ?? 'Cliente');
$total = (float)($input['total'] ?? 0);

if (!$partnerId || !$orderNumber) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing partner_id or order_number']);
    exit;
}

$db = getDB();
$totalStr = number_format($total, 2, ',', '.');

// Notify partner via push (Expo) + in-app notification
$sent = notifyPartner(
    $db,
    $partnerId,
    'Novo pedido por telefone!',
    "Pedido #{$orderNumber} - R$ {$totalStr} - {$customerName} (via IA)",
    '/painel/mercado/pedidos.php',
    ['order_number' => $orderNumber, 'source' => 'voice_ai']
);

error_log("[voice-notify-partner] partner={$partnerId} order={$orderNumber} total={$total} sent={$sent}");
echo json_encode(['success' => true, 'notifications_sent' => $sent]);
