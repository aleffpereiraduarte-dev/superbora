<?php
/**
 * Smart Search Suggestions with AI-enriched query understanding
 * GET /api/mercado/vitrine/smart-search.php
 *
 * Parameters:
 *   ?q=texto           - Search query (required, min 2 chars)
 *   &city=campinas     - City filter (optional)
 *   &customer_id=123   - Customer ID for personalization (optional, also from JWT)
 *   &lat=              - Latitude for distance sorting (optional)
 *   &lng=              - Longitude for distance sorting (optional)
 *
 * Returns AI-enriched suggestions:
 *   - Query expansion: "pizza" -> also "pizzaria", "rodizio pizza"
 *   - Typo correction: "hambuger" -> "hamburguer"
 *   - Category mapping: "comida japonesa" -> category filter
 *   - Natural language: "algo barato perto" -> sort by price, filter by distance
 *   - Time-aware: evening prioritizes dinner restaurants
 *   - Personal: boosts stores the customer ordered from before
 *
 * Cache: 2 min per query+city via CacheHelper (Redis/APCu/File)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

setCorsHeaders();

// ============================================================
// Hardcoded keyword mappings (fast, no AI call needed)
// ============================================================

/** Query expansion: term -> additional search terms */
const QUERY_EXPANSIONS = [
    'pizza'         => ['pizzaria', 'rodizio pizza', 'pizza delivery'],
    'pizzaria'      => ['pizza', 'rodizio pizza'],
    'burger'        => ['hamburgueria', 'hamburguer', 'lanche'],
    'hamburguer'    => ['hamburgueria', 'burger', 'lanche'],
    'hamburgueria'  => ['hamburguer', 'burger', 'lanche'],
    'lanche'        => ['hamburgueria', 'hamburguer', 'hot dog', 'sanduiche'],
    'sushi'         => ['japonesa', 'sashimi', 'temaki', 'japanese'],
    'japonesa'      => ['sushi', 'sashimi', 'temaki', 'japanese', 'ramen'],
    'comida japonesa' => ['sushi', 'sashimi', 'temaki', 'japanese', 'ramen'],
    'acai'          => ['acaiteria', 'bowl', 'frozen'],
    'pastel'        => ['pastelaria', 'feira'],
    'churrasco'     => ['churrasquinho', 'espetinho', 'carne'],
    'carne'         => ['acougue', 'churrasco', 'bife'],
    'fruta'         => ['frutas', 'frutaria', 'hortifruti', 'feira'],
    'frutas'        => ['fruta', 'frutaria', 'hortifruti', 'feira'],
    'hortifruti'    => ['frutas', 'verduras', 'legumes', 'feira'],
    'verdura'       => ['hortifruti', 'legumes', 'feira', 'verduras'],
    'padaria'       => ['pao', 'bolo', 'confeitaria', 'cafe'],
    'pao'           => ['padaria', 'baguete', 'frances'],
    'cafe'          => ['cafeteria', 'padaria', 'cappuccino', 'expresso'],
    'doce'          => ['confeitaria', 'bolo', 'chocolate', 'sobremesa'],
    'bolo'          => ['confeitaria', 'doce', 'padaria', 'aniversario'],
    'sorvete'       => ['sorveteria', 'gelato', 'acai', 'frozen'],
    'cerveja'       => ['bebida', 'bar', 'chopp', 'drink'],
    'bebida'        => ['cerveja', 'refrigerante', 'suco', 'agua'],
    'mercado'       => ['supermercado', 'minimercado', 'mercearia'],
    'farmacia'      => ['remedio', 'drogaria', 'medicamento'],
    'petshop'       => ['pet', 'racao', 'animal'],
    'fit'           => ['saudavel', 'light', 'integral', 'salada', 'fitness'],
    'saudavel'      => ['fit', 'light', 'integral', 'salada', 'low carb'],
    'vegano'        => ['vegetariano', 'plant based', 'saudavel'],
    'vegetariano'   => ['vegano', 'salada', 'saudavel'],
    'chinesa'       => ['chines', 'yakisoba', 'guioza', 'oriental'],
    'mexicana'      => ['mexicano', 'burrito', 'taco', 'nachos'],
    'italiana'      => ['italiano', 'massa', 'lasanha', 'pizza'],
    'arabe'         => ['esfiha', 'kibe', 'shawarma', 'falafel'],
    'esfiha'        => ['arabe', 'esfirra', 'kibe'],
    'marmita'       => ['marmitex', 'prato feito', 'pf', 'refeicao', 'almoco'],
    'almoco'        => ['marmita', 'prato feito', 'restaurante', 'buffet'],
    'jantar'        => ['restaurante', 'pizza', 'hamburguer', 'comida'],
    'cafe da manha' => ['padaria', 'pao', 'cafe', 'leite', 'manteiga'],
];

