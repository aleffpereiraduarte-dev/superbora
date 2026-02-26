<?php
/**
 * GET /api/mercado/produtos/sugestoes.php?q=ter&limit=8
 * A2: Autocomplete - retorna ate 4 produtos + ate 4 lojas que match o termo
 * Cache 60s por termo
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=60');

try {
    $q = trim(substr($_GET["q"] ?? "", 0, 100));
    $limit = min(8, max(1, (int)($_GET["limit"] ?? 8)));

    if (strlen($q) < 2) {
        response(true, ["produtos" => [], "lojas" => [], "populares" => []]);
    }

    $cacheKey = "sugestoes_" . md5($q . "_" . $limit);

    $data = CacheHelper::remember($cacheKey, 60, function() use ($q, $limit) {
        $db = getDB();
        $term = "%" . $q . "%";
        $halfLimit = max(1, intdiv($limit, 2));

        // Buscar produtos (ate metade do limit)
        $startTerm = $q . "%";
        $stmt = $db->prepare("
            SELECT p.product_id, p.name, p.price, p.special_price, p.image,
                   mp.partner_id, mp.name as partner_name, mp.logo as partner_logo
            FROM om_market_products p
            INNER JOIN om_market_partners mp ON p.partner_id = mp.partner_id
            WHERE p.status::text = '1'
              AND (p.available::text = '1' OR p.available IS NULL)
              AND mp.status::text = '1'
              AND (p.name LIKE ? OR p.description LIKE ?)
            ORDER BY
                CASE WHEN p.name LIKE ? THEN 0 ELSE 1 END,
                p.name
            LIMIT ?
        ");
        $stmt->execute([$term, $term, $startTerm, $halfLimit]);
        $produtos = [];
        foreach ($stmt->fetchAll() as $p) {
            $preco = (float)$p['price'];
            $promoPreco = $p['special_price'] ? (float)$p['special_price'] : null;
            $produtos[] = [
                "id" => (int)$p['product_id'],
                "nome" => $p['name'],
                "preco" => $preco,
                "preco_promo" => ($promoPreco && $promoPreco > 0 && $promoPreco < $preco) ? $promoPreco : null,
                "imagem" => $p['image'],
                "parceiro_id" => (int)$p['partner_id'],
                "parceiro_nome" => $p['partner_name']
            ];
        }

        // Buscar lojas (ate metade do limit)
        $stmt = $db->prepare("
            SELECT p.partner_id, p.name, p.trade_name, p.logo, p.categoria,
                   p.rating, p.is_open
            FROM om_market_partners p
            WHERE p.status::text = '1'
              AND (p.name LIKE ? OR p.trade_name LIKE ? OR p.description LIKE ?)
            ORDER BY
                CASE WHEN p.name LIKE ? THEN 0 ELSE 1 END,
                p.rating DESC
            LIMIT ?
        ");
        $stmt->execute([$term, $term, $term, $startTerm, $halfLimit]);
        $lojas = [];
        foreach ($stmt->fetchAll() as $l) {
            $lojas[] = [
                "id" => (int)$l['partner_id'],
                "nome" => $l['name'] ?? $l['trade_name'] ?? "",
                "logo" => $l['logo'],
                "categoria" => $l['categoria'] ?? "mercado",
                "avaliacao" => (float)($l['rating'] ?? 5.0),
                "aberto" => (int)($l['is_open'] ?? 0) === 1
            ];
        }

        // Registrar busca popular (async, nao bloqueia resposta)
        try {
            $termLower = strtolower($q);
            $stmt = $db->prepare("
                INSERT INTO om_market_search_popular (term, search_count, last_searched_at)
                VALUES (?, 1, NOW())
                ON CONFLICT (term) DO UPDATE SET search_count = om_market_search_popular.search_count + 1, last_searched_at = NOW()
            ");
            $stmt->execute([$termLower]);
        } catch (Exception $e) {
            // Tabela pode nao existir ainda, ignorar
        }

        return [
            "produtos" => $produtos,
            "lojas" => $lojas
        ];
    });

    // Buscar termos populares se query curta
    if (strlen($q) <= 3) {
        try {
            $db = getDB();
            $stmt = $db->prepare("
                SELECT term FROM om_market_search_popular
                WHERE term LIKE ?
                ORDER BY search_count DESC
                LIMIT 5
            ");
            $stmt->execute([$q . "%"]);
            $data["populares"] = array_column($stmt->fetchAll(), 'term');
        } catch (Exception $e) {
            $data["populares"] = [];
        }
    } else {
        $data["populares"] = [];
    }

    response(true, $data);

} catch (Exception $e) {
    error_log("[API Sugestoes] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar sugestoes", 500);
}
