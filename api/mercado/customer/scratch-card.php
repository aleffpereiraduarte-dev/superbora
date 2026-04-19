<?php
/**
 * GET  /api/mercado/customer/scratch-card.php  -> check eligibility
 * POST /api/mercado/customer/scratch-card.php  -> play (1x/day)
 *
 * Weighted prize distribution:
 *   50%  cashback R$ 1,00
 *   25%  cashback R$ 2,00
 *   15%  cashback R$ 5,00
 *   7%   cashback R$ 10,00
 *   3%   cashback R$ 25,00
 *
 * Eligibility: max 1 play per customer per calendar day
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

try {
    $db = getDB();
    $customerId = requireCustomerAuth();

    $db->exec("CREATE TABLE IF NOT EXISTS om_market_scratch_plays (
        id BIGSERIAL PRIMARY KEY,
        customer_id BIGINT NOT NULL,
        prize_type VARCHAR(20) NOT NULL,
        prize_value NUMERIC(10,2) NOT NULL,
        played_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_scratch_plays_customer_date
               ON om_market_scratch_plays(customer_id, played_at)");

    $todayStart = date('Y-m-d 00:00:00');

    $stmt = $db->prepare("
        SELECT id, prize_type, prize_value, played_at
        FROM om_market_scratch_plays
        WHERE customer_id = ? AND played_at >= ?
        ORDER BY played_at DESC
        LIMIT 1
    ");
    $stmt->execute([$customerId, $todayStart]);
    $todayPlay = $stmt->fetch();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $nextReset = date('Y-m-d 00:00:00', strtotime('tomorrow'));
        response(true, [
            'eligible' => !$todayPlay,
            'next_reset' => $nextReset,
            'last_play' => $todayPlay ? [
                'prize_type' => $todayPlay['prize_type'],
                'prize_value' => (float)$todayPlay['prize_value'],
                'played_at' => $todayPlay['played_at'],
            ] : null,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, "Metodo nao permitido", 405);
    }

    if ($todayPlay) {
        response(false, [
            'already_played' => true,
            'prize_type' => $todayPlay['prize_type'],
            'prize_value' => (float)$todayPlay['prize_value'],
        ], "Voce ja jogou hoje. Volte amanha!", 409);
    }

    $prizes = [
        ['weight' => 50, 'type' => 'cashback', 'value' => 1.00,  'label' => 'R$ 1 em cashback'],
        ['weight' => 25, 'type' => 'cashback', 'value' => 2.00,  'label' => 'R$ 2 em cashback'],
        ['weight' => 15, 'type' => 'cashback', 'value' => 5.00,  'label' => 'R$ 5 em cashback'],
        ['weight' => 7,  'type' => 'cashback', 'value' => 10.00, 'label' => 'R$ 10 em cashback'],
        ['weight' => 3,  'type' => 'cashback', 'value' => 25.00, 'label' => 'R$ 25 em cashback'],
    ];

    $totalWeight = array_sum(array_column($prizes, 'weight'));
    $roll = random_int(1, $totalWeight);
    $cum = 0;
    $won = $prizes[0];
    foreach ($prizes as $prize) {
        $cum += $prize['weight'];
        if ($roll <= $cum) { $won = $prize; break; }
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO om_market_scratch_plays (customer_id, prize_type, prize_value)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$customerId, $won['type'], $won['value']]);

        $stmt = $db->prepare("
            INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
            VALUES (?, ?, ?)
            ON CONFLICT (customer_id) DO UPDATE SET
                balance = om_cashback_wallet.balance + EXCLUDED.balance,
                total_earned = om_cashback_wallet.total_earned + EXCLUDED.total_earned
        ");
        $stmt->execute([$customerId, $won['value'], $won['value']]);

        $stmt = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $newBalance = (float)$stmt->fetchColumn();

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    response(true, [
        'prize_type' => $won['type'],
        'prize_value' => $won['value'],
        'prize_label' => $won['label'],
        'new_balance' => $newBalance,
        'next_reset' => date('Y-m-d 00:00:00', strtotime('tomorrow')),
    ], "Parabens! Voce ganhou {$won['label']}");

} catch (Exception $e) {
    error_log("[customer/scratch-card] " . $e->getMessage());
    response(false, null, "Erro ao processar raspadinha", 500);
}