/** Common typo corrections (lowercase) */
const TYPO_CORRECTIONS = [
    'hambuger'      => 'hamburguer',
    'hambuguer'     => 'hamburguer',
    'hanburger'     => 'hamburguer',
    'hamburger'     => 'hamburguer',
    'hamburgue'     => 'hamburguer',
    'hambúrger'     => 'hamburguer',
    'hambuerguer'   => 'hamburguer',
    'piza'          => 'pizza',
    'pissa'         => 'pizza',
    'piiza'         => 'pizza',
    'shushi'        => 'sushi',
    'suchi'         => 'sushi',
    'suhsi'         => 'sushi',
    'suhi'          => 'sushi',
    'assai'         => 'acai',
    'açaí'          => 'acai',
    'açai'          => 'acai',
    'acaí'          => 'acai',
    'akai'          => 'acai',
    'farmácia'      => 'farmacia',
    'sorvetee'      => 'sorvete',
    'sorveti'       => 'sorvete',
    'xurrasco'      => 'churrasco',
    'churasco'      => 'churrasco',
    'cerverja'      => 'cerveja',
    'cerveja '      => 'cerveja',
    'refri'         => 'refrigerante',
    'chocolat'      => 'chocolate',
    'chocolote'     => 'chocolate',
    'paderia'       => 'padaria',
    'padarya'       => 'padaria',
    'enpanada'      => 'empanada',
    'esfirrra'      => 'esfiha',
    'esfira'        => 'esfiha',
    'esfirra'       => 'esfiha',
    'lasannha'      => 'lasanha',
    'lazanha'       => 'lasanha',
    'temak'         => 'temaki',
    'temake'        => 'temaki',
    'cappucino'     => 'cappuccino',
    'capuccino'     => 'cappuccino',
    'sanduich'      => 'sanduiche',
    'sanduíche'     => 'sanduiche',
    'hortifruit'    => 'hortifruti',
    'ortifruit'     => 'hortifruti',
    'ortfruti'      => 'hortifruti',
    'petshoop'      => 'petshop',
    'pet shop'      => 'petshop',
    'supemercado'   => 'supermercado',
    'superemrcado'  => 'supermercado',
    'yakissoba'     => 'yakisoba',
    'iakisoba'      => 'yakisoba',
    'pastelariaa'   => 'pastelaria',
];

/** Category mapping: search term -> store category values */
const CATEGORY_MAP = [
    'sushi'             => ['sushi', 'japanese', 'japonesa'],
    'japonesa'          => ['sushi', 'japanese', 'japonesa'],
    'comida japonesa'   => ['sushi', 'japanese', 'japonesa'],
    'pizza'             => ['pizzaria', 'pizza'],
    'pizzaria'          => ['pizzaria', 'pizza'],
    'hamburguer'        => ['hamburgueria', 'burger', 'lanchonete'],
    'hamburgueria'      => ['hamburgueria', 'burger', 'lanchonete'],
    'burger'            => ['hamburgueria', 'burger', 'lanchonete'],
    'lanche'            => ['lanchonete', 'hamburgueria', 'fast food'],
    'acai'              => ['acaiteria', 'acai', 'sorveteria'],
    'sorvete'           => ['sorveteria', 'acai'],
    'cafe'              => ['cafeteria', 'padaria', 'cafe'],
    'padaria'           => ['padaria', 'confeitaria'],
    'bolo'              => ['confeitaria', 'padaria', 'doces'],
    'doce'              => ['confeitaria', 'doces', 'padaria'],
    'churrasco'         => ['churrascaria', 'churrasquinho', 'espetinho'],
    'mercado'           => ['mercado', 'supermercado', 'minimercado'],
    'farmacia'          => ['farmacia', 'drogaria'],
    'petshop'           => ['petshop', 'pet'],
    'arabe'             => ['arabe', 'esfiha', 'oriental'],
    'chinesa'           => ['chines', 'chinesa', 'oriental'],
    'mexicana'          => ['mexicana', 'mexicano'],
    'italiana'          => ['italiana', 'italiano', 'massa'],
    'fit'               => ['fit', 'saudavel', 'fitness'],
    'saudavel'          => ['fit', 'saudavel', 'fitness'],
    'vegano'            => ['vegano', 'vegetariano', 'saudavel'],
    'vegetariano'       => ['vegetariano', 'vegano', 'saudavel'],
    'marmita'           => ['marmitex', 'marmita', 'restaurante', 'pf'],
    'bebida'            => ['bebida', 'bar', 'adega', 'distribuidora'],
    'cerveja'           => ['bebida', 'bar', 'adega', 'distribuidora'],
];

