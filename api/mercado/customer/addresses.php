<?php
/**
 * GET/POST/PUT/PATCH/DELETE /api/mercado/customer/addresses.php
 * CRUD de enderecos do cliente autenticado
 *
 * GET              - listar enderecos
 * POST             - adicionar endereco
 * PUT              - atualizar endereco (body: { id, ... })
 * PATCH            - definir endereco padrao (body: { id, is_default: true })
 * DELETE ?id=N     - remover endereco (soft delete)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Requer autenticacao do cliente
    $token = om_auth()->getTokenFromRequest();
    if (!$token) {
        response(false, null, "Autenticacao necessaria", 401);
    }
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Token invalido", 401);
    }
    $customerId = (int)$payload['uid'];

    $method = $_SERVER['REQUEST_METHOD'];

    // ── GET: listar enderecos ───────────────────────────────────────
    if ($method === 'GET') {
        $stmt = $db->prepare("
            SELECT address_id, label, street, number, complement, neighborhood,
                   city, state, zipcode, lat, lng, reference, is_default
            FROM om_customer_addresses
            WHERE customer_id = ? AND is_active = '1'
            ORDER BY is_default DESC, address_id DESC
        ");
        $stmt->execute([$customerId]);
        $addresses = $stmt->fetchAll();

        $result = [];
        foreach ($addresses as $a) {
            $result[] = [
                "id" => (int)$a['address_id'],
                "label" => $a['label'],
                "cep" => $a['zipcode'],
                "logradouro" => $a['street'],
                "numero" => $a['number'],
                "complemento" => $a['complement'],
                "bairro" => $a['neighborhood'],
                "cidade" => $a['city'],
                "estado" => $a['state'],
                "referencia" => $a['reference'],
                "latitude" => $a['lat'] ? (float)$a['lat'] : null,
                "longitude" => $a['lng'] ? (float)$a['lng'] : null,
                "is_default" => (bool)$a['is_default'],
                "formatted" => trim(
                    $a['street'] . ', ' . $a['number']
                    . ($a['complement'] ? ' - ' . $a['complement'] : '')
                    . ' - ' . $a['neighborhood']
                    . ', ' . $a['city'] . '/' . $a['state']
                )
            ];
        }

        response(true, ["addresses" => $result]);
    }

    // ── POST: adicionar endereco ────────────────────────────────────
    elseif ($method === 'POST') {
        $input = getInput();

        $label = strip_tags(trim(substr($input['label'] ?? 'Casa', 0, 30)));
        $zipcode = preg_replace('/[^0-9]/', '', $input['cep'] ?? $input['zipcode'] ?? '');
        $street = strip_tags(trim(substr($input['logradouro'] ?? $input['street'] ?? '', 0, 200)));
        $number = strip_tags(trim(substr($input['numero'] ?? $input['number'] ?? '', 0, 20)));
        $complement = strip_tags(trim(substr($input['complemento'] ?? $input['complement'] ?? '', 0, 100)));
        $neighborhood = strip_tags(trim(substr($input['bairro'] ?? $input['neighborhood'] ?? '', 0, 100)));
        $city = strip_tags(trim(substr($input['cidade'] ?? $input['city'] ?? '', 0, 100)));
        $state = strtoupper(trim(substr($input['estado'] ?? $input['state'] ?? '', 0, 2)));
        $lat = !empty($input['latitude']) ? (float)$input['latitude'] : null;
        $lng = !empty($input['longitude']) ? (float)$input['longitude'] : null;
        $reference = strip_tags(trim(substr($input['referencia'] ?? $input['reference'] ?? '', 0, 255)));
        $isDefault = (bool)($input['is_default'] ?? false);

        // Validar campos obrigatorios
        if (empty($street)) {
            response(false, null, "Logradouro e obrigatorio", 400);
        }
        if (empty($number)) {
            response(false, null, "Numero e obrigatorio", 400);
        }
        if (empty($neighborhood)) {
            response(false, null, "Bairro e obrigatorio", 400);
        }
        if (empty($city)) {
            response(false, null, "Cidade e obrigatoria", 400);
        }
        if (empty($state)) {
            response(false, null, "Estado e obrigatorio", 400);
        }
        $validStates = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
        if (!in_array($state, $validStates, true)) {
            response(false, null, "Estado invalido", 400);
        }

        // Usar transação para garantir atomicidade do is_default
        $db->beginTransaction();
        try {
            // Se marcar como padrao, desmarcar outros
            if ($isDefault) {
                $db->prepare("UPDATE om_customer_addresses SET is_default = 0 WHERE customer_id = ?")
                    ->execute([$customerId]);
            }

            $stmt = $db->prepare("
                INSERT INTO om_customer_addresses
                    (customer_id, label, zipcode, street, number, complement, neighborhood, city, state, lat, lng, reference, is_default, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([
                $customerId, $label, $zipcode, $street, $number, $complement,
                $neighborhood, $city, $state, $lat, $lng, $reference, $isDefault ? 1 : 0
            ]);

            $addressId = (int)$db->lastInsertId();
            $db->commit();
        } catch (Exception $txEx) {
            $db->rollBack();
            throw $txEx;
        }

        response(true, ["address_id" => $addressId], "Endereco adicionado!", 201);
    }

    // ── PUT: atualizar endereco ─────────────────────────────────────
    elseif ($method === 'PUT') {
        $input = getInput();

        $addressId = (int)($input['address_id'] ?? $input['id'] ?? 0);
        if (!$addressId) {
            response(false, null, "ID do endereco obrigatorio", 400);
        }

        // Verificar ownership
        $stmt = $db->prepare("SELECT address_id FROM om_customer_addresses WHERE address_id = ? AND customer_id = ? AND is_active = '1'");
        $stmt->execute([$addressId, $customerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Endereco nao encontrado", 404);
        }

        $label = strip_tags(trim(substr($input['label'] ?? 'Casa', 0, 30)));
        $zipcode = preg_replace('/[^0-9]/', '', $input['cep'] ?? $input['zipcode'] ?? '');
        $street = strip_tags(trim(substr($input['logradouro'] ?? $input['street'] ?? '', 0, 200)));
        $number = strip_tags(trim(substr($input['numero'] ?? $input['number'] ?? '', 0, 20)));
        $complement = strip_tags(trim(substr($input['complemento'] ?? $input['complement'] ?? '', 0, 100)));
        $neighborhood = strip_tags(trim(substr($input['bairro'] ?? $input['neighborhood'] ?? '', 0, 100)));
        $city = strip_tags(trim(substr($input['cidade'] ?? $input['city'] ?? '', 0, 100)));
        $state = strtoupper(trim(substr($input['estado'] ?? $input['state'] ?? '', 0, 2)));
        $reference = strip_tags(trim(substr($input['referencia'] ?? $input['reference'] ?? '', 0, 255)));

        if (empty($street) || empty($number) || empty($neighborhood) || empty($city) || empty($state)) {
            response(false, null, "Endereco incompleto", 400);
        }
        $validStates = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
        if (!in_array($state, $validStates, true)) {
            response(false, null, "Estado invalido", 400);
        }

        $stmt = $db->prepare("
            UPDATE om_customer_addresses
            SET label = ?, zipcode = ?, street = ?, number = ?, complement = ?,
                neighborhood = ?, city = ?, state = ?, reference = ?
            WHERE address_id = ? AND customer_id = ?
        ");
        $stmt->execute([
            $label, $zipcode, $street, $number, $complement,
            $neighborhood, $city, $state, $reference,
            $addressId, $customerId
        ]);

        response(true, ["address_id" => $addressId], "Endereco atualizado!");
    }

    // ── PATCH: definir endereco padrao ──────────────────────────────
    elseif ($method === 'PATCH') {
        $input = getInput();
        $addressId = (int)($input['address_id'] ?? $input['id'] ?? 0);
        if (!$addressId) {
            response(false, null, "ID do endereco obrigatorio", 400);
        }

        // Verificar ownership
        $stmt = $db->prepare("SELECT address_id FROM om_customer_addresses WHERE address_id = ? AND customer_id = ? AND is_active = '1'");
        $stmt->execute([$addressId, $customerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Endereco nao encontrado", 404);
        }

        // Definir como padrao: transação atômica para desmarcar outros e marcar este
        if (!empty($input['is_default'])) {
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE om_customer_addresses SET is_default = 0 WHERE customer_id = ?")->execute([$customerId]);
                $db->prepare("UPDATE om_customer_addresses SET is_default = 1 WHERE address_id = ? AND customer_id = ?")->execute([$addressId, $customerId]);
                $db->commit();
            } catch (Exception $txEx) {
                $db->rollBack();
                throw $txEx;
            }
        }

        response(true, ["address_id" => $addressId], "Endereco atualizado!");
    }

    // ── DELETE: remover endereco (soft delete) ──────────────────────
    elseif ($method === 'DELETE') {
        $addressId = (int)($_GET['id'] ?? 0);
        if (!$addressId) {
            response(false, null, "ID do endereco obrigatorio", 400);
        }

        // Soft delete - somente se pertence ao cliente
        $stmt = $db->prepare("UPDATE om_customer_addresses SET is_active = 0 WHERE address_id = ? AND customer_id = ?");
        $stmt->execute([$addressId, $customerId]);

        if ($stmt->rowCount() === 0) {
            response(false, null, "Endereco nao encontrado", 404);
        }

        response(true, null, "Endereco removido");
    }

    else {
        response(false, null, "Metodo nao permitido", 405);
    }

} catch (Exception $e) {
    error_log("[customer/addresses] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar enderecos", 500);
}
