<?php
/**
 * GET/POST/PUT/DELETE /api/mercado/boraum/enderecos.php
 * CRUD de enderecos do passageiro BoraUm
 *
 * GET              - Lista todos os enderecos do passageiro
 * GET ?cep=XXXXX   - Consulta CEP via ViaCEP para auto-preenchimento
 * POST             - Cria novo endereco (max 10)
 * PUT              - Atualiza endereco existente (id no body)
 * DELETE ?id=X     - Remove endereco
 *
 * Tabela: om_boraum_passenger_addresses
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

try {

    // =========================================================================
    // GET - Listar enderecos ou consultar CEP
    // =========================================================================
    if ($method === 'GET') {

        // --- Consulta CEP via ViaCEP ---
        if (!empty($_GET['cep'])) {
            $cep = preg_replace('/[^0-9]/', '', $_GET['cep']);

            if (strlen($cep) !== 8) {
                response(false, null, "CEP invalido. Informe 8 digitos.", 400);
            }

            $url = "https://viacep.com.br/ws/{$cep}/json/";
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'method' => 'GET',
                    'header' => "Accept: application/json\r\n"
                ]
            ]);
            $result = @file_get_contents($url, false, $ctx);

            if ($result === false) {
                response(false, null, "Erro ao consultar CEP. Tente novamente.", 502);
            }

            $data = json_decode($result, true);

            if (!$data || !empty($data['erro'])) {
                response(false, null, "CEP nao encontrado.", 404);
            }

            response(true, [
                "cep" => $data['cep'] ?? $cep,
                "endereco" => $data['logradouro'] ?? '',
                "complemento" => $data['complemento'] ?? '',
                "bairro" => $data['bairro'] ?? '',
                "cidade" => $data['localidade'] ?? '',
                "estado" => $data['uf'] ?? '',
            ]);
        }

        // --- Listar enderecos do passageiro ---
        $stmt = $db->prepare("
            SELECT id, label, endereco, complemento, bairro, cidade, estado, cep,
                   lat, lng, is_default, created_at
            FROM om_boraum_passenger_addresses
            WHERE passageiro_id = ?
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->execute([$passageiroId]);
        $rows = $stmt->fetchAll();

        $enderecos = [];
        foreach ($rows as $r) {
            $enderecos[] = [
                "id"          => (int)$r['id'],
                "label"       => $r['label'],
                "endereco"    => $r['endereco'],
                "complemento" => $r['complemento'],
                "bairro"      => $r['bairro'],
                "cidade"      => $r['cidade'],
                "estado"      => $r['estado'],
                "cep"         => $r['cep'],
                "lat"         => $r['lat'] ? (float)$r['lat'] : null,
                "lng"         => $r['lng'] ? (float)$r['lng'] : null,
                "is_default"  => (bool)$r['is_default'],
                "created_at"  => $r['created_at'],
                "formatted"   => trim(
                    $r['endereco']
                    . ($r['complemento'] ? ' - ' . $r['complemento'] : '')
                    . ($r['bairro'] ? ', ' . $r['bairro'] : '')
                    . ($r['cidade'] ? ', ' . $r['cidade'] : '')
                    . ($r['estado'] ? '/' . $r['estado'] : '')
                ),
            ];
        }

        response(true, ["enderecos" => $enderecos]);
    }

    // =========================================================================
    // POST - Criar novo endereco
    // =========================================================================
    elseif ($method === 'POST') {
        $input = getInput();

        $endereco = trim($input['endereco'] ?? '');
        if (empty($endereco)) {
            response(false, null, "Endereco e obrigatorio.", 400);
        }

        // Limite de 10 enderecos
        $stmt = $db->prepare("SELECT COUNT(*) AS total FROM om_boraum_passenger_addresses WHERE passageiro_id = ?");
        $stmt->execute([$passageiroId]);
        $count = (int)$stmt->fetch()['total'];

        if ($count >= 10) {
            response(false, null, "Limite de 10 enderecos atingido. Remova um endereco antes de adicionar outro.", 400);
        }

        $complemento = trim(substr($input['complemento'] ?? '', 0, 200));
        $bairro      = trim(substr($input['bairro'] ?? '', 0, 100));
        $cidade      = trim(substr($input['cidade'] ?? '', 0, 100));
        $estado      = strtoupper(trim(substr($input['estado'] ?? '', 0, 2)));
        $cep         = preg_replace('/[^0-9]/', '', $input['cep'] ?? '');
        $lat         = !empty($input['lat']) ? (float)$input['lat'] : null;
        $lng         = !empty($input['lng']) ? (float)$input['lng'] : null;
        $label       = trim(substr($input['label'] ?? 'Casa', 0, 50));
        $isDefault   = (int)(!empty($input['is_default']));

        // Validar comprimento do endereco
        if (mb_strlen($endereco) > 500) {
            response(false, null, "Endereco muito longo (max 500 caracteres).", 400);
        }

        // Validar CEP se informado
        if (!empty($cep) && strlen($cep) !== 8) {
            response(false, null, "CEP invalido. Informe 8 digitos.", 400);
        }

        // Validar estado se informado
        if (!empty($estado) && strlen($estado) !== 2) {
            response(false, null, "Estado invalido. Use sigla com 2 letras (ex: SP).", 400);
        }

        // Validar coordenadas se informadas
        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            response(false, null, "Latitude invalida.", 400);
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            response(false, null, "Longitude invalida.", 400);
        }

        $db->beginTransaction();
        try {
            // Se marcar como padrao, desmarcar outros
            if ($isDefault) {
                $stmt = $db->prepare("UPDATE om_boraum_passenger_addresses SET is_default = 0 WHERE passageiro_id = ?");
                $stmt->execute([$passageiroId]);
            }

            // Se e o primeiro endereco, marcar como padrao automaticamente
            if ($count === 0) {
                $isDefault = 1;
            }

            $stmt = $db->prepare("
                INSERT INTO om_boraum_passenger_addresses
                    (passageiro_id, label, endereco, complemento, bairro, cidade, estado, cep, lat, lng, is_default, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $passageiroId, $label, $endereco, $complemento, $bairro,
                $cidade, $estado, $cep, $lat, $lng, $isDefault
            ]);

            $newId = (int)$db->lastInsertId();
            $db->commit();

            response(true, ["id" => $newId], "Endereco adicionado com sucesso!", 201);

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // PUT - Atualizar endereco existente
    // =========================================================================
    elseif ($method === 'PUT') {
        $input = getInput();

        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            response(false, null, "ID do endereco e obrigatorio.", 400);
        }

        // Verificar propriedade
        $stmt = $db->prepare("SELECT id FROM om_boraum_passenger_addresses WHERE id = ? AND passageiro_id = ?");
        $stmt->execute([$id, $passageiroId]);
        if (!$stmt->fetch()) {
            response(false, null, "Endereco nao encontrado.", 404);
        }

        // Montar campos para atualizar (somente os que foram enviados)
        $updates = [];
        $params = [];

        if (isset($input['endereco'])) {
            $val = trim($input['endereco']);
            if (empty($val)) {
                response(false, null, "Endereco nao pode ser vazio.", 400);
            }
            if (mb_strlen($val) > 500) {
                response(false, null, "Endereco muito longo (max 500 caracteres).", 400);
            }
            $updates[] = "endereco = ?";
            $params[] = $val;
        }

        if (isset($input['complemento'])) {
            $updates[] = "complemento = ?";
            $params[] = trim(substr($input['complemento'], 0, 200));
        }

        if (isset($input['bairro'])) {
            $updates[] = "bairro = ?";
            $params[] = trim(substr($input['bairro'], 0, 100));
        }

        if (isset($input['cidade'])) {
            $updates[] = "cidade = ?";
            $params[] = trim(substr($input['cidade'], 0, 100));
        }

        if (isset($input['estado'])) {
            $val = strtoupper(trim(substr($input['estado'], 0, 2)));
            if (!empty($val) && strlen($val) !== 2) {
                response(false, null, "Estado invalido. Use sigla com 2 letras.", 400);
            }
            $updates[] = "estado = ?";
            $params[] = $val;
        }

        if (isset($input['cep'])) {
            $val = preg_replace('/[^0-9]/', '', $input['cep']);
            if (!empty($val) && strlen($val) !== 8) {
                response(false, null, "CEP invalido. Informe 8 digitos.", 400);
            }
            $updates[] = "cep = ?";
            $params[] = $val;
        }

        if (isset($input['lat'])) {
            $val = (float)$input['lat'];
            if ($val < -90 || $val > 90) {
                response(false, null, "Latitude invalida.", 400);
            }
            $updates[] = "lat = ?";
            $params[] = $val;
        }

        if (isset($input['lng'])) {
            $val = (float)$input['lng'];
            if ($val < -180 || $val > 180) {
                response(false, null, "Longitude invalida.", 400);
            }
            $updates[] = "lng = ?";
            $params[] = $val;
        }

        if (isset($input['label'])) {
            $updates[] = "label = ?";
            $params[] = trim(substr($input['label'], 0, 50));
        }

        if (isset($input['is_default'])) {
            $isDefault = (int)(!empty($input['is_default']));
            $updates[] = "is_default = ?";
            $params[] = $isDefault;
        }

        if (empty($updates)) {
            response(false, null, "Nenhum campo para atualizar.", 400);
        }

        // Whitelist validation: all field names in $updates are already validated
        // because they are constructed from hardcoded strings in the code above
        // (e.g., "endereco = ?", "complemento = ?", etc.)
        // This is safe because field names are never derived from user input

        $db->beginTransaction();
        try {
            // Se marcando como padrao, desmarcar outros primeiro
            if (isset($input['is_default']) && !empty($input['is_default'])) {
                $stmt = $db->prepare("UPDATE om_boraum_passenger_addresses SET is_default = 0 WHERE passageiro_id = ?");
                $stmt->execute([$passageiroId]);
            }

            $params[] = $id;
            $params[] = $passageiroId;
            // Safe: field names are hardcoded strings, values are bound via prepared statement
            $sql = "UPDATE om_boraum_passenger_addresses SET " . implode(', ', $updates) . " WHERE id = ? AND passageiro_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $db->commit();

            response(true, ["id" => $id], "Endereco atualizado com sucesso!");

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // DELETE - Remover endereco
    // =========================================================================
    elseif ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            response(false, null, "ID do endereco e obrigatorio.", 400);
        }

        // Verificar propriedade
        $stmt = $db->prepare("SELECT id, is_default FROM om_boraum_passenger_addresses WHERE id = ? AND passageiro_id = ?");
        $stmt->execute([$id, $passageiroId]);
        $address = $stmt->fetch();

        if (!$address) {
            response(false, null, "Endereco nao encontrado.", 404);
        }

        $wasDefault = (bool)$address['is_default'];

        $stmt = $db->prepare("DELETE FROM om_boraum_passenger_addresses WHERE id = ? AND passageiro_id = ?");
        $stmt->execute([$id, $passageiroId]);

        // Se era o padrao, promover o mais recente como novo padrao
        if ($wasDefault) {
            $stmt = $db->prepare("
                UPDATE om_boraum_passenger_addresses
                SET is_default = 1
                WHERE id = (
                    SELECT id FROM om_boraum_passenger_addresses
                    WHERE passageiro_id = ?
                    ORDER BY created_at DESC
                    LIMIT 1
                )
            ");
            $stmt->execute([$passageiroId]);
        }

        response(true, null, "Endereco removido com sucesso.");
    }

    // =========================================================================
    // Metodo nao suportado
    // =========================================================================
    else {
        response(false, null, "Metodo nao permitido.", 405);
    }

} catch (Exception $e) {
    error_log("[BoraUm Enderecos] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar enderecos. Tente novamente.", 500);
}
