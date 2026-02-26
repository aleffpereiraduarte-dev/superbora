<?php
/**
 * API DE BUSCA INTELIGENTE
 * Sugestões, autocomplete e resultados
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'autocomplete':
        handleAutocomplete();
        break;
    case 'suggestions':
        handleSuggestions();
        break;
    case 'search':
        handleSearch();
        break;
    case 'trending':
        handleTrending();
        break;
    default:
        jsonResponse(['error' => 'Ação inválida'], 400);
}

/**
 * Autocomplete - sugestões enquanto digita
 */
function handleAutocomplete() {
    $query = trim($_GET['q'] ?? '');

    if (strlen($query) < 2) {
        jsonResponse(['suggestions' => []]);
        return;
    }

    try {
        $pdo = getDB();

        $like = $query . '%';
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                b.name,
                b.brand,
                c.name as category,
                COUNT(*) as relevance
            FROM om_market_products_base b
            JOIN om_market_products_price pr ON b.product_id = pr.product_id
            LEFT JOIN om_market_categories c ON b.category_id = c.category_id
            WHERE b.name LIKE ? OR b.brand LIKE ?
            GROUP BY b.name, b.brand, c.name
            ORDER BY relevance DESC, b.name ASC
            LIMIT 8
        ");
        $stmt->execute([$like, $like]);

        $suggestions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $suggestions[] = [
                'text' => $row['name'],
                'brand' => $row['brand'],
                'category' => $row['category'],
                'type' => 'product'
            ];
        }

        // Adicionar categorias que correspondem
        $categorias = getCategorias();
        foreach ($categorias as $cat) {
            if (stripos($cat['name'] ?? '', $query) !== false) {
                $suggestions[] = [
                    'text' => $cat['name'],
                    'icon' => $cat['icon'] ?? '',
                    'slug' => $cat['slug'] ?? '',
                    'type' => 'category'
                ];
            }
        }

        jsonResponse(['suggestions' => array_slice($suggestions, 0, 10)]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Erro na busca', 'suggestions' => []], 500);
    }
}

/**
 * Sugestões inteligentes baseadas no contexto
 */
function handleSuggestions() {
    $query = trim($_GET['q'] ?? '');

    $suggestions = [];

    // 1. Sugestões baseadas no horário
    $hora = (int)date('H');
    if ($hora >= 6 && $hora < 10) {
        $suggestions[] = ['text' => 'Café da manhã', 'query' => 'cafe pao leite'];
        $suggestions[] = ['text' => 'Pães frescos', 'query' => 'pao'];
    } elseif ($hora >= 11 && $hora < 14) {
        $suggestions[] = ['text' => 'Almoço rápido', 'query' => 'macarrao arroz feijao'];
        $suggestions[] = ['text' => 'Saladas', 'query' => 'salada alface tomate'];
    } elseif ($hora >= 18 && $hora < 21) {
        $suggestions[] = ['text' => 'Jantar', 'query' => 'pizza massa'];
        $suggestions[] = ['text' => 'Happy Hour', 'query' => 'cerveja petisco'];
    } else {
        $suggestions[] = ['text' => 'Snacks', 'query' => 'salgadinho pipoca'];
        $suggestions[] = ['text' => 'Doces', 'query' => 'chocolate doce'];
    }

    try {
        // 2. Produtos populares
        $pdo = getDB();
        $stmt = $pdo->query("
            SELECT b.name, c.name as category
            FROM om_market_products_base b
            JOIN om_market_products_price pr ON b.product_id = pr.product_id
            LEFT JOIN om_market_categories c ON b.category_id = c.category_id
            WHERE pr.price > 0
            ORDER BY RANDOM()
            LIMIT 4
        ");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $suggestions[] = [
                'text' => $row['name'],
                'query' => $row['name'],
                'type' => 'popular'
            ];
        }
    } catch (Exception $e) {}

    // 3. Ofertas do dia
    $suggestions[] = ['text' => 'Ofertas do dia', 'query' => 'oferta', 'type' => 'promo'];

    jsonResponse(['suggestions' => array_slice($suggestions, 0, 8)]);
}

/**
 * Busca principal com filtros
 */
