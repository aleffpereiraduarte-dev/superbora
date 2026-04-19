<?php
/**
 * POST /api/mercado/parceiro/cadastro.php
 * Cadastro de novo parceiro (loja/restaurante/mercado)
 *
 * Body: {
 *   nome, cpf, telefone, email, senha,
 *   tipo_documento (cnpj|cpf), documento_empresa, nome_loja, razao_social, nome_fantasia, categoria,
 *   cep, endereco, numero, complemento, bairro, cidade, estado,
 *   termos_aceitos (boolean)
 * }
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/geocoder.php";
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Método não permitido", 405);
}

try {
    $db = getDB();
    $input = getInput();

    // Validações obrigatórias
    $required = ['nome', 'telefone', 'email', 'senha', 'nome_loja', 'categoria', 'cep', 'endereco', 'numero', 'bairro', 'cidade', 'estado'];
    foreach ($required as $field) {
        if (empty(trim($input[$field] ?? ''))) {
            response(false, null, "Campo obrigatório: $field", 400);
        }
    }

    $nome = trim($input['nome']);
    $cpf = preg_replace('/\D/', '', $input['cpf'] ?? '');
    $telefone = preg_replace('/\D/', '', $input['telefone']);
    $email = strtolower(trim($input['email']));
    $senha = $input['senha'];
    $tipoDoc = $input['tipo_documento'] ?? 'cpf';
    $documento = preg_replace('/\D/', '', $input['documento_empresa'] ?? '');
    $nomeLoja = trim($input['nome_loja']);
    $razaoSocial = trim($input['razao_social'] ?? '');
    $nomeFantasia = trim($input['nome_fantasia'] ?? $nomeLoja);
    $categoria = trim($input['categoria']);
    $cep = preg_replace('/\D/', '', $input['cep']);
    $endereco = trim($input['endereco']);
    $numero = trim($input['numero']);
    $complemento = trim($input['complemento'] ?? '');
    $bairro = trim($input['bairro']);
    $cidade = trim($input['cidade']);
    $estado = strtoupper(trim($input['estado']));
    $termosAceitos = filter_var($input['termos_aceitos'] ?? false, FILTER_VALIDATE_BOOLEAN);

    // Validações
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        response(false, null, "Email inválido", 400);
    }
    if (strlen($senha) < 6) {
        response(false, null, "Senha deve ter no mínimo 6 caracteres", 400);
    }
    // Phone: must be 10-15 digits (DDD + number, with or without country code)
    if (strlen($telefone) < 10 || strlen($telefone) > 15) {
        response(false, null, "Telefone inválido. Informe DDD + número (10-15 dígitos)", 400);
    }
    // CEP: must be exactly 8 digits
    if (strlen($cep) !== 8) {
        response(false, null, "CEP inválido. Informe 8 dígitos", 400);
    }
    if (!$termosAceitos) {
        response(false, null, "Você precisa aceitar os termos de uso", 400);
    }

    // Verificar se email já existe
    $stmt = $db->prepare("SELECT partner_id FROM om_market_partners WHERE login_email = ? OR email = ? LIMIT 1");
    $stmt->execute([$email, $email]);
    if ($stmt->fetch()) {
        response(false, null, "Já existe uma conta com esse email. Faça login no painel.", 409);
    }

    // Verificar se documento já existe (se informado)
    if (!empty($documento)) {
        $stmt = $db->prepare("SELECT partner_id FROM om_market_partners WHERE document = ? OR cnpj = ? LIMIT 1");
        $stmt->execute([$documento, $documento]);
        if ($stmt->fetch()) {
            response(false, null, "Já existe uma loja cadastrada com esse documento.", 409);
        }
    }

    // Hash da senha
    $senhaHash = password_hash($senha, PASSWORD_ARGON2ID);

    // Geocoding: CEP + endereço → lat/lng via Nominatim (OSM)
    $lat = null;
    $lng = null;
    try {
        $coords = geocodeAddress([
            'street' => $endereco,
            'number' => $numero,
            'neighborhood' => $bairro,
            'city' => $cidade,
            'state' => $estado,
            'cep' => $cep,
        ]);
        if ($coords) {
            $lat = $coords['lat'];
            $lng = $coords['lng'];
        }
    } catch (\Throwable $e) {
        error_log("[parceiro-cadastro] geocoding failed: " . $e->getMessage());
    }

    // Endereço completo
    $enderecoCompleto = "$endereco, $numero" . ($complemento ? " - $complemento" : "") . " - $bairro, $cidade - $estado, $cep";

    // IP do cadastro
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Inserir parceiro
    $stmt = $db->prepare("
        INSERT INTO om_market_partners (
            name, trade_name, contact_name, document, cnpj, document_type,
            razao_social, nome_fantasia, owner_name, owner_cpf, owner_phone,
            phone, telefone, whatsapp, email, login_email, login_password,
            categoria, category, store_type,
            cep, street, address, number, address_number, complement, address_complement,
            neighborhood, bairro, city, cidade, state, estado,
            endereco, endereco_completo,
            status, verified, registration_step,
            terms_accepted_at, contract_signed_at, contract_ip, contract_signed_ip,
            is_open, opens_at, closes_at,
            delivery_fee, taxa_entrega, min_order, min_order_value, pedido_minimo,
            delivery_time_min, tempo_preparo, delivery_radius_km, delivery_radius, raio_entrega_km,
            commission_rate, commission_type,
            accepts_pix, accepts_card,
            rating, health_score,
            lat, lng,
            created_at, date_added
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?,
            '0', 0, 0,
            NOW(), NOW(), ?, ?,
            1, '08:00:00', '22:00:00',
            5.99, 5.99, 30.00, 30.00, 30.00,
            25, 30, 10, 10, 10,
            10.00, 'percentage',
            1, 1,
            5.0, 100.0,
            ?, ?,
            NOW(), NOW()
        ) RETURNING partner_id
    ");

    $stmt->execute([
        $nomeLoja, $nomeFantasia, $nome, $documento ?: $cpf, $documento ?: null, $tipoDoc,
        $razaoSocial, $nomeFantasia, $nome, $cpf, $telefone,
        $telefone, $telefone, $telefone, $email, $email, $senhaHash,
        $categoria, $categoria, $categoria,
        $cep, $endereco, $endereco, $numero, $numero, $complemento, $complemento,
        $bairro, $bairro, $cidade, $cidade, $estado, $estado,
        $endereco, $enderecoCompleto,
        $ip, $ip,
        $lat, $lng
    ]);

    $partnerId = (int)$stmt->fetchColumn();

    // Vincular ao customer se logado
    $customerId = null;
    try {
        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $token, $m)) {
            $payload = json_decode(base64_decode(explode('.', $m[1])[1] ?? ''), true);
            $customerId = (int)($payload['sub'] ?? $payload['customer_id'] ?? $payload['user_id'] ?? 0);
        }
    } catch (\Throwable $e) {}

    if ($customerId > 0) {
        // Link customer to partner
        $db->prepare("UPDATE om_market_partners SET customer_id = ? WHERE partner_id = ?")->execute([$customerId, $partnerId]);
    }

    // Log
    error_log("[parceiro-cadastro] Novo parceiro: #$partnerId '$nomeLoja' ($email) - $categoria - $cidade/$estado");

    response(true, [
        'partner_id' => $partnerId,
        'nome_loja' => $nomeLoja,
        'status' => 'pendente',
        'message' => 'Cadastro realizado! Nossa equipe vai analisar em até 48 horas úteis.'
    ], "Cadastro realizado com sucesso!");

} catch (\Throwable $e) {
    error_log("[parceiro-cadastro] Erro: " . $e->getMessage());
    if (strpos($e->getMessage(), 'duplicate key') !== false) {
        response(false, null, "Já existe uma loja com esses dados.", 409);
    }
    response(false, null, "Erro ao realizar cadastro. Tente novamente.", 500);
}
