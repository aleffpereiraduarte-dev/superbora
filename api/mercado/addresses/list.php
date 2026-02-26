<?php
/**
 * GET/POST/PUT/PATCH/DELETE /api/mercado/addresses/list.php
 * CRUD enderecos do cliente
 * PATCH: set address as default (body: { address_id, is_default: true })
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Requer autenticacao
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

    elseif ($method === 'POST') {
        $input = getInput();

        $label = trim(substr($input['label'] ?? 'Casa', 0, 30));
        $zipcode = preg_replace('/[^0-9]/', '', $input['cep'] ?? $input['zipcode'] ?? '');
        $street = trim(substr($input['logradouro'] ?? $input['street'] ?? '', 0, 200));
        $number = trim(substr($input['numero'] ?? $input['number'] ?? '', 0, 20));
        $complement = trim(substr($input['complemento'] ?? $input['complement'] ?? '', 0, 100));
        $neighborhood = trim(substr($input['bairro'] ?? $input['neighborhood'] ?? '', 0, 100));
        $city = trim(substr($input['cidade'] ?? $input['city'] ?? '', 0, 100));
        $state = strtoupper(trim(substr($input['estado'] ?? $input['state'] ?? '', 0, 2)));
        $lat = !empty($input['latitude']) ? (float)$input['latitude'] : null;
        $lng = !empty($input['longitude']) ? (float)$input['longitude'] : null;
        $reference = trim(substr($input['referencia'] ?? $input['reference'] ?? '', 0, 255));
        $isDefault = (bool)($input['is_default'] ?? false);

        if (empty($street) || empty($number) || empty($neighborhood) || empty($city) || empty($state)) {
            response(false, null, "Endereco incompleto", 400);
        }

        // Transação para garantir atomicidade do is_default
        $db->beginTransaction();
        try {
            // Se marcar como padrao, desmarcar outros
            if ($isDefault) {
                $stmt = $db->prepare("UPDATE om_customer_addresses SET is_default = 0 WHERE customer_id = ?");
                $stmt->execute([$customerId]);
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

        response(true, [
            "address_id" => $addressId
        ], "Endereco adicionado!", 201);
    }

    elseif ($method === 'PUT') {
        $input = getInput();

        $addressId = (int)($input['address_id'] ?? $input['id'] ?? 0);
        if (!$addressId) {
            response(false, null, "ID do endereco obrigatorio", 400);
        }

        // Verificar se o endereco pertence ao cliente
        $stmt = $db->prepare("SELECT address_id FROM om_customer_addresses WHERE address_id = ? AND customer_id = ? AND is_active = '1'");
        $stmt->execute([$addressId, $customerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Endereco nao encontrado", 404);
        }

        $label = trim(substr($input['label'] ?? $input['rotulo'] ?? 'Casa', 0, 30));
        $zipcode = preg_replace('/[^0-9]/', '', $input['cep'] ?? $input['zipcode'] ?? '');
        $street = trim(substr($input['logradouro'] ?? $input['street'] ?? '', 0, 200));
        $number = trim(substr($input['numero'] ?? $input['number'] ?? '', 0, 20));
        $complement = trim(substr($input['complemento'] ?? $input['complement'] ?? '', 0, 100));
        $neighborhood = trim(substr($input['bairro'] ?? $input['neighborhood'] ?? '', 0, 100));
        $city = trim(substr($input['cidade'] ?? $input['city'] ?? '', 0, 100));
        $state = strtoupper(trim(substr($input['estado'] ?? $input['state'] ?? '', 0, 2)));
        $reference = trim(substr($input['referencia'] ?? $input['reference'] ?? '', 0, 255));

        if (empty($street) || empty($number) || empty($neighborhood) || empty($city) || empty($state)) {
            response(false, null, "Endereco incompleto", 400);
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

        $formatted = trim(
            $street . ', ' . $number
            . ($complement ? ' - ' . $complement : '')
            . ' - ' . $neighborhood
            . ', ' . $city . '/' . $state
        );

        response(true, [
            "address" => [
                "id" => $addressId,
                "label" => $label,
                "cep" => $zipcode,
                "logradouro" => $street,
                "numero" => $number,
                "complemento" => $complement,
                "bairro" => $neighborhood,
                "cidade" => $city,
                "estado" => $state,
                "referencia" => $reference,
                "formatted" => $formatted
            ]
        ], "Endereco atualizado!");
    }

    elseif ($method === 'PATCH') {
        $input = getInput();
        $addressId = (int)($input['address_id'] ?? $input['id'] ?? 0);
        if (!$addressId) {
            response(false, null, "ID do endereco obrigatorio", 400);
        }

        // Verify ownership
        $stmt = $db->prepare("SELECT address_id FROM om_customer_addresses WHERE address_id = ? AND customer_id = ? AND is_active = '1'");
        $stmt->execute([$addressId, $customerId]);
        if (!$stmt->fetch()) {
            response(false, null, "Endereco nao encontrado", 404);
        }

        // Set as default: transação atômica
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

    elseif ($method === 'DELETE') {
        $addressId = (int)($_GET['id'] ?? 0);
        if (!$addressId) {
            response(false, null, "ID do endereco obrigatorio", 400);
        }

        // Soft delete
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
    error_log("[API Addresses] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar enderecos", 500);
}
