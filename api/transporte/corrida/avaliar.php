<?php
/**
 * API: Avaliar Corrida
 * POST /api/transporte/corrida/avaliar.php
 *
 * Body: {
 *   "corrida_id": 123,
 *   "avaliador_tipo": "passageiro",
 *   "avaliador_id": 1,
 *   "nota": 5,
 *   "comentario": "Ótimo motorista!"
 * }
 */

require_once __DIR__ . "/../config/database.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

try {
    $input = getInput();
    $db = getDB();

    $corrida_id = (int)($input["corrida_id"] ?? 0);
    $avaliador_tipo = $input["avaliador_tipo"] ?? "passageiro";
    $avaliador_id = (int)($input["avaliador_id"] ?? 0);
    $nota = (int)($input["nota"] ?? 5);
    $comentario = $input["comentario"] ?? "";

    // Validar nota
    if ($nota < 1 || $nota > 5) {
        response(false, null, "Nota deve ser entre 1 e 5", 400);
    }

    // Validar tipo de avaliador
    $allowed_tipos = ["passageiro", "motorista"];
    if (!in_array($avaliador_tipo, $allowed_tipos)) {
        $avaliador_tipo = "passageiro";
    }

    // Validar IDs
    if ($corrida_id <= 0 || $avaliador_id <= 0) {
        response(false, null, "Dados inválidos", 400);
    }

    // Buscar corrida
    $stmt = $db->prepare("SELECT * FROM boraum_corridas WHERE id = ?");
    $stmt->execute([$corrida_id]);
    $corrida = $stmt->fetch();

    if (!$corrida || $corrida["status"] !== "finalizada") {
        response(false, null, "Corrida não encontrada ou não finalizada", 400);
    }

    // Determinar quem está sendo avaliado
    if ($avaliador_tipo == "passageiro") {
        $avaliado_tipo = "motorista";
        $avaliado_id = (int)$corrida["motorista_id"];
    } else {
        $avaliado_tipo = "passageiro";
        $avaliado_id = (int)$corrida["passageiro_id"];
    }

    // Inserir avaliação
    $stmt = $db->prepare("INSERT INTO boraum_avaliacoes (corrida_id, avaliador_tipo, avaliador_id, avaliado_tipo, avaliado_id, nota, comentario)
            VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id");
    $stmt->execute([$corrida_id, $avaliador_tipo, $avaliador_id, $avaliado_tipo, $avaliado_id, $nota, $comentario]);

    $avaliacao_id = $stmt->fetchColumn();

    // Atualizar média do avaliado
    if ($avaliado_tipo == "motorista") {
        $stmt = $db->prepare("SELECT AVG(nota) as media FROM boraum_avaliacoes WHERE avaliado_tipo = 'motorista' AND avaliado_id = ?");
        $stmt->execute([$avaliado_id]);
        $media = $stmt->fetch();

        $stmt = $db->prepare("UPDATE boraum_motoristas SET nota = ? WHERE id = ?");
        $stmt->execute([round($media["media"], 2), $avaliado_id]);
    } else {
        $stmt = $db->prepare("SELECT AVG(nota) as media FROM boraum_avaliacoes WHERE avaliado_tipo = 'passageiro' AND avaliado_id = ?");
        $stmt->execute([$avaliado_id]);
        $media = $stmt->fetch();

        $stmt = $db->prepare("UPDATE boraum_passageiros SET nota_media = ? WHERE id = ?");
        $stmt->execute([round($media["media"], 2), $avaliado_id]);
    }

    response(true, [
        "avaliacao_id" => $avaliacao_id,
        "nota" => $nota
    ], "Avaliação enviada! Obrigado.");

} catch (Exception $e) {
    error_log("[transporte/corrida/avaliar] Erro: " . $e->getMessage());
    response(false, null, 'Erro interno do servidor', 500);
}
