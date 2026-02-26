<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * Push Notification Dispatcher - Status Change Hook
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * POST /api/notifications/status-change.php
 *
 * Chamado internamente quando o status de um pedido muda.
 * Determina quem notificar e envia push notifications via send.php.
 *
 * Body: {
 *   "order_id": 123,
 *   "old_status": "pendente",
 *   "new_status": "aceito",
 *   "actor_type": "shopper",
 *   "actor_id": 1
 * }
 *
 * Requer: X-API-Key header ou requisicao de localhost
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Apenas POST permitido
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Carregar dependencias
require_once dirname(__DIR__) . '/mercado/config/database.php';

// ══════════════════════════════════════════════════════════════════════════════
// AUTORIZACAO INTERNA
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Verifica se a requisicao eh interna (localhost ou API key valida).
 */
function isInternalRequest(): bool {
    // Verificar API key
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $expectedKey = $_ENV['INTERNAL_API_KEY'] ?? $_ENV['API_KEY'] ?? '';

    if (!empty($expectedKey) && !empty($apiKey) && hash_equals($expectedKey, $apiKey)) {
        return true;
    }

    // Verificar se vem de localhost
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $localhostIps = ['127.0.0.1', '::1'];

    if (in_array($remoteIp, $localhostIps)) {
        return true;
    }

    // Verificar se vem de rede interna (Docker, etc.)
    if (strpos($remoteIp, '172.') === 0 || strpos($remoteIp, '10.') === 0 || strpos($remoteIp, '192.168.') === 0) {
        return true;
    }

    return false;
}

