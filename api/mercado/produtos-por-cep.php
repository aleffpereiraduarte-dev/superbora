<?php
/**
 * API de Produtos do Mercado por CEP
 *
 * Retorna produtos disponíveis para entrega no CEP informado
 * Integrado com o index principal - sem página separada
 *
 * GET /api/mercado/produtos-por-cep.php?cep=01310100
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

// SECURITY: CORS origin whitelist (replaces Access-Control-Allow-Origin: *)
$_corsAllowed = ['https://superbora.com.br', 'https://www.superbora.com.br', 'https://onemundo.com.br', 'https://www.onemundo.com.br'];
$_corsOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($_corsOrigin, $_corsAllowed, true)) {
    header("Access-Control-Allow-Origin: " . $_corsOrigin);
    header("Vary: Origin");
}

// Use single absolute config path
require_once __DIR__ . '/config/database.php';

$cep = preg_replace('/\D/', '', $_GET['cep'] ?? '');
$categoria = $_GET['categoria'] ?? '';
$limit = min((int)($_GET['limit'] ?? 20), 50);
$offset = max((int)($_GET['offset'] ?? 0), 0);

if (strlen($cep) !== 8) {
    echo json_encode([
        'success' => false,
        'error' => 'CEP inválido. Informe 8 dígitos.',
        'produtos' => []
    ]);
    exit;
}

try {
    $pdo = getDB();

    // Buscar cidade pelo CEP (com cache)
    $cidade = buscarCidadePorCep($cep);

    if (!$cidade) {
        echo json_encode([
            'success' => false,
            'error' => 'CEP não encontrado',
            'produtos' => []
        ]);
        exit;
    }

    // Buscar parceiros que atendem a cidade
    $parceiros = buscarParceirosAtivos($pdo, $cidade);

    if (empty($parceiros)) {
        echo json_encode([
            'success' => true,
            'disponivel' => false,
            'cidade' => $cidade,
            'mensagem' => "Ainda não temos mercados disponíveis em {$cidade}. Em breve!",
            'produtos' => []
        ]);
        exit;
    }

    // Buscar produtos dos parceiros
    $produtos = buscarProdutosParceiros($pdo, $parceiros, $categoria, $limit, $offset);

    // Buscar categorias disponíveis
    $categorias = buscarCategoriasParceiros($pdo, $parceiros);

    echo json_encode([
        'success' => true,
        'disponivel' => true,
        'cidade' => $cidade,
        'cep' => $cep,
        'parceiros' => count($parceiros),
        'total_produtos' => count($produtos),
        'categorias' => $categorias,
        'produtos' => $produtos
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Produtos por CEP error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao buscar produtos',
        'produtos' => []
    ]);
}

/**
 * Busca cidade pelo CEP via ViaCEP com cache
 */
function buscarCidadePorCep($cep) {
    $cacheDir = '/tmp/superbora-cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
    $cacheFile = $cacheDir . '/viacep_' . $cep . '.json';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $data = json_decode(file_get_contents($cacheFile), true);
        return $data['localidade'] ?? null;
    }

    $viaCep = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/");
    if ($viaCep) {
        $data = json_decode($viaCep, true);
        if (!isset($data['erro'])) {
            @file_put_contents($cacheFile, $viaCep);
            return $data['localidade'] ?? null;
        }
    }

    return null;
}

/**
 * Busca parceiros ativos que atendem a cidade
 */
