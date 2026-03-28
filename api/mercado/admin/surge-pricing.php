<?php
/**
 * GET/POST /api/mercado/admin/surge-pricing.php
 * Dynamic Pricing (Surge) Management
 *
 * GET: Returns current surge status, auto rules, and real-time demand
 * POST: Create/update/delete surge rules, or set manual override
 *
 * POST actions:
 *   create_rule: Create a new auto rule
 *   update_rule: Update an existing rule
 *   delete_rule: Delete a rule
 *   toggle_rule: Toggle rule active/inactive
 *   manual_override: Set a manual surge multiplier for X hours
 *   clear_override: Remove manual override
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload['uid'];

    $method = $_SERVER['REQUEST_METHOD'];

    // Ensure table exists
    ensureSurgeTable($db);

    if ($method === 'GET') {
        handleGet($db);
    } elseif ($method === 'POST') {
        handlePost($db, $admin_id);
    } else {
        response(false, null, 'Metodo nao permitido', 405);
    }

} catch (PDOException $e) {
    error_log("[admin/surge-pricing] DB error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
} catch (Exception $e) {
    if (in_array(http_response_code(), [401, 403], true)) {
        exit;
    }
    error_log("[admin/surge-pricing] Error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}

function ensureSurgeTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_surge_rules (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            hours_start TIME,
            hours_end TIME,
            days VARCHAR(50) DEFAULT 'all',
            condition VARCHAR(50),
            multiplier NUMERIC(3,2) NOT NULL DEFAULT 1.00,
            active BOOLEAN DEFAULT true,
            created_by INTEGER,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");
}

function handleGet(PDO $db): void {
    // 1. Get all auto rules
    $stmt = $db->query("SELECT * FROM om_surge_rules ORDER BY hours_start ASC NULLS LAST, id ASC");
    $rules = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rules[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'hours_start' => $row['hours_start'] ? substr($row['hours_start'], 0, 5) : null,
            'hours_end' => $row['hours_end'] ? substr($row['hours_end'], 0, 5) : null,
            'days' => $row['days'] ?? 'all',
            'condition' => $row['condition'],
            'multiplier' => (float)$row['multiplier'],
            'active' => (bool)$row['active'],
            'created_at' => $row['created_at'],
        ];
    }

    // 2. Calculate current surge
    $currentSurge = calculateCurrentSurge($db, $rules);

    // 3. Get manual override from om_config
    $manualOverride = getManualOverride($db);
    if ($manualOverride && $manualOverride['active']) {
        $currentSurge = [
            'active' => true,
            'multiplier' => $manualOverride['multiplier'],
            'reason' => 'Override manual: ' . ($manualOverride['reason'] ?? 'Admin'),
            'expires_at' => $manualOverride['expires_at'],
            'is_manual' => true,
        ];
    }

    // 4. Real-time demand data
    $demand = getDemandData($db);

    response(true, [
        'current_surge' => $currentSurge,
        'auto_rules' => $rules,
        'demand_now' => $demand,
        'manual_override' => $manualOverride,
    ]);
}

function handlePost(PDO $db, int $admin_id): void {
    $input = getInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'create_rule':
            createRule($db, $input, $admin_id);
            break;
        case 'update_rule':
            updateRule($db, $input, $admin_id);
            break;
        case 'delete_rule':
            deleteRule($db, $input, $admin_id);
            break;
        case 'toggle_rule':
            toggleRule($db, $input, $admin_id);
            break;
        case 'manual_override':
            setManualOverride($db, $input, $admin_id);
            break;
        case 'clear_override':
            clearManualOverride($db, $admin_id);
            break;
        default:
            response(false, null, 'Acao invalida', 400);
    }
}

function calculateCurrentSurge(PDO $db, array $rules): array {
    $now = date('H:i');
    $dayOfWeek = strtolower(date('D')); // mon, tue, wed, thu, fri, sat, sun
    $isWeekend = in_array($dayOfWeek, ['sat', 'sun']);

    $bestMatch = null;
    $bestMultiplier = 1.0;

    foreach ($rules as $rule) {
        if (!$rule['active']) continue;

        // Check time range
        if ($rule['hours_start'] && $rule['hours_end']) {
            if ($now < $rule['hours_start'] || $now > $rule['hours_end']) {
                continue;
            }
        }

        // Check days
        $days = $rule['days'] ?? 'all';
        if ($days !== 'all') {
            $dayList = array_map('trim', explode('-', $days));
            // Handle ranges like "mon-fri" or "sat-sun"
            if (count($dayList) === 2) {
                $dayOrder = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
                $start = $dayOrder[$dayList[0]] ?? 0;
                $end = $dayOrder[$dayList[1]] ?? 0;
                $current = $dayOrder[$dayOfWeek] ?? 0;
                if ($current < $start || $current > $end) {
                    continue;
                }
            } else {
                // Comma-separated list
                $dayList = array_map('trim', explode(',', $days));
                if (!in_array($dayOfWeek, $dayList)) {
                    continue;
                }
            }
        }

        // Check conditions
        if ($rule['condition']) {
            // Weather conditions would require external API - skip for now
            // Could be expanded with weather_rain, weather_storm, etc.
            continue;
        }

        // Highest multiplier wins
        if ($rule['multiplier'] > $bestMultiplier) {
            $bestMultiplier = $rule['multiplier'];
            $bestMatch = $rule;
        }
    }

    if ($bestMatch) {
        $hoursLabel = '';
        if ($bestMatch['hours_start'] && $bestMatch['hours_end']) {
            $hoursLabel = " ({$bestMatch['hours_start']}-{$bestMatch['hours_end']})";
        }
        return [
            'active' => true,
            'multiplier' => $bestMultiplier,
            'reason' => $bestMatch['name'] . $hoursLabel,
            'expires_at' => $bestMatch['hours_end'] ? date('Y-m-d') . 'T' . $bestMatch['hours_end'] . ':00' : null,
            'rule_id' => $bestMatch['id'],
            'is_manual' => false,
        ];
    }

    return [
        'active' => false,
        'multiplier' => 1.0,
        'reason' => null,
        'expires_at' => null,
        'is_manual' => false,
    ];
}

function getManualOverride(PDO $db): ?array {
    try {
        $stmt = $db->query("SELECT valor FROM om_config WHERE chave = 'surge_manual_override' LIMIT 1");
        $row = $stmt->fetch();
        if (!$row) return null;

        $data = json_decode($row['valor'], true);
        if (!$data) return null;

        // Check if expired
        if (isset($data['expires_at']) && strtotime($data['expires_at']) < time()) {
            // Clean up expired override
            $db->exec("DELETE FROM om_config WHERE chave = 'surge_manual_override'");
            return null;
        }

        return [
            'active' => true,
            'multiplier' => (float)($data['multiplier'] ?? 1.0),
            'reason' => $data['reason'] ?? 'Override manual',
            'expires_at' => $data['expires_at'] ?? null,
            'set_by' => $data['set_by'] ?? null,
            'set_at' => $data['set_at'] ?? null,
        ];
    } catch (Exception $e) {
        return null;
    }
}

function getDemandData(PDO $db): array {
    // Pending orders (active, not yet delivered)
    $stmt = $db->query("
        SELECT COUNT(*) as cnt
        FROM om_market_orders
        WHERE status IN ('pendente', 'aceito', 'preparando', 'pronto', 'em_entrega')
        AND DATE(created_at) = CURRENT_DATE
    ");
    $pendingOrders = (int)$stmt->fetch()['cnt'];

    // Available drivers: distinct drivers who delivered today
    $stmt = $db->query("
        SELECT COUNT(DISTINCT motorista_nome) as cnt
        FROM om_entregas
        WHERE boraum_delivery_id IS NOT NULL
        AND motorista_nome IS NOT NULL
        AND DATE(created_at) = CURRENT_DATE
    ");
    $availableDrivers = (int)$stmt->fetch()['cnt'];

    // Ratio
    $ratio = $availableDrivers > 0
        ? round($pendingOrders / $availableDrivers, 3)
        : ($pendingOrders > 0 ? 99.0 : 0);

    // Suggest surge based on ratio
    $suggestedSurge = 1.0;
    if ($ratio > 3) $suggestedSurge = 1.5;
    elseif ($ratio > 2) $suggestedSurge = 1.3;
    elseif ($ratio > 1.5) $suggestedSurge = 1.2;
    elseif ($ratio > 1) $suggestedSurge = 1.1;

    // Orders last hour for trend
    $stmt = $db->query("
        SELECT COUNT(*) as cnt
        FROM om_market_orders
        WHERE created_at >= NOW() - INTERVAL '1 hour'
    ");
    $ordersLastHour = (int)$stmt->fetch()['cnt'];

    // Deliveries last hour
    $stmt = $db->query("
        SELECT COUNT(*) as cnt
        FROM om_entregas
        WHERE boraum_delivery_id IS NOT NULL
        AND created_at >= NOW() - INTERVAL '1 hour'
    ");
    $deliveriesLastHour = (int)$stmt->fetch()['cnt'];

    return [
        'pending_orders' => $pendingOrders,
        'available_drivers' => $availableDrivers,
        'ratio' => $ratio,
        'suggested_surge' => $suggestedSurge,
        'orders_last_hour' => $ordersLastHour,
        'deliveries_last_hour' => $deliveriesLastHour,
    ];
}

function createRule(PDO $db, array $input, int $admin_id): void {
    $name = trim($input['name'] ?? '');
    if (!$name) response(false, null, 'Nome obrigatorio', 400);

    $multiplier = (float)($input['multiplier'] ?? 1.0);
    if ($multiplier < 1.0 || $multiplier > 5.0) {
        response(false, null, 'Multiplicador deve ser entre 1.0 e 5.0', 400);
    }

    $hoursStart = $input['hours_start'] ?? null;
    $hoursEnd = $input['hours_end'] ?? null;
    $days = $input['days'] ?? 'all';
    $condition = $input['condition'] ?? null;

    // Validate time format
    if ($hoursStart && !preg_match('/^\d{2}:\d{2}$/', $hoursStart)) {
        response(false, null, 'Formato de hora invalido (use HH:MM)', 400);
    }
    if ($hoursEnd && !preg_match('/^\d{2}:\d{2}$/', $hoursEnd)) {
        response(false, null, 'Formato de hora invalido (use HH:MM)', 400);
    }

    $stmt = $db->prepare("
        INSERT INTO om_surge_rules (name, hours_start, hours_end, days, condition, multiplier, active, created_by, created_at, updated_at)
        VALUES (?, ?::time, ?::time, ?, ?, ?, true, ?, NOW(), NOW())
        RETURNING id
    ");
    $stmt->execute([$name, $hoursStart, $hoursEnd, $days, $condition, $multiplier, $admin_id]);
    $newId = (int)$stmt->fetch()['id'];

    om_audit()->log('create', 'surge_rule', $newId, null, $input, "Regra surge criada: {$name} x{$multiplier}");

    response(true, ['id' => $newId], 'Regra criada com sucesso');
}

function updateRule(PDO $db, array $input, int $admin_id): void {
    $id = (int)($input['id'] ?? 0);
    if (!$id) response(false, null, 'ID obrigatorio', 400);

    $name = trim($input['name'] ?? '');
    $multiplier = (float)($input['multiplier'] ?? 0);

    if ($name === '') response(false, null, 'Nome obrigatorio', 400);
    if ($multiplier < 1.0 || $multiplier > 5.0) {
        response(false, null, 'Multiplicador deve ser entre 1.0 e 5.0', 400);
    }

    $hoursStart = $input['hours_start'] ?? null;
    $hoursEnd = $input['hours_end'] ?? null;
    $days = $input['days'] ?? 'all';
    $condition = $input['condition'] ?? null;

    $stmt = $db->prepare("
        UPDATE om_surge_rules
        SET name = ?, hours_start = ?::time, hours_end = ?::time, days = ?, condition = ?,
            multiplier = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$name, $hoursStart, $hoursEnd, $days, $condition, $multiplier, $id]);

    if ($stmt->rowCount() === 0) {
        response(false, null, 'Regra nao encontrada', 404);
    }

    om_audit()->log('update', 'surge_rule', $id, null, $input, "Regra surge atualizada: {$name}");

    response(true, ['id' => $id], 'Regra atualizada');
}

function deleteRule(PDO $db, array $input, int $admin_id): void {
    $id = (int)($input['id'] ?? 0);
    if (!$id) response(false, null, 'ID obrigatorio', 400);

    $stmt = $db->prepare("DELETE FROM om_surge_rules WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        response(false, null, 'Regra nao encontrada', 404);
    }

    om_audit()->log('delete', 'surge_rule', $id, null, null, "Regra surge removida #{$id}");

    response(true, null, 'Regra removida');
}

function toggleRule(PDO $db, array $input, int $admin_id): void {
    $id = (int)($input['id'] ?? 0);
    if (!$id) response(false, null, 'ID obrigatorio', 400);

    $stmt = $db->prepare("
        UPDATE om_surge_rules
        SET active = NOT active, updated_at = NOW()
        WHERE id = ?
        RETURNING active
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        response(false, null, 'Regra nao encontrada', 404);
    }

    $newState = $row['active'] ? 'ativada' : 'desativada';
    om_audit()->log('update', 'surge_rule', $id, null, null, "Regra surge {$newState}");

    response(true, ['id' => $id, 'active' => (bool)$row['active']], "Regra {$newState}");
}

function setManualOverride(PDO $db, array $input, int $admin_id): void {
    $multiplier = (float)($input['multiplier'] ?? 0);
    if ($multiplier < 1.0 || $multiplier > 5.0) {
        response(false, null, 'Multiplicador deve ser entre 1.0 e 5.0', 400);
    }

    $hours = max(1, min(24, (int)($input['hours'] ?? 2)));
    $reason = trim($input['reason'] ?? 'Override manual');

    $expiresAt = date('Y-m-d\TH:i:s', time() + ($hours * 3600));

    $data = json_encode([
        'multiplier' => $multiplier,
        'reason' => $reason,
        'expires_at' => $expiresAt,
        'set_by' => $admin_id,
        'set_at' => date('Y-m-d\TH:i:s'),
    ]);

    // Upsert into om_config
    $stmt = $db->prepare("SELECT id FROM om_config WHERE chave = 'surge_manual_override'");
    $stmt->execute();
    if ($stmt->fetch()) {
        $stmt = $db->prepare("UPDATE om_config SET valor = ?, updated_by = ?, updated_at = NOW() WHERE chave = 'surge_manual_override'");
        $stmt->execute([$data, $admin_id]);
    } else {
        $stmt = $db->prepare("INSERT INTO om_config (chave, valor, updated_by, updated_at) VALUES ('surge_manual_override', ?, ?, NOW())");
        $stmt->execute([$data, $admin_id]);
    }

    om_audit()->log('create', 'surge_override', null, null, $input, "Surge manual: x{$multiplier} por {$hours}h - {$reason}");

    response(true, [
        'multiplier' => $multiplier,
        'expires_at' => $expiresAt,
        'reason' => $reason,
    ], 'Override manual ativado');
}

function clearManualOverride(PDO $db, int $admin_id): void {
    $db->exec("DELETE FROM om_config WHERE chave = 'surge_manual_override'");

    om_audit()->log('delete', 'surge_override', null, null, null, 'Surge manual removido');

    response(true, null, 'Override manual removido');
}