/** Natural language intent keywords */
const INTENT_KEYWORDS = [
    // Price sorting
    'barato'    => ['sort' => 'price_asc',      'label' => 'Mais barato primeiro'],
    'baratos'   => ['sort' => 'price_asc',      'label' => 'Mais barato primeiro'],
    'economico' => ['sort' => 'price_asc',      'label' => 'Mais economico primeiro'],
    'caro'      => ['sort' => 'price_desc',     'label' => 'Mais caro primeiro'],
    'premium'   => ['sort' => 'price_desc',     'label' => 'Premium primeiro'],
    // Speed sorting
    'rapido'    => ['sort' => 'delivery_time',  'label' => 'Entrega mais rapida'],
    'rapida'    => ['sort' => 'delivery_time',  'label' => 'Entrega mais rapida'],
    'urgente'   => ['sort' => 'delivery_time',  'label' => 'Entrega mais rapida'],
    'agora'     => ['sort' => 'delivery_time',  'label' => 'Entrega mais rapida'],
    // Distance sorting
    'perto'     => ['sort' => 'distance',       'label' => 'Mais perto primeiro'],
    'proximo'   => ['sort' => 'distance',       'label' => 'Mais perto primeiro'],
    'proxima'   => ['sort' => 'distance',       'label' => 'Mais perto primeiro'],
    'pertinho'  => ['sort' => 'distance',       'label' => 'Mais perto primeiro'],
    // Rating sorting
    'melhor'    => ['sort' => 'rating',         'label' => 'Melhor avaliacao primeiro'],
    'melhores'  => ['sort' => 'rating',         'label' => 'Melhor avaliacao primeiro'],
    'top'       => ['sort' => 'rating',         'label' => 'Melhor avaliacao primeiro'],
    'bom'       => ['sort' => 'rating',         'label' => 'Melhor avaliacao primeiro'],
    // Promo filter
    'promocao'  => ['filter' => 'promo',        'label' => 'Em promocao'],
    'promo'     => ['filter' => 'promo',        'label' => 'Em promocao'],
    'desconto'  => ['filter' => 'promo',        'label' => 'Com desconto'],
    'oferta'    => ['filter' => 'promo',        'label' => 'Em oferta'],
    // Open filter
    'aberto'    => ['filter' => 'open',         'label' => 'Aberto agora'],
    'aberta'    => ['filter' => 'open',         'label' => 'Aberto agora'],
    'abertos'   => ['filter' => 'open',         'label' => 'Abertos agora'],
    // Free delivery
    'frete gratis' => ['filter' => 'free_delivery', 'label' => 'Frete gratis'],
    'entrega gratis' => ['filter' => 'free_delivery', 'label' => 'Entrega gratis'],
];

// ============================================================
// Main handler
// ============================================================

