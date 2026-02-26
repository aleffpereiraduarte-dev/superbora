<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * BATCHING HELPER - Sistema de lotes de pedidos
 * Baseado em: Instacart Multi-store Batching, Queued Batches
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class BatchingHelper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Criar batch de pedidos
     */
    public function createBatch($orderIds, $basePay = null) {
        $this->pdo->beginTransaction();
        
        try {
            // Calcular totais
            $totals = $this->calculateBatchTotals($orderIds);
            
            $basePay = $basePay ?: $this->calculateBasePay($totals);
            $heavyPay = $totals['heavy_pay'];
            $totalTips = $totals['tips'];
            $totalEarnings = $basePay + $heavyPay + $totalTips;
            
            // Criar batch
            $batchCode = 'B' . strtoupper(substr(uniqid(), -8));
            
            $stmt = $this->pdo->prepare("
                INSERT INTO om_batches 
                (batch_code, total_items, total_weight_kg, total_distance_km, estimated_time_minutes,
                 base_pay, heavy_pay, total_tips, total_earnings, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
            ");
            $stmt->execute([
                $batchCode,
                $totals['items'],
                $totals['weight'],
                $totals['distance'],
                $totals['time'],
                $basePay,
                $heavyPay,
                $totalTips,
                $totalEarnings
            ]);
            $batchId = $this->pdo->lastInsertId();
            
            // Adicionar pedidos ao batch
            $seq = 1;
            foreach ($orderIds as $orderId) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO om_batch_orders (batch_id, order_id, sequence)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$batchId, $orderId, $seq++]);
                
                // Atualizar pedido
                $this->pdo->prepare("UPDATE om_market_orders SET batch_id = ? WHERE order_id = ?")
                          ->execute([$batchId, $orderId]);
            }
            
            $this->pdo->commit();
            
            return [
                'batch_id' => $batchId,
                'batch_code' => $batchCode,
                'orders_count' => count($orderIds),
                'total_earnings' => $totalEarnings,
                'totals' => $totals
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return false;
        }
    }
    
    /**
     * Calcular totais do batch
     */
    private function calculateBatchTotals($orderIds) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        
        $stmt = $this->pdo->prepare("
            SELECT 
                SUM(items_count) as items,
                SUM(total_weight) as weight,
                SUM(distance_km) as distance,
                SUM(estimated_minutes) as time,
                SUM(tip_amount) as tips
            FROM om_market_orders 
            WHERE order_id IN ($placeholders)
        ");
        $stmt->execute($orderIds);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Heavy pay
        require_once __DIR__ . '/EarningsHelper.php';
        $earningsHelper = new EarningsHelper($this->pdo);
        $totals['heavy_pay'] = $earningsHelper->calculateHeavyPay($totals['weight'] ?? 0, $totals['items'] ?? 0);
        
        return $totals;
    }
    
    /**
     * Calcular pagamento base
     */
    private function calculateBasePay($totals) {
        $basePay = 7.00; // Mínimo
        
        // Adicionar por distância
        $basePay += ($totals['distance'] ?? 0) * 1.50;
        
        // Adicionar por tempo estimado
        $basePay += (($totals['time'] ?? 0) / 60) * 5.00;
        
        return max($basePay, 7.00);
    }
    
    /**
     * Obter batches disponíveis para worker
     */
    public function getAvailableBatches($workerId, $lat = null, $lng = null) {
        // Buscar tier do worker para prioridade
        $stmt = $this->pdo->prepare("
            SELECT t.priority_boost, t.tier_level 
            FROM om_market_workers w
            JOIN om_worker_tiers t ON w.tier_id = t.tier_id
            WHERE w.worker_id = ?
        ");
        $stmt->execute([$workerId]);
        $workerTier = $stmt->fetch();
        
        $priorityBoost = $workerTier['priority_boost'] ?? 0;
        
        // Buscar batches disponíveis
        $stmt = $this->pdo->prepare("
            SELECT b.*,
                   GROUP_CONCAT(bo.order_id) as order_ids,
                   COUNT(bo.order_id) as orders_count
            FROM om_batches b
            LEFT JOIN om_batch_orders bo ON b.batch_id = bo.batch_id
            WHERE b.status = 'available' 
              AND (b.expires_at IS NULL OR b.expires_at > DATE_ADD(NOW(), INTERVAL ? SECOND))
            GROUP BY b.batch_id
            ORDER BY b.priority_level DESC, b.total_earnings DESC
            LIMIT 10
        ");
        $stmt->execute([$priorityBoost]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Aceitar batch
     */
    public function acceptBatch($workerId, $batchId) {
        $stmt = $this->pdo->prepare("SELECT * FROM om_batches WHERE batch_id = ? AND status = 'available'");
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch();
        
        if (!$batch) return ['success' => false, 'error' => 'Batch não disponível'];
        
        $this->pdo->beginTransaction();
        try {
            // Atribuir batch
            $this->pdo->prepare("
                UPDATE om_batches SET status = 'assigned', worker_id = ?, assigned_at = NOW() WHERE batch_id = ?
            ")->execute([$workerId, $batchId]);
            
            // Atribuir pedidos
            $this->pdo->prepare("
                UPDATE om_market_orders SET shopper_id = ?, status = 'accepted' 
                WHERE batch_id = ?
            ")->execute([$workerId, $batchId]);
            
            $this->pdo->commit();
            return ['success' => true, 'batch' => $batch];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Queued Batches - Aceitar próximo antes de terminar atual (Instacart)
     */
    public function queueNextBatch($workerId, $batchId) {
        // Verificar se já tem batch na fila
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM om_queued_batches WHERE worker_id = ?");
        $stmt->execute([$workerId]);
        if ($stmt->fetchColumn() > 0) {
            return ['success' => false, 'error' => 'Já tem um batch na fila'];
        }
        
        // Verificar se batch está disponível
        $stmt = $this->pdo->prepare("SELECT * FROM om_batches WHERE batch_id = ? AND status = 'available'");
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch();
        
        if (!$batch) return ['success' => false, 'error' => 'Batch não disponível'];
        
        // Adicionar à fila
        $stmt = $this->pdo->prepare("
            INSERT INTO om_queued_batches (worker_id, batch_id, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
        ");
        $stmt->execute([$workerId, $batchId]);
        
        // Reservar batch
        $this->pdo->prepare("UPDATE om_batches SET status = 'reserved' WHERE batch_id = ?")
                  ->execute([$batchId]);
        
        return ['success' => true, 'batch' => $batch];
    }
    
    /**
     * Iniciar batch da fila
     */
    public function startQueuedBatch($workerId) {
        $stmt = $this->pdo->prepare("
            SELECT qb.*, b.* 
            FROM om_queued_batches qb
            JOIN om_batches b ON qb.batch_id = b.batch_id
            WHERE qb.worker_id = ? AND qb.expires_at > NOW()
            ORDER BY qb.queued_at ASC LIMIT 1
        ");
        $stmt->execute([$workerId]);
        $queued = $stmt->fetch();
        
        if (!$queued) return null;
        
        // Remover da fila e aceitar
        $this->pdo->prepare("DELETE FROM om_queued_batches WHERE worker_id = ? AND batch_id = ?")
                  ->execute([$workerId, $queued['batch_id']]);
        
        return $this->acceptBatch($workerId, $queued['batch_id']);
    }
    
    /**
     * Completar batch
     */
    public function completeBatch($batchId) {
        $stmt = $this->pdo->prepare("SELECT * FROM om_batches WHERE batch_id = ?");
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch();
        
        if (!$batch) return false;
        
        $this->pdo->beginTransaction();
        try {
            // Atualizar batch
            $this->pdo->prepare("
                UPDATE om_batches SET status = 'completed', completed_at = NOW() WHERE batch_id = ?
            ")->execute([$batchId]);
            
            // Creditar worker
            $this->pdo->prepare("
                UPDATE om_market_workers 
                SET available_balance = available_balance + ?,
                    total_earnings = total_earnings + ?,
                    total_tips = total_tips + ?
                WHERE worker_id = ?
            ")->execute([
                $batch['total_earnings'],
                $batch['total_earnings'],
                $batch['total_tips'],
                $batch['worker_id']
            ]);
            
            $this->pdo->commit();
            return $batch;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return false;
        }
    }
}