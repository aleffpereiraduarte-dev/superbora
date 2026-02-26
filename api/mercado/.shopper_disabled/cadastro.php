<?php
/**
 * ══════════════════════════════════════════════════════════════════════════════
 * POST /api/mercado/shopper/cadastro.php
 * Cadastro de novo shopper (sujeito à aprovação do RH)
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Body: {
 *   "nome": "João Silva",
 *   "email": "joao@email.com",
 *   "telefone": "11999999999",
 *   "senha": "minhasenha123",
 *   "cpf": "12345678900",
 *   "data_nascimento": "1990-01-15",
 *   "endereco": {
 *     "cep": "01310100",
 *     "logradouro": "Av Paulista",
 *     "numero": "1000",
 *     "bairro": "Bela Vista",
 *     "cidade": "São Paulo",
 *     "estado": "SP"
 *   },
 *   "documentos": {
 *     "foto_rg_frente": "base64...",
 *     "foto_rg_verso": "base64...",
 *     "foto_selfie": "base64..."
 *   }
 * }
 *
 * FLUXO:
 * 1. Shopper faz cadastro completo
 * 2. Status inicial = 0 (pendente)
 * 3. RH analisa documentos
 * 4. RH aprova (status='1') ou rejeita (status=2)
 * 5. Shopper é notificado
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $input = getInput();
    $db = getDB();

    OmAudit::getInstance()->setDb($db);

    // Validar campos obrigatórios
    $campos_obrigatorios = ['nome', 'email', 'telefone', 'senha', 'cpf'];
    foreach ($campos_obrigatorios as $campo) {
        if (empty($input[$campo])) {
            response(false, null, "Campo '$campo' é obrigatório", 400);
        }
    }

    $nome = trim($input['nome']);
    $email = strtolower(trim($input['email']));
    $telefone = preg_replace('/[^0-9]/', '', $input['telefone']);
    $senha = $input['senha'];
    $cpf = preg_replace('/[^0-9]/', '', $input['cpf']);
    $data_nascimento = $input['data_nascimento'] ?? null;
    $endereco = $input['endereco'] ?? [];
    $documentos = $input['documentos'] ?? [];

    // Validações
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        response(false, null, "Email inválido", 400);
    }

    if (strlen($telefone) < 10 || strlen($telefone) > 11) {
        response(false, null, "Telefone inválido", 400);
    }

    if (strlen($cpf) !== 11) {
        response(false, null, "CPF inválido", 400);
    }

    if (strlen($senha) < 6) {
        response(false, null, "Senha deve ter no mínimo 6 caracteres", 400);
    }

    // Verificar se email já existe
    $stmt = $db->prepare("SELECT shopper_id FROM om_market_shoppers WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        response(false, null, "Este email já está cadastrado", 409);
    }

    // Verificar se CPF já existe
    $stmt = $db->prepare("SELECT shopper_id FROM om_market_shoppers WHERE cpf = ?");
    $stmt->execute([$cpf]);
    if ($stmt->fetch()) {
        response(false, null, "Este CPF já está cadastrado", 409);
    }

    // Verificar se telefone já existe
    $stmt = $db->prepare("SELECT shopper_id FROM om_market_shoppers WHERE phone = ?");
    $stmt->execute([$telefone]);
    if ($stmt->fetch()) {
        response(false, null, "Este telefone já está cadastrado", 409);
    }

    // Hash da senha usando Argon2
    $senha_hash = password_hash($senha, PASSWORD_ARGON2ID);

    // Iniciar transação
    $db->beginTransaction();

    try {
        // Inserir shopper com status pendente (0)
        $stmt = $db->prepare("
            INSERT INTO om_market_shoppers (
                name, email, phone, password, cpf,
                data_nascimento, endereco_cep, endereco_logradouro,
                endereco_numero, endereco_bairro, endereco_cidade, endereco_estado,
                status, disponivel, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())
        ");
        $stmt->execute([
            $nome, $email, $telefone, $senha_hash, $cpf,
            $data_nascimento,
            $endereco['cep'] ?? null,
            $endereco['logradouro'] ?? null,
            $endereco['numero'] ?? null,
            $endereco['bairro'] ?? null,
            $endereco['cidade'] ?? null,
            $endereco['estado'] ?? null
        ]);

        $shopper_id = $db->lastInsertId();

        // Salvar documentos (se fornecidos)
        if (!empty($documentos)) {
            foreach ($documentos as $tipo => $base64) {
                if ($base64) {
                    $stmt = $db->prepare("
                        INSERT INTO om_shopper_documentos (shopper_id, tipo, arquivo, created_at)
                        VALUES (?, ?, ?, NOW())
                    ");
                    $stmt->execute([$shopper_id, $tipo, $base64]);
                }
            }
        }

        // Criar registro de saldo zerado
        $stmt = $db->prepare("
            INSERT INTO om_shopper_saldo (shopper_id, saldo_disponivel, saldo_bloqueado, total_ganhos, total_saques)
            VALUES (?, 0, 0, 0, 0)
        ");
        $stmt->execute([$shopper_id]);

        $db->commit();

        // Log de auditoria
        om_audit()->log('create', 'shopper', $shopper_id, null, [
            'nome' => $nome,
            'email' => $email,
            'telefone' => $telefone
        ], "Novo cadastro de shopper - aguardando aprovação RH");

        // Gerar token limitado (para acompanhar status)
        OmAuth::getInstance()->setDb($db);
        $token = om_auth()->generateToken(
            OmAuth::USER_TYPE_SHOPPER,
            $shopper_id,
            ['approved' => false, 'status' => 'pending']
        );

        response(true, [
            "shopper_id" => $shopper_id,
            "nome" => $nome,
            "email" => $email,
            "token" => $token,
            "status" => "pending",
            "mensagem" => "Cadastro realizado com sucesso! Seu cadastro será analisado pelo RH em até 48 horas úteis."
        ], "Cadastro realizado! Aguarde aprovação do RH.");

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log("[cadastro] Erro: " . $e->getMessage());
    response(false, null, "Erro ao realizar cadastro. Tente novamente.", 500);
}
