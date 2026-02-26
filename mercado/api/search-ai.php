<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * SUPERBORA - BUSCA INTELIGENTE COM AI
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Features:
 * 1. Autocomplete com sugestoes em tempo real
 * 2. Correcao de erros de digitacao (fuzzy search)
 * 3. Busca por sinonimos
 * 4. Historico de buscas do usuario
 * 5. Ranking por relevancia, popularidade e AI
 * 6. Sugestoes de categorias relacionadas
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

class AISearch {
    private $db;
    private $partner_id;
    private $customer_id;

    // Sinonimos comuns em supermercado
    private $synonyms = [
        'refrigerante' => ['refri', 'coca', 'guarana', 'soda', 'refrigerantes'],
        'cerveja' => ['cerva', 'breja', 'gelada', 'cervejas', 'latinha'],
        'leite' => ['lacteo', 'laticinio', 'leites'],
        'pao' => ['paes', 'pão', 'paozinho', 'bisnaguinha', 'baguete'],
        'arroz' => ['arroz branco', 'arroz integral', 'arrozes'],
        'feijao' => ['feijão', 'feijoada', 'feijoes'],
        'carne' => ['carnes', 'bovina', 'boi', 'bisteca', 'picanha', 'alcatra'],
        'frango' => ['galinha', 'ave', 'peito de frango', 'coxa', 'sobrecoxa'],
        'cafe' => ['café', 'cafes', 'expresso', 'capuccino'],
        'acucar' => ['açucar', 'açúcar', 'adocante', 'doce'],
        'oleo' => ['óleo', 'azeite', 'gordura'],
        'sabao' => ['sabão', 'detergente', 'lava louca', 'limpeza'],
        'shampoo' => ['xampu', 'cabelo', 'condicionador'],
        'papel' => ['papel higienico', 'papel toalha', 'guardanapo'],
        'agua' => ['água', 'mineral', 'aguas'],
        'suco' => ['sucos', 'nectar', 'refresco'],
        'biscoito' => ['bolacha', 'cookie', 'wafer', 'rosquinha'],
        'chocolate' => ['bombom', 'cacau', 'achocolatado'],
        'queijo' => ['queijos', 'mussarela', 'prato', 'minas'],
        'presunto' => ['mortadela', 'frios', 'fatiado'],
        'iogurte' => ['yogurt', 'danone', 'grego'],
        'macarrao' => ['macarrão', 'massa', 'espaguete', 'penne'],
        'molho' => ['extrato', 'tomate', 'ketchup', 'mostarda'],
        'margarina' => ['manteiga', 'qualy', 'doriana'],
        'batata' => ['batatas', 'chips', 'frita'],
        'tomate' => ['tomates', 'italiano', 'cereja'],
        'cebola' => ['cebolas', 'alho'],
        'banana' => ['bananas', 'prata', 'nanica'],
        'maca' => ['maça', 'maçã', 'macas', 'gala', 'fuji'],
        'laranja' => ['laranjas', 'pera', 'citrus'],
    ];

    public function __construct($partner_id, $customer_id = 0) {
        $this->db = getDB();
        $this->partner_id = (int)$partner_id;
        $this->customer_id = (int)$customer_id;
    }

