<?php
/**
 * /api/mercado/boraum/auth.php
 *
 * Middleware de autenticacao para passageiros do BoraUm.
 * Suporta 3 metodos de autenticacao:
 *
 * 1. JWT (HS256) - Authorization: Bearer <jwt_token>
 *    Payload esperado: { sub: passageiro_id, telefone, nome, ... }
 *
 * 2. API Key + Passageiro ID - Header X-Api-Key + X-Passageiro-Id ou X-Passageiro-Telefone
 *    Para chamadas server-to-server do backend BoraUm
 *
 * 3. Hex Token (legado) - Authorization: Bearer <64_hex_chars>
 *    Token gerado no login do passageiro (boraum_passageiros.token)
 *
 * IMPORTANTE:
 * - JWT e API Key NAO criam registro em boraum_passageiros (evita conflito com cadastro)
 * - Criam apenas em om_customers (tabela do marketplace)
 * - Hex token exige registro previo em boraum_passageiros
 */

/**
 * Autentica o passageiro do BoraUm.
 * Retorna array com { passageiro_id, customer_id, nome, telefone, email, saldo }
 */
function requirePassageiro(PDO $db): array {
    // ── Metodo 1: API Key (server-to-server) ────────────────────────────
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!empty($apiKey)) {
        return authenticateByApiKey($db, $apiKey);
    }

    // ── Extrair Bearer token ────────────────────────────────────────────
    $token = getPassageiroToken();
    if (!$token) {
        response(false, null, "Token de autenticacao obrigatorio. Envie no header Authorization: Bearer <token>", 401);
    }

    // ── Metodo 2: JWT (token com 2 pontos = 3 segmentos) ───────────────
    if (substr_count($token, '.') === 2) {
        return authenticateByJWT($db, $token);
    }

    // ── Metodo 3: Hex token (legado) ────────────────────────────────────
    return authenticateByHexToken($db, $token);
}

// =====================================================================
// Metodo 1: API Key + Passageiro ID/Telefone
// =====================================================================
function authenticateByApiKey(PDO $db, string $apiKey): array {
    $expectedKey = $_ENV['BORAUM_PARTNER_API_KEY'] ?? '';

    if (empty($expectedKey) || !hash_equals($expectedKey, $apiKey)) {
        response(false, null, "API Key invalida.", 401);
    }

    $telefone = preg_replace('/[^0-9]/', '', $_SERVER['HTTP_X_PASSAGEIRO_TELEFONE'] ?? '');
    $nome = $_SERVER['HTTP_X_PASSAGEIRO_NOME'] ?? 'Passageiro BoraUm';
    $passageiroId = (int)($_SERVER['HTTP_X_PASSAGEIRO_ID'] ?? 0);

    if (empty($telefone) && $passageiroId <= 0) {
        response(false, null, "API Key autenticada, mas falta identificar o passageiro. Envie X-Passageiro-Telefone no header.", 400);
    }

    // Tentar buscar em boraum_passageiros (se ja existe)
    if ($passageiroId > 0) {
        $stmt = $db->prepare("SELECT id, nome, telefone, email, cpf, foto, status, saldo FROM boraum_passageiros WHERE id = ? LIMIT 1");
        $stmt->execute([$passageiroId]);
        $passageiro = $stmt->fetch();
        if ($passageiro) {
            return buildPassageiroResponse($db, $passageiro);
        }
    }

    if (!empty($telefone)) {
        $stmt = $db->prepare("SELECT id, nome, telefone, email, cpf, foto, status, saldo FROM boraum_passageiros WHERE telefone = ? LIMIT 1");
        $stmt->execute([$telefone]);
        $passageiro = $stmt->fetch();
        if ($passageiro) {
            return buildPassageiroResponse($db, $passageiro);
        }
    }

    // Passageiro NAO existe em boraum_passageiros - OK!
    // Criar/buscar apenas em om_customers (sem tocar boraum_passageiros)
    $customerId = getOrCreateCustomerByPhone($db, $telefone, $nome);

    return [
        'passageiro_id' => 0,
        'customer_id'   => $customerId,
        'nome'          => $nome,
        'telefone'      => $telefone,
        'email'         => null,
        'cpf'           => null,
        'foto'          => null,
        'saldo'         => 0.0,
    ];
}