try {
    $q = trim($_GET['q'] ?? '');
    $city = trim($_GET['city'] ?? '');
    $lat = floatval($_GET['lat'] ?? 0);
    $lng = floatval($_GET['lng'] ?? 0);

    // Get customer_id from query param or JWT
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if (!$customerId) {
        $customerId = getCustomerIdFromToken();
    }

    if (mb_strlen($q) < 2) {
        response(false, null, "Busca precisa ter pelo menos 2 caracteres", 400);
    }

    if (mb_strlen($q) > 200) {
        response(false, null, "Busca muito longa", 400);
    }

    // Sanitize query
    $q = preg_replace('/[^\p{L}\p{N}\s]/u', '', $q);
    $q = preg_replace('/\s+/', ' ', $q);
    $q = trim($q);

    if (mb_strlen($q) < 2) {
        response(false, null, "Busca invalida", 400);
    }

    // Cache key includes query, city, customer, and hour (time-aware)
    $currentHour = (int)date('G');
    $cacheKey = "smart_search_v1:" . md5(mb_strtolower($q) . "|{$city}|{$customerId}|{$currentHour}");

    $result = CacheHelper::remember($cacheKey, 120, function() use ($q, $city, $customerId, $lat, $lng, $currentHour) {
        $db = getDB();
        return processSmartSearch($db, $q, $city, $customerId, $lat, $lng, $currentHour);
    });

    response(true, $result);

} catch (Exception $e) {
    error_log("[SmartSearch] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar busca inteligente", 500);
}

// ============================================================
// Core logic
// ============================================================

function processSmartSearch(PDO $db, string $q, string $city, int $customerId, float $lat, float $lng, int $hour): array {
    $qLower = mb_strtolower($q);
    $words = preg_split('/\s+/', $qLower);

    // Step 1: Typo correction
    $correctedQuery = correctTypos($qLower);
    $wasCorrected = ($correctedQuery !== $qLower);
    $searchQuery = $wasCorrected ? $correctedQuery : $qLower;

    // Step 2: Parse natural language intents (extract sort/filter keywords)
    $intents = parseIntents($words);
    $filtersApplied = $intents['filters'];
    $sortApplied = $intents['sort'];
    // Remove intent keywords from search to get the "content" query
    $contentQuery = $intents['remaining_query'];
    if (empty($contentQuery)) {
        $contentQuery = $searchQuery; // fallback: use full query if all words were intents
    }

    // Step 3: Get expanded terms
    $expandedTerms = getExpandedTerms($contentQuery);

    // Step 4: Get category filter
    $categoryFilter = getCategoryFilter($contentQuery);

    // Step 5: Time-aware priority
    $timePriority = getTimePriority($hour);

    // Step 6: Build combined search terms
    $allSearchTerms = array_unique(array_merge([$contentQuery], $expandedTerms));

    // Step 7: Search stores
    $stores = searchStores($db, $allSearchTerms, $categoryFilter, $city, $sortApplied, $filtersApplied, $lat, $lng);

    // Step 8: Search products
    $products = searchProducts($db, $allSearchTerms, $city, $sortApplied, $filtersApplied);

    // Step 9: Personalization - boost stores customer ordered from
    if ($customerId > 0) {
        $stores = boostFavoriteStores($db, $stores, $customerId);
    }

    // Step 10: Time-aware re-ranking
    if ($timePriority) {
        $stores = applyTimePriority($stores, $timePriority);
    }

    // Build suggestions list (mixed stores, products, categories)
    $suggestions = buildSuggestions($stores, $products, $categoryFilter, $expandedTerms);

    return [
        'query' => $q,
        'corrected_query' => $wasCorrected ? $correctedQuery : null,
        'expanded_terms' => $expandedTerms,
        'filters_applied' => $filtersApplied,
        'sort_applied' => $sortApplied,
        'time_context' => $timePriority,
        'personalized' => $customerId > 0,
        'suggestions' => $suggestions,
        'stores' => array_slice($stores, 0, 8),
        'products' => array_slice($products, 0, 10),
    ];
}

/**
 * Correct typos using hardcoded map + word-level matching
 */
