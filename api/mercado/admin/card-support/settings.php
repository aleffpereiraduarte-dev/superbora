<?php
/**
 * GET|PUT /admin/card-support/settings.php
 *
 * Card policy settings (stored in om_card_support_settings table, singleton row id=1).
 *
 * Fields:
 *   interest_rate_month      - %
 *   annual_fee               - R$
 *   min_payment_pct          - %
 *   grace_period_days        - days
 *   auto_approve_score       - min score for auto
 *   fraud_rule_high_amount   - R$ threshold
 *   fraud_rule_velocity      - tx count/hr
 *   webhook_url              - optional
 *   templates (JSON)         - message templates
 *
 * Response.data: { settings, history }
 */

require_once __DIR__ . "/_common.php";

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $ctx = bootstrapCardSupport();
    $db = $ctx['db'];
    $adminId = $ctx['admin_id'];

    $db->exec("
        CREATE TABLE IF NOT EXISTS om_card_support_settings (
            id INTEGER PRIMARY KEY DEFAULT 1,
            interest_rate_month NUMERIC(5,2) DEFAULT 9.9,
            annual_fee NUMERIC(10,2) DEFAULT 0,
            min_payment_pct NUMERIC(5,2) DEFAULT 15,
            grace_period_days INTEGER DEFAULT 10,
            auto_approve_score INTEGER DEFAULT 700,
            fraud_rule_high_amount NUMERIC(10,2) DEFAULT 2000,
            fraud_rule_velocity INTEGER DEFAULT 10,
            webhook_url TEXT,
            templates JSONB,
            updated_at TIMESTAMP DEFAULT NOW(),
            updated_by INTEGER,
            CONSTRAINT settings_singleton CHECK (id = 1)
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_card_support_settings_history (
            id SERIAL PRIMARY KEY,
            changed_by INTEGER,
            changed_at TIMESTAMP DEFAULT NOW(),
            field VARCHAR(60),
            old_value TEXT,
            new_value TEXT
        )
    ");
    // Insert default row if missing
    $db->exec("INSERT INTO om_card_support_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING");

    if ($method === 'GET') {
        $row = $db->query("SELECT * FROM om_card_support_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $history = $db->query("
            SELECT id, changed_by, changed_at, field, old_value, new_value
            FROM om_card_support_settings_history
            ORDER BY changed_at DESC
            LIMIT 30
        ")->fetchAll(PDO::FETCH_ASSOC);
        response(true, [
            'settings' => [
                'interest_rate_month'    => (float)($row['interest_rate_month'] ?? 9.9),
                'annual_fee'             => (float)($row['annual_fee']          ?? 0),
                'min_payment_pct'        => (float)($row['min_payment_pct']     ?? 15),
                'grace_period_days'      => (int)($row['grace_period_days']     ?? 10),
                'auto_approve_score'     => (int)($row['auto_approve_score']    ?? 700),
                'fraud_rule_high_amount' => (float)($row['fraud_rule_high_amount'] ?? 2000),
                'fraud_rule_velocity'    => (int)($row['fraud_rule_velocity']   ?? 10),
                'webhook_url'            => $row['webhook_url'],
                'templates'              => $row['templates'] ? (json_decode($row['templates'], true) ?: []) : [
                    'reminder_push'     => 'SuperBora: sua fatura de R$ {{amount}} vence em {{days}} dias.',
                    'overdue_whatsapp'  => 'Olá {{name}}, sua fatura está vencida. Pague pelo app para evitar juros.',
                    'approval_push'     => 'Parabéns! Seu cartão SuperBora foi aprovado com limite de R$ {{limit}}.',
                ],
                'updated_at'             => $row['updated_at'],
                'updated_by'             => $row['updated_by'] ? (int)$row['updated_by'] : null,
            ],
            'history' => $history,
        ]);
    }

    if ($method === 'PUT' || $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $current = $db->query("SELECT * FROM om_card_support_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

        $fields = [
            'interest_rate_month'    => 'numeric',
            'annual_fee'             => 'numeric',
            'min_payment_pct'        => 'numeric',
            'grace_period_days'      => 'int',
            'auto_approve_score'     => 'int',
            'fraud_rule_high_amount' => 'numeric',
            'fraud_rule_velocity'    => 'int',
            'webhook_url'            => 'text',
            'templates'              => 'json',
        ];

        $updates = [];
        $params = [];
        foreach ($fields as $f => $type) {
            if (!array_key_exists($f, $body)) continue;
            $newVal = $body[$f];
            $oldVal = $current[$f] ?? null;
            $formattedNew = $newVal;
            if ($type === 'json') {
                $formattedNew = json_encode($newVal, JSON_UNESCAPED_UNICODE);
            }
            // Log history
            try {
                $h = $db->prepare("INSERT INTO om_card_support_settings_history (changed_by, field, old_value, new_value) VALUES (?, ?, ?, ?)");
                $h->execute([$adminId, $f, is_array($oldVal) ? json_encode($oldVal) : (string)$oldVal, is_array($newVal) ? json_encode($newVal) : (string)$newVal]);
            } catch (Exception $e) { /* ignore */ }

            $updates[] = "$f = ?";
            $params[] = $formattedNew;
        }

        if ($updates) {
            $updates[] = 'updated_at = NOW()';
            $updates[] = 'updated_by = ?';
            $params[] = $adminId;
            $sql = 'UPDATE om_card_support_settings SET ' . implode(', ', $updates) . ' WHERE id = 1';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        }

        response(true, ['updated' => count($updates) - 2]);  // subtract updated_at + updated_by
    }

    response(false, null, 'Metodo nao permitido', 405);
} catch (Exception $e) {
    error_log('[card-support-settings] ' . $e->getMessage());
    response(false, null, 'Erro em settings: ' . $e->getMessage(), 500);
}
