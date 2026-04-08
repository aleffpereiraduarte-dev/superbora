<?php
/**
 * GET /api/mercado/customer/pix-key-validate.php?key=...
 *
 * Validates a Brazilian PIX key (DICT lookup) and returns the recipient name + bank.
 *
 * Supported key types:
 *   - CPF      (11 digits, optionally with dots/dashes)
 *   - CNPJ     (14 digits, optionally formatted)
 *   - Email
 *   - Phone    (+5511..., or 11 digits, or with country code)
 *   - EVP      (random UUID v4)
 *
 * Lookup strategy:
 *   1. Validate format locally (cheap, no network)
 *   2. Call Woovi/OpenPix DICT API if WOOVI_API_KEY is configured
 *   3. Cache positive results 5min, negative 30s, in Redis
 *
 * Returns:
 *   {
 *     "valid": bool,
 *     "key": "normalized form",
 *     "key_type": "cpf|cnpj|email|phone|evp",
 *     "holder_name": "JOAO DA SILVA",   // null if lookup unavailable
 *     "holder_document": "***.***.***-90",  // masked
 *     "bank_name": "Nubank",
 *     "bank_ispb": "18236120",
 *     "lookup_source": "woovi|format_only"
 *   }
 *
 * Auth: customer JWT.
 */