function correctTypos(string $query): string {
    // Check full query first
    if (isset(TYPO_CORRECTIONS[$query])) {
        return TYPO_CORRECTIONS[$query];
    }

    // Check word by word
    $words = preg_split('/\s+/', $query);
    $corrected = false;
    foreach ($words as &$word) {
        if (isset(TYPO_CORRECTIONS[$word])) {
            $word = TYPO_CORRECTIONS[$word];
            $corrected = true;
        }
    }
    unset($word);

    // Check two-word combinations
    for ($i = 0; $i < count($words) - 1; $i++) {
        $pair = $words[$i] . ' ' . $words[$i + 1];
        if (isset(TYPO_CORRECTIONS[$pair])) {
            $words[$i] = TYPO_CORRECTIONS[$pair];
            unset($words[$i + 1]);
            $words = array_values($words);
            $corrected = true;
            break;
        }
    }

    return implode(' ', $words);
}

/**
 * Parse natural language intents from query words
 */
function parseIntents(array $words): array {
    $filters = [];
    $sort = null;
    $remaining = [];

    // Check multi-word intents first
    $fullText = implode(' ', $words);
    foreach (INTENT_KEYWORDS as $keyword => $intent) {
        if (str_contains($fullText, $keyword) && str_contains($keyword, ' ')) {
            if (isset($intent['sort']) && !$sort) {
                $sort = $intent['sort'];
            }
            if (isset($intent['filter'])) {
                $filters[] = [
                    'type' => $intent['filter'],
                    'label' => $intent['label'],
                ];
            }
            // Remove multi-word keyword from text
            $fullText = str_replace($keyword, '', $fullText);
        }
    }

    // Re-split after multi-word removal
    $words = preg_split('/\s+/', trim($fullText));

    foreach ($words as $word) {
        $word = trim($word);
        if (empty($word)) continue;

        if (isset(INTENT_KEYWORDS[$word])) {
            $intent = INTENT_KEYWORDS[$word];
            if (isset($intent['sort']) && !$sort) {
                $sort = $intent['sort'];
            }
            if (isset($intent['filter'])) {
                $filters[] = [
                    'type' => $intent['filter'],
                    'label' => $intent['label'],
                ];
            }
            // Don't add intent words to remaining content query
        } else {
            $remaining[] = $word;
        }
    }

    // Deduplicate filters by type
    $seen = [];
    $uniqueFilters = [];
    foreach ($filters as $f) {
        if (!in_array($f['type'], $seen)) {
            $uniqueFilters[] = $f;
            $seen[] = $f['type'];
        }
    }

    return [
        'filters' => $uniqueFilters,
        'sort' => $sort,
        'remaining_query' => implode(' ', $remaining),
    ];
}

/**
 * Get expanded search terms from hardcoded map
 */
function getExpandedTerms(string $query): array {
    $qLower = mb_strtolower($query);
    $expanded = [];

    // Check full query
    if (isset(QUERY_EXPANSIONS[$qLower])) {
        $expanded = QUERY_EXPANSIONS[$qLower];
    }

    // Check individual words
    $words = preg_split('/\s+/', $qLower);
    foreach ($words as $word) {
        if (isset(QUERY_EXPANSIONS[$word])) {
            foreach (QUERY_EXPANSIONS[$word] as $term) {
                if (!in_array($term, $expanded) && $term !== $qLower) {
                    $expanded[] = $term;
                }
            }
        }
    }

    return array_slice($expanded, 0, 6); // limit expansions
}

/**
 * Map query to store category filters
 */
function getCategoryFilter(string $query): array {
    $qLower = mb_strtolower($query);

    // Check full query
    if (isset(CATEGORY_MAP[$qLower])) {
        return CATEGORY_MAP[$qLower];
    }

    // Check individual words
    $categories = [];
    $words = preg_split('/\s+/', $qLower);
    foreach ($words as $word) {
        if (isset(CATEGORY_MAP[$word])) {
            foreach (CATEGORY_MAP[$word] as $cat) {
                if (!in_array($cat, $categories)) {
                    $categories[] = $cat;
                }
            }
        }
    }

    return $categories;
}

/**
 * Determine time-based priority context
 */
function getTimePriority(int $hour): ?string {
    if ($hour >= 6 && $hour < 10) return 'cafe_da_manha';
    if ($hour >= 11 && $hour < 14) return 'almoco';
    if ($hour >= 14 && $hour < 17) return 'lanche_tarde';
    if ($hour >= 17 && $hour < 22) return 'jantar';
    if ($hour >= 22 || $hour < 6) return 'noturno';
    return null;
}

/**
 * Search stores by name, trade_name, categoria
 */