function buscarParceirosAtivos($pdo, $cidade) {
    // Normalizar cidade
    $cidadeNorm = mb_strtolower(trim($cidade));

    // Buscar vendedores com produtos do tipo mercado
    $stmt = $pdo->prepare("
        SELECT DISTINCT v.seller_id, v.store_name, v.store_city,
               COALESCE(v.store_logo, 'catalog/marketplace/default-store.png') as logo,
               COALESCE(v.store_shipping_charge, 5.00) as delivery_fee
        FROM oc_purpletree_vendor_stores v
        WHERE v.store_status::text = '1'
          AND LOWER(v.store_city) LIKE ?
          AND EXISTS (
              SELECT 1 FROM oc_product p
              JOIN oc_purpletree_vendor_products vp ON p.product_id = vp.product_id
              WHERE vp.seller_id = v.seller_id
                AND p.status::text = '1'
                AND p.quantity > 0
          )
        ORDER BY v.seller_id
        LIMIT 20
    ");
    $stmt->execute(['%' . $cidadeNorm . '%']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Busca produtos dos parceiros
 */
function buscarProdutosParceiros($pdo, $parceiros, $categoria = '', $limit = 20, $offset = 0) {
    if (empty($parceiros)) {
        return [];
    }

    $sellerIds = array_column($parceiros, 'seller_id');
    $placeholders = implode(',', array_fill(0, count($sellerIds), '?'));

    $params = $sellerIds;

    $categoriaWhere = '';
    if ($categoria) {
        $categoriaWhere = " AND EXISTS (
            SELECT 1 FROM oc_product_to_category p2c
            JOIN oc_category_description cd ON p2c.category_id = cd.category_id
            WHERE p2c.product_id = p.product_id
              AND LOWER(cd.name) LIKE ?
        )";
        $params[] = '%' . mb_strtolower($categoria) . '%';
    }

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare("
        SELECT
            p.product_id,
            pd.name,
            p.price,
            p.image,
            p.quantity,
            v.seller_id,
            v.store_name,
            COALESCE(v.store_shipping_charge, 5.00) as delivery_fee,
            (SELECT ps.price FROM oc_product_special ps
             WHERE ps.product_id = p.product_id
               AND (ps.date_start IS NULL OR ps.date_start <= CURRENT_DATE)
               AND (ps.date_end IS NULL OR ps.date_end >= CURRENT_DATE)
             ORDER BY ps.priority LIMIT 1) as special_price
        FROM oc_product p
        JOIN oc_product_description pd ON p.product_id = pd.product_id AND pd.language_id = 1
        JOIN oc_purpletree_vendor_products vp ON p.product_id = vp.product_id
        JOIN oc_purpletree_vendor_stores v ON vp.seller_id = v.seller_id
        WHERE vp.seller_id IN ($placeholders)
          AND p.status::text = '1'
          AND p.quantity > 0
          $categoriaWhere
        ORDER BY p.sort_order, p.product_id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    $produtos = [];
    $baseUrl = 'https://onemundo.com.br/';

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $precoOriginal = floatval($row['price']);
        $precoSpecial = floatval($row['special_price'] ?? 0);
        $precoFinal = $precoSpecial > 0 ? $precoSpecial : $precoOriginal;
        $desconto = ($precoSpecial > 0 && $precoOriginal > $precoSpecial)
            ? round((1 - ($precoSpecial / $precoOriginal)) * 100)
            : 0;

        $produtos[] = [
            'product_id' => (int)$row['product_id'],
            'name' => html_entity_decode($row['name'], ENT_QUOTES, 'UTF-8'),
            'price' => $precoFinal,
            'price_formatted' => 'R$ ' . number_format($precoFinal, 2, ',', '.'),
            'original_price' => $desconto > 0 ? 'R$ ' . number_format($precoOriginal, 2, ',', '.') : null,
            'discount' => $desconto,
            'image' => $baseUrl . 'image/' . ($row['image'] ?: 'catalog/placeholder.png'),
            'url' => $baseUrl . 'index.php?route=product/product&product_id=' . $row['product_id'],
            'in_stock' => $row['quantity'] > 0,
            'seller' => [
                'id' => (int)$row['seller_id'],
                'name' => $row['store_name'],
                'delivery_fee' => floatval($row['delivery_fee'] ?? 5.00),
                'delivery_time' => '30-60 min'
            ],
            'type' => 'mercado'
        ];
    }

    return $produtos;
}

/**
 * Busca categorias dos parceiros
 */
function buscarCategoriasParceiros($pdo, $parceiros) {
    if (empty($parceiros)) {
        return [];
    }

    $sellerIds = array_column($parceiros, 'seller_id');
    $placeholders = implode(',', array_fill(0, count($sellerIds), '?'));

    $stmt = $pdo->prepare("
        SELECT DISTINCT cd.category_id, cd.name
        FROM oc_category_description cd
        JOIN oc_product_to_category p2c ON cd.category_id = p2c.category_id
        JOIN oc_product p ON p2c.product_id = p.product_id
        JOIN oc_purpletree_vendor_products vp ON p.product_id = vp.product_id
        WHERE vp.seller_id IN ($placeholders)
          AND p.status::text = '1'
          AND cd.language_id = 1
        ORDER BY cd.name
        LIMIT 20
    ");
    $stmt->execute($sellerIds);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