    /**
     * Busca principal com ranking AI
     */
    public function search($query, $limit = 40, $offset = 0) {
        $query = trim($query);
        if (strlen($query) < 2) {
            return ['products' => [], 'total' => 0, 'suggestions' => []];
        }

        // Expandir query com sinonimos
        $expandedTerms = $this->expandWithSynonyms($query);

        // Corrigir erros de digitacao
        $correctedQuery = $this->correctTypos($query);
        if ($correctedQuery !== $query) {
            $expandedTerms = array_merge($expandedTerms, $this->expandWithSynonyms($correctedQuery));
        }

        $expandedTerms = array_unique($expandedTerms);

        // Construir WHERE clause
        $whereConditions = [];
        $params = [':partner_id' => $this->partner_id];

        foreach ($expandedTerms as $i => $term) {
            if (strlen($term) < 2) continue;
            $whereConditions[] = "(pb.name LIKE :term{$i} OR pb.brand LIKE :term{$i} OR pb.description LIKE :term{$i})";
            $params[":term{$i}"] = "%{$term}%";
        }

        if (empty($whereConditions)) {
            return ['products' => [], 'total' => 0, 'suggestions' => []];
        }

        $whereSQL = "(" . implode(" OR ", $whereConditions) . ")";

        // Query principal com ranking
        $sql = "
            SELECT
                pb.product_id,
                pb.name,
                pb.brand,
                pb.image,
                pb.unit,
                pb.category_id,
                pb.description,
                pp.price,
                pp.price_promo,
                pp.stock,
                pp.ai_price,
                COALESCE(sales.total_sold, 0) as popularity,
                -- Score de relevancia
                (
                    CASE WHEN pb.name LIKE :exact_match THEN 100 ELSE 0 END +
                    CASE WHEN pb.name LIKE :starts_with THEN 50 ELSE 0 END +
                    CASE WHEN pb.brand LIKE :query_brand THEN 30 ELSE 0 END +
                    CASE WHEN pp.price_promo > 0 AND pp.price_promo < pp.price THEN 20 ELSE 0 END +
                    COALESCE(sales.total_sold, 0) * 0.1
                ) as relevance_score
            FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            LEFT JOIN (
                SELECT oi.product_id, SUM(oi.quantity) as total_sold
                FROM om_market_order_items oi
                JOIN om_market_orders o ON oi.order_id = o.order_id
                WHERE o.partner_id = :partner_id_sales
                AND o.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND o.status IN ('delivered', 'completed')
                GROUP BY oi.product_id
            ) sales ON pb.product_id = sales.product_id
            WHERE pp.partner_id = :partner_id
            AND pp.status = '1'
            AND pp.stock > 0
            AND {$whereSQL}
            ORDER BY relevance_score DESC, popularity DESC, pb.name ASC
            LIMIT :limit OFFSET :offset
        ";

        $params[':partner_id_sales'] = $this->partner_id;
        $params[':exact_match'] = $query;
        $params[':starts_with'] = "{$query}%";
        $params[':query_brand'] = "%{$query}%";
        $params[':limit'] = (int)$limit;
        $params[':offset'] = (int)$offset;

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $value, $type);
            }
            $stmt->execute();
            $products = $stmt->fetchAll();

            // Contar total
            $countSQL = "
                SELECT COUNT(DISTINCT pb.product_id)
                FROM om_market_products_base pb
                JOIN om_market_products_price pp ON pb.product_id = pp.product_id
                WHERE pp.partner_id = :partner_id
                AND pp.status = '1'
                AND pp.stock > 0
                AND {$whereSQL}
            ";
            unset($params[':partner_id_sales'], $params[':exact_match'], $params[':starts_with'],
                  $params[':query_brand'], $params[':limit'], $params[':offset']);

            $countStmt = $this->db->prepare($countSQL);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $countStmt->execute();
            $total = $countStmt->fetchColumn();

            // Salvar busca no historico
            $this->saveSearchHistory($query);

            // Formatar produtos
            foreach ($products as &$p) {
                $p['price'] = (float)$p['price'];
                $p['price_promo'] = $p['price_promo'] ? (float)$p['price_promo'] : null;
                $p['stock'] = (int)$p['stock'];
                $p['relevance_score'] = (float)$p['relevance_score'];
            }

            // Sugestoes se poucos resultados
            $suggestions = [];
            if (count($products) < 5) {
                $suggestions = $this->getSuggestions($query);
            }

            return [
                'products' => $products,
                'total' => (int)$total,
                'query' => $query,
                'corrected_query' => $correctedQuery !== $query ? $correctedQuery : null,
                'suggestions' => $suggestions,
                'expanded_terms' => $expandedTerms
            ];

        } catch (Exception $e) {
            return ['products' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Autocomplete para campo de busca
     */
    public function autocomplete($query, $limit = 8) {
        $query = trim($query);
        if (strlen($query) < 2) {
            return ['suggestions' => []];
        }

        $suggestions = [];

        // 1. Produtos que comecam com a query
        $stmt = $this->db->prepare("
            SELECT DISTINCT pb.name, pb.brand, pb.image
            FROM om_market_products_base pb
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            WHERE pp.partner_id = ?
            AND pp.status = '1'
            AND (pb.name LIKE ? OR pb.brand LIKE ?)
            ORDER BY
                CASE WHEN pb.name LIKE ? THEN 0 ELSE 1 END,
                pb.name ASC
            LIMIT ?
        ");
        $stmt->execute([
            $this->partner_id,
            "{$query}%",
            "{$query}%",
            "{$query}%",
            $limit
        ]);
        $products = $stmt->fetchAll();

        foreach ($products as $p) {
            $suggestions[] = [
                'type' => 'product',
                'text' => $p['name'],
                'brand' => $p['brand'],
                'image' => $p['image']
            ];
        }

        // 2. Categorias
        $stmt = $this->db->prepare("
            SELECT DISTINCT c.category_id, c.name, c.icon
            FROM om_market_categories c
            WHERE c.name LIKE ?
            LIMIT 3
        ");
        $stmt->execute(["%{$query}%"]);
        $categories = $stmt->fetchAll();

        foreach ($categories as $c) {
            $suggestions[] = [
                'type' => 'category',
                'text' => $c['name'],
                'category_id' => $c['category_id'],
                'icon' => $c['icon']
            ];
        }

        // 3. Buscas recentes do usuario
        if ($this->customer_id) {
            $stmt = $this->db->prepare("
                SELECT query
                FROM om_search_history
                WHERE customer_id = ?
                AND query LIKE ?
                ORDER BY searched_at DESC
                LIMIT 3
            ");
            $stmt->execute([$this->customer_id, "%{$query}%"]);
            $history = $stmt->fetchAll();

            foreach ($history as $h) {
                $suggestions[] = [
                    'type' => 'history',
                    'text' => $h['query']
                ];
            }
        }

        // 4. Buscas populares
        $stmt = $this->db->prepare("
            SELECT query, COUNT(*) as count
            FROM om_search_history
            WHERE query LIKE ?
            AND searched_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY query
            ORDER BY count DESC
            LIMIT 3
        ");
        $stmt->execute(["%{$query}%"]);
        $popular = $stmt->fetchAll();

        foreach ($popular as $p) {
            $exists = false;
            foreach ($suggestions as $s) {
                if ($s['text'] === $p['query']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $suggestions[] = [
                    'type' => 'popular',
                    'text' => $p['query'],
                    'count' => (int)$p['count']
                ];
            }
        }

        return ['suggestions' => array_slice($suggestions, 0, $limit)];
    }

    /**
     * Expande query com sinonimos
     */
    private function expandWithSynonyms($query) {
        $terms = preg_split("/\s+/", mb_strtolower($query));
        $expanded = $terms;

        foreach ($terms as $term) {
            // Procurar sinonimos
            foreach ($this->synonyms as $key => $synonymList) {
                if ($term === $key || in_array($term, $synonymList)) {
                    $expanded[] = $key;
                    $expanded = array_merge($expanded, $synonymList);
                }
            }
        }

        return array_unique($expanded);
    }

    /**
     * Corrige erros de digitacao comuns
     */
    private function correctTypos($query) {
        $corrections = [
            // Acentos comuns
            'cafe' => 'café',
            'acucar' => 'açúcar',
            'feijao' => 'feijão',
            'pao' => 'pão',
            'maca' => 'maçã',
            'agua' => 'água',
            'oleo' => 'óleo',
            'sabao' => 'sabão',
            'macarrao' => 'macarrão',

            // Erros comuns
            'refri' => 'refrigerante',
            'refrig' => 'refrigerante',
            'cerva' => 'cerveja',
            'breja' => 'cerveja',
            'biscoito' => 'biscoito',
            'bolaxa' => 'bolacha',
            'xocolate' => 'chocolate',
            'xampu' => 'shampoo',
            'sabonette' => 'sabonete',
            'dezodorante' => 'desodorante',
            'esponja' => 'esponja',
            'deterjente' => 'detergente',
        ];

        $words = explode(' ', mb_strtolower($query));
        $corrected = [];

        foreach ($words as $word) {
            if (isset($corrections[$word])) {
                $corrected[] = $corrections[$word];
            } else {
                $corrected[] = $word;
            }
        }

        return implode(' ', $corrected);
    }

    /**
     * Sugestoes quando poucos resultados
     */
    private function getSuggestions($query) {
        $suggestions = [];

        // Buscar categorias relacionadas
        $stmt = $this->db->prepare("
            SELECT c.category_id, c.name, c.icon, COUNT(pb.product_id) as product_count
            FROM om_market_categories c
            JOIN om_market_products_base pb ON c.category_id = pb.category_id
            JOIN om_market_products_price pp ON pb.product_id = pp.product_id
            WHERE pp.partner_id = ?
            AND pp.status = '1'
            AND (c.name LIKE ? OR pb.name LIKE ?)
            GROUP BY c.category_id
            ORDER BY product_count DESC
            LIMIT 5
        ");
        $stmt->execute([$this->partner_id, "%{$query}%", "%{$query}%"]);
        $categories = $stmt->fetchAll();

        foreach ($categories as $c) {
            $suggestions[] = [
                'type' => 'category',
                'text' => "Ver todos em {$c['name']}",
                'category_id' => $c['category_id'],
                'icon' => $c['icon'],
                'count' => (int)$c['product_count']
            ];
        }

        // Buscas similares populares
        $stmt = $this->db->prepare("
            SELECT query, COUNT(*) as count
            FROM om_search_history
            WHERE query LIKE ?
            AND searched_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY query
            ORDER BY count DESC
            LIMIT 5
        ");
        $stmt->execute(["%{$query}%"]);
        $popular = $stmt->fetchAll();

        foreach ($popular as $p) {
            if (mb_strtolower($p['query']) !== mb_strtolower($query)) {
                $suggestions[] = [
                    'type' => 'related',
                    'text' => $p['query']
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Salva busca no historico
     */
    private function saveSearchHistory($query) {
        try {
            // Criar tabela se nao existir
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS om_search_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    customer_id INT DEFAULT 0,
                    partner_id INT NOT NULL,
                    query VARCHAR(255) NOT NULL,
                    results_count INT DEFAULT 0,
                    searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_customer (customer_id),
                    INDEX idx_query (query),
                    INDEX idx_searched (searched_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $stmt = $this->db->prepare("
                INSERT INTO om_search_history (customer_id, partner_id, query)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$this->customer_id, $this->partner_id, $query]);
        } catch (Exception $e) {
            // Ignore errors
        }
    }

    /**
     * Buscas recentes do usuario
     */
    public function getRecentSearches($limit = 10) {
        if (!$this->customer_id) return [];

        try {
            $stmt = $this->db->prepare("
                SELECT query, MAX(searched_at) as last_searched
                FROM om_search_history
                WHERE customer_id = ?
                GROUP BY query
                ORDER BY last_searched DESC
                LIMIT ?
            ");
            $stmt->execute([$this->customer_id, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Buscas populares gerais
     */
    public function getPopularSearches($limit = 10) {
        try {
            $stmt = $this->db->prepare("
                SELECT query, COUNT(*) as search_count
                FROM om_search_history
                WHERE partner_id = ?
                AND searched_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY query
                ORDER BY search_count DESC
                LIMIT ?
            ");
            $stmt->execute([$this->partner_id, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// API ENDPOINT
// ══════════════════════════════════════════════════════════════════════════════

$input = array_merge($_GET, $_POST);
$action = $input['action'] ?? 'search';
$query = trim($input['q'] ?? $input['query'] ?? '');
$partner_id = (int)($input['partner_id'] ?? 100);
$customer_id = (int)($input['customer_id'] ?? 0);
$limit = min((int)($input['limit'] ?? 40), 100);
$offset = (int)($input['offset'] ?? 0);

if (!$partner_id) {
    response(false, null, 'Partner ID obrigatorio', 400);
}

try {
    $search = new AISearch($partner_id, $customer_id);

    switch ($action) {
        case 'search':
            if (empty($query)) {
                response(false, null, 'Query obrigatoria', 400);
            }
            $results = $search->search($query, $limit, $offset);
            response(true, $results, 'Busca realizada');
            break;

        case 'autocomplete':
            if (empty($query)) {
                response(true, ['suggestions' => []], 'Sem sugestoes');
            }
            $results = $search->autocomplete($query, $limit);
            response(true, $results, 'Sugestoes');
            break;

        case 'recent':
            $results = $search->getRecentSearches($limit);
            response(true, ['searches' => $results], 'Buscas recentes');
            break;

        case 'popular':
            $results = $search->getPopularSearches($limit);
            response(true, ['searches' => $results], 'Buscas populares');
            break;

        default:
            response(false, null, 'Acao invalida. Use: search, autocomplete, recent, popular', 400);
    }

} catch (Exception $e) {
    response(false, null, 'Erro: ' . $e->getMessage(), 500);
}