// =====================================================================
// Metodo 2: JWT (HS256)
// =====================================================================
function authenticateByJWT(PDO $db, string $jwt): array {
    $secret = $_ENV['BORAUM_JWT_SECRET'] ?? '';

    if (empty($secret) || $secret === 'PEGAR_COM_BORAUM') {
        $secret = $_ENV['JWT_SECRET'] ?? '';
    }

    if (empty($secret)) {
        error_log("[BoraUm Auth] JWT secret nao configurado (BORAUM_JWT_SECRET)");
        response(false, null, "Autenticacao JWT nao configurada. Contate o suporte.", 500);
    }

    $payload = decodeHS256JWT($jwt, $secret);
    if (!$payload) {
        response(false, null, "Token JWT invalido ou expirado.", 401);
    }

    $passageiroId = (int)($payload['sub'] ?? $payload['passageiro_id'] ?? $payload['user_id'] ?? 0);
    $telefone = preg_replace('/[^0-9]/', '', $payload['telefone'] ?? $payload['phone'] ?? '');
    $nome = $payload['nome'] ?? $payload['name'] ?? 'Passageiro BoraUm';

    // Tentar buscar em boraum_passageiros (se ja existe)
    if ($passageiroId > 0) {
        $stmt = $db->prepare("SELECT id, nome, telefone, email, cpf, foto, status, saldo FROM boraum_passageiros WHERE id = ? LIMIT 1");
        $stmt->execute([$passageiroId]);
        $passageiro = $stmt->fetch();
        if ($passageiro) {
            return buildPassageiroResponse($db, $passageiro);
        }
    }

    if (!empty($telefone)) {
        $stmt = $db->prepare("SELECT id, nome, telefone, email, cpf, foto, status, saldo FROM boraum_passageiros WHERE telefone = ? LIMIT 1");
        $stmt->execute([$telefone]);
        $passageiro = $stmt->fetch();
        if ($passageiro) {
            return buildPassageiroResponse($db, $passageiro);
        }
    }

    if (empty($telefone)) {
        response(false, null, "JWT valido mas sem telefone do passageiro.", 400);
    }

    // NAO cria em boraum_passageiros - cria apenas em om_customers
    $customerId = getOrCreateCustomerByPhone($db, $telefone, $nome);

    return [
        'passageiro_id' => 0,
        'customer_id'   => $customerId,
        'nome'          => $nome,
        'telefone'      => $telefone,
        'email'         => null,
        'cpf'           => null,
        'foto'          => null,
        'saldo'         => 0.0,
    ];
}

