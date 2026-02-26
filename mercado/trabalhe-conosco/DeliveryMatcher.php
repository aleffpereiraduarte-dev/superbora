<?php
class DeliveryMatcher {
    private $pdo;
    
    private $vehicleConfig = [
        'bicicleta' => ['max_distance_km' => 3, 'max_weight_kg' => 8, 'max_items' => 10, 'max_bags' => 2, 'speed_kmh' => 15, 'base_fee' => 5.00, 'per_km_fee' => 1.50, 'priority' => 1, 'can_carry_frozen' => false, 'can_carry_drinks' => false, 'max_drink_units' => 0],
        'moto' => ['max_distance_km' => 15, 'max_weight_kg' => 20, 'max_items' => 25, 'max_bags' => 4, 'speed_kmh' => 35, 'base_fee' => 7.00, 'per_km_fee' => 2.00, 'priority' => 2, 'can_carry_frozen' => true, 'can_carry_drinks' => true, 'max_drink_units' => 6],
        'carro' => ['max_distance_km' => 30, 'max_weight_kg' => 100, 'max_items' => 100, 'max_bags' => 15, 'speed_kmh' => 40, 'base_fee' => 12.00, 'per_km_fee' => 2.50, 'priority' => 3, 'can_carry_frozen' => true, 'can_carry_drinks' => true, 'max_drink_units' => 48],
        'van' => ['max_distance_km' => 50, 'max_weight_kg' => 500, 'max_items' => 500, 'max_bags' => 50, 'speed_kmh' => 35, 'base_fee' => 25.00, 'per_km_fee' => 3.50, 'priority' => 4, 'can_carry_frozen' => true, 'can_carry_drinks' => true, 'max_drink_units' => 200],
    ];
    
    private $categoryWeights = ['bebidas' => 1.5, 'agua' => 1.0, 'cerveja' => 0.5, 'refrigerante' => 2.0, 'limpeza' => 1.0, 'higiene' => 0.3, 'mercearia' => 0.5, 'hortifruti' => 0.3, 'congelados' => 0.5, 'laticinios' => 0.4, 'carnes' => 0.8, 'padaria' => 0.2, 'pet' => 2.0, 'bebe' => 0.8, 'default' => 0.4];
    
    public function __construct($pdo) { $this->pdo = $pdo; }
    
