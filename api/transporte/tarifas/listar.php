<?php
/**
 * GET /api/transporte/tarifas/listar.php
 * Lista tarifas de transporte
 * Otimizado com cache (TTL: 1 hora)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

header('Cache-Control: public, max-age=3600');

try {
    $cidade = $_GET["cidade"] ?? null;
    $cacheKey = "transporte_tarifas_" . md5($cidade ?? 'all');

    $data = CacheHelper::remember($cacheKey, 3600, function() use ($cidade) {
        $db = getDB();

        if ($cidade) {
            $stmt = $db->prepare("SELECT * FROM boraum_tarifas WHERE ativo = 1 AND cidade = ? ORDER BY tipo_veiculo");
            $stmt->execute([$cidade]);
            $tarifas = $stmt->fetchAll();
        } else {
            $tarifas = $db->query("SELECT * FROM boraum_tarifas WHERE ativo = 1 ORDER BY tipo_veiculo")->fetchAll();
        }

        return ["tarifas" => $tarifas];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[transporte/tarifas/listar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
