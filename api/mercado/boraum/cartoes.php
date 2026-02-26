<?php
/**
 * GET/POST/DELETE /api/mercado/boraum/cartoes.php
 * Gerenciamento de cartoes salvos do passageiro BoraUm
 *
 * GET              - Lista cartoes salvos (token mascarado)
 * POST             - Salva novo cartao (max 5)
 * DELETE ?id=X     - Remove cartao
 *
 * Tabela: om_boraum_passenger_cards
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
setCorsHeaders();
$db = getDB();
$user = requirePassageiro($db);

$passageiroId = $user['passageiro_id'];

if ($passageiroId <= 0) {
    response(false, null, "Perfil nao vinculado", 403);
}
$method = $_SERVER['REQUEST_METHOD'];

// Bandeiras aceitas
$bandeirasValidas = ['visa', 'mastercard', 'elo', 'amex', 'hipercard'];

try {

    // =========================================================================
    // GET - Listar cartoes salvos
    // =========================================================================
    if ($method === 'GET') {

        $stmt = $db->prepare("
            SELECT id, bandeira, ultimos4, holder_name, is_default, created_at
            FROM om_boraum_passenger_cards
            WHERE passageiro_id = ?
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->execute([$passageiroId]);
        $rows = $stmt->fetchAll();

        $cartoes = [];
        foreach ($rows as $r) {
            $cartoes[] = [
                "id"          => (int)$r['id'],
                "bandeira"    => $r['bandeira'],
                "ultimos4"    => $r['ultimos4'],
                "holder_name" => $r['holder_name'],
                "is_default"  => (bool)$r['is_default'],
                "created_at"  => $r['created_at'],
                "display"     => strtoupper($r['bandeira']) . ' **** ' . $r['ultimos4'],
            ];
        }

        response(true, ["cartoes" => $cartoes, "total" => count($cartoes)]);
    }

    // =========================================================================
    // POST - Salvar novo cartao
    // =========================================================================
    elseif ($method === 'POST') {
        $input = getInput();

        // Validar campos obrigatorios
        $bandeira    = strtolower(trim($input['bandeira'] ?? ''));
        $ultimos4    = trim($input['ultimos4'] ?? '');
        $tokenCartao = trim($input['token_cartao'] ?? '');
        $holderName  = trim($input['holder_name'] ?? '');
        $isDefault   = (int)(!empty($input['is_default']));

        if (empty($bandeira)) {
            response(false, null, "Bandeira do cartao e obrigatoria.", 400);
        }

        if (!in_array($bandeira, $bandeirasValidas, true)) {
            response(false, null, "Bandeira invalida. Aceitas: " . implode(', ', $bandeirasValidas) . ".", 400);
        }

        if (empty($ultimos4) || !preg_match('/^\d{4}$/', $ultimos4)) {
            response(false, null, "Ultimos 4 digitos do cartao sao obrigatorios (4 numeros).", 400);
        }

        if (empty($tokenCartao)) {
            response(false, null, "Token do cartao e obrigatorio.", 400);
        }

        if (mb_strlen($tokenCartao) > 255) {
            response(false, null, "Token do cartao invalido.", 400);
        }

        if (empty($holderName)) {
            response(false, null, "Nome do titular e obrigatorio.", 400);
        }

        if (mb_strlen($holderName) > 100) {
            response(false, null, "Nome do titular muito longo (max 100 caracteres).", 400);
        }

        // Limite de 5 cartoes
        $stmt = $db->prepare("SELECT COUNT(*) AS total FROM om_boraum_passenger_cards WHERE passageiro_id = ?");
        $stmt->execute([$passageiroId]);
        $count = (int)$stmt->fetch()['total'];

        if ($count >= 5) {
            response(false, null, "Limite de 5 cartoes atingido. Remova um cartao antes de adicionar outro.", 400);
        }

        // Verificar se ja existe cartao com mesmos ultimos 4 e bandeira
        $stmt = $db->prepare("
            SELECT id FROM om_boraum_passenger_cards
            WHERE passageiro_id = ? AND bandeira = ? AND ultimos4 = ?
            LIMIT 1
        ");
        $stmt->execute([$passageiroId, $bandeira, $ultimos4]);
        if ($stmt->fetch()) {
            response(false, null, "Cartao " . strtoupper($bandeira) . " final " . $ultimos4 . " ja cadastrado.", 409);
        }

        $db->beginTransaction();
        try {
            // Se marcar como padrao, desmarcar outros
            if ($isDefault) {
                $stmt = $db->prepare("UPDATE om_boraum_passenger_cards SET is_default = 0 WHERE passageiro_id = ?");
                $stmt->execute([$passageiroId]);
            }

            // Se e o primeiro cartao, marcar como padrao automaticamente
            if ($count === 0) {
                $isDefault = 1;
            }

            $stmt = $db->prepare("
                INSERT INTO om_boraum_passenger_cards
                    (passageiro_id, bandeira, ultimos4, token_cartao, holder_name, is_default, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $passageiroId, $bandeira, $ultimos4, $tokenCartao, $holderName, $isDefault
            ]);

            $newId = (int)$db->lastInsertId();
            $db->commit();

            response(true, [
                "id" => $newId,
                "display" => strtoupper($bandeira) . ' **** ' . $ultimos4,
            ], "Cartao salvo com sucesso!", 201);

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // DELETE - Remover cartao
    // =========================================================================
    elseif ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            response(false, null, "ID do cartao e obrigatorio.", 400);
        }

        // Verificar propriedade
        $stmt = $db->prepare("SELECT id, is_default FROM om_boraum_passenger_cards WHERE id = ? AND passageiro_id = ?");
        $stmt->execute([$id, $passageiroId]);
        $card = $stmt->fetch();

        if (!$card) {
            response(false, null, "Cartao nao encontrado.", 404);
        }

        $wasDefault = (bool)$card['is_default'];

        $stmt = $db->prepare("DELETE FROM om_boraum_passenger_cards WHERE id = ? AND passageiro_id = ?");
        $stmt->execute([$id, $passageiroId]);

        // Se era o padrao, promover o mais recente como novo padrao
        if ($wasDefault) {
            $stmt = $db->prepare("
                UPDATE om_boraum_passenger_cards
                SET is_default = 1
                WHERE id = (
                    SELECT id FROM om_boraum_passenger_cards
                    WHERE passageiro_id = ?
                    ORDER BY created_at DESC
                    LIMIT 1
                )
            ");
            $stmt->execute([$passageiroId]);
        }

        response(true, null, "Cartao removido com sucesso.");
    }

    // =========================================================================
    // Metodo nao suportado
    // =========================================================================
    else {
        response(false, null, "Metodo nao permitido.", 405);
    }

} catch (Exception $e) {
    error_log("[BoraUm Cartoes] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar cartoes. Tente novamente.", 500);
}
