<?php
/**
 * POST /api/mercado/auth/social-login.php
 * Unified social login endpoint — Google, Facebook, Apple
 *
 * Body: { provider: 'google'|'facebook'|'apple', token: '...', name?: '...', email?: '...', extra?: {} }
 *
 * - Verifies token with respective provider
 * - Finds existing user by provider ID or email → login
 * - If not found → auto-register
 * - Returns JWT token (same format as regular login)
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, "Metodo nao permitido", 405);
}

try {
    $input = getInput();
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Rate limit: 10 attempts per minute per IP
    require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
    if (!RateLimiter::check(10, 60)) {
        response(false, null, "Muitas tentativas. Aguarde um momento.", 429);
    }

    $provider = strtolower(trim($input['provider'] ?? ''));
    $token = trim($input['token'] ?? '');
    $clientName = trim($input['name'] ?? '');
    $clientEmail = trim($input['email'] ?? '');
    $extra = $input['extra'] ?? [];

    if (empty($provider) || empty($token)) {
        response(false, null, "Provider e token obrigatorios", 400);
    }

    if (!in_array($provider, ['google', 'facebook', 'apple'], true)) {
        response(false, null, "Provider invalido. Use: google, facebook, apple", 400);
    }

    // ──── Verify token with provider ────
    $verifiedUser = null;

    switch ($provider) {
        case 'google':
            $verifiedUser = verifyGoogleToken($token);
            break;
        case 'facebook':
            $verifiedUser = verifyFacebookToken($token);
            break;
        case 'apple':
            $verifiedUser = verifyAppleToken($token, $extra);
            break;
    }

    if (!$verifiedUser) {
        response(false, null, "Token invalido ou expirado para {$provider}", 401);
    }

    $providerUserId = $verifiedUser['provider_id'];
    $verifiedEmail = $verifiedUser['email'];
    $verifiedName = $verifiedUser['name'] ?: $clientName;
    $verifiedPicture = $verifiedUser['picture'] ?? '';

    if (empty($verifiedEmail)) {
        // For Apple: email may be hidden — use placeholder
        if ($provider === 'apple') {
            $verifiedEmail = "apple_{$providerUserId}@apple.superbora.com";
        } else {
            response(false, null, "Email nao disponivel na conta {$provider}", 400);
        }
    }

    // ──── Find or create customer (inside transaction) ────
    $db->beginTransaction();

    try {
        $customer = null;
        $isNew = false;

        // 1. Try to find by provider-specific ID column
        $providerIdColumn = getProviderIdColumn($provider);
        if ($providerIdColumn) {
            $stmt = $db->prepare("
                SELECT customer_id, name, email, phone, cpf, foto, is_active
                FROM om_customers
                WHERE {$providerIdColumn} = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$providerUserId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 2. If not found by provider ID, try by email
        if (!$customer) {
            $stmt = $db->prepare("
                SELECT customer_id, name, email, phone, cpf, foto, is_active
                FROM om_customers
                WHERE LOWER(email) = LOWER(?)
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$verifiedEmail]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            // Link provider ID to existing account
            if ($customer && $providerIdColumn) {
                $stmtLink = $db->prepare("UPDATE om_customers SET {$providerIdColumn} = ? WHERE customer_id = ?");
                $stmtLink->execute([$providerUserId, $customer['customer_id']]);
            }
        }

        if ($customer) {
            // ──── Existing user — login ────
            if (!$customer['is_active']) {
                $db->rollBack();
                response(false, null, "Conta desativada. Entre em contato com o suporte.", 403);
            }

            // Update photo if missing
            if (empty($customer['foto']) && !empty($verifiedPicture)) {
                $stmtFoto = $db->prepare("UPDATE om_customers SET foto = ? WHERE customer_id = ?");
                $stmtFoto->execute([$verifiedPicture, $customer['customer_id']]);
                $customer['foto'] = $verifiedPicture;
            }

            // Update last_login
            $stmtLogin = $db->prepare("UPDATE om_customers SET last_login = NOW() WHERE customer_id = ?");
            $stmtLogin->execute([$customer['customer_id']]);

        } else {
            // ──── New user — auto-register ────
            $isNew = true;
            $insertColumns = ['name', 'email', 'password_hash', 'is_active', 'is_verified', 'created_at', 'updated_at'];
            $insertValues = [$verifiedName ?: 'Usuario ' . ucfirst($provider), $verifiedEmail, '', 1, 1, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')];
            $placeholders = array_fill(0, count($insertColumns), '?');

            // Add provider ID column
            if ($providerIdColumn) {
                $insertColumns[] = $providerIdColumn;
                $insertValues[] = $providerUserId;
                $placeholders[] = '?';
            }

            // Add photo if available
            if (!empty($verifiedPicture)) {
                $insertColumns[] = 'foto';
                $insertValues[] = $verifiedPicture;
                $placeholders[] = '?';
            }

            $columnsSql = implode(', ', $insertColumns);
            $placeholdersSql = implode(', ', $placeholders);

            $stmt = $db->prepare("
                INSERT INTO om_customers ({$columnsSql})
                VALUES ({$placeholdersSql})
                RETURNING customer_id
            ");
            $stmt->execute($insertValues);
            $newId = (int)$stmt->fetchColumn();

            // Fetch the newly created customer
            $stmt = $db->prepare("
                SELECT customer_id, name, email, phone, cpf, foto, is_active
                FROM om_customers
                WHERE customer_id = ?
            ");
            $stmt->execute([$newId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $db->commit();

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    // ──── Generate JWT token ────
    $customerId = (int)$customer['customer_id'];
    $token = om_auth()->generateToken('customer', $customerId, [
        'name' => $customer['name'],
        'email' => $customer['email'],
    ]);

    response(true, [
        'token' => $token,
        'customer' => [
            'id' => $customerId,
            'nome' => $customer['name'],
            'email' => $customer['email'],
            'telefone' => $customer['phone'] ?? null,
            'cpf' => $customer['cpf'] ?? null,
            'foto' => $customer['foto'] ?? null,
            'avatar' => $customer['foto'] ?? null,
        ],
        'is_new' => $isNew,
        'provider' => $provider,
    ], $isNew ? "Conta criada com sucesso!" : "Login realizado com sucesso!", $isNew ? 201 : 200);

} catch (Exception $e) {
    $providerName = $provider ?? 'unknown';
    error_log("[API Auth SocialLogin] Erro ({$providerName}): " . $e->getMessage());
    response(false, null, "Erro ao autenticar com " . ucfirst($providerName), 500);
}

// ════════════════════════════════════════════════════════════
// Token verification functions
// ════════════════════════════════════════════════════════════

/**
 * Returns the database column name for each provider's user ID
 */
