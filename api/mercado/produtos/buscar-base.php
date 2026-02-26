<?php
/**
 * API de Busca no Catálogo de Produtos Base (Brain)
 * 57.000+ produtos pré-cadastrados
 * Busca por código de barras, nome ou categoria
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/database.php';
setCorsHeaders();
$db = getDB();

$response = ['success' => false, 'products' => [], 'product' => null];

try {
    $barcode = trim($_GET['barcode'] ?? '');
    $query = trim($_GET['q'] ?? '');
    $categoria = trim($_GET['categoria'] ?? '');
    $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));

    // Buscar por código de barras específico
    if ($barcode) {
        $stmt = $db->prepare("
            SELECT
                product_id as id,
                barcode as ean,
                name,
                descricao as description,
                brand,
                ai_category as category,
                unit,
                CONCAT(
                    COALESCE(size, ''),
                    CASE WHEN volume IS NOT NULL THEN CONCAT(volume, 'ml') ELSE '' END
                ) as weight,
                image,
                suggested_price,
                price_avg as avg_market_price,
                is_organic,
                is_fresh,
                is_frozen,
                ai_benefits,
                ai_tips,
                nutri_score_letter,
                calories, proteins, carbs, fats, fiber, sodium
            FROM om_market_products_base
            WHERE (barcode = ? OR barcode LIKE ?)
            AND status = '1'
            LIMIT 1
        ");
        $stmt->execute([$barcode, "%$barcode%"]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            // Decodificar JSON de benefits
            if ($product['ai_benefits']) {
                $product['ai_benefits'] = json_decode($product['ai_benefits'], true);
            }
            if ($product['ai_tips']) {
                $product['ai_tips'] = json_decode($product['ai_tips'], true);
            }

            $response['success'] = true;
            $response['product'] = $product;
        } else {
            $response['message'] = 'Produto não encontrado';
        }
    }
    // Buscar por texto/categoria
    elseif ($query || $categoria) {
        $where = "WHERE status = '1'";
        $params = [];

        if ($query) {
            $where .= " AND (name LIKE ? OR brand LIKE ? OR barcode LIKE ? OR descricao LIKE ?)";
            $params[] = "%$query%";
            $params[] = "%$query%";
            $params[] = "%$query%";
            $params[] = "%$query%";
        }

        if ($categoria) {
            $where .= " AND (ai_category LIKE ? OR ai_category = ?)";
            $params[] = "%$categoria%";
            $params[] = $categoria;
        }

        $sql = "
            SELECT
                product_id as id,
                barcode as ean,
                name,
                descricao as description,
                brand,
                ai_category as category,
                unit,
                CONCAT(
                    COALESCE(size, ''),
                    CASE WHEN volume IS NOT NULL THEN CONCAT(volume, 'ml') ELSE '' END
                ) as weight,
                image,
                suggested_price,
                price_avg as avg_market_price,
                is_organic,
                is_fresh,
                is_frozen,
                nutri_score_letter
            FROM om_market_products_base
            $where
            ORDER BY
                CASE WHEN name LIKE ? THEN 0 ELSE 1 END,
                ai_confidence DESC,
                name ASC
            LIMIT $limit
        ";

        $params[] = "$query%"; // Prioridade para match no início

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['products'] = $products;
        $response['total'] = count($products);
    }
    else {
        // Retornar categorias disponíveis
        $stmt = $db->query("
            SELECT DISTINCT ai_category as category, COUNT(*) as total
            FROM om_market_products_base
            WHERE ai_category IS NOT NULL AND ai_category != '' AND status = '1'
            GROUP BY ai_category
            ORDER BY total DESC
            LIMIT 30
        ");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['categories'] = $categories;
        $response['message'] = 'Informe barcode, q (busca) ou categoria';
    }

} catch (PDOException $e) {
    $response['error'] = 'Erro no banco de dados';
    error_log("API buscar-base: " . $e->getMessage());
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
