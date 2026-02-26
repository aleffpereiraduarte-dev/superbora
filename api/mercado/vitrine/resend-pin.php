<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/vitrine/resend-pin.php
 * Reenvia PIN de entrega para o cliente via SMS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Body:
 * {
 *   "order_id": 123
 * }
 *
 * SECURITY:
 * - Requires JWT authentication
 * - Verifies order ownership via authenticated customer_id
 * - Rate limited: 3 resends per order per 10 minutes
 * - Global rate limit: 5 requests per minute per IP
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";

// Rate limiting global: 5 requests por minuto (stricter — SMS costs money)
if (!RateLimiter::check(5, 60)) {
    exit;
}

try {
    $db = getDB();

    // ── AUTH REQUIRED ────────────────────────────────────────
    $customer_id = requireCustomerAuth();

    $input = getInput();

    $order_id = (int)($input["order_id"] ?? 0);

    if (!$order_id) {
        response(false, null, "order_id e obrigatorio", 400);
    }

    // Buscar pedido
    $stmt = $db->prepare("
        SELECT order_id, order_number, customer_id, customer_phone,
               delivery_pin, status, is_pickup
        FROM om_market_orders
        WHERE order_id = ?
    ");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        response(false, null, "Pedido nao encontrado", 404);
    }

    // Verify ownership via authenticated customer_id
    if ((int)$pedido['customer_id'] !== $customer_id) {
        response(false, null, "Acesso negado a este pedido", 403);
    }

    // Verificar se e pedido de entrega (nao retirada)
    if ($pedido['is_pickup']) {
        response(false, null, "Este pedido e para retirada, nao possui PIN de entrega", 400);
    }

    // Verificar se tem PIN
    if (empty($pedido['delivery_pin'])) {
        response(false, null, "PIN nao disponivel para este pedido", 400);
    }

    // Verificar se pedido esta em status valido
    $validStatuses = ['pendente', 'confirmed', 'aceito', 'preparando', 'shopping', 'coletando',
                      'purchased', 'coleta_finalizada', 'pronto', 'ready_for_delivery',
                      'aguardando_entregador', 'delivering', 'out_for_delivery', 'em_entrega'];
    if (!in_array($pedido['status'], $validStatuses)) {
        response(false, null, "PIN nao pode ser reenviado. Status: " . $pedido['status'], 400);
    }

    // Verificar se tem telefone
    if (empty($pedido['customer_phone'])) {
        response(false, null, "Telefone do cliente nao cadastrado", 400);
    }

    // Rate limit especifico: max 3 reenvios por pedido a cada 10 minutos
    // Use DB-backed rate limiting (APCu may not be available)
    $cacheKey = "pin_resend_{$order_id}";
    $resendCount = 0;

    if (function_exists('apcu_fetch')) {
        $resendCount = (int)(apcu_fetch($cacheKey) ?: 0);
    } else {
        // Fallback: count recent resends from a lightweight check
        try {
            $stmtRate = $db->prepare("
                SELECT COUNT(*) FROM om_rate_limits
                WHERE key_name = ? AND created_at > NOW() - INTERVAL '10 minutes'
            ");
            $stmtRate->execute([$cacheKey]);
            $resendCount = (int)$stmtRate->fetchColumn();
        } catch (Exception $e) {
            // Table om_rate_limits created via migration
            $resendCount = 0;
        }
    }

    if ($resendCount >= 3) {
        response(false, null, "Limite de reenvio atingido. Aguarde alguns minutos.", 429);
    }

    // Enviar SMS
    try {
        require_once __DIR__ . '/../helpers/twilio-sms.php';

        $pin = $pedido['delivery_pin'];
        $orderNum = $pedido['order_number'];
        $phone = $pedido['customer_phone'];

        $smsMsg = "SuperBora: Seu codigo de entrega e $pin. " .
                  "Informe este codigo ao entregador para confirmar o recebimento. " .
                  "Pedido #$orderNum";

        $result = sendSMS($phone, $smsMsg);

        if (!$result['success']) {
            response(false, null, "Erro ao enviar SMS: " . ($result['message'] ?? 'Falha desconhecida'), 500);
        }

        // Incrementar contador de reenvio
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $resendCount + 1, 600); // 10 minutos
        } else {
            try {
                $db->prepare("INSERT INTO om_rate_limits (key_name, created_at) VALUES (?, NOW())")->execute([$cacheKey]);
            } catch (Exception $e) { /* ignore */ }
        }

        response(true, [
            "order_id" => $order_id,
            "sent_to" => substr($phone, 0, 3) . '****' . substr($phone, -4),
            "attempts_remaining" => 2 - $resendCount
        ], "PIN reenviado com sucesso via SMS");

    } catch (Exception $e) {
        error_log("[resend-pin] Erro SMS: " . $e->getMessage());
        response(false, null, "Erro ao enviar SMS", 500);
    }

} catch (Exception $e) {
    error_log("[resend-pin] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar solicitacao", 500);
}
