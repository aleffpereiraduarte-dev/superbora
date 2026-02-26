<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * HOTSPOTS HELPER - Áreas de alta demanda
 * Baseado em: DoorDash Hotspots, Rappi Mapa de Demanda
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class HotspotsHelper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Obter todos os hotspots ativos
     */
    public function getActiveHotspots() {
        $stmt = $this->pdo->query("
            SELECT h.*, 
                   CASE 
                       WHEN h.demand_level = 'very_high' THEN 4
                       WHEN h.demand_level = 'high' THEN 3
                       WHEN h.demand_level = 'medium' THEN 2
                       ELSE 1
                   END as priority
            FROM om_hotspots h
            ORDER BY priority DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter hotspots próximos a uma localização
     */
    public function getNearbyHotspots($lat, $lng, $radiusKm = 5) {
        $stmt = $this->pdo->prepare("
            SELECT h.*,
                   (6371 * acos(cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat)))) AS distance_km
            FROM om_hotspots h
            HAVING distance_km <= ?
            ORDER BY demand_level DESC, distance_km ASC
        ");
        $stmt->execute([$lat, $lng, $lat, $radiusKm]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Atualizar demanda de um hotspot baseado em pedidos
     */
    public function updateHotspotDemand($hotspotId) {
        // Contar pedidos ativos na área
        $stmt = $this->pdo->prepare("
            SELECT h.*, 
                   COUNT(DISTINCT o.order_id) as active_orders,
                   COUNT(DISTINCT w.worker_id) as active_workers
            FROM om_hotspots h
            LEFT JOIN om_market_orders o ON (
                6371 * acos(cos(radians(h.lat)) * cos(radians(o.store_lat)) * cos(radians(o.store_lng) - radians(h.lng)) + sin(radians(h.lat)) * sin(radians(o.store_lat)))
            ) <= (h.radius_meters / 1000) AND o.status IN ('pending', 'accepted', 'shopping')
            LEFT JOIN om_market_workers w ON w.is_online = 1 AND (
                6371 * acos(cos(radians(h.lat)) * cos(radians(w.current_lat)) * cos(radians(w.current_lng) - radians(h.lng)) + sin(radians(h.lat)) * sin(radians(w.current_lat)))
            ) <= (h.radius_meters / 1000)
            WHERE h.hotspot_id = ?
            GROUP BY h.hotspot_id
        ");
        $stmt->execute([$hotspotId]);
        $data = $stmt->fetch();
        
        if (!$data) return false;
        
        // Calcular nível de demanda
        $ratio = $data['active_workers'] > 0 ? $data['active_orders'] / $data['active_workers'] : $data['active_orders'];
        
        $demandLevel = 'low';
        $waitMinutes = 30;
        
        if ($ratio >= 3) {
            $demandLevel = 'very_high';
            $waitMinutes = 5;
        } elseif ($ratio >= 2) {
            $demandLevel = 'high';
            $waitMinutes = 10;
        } elseif ($ratio >= 1) {
            $demandLevel = 'medium';
            $waitMinutes = 15;
        }
        
        $this->pdo->prepare("
            UPDATE om_hotspots 
            SET demand_level = ?, active_orders = ?, active_workers = ?, estimated_wait_minutes = ?
            WHERE hotspot_id = ?
        ")->execute([$demandLevel, $data['active_orders'], $data['active_workers'], $waitMinutes, $hotspotId]);
        
        return $demandLevel;
    }
    
    /**
     * Ativar bônus em hotspot
     */
    public function activateHotspotBonus($hotspotId, $bonusAmount) {
        $this->pdo->prepare("
            UPDATE om_hotspots SET bonus_active = 1, bonus_amount = ? WHERE hotspot_id = ?
        ")->execute([$bonusAmount, $hotspotId]);
        return true;
    }
    
    /**
     * Criar hotspot
     */
    public function createHotspot($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO om_hotspots (name, lat, lng, radius_meters)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['lat'],
            $data['lng'],
            $data['radius'] ?? 500
        ]);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Obter mapa de calor para o app
     */
    public function getHeatmapData() {
        $hotspots = $this->getActiveHotspots();
        
        return array_map(function($h) {
            return [
                'lat' => (float)$h['lat'],
                'lng' => (float)$h['lng'],
                'weight' => $h['priority'],
                'radius' => $h['radius_meters'],
                'demand' => $h['demand_level'],
                'bonus' => $h['bonus_active'] ? $h['bonus_amount'] : 0,
                'wait' => $h['estimated_wait_minutes']
            ];
        }, $hotspots);
    }
}