    public function analyzeOrder($order_id) {
        $stmt = $this->pdo->prepare("SELECT op.product_id, op.quantity, op.name, p.weight, (SELECT GROUP_CONCAT(cd.name) FROM oc_product_to_category pc JOIN oc_category_description cd ON pc.category_id = cd.category_id WHERE pc.product_id = op.product_id AND cd.language_id = 1) as categories FROM oc_order_product op LEFT JOIN oc_product p ON op.product_id = p.product_id WHERE op.order_id = ?");
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $this->pdo->prepare("SELECT * FROM oc_order WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $analysis = ['order_id' => $order_id, 'total_items' => 0, 'total_quantity' => 0, 'estimated_weight_kg' => 0, 'estimated_bags' => 0, 'has_drinks' => false, 'drink_units' => 0, 'has_frozen' => false, 'has_fragile' => false, 'distance_km' => 0];
        
        foreach ($items as $item) {
            $qty = (int)$item['quantity'];
            $analysis['total_items']++;
            $analysis['total_quantity'] += $qty;
            
            $weight = (float)($item['weight'] ?? 0);
            if ($weight <= 0) $weight = $this->estimateWeight($item['name'], $item['categories'] ?? '');
            $analysis['estimated_weight_kg'] += $weight * $qty;
            
            $cats = strtolower($item['categories'] ?? '');
            if ($this->containsAny($cats, ['bebida', 'água', 'refrigerante', 'cerveja', 'suco'])) { $analysis['has_drinks'] = true; $analysis['drink_units'] += $qty; }
            if ($this->containsAny($cats, ['congelado', 'frozen', 'sorvete'])) $analysis['has_frozen'] = true;
            if ($this->containsAny($item['name'], ['vidro', 'ovos', 'vinho'])) $analysis['has_fragile'] = true;
        }
        
        $analysis['estimated_bags'] = max(ceil($analysis['total_quantity'] / 5), ceil($analysis['estimated_weight_kg'] / 3));
        $analysis['distance_km'] = $this->calculateDistance($order);
        
        return $analysis;
    }
    
    public function getRequiredVehicleTypes($analysis) {
        $suitable = [];
        foreach ($this->vehicleConfig as $type => $config) {
            if ($analysis['distance_km'] > $config['max_distance_km']) continue;
            if ($analysis['estimated_weight_kg'] > $config['max_weight_kg']) continue;
            if ($analysis['total_quantity'] > $config['max_items']) continue;
            if ($analysis['estimated_bags'] > $config['max_bags']) continue;
            if ($analysis['has_drinks'] && !$config['can_carry_drinks']) continue;
            if ($analysis['has_drinks'] && $analysis['drink_units'] > $config['max_drink_units']) continue;
            if ($analysis['has_frozen'] && !$config['can_carry_frozen']) continue;
            $suitable[] = $type;
        }
        usort($suitable, fn($a, $b) => $this->vehicleConfig[$a]['priority'] - $this->vehicleConfig[$b]['priority']);
        return ['suitable_vehicles' => $suitable, 'recommended' => $suitable[0] ?? 'carro'];
    }
    
    public function calculateDeliveryFee($vehicleType, $distanceKm) {
        $config = $this->vehicleConfig[$vehicleType] ?? $this->vehicleConfig['moto'];
        $fee = ceil(($config['base_fee'] + ($config['per_km_fee'] * $distanceKm)) * 2) / 2;
        return ['vehicle_type' => $vehicleType, 'distance_km' => $distanceKm, 'total_fee' => $fee, 'estimated_time_min' => ceil(($distanceKm / $config['speed_kmh']) * 60) + 10];
    }
    
    public function findAvailableDrivers($analysis, $vehicleTypes, $warehouseId = null) {
        $types = is_array($vehicleTypes) ? $vehicleTypes : [$vehicleTypes];
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $sql = "SELECT worker_id, name, phone, vehicle_type, rating, total_deliveries FROM om_workers WHERE status = 'approved' AND is_online = 1 AND is_delivery = 1 AND vehicle_type IN ($placeholders) AND current_order_id IS NULL ORDER BY rating DESC LIMIT 20";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($types);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function createDeliveryOffer($orderId, $analysis, $vehicleReq) {
        $fee = $this->calculateDeliveryFee($vehicleReq['recommended'], $analysis['distance_km']);
        $stmt = $this->pdo->prepare("INSERT INTO om_delivery_offers (order_id, required_vehicle_types, recommended_vehicle, distance_km, estimated_weight_kg, estimated_bags, has_drinks, has_frozen, has_fragile, delivery_fee, estimated_time_min, status, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW())");
        $stmt->execute([$orderId, json_encode($vehicleReq['suitable_vehicles']), $vehicleReq['recommended'], $analysis['distance_km'], $analysis['estimated_weight_kg'], $analysis['estimated_bags'], $analysis['has_drinks'] ? 1 : 0, $analysis['has_frozen'] ? 1 : 0, $analysis['has_fragile'] ? 1 : 0, $fee['total_fee'], $fee['estimated_time_min']]);
        return ['offer_id' => $this->pdo->lastInsertId(), 'fee' => $fee];
    }
    
    public function dispatchOffers($offerId, $drivers) {
        foreach ($drivers as $driver) {
            $this->pdo->prepare("INSERT INTO om_delivery_notifications (offer_id, worker_id, status, sent_at) VALUES (?, ?, 'sent', NOW())")->execute([$offerId, $driver['worker_id']]);
        }
        return count($drivers);
    }
    
    private function estimateWeight($name, $category) {
        $name = strtolower($name ?? ''); $cat = strtolower($category ?? '');
        if (strpos($name, 'fardo') !== false) return 6.0;
        if (strpos($name, '2l') !== false) return 2.0;
        if (strpos($name, 'galão') !== false) return 5.0;
        foreach ($this->categoryWeights as $key => $weight) { if (strpos($cat, $key) !== false || strpos($name, $key) !== false) return $weight; }
        return $this->categoryWeights['default'];
    }
    
    private function containsAny($haystack, $needles) { $h = strtolower($haystack); foreach ($needles as $n) { if (strpos($h, strtolower($n)) !== false) return true; } return false; }
    
    private function calculateDistance($order) {
        $postcode = preg_replace('/\D/', '', $order['shipping_postcode'] ?? '');
        $stmt = $this->pdo->prepare("SELECT ABS(CAST(SUBSTRING(postcode, 1, 5) AS SIGNED) - CAST(SUBSTRING(?, 1, 5) AS SIGNED)) as cep_diff FROM om_warehouses WHERE status = 'active' ORDER BY cep_diff LIMIT 1");
        $stmt->execute([$postcode]);
        $w = $stmt->fetch();
        return round(max(1, min(30, ($w['cep_diff'] ?? 5000) / 500)), 1);
    }
}