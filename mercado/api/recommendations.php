<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SUPERBORA - SISTEMA DE RECOMENDACOES INTELIGENTES COM AI
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Algoritmos:
 * 1. Collaborative Filtering - Produtos frequentemente comprados juntos
 * 2. Category Affinity - Produtos similares da mesma categoria
 * 3. Purchase History - Produtos que o cliente ja comprou
 * 4. Trending - Produtos mais vendidos do mercado
 * 5. Personalized Score - Score combinado para ranking
 *
 * NOTA: Este arquivo pode ser incluido como biblioteca OU acessado diretamente como API
 */

// Detectar se esta sendo acessado diretamente ou incluido
$isDirectAccess = (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'recommendations.php');

// So definir headers JSON se for acesso direto (API)
if ($isDirectAccess) {
    header("Content-Type: application/json; charset=utf-8");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");

    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        http_response_code(200);
        exit;
    }
}

require_once __DIR__ . '/config.php';

class AIRecommendations {
    private $db;
    private $partner_id;
    private $customer_id;
    private $cart_product_ids;
    private $limit;

    // Pesos para o algoritmo de scoring
    const WEIGHT_BOUGHT_TOGETHER = 0.35;
    const WEIGHT_CATEGORY = 0.25;
    const WEIGHT_HISTORY = 0.20;
    const WEIGHT_TRENDING = 0.15;
    const WEIGHT_PROMO = 0.05;

    public function __construct($partner_id, $customer_id = 0, $cart_product_ids = [], $limit = 12) {
        $this->db = getDB();
        $this->partner_id = (int)$partner_id;
        $this->customer_id = (int)$customer_id;
        $this->cart_product_ids = array_map('intval', $cart_product_ids);
        $this->limit = (int)$limit;
    }

    /**
     * Gera recomendacoes inteligentes combinando todos os algoritmos
     */
    public function getRecommendations() {
        $scores = [];

        // 1. Produtos frequentemente comprados juntos
        $boughtTogether = $this->getBoughtTogether();
        foreach ($boughtTogether as $product) {
            $id = $product['product_id'];
            if (!isset($scores[$id])) $scores[$id] = ['score' => 0, 'reasons' => []];
            $scores[$id]['score'] += $product['frequency'] * self::WEIGHT_BOUGHT_TOGETHER;
            $scores[$id]['reasons'][] = 'bought_together';
        }

        // 2. Produtos da mesma categoria
        $categoryProducts = $this->getCategoryProducts();
        foreach ($categoryProducts as $product) {
            $id = $product['product_id'];
            if (!isset($scores[$id])) $scores[$id] = ['score' => 0, 'reasons' => []];
            $scores[$id]['score'] += 0.5 * self::WEIGHT_CATEGORY;
            $scores[$id]['reasons'][] = 'same_category';
        }

        // 3. Historico de compras do cliente
        if ($this->customer_id) {
            $historyProducts = $this->getFromHistory();
            foreach ($historyProducts as $product) {
                $id = $product['product_id'];
                if (!isset($scores[$id])) $scores[$id] = ['score' => 0, 'reasons' => []];
                $scores[$id]['score'] += $product['purchase_count'] * self::WEIGHT_HISTORY;
                $scores[$id]['reasons'][] = 'purchase_history';
            }
        }

        // 4. Produtos trending (mais vendidos)
        $trending = $this->getTrending();
        $maxSales = !empty($trending) ? max(array_column($trending, 'total_sold')) : 1;
        foreach ($trending as $product) {
            $id = $product['product_id'];
            if (!isset($scores[$id])) $scores[$id] = ['score' => 0, 'reasons' => []];
            $normalizedScore = $product['total_sold'] / max($maxSales, 1);
            $scores[$id]['score'] += $normalizedScore * self::WEIGHT_TRENDING;
            $scores[$id]['reasons'][] = 'trending';
        }

        // 5. Bonus para produtos em promocao
        $promos = $this->getPromoProducts();
        foreach ($promos as $product) {
            $id = $product['product_id'];
            if (!isset($scores[$id])) $scores[$id] = ['score' => 0, 'reasons' => []];
            $scores[$id]['score'] += self::WEIGHT_PROMO;
            $scores[$id]['reasons'][] = 'on_sale';
        }

        // Remover produtos que ja estao no carrinho
        foreach ($this->cart_product_ids as $id) {
            unset($scores[$id]);
        }

        // Ordenar por score
        arsort($scores);

        // Pegar os top N produtos
        $topIds = array_slice(array_keys($scores), 0, $this->limit);

        if (empty($topIds)) {
            // Fallback: produtos populares
            return $this->getFallbackProducts();
        }

        // Buscar dados completos dos produtos
        return $this->getProductDetails($topIds, $scores);
    }

