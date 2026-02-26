<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * GAMIFICATION HELPER - Sistema completo de gamificação
 * Baseado em: Instacart Cart Star, iFood Super Entregadores, DoorDash Top Dasher
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class GamificationHelper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // TIERS / NÍVEIS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Obter tier atual do worker
     */
    public function getWorkerTier($workerId) {
        $stmt = $this->pdo->prepare("
            SELECT t.* FROM om_worker_tiers t
            JOIN om_market_workers w ON w.tier_id = t.tier_id
            WHERE w.worker_id = ?
        ");
        $stmt->execute([$workerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Calcular e atualizar tier do worker
     */
    public function updateWorkerTier($workerId) {
        // Buscar stats do worker
        $stmt = $this->pdo->prepare("
            SELECT total_deliveries, average_rating, acceptance_rate 
            FROM om_market_workers WHERE worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
        
        if (!$worker) return false;
        
        // Encontrar tier adequado
        $stmt = $this->pdo->prepare("
            SELECT * FROM om_worker_tiers 
            WHERE min_deliveries <= ? AND min_rating <= ? AND min_acceptance_rate <= ?
            ORDER BY tier_level DESC LIMIT 1
        ");
        $stmt->execute([
            $worker['total_deliveries'],
            $worker['average_rating'],
            $worker['acceptance_rate']
        ]);
        $newTier = $stmt->fetch();
        
        if ($newTier) {
            $this->pdo->prepare("UPDATE om_market_workers SET tier_id = ? WHERE worker_id = ?")
                      ->execute([$newTier['tier_id'], $workerId]);
            return $newTier;
        }
        return false;
    }
    
    /**
     * Obter benefícios do tier
     */
    public function getTierBenefits($tierId) {
        $stmt = $this->pdo->prepare("SELECT benefits, earnings_bonus_percent, priority_boost FROM om_worker_tiers WHERE tier_id = ?");
        $stmt->execute([$tierId]);
        $tier = $stmt->fetch();
        
        if ($tier) {
            return [
                'benefits' => json_decode($tier['benefits'], true),
                'earnings_bonus' => $tier['earnings_bonus_percent'],
                'priority_seconds' => $tier['priority_boost']
            ];
        }
        return null;
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // CHALLENGES / DESAFIOS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Obter desafios disponíveis para worker
     */
    public function getAvailableChallenges($workerId) {
        $stmt = $this->pdo->prepare("
            SELECT c.*, 
                   COALESCE(wc.current_progress, 0) as progress,
                   COALESCE(wc.status, 'available') as worker_status
            FROM om_challenges c
            LEFT JOIN om_worker_challenges wc ON c.challenge_id = wc.challenge_id AND wc.worker_id = ?
            WHERE c.is_active = 1 
              AND c.start_date <= CURRENT_DATE 
              AND c.end_date >= CURRENT_DATE
              AND (c.tier_required IS NULL OR c.tier_required IN (
                  SELECT t.tier_slug FROM om_worker_tiers t
                  JOIN om_market_workers w ON w.tier_id = t.tier_id
                  WHERE w.worker_id = ?
              ))
            ORDER BY c.reward_amount DESC
        ");
        $stmt->execute([$workerId, $workerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Participar de um desafio
     */
    public function joinChallenge($workerId, $challengeId) {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO om_worker_challenges (worker_id, challenge_id, status)
            VALUES (?, ?, 'active')
        ");
        return $stmt->execute([$workerId, $challengeId]);
    }
    
    /**
     * Atualizar progresso do desafio
     */
    public function updateChallengeProgress($workerId, $type, $value = 1) {
        // Buscar desafios ativos do tipo
        $stmt = $this->pdo->prepare("
            SELECT wc.*, c.target_value, c.reward_amount, c.reward_type
            FROM om_worker_challenges wc
            JOIN om_challenges c ON wc.challenge_id = c.challenge_id
            WHERE wc.worker_id = ? AND wc.status = 'active' AND c.type = ?
        ");
        $stmt->execute([$workerId, $type]);
        $challenges = $stmt->fetchAll();
        
        $completed = [];
        foreach ($challenges as $ch) {
            $newProgress = $ch['current_progress'] + $value;
            
            if ($newProgress >= $ch['target_value']) {
                // Completou!
                $this->pdo->prepare("
                    UPDATE om_worker_challenges 
                    SET current_progress = ?, status = 'completed', completed_at = NOW()
                    WHERE id = ?
                ")->execute([$ch['target_value'], $ch['id']]);
                
                $completed[] = [
                    'challenge_id' => $ch['challenge_id'],
                    'reward_amount' => $ch['reward_amount'],
                    'reward_type' => $ch['reward_type']
                ];
            } else {
                $this->pdo->prepare("UPDATE om_worker_challenges SET current_progress = ? WHERE id = ?")
                          ->execute([$newProgress, $ch['id']]);
            }
        }
        
        return $completed;
    }
    
    /**
     * Reivindicar recompensa de desafio
     */
    public function claimChallengeReward($workerId, $challengeId) {
        $stmt = $this->pdo->prepare("
            SELECT wc.*, c.reward_amount, c.reward_type
            FROM om_worker_challenges wc
            JOIN om_challenges c ON wc.challenge_id = c.challenge_id
            WHERE wc.worker_id = ? AND wc.challenge_id = ? AND wc.status = 'completed'
        ");
        $stmt->execute([$workerId, $challengeId]);
        $challenge = $stmt->fetch();
        
        if (!$challenge) return false;
        
        $this->pdo->beginTransaction();
        try {
            // Marcar como reivindicado
            $this->pdo->prepare("
                UPDATE om_worker_challenges SET status = 'claimed', claimed_at = NOW() WHERE id = ?
            ")->execute([$challenge['id']]);
            
            // Dar recompensa
            if ($challenge['reward_type'] === 'cash' || $challenge['reward_type'] === 'bonus') {
                $this->pdo->prepare("
                    UPDATE om_market_workers 
                    SET available_balance = available_balance + ? 
                    WHERE worker_id = ?
                ")->execute([$challenge['reward_amount'], $workerId]);
            } elseif ($challenge['reward_type'] === 'points') {
                $this->addPoints($workerId, $challenge['reward_amount'], 'challenge', $challengeId);
            }
            
            $this->pdo->commit();
            return $challenge;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return false;
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // POINTS / PONTOS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Adicionar pontos ao worker
     */
    public function addPoints($workerId, $points, $source, $referenceId = null, $description = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO om_worker_points (worker_id, points, source, reference_id, description)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$workerId, $points, $source, $referenceId, $description]);
        
        // Atualizar total
        $this->pdo->prepare("UPDATE om_market_workers SET total_points = total_points + ? WHERE worker_id = ?")
                  ->execute([$points, $workerId]);
        
        return true;
    }
    
    /**
     * Obter saldo de pontos
     */
    public function getPointsBalance($workerId) {
        $stmt = $this->pdo->prepare("SELECT total_points FROM om_market_workers WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        return $stmt->fetchColumn() ?: 0;
    }
    
    /**
     * Obter histórico de pontos
     */
    public function getPointsHistory($workerId, $limit = 50) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM om_worker_points 
            WHERE worker_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$workerId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // REWARDS STORE / LOJA DE RECOMPENSAS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Obter itens da loja
     */
    public function getRewardsStore() {
        $stmt = $this->pdo->query("
            SELECT * FROM om_rewards_store 
            WHERE is_active = 1 AND (stock IS NULL OR stock > 0)
            ORDER BY points_required ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Resgatar recompensa
     */
    public function redeemReward($workerId, $rewardId) {
        $stmt = $this->pdo->prepare("SELECT * FROM om_rewards_store WHERE reward_id = ? AND is_active = 1");
        $stmt->execute([$rewardId]);
        $reward = $stmt->fetch();
        
        if (!$reward) return ['success' => false, 'error' => 'Recompensa não encontrada'];
        
        $balance = $this->getPointsBalance($workerId);
        if ($balance < $reward['points_required']) {
            return ['success' => false, 'error' => 'Pontos insuficientes'];
        }
        
        if ($reward['stock'] !== null && $reward['stock'] <= 0) {
            return ['success' => false, 'error' => 'Recompensa esgotada'];
        }
        
        $this->pdo->beginTransaction();
        try {
            // Criar resgate
            $stmt = $this->pdo->prepare("
                INSERT INTO om_reward_redemptions (worker_id, reward_id, points_spent)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$workerId, $rewardId, $reward['points_required']]);
            
            // Descontar pontos
            $this->pdo->prepare("
                UPDATE om_market_workers SET total_points = total_points - ? WHERE worker_id = ?
            ")->execute([$reward['points_required'], $workerId]);
            
            // Descontar estoque
            if ($reward['stock'] !== null) {
                $this->pdo->prepare("UPDATE om_rewards_store SET stock = stock - 1 WHERE reward_id = ?")
                          ->execute([$rewardId]);
            }
            
            // Se for cash, creditar
            if ($reward['reward_type'] === 'cash') {
                $this->pdo->prepare("
                    UPDATE om_market_workers SET available_balance = available_balance + ? WHERE worker_id = ?
                ")->execute([$reward['reward_value'], $workerId]);
            }
            
            $this->pdo->commit();
            return ['success' => true, 'reward' => $reward];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // STREAKS / SEQUÊNCIAS
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Atualizar streak do worker
     */
    public function updateStreak($workerId) {
        // Verificar se fez entrega hoje e ontem
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT DATE(completed_at)) as days
            FROM om_market_orders 
            WHERE (shopper_id = ? OR delivery_id = ?) 
              AND status = 'delivered'
              AND completed_at >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)
            ORDER BY completed_at DESC
        ");
        $stmt->execute([$workerId, $workerId]);
        
        // Lógica simplificada - verificar dias consecutivos
        $stmt = $this->pdo->prepare("
            SELECT current_streak, best_streak FROM om_market_workers WHERE worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
        
        // Verificar se entregou ontem
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM om_market_orders 
            WHERE (shopper_id = ? OR delivery_id = ?) 
              AND status = 'delivered'
              AND DATE(completed_at) = DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY)
        ");
        $stmt->execute([$workerId, $workerId]);
        $yesterdayDeliveries = $stmt->fetchColumn();
        
        // Verificar se entregou hoje
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM om_market_orders 
            WHERE (shopper_id = ? OR delivery_id = ?) 
              AND status = 'delivered'
              AND DATE(completed_at) = CURRENT_DATE
        ");
        $stmt->execute([$workerId, $workerId]);
        $todayDeliveries = $stmt->fetchColumn();
        
        $newStreak = $worker['current_streak'];
        
        if ($todayDeliveries > 0) {
            if ($yesterdayDeliveries > 0) {
                $newStreak++;
            } else {
                $newStreak = 1;
            }
        }
        
        $bestStreak = max($worker['best_streak'], $newStreak);
        
        $this->pdo->prepare("
            UPDATE om_market_workers SET current_streak = ?, best_streak = ? WHERE worker_id = ?
        ")->execute([$newStreak, $bestStreak, $workerId]);
        
        return ['current' => $newStreak, 'best' => $bestStreak];
    }
    
    // ═══════════════════════════════════════════════════════════════════════════
    // REFERRAL / INDICAÇÃO
    // ═══════════════════════════════════════════════════════════════════════════
    
    /**
     * Gerar código de indicação
     */
    public function generateReferralCode($workerId) {
        $code = strtoupper(substr(md5($workerId . time()), 0, 8));
        $this->pdo->prepare("UPDATE om_market_workers SET referral_code = ? WHERE worker_id = ?")
                  ->execute([$code, $workerId]);
        return $code;
    }
    
    /**
     * Aplicar código de indicação
     */
    public function applyReferralCode($newWorkerId, $code) {
        $stmt = $this->pdo->prepare("SELECT worker_id FROM om_market_workers WHERE referral_code = ?");
        $stmt->execute([$code]);
        $referrer = $stmt->fetch();
        
        if (!$referrer || $referrer['worker_id'] == $newWorkerId) {
            return false;
        }
        
        // Criar registro de indicação
        $stmt = $this->pdo->prepare("
            INSERT INTO om_referrals (referrer_worker_id, referred_worker_id, referral_code)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$referrer['worker_id'], $newWorkerId, $code]);
        
        // Marcar quem indicou
        $this->pdo->prepare("UPDATE om_market_workers SET referred_by = ? WHERE worker_id = ?")
                  ->execute([$referrer['worker_id'], $newWorkerId]);
        
        return true;
    }
    
    /**
     * Verificar e pagar bônus de indicação
     */
    public function checkReferralBonus($workerId) {
        $stmt = $this->pdo->prepare("
            SELECT r.*, w.total_deliveries
            FROM om_referrals r
            JOIN om_market_workers w ON r.referred_worker_id = w.worker_id
            WHERE r.referred_worker_id = ? AND r.status IN ('pending', 'active')
        ");
        $stmt->execute([$workerId]);
        $referral = $stmt->fetch();
        
        if (!$referral) return null;
        
        if ($referral['status'] === 'pending' && $referral['total_deliveries'] > 0) {
            // Ativou
            $this->pdo->prepare("UPDATE om_referrals SET status = 'active' WHERE referral_id = ?")
                      ->execute([$referral['referral_id']]);
        }
        
        if ($referral['total_deliveries'] >= $referral['required_deliveries'] && $referral['status'] !== 'paid') {
            // Qualificou - pagar bônus
            $this->pdo->beginTransaction();
            try {
                // Bônus para quem indicou
                $this->pdo->prepare("
                    UPDATE om_market_workers SET available_balance = available_balance + ? WHERE worker_id = ?
                ")->execute([$referral['bonus_referrer'], $referral['referrer_worker_id']]);
                
                // Bônus para indicado
                $this->pdo->prepare("
                    UPDATE om_market_workers SET available_balance = available_balance + ? WHERE worker_id = ?
                ")->execute([$referral['bonus_referred'], $workerId]);
                
                // Marcar como pago
                $this->pdo->prepare("UPDATE om_referrals SET status = 'paid', paid_at = NOW() WHERE referral_id = ?")
                          ->execute([$referral['referral_id']]);
                
                $this->pdo->commit();
                return $referral;
            } catch (Exception $e) {
                $this->pdo->rollBack();
            }
        }
        
        return null;
    }
}