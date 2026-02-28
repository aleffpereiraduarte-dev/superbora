<?php
/**
 * GET /api/mercado/parceiros/listar.php
 * Lista mercados disponíveis
 * Otimizado com cache (TTL: 10 min)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=600');

try {
    $lat = $_GET["lat"] ?? null;
    $lng = $_GET["lng"] ?? null;
    $raio = $_GET["raio"] ?? 10;
    $cidade = isset($_GET["cidade"]) ? trim($_GET["cidade"]) : null;
    $page = max(1, (int)($_GET["page"] ?? ($_GET["pagina"] ?? 1)));
    $limit = min(100, max(1, (int)($_GET["limit"] ?? ($_GET["limite"] ?? 50))));
    $offset = ($page - 1) * $limit;

    // Cache key baseado nos parâmetros
    $cacheKey = "mercado_parceiros_" . md5($lat . $lng . $raio . $cidade . $page . $limit);

    $data = CacheHelper::remember($cacheKey, 600, function() use ($lat, $lng, $raio, $cidade, $page, $limit, $offset) {
        $db = getDB();

        $where = ["p.status::text = '1'"];
        $params = [];

        if ($cidade) {
            $where[] = "p.city ILIKE ?";
            $params[] = $cidade;
        }

        $whereSQL = implode(" AND ", $where);

        // Count total
        $countStmt = $db->prepare("SELECT COUNT(*) FROM om_market_partners p WHERE $whereSQL");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT p.*,
                (SELECT COUNT(*) FROM om_market_products WHERE partner_id = p.partner_id AND status::text = '1') as total_produtos
                FROM om_market_partners p
                WHERE $whereSQL
                ORDER BY p.partner_id DESC
                LIMIT ? OFFSET ?";

        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $parceiros = $stmt->fetchAll();

        return [
            "total" => $total,
            "page" => $page,
            "limit" => $limit,
            "parceiros" => array_map(function($p) {
                return [
                    "id" => $p["partner_id"],
                    "nome" => $p["name"] ?? $p["trade_name"],
                    "logo" => $p["logo"] ?? null,
                    "endereco" => $p["address"] ?? "",
                    "cidade" => $p["city"] ?? "",
                    "total_produtos" => $p["total_produtos"],
                    "taxa_entrega" => $p["delivery_fee"] ?? 0,
                    "tempo_estimado" => $p["delivery_time_min"] ?? 60,
                    "avaliacao" => $p["rating"] ?? 5.0,
                    "aberto" => (int)($p["is_open"] ?? 0) === 1
                ];
            }, $parceiros)
        ];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[parceiros/listar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
