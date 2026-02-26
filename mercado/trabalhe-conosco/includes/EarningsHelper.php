<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * EARNINGS HELPER - Sistema completo de ganhos
 * Baseado em: DoorDash Peak Pay, Instacart Heavy Pay, iFood/99Food Tips
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class EarningsHelper {
    private $pdo;
    
    // Configurações
    const QUALITY_BONUS_5_STAR = 3.00;      // Bônus por avaliação 5 estrelas
    const TIP_PROTECTION_MAX = 10.00;       // Máximo de proteção de gorjeta
    const WAIT_TIME_THRESHOLD = 10;         // Minutos para começar pagar espera
    const WAIT_TIME_RATE = 0.50;            // R$ por minuto de espera
    const FAST_PAY_FEE = 1.99;              // Taxa de saque rápido
    const FAST_PAY_FREE_TIER = 'gold';      // Tier com saque grátis
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // PEAK PAY / BOOST
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Obter Peak Pay ativo para região
     */
    public function getActivePeakPay($regionId = null, $partnerId = null) {
        $sql = "
            SELECT * FROM om_peak_pay 
            WHERE is_active = 1 
              AND NOW() BETWEEN start_time AND end_time
              AND (max_uses IS NULL OR current_uses < max_uses)
        ";
        $params = [];
        
        if ($regionId) {
            $sql .= " AND (region_id IS NULL OR region_id = ?)";
            $params[] = $regionId;
        }
        if ($partnerId) {
            $sql .= " AND (partner_id IS NULL OR partner_id = ?)";
            $params[] = $partnerId;
        }
        
        $sql .= " ORDER BY bonus_amount DESC LIMIT 1";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Aplicar Peak Pay a uma entrega
     */
    public function applyPeakPay($orderId, $peakId) {
        $stmt = $this->pdo->prepare("SELECT * FROM om_peak_pay WHERE peak_id = ?");
        $stmt->execute([$peakId]);
        $peak = $stmt->fetch();
        
        if (!$peak) return 0;
        
        // Incrementar uso
        $this->pdo->prepare("UPDATE om_peak_pay SET current_uses = current_uses + 1 WHERE peak_id = ?")
                  ->execute([$peakId]);
        
        return $peak['bonus_amount'];
    }
    
    /**
     * Criar novo Peak Pay
     */
    public function createPeakPay($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO om_peak_pay 
            (region_id, partner_id, title, description, bonus_amount, bonus_type, start_time, end_time, day_of_week, min_deliveries, max_uses)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $data['region_id'] ?? null,
            $data['partner_id'] ?? null,
            $data['title'],
            $data['description'] ?? null,
            $data['bonus_amount'],
            $data['bonus_type'] ?? 'fixed',
            $data['start_time'],
            $data['end_time'],
            $data['day_of_week'] ?? null,
            $data['min_deliveries'] ?? 1,
            $data['max_uses'] ?? null
        ]);
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // HEAVY PAY (Instacart)
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Calcular Heavy Pay baseado no peso do pedido
     */
    public function calculateHeavyPay($totalWeightKg, $totalItems = 0) {
        $stmt = $this->pdo->prepare("
            SELECT bonus_amount FROM om_heavy_pay_rules 
            WHERE is_active = 1 
              AND (min_weight_kg <= ? OR (min_items IS NOT NULL AND min_items <= ?))
            ORDER BY bonus_amount DESC LIMIT 1
        ");
        $stmt->execute([$totalWeightKg, $totalItems]);
        return $stmt->fetchColumn() ?: 0;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // TIPS / GORJETAS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Registrar gorjeta
     */
    public function recordTip($orderId, $workerId, $customerId, $amount, $type = 'pre_order') {
        $stmt = $this->pdo->prepare("
            INSERT INTO om_tips (order_id, worker_id, customer_id, amount, tip_type)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE amount = ?, tip_type = ?
        ");
        $stmt->execute([$orderId, $workerId, $customerId, $amount, $type, $amount, $type]);
        
        // Atualizar total de gorjetas do worker
        $this->pdo->prepare("
            UPDATE om_market_workers SET total_tips = total_tips + ? WHERE worker_id = ?
        ")->execute([$amount, $workerId]);
        
        return true;
    }
    
    /**
     * Cliente aumentou gorjeta após entrega
     */
    public function increaseTip($orderId, $newAmount) {
        $stmt = $this->pdo->prepare("SELECT * FROM om_tips WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $tip = $stmt->fetch();
        
        if (!$tip) return false;
        
        $increase = $newAmount - $tip['amount'];
        if ($increase <= 0) return false;
        
        $this->pdo->prepare("
            UPDATE om_tips SET amount = ?, tip_type = 'increased', original_amount = ? WHERE tip_id = ?
        ")->execute([$newAmount, $tip['amount'], $tip['tip_id']]);
        
        // Atualizar total
        $this->pdo->prepare("
            UPDATE om_market_workers SET total_tips = total_tips + ? WHERE worker_id = ?
        ")->execute([$increase, $tip['worker_id']]);
        
        return $increase;
    }
    
    /**
     * Tip Protection - Cobrir gorjeta zerada (Instacart)
     */
    public function applyTipProtection($orderId, $originalTip, $finalTip, $workerId) {
        if ($finalTip >= $originalTip) return 0;
        
        $protectionAmount = min($originalTip - $finalTip, self::TIP_PROTECTION_MAX);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO om_tip_protection (order_id, worker_id, original_tip, final_tip, protection_amount)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$orderId, $workerId, $originalTip, $finalTip, $protectionAmount]);
        
        // Creditar proteção
        $this->pdo->prepare("
            UPDATE om_market_workers SET available_balance = available_balance + ? WHERE worker_id = ?
        ")->execute([$protectionAmount, $workerId]);
        
        return $protectionAmount;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // QUALITY BONUS (Instacart - bônus por 5 estrelas)
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Dar bônus por avaliação 5 estrelas
     */
    public function giveQualityBonus($workerId, $orderId, $rating) {
        if ($rating < 5) return 0;
        
        $bonus = self::QUALITY_BONUS_5_STAR;
        
        $stmt = $this->pdo->prepare("
            INSERT INTO om_quality_bonuses (worker_id, order_id, rating, bonus_amount)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$workerId, $orderId, $rating, $bonus]);
        
        // Creditar
        $this->pdo->prepare("
            UPDATE om_market_workers 
            SET available_balance = available_balance + ?, five_star_count = five_star_count + 1 
            WHERE worker_id = ?
        ")->execute([$bonus, $workerId]);
        
        return $bonus;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // WAIT TIME PAY (iFood / DoorDash)
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Calcular pagamento por tempo de espera
     */
    public function calculateWaitTimePay($orderId, $workerId, $waitType, $waitMinutes) {
        if ($waitMinutes <= self::WAIT_TIME_THRESHOLD) return 0;
        
        $extraMinutes = $waitMinutes - self::WAIT_TIME_THRESHOLD;
        $payment = $extraMinutes * self::WAIT_TIME_RATE;
        
        $stmt = $this->pdo->prepare("
            INSERT INTO om_wait_time_payments (order_id, worker_id, wait_type, wait_minutes, payment_amount)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$orderId, $workerId, $waitType, $waitMinutes, $payment]);
        
        return $payment;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // DAILY GOALS / METAS DIÁRIAS (99Food)
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Obter metas diárias ativas
     */
    public function getDailyGoals() {
        $dayOfWeek = strtolower(date('D'));
        $stmt = $this->pdo->prepare("
            SELECT * FROM om_daily_goals 
            WHERE is_active = 1 
              AND FIND_IN_SET(?, days_active)
              AND CURTIME() BETWEEN valid_from AND valid_until
            ORDER BY guaranteed_amount DESC
        ");
        $stmt->execute([$dayOfWeek]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter progresso do worker nas metas
     */
    public function getWorkerDailyProgress($workerId) {
        $stmt = $this->pdo->prepare("
            SELECT g.*, 
                   COALESCE(p.deliveries_done, 0) as deliveries_done,
                   COALESCE(p.shopping_done, 0) as shopping_done,
                   COALESCE(p.is_completed, 0) as is_completed,
                   COALESCE(p.bonus_paid, 0) as bonus_paid
            FROM om_daily_goals g
            LEFT JOIN om_worker_daily_progress p ON g.goal_id = p.goal_id 
                AND p.worker_id = ? AND p.date = CURRENT_DATE
            WHERE g.is_active = 1
        ");
        $stmt->execute([$workerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Atualizar progresso da meta diária
     */
    public function updateDailyProgress($workerId, $type = 'delivery') {
        $goals = $this->getDailyGoals();
        $completedGoals = [];
        
        foreach ($goals as $goal) {
            // Inserir ou atualizar progresso
            $field = $type === 'delivery' ? 'deliveries_done' : 'shopping_done';
            
            $stmt = $this->pdo->prepare("
                INSERT INTO om_worker_daily_progress (worker_id, goal_id, date, {$field})
                VALUES (?, ?, CURRENT_DATE, 1)
                ON DUPLICATE KEY UPDATE {$field} = {$field} + 1
            ");
            $stmt->execute([$workerId, $goal['goal_id']]);
            
            // Verificar se completou
            $stmt = $this->pdo->prepare("
                SELECT * FROM om_worker_daily_progress 
                WHERE worker_id = ? AND goal_id = ? AND date = CURRENT_DATE
            ");
            $stmt->execute([$workerId, $goal['goal_id']]);
            $progress = $stmt->fetch();
            
            if ($progress && !$progress['is_completed'] &&
                $progress['deliveries_done'] >= $goal['required_deliveries'] &&
                $progress['shopping_done'] >= $goal['required_shopping']) {
                
                // Completou a meta!
                $this->pdo->prepare("
                    UPDATE om_worker_daily_progress 
                    SET is_completed = 1, completed_at = NOW() 
                    WHERE id = ?
                ")->execute([$progress['id']]);
                
                $completedGoals[] = $goal;
            }
        }
        
        return $completedGoals;
    }
    
    /**
     * Pagar bônus de meta diária
     */
    public function payDailyGoalBonus($workerId, $goalId) {
        $stmt = $this->pdo->prepare("
            SELECT p.*, g.guaranteed_amount 
            FROM om_worker_daily_progress p
            JOIN om_daily_goals g ON p.goal_id = g.goal_id
            WHERE p.worker_id = ? AND p.goal_id = ? AND p.date = CURRENT_DATE
              AND p.is_completed = 1 AND p.bonus_paid = 0
        ");
        $stmt->execute([$workerId, $goalId]);
        $progress = $stmt->fetch();
        
        if (!$progress) return false;
        
        $this->pdo->beginTransaction();
        try {
            // Marcar como pago
            $this->pdo->prepare("
                UPDATE om_worker_daily_progress 
                SET bonus_paid = 1, bonus_amount = ? 
                WHERE id = ?
            ")->execute([$progress['guaranteed_amount'], $progress['id']]);
            
            // Creditar
            $this->pdo->prepare("
                UPDATE om_market_workers 
                SET available_balance = available_balance + ? 
                WHERE worker_id = ?
            ")->execute([$progress['guaranteed_amount'], $workerId]);
            
            $this->pdo->commit();
            return $progress['guaranteed_amount'];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return false;
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // FAST PAY / SAQUE INSTANTÂNEO (DoorDash / iFood / 99Food)
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Verificar se pode fazer Fast Pay
     */
    public function canFastPay($workerId) {
        // Verificar saldo disponível
        $stmt = $this->pdo->prepare("SELECT available_balance, tier_id FROM om_market_workers WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
        
        if (!$worker || $worker['available_balance'] < 10) {
            return ['can' => false, 'reason' => 'Saldo mínimo de R$10 necessário'];
        }
        
        // Verificar se já fez hoje
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM om_fast_pay_requests 
            WHERE worker_id = ? AND DATE(requested_at) = CURRENT_DATE AND status != 'failed'
        ");
        $stmt->execute([$workerId]);
        if ($stmt->fetchColumn() > 0) {
            return ['can' => false, 'reason' => 'Limite de 1 saque por dia'];
        }
        
        // Verificar tier para taxa
        $stmt = $this->pdo->prepare("SELECT tier_slug FROM om_worker_tiers WHERE tier_id = ?");
        $stmt->execute([$worker['tier_id']]);
        $tier = $stmt->fetch();
        
        $fee = self::FAST_PAY_FEE;
        if ($tier && in_array($tier['tier_slug'], ['gold', 'platinum', 'diamond'])) {
            $fee = 0; // Grátis para tiers altos
        }
        
        return [
            'can' => true,
            'available' => $worker['available_balance'],
            'fee' => $fee,
            'net' => $worker['available_balance'] - $fee
        ];
    }
    
    /**
     * Solicitar Fast Pay
     */
    public function requestFastPay($workerId, $amount = null, $pixKey = null) {
        $check = $this->canFastPay($workerId);
        if (!$check['can']) {
            return ['success' => false, 'error' => $check['reason']];
        }
        
        $amount = $amount ?: $check['available'];
        if ($amount > $check['available']) {
            return ['success' => false, 'error' => 'Saldo insuficiente'];
        }
        
        $fee = $check['fee'];
        $net = $amount - $fee;
        
        $this->pdo->beginTransaction();
        try {
            // Criar requisição
            $stmt = $this->pdo->prepare("
                INSERT INTO om_fast_pay_requests 
                (worker_id, amount, fee, net_amount, payment_key)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$workerId, $amount, $fee, $net, $pixKey]);
            $requestId = $this->pdo->lastInsertId();
            
            // Debitar saldo
            $this->pdo->prepare("
                UPDATE om_market_workers 
                SET available_balance = available_balance - ?, pending_balance = pending_balance + ?
                WHERE worker_id = ?
            ")->execute([$amount, $net, $workerId]);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'request_id' => $requestId,
                'amount' => $amount,
                'fee' => $fee,
                'net' => $net
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // EARNINGS HISTORY
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Registrar ganhos do dia
     */
    public function recordDailyEarnings($workerId, $data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO om_earnings_history 
            (worker_id, date, base_earnings, tips_earnings, bonus_earnings, peak_pay_earnings,
             challenge_earnings, heavy_pay_earnings, wait_time_earnings, quality_bonus_earnings,
             total_earnings, deliveries_count, shopping_count, hours_online, hours_active, distance_km)
            VALUES (?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            base_earnings = base_earnings + VALUES(base_earnings),
            tips_earnings = tips_earnings + VALUES(tips_earnings),
            bonus_earnings = bonus_earnings + VALUES(bonus_earnings),
            peak_pay_earnings = peak_pay_earnings + VALUES(peak_pay_earnings),
            challenge_earnings = challenge_earnings + VALUES(challenge_earnings),
            heavy_pay_earnings = heavy_pay_earnings + VALUES(heavy_pay_earnings),
            wait_time_earnings = wait_time_earnings + VALUES(wait_time_earnings),
            quality_bonus_earnings = quality_bonus_earnings + VALUES(quality_bonus_earnings),
            total_earnings = total_earnings + VALUES(total_earnings),
            deliveries_count = deliveries_count + VALUES(deliveries_count),
            shopping_count = shopping_count + VALUES(shopping_count),
            hours_online = hours_online + VALUES(hours_online),
            hours_active = hours_active + VALUES(hours_active),
            distance_km = distance_km + VALUES(distance_km)
        ");
        
        return $stmt->execute([
            $workerId,
            $data['base'] ?? 0,
            $data['tips'] ?? 0,
            $data['bonus'] ?? 0,
            $data['peak_pay'] ?? 0,
            $data['challenge'] ?? 0,
            $data['heavy_pay'] ?? 0,
            $data['wait_time'] ?? 0,
            $data['quality_bonus'] ?? 0,
            array_sum($data),
            $data['deliveries'] ?? 0,
            $data['shopping'] ?? 0,
            $data['hours_online'] ?? 0,
            $data['hours_active'] ?? 0,
            $data['distance'] ?? 0
        ]);
    }
    
    /**
     * Obter histórico de ganhos
     */
    public function getEarningsHistory($workerId, $days = 30) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM om_earnings_history 
            WHERE worker_id = ? AND date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
            ORDER BY date DESC
        ");
        $stmt->execute([$workerId, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter resumo de ganhos
     */
    public function getEarningsSummary($workerId, $period = 'week') {
        $interval = $period === 'week' ? 7 : ($period === 'month' ? 30 : 1);
        
        $stmt = $this->pdo->prepare("
            SELECT 
                SUM(total_earnings) as total,
                SUM(base_earnings) as base,
                SUM(tips_earnings) as tips,
                SUM(bonus_earnings) as bonus,
                SUM(peak_pay_earnings) as peak_pay,
                SUM(deliveries_count) as deliveries,
                SUM(shopping_count) as shopping,
                SUM(hours_active) as hours,
                SUM(distance_km) as distance,
                AVG(total_earnings) as daily_avg
            FROM om_earnings_history 
            WHERE worker_id = ? AND date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
        ");
        $stmt->execute([$workerId, $interval]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}