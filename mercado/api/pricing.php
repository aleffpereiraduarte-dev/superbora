<?php
require_once dirname(__DIR__) . '/config/database.php';
/**
 * ==============================================================================
 * ONEMUNDO MARKET - API DE PRECIFICACAO COM IA
 * ==============================================================================
 *
 * Motor inteligente que calcula precos de venda automaticamente baseado em:
 * - Preco real do mercado
 * - Media regional e nacional
 * - Demanda historica
 * - Categoria do produto
 * - Concorrencia
 * - Configuracoes de margem
 *
 * ENDPOINTS:
 * - POST /calculate     -> Calcula preco de um produto
 * - POST /bulk          -> Calcula precos em lote
 * - POST /recalculate   -> Recalcula todos os precos de um mercado
 * - GET  /stats         -> Estatisticas da IA
 * - GET  /config        -> Configuracoes atuais
 * - POST /config        -> Atualizar configuracoes
 *
 * ==============================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==============================================================================
// CONEXAO
// ==============================================================================

try {
    $pdo = getPDO();
} catch (Exception $e) {
    jsonResponse(['error' => 'Database connection failed'], 500);
}

// ==============================================================================
// FUNCOES AUXILIARES
// ==============================================================================

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}

// ==============================================================================
// CLASSE: MOTOR DE IA PARA PRECIFICACAO
// ==============================================================================

class PricingAI {
    private $pdo;
    private $config = [];

    // Mapeamento de regioes por estado
    private $regions = [
        'Norte' => ['AC', 'AP', 'AM', 'PA', 'RO', 'RR', 'TO'],
        'Nordeste' => ['AL', 'BA', 'CE', 'MA', 'PB', 'PE', 'PI', 'RN', 'SE'],
        'Centro-Oeste' => ['DF', 'GO', 'MT', 'MS'],
        'Sudeste' => ['ES', 'MG', 'RJ', 'SP'],
        'Sul' => ['PR', 'RS', 'SC']
    ];

    // Multiplicador regional (custo de vida)
    private $regionalMultiplier = [
        'Norte' => 1.15,      // +15% (logistica mais cara)
        'Nordeste' => 1.05,   // +5%
        'Centro-Oeste' => 1.08, // +8%
        'Sudeste' => 1.00,    // Base
        'Sul' => 1.02         // +2%
    ];

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadConfig();
    }

    /**
     * Carrega configuracoes da IA do banco
     */
    private function loadConfig() {
        $stmt = $this->pdo->query("SELECT config_key, config_value, config_type FROM om_market_ai_config");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $value = $row['config_value'];
                switch ($row['config_type']) {
                    case 'number':
                    case 'percent':
                        $value = floatval($value);
                        break;
                    case 'boolean':
                        $value = $value === '1' || $value === 'true';
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                }
                $this->config[$row['config_key']] = $value;
            }
        }

        // Valores padrao se nao existirem
        $defaults = [
            'margin_min_global' => 8,
            'margin_max_global' => 30,
            'margin_default' => 15,
            'consider_regional_price' => true,
            'consider_national_price' => true,
            'consider_demand' => true,
            'consider_competition' => true,
            'weight_regional' => 0.3,
            'weight_national' => 0.2,
            'weight_demand' => 0.25,
            'weight_competition' => 0.25,
            'auto_approve_margin_below' => 20,
            'alert_margin_below' => 10
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($this->config[$key])) {
                $this->config[$key] = $value;
            }
        }
    }

    /**
     * Obtem a regiao de um estado
     */
    private function getRegion($state) {
        foreach ($this->regions as $region => $states) {
            if (in_array(strtoupper($state), $states)) {
                return $region;
            }
        }
        return 'Sudeste'; // Default
    }

    /**
     * Calcula preco de venda para um produto
     */
    public function calculatePrice($productId, $partnerId) {
        // 1. Buscar dados do produto e preco real
        $sql = "SELECT
                    pb.product_id, pb.name, pb.category_id, pb.brand,
                    pp.price as price_real, pp.price_promo,
                    pp.promo_start, pp.promo_end,
                    p.state, p.city,
                    mc.default_margin_percent, mc.min_margin_percent, mc.max_margin_percent,
                    mc.name as category_name
                FROM om_market_products_base pb
                JOIN om_market_products_price pp ON pb.product_id = pp.product_id
                JOIN om_market_partners p ON pp.partner_id = p.partner_id
                LEFT JOIN om_market_categories mc ON pb.category_id = mc.category_id
                WHERE pb.product_id = ? AND pp.partner_id = ?";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$productId, $partnerId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($products) === 0) {
            return ['error' => 'Produto nao encontrado para este mercado'];
        }

        $product = $products[0];
        $priceReal = floatval($product['price_real']);

        // Verificar se tem promocao ativa no mercado
        $hasPromo = false;
        if ($product['price_promo'] && $product['promo_start'] && $product['promo_end']) {
            $today = date('Y-m-d');
            if ($today >= $product['promo_start'] && $today <= $product['promo_end']) {
                $priceReal = floatval($product['price_promo']);
                $hasPromo = true;
            }
        }

        // 2. Coletar fatores para calculo
        $factors = [];
        $scores = [];

        // Fator 1: Margem da categoria
        $categoryMargin = floatval($product['default_margin_percent'] ?? $this->config['margin_default']);
        $categoryMin = floatval($product['min_margin_percent'] ?? $this->config['margin_min_global']);
        $categoryMax = floatval($product['max_margin_percent'] ?? $this->config['margin_max_global']);

        $factors['category'] = [
            'name' => $product['category_name'] ?? 'Sem categoria',
            'default_margin' => $categoryMargin,
            'min' => $categoryMin,
            'max' => $categoryMax
        ];

        // Fator 2: Preco medio regional
        $region = $this->getRegion($product['state']);
        $regionalAvg = $this->getRegionalAverage($productId, $region);
        $factors['regional'] = [
            'region' => $region,
            'state' => $product['state'],
            'average_price' => $regionalAvg,
            'multiplier' => $this->regionalMultiplier[$region] ?? 1.0,
            'weight' => $this->config['weight_regional']
        ];

        // Fator 3: Preco medio nacional
        $nationalAvg = $this->getNationalAverage($productId);
        $factors['national'] = [
            'average_price' => $nationalAvg,
            'weight' => $this->config['weight_national']
        ];

        // Fator 4: Demanda historica
        $demand = $this->getDemandScore($productId);
        $factors['demand'] = [
            'score' => $demand['score'],
            'total_sold' => $demand['total_sold'],
            'trend' => $demand['trend'],
            'weight' => $this->config['weight_demand']
        ];

        // Fator 5: Competitividade
        $competition = $this->getCompetitionFactor($productId, $partnerId, $priceReal);
        $factors['competition'] = [
            'position' => $competition['position'],
            'total_partners' => $competition['total'],
            'is_cheapest' => $competition['is_cheapest'],
            'price_diff_percent' => $competition['diff_percent'],
            'weight' => $this->config['weight_competition']
        ];

        // 3. ALGORITMO DE PRECIFICACAO

        // Base: margem da categoria
        $targetMargin = $categoryMargin;

        // Ajuste regional
        if ($this->config['consider_regional_price'] && $regionalAvg > 0) {
            $regionalMultiplier = $this->regionalMultiplier[$region] ?? 1.0;
            // Se preco real esta abaixo da media regional, podemos aumentar margem
            if ($priceReal < $regionalAvg * 0.9) {
                $targetMargin += 3; // +3% se esta muito abaixo da media
            } elseif ($priceReal > $regionalAvg * 1.1) {
                $targetMargin -= 2; // -2% se esta acima da media
            }
        }

        // Ajuste por demanda
        if ($this->config['consider_demand']) {
            if ($demand['score'] > 80) {
                // Alta demanda = pode ter margem maior
                $targetMargin += 2;
            } elseif ($demand['score'] < 30) {
                // Baixa demanda = reduzir margem para vender mais
                $targetMargin -= 3;
            }
        }

        // Ajuste por competitividade
        if ($this->config['consider_competition']) {
            if ($competition['is_cheapest']) {
                // Ja somos os mais baratos, podemos aumentar um pouco
                $targetMargin += 1;
            } elseif ($competition['diff_percent'] > 10) {
                // Estamos muito mais caros que a media
                $targetMargin -= 2;
            }
        }

        // Garantir que margem esta dentro dos limites
        $targetMargin = max($categoryMin, min($categoryMax, $targetMargin));
        $targetMargin = max($this->config['margin_min_global'], min($this->config['margin_max_global'], $targetMargin));

        // 4. Calcular preco final
        $marginMultiplier = 1 + ($targetMargin / 100);
        $salePrice = round($priceReal * $marginMultiplier, 2);
        $marginValue = $salePrice - $priceReal;

        // 5. Score de confianca da IA (0-100)
        $confidenceScore = $this->calculateConfidence($factors);

        // 6. Preparar resultado
        $result = [
            'success' => true,
            'product_id' => $productId,
            'partner_id' => $partnerId,
            'product_name' => $product['name'],
            'pricing' => [
                'price_real' => $priceReal,
                'price_real_has_promo' => $hasPromo,
                'sale_price' => $salePrice,
                'margin_percent' => round($targetMargin, 2),
                'margin_value' => round($marginValue, 2),
                'profit_per_unit' => round($marginValue, 2)
            ],
            'ai_analysis' => [
                'confidence_score' => $confidenceScore,
                'factors' => $factors,
                'recommendation' => $this->getRecommendation($targetMargin, $confidenceScore),
                'alerts' => $this->getAlerts($targetMargin, $priceReal, $salePrice)
            ],
            'calculated_at' => date('Y-m-d H:i:s')
        ];

        return $result;
    }

    /**
     * Obtem media de preco regional
     */
    private function getRegionalAverage($productId, $region) {
        $states = $this->regions[$region] ?? [];
        if (empty($states)) return 0;

        $placeholders = implode(',', array_fill(0, count($states), '?'));

        $sql = "SELECT AVG(pp.price) as avg_price
                FROM om_market_products_price pp
                JOIN om_market_partners p ON pp.partner_id = p.partner_id
                WHERE pp.product_id = ? AND p.state IN ($placeholders) AND p.status = 'active'";

        $stmt = $this->pdo->prepare($sql);
        $params = array_merge([$productId], $states);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return floatval($result['avg_price'] ?? 0);
    }

    /**
     * Obtem media de preco nacional
     */
    private function getNationalAverage($productId) {
        $sql = "SELECT AVG(pp.price) as avg_price, COUNT(*) as total
                FROM om_market_products_price pp
                JOIN om_market_partners p ON pp.partner_id = p.partner_id
                WHERE pp.product_id = ? AND p.status = 'active'";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$productId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return floatval($result['avg_price'] ?? 0);
    }

    /**
     * Calcula score de demanda
     */
    private function getDemandScore($productId) {
        // Buscar historico de vendas dos ultimos 30 dias
        $sql = "SELECT COUNT(*) as orders, SUM(oi.quantity) as total_qty
                FROM om_market_order_items oi
                JOIN om_market_orders o ON oi.order_id = o.order_id
                WHERE oi.product_id = ?
                AND o.status NOT IN ('cancelled', 'refunded')
                AND o.date_added >= NOW() - INTERVAL '30 days'";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$productId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalSold = intval($result['total_qty'] ?? 0);

        // Score baseado em vendas (0-100)
        // 0 vendas = 20, 10 vendas = 50, 50+ vendas = 90
        $score = min(90, 20 + ($totalSold * 1.4));

        // Tendencia: comparar ultimos 15 dias com 15 dias anteriores
        $trend = 'stable';
        $sql2 = "SELECT
                    SUM(CASE WHEN o.date_added >= NOW() - INTERVAL '15 days' THEN oi.quantity ELSE 0 END) as recent,
                    SUM(CASE WHEN o.date_added < NOW() - INTERVAL '15 days' THEN oi.quantity ELSE 0 END) as older
                FROM om_market_order_items oi
                JOIN om_market_orders o ON oi.order_id = o.order_id
                WHERE oi.product_id = ?
                AND o.status NOT IN ('cancelled', 'refunded')
                AND o.date_added >= NOW() - INTERVAL '30 days'";

        $stmt2 = $this->pdo->prepare($sql2);
        $stmt2->execute([$productId]);
        $result2 = $stmt2->fetch(PDO::FETCH_ASSOC);

        $recent = intval($result2['recent'] ?? 0);
        $older = intval($result2['older'] ?? 0);

        if ($older > 0) {
            $change = (($recent - $older) / $older) * 100;
            if ($change > 20) $trend = 'up';
            elseif ($change < -20) $trend = 'down';
        }

        return [
            'score' => round($score),
            'total_sold' => $totalSold,
            'trend' => $trend
        ];
    }

    /**
     * Analisa fator de competitividade
     */
    private function getCompetitionFactor($productId, $partnerId, $currentPrice) {
        $sql = "SELECT pp.partner_id, pp.price
                FROM om_market_products_price pp
                JOIN om_market_partners p ON pp.partner_id = p.partner_id
                WHERE pp.product_id = ? AND p.status = 'active'
                ORDER BY pp.price ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $prices = [];
        $position = 0;
        $currentPosition = 0;

        foreach ($rows as $row) {
            $position++;
            $prices[] = floatval($row['price']);
            if ($row['partner_id'] == $partnerId) {
                $currentPosition = $position;
            }
        }

        $total = count($prices);
        $avgPrice = $total > 0 ? array_sum($prices) / $total : $currentPrice;
        $minPrice = $total > 0 ? min($prices) : $currentPrice;

        $diffPercent = $avgPrice > 0 ? (($currentPrice - $avgPrice) / $avgPrice) * 100 : 0;

        return [
            'position' => $currentPosition,
            'total' => $total,
            'is_cheapest' => $currentPosition === 1,
            'diff_percent' => round($diffPercent, 2),
            'avg_price' => round($avgPrice, 2),
            'min_price' => round($minPrice, 2)
        ];
    }

    /**
     * Calcula score de confianca
     */
    private function calculateConfidence($factors) {
        $score = 50; // Base

        // +20 se temos dados regionais
        if ($factors['regional']['average_price'] > 0) $score += 20;

        // +15 se temos dados nacionais
        if ($factors['national']['average_price'] > 0) $score += 15;

        // +10 se temos dados de demanda
        if ($factors['demand']['total_sold'] > 0) $score += 10;

        // +5 se temos multiplos parceiros para comparar
        if ($factors['competition']['total'] > 1) $score += 5;

        return min(100, $score);
    }

    /**
     * Gera recomendacao baseada na analise
     */
    private function getRecommendation($margin, $confidence) {
        if ($confidence >= 80 && $margin >= 12 && $margin <= 20) {
            return 'Preco otimizado com alta confianca. Aprovacao automatica recomendada.';
        } elseif ($confidence >= 60) {
            return 'Preco calculado com boa base de dados. Revisar periodicamente.';
        } elseif ($margin < 10) {
            return 'Margem baixa. Considere ajustar categoria ou revisar manualmente.';
        } else {
            return 'Poucos dados disponiveis. Monitorar desempenho nas proximas semanas.';
        }
    }

    /**
     * Gera alertas se necessario
     */
    private function getAlerts($margin, $priceReal, $salePrice) {
        $alerts = [];

        if ($margin < $this->config['alert_margin_below']) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "Margem abaixo de {$this->config['alert_margin_below']}%"
            ];
        }

        if ($margin < $this->config['margin_min_global']) {
            $alerts[] = [
                'type' => 'error',
                'message' => 'Margem abaixo do minimo global permitido'
            ];
        }

        if ($salePrice < $priceReal) {
            $alerts[] = [
                'type' => 'critical',
                'message' => 'ERRO: Preco de venda menor que preco real!'
            ];
        }

        return $alerts;
    }

    /**
     * Salva o preco calculado no banco
     */
    public function savePrice($productId, $partnerId, $calculation) {
        if (!isset($calculation['pricing'])) {
            return false;
        }

        $pricing = $calculation['pricing'];
        $ai = $calculation['ai_analysis'];
        $factorsJson = json_encode($ai['factors']);

        if (isPostgreSQL()) {
            $sql = "INSERT INTO om_market_products_sale
                    (product_id, partner_id, sale_price, source_price, margin_percent, margin_value,
                     ai_score, ai_factors, ai_calculated_at, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), '1')
                    ON CONFLICT (product_id, partner_id) DO UPDATE SET
                        sale_price = EXCLUDED.sale_price,
                        source_price = EXCLUDED.source_price,
                        margin_percent = EXCLUDED.margin_percent,
                        margin_value = EXCLUDED.margin_value,
                        ai_score = EXCLUDED.ai_score,
                        ai_factors = EXCLUDED.ai_factors,
                        ai_calculated_at = NOW(),
                        date_modified = NOW()";
        } else {
            $sql = "INSERT INTO om_market_products_sale
                    (product_id, partner_id, sale_price, source_price, margin_percent, margin_value,
                     ai_score, ai_factors, ai_calculated_at, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
                    ON DUPLICATE KEY UPDATE
                        sale_price = VALUES(sale_price),
                        source_price = VALUES(source_price),
                        margin_percent = VALUES(margin_percent),
                        margin_value = VALUES(margin_value),
                        ai_score = VALUES(ai_score),
                        ai_factors = VALUES(ai_factors),
                        ai_calculated_at = NOW(),
                        date_modified = NOW()";
        }

        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute([
            $productId,
            $partnerId,
            $pricing['sale_price'],
            $pricing['price_real'],
            $pricing['margin_percent'],
            $pricing['margin_value'],
            $ai['confidence_score'],
            $factorsJson
        ]);
    }

    /**
     * Recalcula todos os precos de um mercado
     */
    public function recalculatePartner($partnerId) {
        $stmt = $this->pdo->prepare("SELECT product_id FROM om_market_products_price WHERE partner_id = ?");
        $stmt->execute([$partnerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $processed = 0;
        $errors = 0;
        $results = [];

        foreach ($rows as $row) {
            $calc = $this->calculatePrice($row['product_id'], $partnerId);
            if (isset($calc['success']) && $calc['success']) {
                if ($this->savePrice($row['product_id'], $partnerId, $calc)) {
                    $processed++;
                    $results[] = [
                        'product_id' => $row['product_id'],
                        'sale_price' => $calc['pricing']['sale_price'],
                        'margin' => $calc['pricing']['margin_percent']
                    ];
                } else {
                    $errors++;
                }
            } else {
                $errors++;
            }
        }

        // Log da operacao
        $this->logAction('bulk_recalculate', 'partner', $partnerId, null, json_encode([
            'processed' => $processed,
            'errors' => $errors
        ]));

        return [
            'success' => true,
            'partner_id' => $partnerId,
            'processed' => $processed,
            'errors' => $errors,
            'sample_results' => array_slice($results, 0, 10)
        ];
    }

    /**
     * Obtem estatisticas da IA
     */
    public function getStats() {
        $stats = [];

        // Total de precos calculados
        $r = $this->pdo->query("SELECT COUNT(*) as c FROM om_market_products_sale WHERE ai_calculated_at IS NOT NULL");
        $stats['total_calculated'] = intval($r->fetch(PDO::FETCH_ASSOC)['c']);

        // Margem media
        $r = $this->pdo->query("SELECT AVG(margin_percent) as avg FROM om_market_products_sale WHERE status = '1'");
        $stats['average_margin'] = round(floatval($r->fetch(PDO::FETCH_ASSOC)['avg']), 2);

        // Score medio de confianca
        $r = $this->pdo->query("SELECT AVG(ai_score) as avg FROM om_market_products_sale WHERE ai_score IS NOT NULL");
        $stats['average_confidence'] = round(floatval($r->fetch(PDO::FETCH_ASSOC)['avg']), 1);

        // Produtos com margem baixa
        $minMargin = $this->config['alert_margin_below'];
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as c FROM om_market_products_sale WHERE margin_percent < ? AND status = '1'");
        $stmt->execute([$minMargin]);
        $stats['low_margin_count'] = intval($stmt->fetch(PDO::FETCH_ASSOC)['c']);

        // Por regiao (ultimos calculos)
        $stats['by_region'] = [];
        foreach (array_keys($this->regions) as $region) {
            $states = $this->regions[$region];
            $placeholders = implode(',', array_fill(0, count($states), '?'));
            $sql = "SELECT AVG(ps.margin_percent) as avg_margin, COUNT(*) as total
                    FROM om_market_products_sale ps
                    JOIN om_market_partners p ON ps.partner_id = p.partner_id
                    WHERE p.state IN ($placeholders) AND ps.status = '1'";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($states);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['by_region'][$region] = [
                'average_margin' => round(floatval($row['avg_margin']), 2),
                'total_products' => intval($row['total'])
            ];
        }

        return $stats;
    }

    /**
     * Obtem configuracoes atuais
     */
    public function getConfig() {
        return $this->config;
    }

    /**
     * Atualiza configuracao
     */
    public function updateConfig($key, $value) {
        $stmt = $this->pdo->prepare("UPDATE om_market_ai_config SET config_value = ?, date_modified = NOW() WHERE config_key = ?");
        $result = $stmt->execute([$value, $key]);

        if ($result) {
            $this->loadConfig(); // Recarregar
            $this->logAction('config_update', 'ai_config', null, null, "Key: $key, Value: $value");
        }

        return $result;
    }

    /**
     * Log de acoes
     */
    private function logAction($action, $entityType, $entityId, $oldValue, $newValue) {
        $sql = "INSERT INTO om_market_logs (action, entity_type, entity_id, old_value, new_value, user_type, date_added)
                VALUES (?, ?, ?, ?, ?, 'system', NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$action, $entityType, $entityId, $oldValue, $newValue]);
    }
}