function getProviderIdColumn(string $provider): ?string {
    return match ($provider) {
        'google' => 'google_id',
        'facebook' => 'facebook_id',
        default => null,
    };
}

/**
 * Verify Google ID Token via Google's tokeninfo API
 */
function verifyGoogleToken(string $idToken): ?array {
    $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID');
    if (!$clientId || $clientId === 'CHANGE_ME') {
        error_log("[SocialLogin] GOOGLE_CLIENT_ID nao configurado");
        return null;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        error_log("[SocialLogin] Google token invalido (HTTP $httpCode)");
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['sub']) || empty($data['email'])) {
        error_log("[SocialLogin] Google resposta invalida");
        return null;
    }

    // Verify issuer
    $allowedIssuers = ['accounts.google.com', 'https://accounts.google.com'];
    if (!in_array($data['iss'] ?? '', $allowedIssuers, true)) {
        error_log("[SocialLogin] Google issuer invalido: " . ($data['iss'] ?? ''));
        return null;
    }

    // Verify audience
    if (($data['aud'] ?? '') !== $clientId) {
        error_log("[SocialLogin] Google client ID nao confere");
        return null;
    }

    // Email must be verified
    if (($data['email_verified'] ?? 'false') !== 'true' && $data['email_verified'] !== true) {
        error_log("[SocialLogin] Google email nao verificado");
        return null;
    }

    return [
        'provider_id' => $data['sub'],
        'email' => $data['email'],
        'name' => $data['name'] ?? '',
        'picture' => $data['picture'] ?? '',
    ];
}

