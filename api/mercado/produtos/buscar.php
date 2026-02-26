<?php
/**
 * GET /api/mercado/produtos/buscar.php?q=leite&partner_id=1
 * Busca de produtos do mercado
 * Otimizado com cache (TTL: 5 min) e prepared statements
 *
 * IMPORTANTE: Retorna preco_venda para o cliente (preco_custo + markup)
 * O cliente NUNCA ve o preco_custo, apenas o preco_venda
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";
require_once __DIR__ . "/../../../includes/classes/OmPricing.php";

try {
    $q = trim($_GET["q"] ?? "");
    $partner_id = isset($_GET["partner_id"]) ? (int)$_GET["partner_id"] : null;

    if (strlen($q) < 2 || strlen($q) > 200) response(false, null, "Busca deve ter entre 2 e 200 caracteres", 400);

    $cacheKey = "busca_produtos_v2_" . md5($q . "_" . ($partner_id ?? "all"));

    $data = CacheHelper::remember($cacheKey, 300, function() use ($q, $partner_id) {
        $db = getDB();

        // Inicializar OmPricing
        $pricing = OmPricing::getInstance();
        $pricing->setDb($db);

        $termo = "%{$q}%";
        // Normalizar busca: remover acentos comuns pra fuzzy match
        $termoNorm = "%{$q}%";
        $params = [$termo, $termo, $termo, $termoNorm];

        $where = "p.status::text = '1' AND (p.name ILIKE ? OR p.description ILIKE ? OR p.barcode ILIKE ? OR p.category ILIKE ?)";

        if ($partner_id) {
            $where .= " AND p.partner_id = ?";
            $params[] = $partner_id;
        }

        $stmt = $db->prepare("SELECT p.*, c.name as categoria FROM om_market_products p
                              LEFT JOIN om_market_categories c ON p.category_id = c.category_id
                              WHERE $where LIMIT 50");
        $stmt->execute($params);
        $produtos = $stmt->fetchAll();

        // Processar produtos para retornar preco_venda ao cliente
        $produtos_processados = array_map(function($p) use ($pricing) {
            // Determinar preco de venda
            // Se preco_venda ja esta preenchido, usar ele
            // Caso contrario, calcular on-the-fly
            $preco_venda = null;

            if (!empty($p['preco_venda']) && $p['preco_venda'] > 0) {
                $preco_venda = floatval($p['preco_venda']);
            } elseif (!empty($p['preco_custo']) && $p['preco_custo'] > 0) {
                // Calcular on-the-fly usando OmPricing
                $preco_venda = OmPricing::calcularPrecoVenda(
                    floatval($p['preco_custo']),
                    $p['partner_id'] ?? null,
                    $p['category_id'] ?? null
                );
            } else {
                // Fallback: usar price legado
                $preco_venda = floatval($p['price'] ?? 0);
            }

            // Retornar produto com preco_venda (NAO expor preco_custo ao cliente)
            return [
                'id' => $p['id'] ?? $p['product_id'],
                'product_id' => $p['product_id'] ?? $p['id'],
                'partner_id' => $p['partner_id'],
                'name' => $p['name'],
                'description' => $p['description'],
                'price' => $preco_venda, // Cliente ve preco_venda
                'preco' => $preco_venda, // Alias
                'preco_original' => floatval($p['original_price'] ?? $preco_venda),
                'image' => $p['image'],
                'categoria' => $p['categoria'],
                'category_id' => $p['category_id'],
                'unit' => $p['unit'] ?? 'un',
                'quantity' => $p['quantity'] ?? 999,
                'barcode' => $p['barcode'] ?? null,
                'status' => $p['status']
            ];
        }, $produtos);

        return [
            "termo" => $q,
            "total" => count($produtos_processados),
            "produtos" => $produtos_processados
        ];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Mercado Produtos Buscar] Erro: " . $e->getMessage());
    response(false, null, "Erro na busca. Tente novamente.", 500);
}