if (!isInternalRequest()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Internal use only.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// PROCESSAR REQUISICAO
// ══════════════════════════════════════════════════════════════════════════════

try {
    $db = getDB();
    $input = getInput();

    // Validar campos obrigatorios
    $order_id   = (int)($input['order_id'] ?? 0);
    $old_status = trim($input['old_status'] ?? '');
    $new_status = trim($input['new_status'] ?? '');
    $actor_type = trim($input['actor_type'] ?? 'system');
    $actor_id   = (int)($input['actor_id'] ?? 0);

    if (!$order_id || !$new_status) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'order_id and new_status are required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════
    // BUSCAR DADOS DO PEDIDO
    // ══════════════════════════════════════════════════════════════════════

    $stmt = $db->prepare("
        SELECT o.order_id, o.customer_id, o.shopper_id, o.delivery_id,
               o.status, o.sacolas_total,
               s.name as shopper_name, s.phone as shopper_phone,
               p.name as partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Dados auxiliares para montar as mensagens
    $shopper = [
        'name'  => $order['shopper_name'] ?? 'Shopper',
        'phone' => $order['shopper_phone'] ?? '',
    ];
    $partner = [
        'name' => $order['partner_name'] ?? 'mercado',
    ];

    // ══════════════════════════════════════════════════════════════════════
    // DETERMINAR NOTIFICACOES A ENVIAR
    // ══════════════════════════════════════════════════════════════════════

    $notifications = [];

    switch ($new_status) {
        case 'aceito':
            // Notificar cliente: shopper aceitou o pedido
            $notifications[] = [
                'target'      => 'customer',
                'customer_id' => $order['customer_id'],
                'title'       => 'Shopper encontrado!',
                'body'        => "{$shopper['name']} vai fazer suas compras no {$partner['name']}",
                'icon'        => '/mercado/shopper/icons/icon-192.png',
            ];
            break;

        case 'coletando':
            // Notificar cliente: compras iniciadas
            $notifications[] = [
                'target'      => 'customer',
                'customer_id' => $order['customer_id'],
                'title'       => 'Compras iniciadas!',
                'body'        => 'Seu shopper comecou a coletar os produtos',
            ];
            break;

        case 'coleta_finalizada':
        case 'purchased':
            // Notificar cliente: compras prontas, buscando entregador
            $notifications[] = [
                'target'      => 'customer',
                'customer_id' => $order['customer_id'],
                'title'       => 'Compras prontas!',
                'body'        => 'Seus produtos foram coletados. Buscando entregador...',
            ];
            break;

        case 'aguardando_motorista':
            // Notificar motoristas proximos sobre nova entrega
            $notifications[] = [
                'target'    => 'drivers',
                'user_type' => 'motorista',
                'title'     => 'Nova entrega disponivel!',
                'body'      => "Entrega do {$partner['name']} - " . ((int)($order['sacolas_total'] ?? 0)) . " sacolas",
                'data'      => ['order_id' => $order_id],
            ];
            break;

        case 'em_entrega':
        case 'out_for_delivery':
            // Notificar cliente: pedido saiu para entrega
            $notifications[] = [
                'target'      => 'customer',
                'customer_id' => $order['customer_id'],
                'title'       => 'Pedido a caminho!',
                'body'        => 'Seu pedido saiu para entrega',
            ];
            break;

        case 'delivered':
            // Notificar cliente: pedido entregue
            $notifications[] = [
                'target'      => 'customer',
                'customer_id' => $order['customer_id'],
                'title'       => 'Pedido entregue!',
                'body'        => 'Seu pedido foi entregue. Bom apetite!',
            ];
            // Notificar shopper: entrega confirmada, ganhos adicionados
            if ($order['shopper_id']) {
                $notifications[] = [
                    'target'      => 'shopper',
                    'customer_id' => $order['shopper_id'],
                    'user_type'   => 'shopper',
                    'title'       => 'Entrega confirmada!',
                    'body'        => "Pedido #{$order_id} entregue. Ganhos adicionados ao saldo.",
                ];
            }
            break;

        case 'cancelado':
            // Notificar cliente: pedido cancelado
            $notifications[] = [
                'target'      => 'customer',
                'customer_id' => $order['customer_id'],
                'title'       => 'Pedido cancelado',
                'body'        => "Seu pedido #{$order_id} foi cancelado",
            ];
            // Notificar shopper se havia um designado
            if ($order['shopper_id']) {
                $notifications[] = [
                    'target'      => 'shopper',
                    'customer_id' => $order['shopper_id'],
                    'user_type'   => 'shopper',
                    'title'       => 'Pedido cancelado',
                    'body'        => "Pedido #{$order_id} foi cancelado pelo cliente",
                ];
            }
            break;

        default:
            // Status desconhecido - logar mas nao falhar
            error_log("[StatusChange] Unknown status '{$new_status}' for order {$order_id}");
            break;
    }

    // ══════════════════════════════════════════════════════════════════════
    // ENVIAR NOTIFICACOES VIA send.php
    // ══════════════════════════════════════════════════════════════════════

    $sentCount = 0;
    $failedCount = 0;
    $results = [];

    foreach ($notifications as $notif) {
        $pushPayload = [
            'title'       => $notif['title'],
            'body'        => $notif['body'],
            'icon'        => $notif['icon'] ?? '/assets/images/icon-192.png',
            'customer_id' => $notif['customer_id'] ?? null,
            'user_type'   => $notif['user_type'] ?? $notif['target'],
            'topic'       => 'orders',
            'data'        => array_merge($notif['data'] ?? [], [
                'order_id' => $order_id,
                'status'   => $new_status,
                'url'      => '/mercado/shopper/',
            ]),
        ];

        // Chamar send.php internamente via cURL
        $sendUrl = 'http://localhost/api/notifications/send.php';
        $ch = curl_init($sendUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($pushPayload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $pushResult = json_decode($response, true);
        $success = ($httpCode >= 200 && $httpCode < 300) && ($pushResult['success'] ?? false);

        if ($success) {
            $sentCount++;
        } else {
            $failedCount++;
            error_log("[StatusChange] Push failed for order {$order_id}, target={$notif['target']}: HTTP {$httpCode}, curl_error={$curlError}, response={$response}");
        }

        $results[] = [
            'target'    => $notif['target'],
            'title'     => $notif['title'],
            'success'   => $success,
            'http_code' => $httpCode,
            'sent'      => $pushResult['sent'] ?? 0,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // REGISTRAR LOG DE MUDANCA DE STATUS
    // ══════════════════════════════════════════════════════════════════════

    // Garantir que a tabela de log existe
    $db->exec("CREATE TABLE IF NOT EXISTS om_order_status_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        old_status VARCHAR(30),
        new_status VARCHAR(30),
        actor_type VARCHAR(20),
        actor_id INT,
        notifications_sent INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_order (order_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $logStmt = $db->prepare("
        INSERT INTO om_order_status_log (order_id, old_status, new_status, actor_type, actor_id, notifications_sent)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $logStmt->execute([
        $order_id,
        $old_status,
        $new_status,
        $actor_type,
        $actor_id,
        $sentCount,
    ]);

    $logId = $db->lastInsertId();

    // ══════════════════════════════════════════════════════════════════════
    // RESPOSTA
    // ══════════════════════════════════════════════════════════════════════

    echo json_encode([
        'success'              => true,
        'message'              => "Status change processed: {$old_status} -> {$new_status}",
        'order_id'             => $order_id,
        'notifications_sent'   => $sentCount,
        'notifications_failed' => $failedCount,
        'notifications_total'  => count($notifications),
        'log_id'               => (int)$logId,
        'details'              => $results,
        'timestamp'            => date('c'),
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log("[StatusChange] Database error for order " . ($order_id ?? 'unknown') . ": " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error. Please try again.',
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("[StatusChange] Error for order " . ($order_id ?? 'unknown') . ": " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error. Please try again.',
    ], JSON_UNESCAPED_UNICODE);
}
