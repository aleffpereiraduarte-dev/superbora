<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * ACCOUNT HEALTH HELPER - Saúde da conta do worker
 * Baseado em: iFood Saúde da Conta
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class AccountHealthHelper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Obter saúde da conta
     */
    public function getAccountHealth($workerId) {
        $stmt = $this->pdo->prepare("SELECT * FROM om_account_health WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        $health = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$health) {
            // Criar registro
            $this->pdo->prepare("INSERT INTO om_account_health (worker_id) VALUES (?)")->execute([$workerId]);
            return $this->getAccountHealth($workerId);
        }
        
        return $health;
    }
    
    /**
     * Atualizar scores de saúde
     */
    public function updateHealthScores($workerId) {
        $stmt = $this->pdo->prepare("
            SELECT average_rating, acceptance_rate, completion_rate, on_time_rate
            FROM om_market_workers WHERE worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
        
        if (!$worker) return false;
        
        // Calcular scores (0-100)
        $ratingScore = min(100, ($worker['average_rating'] / 5) * 100);
        $acceptanceScore = $worker['acceptance_rate'];
        $completionScore = $worker['completion_rate'];
        
        // Verificar fraudes (placeholder)
        $fraudScore = 100;
        
        // Score geral
        $overallScore = round(($ratingScore + $acceptanceScore + $completionScore + $fraudScore) / 4);
        
        // Determinar status
        $status = 'good';
        if ($overallScore >= 90) $status = 'excellent';
        elseif ($overallScore >= 70) $status = 'good';
        elseif ($overallScore >= 50) $status = 'attention';
        elseif ($overallScore >= 30) $status = 'warning';
        else $status = 'critical';
        
        $this->pdo->prepare("
            UPDATE om_account_health 
            SET rating_score = ?, acceptance_score = ?, completion_score = ?, 
                fraud_score = ?, overall_score = ?, health_status = ?
            WHERE worker_id = ?
        ")->execute([$ratingScore, $acceptanceScore, $completionScore, $fraudScore, $overallScore, $status, $workerId]);
        
        return [
            'rating_score' => $ratingScore,
            'acceptance_score' => $acceptanceScore,
            'completion_score' => $completionScore,
            'fraud_score' => $fraudScore,
            'overall_score' => $overallScore,
            'status' => $status
        ];
    }
    
    /**
     * Adicionar warning
     */
    public function addWarning($workerId, $reason) {
        $this->pdo->prepare("
            UPDATE om_account_health 
            SET warnings_count = warnings_count + 1, last_warning_at = NOW()
            WHERE worker_id = ?
        ")->execute([$workerId]);
        
        // Criar notificação
        $this->pdo->prepare("
            INSERT INTO om_worker_notifications (worker_id, title, message, type)
            VALUES (?, 'Aviso na sua conta', ?, 'warning')
        ")->execute([$workerId, $reason]);
        
        return true;
    }
    
    /**
     * Verificar risco de desativação
     */
    public function checkDeactivationRisk($workerId) {
        $health = $this->getAccountHealth($workerId);
        
        $risks = [];
        
        if ($health['rating_score'] < 40) {
            $risks[] = ['type' => 'rating', 'message' => 'Avaliação muito baixa', 'severity' => 'high'];
        }
        if ($health['acceptance_score'] < 50) {
            $risks[] = ['type' => 'acceptance', 'message' => 'Taxa de aceite baixa', 'severity' => 'medium'];
        }
        if ($health['completion_score'] < 80) {
            $risks[] = ['type' => 'completion', 'message' => 'Muitos pedidos cancelados', 'severity' => 'high'];
        }
        if ($health['warnings_count'] >= 3) {
            $risks[] = ['type' => 'warnings', 'message' => 'Muitos avisos recebidos', 'severity' => 'critical'];
        }
        
        return [
            'at_risk' => count($risks) > 0,
            'risks' => $risks,
            'overall_score' => $health['overall_score'],
            'status' => $health['health_status']
        ];
    }
}