require_once __DIR__ . '/../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, 'Token nao fornecido', 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || $payload['type'] !== 'customer') response(false, null, 'Nao autorizado', 401);
    $customerId = (int)$payload['uid'];

    $key = trim($_GET['key'] ?? '');
    if ($key === '') response(false, null, 'key obrigatorio', 400);
    if (mb_strlen($key) > 80) response(false, null, 'key muito longo', 400);

    // Rate limit per customer (avoid abuse)
    if (function_exists('checkRateLimit')) {
        checkRateLimit("pix_validate:{$customerId}", 30, 60);
    }

    // ─── Detect + normalize ───
    $detected = detectPixKeyType($key);
    if (!$detected['valid']) {
        response(false, null, $detected['error'] ?? 'Chave PIX invalida', 400);
    }
    $normalized = $detected['normalized'];
    $keyType = $detected['type'];

    // ─── Cache check ───
    $cacheKey = 'pix_dict_' . md5(strtolower($normalized));
    $cached = redisGet($cacheKey);
    if ($cached) {
        $cached['from_cache'] = true;
        response(true, $cached);
    }

    // ─── DICT lookup chain (cheapest first) ───
    $holderName = null;
    $bankName = null;
    $bankIspb = null;
    $isCustomer = false;
    $lookupSource = 'format_only';

    // 1) LOCAL DB: is this key a SuperBora customer? (instant, free)
    $local = localCustomerLookup($db, $normalized, $keyType);
    if ($local) {
        $holderName = $local['name'];
        $bankName = 'SuperBora';
        $isCustomer = true;
        $lookupSource = 'local_customer';
    }

    // 2) WOOVI/OpenPix DICT (if configured + not yet resolved)
    if (!$holderName) {
        $wooviKey = $_ENV['WOOVI_API_KEY'] ?? getenv('WOOVI_API_KEY') ?: '';
        if ($wooviKey) {
            $woovi = wooviPixLookup($normalized, $wooviKey);
            if ($woovi['ok']) {
                $holderName = $woovi['holder_name'];
                $bankName = $woovi['bank_name'];
                $bankIspb = $woovi['bank_ispb'];
                $lookupSource = 'woovi';
            }
        }
    }

    // 3) Banco Inter PJ DICT (free for Inter PJ correntistas)
    if (!$holderName) {
        try {
            require_once dirname(__DIR__, 3) . '/includes/classes/InterClient.php';
            if (class_exists('InterClient')) {
                $inter = new InterClient();
                if ($inter->isConfigured()) {
                    $r = $inter->lookupPixKey($normalized);
                    if (!empty($r['holder_name'])) {
                        $holderName = $r['holder_name'];
                        $bankName   = $r['bank_name'];
                        $bankIspb   = $r['bank_ispb'];
                        $lookupSource = 'inter';
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[pix-key-validate] Inter lookup error: ' . $e->getMessage());
        }
    }

    // 4) Asaas DICT (if credentials configured)
    if (!$holderName) {
        try {
            require_once dirname(__DIR__, 3) . '/includes/classes/AsaasClient.php';
            if (class_exists('AsaasClient')) {
                $asaas = new AsaasClient();
                if ($asaas->isConfigured()) {
                    $r = $asaas->lookupPixKey($normalized);
                    if (!empty($r['holder_name'])) {
                        $holderName = $r['holder_name'];
                        $bankName   = $r['bank_name'];
                        $bankIspb   = $r['bank_ispb'];
                        $lookupSource = 'asaas';
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[pix-key-validate] Asaas lookup error: ' . $e->getMessage());
        }
    }

    // 5) EFI Pay DICT (legacy fallback — does not work for most accounts since
    //    EFI Pay does not expose DICT lookup as a public product anymore)
    if (!$holderName) {
        try {
            require_once dirname(__DIR__, 3) . '/includes/classes/EfiClient.php';
            if (class_exists('EfiClient')) {
                $efi = new EfiClient();
                if ($efi->isConfigured() && method_exists($efi, 'lookupPixKey')) {
                    $efiResult = $efi->lookupPixKey($normalized);
                    if (!empty($efiResult['holder_name'])) {
                        $holderName = $efiResult['holder_name'];
                        $bankName = $efiResult['bank_name'];
                        $bankIspb = $efiResult['bank_ispb'];
                        $lookupSource = 'efi';
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[pix-key-validate] EFI lookup error: ' . $e->getMessage());
        }
    }

    $result = [
        'valid' => true,
        'key' => $normalized,
        'key_type' => $keyType,
        'holder_name' => $holderName,
        'holder_document' => maskDocument($normalized, $keyType),
        'bank_name' => $bankName,
        'bank_ispb' => $bankIspb,
        'is_customer' => $isCustomer,
        'lookup_source' => $lookupSource,
    ];

    // Cache positive 5min, format_only 30s
    redisSet($cacheKey, $result, $holderName ? 300 : 30);

    response(true, $result);

} catch (Exception $e) {
    error_log('[pix-key-validate] ' . $e->getMessage());
    response(false, null, 'Erro ao validar chave PIX', 500);
}

// ────────────────────────────────────────────────────────────────────────────

/**
 * Look up a PIX key against the local SuperBora customer base.
 * If the key matches a customer (CPF, email, phone), we already know the name.
 */
function localCustomerLookup(PDO $db, string $key, string $keyType): ?array {
    try {
        if ($keyType === 'cpf') {
            $stmt = $db->prepare("SELECT customer_id, name FROM om_customers WHERE cpf = ? AND is_active = '1' LIMIT 1");
            $stmt->execute([$key]);
        } elseif ($keyType === 'email') {
            $stmt = $db->prepare("SELECT customer_id, name FROM om_customers WHERE LOWER(email) = LOWER(?) AND is_active = '1' LIMIT 1");
            $stmt->execute([$key]);
        } elseif ($keyType === 'phone') {
            // Strip +55 prefix to compare against the stored phone
            $bare = preg_replace('/\D/', '', $key);
            if (substr($bare, 0, 2) === '55') $bare = substr($bare, 2);
            $stmt = $db->prepare("
                SELECT customer_id, name FROM om_customers
                WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), '(', ''), ')', '') LIKE ?
                  AND is_active = '1'
                LIMIT 1
            ");
            $stmt->execute(['%' . $bare]);
        } else {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function detectPixKeyType(string $key): array {
    $clean = preg_replace('/\s+/', '', $key);

    // CPF
    if (preg_match('/^\d{11}$/', preg_replace('/\D/', '', $clean))) {
        $digits = preg_replace('/\D/', '', $clean);
        if (validateCpf($digits)) {
            return ['valid' => true, 'type' => 'cpf', 'normalized' => $digits];
        }
    }

    // CNPJ
    if (preg_match('/^\d{14}$/', preg_replace('/\D/', '', $clean))) {
        $digits = preg_replace('/\D/', '', $clean);
        if (validateCnpj($digits)) {
            return ['valid' => true, 'type' => 'cnpj', 'normalized' => $digits];
        }
    }

    // Email
    if (filter_var($clean, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => true, 'type' => 'email', 'normalized' => strtolower($clean)];
    }

    // Phone — accept +55XX..., 55XX..., (XX) XXXXX-XXXX, etc
    $phoneDigits = preg_replace('/\D/', '', $clean);
    if (strlen($phoneDigits) >= 10 && strlen($phoneDigits) <= 13) {
        // Normalize to +55... format
        if (substr($phoneDigits, 0, 2) === '55' && strlen($phoneDigits) >= 12) {
            return ['valid' => true, 'type' => 'phone', 'normalized' => '+' . $phoneDigits];
        }
        if (strlen($phoneDigits) === 11 || strlen($phoneDigits) === 10) {
            return ['valid' => true, 'type' => 'phone', 'normalized' => '+55' . $phoneDigits];
        }
    }

    // EVP (UUID v4)
    if (preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?4[0-9a-f]{3}-?[89ab][0-9a-f]{3}-?[0-9a-f]{12}$/i', $clean)) {
        $normalized = strtolower(preg_replace('/[^0-9a-f]/i', '', $clean));
        $normalized = substr($normalized, 0, 8) . '-' . substr($normalized, 8, 4) . '-' .
                      substr($normalized, 12, 4) . '-' . substr($normalized, 16, 4) . '-' . substr($normalized, 20);
        return ['valid' => true, 'type' => 'evp', 'normalized' => $normalized];
    }

    return ['valid' => false, 'error' => 'Formato de chave PIX nao reconhecido'];
}

function validateCpf(string $cpf): bool {
    if (strlen($cpf) !== 11) return false;
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false; // all same digit
    for ($t = 9; $t < 11; $t++) {
        $d = 0;
        for ($c = 0; $c < $t; $c++) {
            $d += (int)$cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ((int)$cpf[$t] !== $d) return false;
    }
    return true;
}

function validateCnpj(string $cnpj): bool {
    if (strlen($cnpj) !== 14) return false;
    if (preg_match('/^(\d)\1{13}$/', $cnpj)) return false;
    $b = [6,5,4,3,2,9,8,7,6,5,4,3,2];
    for ($i = 0, $n = 0; $i < 12; $n += $cnpj[$i] * $b[++$i]);
    if ($cnpj[12] != ((($n %= 11) < 2) ? 0 : 11 - $n)) return false;
    for ($i = 0, $n = 0; $i <= 12; $n += $cnpj[$i] * $b[$i++]);
    if ($cnpj[13] != ((($n %= 11) < 2) ? 0 : 11 - $n)) return false;
    return true;
}

function maskDocument(string $key, string $type): ?string {
    if ($type === 'cpf') {
        return substr($key, 0, 3) . '.***.***-' . substr($key, -2);
    }
    if ($type === 'cnpj') {
        return substr($key, 0, 2) . '.***.***/****-' . substr($key, -2);
    }
    if ($type === 'phone') {
        $len = strlen($key);
        if ($len >= 8) return substr($key, 0, -6) . '****' . substr($key, -2);
    }
    if ($type === 'email') {
        $parts = explode('@', $key, 2);
        if (count($parts) === 2 && strlen($parts[0]) > 2) {
            return substr($parts[0], 0, 2) . '***@' . $parts[1];
        }
    }
    return null;
}

/**
 * Look up PIX key holder via Woovi (OpenPix) DICT API.
 * Endpoint: GET /api/v1/payment/key/{key}
 *
 * Note: Some Woovi tier plans don't expose this endpoint. We treat any
 * non-200 as a graceful fallback to format-only validation.
 */
function wooviPixLookup(string $key, string $apiKey): array {
    $url = 'https://api.woovi.com/api/v1/payment/key/' . urlencode($key);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $apiKey,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        return ['ok' => false];
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) return ['ok' => false];

    // Woovi response shape varies by plan; try multiple fields
    $name = $data['holder']['name']
        ?? $data['name']
        ?? $data['ownerName']
        ?? $data['payment']['holderName']
        ?? null;
    $bank = $data['bank']['name']
        ?? $data['bankName']
        ?? $data['institution']
        ?? null;
    $ispb = $data['bank']['ispb']
        ?? $data['ispb']
        ?? null;

    if (!$name) return ['ok' => false];

    return [
        'ok' => true,
        'holder_name' => $name,
        'bank_name' => $bank,
        'bank_ispb' => $ispb,
    ];
}

// ─── Tiny Redis cache helpers (graceful fallback if no Redis) ───

function redisGet(string $key) {
    try {
        if (!class_exists('Redis')) return null;
        static $r = null;
        if ($r === null) {
            $r = new Redis();
            $r->connect('127.0.0.1', 6379, 1.0);
            $pwd = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: '';
            if ($pwd) $r->auth($pwd);
        }
        $v = $r->get($key);
        return $v ? json_decode($v, true) : null;
    } catch (Exception $e) {
        return null;
    }
}

function redisSet(string $key, $value, int $ttl): void {
    try {
        if (!class_exists('Redis')) return;
        static $r = null;
        if ($r === null) {
            $r = new Redis();
            $r->connect('127.0.0.1', 6379, 1.0);
            $pwd = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: '';
            if ($pwd) $r->auth($pwd);
        }
        $r->setex($key, $ttl, json_encode($value));
    } catch (Exception $e) {
        // silent
    }
}
