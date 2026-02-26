<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * GET /api/tracking/eta-ai.php?order_id=123
 * Calcula ETA inteligente para um pedido com base em multiplos fatores
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * REQUER: Autenticacao (shopper, customer, admin ou partner)
 * Header: Authorization: Bearer <token>
 *
 * Query: ?order_id=123
 *
 * SEGURANCA:
 * - Autenticacao obrigatoria via token
 * - Prepared statements (sem SQL injection)
 * - Validacao de input
 * - Erros internos nao expostos ao cliente
 */

require_once dirname(__DIR__) . '/mercado/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 2) . '/includes/classes/OmAudit.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // ═══════════════════════════════════════════════════════════════════
    // AUTENTICACAO - qualquer usuario autenticado
    // ═══════════════════════════════════════════════════════════════════
    $token = OmAuth::getInstance()->getTokenFromRequest();
    $payload = $token ? OmAuth::getInstance()->validateToken($token) : null;
    if (!$payload) {
        response(false, null, 'Token invalido', 401);
    }

    // ═══════════════════════════════════════════════════════════════════
    // VALIDACAO DE INPUT
    // ═══════════════════════════════════════════════════════════════════
    $order_id = (int)($_GET['order_id'] ?? 0);
    if (!$order_id) {
        response(false, null, 'order_id e obrigatorio', 400);
    }

    // ═══════════════════════════════════════════════════════════════════
    // BUSCAR PEDIDO COM DADOS DE TIMELINE
    // ═══════════════════════════════════════════════════════════════════
    $stmt = $db->prepare("
        SELECT o.*,
               p.name AS partner_name,
               p.address AS partner_address,
               p.latitude AS partner_lat,
               p.longitude AS partner_lng,
               s.latitude AS shopper_lat,
               s.longitude AS shopper_lng,
               s.nome AS shopper_nome
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        LEFT JOIN om_market_shoppers s ON o.shopper_id = s.shopper_id
        WHERE o.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        response(false, null, 'Pedido nao encontrado', 404);
    }

    // Verificar se pedido ja foi finalizado
    $statusFinais = ['delivered', 'entregue', 'cancelado', 'cancelled'];
    if (in_array($pedido['status'], $statusFinais)) {
        response(true, [
            'order_id'      => $order_id,
            'status'        => $pedido['status'],
            'eta_minutes'   => 0,
            'eta_range'     => ['min' => 0, 'max' => 0],
            'eta_timestamp' => null,
            'breakdown'     => [],
            'progress'      => 100,
            'confidence'    => 1.0,
            'factors'       => [],
            'ai_insight'    => 'Pedido ja finalizado'
        ], 'Pedido ja finalizado');
    }

    // ═══════════════════════════════════════════════════════════════════
    // CONTAR ITENS E PROGRESSO DE COLETA
    // ═══════════════════════════════════════════════════════════════════
    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_order_items WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $totalItems = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM om_market_order_items WHERE order_id = ? AND collected = 1");
    $stmt->execute([$order_id]);
    $collectedItems = (int)$stmt->fetchColumn();

    // ═══════════════════════════════════════════════════════════════════
    // BUSCAR MEDIAS HISTORICAS DO PARCEIRO
    // ═══════════════════════════════════════════════════════════════════
    $historicalAvgs = [
        'avg_travel_to_store' => null,
        'avg_shopping_time'   => null,
        'avg_delivery_time'   => null,
        'avg_total_time'      => null
    ];

    if ($pedido['partner_id']) {
        $stmt = $db->prepare("
            SELECT
                AVG(TIMESTAMPDIFF(MINUTE, accepted_at, started_collecting_at)) AS avg_travel_to_store,
                AVG(TIMESTAMPDIFF(MINUTE, started_collecting_at, finished_collecting_at)) AS avg_shopping_time,
                AVG(TIMESTAMPDIFF(MINUTE, started_delivery_at, delivered_at)) AS avg_delivery_time,
                AVG(TIMESTAMPDIFF(MINUTE, date_added, delivered_at)) AS avg_total_time
            FROM om_market_orders
            WHERE partner_id = ? AND status IN ('delivered', 'entregue')
            AND delivered_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$pedido['partner_id']]);
        $hist = $stmt->fetch();

        if ($hist) {
            $historicalAvgs = [
                'avg_travel_to_store' => $hist['avg_travel_to_store'] !== null ? round((float)$hist['avg_travel_to_store'], 1) : null,
                'avg_shopping_time'   => $hist['avg_shopping_time'] !== null ? round((float)$hist['avg_shopping_time'], 1) : null,
                'avg_delivery_time'   => $hist['avg_delivery_time'] !== null ? round((float)$hist['avg_delivery_time'], 1) : null,
                'avg_total_time'      => $hist['avg_total_time'] !== null ? round((float)$hist['avg_total_time'], 1) : null,
            ];
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // CALCULAR ETA POR STATUS
    // ═══════════════════════════════════════════════════════════════════
    $breakdown  = [];
    $factors    = [];
    $confidence = 0.7;
    $progress   = 0;

    $status = $pedido['status'];

    // Defaults (usados quando nao ha dados historicos)
    $defaultTravelToStore = 5;   // minutos
    $defaultPerItem       = 1.5; // minutos por item
    $defaultCheckout      = 5;   // minutos
    $defaultDriverWait    = 10;  // minutos
    $defaultDelivery      = 12;  // minutos
    $defaultFindShopper   = 5;   // minutos

    // Calcular tempos com base historica quando disponivel
    $travelToStore   = $historicalAvgs['avg_travel_to_store'] ?? $defaultTravelToStore;
    $perItemTime     = ($historicalAvgs['avg_shopping_time'] !== null && $totalItems > 0)
                       ? ($historicalAvgs['avg_shopping_time'] / $totalItems)
                       : $defaultPerItem;
    $deliveryTime    = $historicalAvgs['avg_delivery_time'] ?? $defaultDelivery;
    $checkoutTime    = $defaultCheckout;
    $driverWaitTime  = $defaultDriverWait;
    $findShopperTime = $defaultFindShopper;

    // Extrair coordenadas para calculo de distancia
    $deliveryLat = (float)($pedido['delivery_lat'] ?? 0);
    $deliveryLng = (float)($pedido['delivery_lng'] ?? 0);
    $shopperLat  = (float)($pedido['shopper_lat'] ?? 0);
    $shopperLng  = (float)($pedido['shopper_lng'] ?? 0);
    $partnerLat  = (float)($pedido['partner_lat'] ?? 0);
    $partnerLng  = (float)($pedido['partner_lng'] ?? 0);

    switch ($status) {
        case 'pendente':
            $shoppingTime = $totalItems * $perItemTime;
            $breakdown = [
                'find_shopper'     => round($findShopperTime),
                'travel_to_store'  => round($travelToStore),
                'shopping'         => round($shoppingTime),
                'checkout_handoff' => round($checkoutTime),
                'driver_wait'      => round($driverWaitTime),
                'delivery'         => round($deliveryTime)
            ];
            $progress   = 5;
            $confidence = 0.5;
            break;

        case 'aceito':
            $travelEst = $travelToStore;
            if ($shopperLat && $shopperLng && $partnerLat && $partnerLng) {
                $distToStore = haversineDistance($shopperLat, $shopperLng, $partnerLat, $partnerLng);
                $travelEst = ($distToStore / 30) * 60; // 30 km/h
                $travelEst = max(2, round($travelEst));
                $confidence += 0.1;
            }
            $shoppingTime = $totalItems * $perItemTime;
            $breakdown = [
                'travel_to_store'  => round($travelEst),
                'shopping'         => round($shoppingTime),
                'checkout_handoff' => round($checkoutTime),
                'driver_wait'      => round($driverWaitTime),
                'delivery'         => round($deliveryTime)
            ];
            $progress   = 15;
            $confidence = 0.6;
            break;

        case 'coletando':
            $itemsRemaining = max(0, $totalItems - $collectedItems);
            $shoppingRemaining = $itemsRemaining * $perItemTime;
            $breakdown = [
                'shopping_remaining' => round($shoppingRemaining),
                'checkout_handoff'   => round($checkoutTime),
                'driver_wait'        => round($driverWaitTime),
                'delivery'           => round($deliveryTime)
            ];
            $progress   = $totalItems > 0 ? round(20 + (($collectedItems / $totalItems) * 40)) : 30;
            $confidence = 0.75;
            break;

        case 'coleta_finalizada':
        case 'purchased':
            $breakdown = [
                'driver_wait' => round($driverWaitTime),
                'delivery'    => round($deliveryTime)
            ];
            $progress   = 65;
            $confidence = 0.8;
            break;

        case 'em_entrega':
        case 'out_for_delivery':
            $deliveryEst = $deliveryTime;
            if ($shopperLat && $shopperLng && $deliveryLat && $deliveryLng) {
                $distToCustomer = haversineDistance($shopperLat, $shopperLng, $deliveryLat, $deliveryLng);
                $deliveryEst = ($distToCustomer / 25) * 60; // 25 km/h na cidade
                $deliveryEst = max(2, round($deliveryEst));
                $confidence += 0.1;
            }
            $breakdown = [
                'delivery' => round($deliveryEst)
            ];
            $progress   = 85;
            $confidence = 0.85;
            break;

        default:
            $breakdown = [
                'estimated_total' => round($historicalAvgs['avg_total_time'] ?? 45)
            ];
            $progress   = 50;
            $confidence = 0.4;
            break;
    }

    // Somar ETA total
    $etaMinutes = array_sum($breakdown);
    $etaMinutes = max(1, $etaMinutes);

    // ═══════════════════════════════════════════════════════════════════
    // AJUSTES CONTEXTUAIS
    // ═══════════════════════════════════════════════════════════════════
    $adjustmentFactor = 1.0;
    $hour = (int)date('G');
    $dayOfWeek = (int)date('w'); // 0=domingo, 6=sabado

    // Horario de pico (almoco e jantar)
    if (($hour >= 11 && $hour <= 13) || ($hour >= 18 && $hour <= 20)) {
        $adjustmentFactor += 0.20;
        $factors[] = 'hora_pico';
    }

    // Fim de semana
    if ($dayOfWeek === 0 || $dayOfWeek === 6) {
        $adjustmentFactor += 0.10;
        $factors[] = 'fim_de_semana';
    }

    // Pedido grande (mais de 20 itens)
    if ($totalItems > 20) {
        $adjustmentFactor += 0.15;
        $factors[] = 'pedido_grande';
    }

    // Pedido pequeno (ate 5 itens) = mais rapido
    if ($totalItems <= 5 && $totalItems > 0) {
        $adjustmentFactor -= 0.10;
        $factors[] = 'pedido_pequeno';
    }

    // Noturno (apos 21h)
    if ($hour >= 21 || $hour < 7) {
        $adjustmentFactor += 0.15;
        $factors[] = 'horario_noturno';
    }

    // Aplicar ajuste
    $etaAdjusted = round($etaMinutes * $adjustmentFactor);
    $etaAdjusted = max(1, $etaAdjusted);

    // Range (margem de erro baseada na confianca)
    $marginFactor = 1.0 - $confidence;
    $etaMin = max(1, round($etaAdjusted * (1 - $marginFactor)));
    $etaMax = round($etaAdjusted * (1 + $marginFactor));

    // Timestamp estimado de chegada
    $etaTimestamp = date('c', strtotime("+{$etaAdjusted} minutes"));

    // Se temos dados historicos, confianca aumenta
    if ($historicalAvgs['avg_total_time'] !== null) {
        $confidence = min(0.95, $confidence + 0.1);
        $factors[] = 'dados_historicos';
    }

    $confidence = min(1.0, round($confidence, 2));

    // ═══════════════════════════════════════════════════════════════════
    // INSIGHT COM CLAUDE (OPCIONAL)
    // ═══════════════════════════════════════════════════════════════════
    $aiInsight = null;
    if (defined('CLAUDE_API_KEY') && !empty(CLAUDE_API_KEY) && count($factors) > 0) {
        $aiInsight = getClaudeInsight($pedido, $factors, $etaAdjusted, $totalItems, $collectedItems, $hour, $dayOfWeek);
    }

    // Fallback de insight quando Claude nao disponivel
    if (!$aiInsight && count($factors) > 0) {
        $aiInsight = buildFallbackInsight($factors, $adjustmentFactor);
    }

    // ═══════════════════════════════════════════════════════════════════
    // RESPOSTA
    // ═══════════════════════════════════════════════════════════════════
    response(true, [
        'order_id'      => $order_id,
        'status'        => $status,
        'eta_minutes'   => $etaAdjusted,
        'eta_range'     => [
            'min' => $etaMin,
            'max' => $etaMax
        ],
        'eta_timestamp' => $etaTimestamp,
        'breakdown'     => $breakdown,
        'progress'      => $progress,
        'confidence'    => $confidence,
        'factors'       => $factors,
        'ai_insight'    => $aiInsight
    ], 'ETA calculado com sucesso');

} catch (Exception $e) {
    error_log("[eta-ai] Erro: " . $e->getMessage());
    response(false, null, 'Erro ao calcular ETA. Tente novamente.', 500);
}

