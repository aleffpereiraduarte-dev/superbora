<?php
/**
 * GET /api/mercado/produtos/buscar-global.php?q=termo&cep=01310100&limit=30
 * Global product search across all active stores
 * Returns flat list + grouped by store
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=120');

try {
    $q = trim($_GET["q"] ?? "");
    $cep = preg_replace('/\D/', '', $_GET["cep"] ?? "");
    $limit = min(60, max(1, (int)($_GET["limit"] ?? 30)));

    if (strlen($q) < 2) {
        response(false, null, "Termo de busca deve ter pelo menos 2 caracteres", 400);
    }

    $cacheKey = "busca_global_" . md5(json_encode([$q, $cep, $limit]));

    $data = CacheHelper::remember($cacheKey, 120, function() use ($q, $cep, $limit) {
        $db = getDB();

        // If CEP provided, get partner IDs that serve that area
        $coveredIds = [];

        if (strlen($cep) === 8) {
            $stmt = $db->prepare("
                SELECT partner_id FROM om_market_partners
                WHERE status::text = '1'
                  AND cep_inicio IS NOT NULL AND cep_fim IS NOT NULL
                  AND CAST(? AS BIGINT) BETWEEN CAST(cep_inicio AS BIGINT) AND CAST(cep_fim AS BIGINT)
            ");
            $stmt->execute([$cep]);
            $coveredIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Fallback: regional prefix matching (first 3 digits)
            if (empty($coveredIds)) {
                $prefixo = substr($cep, 0, 3);
                $stmt = $db->prepare("
                    SELECT partner_id FROM om_market_partners
                    WHERE status::text = '1' AND (
                        LEFT(REPLACE(cep, '-', ''), 3) = ?
                        OR (delivery_radius_km IS NOT NULL AND delivery_radius_km >= 50)
                    )
                ");
                $stmt->execute([$prefixo]);
                $coveredIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        }

        // ── PRIMARY: Try Meilisearch (fast, fuzzy, typo-tolerant) ──
        $rows = [];
        $meiliUsed = false;
        $meiliKey = $_ENV['MEILI_ADMIN_KEY'] ?? getenv('MEILI_ADMIN_KEY') ?: '';

        if ($meiliKey) {
            try {
                $meiliPayload = [
                    'q' => $q,
                    'limit' => $limit,
                    'attributesToRetrieve' => ['id', 'name', 'nome', 'description', 'price', 'preco', 'special_price', 'image', 'unit', 'quantity', 'partner_id', 'partner_name', 'partner_logo', 'partner_rating', 'partner_delivery_fee', 'partner_delivery_time', 'partner_is_open', 'available', 'status', 'category_name'],
                    'matchingStrategy' => 'last',
                ];

                // Filter by partner IDs if CEP-based
                if (!empty($coveredIds)) {
                    $meiliPayload['filter'] = 'partner_id IN [' . implode(',', $coveredIds) . ']';
                }

                $ch = curl_init('http://localhost:7700/indexes/products/search');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($meiliPayload),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $meiliKey],
                    CURLOPT_TIMEOUT => 3,
                    CURLOPT_CONNECTTIMEOUT => 2,
                ]);
                $res = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && $res) {
                    $data = json_decode($res, true);
                    $hits = $data['hits'] ?? [];

                    if (!empty($hits)) {
                        // Convert Meilisearch hits to DB-like rows
                        foreach ($hits as $h) {
                            $rows[] = [
                                'product_id' => $h['id'] ?? 0,
                                'name' => $h['name'] ?? $h['nome'] ?? '',
                                'description' => $h['description'] ?? '',
                                'price' => $h['price'] ?? $h['preco'] ?? 0,
                                'special_price' => $h['special_price'] ?? null,
                                'image' => $h['image'] ?? '',
                                'unit' => $h['unit'] ?? 'un',
                                'quantity' => $h['quantity'] ?? 999,
                                'partner_id' => $h['partner_id'] ?? 0,
                                'parceiro_nome' => $h['partner_name'] ?? '',
                                'parceiro_logo' => $h['partner_logo'] ?? '',
                                'parceiro_taxa' => $h['partner_delivery_fee'] ?? 0,
                                'parceiro_avaliacao' => $h['partner_rating'] ?? 5.0,
                                'parceiro_tempo' => $h['partner_delivery_time'] ?? 60,
                                'parceiro_aberto' => $h['partner_is_open'] ?? 0,
                            ];
                        }
                        $meiliUsed = true;
                    }
                }
            } catch (Exception $e) {
                error_log("[Buscar Global] Meilisearch error: " . $e->getMessage());
            }
        }

        // ── FALLBACK: PostgreSQL LIKE search ──
        if (!$meiliUsed) {
            $partnerFilter = "";
            $partnerParams = [];

            if (!empty($coveredIds)) {
                $placeholders = implode(',', array_fill(0, count($coveredIds), '?'));
                $partnerFilter = "AND p.partner_id IN ($placeholders)";
                $partnerParams = $coveredIds;
            }

            $searchTerm = "%{$q}%";
            $params = array_merge([$searchTerm, $searchTerm], $partnerParams);

            $sql = "SELECT p.product_id, p.name, p.description, p.price, p.special_price,
                           p.image, p.unit, p.quantity, p.partner_id,
                           m.name as parceiro_nome, m.logo as parceiro_logo,
                           m.delivery_fee as parceiro_taxa, m.rating as parceiro_avaliacao,
                           m.delivery_time_min as parceiro_tempo, m.is_open as parceiro_aberto
                    FROM om_market_products p
                    INNER JOIN om_market_partners m ON p.partner_id = m.partner_id AND m.status::text = '1'
                    WHERE p.status::text = '1' AND (p.available::text = '1' OR p.available IS NULL)
                      AND (LOWER(p.name) LIKE LOWER(?) OR LOWER(p.description) LIKE LOWER(?))
                      {$partnerFilter}
                    ORDER BY m.is_open DESC, m.rating DESC, p.name ASC
                    LIMIT {$limit}";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        }

        // Build flat list and grouped by store
        $flat = [];
        $agrupado = [];

        foreach ($rows as $r) {
            $preco = (float)$r["price"];
            $promoPreco = $r["special_price"] ? (float)$r["special_price"] : null;
            $emPromocao = $promoPreco && $promoPreco > 0 && $promoPreco < $preco;
            $pid = (int)$r["partner_id"];

            $produto = [
                "id" => (int)$r["product_id"],
                "nome" => $r["name"],
                "descricao" => $r["description"] ?? "",
                "preco" => $preco,
                "preco_promo" => $emPromocao ? $promoPreco : null,
                "imagem" => $r["image"],
                "unidade" => $r["unit"] ?? "un",
                "estoque" => (int)($r["quantity"] ?? 999),
                "disponivel" => ((int)($r["quantity"] ?? 999)) > 0,
                "parceiro_id" => $pid,
                "parceiro_nome" => $r["parceiro_nome"],
                "parceiro_logo" => $r["parceiro_logo"],
                "parceiro_taxa" => (float)($r["parceiro_taxa"] ?? 0),
                "parceiro_avaliacao" => (float)($r["parceiro_avaliacao"] ?? 5.0),
                "parceiro_tempo" => (int)($r["parceiro_tempo"] ?? 60),
                "parceiro_aberto" => (int)($r["parceiro_aberto"] ?? 0) === 1
            ];

            $flat[] = $produto;

            if (!isset($agrupado[$pid])) {
                $agrupado[$pid] = [
                    "parceiro_id" => $pid,
                    "parceiro_nome" => $r["parceiro_nome"],
                    "parceiro_logo" => $r["parceiro_logo"],
                    "parceiro_taxa" => (float)($r["parceiro_taxa"] ?? 0),
                    "parceiro_avaliacao" => (float)($r["parceiro_avaliacao"] ?? 5.0),
                    "parceiro_tempo" => (int)($r["parceiro_tempo"] ?? 60),
                    "parceiro_aberto" => (int)($r["parceiro_aberto"] ?? 0) === 1,
                    "produtos" => []
                ];
            }
            $agrupado[$pid]["produtos"][] = $produto;
        }

        return [
            "termo" => $q,
            "total" => count($flat),
            "produtos" => $flat,
            "agrupado" => array_values($agrupado),
            "search_engine" => $meiliUsed ? "meilisearch" : "postgres"
        ];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Buscar Global] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar produtos", 500);
}
