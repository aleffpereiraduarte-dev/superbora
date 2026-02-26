<?php
/**
 * GET /api/transporte/passageiro/historico.php?passageiro_id=1
 * Histórico de corridas do passageiro
 * Otimizado com cache (TTL: 2 min) e prepared statements
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 2) . "/cache/CacheHelper.php";

try {
    $passageiro_id = (int)($_GET["passageiro_id"] ?? 0);

    if (!$passageiro_id) {
        response(false, null, "passageiro_id obrigatório", 400);
    }

    $cacheKey = "historico_passageiro_{$passageiro_id}";

    $data = CacheHelper::remember($cacheKey, 120, function() use ($passageiro_id) {
        $db = getDB();

        $stmt = $db->prepare("SELECT c.*, m.nome as motorista_nome, m.foto as motorista_foto, m.veiculo_modelo, m.veiculo_placa
                FROM boraum_corridas c
                LEFT JOIN boraum_motoristas m ON c.motorista_id = m.id
                WHERE c.passageiro_id = ?
                ORDER BY c.created_at DESC
                LIMIT 50");
        $stmt->execute([$passageiro_id]);

        return ["corridas" => $stmt->fetchAll()];
    });

    response(true, $data);

} catch (Exception $e) {
    error_log("[transporte/passageiro/historico] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