function handleSearch() {
    $query = trim($_GET['q'] ?? '');
    $categoria = $_GET['categoria'] ?? null;
    $marca = $_GET['marca'] ?? null;
    $preco_min = (float)($_GET['preco_min'] ?? 0);
    $preco_max = (float)($_GET['preco_max'] ?? 9999);
    $ordenar = $_GET['ordenar'] ?? 'relevancia';
    $pagina = max(1, (int)($_GET['pagina'] ?? 1));
    $por_pagina = 24;
    $partner_id = (int)($_GET['partner_id'] ?? 0);

    try {
        $pdo = getDB();

        // Construir query
        $where = ["pr.price > 0", "pr.status = '1'"];
        $params = [];

        if ($query) {
            $where[] = "(b.name LIKE ? OR b.brand LIKE ? OR b.barcode LIKE ? OR c.name LIKE ?)";
            $like = "%$query%";
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        if ($partner_id) {
            $where[] = "pr.partner_id = ?";
            $params[] = $partner_id;
        }

        if ($categoria) {
            $where[] = "(c.name LIKE ? OR b.category_id = ?)";
            $params[] = "%$categoria%";
            $params[] = $categoria;
        }

        if ($marca) {
            $where[] = "b.brand = ?";
            $params[] = $marca;
        }

        if ($preco_min > 0) {
            $where[] = "pr.price >= ?";
            $params[] = $preco_min;
        }

        if ($preco_max < 9999) {
            $where[] = "pr.price <= ?";
            $params[] = $preco_max;
        }

        $where_sql = implode(" AND ", $where);

        // Ordenação
        $order = match($ordenar) {
            'preco_asc' => 'pr.price ASC',
            'preco_desc' => 'pr.price DESC',
            'nome' => 'b.name ASC',
            'novos' => 'b.product_id DESC',
            default => 'b.name ASC'
        };

        // Contar total
        $count_sql = "SELECT COUNT(DISTINCT b.product_id) as total FROM om_market_products_base b JOIN om_market_products_price pr ON b.product_id = pr.product_id LEFT JOIN om_market_categories c ON b.category_id = c.category_id WHERE $where_sql";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Buscar produtos
        $offset = ($pagina - 1) * $por_pagina;
        $sql = "
            SELECT DISTINCT b.*, pr.price, pr.price_promo, pr.promo_start, pr.promo_end, pr.partner_id, pr.stock, c.name as category_name
            FROM om_market_products_base b
            JOIN om_market_products_price pr ON b.product_id = pr.product_id
            LEFT JOIN om_market_categories c ON b.category_id = c.category_id
            WHERE $where_sql
            ORDER BY $order
            LIMIT $por_pagina OFFSET $offset
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $produtos = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $preco_final = $row['price'];
            $em_promocao = false;

            if ($row['price_promo'] && $row['price_promo'] > 0) {
                $hoje = date('Y-m-d');
                if ($hoje >= ($row['promo_start'] ?? '2000-01-01') && $hoje <= ($row['promo_end'] ?? '2099-12-31')) {
                    $preco_final = $row['price_promo'];
                    $em_promocao = true;
                }
            }

            $produtos[] = [
                'product_id' => (int)$row['product_id'],
                'name' => $row['name'],
                'brand' => $row['brand'],
                'image' => $row['image'],
                'weight' => $row['weight'],
                'price' => (float)$row['price'],
                'preco_final' => (float)$preco_final,
                'em_promocao' => $em_promocao,
                'desconto' => $em_promocao ? round((1 - $preco_final / $row['price']) * 100) : 0,
                'partner_id' => (int)$row['partner_id'],
                'stock' => (int)$row['stock']
            ];
        }

        // Buscar filtros disponíveis (marcas)
        $marcas_sql = "
            SELECT DISTINCT b.brand, COUNT(*) as count
            FROM om_market_products_base b
            JOIN om_market_products_price pr ON b.product_id = pr.product_id
            LEFT JOIN om_market_categories c ON b.category_id = c.category_id
            WHERE $where_sql AND b.brand IS NOT NULL AND b.brand != ''
            GROUP BY b.brand
            ORDER BY count DESC
            LIMIT 20
        ";
        $stmt = $pdo->prepare($marcas_sql);
        $stmt->execute($params);

        $marcas = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $marcas[] = ['name' => $row['brand'], 'count' => (int)$row['count']];
        }

        jsonResponse([
            'success' => true,
            'produtos' => $produtos,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $por_pagina,
            'total_paginas' => ceil($total / $por_pagina),
            'filtros' => [
                'marcas' => $marcas
            ],
            'query' => $query
        ]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Erro na busca: ' . $e->getMessage()], 500);
    }
}

/**
 * Termos em alta
 */
function handleTrending() {
    $trending = [
        ['text' => 'Leite', 'icon' => '🥛', 'searches' => 1250],
        ['text' => 'Arroz', 'icon' => '🍚', 'searches' => 980],
        ['text' => 'Café', 'icon' => '☕', 'searches' => 875],
        ['text' => 'Açúcar', 'icon' => '🧂', 'searches' => 654],
        ['text' => 'Pão', 'icon' => '🥖', 'searches' => 543],
        ['text' => 'Cerveja', 'icon' => '🍺', 'searches' => 432],
        ['text' => 'Refrigerante', 'icon' => '🥤', 'searches' => 321],
        ['text' => 'Chocolate', 'icon' => '🍫', 'searches' => 298],
    ];

    jsonResponse(['trending' => $trending]);
}

/**
 * Buscar categorias
 */
function getCategorias() {
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT category_id as id, name, icon, slug FROM oc_category WHERE status = '1' ORDER BY name LIMIT 50");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
