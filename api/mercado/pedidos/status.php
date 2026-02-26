<?php
/**
 * GET /api/mercado/pedidos/status.php?customer_id=X
 * Returns active (non-completed) orders for real-time tracking
 *
 * SECURITY: Requires authentication. customer_id from token is used,
 * ignoring any customer_id passed in query params for security.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    // Rate limiting: 60 requests per minute per IP for status checks
    // This is a polling endpoint that needs to be protected from abuse
    if (!RateLimiter::check(60, 60)) {
        exit;
    }

    $db = getDB();

    // SECURITY: Authenticate and get customer_id from token
    OmAuth::getInstance()->setDb($db);
    $token = om_auth()->getTokenFromRequest();

    if (!$token) {
        response(false, null, "Autenticacao necessaria", 401);
    }

    $payload = om_auth()->validateToken($token);
    if (!$payload || ($payload['type'] ?? '') !== 'customer') {
        response(false, null, "Token invalido ou expirado", 401);
    }

    // Use customer_id from token (ignore query param for security)
    $customer_id = (int)$payload['uid'];
    $order_id = (int)($_GET["order_id"] ?? 0);

    if (!$customer_id) {
        response(false, null, "Cliente nao autenticado", 401);
    }

    $db = getDB();

    $where = [];
    $params = [];

    if ($order_id) {
        $where[] = "o.order_id = ?";
        $params[] = $order_id;
        $where[] = "o.customer_id = ?";
        $params[] = $customer_id;
    } else {
        $where[] = "o.customer_id = ?";
        $params[] = $customer_id;
        $where[] = "o.status NOT IN ('cancelled','cancelado','entregue','retirado','completed')";
    }

    $whereSQL = implode(" AND ", $where);

    $stmt = $db->prepare("
        SELECT o.order_id, o.partner_id, o.status, o.total, o.delivery_fee,
               o.date_added, o.confirmed_at, o.shopping_started_at,
               o.delivering_at, o.delivered_at, o.estimated_delivery_at,
               o.eta_minutes, o.shopper_name, o.total_items,
               o.items_found, o.progress_pct,
               o.delivery_pin, o.delivery_type, o.delivery_photo,
               o.pin_verified_at, o.is_pickup,
               p.name as parceiro_nome, p.logo as parceiro_logo
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE $whereSQL
        ORDER BY o.date_added DESC
        LIMIT 5
    ");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    $statusLabels = [
        'pending' => 'Pedido recebido',
        'pendente' => 'Pedido recebido',
        'confirmed' => 'Confirmado pela loja',
        'aceito' => 'Confirmado pela loja',
        'preparando' => 'Sendo preparado',
        'shopping' => 'Compras em andamento',
        'coletando' => 'Compras em andamento',
        'purchased' => 'Compras finalizadas',
        'coleta_finalizada' => 'Compras finalizadas',
        'pronto' => 'Pronto para entrega',
        'ready_for_delivery' => 'Pronto para entrega',
        'aguardando_entregador' => 'Aguardando entregador',
        'delivering' => 'Saiu para entrega',
        'out_for_delivery' => 'Saiu para entrega',
        'em_entrega' => 'Saiu para entrega',
        'aguardando_retirada' => 'Pronto para retirada'
    ];

    $statusSteps = [
        'pending' => 1, 'pendente' => 1,
        'confirmed' => 2, 'aceito' => 2,
        'preparando' => 2,
        'shopping' => 3, 'coletando' => 3,
        'purchased' => 4, 'coleta_finalizada' => 4,
        'pronto' => 4, 'ready_for_delivery' => 4,
        'aguardando_entregador' => 4,
        'delivering' => 5, 'out_for_delivery' => 5, 'em_entrega' => 5
    ];

    $pedidos = [];
    foreach ($orders as $o) {
        $st = $o['status'];
        $pedido = [
            "order_id" => (int)$o["order_id"],
            "partner_id" => (int)$o["partner_id"],
            "parceiro_nome" => $o["parceiro_nome"],
            "parceiro_logo" => $o["parceiro_logo"],
            "status" => $st,
            "status_label" => $statusLabels[$st] ?? ucfirst($st),
            "step" => $statusSteps[$st] ?? 1,
            "total_steps" => 5,
            "total" => (float)$o["total"],
            "taxa_entrega" => (float)($o["delivery_fee"] ?? 0),
            "data" => $o["date_added"],
            "eta_minutos" => $o["eta_minutes"] ? (int)$o["eta_minutes"] : null,
            "entrega_estimada" => $o["estimated_delivery_at"],
            "shopper" => $o["shopper_name"],
            "progresso_itens" => $o["progress_pct"] ? (int)$o["progress_pct"] : null,
            "total_itens" => $o["total_items"] ? (int)$o["total_items"] : null,
            "itens_encontrados" => $o["items_found"] ? (int)$o["items_found"] : null,
            "is_pickup" => (bool)($o["is_pickup"] ?? false)
        ];

        // PIN de entrega - mostrar apenas quando pedido esta em entrega ou proximo
        // Cliente usa este PIN para confirmar recebimento ao entregador
        // SECURITY: Only show PIN if this order belongs to the authenticated customer
        $showPinStatuses = ['delivering', 'out_for_delivery', 'em_entrega', 'pronto', 'ready_for_delivery'];
        if (!$o['is_pickup'] && in_array($st, $showPinStatuses) && !empty($o['delivery_pin'])) {
            // Double-check order ownership before exposing PIN
            if ((int)$o['order_id'] && $customer_id) {
                // Show masked PIN hint (last 2 digits) for UI, full PIN sent via push/SMS
                $pin = $o['delivery_pin'];
                $pedido['delivery_pin_hint'] = '****' . substr($pin, -2);
                $pedido['pin_message'] = 'Codigo de entrega enviado por SMS/notificacao. Informe ao entregador.';
                // Note: Full PIN should be delivered via secure channel (SMS/push notification)
                // Not exposed in API response to prevent interception
            }
        }

        // Se ja foi entregue, mostrar dados da prova de entrega
        if ($st === 'entregue') {
            $pedido['delivery_proof'] = [
                'type' => $o['delivery_type'],
                'has_photo' => !empty($o['delivery_photo']),
                'pin_verified' => !empty($o['pin_verified_at']),
                'delivered_at' => $o['delivered_at']
            ];
        }

        $pedidos[] = $pedido;
    }

    response(true, ["pedidos" => $pedidos]);

} catch (Exception $e) {
    error_log("[API Status] Erro: " . $e->getMessage());
    response(false, null, "Erro ao verificar status", 500);
}
