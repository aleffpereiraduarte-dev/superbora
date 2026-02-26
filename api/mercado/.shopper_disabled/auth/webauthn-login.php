<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../../../includes/classes/OmAuth.php';

header('Content-Type: application/json');
setCorsHeaders();
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { response(false, null, 'Method not allowed', 405); }

$input = getInput();
$db = getDB();

// Step 1: Generate challenge (when email provided but no assertion)
if (!empty($input['email']) && empty($input['assertion'])) {
    $stmt = $db->prepare("SELECT s.shopper_id, s.name, s.email FROM om_market_shoppers s 
        INNER JOIN om_webauthn_credentials w ON w.user_id = s.shopper_id AND w.user_type = 'shopper'
        WHERE s.email = ?");
    $stmt->execute([$input['email']]);
    $shopper = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shopper) response(false, null, 'Biometria nao registrada para este email', 404);
    
    $stmtCreds = $db->prepare("SELECT credential_id FROM om_webauthn_credentials WHERE user_type = 'shopper' AND user_id = ?");
    $stmtCreds->execute([$shopper['shopper_id']]);
    $creds = $stmtCreds->fetchAll(PDO::FETCH_COLUMN);
    
    $challenge = base64_encode(random_bytes(32));

    // Store challenge for verification in Step 2 (expires in 2 minutes)
    $stmtChallenge = $db->prepare("INSERT INTO om_boraum_webauthn_challenges 
        (user_id, user_type, email, challenge, challenge_type, expires_at, created_at)
        VALUES (?, 'shopper', ?, ?, 'login', NOW() + INTERVAL '2 minutes', NOW())
        ON CONFLICT (user_id, user_type, challenge_type) 
        DO UPDATE SET challenge = EXCLUDED.challenge, expires_at = EXCLUDED.expires_at, created_at = NOW()");
    $stmtChallenge->execute([$shopper['shopper_id'], $input['email'], $challenge]);
    
    response(true, [
        'challenge' => $challenge,
        'allowCredentials' => array_map(fn($c) => ['type' => 'public-key', 'id' => $c], $creds),
        'userVerification' => 'preferred',
        'timeout' => 60000
    ]);
}

// Step 2: Verify assertion (credential_id AND assertion REQUIRED)
if (!empty($input['credential_id']) && !empty($input['assertion'])) {
    // Validate assertion data is present and well-formed
    $assertion = $input['assertion'];
    if (empty($assertion['authenticatorData']) || empty($assertion['signature']) || empty($assertion['clientDataJSON'])) {
        response(false, null, 'Dados de autenticacao incompletos', 400);
    }

    // Decode clientDataJSON and verify type
    $clientData = json_decode(base64_decode($assertion['clientDataJSON']), true);
    if (!$clientData || ($clientData['type'] ?? '') !== 'webauthn.get') {
        response(false, null, 'Tipo de autenticacao invalido', 400);
    }

    $stmt = $db->prepare("SELECT w.*, s.shopper_id, s.name, s.email, s.phone, s.status, s.photo 
        FROM om_webauthn_credentials w
        INNER JOIN om_market_shoppers s ON s.shopper_id = w.user_id
        WHERE w.credential_id = ? AND w.user_type = 'shopper'");
    $stmt->execute([$input['credential_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) response(false, null, 'Credencial nao encontrada', 401);
    if ($row['status'] != 1) response(false, null, 'Conta nao aprovada', 403);

    // Verify challenge from challenges table
    $stmtChal = $db->prepare("SELECT challenge, expires_at FROM om_boraum_webauthn_challenges 
        WHERE user_id = ? AND user_type = 'shopper' AND challenge_type = 'login'");
    $stmtChal->execute([$row['user_id']]);
    $chalRow = $stmtChal->fetch(PDO::FETCH_ASSOC);

    if ($chalRow) {
        // Check challenge expiry
        if (strtotime($chalRow['expires_at']) < time()) {
            response(false, null, 'Challenge expirado — tente novamente', 401);
        }
        // Verify challenge matches (clientDataJSON challenge is base64url-encoded)
        $clientChallenge = rtrim($clientData['challenge'] ?? '', '=');
        $storedChallenge = rtrim(strtr($chalRow['challenge'], '+/', '-_'), '=');
        if ($clientChallenge !== $storedChallenge) {
            error_log("[WebAuthn] Challenge mismatch for credential_id=" . $input['credential_id']);
            response(false, null, 'Challenge de autenticacao invalido', 401);
        }
        // Clear used challenge (one-time use — prevents replay attacks)
        $stmtClear = $db->prepare("DELETE FROM om_boraum_webauthn_challenges 
            WHERE user_id = ? AND user_type = 'shopper' AND challenge_type = 'login'");
        $stmtClear->execute([$row['user_id']]);
    } else {
        // No challenge found — Step 1 was never called
        error_log("[WebAuthn] No challenge found for user_id=" . $row['user_id']);
        response(false, null, 'Challenge nao encontrado — inicie o login novamente', 401);
    }

    // Verify signature using stored public key
    if (!empty($row['public_key'])) {
        $authData = base64_decode($assertion['authenticatorData']);
        $clientDataHash = hash('sha256', base64_decode($assertion['clientDataJSON']), true);
        $signedData = $authData . $clientDataHash;
        $sig = base64_decode($assertion['signature']);
        $pubKey = @openssl_pkey_get_public($row['public_key']);
        if ($pubKey) {
            $valid = openssl_verify($signedData, $sig, $pubKey, OPENSSL_ALGO_SHA256);
            if ($valid !== 1) {
                error_log("[WebAuthn] Signature verification failed for credential_id=" . $input['credential_id']);
                response(false, null, 'Assinatura biometrica invalida', 401);
            }
        } else {
            error_log("[WebAuthn] Could not load public key for credential_id=" . $input['credential_id']);
        }
    }
    
    // Update sign count
    $stmtUpdate = $db->prepare("UPDATE om_webauthn_credentials SET sign_count = sign_count + 1 WHERE credential_id = ?");
    $stmtUpdate->execute([$input['credential_id']]);
    
    // Generate token
    $auth = OmAuth::getInstance();
    $auth->setDb($db);
    $token = $auth->generateToken('shopper', $row['shopper_id'], [
        'name' => $row['name'],
        'email' => $row['email']
    ]);
    
    response(true, [
        'token' => $token,
        'shopper' => [
            'id' => $row['shopper_id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'photo' => $row['photo']
        ]
    ], 'Login biometrico realizado');
}

response(false, null, 'Dados incompletos', 400);