/**
 * Verify Facebook Access Token via Graph API debug_token + user info
 */
function verifyFacebookToken(string $accessToken): ?array {
    $appId = $_ENV['FACEBOOK_APP_ID'] ?? getenv('FACEBOOK_APP_ID');
    $appSecret = $_ENV['FACEBOOK_APP_SECRET'] ?? getenv('FACEBOOK_APP_SECRET');

    if (!$appId || !$appSecret || $appId === 'CHANGE_ME') {
        error_log("[SocialLogin] FACEBOOK_APP_ID ou FACEBOOK_APP_SECRET nao configurado");
        return null;
    }

    // Step 1: Verify token is valid and belongs to our app
    $appToken = $appId . '|' . $appSecret;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://graph.facebook.com/debug_token?input_token=' . urlencode($accessToken) . '&access_token=' . urlencode($appToken),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $debugResponse = curl_exec($ch);
    $debugHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($debugHttpCode !== 200 || empty($debugResponse)) {
        error_log("[SocialLogin] Facebook debug_token falhou (HTTP $debugHttpCode)");
        return null;
    }

    $debugData = json_decode($debugResponse, true);
    $tokenData = $debugData['data'] ?? null;

    if (!$tokenData || !($tokenData['is_valid'] ?? false)) {
        error_log("[SocialLogin] Facebook token invalido");
        return null;
    }

    // Verify app_id matches
    if (($tokenData['app_id'] ?? '') !== $appId) {
        error_log("[SocialLogin] Facebook app_id nao confere");
        return null;
    }

    // Check token is not expired
    if (isset($tokenData['expires_at']) && $tokenData['expires_at'] > 0 && $tokenData['expires_at'] < time()) {
        error_log("[SocialLogin] Facebook token expirado");
        return null;
    }

    $facebookUserId = $tokenData['user_id'] ?? '';
    if (empty($facebookUserId)) {
        error_log("[SocialLogin] Facebook user_id ausente");
        return null;
    }

    // Step 2: Fetch user profile info
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://graph.facebook.com/v19.0/me?fields=id,name,email,picture.width(200).height(200)&access_token=' . urlencode($accessToken),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $userResponse = curl_exec($ch);
    $userHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($userHttpCode !== 200 || empty($userResponse)) {
        error_log("[SocialLogin] Facebook me API falhou (HTTP $userHttpCode)");
        return null;
    }

    $userData = json_decode($userResponse, true);
    if (!$userData || empty($userData['id'])) {
        error_log("[SocialLogin] Facebook user data invalida");
        return null;
    }

    // Double-check user ID matches debug_token
    if ($userData['id'] !== $facebookUserId) {
        error_log("[SocialLogin] Facebook user_id mismatch");
        return null;
    }

    $pictureUrl = $userData['picture']['data']['url'] ?? '';

    return [
        'provider_id' => $userData['id'],
        'email' => $userData['email'] ?? '',
        'name' => $userData['name'] ?? '',
        'picture' => $pictureUrl,
    ];
}

/**
 * Verify Apple identityToken (JWT) using Apple JWKS
 */
