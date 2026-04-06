<?php
/**
 * GET/POST /api/mercado/partner/wallet-schedule.php
 * Auto-withdrawal schedule configuration
 *
 * GET: Returns current schedule from om_payout_schedule
 * POST: Save/update schedule {frequency, day_of_week, min_amount, enabled}
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
    ensureScheduleTable($db);

    // ======================== GET: Load schedule ========================
    if ($method === 'GET') {
        $stmt = dbQuery($db, "
            SELECT frequency, day_of_week, min_amount, enabled, created_at, updated_at
            FROM om_payout_schedule
            WHERE partner_id = ?
        ", [$partnerId]);
        $schedule = $stmt->fetch();

        if (!$schedule) {
            // Return defaults
            $schedule = [
                'frequency' => 'weekly',
                'day_of_week' => 5,
                'min_amount' => 50.00,
                'enabled' => false,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        $nextDate = calculateNextScheduleDate($schedule['frequency'], (int)$schedule['day_of_week']);

        response(true, [
            'schedule' => [
                'frequency' => $schedule['frequency'],
                'day_of_week' => (int)$schedule['day_of_week'],
                'min_amount' => round((float)$schedule['min_amount'], 2),
                'enabled' => (bool)$schedule['enabled'],
                'created_at' => $schedule['created_at'],
                'updated_at' => $schedule['updated_at'],
            ],
            'next_withdrawal_date' => $nextDate,
            'frequency_options' => [
                ['value' => 'weekly', 'label' => 'Semanal'],
                ['value' => 'biweekly', 'label' => 'Quinzenal'],
                ['value' => 'monthly', 'label' => 'Mensal'],
            ],
            'day_options' => getDayOptions($schedule['frequency']),
        ], "Agendamento de saque carregado");
    }

    // ======================== POST: Save schedule ========================
    if ($method === 'POST') {
        $input = getInput();

        // Validate frequency
        $frequency = $input['frequency'] ?? 'weekly';
        if (!in_array($frequency, ['weekly', 'biweekly', 'monthly'], true)) {
            $frequency = 'weekly';
        }

        // Validate day_of_week
        $dayOfWeek = (int)($input['day_of_week'] ?? 5);
        if ($frequency === 'monthly') {
            $dayOfWeek = max(1, min(28, $dayOfWeek));
        } else {
            $dayOfWeek = max(1, min(7, $dayOfWeek)); // 1=Monday, 7=Sunday
        }

        // Validate min_amount
        $minAmount = max(10.00, (float)($input['min_amount'] ?? 50));

        // Enabled
        $enabled = isset($input['enabled']) ? (bool)$input['enabled'] : true;

        // Upsert schedule
        dbQuery($db, "
            INSERT INTO om_payout_schedule (partner_id, frequency, day_of_week, min_amount, enabled, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ON CONFLICT (partner_id) DO UPDATE SET
                frequency = EXCLUDED.frequency,
                day_of_week = EXCLUDED.day_of_week,
                min_amount = EXCLUDED.min_amount,
                enabled = EXCLUDED.enabled,
                updated_at = NOW()
        ", [$partnerId, $frequency, $dayOfWeek, $minAmount, $enabled ? 1 : 0]);

        $nextDate = calculateNextScheduleDate($frequency, $dayOfWeek);

        response(true, [
            'schedule' => [
                'frequency' => $frequency,
                'day_of_week' => $dayOfWeek,
                'min_amount' => round($minAmount, 2),
                'enabled' => $enabled,
            ],
            'next_withdrawal_date' => $nextDate,
        ], "Agendamento de saque " . ($enabled ? "ativado" : "atualizado"));
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/wallet-schedule] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

/**
 * Create om_payout_schedule table if it doesn't exist
 */
function ensureScheduleTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_payout_schedule (
            id SERIAL PRIMARY KEY,
            partner_id INT NOT NULL UNIQUE,
            frequency VARCHAR(20) NOT NULL DEFAULT 'weekly',
            day_of_week INT NOT NULL DEFAULT 5,
            min_amount DECIMAL(10,2) NOT NULL DEFAULT 50.00,
            enabled BOOLEAN NOT NULL DEFAULT false,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");
}

/**
 * Calculate the next scheduled withdrawal date
 */
function calculateNextScheduleDate(string $frequency, int $day): string {
    $today = new DateTime();

    if ($frequency === 'monthly') {
        $currentDay = (int)$today->format('j');
        if ($currentDay < $day) {
            return $today->setDate((int)$today->format('Y'), (int)$today->format('n'), min($day, 28))->format('Y-m-d');
        }
        $next = (clone $today)->modify('first day of next month');
        return $next->setDate((int)$next->format('Y'), (int)$next->format('n'), min($day, 28))->format('Y-m-d');
    }

    // Weekly or biweekly
    $currentDow = (int)$today->format('N'); // 1=Mon, 7=Sun
    $daysUntil = ($day - $currentDow + 7) % 7;
    if ($daysUntil === 0) $daysUntil = 7;

    if ($frequency === 'biweekly') {
        // Next occurrence at least 7 days from now
        if ($daysUntil < 7) $daysUntil += 7;
    }

    return (clone $today)->modify("+{$daysUntil} days")->format('Y-m-d');
}

/**
 * Get day options based on frequency
 */
function getDayOptions(string $frequency): array {
    if ($frequency === 'monthly') {
        $options = [];
        for ($i = 1; $i <= 28; $i++) {
            $options[] = ['value' => $i, 'label' => "Dia $i"];
        }
        return $options;
    }

    return [
        ['value' => 1, 'label' => 'Segunda-feira'],
        ['value' => 2, 'label' => 'Terca-feira'],
        ['value' => 3, 'label' => 'Quarta-feira'],
        ['value' => 4, 'label' => 'Quinta-feira'],
        ['value' => 5, 'label' => 'Sexta-feira'],
        ['value' => 6, 'label' => 'Sabado'],
        ['value' => 7, 'label' => 'Domingo'],
    ];
}
