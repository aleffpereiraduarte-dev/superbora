<?php
/**
 * GET /api/mercado/vitrine/stores-by-ids.php
 * Fetch multiple stores by their IDs
 *
 * Parameters:
 *   ids (required) - Comma-separated list of partner IDs
 */
require_once __DIR__ . "/../config/database.php";

setCorsHeaders();

header('Cache-Control: public, max-age=60');

try {
    $db = getDB();

    $idsParam = $_GET['ids'] ?? '';
    if (!$idsParam) {
        response(false, null, "ids obrigatorio", 400);
    }

    // Parse and validate IDs
    $ids = array_filter(array_map('intval', explode(',', $idsParam)));
    if (empty($ids)) {
        response(true, ['stores' => []]);
    }

    // Limit to 50 stores max
    $ids = array_slice($ids, 0, 50);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $db->prepare("
        SELECT
            p.partner_id as id,
            COALESCE(p.name, p.trade_name) as nome,
            p.logo,
            p.banner,
            p.categoria,
            p.rating as avaliacao,
            p.delivery_fee as taxa_entrega,
            p.delivery_time_min as tempo_estimado,
            p.min_order_value as pedido_minimo,
            p.is_open as aberto,
            p.free_delivery_min as entrega_gratis_acima,
            p.address as endereco,
            p.city as cidade
        FROM om_market_partners p
        WHERE p.partner_id IN ($placeholders)
        AND p.status = '1'
    ");
    $stmt->execute($ids);
    $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $formattedStores = array_map(function($s) {
        return [
            'id' => (int)$s['id'],
            'nome' => $s['nome'],
            'logo' => $s['logo'],
            'banner' => $s['banner'],
            'categoria' => $s['categoria'] ?? 'supermercado',
            'avaliacao' => (float)($s['avaliacao'] ?? 5),
            'taxa_entrega' => (float)($s['taxa_entrega'] ?? 0),
            'tempo_estimado' => (int)($s['tempo_estimado'] ?? 30),
            'pedido_minimo' => (float)($s['pedido_minimo'] ?? 0),
            'aberto' => (bool)($s['aberto'] ?? true),
            'entrega_gratis_acima' => (float)($s['entrega_gratis_acima'] ?? 0),
            'endereco' => $s['endereco'],
            'cidade' => $s['cidade'],
        ];
    }, $stores);

    response(true, ['stores' => $formattedStores]);

} catch (Exception $e) {
    error_log("[vitrine/stores-by-ids] Erro: " . $e->getMessage());
    response(false, null, "Erro ao buscar lojas", 500);
}
