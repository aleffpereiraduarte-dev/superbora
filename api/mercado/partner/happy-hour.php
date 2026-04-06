<?php
/**
 * GET/POST/DELETE /api/mercado/partner/happy-hour.php
 * Happy hour pricing rules management
 *
 * GET: List all happy hour rules
 * POST: Create or update a rule
 * DELETE ?id=X: Delete a rule
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    $method = $_SERVER['REQUEST_METHOD'];

    // Ensure table exists
    ensureHappyHourTable($db);

    // ======================== GET: List rules ========================
    if ($method === 'GET') {
        $stmt = dbQuery($db, "
            SELECT id, name, day_of_week, start_time, end_time,
                   discount_type, discount_value, applies_to, target_ids,
                   active, created_at, updated_at
            FROM om_happy_hour_rules
            WHERE partner_id = ?
            ORDER BY created_at DESC
        ", [$partnerId]);
        $rows = $stmt->fetchAll();

        $rules = [];
        foreach ($rows as $row) {
            $rules[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'days' => json_decode($row['day_of_week'] ?? '[]', true) ?: [],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'discount_type' => $row['discount_type'],
                'discount_value' => round((float)$row['discount_value'], 2),
                'applies_to' => $row['applies_to'],
                'target_ids' => json_decode($row['target_ids'] ?? '[]', true) ?: [],
                'active' => (bool)$row['active'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }

        // Check if any rule is currently active
        $currentlyActive = null;
        $now = new DateTime();
        $currentDow = (int)$now->format('N'); // 1=Mon, 7=Sun
        $currentTime = $now->format('H:i:s');

        foreach ($rules as $rule) {
            if ($rule['active'] && in_array($currentDow, $rule['days'])) {
                if ($currentTime >= $rule['start_time'] && $currentTime <= $rule['end_time']) {
                    $currentlyActive = $rule;
                    break;
                }
            }
        }

        response(true, [
            'rules' => $rules,
            'currently_active' => $currentlyActive,
            'total' => count($rules),
        ], "Regras de happy hour carregadas");
    }

    // ======================== POST: Create/Update rule ========================
    if ($method === 'POST') {
        $input = getInput();

        $ruleId = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $days = $input['days'] ?? [];
        $startTime = trim($input['start_time'] ?? '');
        $endTime = trim($input['end_time'] ?? '');
        $discountType = trim($input['discount_type'] ?? '%');
        $discountValue = (float)($input['discount_value'] ?? 0);
        $appliesTo = trim($input['applies_to'] ?? 'all');
        $targetIds = $input['target_ids'] ?? [];
        $active = isset($input['active']) ? (bool)$input['active'] : true;

        // Validations
        if (empty($name)) {
            response(false, null, "Nome da regra obrigatorio", 400);
        }
        if (empty($days) || !is_array($days)) {
            response(false, null, "Selecione pelo menos um dia da semana", 400);
        }
        // Validate days are 1-7
        $days = array_filter(array_map('intval', $days), fn($d) => $d >= 1 && $d <= 7);
        if (empty($days)) {
            response(false, null, "Dias da semana invalidos", 400);
        }

        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) {
            response(false, null, "Horario de inicio invalido (HH:MM)", 400);
        }
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
            response(false, null, "Horario de termino invalido (HH:MM)", 400);
        }

        // Normalize time to HH:MM:SS
        if (strlen($startTime) === 5) $startTime .= ':00';
        if (strlen($endTime) === 5) $endTime .= ':00';

        if ($startTime >= $endTime) {
            response(false, null, "Horario de inicio deve ser anterior ao termino", 400);
        }

        if (!in_array($discountType, ['%', 'fixed'], true)) {
            $discountType = '%';
        }

        if ($discountValue <= 0) {
            response(false, null, "Valor do desconto deve ser maior que zero", 400);
        }
        if ($discountType === '%' && $discountValue > 100) {
            response(false, null, "Desconto percentual nao pode ser maior que 100%", 400);
        }

        if (!in_array($appliesTo, ['all', 'category', 'product'], true)) {
            $appliesTo = 'all';
        }

        if (!is_array($targetIds)) $targetIds = [];
        $targetIds = array_map('intval', $targetIds);

        // Limit rules per partner
        if ($ruleId === 0) {
            $stmtCount = dbQuery($db, "SELECT COUNT(*) FROM om_happy_hour_rules WHERE partner_id = ?", [$partnerId]);
            if ((int)$stmtCount->fetchColumn() >= 20) {
                response(false, null, "Limite de 20 regras de happy hour atingido", 400);
            }
        }

        $daysJson = json_encode(array_values($days));
        $targetIdsJson = json_encode(array_values($targetIds));

        if ($ruleId > 0) {
            // Update existing rule — verify ownership
            $stmtOwner = dbQuery($db, "SELECT id FROM om_happy_hour_rules WHERE id = ? AND partner_id = ?", [$ruleId, $partnerId]);
            if (!$stmtOwner->fetch()) {
                response(false, null, "Regra nao encontrada", 404);
            }

            dbQuery($db, "
                UPDATE om_happy_hour_rules SET
                    name = ?, day_of_week = ?, start_time = ?, end_time = ?,
                    discount_type = ?, discount_value = ?, applies_to = ?,
                    target_ids = ?, active = ?, updated_at = NOW()
                WHERE id = ? AND partner_id = ?
            ", [$name, $daysJson, $startTime, $endTime, $discountType, $discountValue, $appliesTo, $targetIdsJson, $active ? 1 : 0, $ruleId, $partnerId]);

            response(true, ['id' => $ruleId], "Regra atualizada com sucesso");
        }

        // Create new rule
        dbQuery($db, "
            INSERT INTO om_happy_hour_rules
                (partner_id, name, day_of_week, start_time, end_time, discount_type, discount_value, applies_to, target_ids, active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ", [$partnerId, $name, $daysJson, $startTime, $endTime, $discountType, $discountValue, $appliesTo, $targetIdsJson, $active ? 1 : 0]);

        $newId = (int)$db->lastInsertId();

        response(true, ['id' => $newId], "Regra criada com sucesso");
    }

    // ======================== DELETE: Remove rule ========================
    if ($method === 'DELETE') {
        $ruleId = (int)($_GET['id'] ?? 0);
        if ($ruleId <= 0) {
            response(false, null, "ID da regra obrigatorio", 400);
        }

        $stmt = dbQuery($db, "DELETE FROM om_happy_hour_rules WHERE id = ? AND partner_id = ?", [$ruleId, $partnerId]);

        if ($stmt->rowCount() === 0) {
            response(false, null, "Regra nao encontrada", 404);
        }

        response(true, ['id' => $ruleId], "Regra removida com sucesso");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/happy-hour] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

/**
 * Create om_happy_hour_rules table if not exists
 */
function ensureHappyHourTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_happy_hour_rules (
            id SERIAL PRIMARY KEY,
            partner_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            day_of_week JSONB NOT NULL DEFAULT '[]',
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            discount_type VARCHAR(10) NOT NULL DEFAULT '%',
            discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            applies_to VARCHAR(20) NOT NULL DEFAULT 'all',
            target_ids JSONB NOT NULL DEFAULT '[]',
            active BOOLEAN NOT NULL DEFAULT true,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");
    // Index for quick lookups by partner
    $db->exec("CREATE INDEX IF NOT EXISTS idx_happy_hour_partner ON om_happy_hour_rules (partner_id)");
}
