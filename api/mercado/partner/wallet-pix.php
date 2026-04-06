<?php
/**
 * GET/POST /api/mercado/partner/wallet-pix.php
 * PIX key management for partner wallet
 *
 * GET: List partner's PIX keys from om_payout_config
 * POST: Save/update PIX key {type, key}
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];

    $method = $_SERVER['REQUEST_METHOD'];

    // ======================== GET: List PIX keys ========================
    if ($method === 'GET') {
        $stmt = dbQuery($db, "
            SELECT pix_key, pix_key_type, pix_key_validated, pix_key_validated_at, updated_at
            FROM om_payout_config
            WHERE partner_id = ?
        ", [$partnerId]);
        $config = $stmt->fetch();

        $keys = [];
        if ($config && !empty($config['pix_key'])) {
            $keys[] = [
                'type' => mapPixKeyType($config['pix_key_type']),
                'key' => maskPixKey($config['pix_key'], $config['pix_key_type']),
                'key_raw_preview' => getPixKeyPreview($config['pix_key'], $config['pix_key_type']),
                'is_primary' => true,
                'validated' => (bool)($config['pix_key_validated'] ?? false),
                'validated_at' => $config['pix_key_validated_at'] ?? null,
                'updated_at' => $config['updated_at'] ?? null,
            ];
        }

        $pixKeyTypes = [
            ['value' => 'cpf', 'label' => 'CPF'],
            ['value' => 'cnpj', 'label' => 'CNPJ'],
            ['value' => 'email', 'label' => 'E-mail'],
            ['value' => 'phone', 'label' => 'Telefone'],
            ['value' => 'random', 'label' => 'Chave Aleatoria'],
        ];

        response(true, [
            'keys' => $keys,
            'pix_key_types' => $pixKeyTypes,
        ], "Chaves PIX carregadas");
    }

    // ======================== POST: Save/update PIX key ========================
    if ($method === 'POST') {
        $input = getInput();
        $type = trim($input['type'] ?? '');
        $key = trim($input['key'] ?? '');

        // Validate type
        $allowedTypes = ['cpf', 'cnpj', 'email', 'phone', 'telefone', 'random', 'aleatoria'];
        if (!in_array($type, $allowedTypes, true)) {
            response(false, null, "Tipo de chave PIX invalido", 400);
        }

        // Normalize type names
        if ($type === 'telefone') $type = 'phone';
        if ($type === 'aleatoria') $type = 'random';

        if (empty($key)) {
            response(false, null, "Chave PIX obrigatoria", 400);
        }

        // Validate format
        if (!validatePixKey($key, $type)) {
            response(false, null, "Formato da chave PIX invalido para o tipo selecionado", 400);
        }

        $key = sanitizeOutput($key);

        // Check if key changed — if so, reset validation
        $stmtOld = dbQuery($db, "SELECT pix_key, pix_key_validated FROM om_payout_config WHERE partner_id = ?", [$partnerId]);
        $oldConfig = $stmtOld->fetch();

        $pixKeyValidated = false;
        $pixKeyValidatedAt = null;
        if ($oldConfig && $oldConfig['pix_key'] === $key && $oldConfig['pix_key_validated']) {
            $pixKeyValidated = true;
            $pixKeyValidatedAt = date('Y-m-d H:i:s');
        }

        // Upsert
        $stmtUpsert = dbQuery($db, "
            INSERT INTO om_payout_config (partner_id, pix_key, pix_key_type, pix_key_validated, pix_key_validated_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON CONFLICT (partner_id) DO UPDATE SET
                pix_key = EXCLUDED.pix_key,
                pix_key_type = EXCLUDED.pix_key_type,
                pix_key_validated = EXCLUDED.pix_key_validated,
                pix_key_validated_at = EXCLUDED.pix_key_validated_at,
                updated_at = NOW()
        ", [$partnerId, $key, $type, $pixKeyValidated ? 1 : 0, $pixKeyValidatedAt]);

        response(true, [
            'type' => mapPixKeyType($type),
            'key' => maskPixKey($key, $type),
            'is_primary' => true,
            'validated' => $pixKeyValidated,
        ], "Chave PIX salva com sucesso");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/wallet-pix] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

/**
 * Map internal type to display type
 */
function mapPixKeyType(?string $type): string {
    return match ($type) {
        'cpf' => 'cpf',
        'cnpj' => 'cnpj',
        'email' => 'email',
        'phone' => 'telefone',
        'random' => 'aleatoria',
        default => $type ?? 'desconhecido',
    };
}

/**
 * Mask PIX key for display
 */
function maskPixKey(?string $key, ?string $type): string {
    if (empty($key)) return '';
    $len = strlen($key);
    if ($len <= 4) return str_repeat('*', $len);

    return match ($type) {
        'cpf' => substr(preg_replace('/\D/', '', $key), 0, 3) . '.***.***-' . substr(preg_replace('/\D/', '', $key), -2),
        'cnpj' => substr(preg_replace('/\D/', '', $key), 0, 2) . '.***.***/****-' . substr(preg_replace('/\D/', '', $key), -2),
        'email' => substr(explode('@', $key)[0], 0, 2) . '***@' . (explode('@', $key)[1] ?? '***'),
        'phone' => substr($key, 0, 4) . '****' . substr($key, -2),
        default => substr($key, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($key, -4),
    };
}

/**
 * Short preview for identification
 */
function getPixKeyPreview(?string $key, ?string $type): string {
    if (empty($key)) return '';
    return match ($type) {
        'cpf' => '***.' . substr(preg_replace('/\D/', '', $key), -5, 3) . '.' . substr(preg_replace('/\D/', '', $key), -2),
        'cnpj' => '**.' . substr(preg_replace('/\D/', '', $key), -6, 3) . '.' . substr(preg_replace('/\D/', '', $key), -2),
        'email' => substr(explode('@', $key)[0], 0, 2) . '...@' . (explode('@', $key)[1] ?? ''),
        'phone' => '(' . substr(preg_replace('/\D/', '', $key), -11, 2) . ') ****-' . substr(preg_replace('/\D/', '', $key), -4),
        default => substr($key, 0, 8) . '...',
    };
}

/**
 * Validate PIX key format
 */
function validatePixKey(string $key, string $type): bool {
    return match ($type) {
        'cpf' => (bool)preg_match('/^\d{11}$/', preg_replace('/\D/', '', $key)),
        'cnpj' => (bool)preg_match('/^\d{14}$/', preg_replace('/\D/', '', $key)),
        'email' => filter_var($key, FILTER_VALIDATE_EMAIL) !== false,
        'phone' => (bool)preg_match('/^\+?55?\d{10,11}$/', preg_replace('/\D/', '', $key)),
        'random' => (bool)preg_match('/^[a-zA-Z0-9\-]{32,36}$/', $key),
        default => true,
    };
}
