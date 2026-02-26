<?php
/**
 * POST /api/mercado/auth/apple.php
 * Apple Sign-In - valida identityToken e cria/retorna usuario
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

/**
 * Convert JWK (RSA) to PEM format for openssl_verify
 */
function jwkToPem(array $jwk): ?string {
    if (($jwk['kty'] ?? '') !== 'RSA') return null;
    $n = base64_decode(strtr($jwk['n'], '-_', '+/'));
    $e = base64_decode(strtr($jwk['e'], '-_', '+/'));
    if ($n === false || $e === false) return null;
    // Ensure leading zero byte for positive ASN.1 integers
    if (ord($n[0]) > 0x7f) $n = "\x00" . $n;
    if (ord($e[0]) > 0x7f) $e = "\x00" . $e;
    // DER encode RSA public key
    $nDer = "\x02" . asn1Length(strlen($n)) . $n;
    $eDer = "\x02" . asn1Length(strlen($e)) . $e;
    $seq = $nDer . $eDer;
    $rsaSeq = "\x30" . asn1Length(strlen($seq)) . $seq;
    $bitString = "\x00" . $rsaSeq;
    // RSA OID: 1.2.840.113549.1.1.1 + NULL
    $rsaOid = hex2bin('300d06092a864886f70d0101010500');
    $pubKeyBody = $rsaOid . "\x03" . asn1Length(strlen($bitString)) . $bitString;
    $outer = "\x30" . asn1Length(strlen($pubKeyBody)) . $pubKeyBody;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($outer), 64, "\n") . "-----END PUBLIC KEY-----";
}

function asn1Length(int $len): string {
    if ($len < 0x80) return chr($len);
    if ($len < 0x100) return "\x81" . chr($len);
    return "\x82" . pack('n', $len);
}

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Rate limit: 10 attempts per 15 minutes
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    checkRateLimit("apple_login:{$ip}", 10, 900);

    $input = json_decode(file_get_contents('php://input'), true);

    $identityToken = $input['identityToken'] ?? '';
    $fullName = $input['fullName'] ?? null; // {givenName, familyName}
    $email = $input['email'] ?? '';
    $appleUserId = $input['user'] ?? ''; // Apple user ID

    if (!$identityToken || !$appleUserId) {
        response(false, null, 'identityToken e user obrigatorios', 400);
    }

    // Decode and VERIFY Apple JWT (identityToken)
    $parts = explode('.', $identityToken);
    if (count($parts) !== 3) {
        response(false, null, 'Token invalido', 400);
    }

    $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (!$payload || !$header) {
        response(false, null, 'Token payload invalido', 400);
    }

    // Validate issuer
    if (($payload['iss'] ?? '') !== 'https://appleid.apple.com') {
        response(false, null, 'Token issuer invalido', 400);
    }

    // Validate audience (MUST be our app bundle ID — prevents cross-app token reuse)
    $expectedAud = $_ENV['APPLE_BUNDLE_ID'] ?? 'com.superbora.app';
    if (($payload['aud'] ?? '') !== $expectedAud) {
        error_log("[AppleAuth] Invalid aud: " . ($payload['aud'] ?? 'missing') . " expected: $expectedAud");
        response(false, null, 'Token audience invalido', 400);
    }

    // Validate expiration
    if (($payload['exp'] ?? 0) < time()) {
        response(false, null, 'Token expirado', 400);
    }

    // Verify JWT signature using Apple JWKS
    $kid = $header['kid'] ?? '';
    $signatureVerified = false;
    if ($kid) {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $jwksJson = @file_get_contents('https://appleid.apple.com/auth/keys', false, $ctx);
        if ($jwksJson) {
            $jwks = json_decode($jwksJson, true);
            foreach (($jwks['keys'] ?? []) as $jwk) {
                if (($jwk['kid'] ?? '') !== $kid) continue;
                $pem = jwkToPem($jwk);
                if (!$pem) {
                    error_log("[AppleAuth] Failed to convert JWK to PEM for kid=$kid");
                    break;
                }
                $dataToVerify = $parts[0] . '.' . $parts[1];
                $sig = base64_decode(strtr($parts[2], '-_', '+/'));
                $pubKey = @openssl_pkey_get_public($pem);
                if ($pubKey && openssl_verify($dataToVerify, $sig, $pubKey, OPENSSL_ALGO_SHA256) === 1) {
                    $signatureVerified = true;
                }
                break;
            }
            if (!$signatureVerified) {
                error_log("[AppleAuth] JWT signature verification FAILED for kid=$kid");
                response(false, null, 'Assinatura do token invalida', 401);
            }
        } else {
            // If Apple JWKS is unreachable, log but still reject — do not allow unverified tokens
            error_log("[AppleAuth] Could not fetch Apple JWKS — rejecting login");
            response(false, null, 'Nao foi possivel verificar o token', 503);
        }
    } else {
        error_log("[AppleAuth] JWT header missing kid — rejecting token");
        response(false, null, 'Token invalido (sem kid)', 400);
    }

    // SECURITY: Only trust email from verified JWT payload, NEVER client-provided email
    $appleEmail = $payload['email'] ?? null;
    $appleSub = $payload['sub'] ?? '';

    if (empty($appleSub)) {
        response(false, null, 'Token sem subject identifier', 400);
    }

    // Internal email placeholder for accounts without real email
    $internalEmail = "apple_{$appleSub}@apple.superbora.com";

    // Buscar usuario existente pelo Apple internal email OR verified JWT email
    $searchEmails = [$internalEmail];
    if ($appleEmail) {
        $searchEmails[] = $appleEmail;
    }

    $placeholders = implode(',', array_fill(0, count($searchEmails), '?'));
    $stmt = $db->prepare("SELECT customer_id, name, email, phone FROM om_customers
        WHERE email IN ($placeholders) LIMIT 1");
    $stmt->execute($searchEmails);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Login existente
        $customerId = (int)$existing['customer_id'];
    } else {
        // Criar nova conta
        $name = 'Usuario Apple';
        if ($fullName) {
            $nameParts = array_filter([$fullName['givenName'] ?? '', $fullName['familyName'] ?? '']);
            if ($nameParts) $name = implode(' ', $nameParts);
        }

        $finalEmail = $appleEmail ?: $internalEmail;

        $stmt = $db->prepare("INSERT INTO om_customers (name, email, is_active, is_verified, created_at, updated_at)
            VALUES (?, ?, 1, 1, NOW(), NOW()) RETURNING customer_id");
        $stmt->execute([$name, $finalEmail]);
        $customerId = (int)$stmt->fetchColumn();
    }

    // Gerar JWT via singleton
    $token = om_auth()->generateToken('customer', $customerId, [
        'name' => $existing['name'] ?? $name ?? 'Usuario Apple',
        'email' => $existing['email'] ?? $finalEmail ?? $internalEmail,
    ]);

    // Buscar dados atualizados
    $stmt = $db->prepare("SELECT customer_id, name, email, phone, cpf FROM om_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    response(true, [
        'token' => $token,
        'customer' => [
            'id' => (int)$customer['customer_id'],
            'name' => $customer['name'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
        ],
        'is_new' => !$existing,
    ]);

} catch (Exception $e) {
    error_log("[AppleAuth] " . $e->getMessage());
    response(false, null, 'Erro no login Apple', 500);
}
