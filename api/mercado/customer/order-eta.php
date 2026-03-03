<?php
/**
 * GET /api/mercado/customer/order-eta.php?order_id=X
 * Calcula ETA em tempo real do pedido com base no status atual,
 * historico do parceiro, e horario de pico.
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = requireCustomerAuth();
    $customerId = (int)$payload['uid'];

    $orderId = (int)($_GET['order_id'] ?? 0);
    if (!$orderId) response(false, null, "order_id obrigatorio", 400);

    // Get order with store info
    $stmt = $db->prepare("
        SELECT o.order_id, o.mercado_id, o.customer_id, o.status,
               o.created_at, o.updated_at,
               m.nome as store_name, m.prep_time_min, m.prep_time_max
        FROM om_market_orders o
        JOIN om_mercados m ON m.mercado_id = o.mercado_id
        WHERE o.order_id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$orderId, $customerId]);
    $order = $stmt->fetch();

    if (!$order) response(false, null, "Pedido nao encontrado", 404);

    $status = $order['status'];
    $createdAt = strtotime($order['created_at']);
    $updatedAt = strtotime($order['updated_at']);
    $now = time();

    // Final statuses — no ETA needed
    $finalStatuses = ['entregue', 'cancelled', 'refunded'];
    if (in_array($status, $finalStatuses)) {
        response(true, [
            'order_id' => $orderId,
            'status' => $status,
            'is_final' => true,
            'eta_minutes' => 0,
            'eta_range' => null,
            'message' => $status === 'entregue' ? 'Pedido entregue' : 'Pedido cancelado',
        ]);
    }

    // Get historical averages for this store
    $stmt = $db->prepare("
        SELECT
            AVG(EXTRACT(EPOCH FROM (updated_at - created_at)) / 60) as avg_total_minutes,
            PERCENTILE_CONT(0.8) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (updated_at - created_at)) / 60) as p80_minutes
        FROM om_market_orders
        WHERE mercado_id = ? AND status = 'entregue'
          AND created_at >= NOW() - INTERVAL '30 days'
    ");
    $stmt->execute([$order['mercado_id']]);
    $hist = $stmt->fetch();

    $avgTotalMinutes = (float)($hist['avg_total_minutes'] ?? 45);
    $p80Minutes = (float)($hist['p80_minutes'] ?? 60);

    // Base prep time from store config or historical data
    $basePrepMin = (int)($order['prep_time_min'] ?? 15);
    $basePrepMax = (int)($order['prep_time_max'] ?? 30);

    // Rush hour multiplier (lunch 11-14, dinner 18-21)
    $hour = (int)date('G');
    $rushMultiplier = 1.0;
    if (($hour >= 11 && $hour <= 13) || ($hour >= 18 && $hour <= 20)) {
        $rushMultiplier = 1.3;
    } elseif ($hour >= 14 && $hour <= 17) {
        $rushMultiplier = 0.9;
    }

    // Base delivery time (will be replaced with real data when available)
    $baseDeliveryMin = 10;
    $baseDeliveryMax = 25;

    // Check how many orders the store has right now
    $stmt = $db->prepare("
        SELECT COUNT(*) as pending_count
        FROM om_market_orders
        WHERE mercado_id = ? AND status IN ('pendente', 'aceito', 'preparando')
    ");
    $stmt->execute([$order['mercado_id']]);
    $queueCount = (int)$stmt->fetch()['pending_count'];

    // Queue delay: each order ahead adds ~3 minutes
    $queueDelay = max(0, ($queueCount - 1)) * 3;

    // Calculate ETA based on current status
    $elapsed = ($now - $createdAt) / 60; // minutes since order created
    $statusElapsed = ($now - $updatedAt) / 60; // minutes since last status change

    $etaMinMin = 0;
    $etaMinMax = 0;
    $phase = '';
    $progress = 0;

    switch ($status) {
        case 'pendente':
            // Waiting for store to accept
            $etaMinMin = round(($basePrepMin + $baseDeliveryMin + $queueDelay + 5) * $rushMultiplier);
            $etaMinMax = round(($basePrepMax + $baseDeliveryMax + $queueDelay + 10) * $rushMultiplier);
            $phase = 'Aguardando confirmacao';
            $progress = 5;
            break;

        case 'aceito':
            // Accepted, waiting for prep
            $etaMinMin = round(($basePrepMin + $baseDeliveryMin + $queueDelay) * $rushMultiplier);
            $etaMinMax = round(($basePrepMax + $baseDeliveryMax + $queueDelay) * $rushMultiplier);
            $phase = 'Pedido confirmado';
            $progress = 15;
            break;

        case 'preparando':
            // Being prepared
            $prepRemaining = max(0, $basePrepMin - $statusElapsed);
            $prepRemainingMax = max(0, $basePrepMax - $statusElapsed);
            $etaMinMin = round(($prepRemaining + $baseDeliveryMin) * $rushMultiplier);
            $etaMinMax = round(($prepRemainingMax + $baseDeliveryMax) * $rushMultiplier);
            $phase = 'Sendo preparado';
            $progress = 35 + min(30, ($statusElapsed / max(1, $basePrepMax)) * 30);
            break;

        case 'pronto':
            // Ready, waiting for pickup/delivery
            $etaMinMin = round($baseDeliveryMin * $rushMultiplier);
            $etaMinMax = round($baseDeliveryMax * $rushMultiplier);
            $phase = 'Pronto para retirada';
            $progress = 65;
            break;

        case 'collecting':
            // Shopper is picking up
            $etaMinMin = round(($baseDeliveryMin - 3) * $rushMultiplier);
            $etaMinMax = round($baseDeliveryMax * $rushMultiplier);
            $phase = 'Entregador buscando';
            $progress = 75;
            break;

        case 'in_transit':
            // En route
            $transitRemaining = max(2, $baseDeliveryMin - $statusElapsed);
            $transitRemainingMax = max(5, $baseDeliveryMax - $statusElapsed);
            $etaMinMin = round($transitRemaining);
            $etaMinMax = round($transitRemainingMax);
            $phase = 'A caminho';
            $progress = 85 + min(10, ($statusElapsed / max(1, $baseDeliveryMax)) * 10);
            break;

        default:
            $etaMinMin = 5;
            $etaMinMax = 30;
            $phase = 'Em andamento';
            $progress = 50;
    }

    // Ensure min <= max and both >= 0
    $etaMinMin = max(0, $etaMinMin);
    $etaMinMax = max($etaMinMin, $etaMinMax);
    $progress = min(95, max(0, round($progress)));

    // Confidence level
    $confidence = 'medium';
    if ($avgTotalMinutes > 0 && $queueCount < 5) {
        $confidence = 'high';
    } elseif ($queueCount > 10) {
        $confidence = 'low';
    }

    // Smart message
    $etaAvg = round(($etaMinMin + $etaMinMax) / 2);
    if ($etaAvg <= 5) {
        $message = 'Quase la! Chegando em instantes.';
    } elseif ($etaAvg <= 15) {
        $message = "Seu pedido chega em ~{$etaAvg} minutos.";
    } elseif ($etaAvg <= 30) {
        $message = "Previsao: {$etaMinMin}-{$etaMinMax} minutos.";
    } else {
        $message = "Estimativa: {$etaMinMin}-{$etaMinMax} min. Horario movimentado, pode demorar um pouco mais.";
    }

    response(true, [
        'order_id' => $orderId,
        'status' => $status,
        'is_final' => false,
        'phase' => $phase,
        'progress' => $progress,
        'eta_minutes' => $etaAvg,
        'eta_range' => [
            'min' => $etaMinMin,
            'max' => $etaMinMax,
        ],
        'confidence' => $confidence,
        'message' => $message,
        'queue_position' => $queueCount,
        'rush_hour' => $rushMultiplier > 1.0,
        'elapsed_minutes' => round($elapsed),
        'updated_at' => $order['updated_at'],
    ]);

} catch (Exception $e) {
    error_log("[customer/order-eta] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