function searchStores(PDO $db, array $terms, array $categoryFilter, string $city, ?string $sort, array $filters, float $lat, float $lng): array {
    // Build ILIKE conditions for all terms
    $conditions = ["mp.status = '1'"];
    $params = [];
    $paramIdx = 0;

    // Name/trade_name matching for all terms (OR between terms)
    $nameConditions = [];
    foreach ($terms as $term) {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $term);
        $like = "%{$escaped}%";
        $p1 = ":name{$paramIdx}";
        $p2 = ":trade{$paramIdx}";
        $p3 = ":cat{$paramIdx}";
        $nameConditions[] = "(mp.name ILIKE {$p1} OR mp.trade_name ILIKE {$p2} OR mp.categoria ILIKE {$p3})";
        $params["name{$paramIdx}"] = $like;
        $params["trade{$paramIdx}"] = $like;
        $params["cat{$paramIdx}"] = $like;
        $paramIdx++;
    }

    // Category filter (if mapped)
    $catConditions = [];
    if (!empty($categoryFilter)) {
        foreach ($categoryFilter as $idx => $cat) {
            $catKey = "catfilter{$idx}";
            $catConditions[] = "mp.categoria ILIKE :{$catKey}";
            $params[$catKey] = "%{$cat}%";
        }
    }

    // Combine: (name matches) OR (category matches)
    $searchOr = [];
    if (!empty($nameConditions)) {
        $searchOr[] = '(' . implode(' OR ', $nameConditions) . ')';
    }
    if (!empty($catConditions)) {
        $searchOr[] = '(' . implode(' OR ', $catConditions) . ')';
    }
    if (!empty($searchOr)) {
        $conditions[] = '(' . implode(' OR ', $searchOr) . ')';
    }

    // City filter
    if (!empty($city)) {
        $conditions[] = "mp.city ILIKE :city";
        $params['city'] = "%{$city}%";
    }

    // Open filter
    foreach ($filters as $f) {
        if ($f['type'] === 'open') {
            $conditions[] = "mp.is_open = 1";
        }
        if ($f['type'] === 'free_delivery') {
            $conditions[] = "(mp.delivery_fee = 0 OR mp.delivery_fee IS NULL)";
        }
    }

    // Order by
    $orderBy = "mp.rating DESC, mp.name ASC";
    if ($sort === 'price_asc') {
        $orderBy = "mp.delivery_fee ASC, mp.rating DESC";
    } elseif ($sort === 'price_desc') {
        $orderBy = "mp.delivery_fee DESC, mp.rating DESC";
    } elseif ($sort === 'delivery_time') {
        $orderBy = "mp.delivery_time_min ASC, mp.rating DESC";
    } elseif ($sort === 'rating') {
        $orderBy = "mp.rating DESC, mp.name ASC";
    } elseif ($sort === 'distance' && $lat && $lng) {
        // Simple Haversine approximation for sorting
        $orderBy = "(
            6371 * acos(
                cos(radians(:sort_lat)) * cos(radians(COALESCE(mp.latitude, 0)))
                * cos(radians(COALESCE(mp.longitude, 0)) - radians(:sort_lng))
                + sin(radians(:sort_lat2)) * sin(radians(COALESCE(mp.latitude, 0)))
            )
        ) ASC";
        $params['sort_lat'] = $lat;
        $params['sort_lng'] = $lng;
        $params['sort_lat2'] = $lat;
    }

    $where = implode(' AND ', $conditions);

    $sql = "
        SELECT mp.partner_id, mp.name, mp.trade_name, mp.logo, mp.rating, mp.categoria,
               mp.delivery_fee, mp.delivery_time_min, mp.is_open, mp.city
        FROM om_market_partners mp
        WHERE {$where}
        ORDER BY {$orderBy}
        LIMIT 10
    ";

    try {
        $stmt = dbQuery($db, $sql, $params);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("[SmartSearch] Store query error: " . $e->getMessage());
        $rows = [];
    }

    return array_map(function($s) {
        return [
            'id' => (int)$s['partner_id'],
            'type' => 'store',
            'name' => $s['name'],
            'trade_name' => $s['trade_name'] ?? null,
            'logo' => $s['logo'],
            'category' => $s['categoria'] ?? 'mercado',
            'rating' => round((float)($s['rating'] ?? 5.0), 1),
            'delivery_fee' => (float)($s['delivery_fee'] ?? 0),
            'delivery_time_min' => (int)($s['delivery_time_min'] ?? 60),
            'is_open' => (int)($s['is_open'] ?? 0) === 1,
            'city' => $s['city'] ?? null,
            'boost_score' => 0, // will be set by personalization
        ];
    }, $rows);
}

