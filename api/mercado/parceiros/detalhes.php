<?php
/**
 * GET /api/mercado/parceiros/detalhes.php?id=1
 * Detalhes de um parceiro do mercado
 * Otimizado com cache (TTL: 10 min)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=600');

try {
    $id = (int)($_GET["id"] ?? 0);

    if (!$id) response(false, null, "ID obrigatório", 400);

    $cacheKey = "parceiro_detalhes_{$id}";

    $data = CacheHelper::remember($cacheKey, 600, function() use ($id) {
        $db = getDB();

        $stmt = $db->prepare("SELECT partner_id, name, trade_name, logo, banner, address, city, state, phone, email, cep, categoria, description, delivery_fee, min_order, min_order_value, delivery_time_min, delivery_time_max, rating, is_open, open_time, close_time, latitude, longitude, free_delivery_above, status, busy_mode, pause_until FROM om_market_partners WHERE partner_id = ?");
        $stmt->execute([$id]);
        $parceiro = $stmt->fetch();

        if (!$parceiro) return null;

        // Categorias do parceiro
        $catStmt = $db->prepare("SELECT DISTINCT c.* FROM om_market_categories c
                                  INNER JOIN om_market_products p ON p.category_id = c.category_id
                                  WHERE p.partner_id = ? AND p.status::text = '1'");
        $catStmt->execute([$id]);
        $categorias = $catStmt->fetchAll();

        return [
            "parceiro" => [
                "id" => $parceiro["partner_id"],
                "nome" => $parceiro["name"] ?? $parceiro["trade_name"],
                "logo" => $parceiro["logo"],
                "endereco" => $parceiro["address"],
                "cidade" => $parceiro["city"],
                "telefone" => $parceiro["phone"],
                "taxa_entrega" => $parceiro["delivery_fee"] ?? 0,
                "pedido_minimo" => $parceiro["min_order"] ?? $parceiro["min_order_value"] ?? 0,
                "tempo_estimado" => $parceiro["delivery_time_min"] ?? 60,
                "aberto" => (int)($parceiro["is_open"] ?? 0) === 1,
                "busy_mode" => (bool)($parceiro["busy_mode"] ?? false),
                "pause_until" => $parceiro["pause_until"] ?? null
            ],
            "categorias" => $categorias
        ];
    });

    if (!$data) response(false, null, "Parceiro não encontrado", 404);

    response(true, $data);

} catch (Exception $e) {
    error_log("[parceiros/detalhes] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
