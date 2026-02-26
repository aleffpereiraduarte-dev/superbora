<?php
/**
 * 🧠 VehicleAI - Sistema Inteligente de Cálculo de Veículo
 * VERSÃO CORRIGIDA
 */

class VehicleAI {
    private $pdo;
    
    const MOTO_MAX_WEIGHT = 15;
    const MOTO_MAX_VOLUME = 50000;
    const MOTO_MAX_ITEMS = 30;
    const MOTO_MAX_BOTTLES = 12;
    const SCORE_THRESHOLD = 60;
    
    private $heavy_pattern;
    private $fragile_pattern;
    private $large_pattern;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        $heavy_keywords = ["bebida", "água", "refrigerante", "cerveja", "suco", "leite", "óleo", "azeite", "arroz", "açúcar", "farinha", "sal"];
        $fragile_keywords = ["vidro", "vinho", "champagne", "espumante", "ovos", "ovo", "cerâmica", "porcelana"];
        $large_keywords = ["fardo", "pack", "caixa", "engradado", "galão", "bombona", "colchão", "móvel", "eletrodoméstico"];
        
        $this->heavy_pattern = '/(' . implode('|', array_map('preg_quote', $heavy_keywords)) . ')/i';
        $this->fragile_pattern = '/(' . implode('|', array_map('preg_quote', $fragile_keywords)) . ')/i';
        $this->large_pattern = '/(' . implode('|', array_map('preg_quote', $large_keywords)) . ')/i';
    }

    private function getDefaultResponse() {
        return [
            "vehicle" => "moto",
            "score" => 0,
            "threshold" => self::SCORE_THRESHOLD,
            "reasons" => [],
            "details" => [
                "total_items" => 0,
                "total_weight" => 0,
                "total_volume" => 0,
                "bottles" => 0,
                "fragile" => 0,
                "large" => 0
            ]
        ];
    }
    
    public function calcularVeiculo($order_id) {
        // Validação de entrada
        if (!is_numeric($order_id) || $order_id <= 0) {
            throw new InvalidArgumentException('Order ID deve ser um número positivo');
        }
        
        $response = $this->getDefaultResponse();
        
        $stmt = $this->pdo->prepare("
            SELECT op.product_id, op.name, op.quantity, op.price,
                   COALESCE(p.weight, 0.5) as weight,
                   COALESCE(p.length, 10) as length,
                   COALESCE(p.width, 10) as width,
                   COALESCE(p.height, 10) as height
            FROM om_market_order_items op
            LEFT JOIN oc_product p ON op.product_id = p.product_id
            WHERE op.order_id = ?
        ");
        $stmt->execute([intval($order_id)]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($products)) {
            $response["reasons"][] = "Pedido sem produtos";
            return $response;
        }
        
        $score = 0;
        $reasons = [];
        $total_weight = 0;
        $total_volume = 0;
        $total_items = 0;
        $bottles_count = 0;
        $fragile_count = 0;
        $large_count = 0;
        
        foreach ($products as $p) {
            $qty = intval($p["quantity"]);
            $total_items += $qty;
            $total_weight += floatval($p["weight"]) * $qty;
            $total_volume += floatval($p["length"]) * floatval($p["width"]) * floatval($p["height"]) * $qty;
            
            $name_lower = mb_strtolower($p["name"]);
            
            // Usar regex patterns definidos no construtor
            if (preg_match($this->heavy_pattern, $name_lower)) {
                $bottles_count += $qty;
            }

            if (preg_match($this->fragile_pattern, $name_lower)) {
                $fragile_count += $qty;
            }

            if (preg_match($this->large_pattern, $name_lower)) {
                $large_count += $qty;
            }
        }
        
        $response["details"] = [
            "total_items" => $total_items,
            "total_weight" => round($total_weight, 2),
            "total_volume" => round($total_volume, 0),
            "bottles" => $bottles_count,
            "fragile" => $fragile_count,
            "large" => $large_count
        ];
        
        if ($total_weight > self::MOTO_MAX_WEIGHT) {
            $score += 50;
            $reasons[] = "Peso: " . round($total_weight, 1) . "kg";
        } elseif ($total_weight > self::MOTO_MAX_WEIGHT * 0.7) {
            $score += 20;
        }
        
        if ($total_volume > self::MOTO_MAX_VOLUME) {
            $score += 40;
            $reasons[] = "Volume grande";
        }
        
        if ($total_items > self::MOTO_MAX_ITEMS) {
            $score += 25;
            $reasons[] = "Muitos itens: $total_items";
        }
        
        if ($bottles_count > self::MOTO_MAX_BOTTLES) {
            $score += 30;
            $reasons[] = "Muitas bebidas: $bottles_count";
        }
        
        if ($fragile_count > 0) {
            $score += $fragile_count * 8;
            $reasons[] = "Frágeis: $fragile_count";
        }
        
        if ($large_count > 0) {
            $score += $large_count * 15;
            $reasons[] = "Grandes: $large_count";
        }
        
        if (empty($reasons)) {
            $reasons[] = "Pedido leve - ideal para moto";
        }
        
        $response["score"] = $score;
        $response["reasons"] = $reasons;
        $response["vehicle"] = $score >= self::SCORE_THRESHOLD ? "carro" : "moto";
        
        return $response;
    }
    
    public function calcularESalvar($order_id) {
        $result = $this->calcularVeiculo($order_id);
        $reason_text = implode("; ", $result["reasons"]);
        
        $stmt = $this->pdo->prepare("
            UPDATE om_market_orders 
            SET vehicle_required = ?, vehicle_score = ?, vehicle_reason = ?, vehicle_auto_calculated = 1
            WHERE order_id = ?
        ");
        
        try {
            $stmt->execute([$result["vehicle"], $result["score"], $reason_text, intval($order_id)]);
            if ($stmt->rowCount() === 0) {
                throw new Exception('Nenhuma linha foi atualizada. Order ID pode não existir.');
            }
        } catch (PDOException $e) {
            error_log('Erro ao atualizar vehicle calculation: ' . $e->getMessage());
            throw new Exception('Erro interno ao salvar cálculo do veículo');
        }
        
        return $result;
    }
}
