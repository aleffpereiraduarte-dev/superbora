<?php
/**
 * GET /api/mercado/customer/profile.php - Retorna dados do perfil do cliente
 * PUT /api/mercado/customer/profile.php - Atualiza nome e telefone do cliente
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
setCorsHeaders();

header("Access-Control-Allow-Methods: GET, PUT, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Autenticar cliente
    $token = om_auth()->getTokenFromRequest();
    if (!$token) {
        response(false, null, "Token nao fornecido", 401);
    }

    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') {
        response(false, null, "Nao autorizado", 401);
    }

    $customerId = $payload['uid'];

    // Verificar se o cliente existe e esta ativo
    $stmt = $db->prepare("
        SELECT customer_id, name, email, phone, cpf, foto, is_active
        FROM om_customers
        WHERE customer_id = ? AND is_active = '1'
    ");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();

    if (!$customer) {
        response(false, null, "Cliente nao encontrado", 404);
    }

    $method = $_SERVER['REQUEST_METHOD'];

    // ─── GET: Retornar dados do perfil ───────────────────────────────────────
    if ($method === 'GET') {
        // Check if customer is also a partner
        $stmtPartner = $db->prepare("
            SELECT partner_id, status FROM om_market_partners
            WHERE email = ? OR owner_cpf = ?
            LIMIT 1
        ");
        $stmtPartner->execute([$customer['email'], $customer['cpf'] ?? '']);
        $partner = $stmtPartner->fetch();

        response(true, [
            "id" => (int)$customer['customer_id'],
            "nome" => $customer['name'],
            "email" => $customer['email'],
            "telefone" => $customer['phone'],
            "cpf" => $customer['cpf'],
            "foto" => $customer['foto'],
            "avatar" => $customer['foto'],
            "is_partner" => $partner ? true : false,
            "partner_id" => $partner ? (int)$partner['partner_id'] : null,
            "partner_status" => $partner ? (int)$partner['status'] : null
        ]);
    }

    // ─── PUT/POST: Atualizar perfil ──────────────────────────────────────────
    if ($method === 'PUT' || $method === 'POST') {
        $input = getInput();

        $nome = trim($input['nome'] ?? '');
        $telefone = trim($input['telefone'] ?? '');
        $cpfInput = trim($input['cpf'] ?? '');

        // Validacoes
        if (empty($nome)) {
            response(false, null, "Nome e obrigatorio", 400);
        }

        if (mb_strlen($nome) < 2) {
            response(false, null, "Nome deve ter pelo menos 2 caracteres", 400);
        }

        if (mb_strlen($nome) > 100) {
            response(false, null, "Nome muito longo", 400);
        }

        if (empty($telefone)) {
            response(false, null, "Telefone e obrigatorio", 400);
        }

        // Limpar telefone - aceitar apenas digitos
        $telefoneLimpo = preg_replace('/[^0-9]/', '', $telefone);

        // Validar formato: 10 ou 11 digitos (com DDD)
        if (strlen($telefoneLimpo) < 10 || strlen($telefoneLimpo) > 11) {
            response(false, null, "Telefone invalido. Use formato (XX) XXXXX-XXXX", 400);
        }

        // Validar CPF se fornecido
        $cpfLimpo = preg_replace('/[^0-9]/', '', $cpfInput);
        $cpfFinal = $customer['cpf']; // manter o atual por padrao
        if (!empty($cpfLimpo)) {
            if (strlen($cpfLimpo) !== 11) {
                response(false, null, "CPF invalido. Deve ter 11 digitos.", 400);
            }
            // Validacao basica de CPF (nao permite todos iguais)
            if (preg_match('/^(\d)\1{10}$/', $cpfLimpo)) {
                response(false, null, "CPF invalido.", 400);
            }
            // Validacao mod-11 (digitos verificadores)
            for ($t = 9; $t < 11; $t++) {
                $d = 0;
                for ($c = 0; $c < $t; $c++) {
                    $d += (int)$cpfLimpo[$c] * (($t + 1) - $c);
                }
                $d = ((10 * $d) % 11) % 10;
                if ((int)$cpfLimpo[$t] !== $d) {
                    response(false, null, "CPF invalido.", 400);
                }
            }
            $cpfFinal = $cpfLimpo;
        }

        // Atualizar no banco
        $stmtUpdate = $db->prepare("
            UPDATE om_customers
            SET name = ?, phone = ?, cpf = ?, updated_at = NOW()
            WHERE customer_id = ?
        ");
        $stmtUpdate->execute([$nome, $telefoneLimpo, $cpfFinal, $customerId]);

        response(true, [
            "id" => (int)$customer['customer_id'],
            "nome" => $nome,
            "email" => $customer['email'],
            "telefone" => $telefoneLimpo,
            "cpf" => $cpfFinal,
            "foto" => $customer['foto'],
            "avatar" => $customer['foto']
        ], "Perfil atualizado com sucesso!");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[customer/profile] Erro: " . $e->getMessage());
    response(false, null, "Erro ao processar perfil", 500);
}
