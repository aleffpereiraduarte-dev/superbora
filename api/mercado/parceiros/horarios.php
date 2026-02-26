<?php
/**
 * GET /api/mercado/parceiros/horarios.php?partner_id=123
 * Retorna horarios de funcionamento e status aberto/fechado
 *
 * POST — Parceiro atualiza seus horarios
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

function isOpenNow(array $partner): array {
    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $dayMap = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sab'];
    $today = $dayMap[(int)$now->format('w')];
    $currentTime = $now->format('H:i');

    // Tentar weekly_hours JSON primeiro
    $weeklyHours = null;
    if (!empty($partner['weekly_hours'])) {
        $weeklyHours = json_decode($partner['weekly_hours'], true);
    }
    if (!empty($partner['horario_funcionamento'])) {
        $parsed = json_decode($partner['horario_funcionamento'], true);
        if ($parsed) $weeklyHours = $parsed;
    }

    if ($weeklyHours && isset($weeklyHours[$today])) {
        $dayHours = $weeklyHours[$today];
        if ($dayHours === null || $dayHours === false) {
            return ['is_open' => false, 'message' => 'Fechado hoje', 'next_open' => findNextOpen($weeklyHours, $dayMap, (int)$now->format('w'))];
        }
        $opens = $dayHours['abre'] ?? $dayHours['opens'] ?? '00:00';
        $closes = $dayHours['fecha'] ?? $dayHours['closes'] ?? '23:59';

        if ($currentTime >= $opens && $currentTime <= $closes) {
            return ['is_open' => true, 'message' => "Aberto ate {$closes}", 'closes_at' => $closes];
        } else if ($currentTime < $opens) {
            return ['is_open' => false, 'message' => "Abre as {$opens}", 'opens_at' => $opens];
        } else {
            return ['is_open' => false, 'message' => 'Fechado hoje', 'next_open' => findNextOpen($weeklyHours, $dayMap, (int)$now->format('w'))];
        }
    }

    // Fallback: usar opens_at / closes_at ou horario_abre / horario_fecha
    $opens = $partner['opens_at'] ?? $partner['horario_abre'] ?? $partner['open_time'] ?? null;
    $closes = $partner['closes_at'] ?? $partner['horario_fecha'] ?? $partner['close_time'] ?? null;

    if ($opens && $closes) {
        $opensStr = substr($opens, 0, 5);
        $closesStr = substr($closes, 0, 5);

        // Domingo: verificar horario especial
        if ($today === 'dom' && !empty($partner['open_sunday'])) {
            $opensStr = substr($partner['sunday_opens_at'] ?? $opens, 0, 5);
            $closesStr = substr($partner['sunday_closes_at'] ?? $closes, 0, 5);
        } else if ($today === 'dom' && empty($partner['open_sunday'])) {
            return ['is_open' => false, 'message' => 'Fechado aos domingos'];
        }

        if ($currentTime >= $opensStr && $currentTime <= $closesStr) {
            return ['is_open' => true, 'message' => "Aberto ate {$closesStr}", 'closes_at' => $closesStr];
        } else if ($currentTime < $opensStr) {
            return ['is_open' => false, 'message' => "Abre as {$opensStr}", 'opens_at' => $opensStr];
        }
        return ['is_open' => false, 'message' => 'Fechado agora'];
    }

    // Sem horario definido = assume aberto
    return ['is_open' => true, 'message' => 'Horario nao definido'];
}

function findNextOpen(array $weeklyHours, array $dayMap, int $currentDayIndex): ?string {
    for ($i = 1; $i <= 7; $i++) {
        $nextIdx = ($currentDayIndex + $i) % 7;
        $dayKey = $dayMap[$nextIdx];
        if (isset($weeklyHours[$dayKey]) && $weeklyHours[$dayKey] !== null && $weeklyHours[$dayKey] !== false) {
            $opens = $weeklyHours[$dayKey]['abre'] ?? $weeklyHours[$dayKey]['opens'] ?? null;
            if ($opens) {
                $dayNames = ['Domingo', 'Segunda', 'Terca', 'Quarta', 'Quinta', 'Sexta', 'Sabado'];
                return $dayNames[$nextIdx] . " as {$opens}";
            }
        }
    }
    return null;
}

// Only run request handlers when accessed directly (not when included)
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'horarios.php') return;

// Ensure CORS headers are set for all request methods
setCorsHeaders();

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $partnerId = (int)($_GET['partner_id'] ?? 0);
        if (!$partnerId) response(false, null, 'partner_id obrigatorio', 400);

        $stmt = $db->prepare("SELECT partner_id, name, trade_name, opens_at, closes_at,
            open_sunday, sunday_opens_at, sunday_closes_at,
            horario_abre, horario_fecha, open_time, close_time,
            weekly_hours, horario_funcionamento, is_open,
            delivery_time_min, delivery_time_max, min_order_value, min_order,
            free_delivery_above, busy_mode, busy_mode_until, current_prep_time, default_prep_time
            FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $partner = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$partner) response(false, null, 'Loja nao encontrada', 404);

        // Verificar busy_mode
        $busyMode = false;
        if ($partner['busy_mode'] && $partner['busy_mode_until']) {
            $busyUntil = new DateTime($partner['busy_mode_until']);
            $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
            $busyMode = $now < $busyUntil;
        }

        $openStatus = isOpenNow($partner);

        // Se is_open = 0 forcado pelo parceiro, respeitar
        if (isset($partner['is_open']) && $partner['is_open'] === 0) {
            $openStatus['is_open'] = false;
            $openStatus['message'] = 'Loja temporariamente fechada';
        }

        // Parse weekly hours
        $schedule = null;
        if (!empty($partner['weekly_hours'])) {
            $schedule = json_decode($partner['weekly_hours'], true);
        }
        if (!$schedule && !empty($partner['horario_funcionamento'])) {
            $schedule = json_decode($partner['horario_funcionamento'], true);
        }

        $minOrder = (float)($partner['min_order_value'] ?: $partner['min_order'] ?: 0);
        $deliveryTimeMin = (int)($partner['delivery_time_min'] ?: $partner['current_prep_time'] ?: $partner['default_prep_time'] ?: 30);
        $deliveryTimeMax = (int)($partner['delivery_time_max'] ?: $deliveryTimeMin + 15);

        response(true, [
            'partner_id' => (int)$partner['partner_id'],
            'is_open' => $openStatus['is_open'],
            'status_message' => $openStatus['message'],
            'closes_at' => $openStatus['closes_at'] ?? null,
            'opens_at' => $openStatus['opens_at'] ?? null,
            'next_open' => $openStatus['next_open'] ?? null,
            'busy_mode' => $busyMode,
            'delivery_time_min' => $deliveryTimeMin,
            'delivery_time_max' => $deliveryTimeMax,
            'delivery_time_label' => "{$deliveryTimeMin}-{$deliveryTimeMax} min",
            'min_order' => $minOrder,
            'free_delivery_above' => (float)($partner['free_delivery_above'] ?: 0),
            'schedule' => $schedule,
        ]);
    }

    // POST: parceiro atualiza horarios (requires partner auth)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // SECURITY: Require partner authentication to update hours
        OmAuth::getInstance()->setDb($db);
        $payload = om_auth()->requirePartner();
        $authPartnerId = (int)$payload['uid'];

        $input = json_decode(file_get_contents('php://input'), true);
        $partnerId = (int)($input['partner_id'] ?? 0);
        $schedule = $input['schedule'] ?? null; // {seg:{abre:"08:00",fecha:"22:00"}, ...}

        if (!$schedule) response(false, null, 'schedule obrigatorio', 400);

        // SECURITY: Partner can only update their own hours
        // If partner_id is provided, it must match the authenticated partner
        if ($partnerId && $partnerId !== $authPartnerId) {
            response(false, null, 'Voce so pode alterar seus proprios horarios', 403);
        }
        $partnerId = $authPartnerId;

        $db->prepare("UPDATE om_market_partners SET weekly_hours = ?, horario_funcionamento = ?, date_modified = NOW() WHERE partner_id = ?")
            ->execute([json_encode($schedule), json_encode($schedule), $partnerId]);

        response(true, null, 'Horarios atualizados');
    }

} catch (Exception $e) {
    error_log("[Horarios] " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