// =====================================================================
// Metodo 3: Hex Token (legado - login direto)
// =====================================================================
function authenticateByHexToken(PDO $db, string $token): array {
    $stmt = $db->prepare("SELECT id, nome, telefone, email, cpf, foto, status, saldo FROM boraum_passageiros WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $passageiro = $stmt->fetch();

    if (!$passageiro) {
        response(false, null, "Token invalido ou sessao expirada. Faca login novamente.", 401);
    }

    return buildPassageiroResponse($db, $passageiro);
}

// =====================================================================
// Helpers
// =====================================================================

function decodeHS256JWT(string $jwt, string $secret): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;

    [$header64, $payload64, $sig64] = $parts;

    $header = json_decode(base64url_decode($header64), true);
    if (!$header || ($header['alg'] ?? '') !== 'HS256') {
        error_log("[BoraUm Auth] JWT algoritmo invalido: " . ($header['alg'] ?? 'null'));
        return null;
    }

    $expectedSig = hash_hmac('sha256', "$header64.$payload64", $secret, true);
    $expectedSig64 = base64url_encode($expectedSig);

    if (!hash_equals($expectedSig64, $sig64)) {
        error_log("[BoraUm Auth] JWT assinatura invalida");
        return null;
    }

    $payload = json_decode(base64url_decode($payload64), true);
    if (!$payload) return null;

    // SECURITY: Validate required JWT claims
    // Check for expiration
    if (isset($payload['exp']) && (int)$payload['exp'] < time()) {
        error_log("[BoraUm Auth] JWT expirado");
        return null;
    }

    // Validate 'iat' (issued at) is present and not in the future
    if (isset($payload['iat'])) {
        $iat = (int)$payload['iat'];
        // Allow 60 seconds of clock skew
        if ($iat > time() + 60) {
            error_log("[BoraUm Auth] JWT iat no futuro: $iat");
            return null;
        }
    }

    // Validate 'nbf' (not before) if present
    if (isset($payload['nbf'])) {
        $nbf = (int)$payload['nbf'];
        // Allow 60 seconds of clock skew
        if ($nbf > time() + 60) {
            error_log("[BoraUm Auth] JWT nbf ainda nao valido: $nbf");
            return null;
        }
    }

    // Validate subject claim exists (passageiro identification)
    if (empty($payload['sub']) && empty($payload['passageiro_id']) && empty($payload['user_id']) && empty($payload['telefone']) && empty($payload['phone'])) {
        error_log("[BoraUm Auth] JWT sem identificador de passageiro (sub/passageiro_id/user_id/telefone)");
        return null;
    }

    return $payload;
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Monta resposta para passageiro que JA existe em boraum_passageiros.
 */
function buildPassageiroResponse(PDO $db, array $passageiro): array {
    if ($passageiro['status'] !== 'ativo') {
        response(false, null, "Conta bloqueada. Entre em contato com o suporte.", 403);
    }

    $passageiroId = (int)$passageiro['id'];
    $customerId = getOrCreateCustomerLink($db, $passageiroId, $passageiro);

    return [
        'passageiro_id' => $passageiroId,
        'customer_id'   => $customerId,
        'nome'          => $passageiro['nome'],
        'telefone'      => $passageiro['telefone'],
        'email'         => $passageiro['email'] ?? null,
        'cpf'           => $passageiro['cpf'] ?? null,
        'foto'          => $passageiro['foto'] ?? null,
        'saldo'         => (float)($passageiro['saldo'] ?? 0),
    ];
}

/**
 * Cria ou busca om_customers pelo telefone (SEM tocar boraum_passageiros).
 * Usado para JWT/API Key quando passageiro nao tem cadastro local.
 */
function getOrCreateCustomerByPhone(PDO $db, string $telefone, string $nome): int {
    $stmt = $db->prepare("SELECT customer_id FROM om_customers WHERE phone = ? LIMIT 1");
    $stmt->execute([$telefone]);
    $existing = $stmt->fetch();

    if ($existing) {
        return (int)$existing['customer_id'];
    }

    $passwordHash = password_hash('boraum_ext_' . bin2hex(random_bytes(8)), PASSWORD_ARGON2ID);
    $stmtInsert = $db->prepare("
        INSERT INTO om_customers (name, phone, password_hash, is_active, created_at)
        VALUES (?, ?, ?, 1, NOW())
    ");
    $stmtInsert->execute([$nome, $telefone, $passwordHash]);

    return (int)$db->lastInsertId();
}

/**
 * Extrai o token do header Authorization: Bearer <token>
 */
function getPassageiroToken(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if (empty($header) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

/**
 * Verifica se existe link passageiro<->customer. Se nao, cria om_customers e link.
 * Usado apenas para passageiros que JA existem em boraum_passageiros.
 */
function getOrCreateCustomerLink(PDO $db, int $passageiroId, array $passageiro): int {
    $stmt = $db->prepare("SELECT customer_id FROM om_boraum_customer_link WHERE passageiro_id = ?");
    $stmt->execute([$passageiroId]);
    $link = $stmt->fetch();

    if ($link) {
        return (int)$link['customer_id'];
    }

    $inTransaction = $db->inTransaction();
    if (!$inTransaction) $db->beginTransaction();

    try {
        $stmtExist = $db->prepare("SELECT customer_id FROM om_customers WHERE phone = ? LIMIT 1");
        $stmtExist->execute([$passageiro['telefone']]);
        $existing = $stmtExist->fetch();

        if ($existing) {
            $customerId = (int)$existing['customer_id'];
        } else {
            $passwordHash = password_hash('boraum_' . $passageiroId . '_' . bin2hex(random_bytes(8)), PASSWORD_ARGON2ID);
            $stmtInsert = $db->prepare("
                INSERT INTO om_customers (name, email, phone, cpf, password_hash, foto, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmtInsert->execute([
                $passageiro['nome'],
                $passageiro['email'] ?? null,
                $passageiro['telefone'],
                $passageiro['cpf'] ?? null,
                $passwordHash,
                $passageiro['foto'] ?? null,
            ]);
            $customerId = (int)$db->lastInsertId();
        }

        $stmtLink = $db->prepare("INSERT INTO om_boraum_customer_link (passageiro_id, customer_id, created_at) VALUES (?, ?, NOW())");
        $stmtLink->execute([$passageiroId, $customerId]);

        if (!$inTransaction) $db->commit();
        return $customerId;

    } catch (Exception $e) {
        if (!$inTransaction) $db->rollBack();
        error_log("[BoraUm Auth] Erro ao criar link customer: " . $e->getMessage());
        response(false, null, "Erro ao configurar conta. Tente novamente.", 500);
        return 0;
    }
}
