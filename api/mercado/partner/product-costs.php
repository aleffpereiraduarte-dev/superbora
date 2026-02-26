<?php
/**
 * GET/POST /api/mercado/partner/product-costs.php
 * Product cost management and CMV (Custo de Mercadoria Vendida) analysis
 *
 * GET (default): List all products with cost data
 * GET action=analysis: CMV analysis with BCG matrix
 * POST action=set_cost: Set cost for a single product
 * POST action=bulk_costs: Bulk update costs
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    // Table om_market_product_costs created via migration

    $method = $_SERVER['REQUEST_METHOD'];
    $action = trim($_GET['action'] ?? '');

    // ======================== POST: SET_COST ========================
    if ($method === 'POST' && $action === 'set_cost') {
        $input = getInput();
        $productId = (int)($input['product_id'] ?? 0);
        $custo = (float)($input['custo'] ?? 0);
        $fornecedor = trim($input['fornecedor'] ?? '');

        if ($productId <= 0) {
            response(false, null, "product_id obrigatorio", 400);
        }
        if ($custo < 0) {
            response(false, null, "Custo nao pode ser negativo", 400);
        }

        // Verify product belongs to this partner
        $stmtCheck = $db->prepare("
            SELECT product_id FROM om_market_products WHERE product_id = ? AND partner_id = ?
            UNION
            SELECT pp.product_id FROM om_market_products_price pp WHERE pp.product_id = ? AND pp.partner_id = ?
        ");
        $stmtCheck->execute([$productId, $partnerId, $productId, $partnerId]);
        if (!$stmtCheck->fetch()) {
            response(false, null, "Produto nao encontrado", 404);
        }

        $stmtUpsert = $db->prepare("
            INSERT INTO om_market_product_costs (product_id, partner_id, custo, fornecedor, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON CONFLICT (product_id)
            DO UPDATE SET custo = EXCLUDED.custo, fornecedor = EXCLUDED.fornecedor, updated_at = NOW()
        ");
        $stmtUpsert->execute([$productId, $partnerId, $custo, $fornecedor ?: null]);

        response(true, [
            'product_id' => $productId,
            'custo' => $custo,
            'fornecedor' => $fornecedor ?: null,
        ], "Custo atualizado");
        exit;
    }

    // ======================== POST: BULK_COSTS ========================
    if ($method === 'POST' && $action === 'bulk_costs') {
        $input = getInput();
        $items = $input['items'] ?? [];

        if (!is_array($items) || empty($items)) {
            response(false, null, "Lista de items obrigatoria", 400);
        }

        if (count($items) > 500) {
            response(false, null, "Maximo de 500 itens por vez", 400);
        }

        $db->beginTransaction();
        try {
            $updated = 0;
            $errors = [];

            $stmtUpsert = $db->prepare("
                INSERT INTO om_market_product_costs (product_id, partner_id, custo, fornecedor, updated_at)
                VALUES (?, ?, ?, ?, NOW())
                ON CONFLICT (product_id)
                DO UPDATE SET custo = EXCLUDED.custo, fornecedor = COALESCE(EXCLUDED.fornecedor, om_market_product_costs.fornecedor), updated_at = NOW()
            ");

            foreach ($items as $idx => $item) {
                $pid = (int)($item['product_id'] ?? 0);
                $custo = (float)($item['custo'] ?? 0);
                $fornecedor = trim($item['fornecedor'] ?? '');

                if ($pid <= 0) {
                    $errors[] = "Item {$idx}: product_id invalido";
                    continue;
                }
                if ($custo < 0) {
                    $errors[] = "Item {$idx}: custo negativo";
                    continue;
                }

                $stmtUpsert->execute([$pid, $partnerId, $custo, $fornecedor ?: null]);
                $updated++;
            }

            $db->commit();

            response(true, [
                'updated' => $updated,
                'errors' => $errors,
                'total' => count($items),
            ], "{$updated} custos atualizados");
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        exit;
    }

    // ======================== GET: ANALYSIS ========================
    if ($method === 'GET' && $action === 'analysis') {
        $mes = trim($_GET['mes'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            $mes = date('Y-m');
        }
        $startDate = $mes . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        // Get all products with costs and sales data
        $stmtAnalysis = $db->prepare("
            SELECT
                p.product_id,
                COALESCE(p.name, pb.name) as nome,
                COALESCE(p.price, pp.price, 0) as preco_venda,
                COALESCE(pc.custo, 0) as custo,
                pc.fornecedor,
                COALESCE(vendas.qtd_vendida, 0) as qtd_vendida,
                COALESCE(vendas.receita, 0) as receita
            FROM om_market_products p
            LEFT JOIN om_market_product_costs pc ON pc.product_id = p.product_id
            LEFT JOIN (
                SELECT
                    oi.product_id,
                    SUM(oi.quantity) as qtd_vendida,
                    SUM(oi.price * oi.quantity) as receita
                FROM om_market_order_items oi
                INNER JOIN om_market_orders o ON o.order_id = oi.order_id
                WHERE o.partner_id = ?
                  AND DATE(o.date_added) BETWEEN ? AND ?
                  AND o.status NOT IN ('cancelado', 'cancelled')
                GROUP BY oi.product_id
            ) vendas ON vendas.product_id = p.product_id
            WHERE p.partner_id = ?
            ORDER BY COALESCE(vendas.receita, 0) DESC
        ");
        $stmtAnalysis->execute([$partnerId, $startDate, $endDate, $partnerId]);
        $products = $stmtAnalysis->fetchAll();

        // Also try catalog model (om_market_products_price)
        $stmtAnalysis2 = $db->prepare("
            SELECT
                pb.product_id,
                pb.name as nome,
                pp.price as preco_venda,
                COALESCE(pc.custo, 0) as custo,
                pc.fornecedor,
                COALESCE(vendas.qtd_vendida, 0) as qtd_vendida,
                COALESCE(vendas.receita, 0) as receita
            FROM om_market_products_price pp
            INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
            LEFT JOIN om_market_product_costs pc ON pc.product_id = pb.product_id
            LEFT JOIN (
                SELECT
                    oi.product_id,
                    SUM(oi.quantity) as qtd_vendida,
                    SUM(oi.price * oi.quantity) as receita
                FROM om_market_order_items oi
                INNER JOIN om_market_orders o ON o.order_id = oi.order_id
                WHERE o.partner_id = ?
                  AND DATE(o.date_added) BETWEEN ? AND ?
                  AND o.status NOT IN ('cancelado', 'cancelled')
                GROUP BY oi.product_id
            ) vendas ON vendas.product_id = pb.product_id
            WHERE pp.partner_id = ?
            ORDER BY COALESCE(vendas.receita, 0) DESC
        ");
        try {
            $stmtAnalysis2->execute([$partnerId, $startDate, $endDate, $partnerId]);
            $catalogProducts = $stmtAnalysis2->fetchAll();
            // Merge, avoiding duplicates by product_id
            $seenIds = [];
            foreach ($products as $p) {
                $seenIds[(int)$p['product_id']] = true;
            }
            foreach ($catalogProducts as $cp) {
                if (!isset($seenIds[(int)$cp['product_id']])) {
                    $products[] = $cp;
                }
            }
        } catch (Exception $e) {
            // Table might not exist for this partner model - ignore
        }

        $cmvTotal = 0;
        $receitaTotal = 0;
        $margemTotal = 0;
        $comCusto = 0;

        $analysisProducts = [];
        foreach ($products as $p) {
            $preco = (float)$p['preco_venda'];
            $custo = (float)$p['custo'];
            $qtd = (int)$p['qtd_vendida'];
            $receita = (float)$p['receita'];

            $margemValor = $preco > 0 ? $preco - $custo : 0;
            $margemPct = $preco > 0 ? round((($preco - $custo) / $preco) * 100, 1) : 0;
            $lucroTotal = $margemValor * $qtd;

            $cmvProduto = $custo * $qtd;
            $cmvTotal += $cmvProduto;
            $receitaTotal += $receita;

            if ($custo > 0) {
                $margemTotal += $margemPct;
                $comCusto++;
            }

            $analysisProducts[] = [
                'product_id' => (int)$p['product_id'],
                'nome' => $p['nome'],
                'preco_venda' => $preco,
                'custo' => $custo,
                'margem_valor' => round($margemValor, 2),
                'margem_percentual' => $margemPct,
                'qtd_vendida' => $qtd,
                'receita' => round($receita, 2),
                'cmv' => round($cmvProduto, 2),
                'lucro_total' => round($lucroTotal, 2),
                'fornecedor' => $p['fornecedor'] ?? null,
            ];
        }

        $cmvTotal = round($cmvTotal, 2);
        $margemMedia = $comCusto > 0 ? round($margemTotal / $comCusto, 1) : 0;

        // BCG Matrix classification
        // Calculate medians for volume and margin
        $volumes = array_map(fn($p) => $p['qtd_vendida'], $analysisProducts);
        $margens = array_map(fn($p) => $p['margem_percentual'], $analysisProducts);

        sort($volumes);
        sort($margens);

        $medianVolume = count($volumes) > 0 ? $volumes[(int)(count($volumes) / 2)] : 0;
        $medianMargem = count($margens) > 0 ? $margens[(int)(count($margens) / 2)] : 0;

        $estrela = [];      // High volume + high margin
        $vacaLeiteira = []; // High volume + low/moderate margin
        $interrogacao = []; // Low volume + high margin
        $abacaxi = [];      // Low volume + low margin

        foreach ($analysisProducts as &$prod) {
            $highVolume = $prod['qtd_vendida'] >= $medianVolume;
            $highMargem = $prod['margem_percentual'] >= $medianMargem;

            if ($highVolume && $highMargem) {
                $prod['classificacao'] = 'estrela';
                $estrela[] = $prod;
            } elseif ($highVolume && !$highMargem) {
                $prod['classificacao'] = 'vaca_leiteira';
                $vacaLeiteira[] = $prod;
            } elseif (!$highVolume && $highMargem) {
                $prod['classificacao'] = 'interrogacao';
                $interrogacao[] = $prod;
            } else {
                $prod['classificacao'] = 'abacaxi';
                $abacaxi[] = $prod;
            }
        }
        unset($prod);

        // Sort by total profit contribution
        $ranking = $analysisProducts;
        usort($ranking, fn($a, $b) => $b['lucro_total'] <=> $a['lucro_total']);

        // Best and worst
        $melhorProduto = !empty($ranking) ? $ranking[0] : null;
        $piorProduto = !empty($ranking) ? end($ranking) : null;

        response(true, [
            'periodo' => [
                'mes' => $mes,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'cmv_total' => $cmvTotal,
            'receita_total' => round($receitaTotal, 2),
            'margem_media' => $margemMedia,
            'total_produtos' => count($analysisProducts),
            'produtos_com_custo' => $comCusto,
            'produtos_estrela' => array_slice($estrela, 0, 10),
            'produtos_vaca_leiteira' => array_slice($vacaLeiteira, 0, 10),
            'produtos_interrogacao' => array_slice($interrogacao, 0, 10),
            'produtos_abacaxi' => array_slice($abacaxi, 0, 10),
            'ranking_lucratividade' => array_slice($ranking, 0, 20),
            'melhor_produto' => $melhorProduto,
            'pior_produto' => $piorProduto,
            'medianas' => [
                'volume' => $medianVolume,
                'margem' => $medianMargem,
            ],
        ], "Analise CMV gerada");
        exit;
    }

    // ======================== GET: LIST PRODUCTS WITH COSTS ========================
    if ($method === 'GET') {
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
        $offset = ($page - 1) * $limit;

        // Check which product model this partner uses
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM om_market_products_price WHERE partner_id = ?");
        $stmtCheck->execute([$partnerId]);
        $hasPriceTable = (int)$stmtCheck->fetchColumn() > 0;

        if ($hasPriceTable) {
            $where = ["pp.partner_id = ?"];
            $params = [$partnerId];

            if ($search !== '') {
                $searchEsc = str_replace(['%', '_'], ['\\%', '\\_'], $search);
                $where[] = "(pb.name ILIKE ?)";
                $params[] = "%{$searchEsc}%";
            }

            $whereSQL = implode(" AND ", $where);

            $stmtCount = $db->prepare("
                SELECT COUNT(*)
                FROM om_market_products_price pp
                INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
                WHERE {$whereSQL}
            ");
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetchColumn();

            $stmt = $db->prepare("
                SELECT
                    pb.product_id,
                    pb.name as nome,
                    pp.price as preco_venda,
                    COALESCE(pc.custo, 0) as custo,
                    pc.fornecedor,
                    pc.updated_at as custo_updated_at,
                    cat.name as category_name
                FROM om_market_products_price pp
                INNER JOIN om_market_products_base pb ON pb.product_id = pp.product_id
                LEFT JOIN om_market_product_costs pc ON pc.product_id = pb.product_id
                LEFT JOIN om_market_categories cat ON cat.category_id = pb.category_id
                WHERE {$whereSQL}
                ORDER BY pb.name ASC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute(array_merge($params, [$limit, $offset]));
        } else {
            $where = ["p.partner_id = ?"];
            $params = [$partnerId];

            if ($search !== '') {
                $searchEsc = str_replace(['%', '_'], ['\\%', '\\_'], $search);
                $where[] = "(p.name ILIKE ?)";
                $params[] = "%{$searchEsc}%";
            }

            $whereSQL = implode(" AND ", $where);

            $stmtCount = $db->prepare("SELECT COUNT(*) FROM om_market_products p WHERE {$whereSQL}");
            $stmtCount->execute($params);
            $total = (int)$stmtCount->fetchColumn();

            $stmt = $db->prepare("
                SELECT
                    p.product_id,
                    p.name as nome,
                    p.price as preco_venda,
                    COALESCE(pc.custo, 0) as custo,
                    pc.fornecedor,
                    pc.updated_at as custo_updated_at,
                    p.category as category_name
                FROM om_market_products p
                LEFT JOIN om_market_product_costs pc ON pc.product_id = p.product_id
                WHERE {$whereSQL}
                ORDER BY p.name ASC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute(array_merge($params, [$limit, $offset]));
        }

        $items = $stmt->fetchAll();
        $formatted = [];

        foreach ($items as $item) {
            $preco = (float)$item['preco_venda'];
            $custo = (float)$item['custo'];
            $margemValor = $preco - $custo;
            $margemPct = $preco > 0 ? round((($preco - $custo) / $preco) * 100, 1) : 0;

            // ABC classification based on margin
            if ($margemPct >= 40) {
                $classificacao = 'A';
            } elseif ($margemPct >= 20) {
                $classificacao = 'B';
            } else {
                $classificacao = 'C';
            }

            $formatted[] = [
                'product_id' => (int)$item['product_id'],
                'nome' => $item['nome'],
                'preco_venda' => $preco,
                'custo' => $custo,
                'margem_valor' => round($margemValor, 2),
                'margem_percentual' => $margemPct,
                'classificacao' => $classificacao,
                'fornecedor' => $item['fornecedor'] ?? null,
                'custo_updated_at' => $item['custo_updated_at'] ?? null,
                'category_name' => $item['category_name'] ?? null,
            ];
        }

        $pages = $total > 0 ? ceil($total / $limit) : 1;

        response(true, [
            'items' => $formatted,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'pages' => (int)$pages,
                'limit' => $limit,
            ],
        ], "Produtos com custos listados");
        exit;
    }

    response(false, null, "Metodo nao suportado", 405);

} catch (Exception $e) {
    error_log("[partner/product-costs] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