    /**
     * Produtos frequentemente comprados juntos
     */
    private function getBoughtTogether() {
        if (empty($this->cart_product_ids)) return [];

        $placeholders = implode(',', array_fill(0, count($this->cart_product_ids), '?'));

        $sql = "
            SELECT oi2.product_id, COUNT(*) as frequency
            FROM om_market_order_items oi1
            JOIN om_market_order_items oi2 ON oi1.order_id = oi2.order_id
            JOIN om_market_orders o ON oi1.order_id = o.order_id
            WHERE oi1.product_id IN ({$placeholders})
            AND oi2.product_id NOT IN ({$placeholders})
            AND o.partner_id = ?
            AND o.status IN ('delivered', 'completed')
            AND o.created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)
            GROUP BY oi2.product_id
            ORDER BY frequency DESC
            LIMIT 50
        ";

        $params = array_merge(
            $this->cart_product_ids,
            $this->cart_product_ids,
            [$this->partner_id]
        );

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Produtos da mesma categoria dos itens no carrinho
     */
    private function getCategoryProducts() {
        if (empty($this->cart_product_ids)) return [];

        $placeholders = implode(',', array_fill(0, count($this->cart_product_ids), '?'));

        $sql = "
            SELECT DISTINCT pb2.product_id
            FROM om_market_products_base pb1
            JOIN om_market_products_base pb2 ON pb1.category_id = pb2.category_id
            JOIN om_market_products_price pp ON pb2.product_id = pp.product_id
            WHERE pb1.product_id IN ({$placeholders})
            AND pb2.product_id NOT IN ({$placeholders})
            AND pp.partner_id = ?
            AND pp.status = '1'
            AND pp.stock > 0
            LIMIT 30
        ";

        $params = array_merge(
            $this->cart_product_ids,
            $this->cart_product_ids,
            [$this->partner_id]
        );

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Produtos do historico de compras do cliente
     */
    private function getFromHistory() {
        if (!$this->customer_id) return [];

        $excludePlaceholders = '';
        $params = [$this->customer_id, $this->partner_id];

        if (!empty($this->cart_product_ids)) {
            $excludePlaceholders = 'AND oi.product_id NOT IN (' .
                implode(',', array_fill(0, count($this->cart_product_ids), '?')) . ')';
            $params = array_merge($params, $this->cart_product_ids);
        }

        $sql = "
            SELECT oi.product_id, COUNT(*) as purchase_count
            FROM om_market_order_items oi
            JOIN om_market_orders o ON oi.order_id = o.order_id
            JOIN om_market_products_price pp ON oi.product_id = pp.product_id AND pp.partner_id = o.partner_id
            WHERE o.customer_id = ?
            AND o.partner_id = ?
            AND o.status IN ('delivered', 'completed')
            {$excludePlaceholders}
            AND pp.status = '1'
            AND pp.stock > 0
            GROUP BY oi.product_id
            ORDER BY purchase_count DESC, MAX(o.created_at) DESC
            LIMIT 30
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Produtos mais vendidos (trending)
     */
    private function getTrending() {
        $excludePlaceholders = '';
        $params = [$this->partner_id];

        if (!empty($this->cart_product_ids)) {
            $excludePlaceholders = 'AND oi.product_id NOT IN (' .
                implode(',', array_fill(0, count($this->cart_product_ids), '?')) . ')';
            $params = array_merge($params, $this->cart_product_ids);
        }

        $sql = "
            SELECT oi.product_id, SUM(oi.quantity) as total_sold
            FROM om_market_order_items oi
            JOIN om_market_orders o ON oi.order_id = o.order_id
            JOIN om_market_products_price pp ON oi.product_id = pp.product_id AND pp.partner_id = o.partner_id
            WHERE o.partner_id = ?
            AND o.status IN ('delivered', 'completed')
            AND o.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            {$excludePlaceholders}
            AND pp.status = '1'
            AND pp.stock > 0
            GROUP BY oi.product_id
            ORDER BY total_sold DESC
            LIMIT 40
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Produtos em promocao
     */
    private function getPromoProducts() {
        $excludePlaceholders = '';
        $params = [$this->partner_id];

        if (!empty($this->cart_product_ids)) {
            $excludePlaceholders = 'AND pp.product_id NOT IN (' .
                implode(',', array_fill(0, count($this->cart_product_ids), '?')) . ')';
            $params = array_merge($params, $this->cart_product_ids);
        }

        $sql = "
            SELECT pp.product_id
            FROM om_market_products_price pp
            WHERE pp.partner_id = ?
            AND pp.status = '1'
            AND pp.stock > 0
            AND pp.price_promo > 0
            AND pp.price_promo < pp.price
            {$excludePlaceholders}
            LIMIT 20
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Fallback: produtos populares quando nao ha dados suficientes
     */
    private function getFallbackProducts() {
        $excludePlaceholders = '';
        $params = [$this->partner_id];

        if (!empty($this->cart_product_ids)) {
            $excludePlaceholders = 'AND pb.product_id NOT IN (' .
                implode(',', array_fill(0, count($this->cart_product_ids), '?')) . ')';
            $params = array_merge($params, $this->cart_product_ids);
        }

        $sql = "
            SELECT pb.product_id, pb.name, pb.brand, pb.image, pb.unit,
                   COALESCE(ps.sale_price, pp.price) as price,
                   pp.price_promo,
                   pp.price as original_price,
                   'popular' as recommendation_reason
            FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            LEFT JOIN om_market_products_sale ps ON pb.product_id = ps.product_id AND pp.partner_id = ps.partner_id
            WHERE pp.partner_id = ?
            AND pp.status = '1'
            AND pp.stock > 0
            {$excludePlaceholders}
            ORDER BY pp.price_promo > 0 DESC, RANDOM()
            LIMIT ?
        ";

        $params[] = $this->limit;

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Busca detalhes completos dos produtos recomendados
     */
    private function getProductDetails($productIds, $scores) {
        if (empty($productIds)) return [];

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        $sql = "
            SELECT pb.product_id, pb.name, pb.brand, pb.image, pb.unit, pb.category_id,
                   COALESCE(ps.sale_price, pp.price) as price,
                   pp.price_promo,
                   pp.price as original_price,
                   pp.stock
            FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            LEFT JOIN om_market_products_sale ps ON pb.product_id = ps.product_id AND pp.partner_id = ps.partner_id
            WHERE pb.product_id IN ({$placeholders})
            AND pp.partner_id = ?
            AND pp.status = '1'
        ";

        $params = array_merge($productIds, [$this->partner_id]);

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll();

            // Adicionar score e razoes
            $result = [];
            foreach ($products as $product) {
                $id = $product['product_id'];
                $product['ai_score'] = round($scores[$id]['score'] ?? 0, 3);
                $product['recommendation_reasons'] = array_unique($scores[$id]['reasons'] ?? []);
                $product['recommendation_reason'] = $this->getMainReason($product['recommendation_reasons']);
                $result[] = $product;
            }

            // Ordenar pelo score
            usort($result, function($a, $b) {
                return $b['ai_score'] <=> $a['ai_score'];
            });

            return $result;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Retorna a razao principal para exibicao
     */
    private function getMainReason($reasons) {
        $priority = ['bought_together', 'purchase_history', 'trending', 'same_category', 'on_sale'];

        foreach ($priority as $reason) {
            if (in_array($reason, $reasons)) {
                return $reason;
            }
        }

        return 'recommended';
    }

    /**
     * Gera recomendacoes para a homepage (sem carrinho)
     */
    public function getHomepageRecommendations() {
        $results = [];

        // Para usuario logado: produtos do historico + trending
        if ($this->customer_id) {
            // Comprou recentemente
            $history = $this->getRecentlyPurchased();
            foreach ($history as $p) {
                $p['section'] = 'buy_again';
                $p['section_title'] = 'Compre novamente';
                $results[] = $p;
            }
        }

        // Trending
        $trending = $this->getTrendingWithDetails();
        foreach ($trending as $p) {
            $p['section'] = 'trending';
            $p['section_title'] = 'Mais vendidos';
            $results[] = $p;
        }

        // Promocoes
        $promos = $this->getPromoWithDetails();
        foreach ($promos as $p) {
            $p['section'] = 'deals';
            $p['section_title'] = 'Ofertas do dia';
            $results[] = $p;
        }

        return $results;
    }

    /**
     * Produtos comprados recentemente pelo cliente
     */
    private function getRecentlyPurchased() {
        if (!$this->customer_id) return [];

        $sql = "
            SELECT pb.product_id, pb.name, pb.brand, pb.image, pb.unit,
                   COALESCE(ps.sale_price, pp.price) as price,
                   pp.price_promo,
                   MAX(o.created_at) as last_purchased
            FROM om_market_order_items oi
            JOIN om_market_orders o ON oi.order_id = o.order_id
            JOIN om_market_products_base pb ON oi.product_id = pb.product_id
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id AND pp.partner_id = o.partner_id
            LEFT JOIN om_market_products_sale ps ON pb.product_id = ps.product_id AND pp.partner_id = ps.partner_id
            WHERE o.customer_id = ?
            AND o.partner_id = ?
            AND o.status IN ('delivered', 'completed')
            AND pp.status = '1'
            AND pp.stock > 0
            GROUP BY pb.product_id
            ORDER BY last_purchased DESC
            LIMIT 10
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->customer_id, $this->partner_id]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Trending com detalhes
     */
    private function getTrendingWithDetails() {
        $sql = "
            SELECT pb.product_id, pb.name, pb.brand, pb.image, pb.unit,
                   COALESCE(ps.sale_price, pp.price) as price,
                   pp.price_promo,
                   SUM(oi.quantity) as total_sold
            FROM om_market_order_items oi
            JOIN om_market_orders o ON oi.order_id = o.order_id
            JOIN om_market_products_base pb ON oi.product_id = pb.product_id
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id AND pp.partner_id = o.partner_id
            LEFT JOIN om_market_products_sale ps ON pb.product_id = ps.product_id AND pp.partner_id = ps.partner_id
            WHERE o.partner_id = ?
            AND o.status IN ('delivered', 'completed')
            AND o.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND pp.status = '1'
            AND pp.stock > 0
            GROUP BY pb.product_id
            ORDER BY total_sold DESC
            LIMIT 10
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->partner_id]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Promocoes com detalhes
     */
    private function getPromoWithDetails() {
        $sql = "
            SELECT pb.product_id, pb.name, pb.brand, pb.image, pb.unit,
                   COALESCE(ps.sale_price, pp.price) as price,
                   pp.price_promo,
                   pp.price as original_price,
                   ROUND((1 - pp.price_promo/pp.price) * 100) as discount_percent
            FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            LEFT JOIN om_market_products_sale ps ON pb.product_id = ps.product_id AND pp.partner_id = ps.partner_id
            WHERE pp.partner_id = ?
            AND pp.status = '1'
            AND pp.stock > 0
            AND pp.price_promo > 0
            AND pp.price_promo < pp.price
            ORDER BY discount_percent DESC
            LIMIT 10
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->partner_id]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// API ENDPOINT - So executa se acessado diretamente
// ══════════════════════════════════════════════════════════════════════════════

// Nao executar se incluido em outro arquivo
if (!$isDirectAccess) {
    return;
}

$input = getInput();
$action = $input['action'] ?? 'cart';
$partner_id = (int)($input['partner_id'] ?? 100);
$customer_id = (int)($input['customer_id'] ?? 0);
$cart_product_ids = $input['cart_product_ids'] ?? [];
$limit = (int)($input['limit'] ?? 12);

// Validar
if (!$partner_id) {
    response(false, null, 'Partner ID obrigatorio', 400);
}

if (!is_array($cart_product_ids)) {
    $cart_product_ids = [];
}

try {
    $ai = new AIRecommendations($partner_id, $customer_id, $cart_product_ids, $limit);

    switch ($action) {
        case 'cart':
            // Recomendacoes para pagina do carrinho
            $recommendations = $ai->getRecommendations();
            response(true, [
                'recommendations' => $recommendations,
                'algorithm' => 'ai_collaborative_filtering',
                'factors' => ['bought_together', 'category', 'history', 'trending', 'promotions']
            ], 'Recomendacoes geradas com sucesso');
            break;

        case 'homepage':
            // Recomendacoes para homepage
            $recommendations = $ai->getHomepageRecommendations();
            response(true, [
                'recommendations' => $recommendations,
                'sections' => ['buy_again', 'trending', 'deals']
            ], 'Recomendacoes da homepage');
            break;

        case 'product':
            // Recomendacoes para pagina de produto
            $product_id = (int)($input['product_id'] ?? 0);
            if (!$product_id) {
                response(false, null, 'Product ID obrigatorio', 400);
            }
            $ai = new AIRecommendations($partner_id, $customer_id, [$product_id], $limit);
            $recommendations = $ai->getRecommendations();
            response(true, [
                'recommendations' => $recommendations,
                'context' => 'product_page'
            ], 'Produtos relacionados');
            break;

        default:
            response(false, null, 'Acao invalida. Use: cart, homepage, product', 400);
    }

} catch (Exception $e) {
    response(false, null, 'Erro ao gerar recomendacoes: ' . $e->getMessage(), 500);
}