/**
 * Search products by name and description
 */
function searchProducts(PDO $db, array $terms, string $city, ?string $sort, array $filters): array {
    $conditions = ["p.status = '1'", "mp.status = '1'"];
    $params = [];
    $paramIdx = 0;

    // Name/description matching for all terms (OR between terms)
    $nameConditions = [];
    foreach ($terms as $term) {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $term);
        $like = "%{$escaped}%";
        $p1 = ":pname{$paramIdx}";
        $p2 = ":pdesc{$paramIdx}";
        $nameConditions[] = "(p.name ILIKE {$p1} OR p.description ILIKE {$p2})";
        $params["pname{$paramIdx}"] = $like;
        $params["pdesc{$paramIdx}"] = $like;
        $paramIdx++;
    }

    if (!empty($nameConditions)) {
        $conditions[] = '(' . implode(' OR ', $nameConditions) . ')';
    }

    // City filter on partner
    if (!empty($city)) {
        $conditions[] = "mp.city ILIKE :pcity";
        $params['pcity'] = "%{$city}%";
    }

    // Open filter
    foreach ($filters as $f) {
        if ($f['type'] === 'open') {
            $conditions[] = "mp.is_open = 1";
        }
    }

    // Promo filter
    $hasPromoFilter = false;
    foreach ($filters as $f) {
        if ($f['type'] === 'promo') {
            $hasPromoFilter = true;
            $conditions[] = "p.special_price IS NOT NULL AND p.special_price > 0 AND p.special_price < p.price";
        }
    }

    // Order by
    $orderBy = "p.name ASC";
    if ($sort === 'price_asc') {
        $orderBy = "COALESCE(p.special_price, p.price) ASC";
    } elseif ($sort === 'price_desc') {
        $orderBy = "COALESCE(p.special_price, p.price) DESC";
    } elseif ($sort === 'rating') {
        $orderBy = "mp.rating DESC, p.name ASC";
    }

    $where = implode(' AND ', $conditions);

    $sql = "
        SELECT p.product_id, p.name, p.price, p.special_price, p.image, p.unit,
               mp.partner_id, mp.name as partner_name, mp.logo as partner_logo
        FROM om_market_products p
        INNER JOIN om_market_partners mp ON p.partner_id = mp.partner_id
        WHERE {$where}
        ORDER BY {$orderBy}
        LIMIT 12
    ";

    try {
        $stmt = dbQuery($db, $sql, $params);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("[SmartSearch] Product query error: " . $e->getMessage());
        $rows = [];
    }

    return array_map(function($p) {
        $price = (float)$p['price'];
        $promoPrice = $p['special_price'] ? (float)$p['special_price'] : null;
        $isPromo = $promoPrice && $promoPrice > 0 && $promoPrice < $price;

        return [
            'id' => (int)$p['product_id'],
            'type' => 'product',
            'name' => $p['name'],
            'price' => $price,
            'promo_price' => $isPromo ? $promoPrice : null,
            'image' => $p['image'],
            'unit' => $p['unit'] ?? 'un',
            'partner_id' => (int)$p['partner_id'],
            'partner_name' => $p['partner_name'],
            'partner_logo' => $p['partner_logo'],
        ];
    }, $rows);
}

/**
 * Boost stores the customer has ordered from before
 */