// ═══════════════════════════════════════════════════════════════════════════════
// FUNCOES AUXILIARES
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Calcula distancia entre dois pontos GPS usando formula de Haversine
 * @return float Distancia em kilometros
 */
function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthRadius = 6371; // km

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLng / 2) * sin($dLng / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/**
 * Chama Claude para gerar insight contextual sobre a ETA
 */
function getClaudeInsight(array $pedido, array $factors, int $eta, int $totalItems, int $collectedItems, int $hour, int $dayOfWeek): ?string {
    try {
        $diasSemana = ['domingo', 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'];
        $dia = $diasSemana[$dayOfWeek] ?? 'desconhecido';

        $factorLabels = [
            'hora_pico'        => 'horario de pico',
            'fim_de_semana'    => 'fim de semana',
            'pedido_grande'    => 'pedido com muitos itens (>20)',
            'pedido_pequeno'   => 'pedido pequeno (<=5 itens)',
            'horario_noturno'  => 'horario noturno',
            'dados_historicos' => 'dados historicos disponiveis'
        ];

        $factorsText = implode(', ', array_map(function($f) use ($factorLabels) {
            return $factorLabels[$f] ?? $f;
        }, $factors));

        $prompt = "Voce e um assistente de delivery. Gere uma frase curta (max 80 caracteres) em portugues brasileiro explicando o ETA de {$eta} minutos para um pedido de supermercado. "
                . "Fatores: {$factorsText}. "
                . "Status: {$pedido['status']}. Horario: {$hour}h, {$dia}. "
                . "Itens: {$totalItems} total, {$collectedItems} coletados. "
                . "Responda APENAS a frase, sem aspas, sem explicacao extra.";

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . CLAUDE_API_KEY,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'      => 'claude-3-haiku-20240307',
                'max_tokens' => 120,
                'messages'   => [['role' => 'user', 'content' => $prompt]]
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$result) {
            return null;
        }

        $data = json_decode($result, true);
        $text = $data['content'][0]['text'] ?? null;

        // Sanitizar resposta: remover aspas envolventes e limitar tamanho
        if ($text) {
            $text = trim($text, " \t\n\r\0\x0B\"'");
            $text = mb_substr($text, 0, 200);
        }

        return $text;

    } catch (Exception $e) {
        error_log("[eta-ai] Claude error: " . $e->getMessage());
        return null;
    }
}

/**
 * Gera insight de fallback quando Claude nao esta disponivel
 */
function buildFallbackInsight(array $factors, float $adjustmentFactor): string {
    $parts = [];

    if (in_array('hora_pico', $factors)) {
        $parts[] = 'horario de pico';
    }
    if (in_array('fim_de_semana', $factors)) {
        $parts[] = 'fim de semana';
    }
    if (in_array('pedido_grande', $factors)) {
        $parts[] = 'pedido com muitos itens';
    }
    if (in_array('horario_noturno', $factors)) {
        $parts[] = 'horario noturno';
    }

    if (empty($parts)) {
        return 'Estimativa com base nas condicoes atuais';
    }

    $adjustPct = round(($adjustmentFactor - 1.0) * 100);
    $direction = $adjustPct > 0 ? '+' : '';

    return "Estimativa ajustada {$direction}{$adjustPct}% por " . implode(' e ', $parts);
}