function verifyAppleToken(string $identityToken, array $extra = []): ?array {
    $parts = explode('.', $identityToken);
    if (count($parts) !== 3) {
        error_log("[SocialLogin] Apple token formato invalido");
        return null;
    }

    $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

    if (!$payload || !$header) {
        error_log("[SocialLogin] Apple token payload invalido");
        return null;
    }

    // Validate issuer
    if (($payload['iss'] ?? '') !== 'https://appleid.apple.com') {
        error_log("[SocialLogin] Apple issuer invalido");
        return null;
    }

    // Validate audience (bundle ID)
    $expectedAud = $_ENV['APPLE_BUNDLE_ID'] ?? 'com.superbora.mercado';
    if (($payload['aud'] ?? '') !== $expectedAud) {
        error_log("[SocialLogin] Apple aud invalido: " . ($payload['aud'] ?? ''));
        return null;
    }

    // Validate expiration
    if (($payload['exp'] ?? 0) < time()) {
        error_log("[SocialLogin] Apple token expirado");
        return null;
    }

    // Verify JWT signature using Apple JWKS
    $kid = $header['kid'] ?? '';
    if (!$kid) {
        error_log("[SocialLogin] Apple JWT sem kid");
        return null;
    }

    // Fetch JWKS (with APCu cache)
    $jwksJson = false;
    $cacheKey = 'apple_jwks_v1';
    if (function_exists('apcu_fetch') && apcu_enabled()) {
        $jwksJson = apcu_fetch($cacheKey);
    }
    if (!$jwksJson) {
        $ctx = stream_context_create(['http' => ['timeout' => 10], 'ssl' => ['verify_peer' => true]]);
        $jwksJson = @file_get_contents('https://appleid.apple.com/auth/keys', false, $ctx);
        if (!$jwksJson) {
            usleep(500000);
            $jwksJson = @file_get_contents('https://appleid.apple.com/auth/keys', false, $ctx);
        }
        if ($jwksJson && function_exists('apcu_store') && apcu_enabled()) {
            apcu_store($cacheKey, $jwksJson, 3600);
        }
    }

    if (!$jwksJson) {
        error_log("[SocialLogin] Nao foi possivel buscar Apple JWKS");
        return null;
    }

    $jwks = json_decode($jwksJson, true);
    $signatureVerified = false;

    foreach (($jwks['keys'] ?? []) as $jwk) {
        if (($jwk['kid'] ?? '') !== $kid) continue;
        $pem = appleJwkToPem($jwk);
        if (!$pem) break;

        $dataToVerify = $parts[0] . '.' . $parts[1];
        $sig = base64_decode(strtr($parts[2], '-_', '+/'));
        $pubKey = @openssl_pkey_get_public($pem);
        if ($pubKey && openssl_verify($dataToVerify, $sig, $pubKey, OPENSSL_ALGO_SHA256) === 1) {
            $signatureVerified = true;
        }
        break;
    }

    if (!$signatureVerified) {
        error_log("[SocialLogin] Apple JWT signature verification falhou");
        return null;
    }

    $appleSub = $payload['sub'] ?? '';
    if (empty($appleSub)) {
        error_log("[SocialLogin] Apple token sem sub");
        return null;
    }

    return [
        'provider_id' => $appleSub,
        'email' => $payload['email'] ?? ($extra['email'] ?? ''),
        'name' => $extra['fullName'] ?? '',
        'picture' => '',
    ];
}

/**
 * Convert Apple JWK (RSA) to PEM format
 */
function appleJwkToPem(array $jwk): ?string {
    if (($jwk['kty'] ?? '') !== 'RSA') return null;
    $n = base64_decode(strtr($jwk['n'], '-_', '+/'));
    $e = base64_decode(strtr($jwk['e'], '-_', '+/'));
    if ($n === false || $e === false) return null;
    if (ord($n[0]) > 0x7f) $n = "\x00" . $n;
    if (ord($e[0]) > 0x7f) $e = "\x00" . $e;
    $nDer = "\x02" . appleAsn1Len(strlen($n)) . $n;
    $eDer = "\x02" . appleAsn1Len(strlen($e)) . $e;
    $seq = $nDer . $eDer;
    $rsaSeq = "\x30" . appleAsn1Len(strlen($seq)) . $seq;
    $bitString = "\x00" . $rsaSeq;
    $rsaOid = hex2bin('300d06092a864886f70d0101010500');
    $pubKeyBody = $rsaOid . "\x03" . appleAsn1Len(strlen($bitString)) . $bitString;
    $outer = "\x30" . appleAsn1Len(strlen($pubKeyBody)) . $pubKeyBody;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($outer), 64, "\n") . "-----END PUBLIC KEY-----";
}

function appleAsn1Len(int $len): string {
    if ($len < 0x80) return chr($len);
    if ($len < 0x100) return "\x81" . chr($len);
    return "\x82" . pack('n', $len);
}