// ==============================================================================
// ROTEAMENTO
// ==============================================================================

$ai = new PricingAI($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = getInput();

switch ($action) {

    // -------------------------------------------------------------------------
    // Calcular preco de um produto
    // -------------------------------------------------------------------------
    case 'calculate':
        $productId = intval($input['product_id'] ?? $_GET['product_id'] ?? 0);
        $partnerId = intval($input['partner_id'] ?? $_GET['partner_id'] ?? 0);
        $save = isset($input['save']) ? $input['save'] : true;

        if (!$productId || !$partnerId) {
            jsonResponse(['error' => 'product_id e partner_id sao obrigatorios'], 400);
        }

        $result = $ai->calculatePrice($productId, $partnerId);

        if (isset($result['success']) && $result['success'] && $save) {
            $ai->savePrice($productId, $partnerId, $result);
            $result['saved'] = true;
        }

        jsonResponse($result);
        break;

    // -------------------------------------------------------------------------
    // Calcular precos em lote
    // -------------------------------------------------------------------------
    case 'bulk':
        $items = $input['items'] ?? [];
        $save = isset($input['save']) ? $input['save'] : true;

        if (empty($items)) {
            jsonResponse(['error' => 'items array e obrigatorio'], 400);
        }

        $results = [];
        foreach ($items as $item) {
            $productId = intval($item['product_id'] ?? 0);
            $partnerId = intval($item['partner_id'] ?? 0);

            if ($productId && $partnerId) {
                $calc = $ai->calculatePrice($productId, $partnerId);
                if (isset($calc['success']) && $calc['success'] && $save) {
                    $ai->savePrice($productId, $partnerId, $calc);
                }
                $results[] = [
                    'product_id' => $productId,
                    'partner_id' => $partnerId,
                    'success' => $calc['success'] ?? false,
                    'sale_price' => $calc['pricing']['sale_price'] ?? null,
                    'margin' => $calc['pricing']['margin_percent'] ?? null
                ];
            }
        }

        jsonResponse([
            'success' => true,
            'processed' => count($results),
            'results' => $results
        ]);
        break;

    // -------------------------------------------------------------------------
    // Recalcular todos os precos de um mercado
    // -------------------------------------------------------------------------
    case 'recalculate':
        $partnerId = intval($input['partner_id'] ?? $_GET['partner_id'] ?? 0);

        if (!$partnerId) {
            jsonResponse(['error' => 'partner_id e obrigatorio'], 400);
        }

        $result = $ai->recalculatePartner($partnerId);
        jsonResponse($result);
        break;

    // -------------------------------------------------------------------------
    // Estatisticas da IA
    // -------------------------------------------------------------------------
    case 'stats':
        jsonResponse([
            'success' => true,
            'stats' => $ai->getStats()
        ]);
        break;

    // -------------------------------------------------------------------------
    // Obter configuracoes
    // -------------------------------------------------------------------------
    case 'config':
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($input)) {
            // Atualizar configuracoes
            $updated = [];
            foreach ($input as $key => $value) {
                if ($ai->updateConfig($key, $value)) {
                    $updated[] = $key;
                }
            }
            jsonResponse([
                'success' => true,
                'updated' => $updated,
                'config' => $ai->getConfig()
            ]);
        } else {
            // Retornar configuracoes atuais
            jsonResponse([
                'success' => true,
                'config' => $ai->getConfig()
            ]);
        }
        break;

    // -------------------------------------------------------------------------
    // Documentacao / Default
    // -------------------------------------------------------------------------
    default:
        jsonResponse([
            'api' => 'OneMundo Market - Pricing AI',
            'version' => '1.0.0',
            'endpoints' => [
                'GET/POST ?action=calculate' => 'Calcula preco de um produto (product_id, partner_id)',
                'POST ?action=bulk' => 'Calcula precos em lote (items: [{product_id, partner_id}])',
                'GET/POST ?action=recalculate' => 'Recalcula todos os precos de um mercado (partner_id)',
                'GET ?action=stats' => 'Estatisticas da IA',
                'GET ?action=config' => 'Obter configuracoes',
                'POST ?action=config' => 'Atualizar configuracoes'
            ],
            'example' => [
                'calculate' => '?action=calculate&product_id=1&partner_id=1',
                'bulk' => 'POST {"items": [{"product_id": 1, "partner_id": 1}]}',
                'recalculate' => '?action=recalculate&partner_id=1'
            ]
        ]);
        break;
}