function boostFavoriteStores(PDO $db, array $stores, int $customerId): array {
    if (empty($stores)) return $stores;

    $storeIds = array_column($stores, 'id');
    if (empty($storeIds)) return $stores;

    $placeholders = implode(',', array_fill(0, count($storeIds), '?'));

    try {
        $stmt = dbQuery($db, "
            SELECT partner_id, COUNT(*) as order_count
            FROM om_market_orders
            WHERE customer_id = ?
              AND partner_id IN ({$placeholders})
              AND status NOT IN ('cancelado')
            GROUP BY partner_id
        ", array_merge([$customerId], $storeIds));

        $orderCounts = [];
        while ($row = $stmt->fetch()) {
            $orderCounts[(int)$row['partner_id']] = (int)$row['order_count'];
        }

        // Apply boost score
        foreach ($stores as &$store) {
            $count = $orderCounts[$store['id']] ?? 0;
            $store['boost_score'] = $count;
        }
        unset($store);

        // Sort: boosted stores first (by order count desc), then original order
        usort($stores, function($a, $b) {
            if ($a['boost_score'] > 0 && $b['boost_score'] === 0) return -1;
            if ($a['boost_score'] === 0 && $b['boost_score'] > 0) return 1;
            if ($a['boost_score'] !== $b['boost_score']) return $b['boost_score'] - $a['boost_score'];
            return 0; // preserve original order
        });

    } catch (Exception $e) {
        error_log("[SmartSearch] Boost query error: " . $e->getMessage());
    }

    return $stores;
}

/**
 * Apply time-of-day priority to store ranking
 */
function applyTimePriority(array $stores, string $timePriority): array {
    // Map time context to preferred categories
    $categoryBoost = [
        'cafe_da_manha' => ['padaria', 'cafe', 'cafeteria', 'confeitaria'],
        'almoco'        => ['restaurante', 'marmita', 'marmitex', 'pf', 'buffet', 'prato feito', 'churrascaria'],
        'lanche_tarde'  => ['acaiteria', 'acai', 'sorveteria', 'cafeteria', 'padaria', 'lanchonete', 'doces'],
        'jantar'        => ['pizzaria', 'pizza', 'hamburgueria', 'japonesa', 'sushi', 'restaurante', 'churrascaria', 'italiana'],
        'noturno'       => ['pizzaria', 'hamburgueria', 'lanchonete', 'fast food', 'bar'],
    ];

    $boostCategories = $categoryBoost[$timePriority] ?? [];
    if (empty($boostCategories)) return $stores;

    foreach ($stores as &$store) {
        $cat = mb_strtolower($store['category'] ?? '');
        foreach ($boostCategories as $boost) {
            if (str_contains($cat, $boost)) {
                // Small time boost (don't override personalization boost)
                $store['boost_score'] = ($store['boost_score'] ?? 0) + 1;
                break;
            }
        }
    }
    unset($store);

    // Re-sort preserving heavily boosted (personal) above time-boosted
    usort($stores, function($a, $b) {
        $diff = ($b['boost_score'] ?? 0) - ($a['boost_score'] ?? 0);
        return $diff !== 0 ? $diff : 0;
    });

    return $stores;
}

/**
 * Build a unified suggestions list for the frontend autocomplete dropdown
 */
function buildSuggestions(array $stores, array $products, array $categoryFilter, array $expandedTerms): array {
    $suggestions = [];

    // Add category suggestions
    foreach ($categoryFilter as $cat) {
        $suggestions[] = [
            'text' => ucfirst($cat),
            'type' => 'category',
        ];
    }

    // Add store suggestions (top 5)
    foreach (array_slice($stores, 0, 5) as $store) {
        $suggestions[] = [
            'text' => $store['name'],
            'type' => 'store',
            'id' => $store['id'],
        ];
    }

    // Add product suggestions (top 5)
    foreach (array_slice($products, 0, 5) as $product) {
        $suggestions[] = [
            'text' => $product['name'],
            'type' => 'product',
            'id' => $product['id'],
            'partner_id' => $product['partner_id'],
        ];
    }

    // Add expanded term suggestions
    foreach (array_slice($expandedTerms, 0, 3) as $term) {
        // Avoid duplicates with category suggestions
        $isDuplicate = false;
        foreach ($suggestions as $s) {
            if (mb_strtolower($s['text']) === mb_strtolower($term)) {
                $isDuplicate = true;
                break;
            }
        }
        if (!$isDuplicate) {
            $suggestions[] = [
                'text' => ucfirst($term),
                'type' => 'expanded_term',
            ];
        }
    }

    return array_slice($suggestions, 0, 15);
}
