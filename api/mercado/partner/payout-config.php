<?php
/**
 * GET/POST /api/mercado/partner/payout-config.php
 * Configuracao de Repasses
 *
 * GET: Ver configuracao atual
 * POST: Atualizar configuracao de repasse
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";
require_once dirname(__DIR__, 3) . "/includes/classes/WooviClient.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partnerId = (int)$payload['uid'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmtConfig = $db->prepare("
            SELECT
                payout_frequency,
                payout_day,
                min_payout,
                bank_name,
                bank_agency,
                bank_account,
                bank_account_type,
                pix_key,
                pix_key_type,
                pix_key_validated,
                pix_key_validated_at,
                auto_payout,
                updated_at
            FROM om_payout_config
            WHERE partner_id = ?
        ");
        $stmtConfig->execute([$partnerId]);
        $config = $stmtConfig->fetch();

        if (!$config) {
            $config = [
                'payout_frequency' => 'weekly',
                'payout_day' => 5,
                'min_payout' => 50.00,
                'bank_name' => null,
                'bank_agency' => null,
                'bank_account' => null,
                'bank_account_type' => 'checking',
                'pix_key' => null,
                'pix_key_type' => null,
                'pix_key_validated' => false,
                'pix_key_validated_at' => null,
                'auto_payout' => true,
                'updated_at' => null,
            ];
        }

        // Calcular proxima data de repasse
        $nextPayoutDate = calculateNextPayoutDate(
            $config['payout_frequency'],
            (int)$config['payout_day']
        );

        response(true, [
            'config' => [
                'payout_frequency' => $config['payout_frequency'],
                'payout_day' => (int)$config['payout_day'],
                'min_payout' => round((float)$config['min_payout'], 2),
                'bank_name' => $config['bank_name'],
                'bank_agency' => $config['bank_agency'],
                'bank_account' => $config['bank_account'],
                'bank_account_type' => $config['bank_account_type'],
                'pix_key' => maskPixKey($config['pix_key'], $config['pix_key_type']),
                'pix_key_type' => $config['pix_key_type'],
                'pix_key_validated' => (bool)($config['pix_key_validated'] ?? false),
                'pix_key_validated_at' => $config['pix_key_validated_at'] ?? null,
                'auto_payout' => (bool)$config['auto_payout'],
                'updated_at' => $config['updated_at'],
            ],
            'next_payout_date' => $nextPayoutDate,
            'frequency_options' => [
                ['value' => 'daily', 'label' => 'Diario'],
                ['value' => 'weekly', 'label' => 'Semanal'],
                ['value' => 'biweekly', 'label' => 'Quinzenal'],
                ['value' => 'monthly', 'label' => 'Mensal'],
            ],
            'day_options' => getDayOptions($config['payout_frequency']),
            'pix_key_types' => [
                ['value' => 'cpf', 'label' => 'CPF'],
                ['value' => 'cnpj', 'label' => 'CNPJ'],
                ['value' => 'email', 'label' => 'E-mail'],
                ['value' => 'phone', 'label' => 'Telefone'],
                ['value' => 'random', 'label' => 'Chave Aleatoria'],
            ],
        ], "Configuracao de repasse carregada");
    }

    if ($method === 'POST') {
        $input = getInput();

        // Validar frequencia
        $frequency = $input['payout_frequency'] ?? 'weekly';
        if (!in_array($frequency, ['daily', 'weekly', 'biweekly', 'monthly'])) {
            $frequency = 'weekly';
        }

        // Validar dia
        $payoutDay = (int)($input['payout_day'] ?? 1);
        if ($frequency === 'weekly' || $frequency === 'biweekly') {
            $payoutDay = max(1, min(7, $payoutDay)); // 1-7 (Segunda a Domingo)
        } elseif ($frequency === 'monthly') {
            $payoutDay = max(1, min(28, $payoutDay)); // 1-28
        } else {
            $payoutDay = 1; // daily nao usa
        }

        // Validar valor minimo
        $minPayout = max(10, (float)($input['min_payout'] ?? 50));

        // Dados bancarios
        $bankName = isset($input['bank_name']) ? sanitizeOutput($input['bank_name']) : null;
        $bankAgency = isset($input['bank_agency']) ? preg_replace('/[^0-9\-]/', '', $input['bank_agency']) : null;
        $bankAccount = isset($input['bank_account']) ? preg_replace('/[^0-9\-]/', '', $input['bank_account']) : null;
        $bankAccountType = in_array($input['bank_account_type'] ?? '', ['checking', 'savings'])
            ? $input['bank_account_type']
            : 'checking';

        // PIX
        $pixKey = isset($input['pix_key']) ? sanitizeOutput($input['pix_key']) : null;
        $pixKeyType = in_array($input['pix_key_type'] ?? '', ['cpf', 'cnpj', 'email', 'phone', 'random'])
            ? $input['pix_key_type']
            : null;

        // Validar formato da chave PIX
        if ($pixKey && $pixKeyType) {
            if (!validatePixKey($pixKey, $pixKeyType)) {
                response(false, null, "Chave PIX invalida para o tipo selecionado", 400);
            }
        }

        // Verificar se chave PIX mudou - precisa revalidar
        $pixKeyValidated = false;
        $pixKeyValidatedAt = null;

        if ($pixKey && $pixKeyType) {
            // Checar se e a mesma chave ja validada
            $stmtOld = $db->prepare("SELECT pix_key, pix_key_validated FROM om_payout_config WHERE partner_id = ?");
            $stmtOld->execute([$partnerId]);
            $oldConfig = $stmtOld->fetch();

            if ($oldConfig && $oldConfig['pix_key'] === $pixKey && $oldConfig['pix_key_validated']) {
                // Same key, already validated — keep validation status
                $pixKeyValidated = true;
                $pixKeyValidatedAt = date('Y-m-d H:i:s');
            } else {
                // New or changed PIX key — NOT validated until confirmed by Woovi API payout
                $pixKeyValidated = false;
                $pixKeyValidatedAt = null;
            }
        }

        $autoPayout = isset($input['auto_payout']) ? (bool)$input['auto_payout'] : true;

        // Upsert configuracao
        $stmtUpsert = $db->prepare("
            INSERT INTO om_payout_config (
                partner_id, payout_frequency, payout_day, min_payout,
                bank_name, bank_agency, bank_account, bank_account_type,
                pix_key, pix_key_type, pix_key_validated, pix_key_validated_at, auto_payout
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (partner_id) DO UPDATE SET
                payout_frequency = EXCLUDED.payout_frequency,
                payout_day = EXCLUDED.payout_day,
                min_payout = EXCLUDED.min_payout,
                bank_name = EXCLUDED.bank_name,
                bank_agency = EXCLUDED.bank_agency,
                bank_account = EXCLUDED.bank_account,
                bank_account_type = EXCLUDED.bank_account_type,
                pix_key = EXCLUDED.pix_key,
                pix_key_type = EXCLUDED.pix_key_type,
                pix_key_validated = EXCLUDED.pix_key_validated,
                pix_key_validated_at = EXCLUDED.pix_key_validated_at,
                auto_payout = EXCLUDED.auto_payout,
                updated_at = NOW()
        ");
        $stmtUpsert->execute([
            $partnerId,
            $frequency,
            $payoutDay,
            $minPayout,
            $bankName,
            $bankAgency,
            $bankAccount,
            $bankAccountType,
            $pixKey,
            $pixKeyType,
            $pixKeyValidated ? 1 : 0,
            $pixKeyValidatedAt,
            $autoPayout ? 1 : 0,
        ]);

        // Registrar auditoria
        om_audit()->log('payout_config', 'update', [
            'frequency' => $frequency,
            'payout_day' => $payoutDay,
            'min_payout' => $minPayout,
            'has_bank' => !empty($bankName),
            'has_pix' => !empty($pixKey),
        ], $partnerId);

        $nextPayoutDate = calculateNextPayoutDate($frequency, $payoutDay);

        response(true, [
            'config' => [
                'payout_frequency' => $frequency,
                'payout_day' => $payoutDay,
                'min_payout' => $minPayout,
                'bank_name' => $bankName,
                'bank_agency' => $bankAgency,
                'bank_account' => $bankAccount,
                'bank_account_type' => $bankAccountType,
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'pix_key_validated' => $pixKeyValidated,
                'auto_payout' => $autoPayout,
            ],
            'next_payout_date' => $nextPayoutDate,
        ], "Configuracao de repasse atualizada");
    }

    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[partner/payout-config] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

function calculateNextPayoutDate($frequency, $day) {
    $today = new DateTime();

    switch ($frequency) {
        case 'daily':
            return $today->modify('+1 day')->format('Y-m-d');

        case 'weekly':
            $currentDayOfWeek = (int)$today->format('N'); // 1 (Mon) to 7 (Sun)
            $daysUntil = ($day - $currentDayOfWeek + 7) % 7;
            if ($daysUntil === 0) $daysUntil = 7; // Proximo semana se hoje e o dia
            return $today->modify("+{$daysUntil} days")->format('Y-m-d');

        case 'biweekly':
            $currentDayOfWeek = (int)$today->format('N');
            $daysUntil = ($day - $currentDayOfWeek + 14) % 14;
            if ($daysUntil === 0) $daysUntil = 14;
            return $today->modify("+{$daysUntil} days")->format('Y-m-d');

        case 'monthly':
            $currentDay = (int)$today->format('j');
            if ($currentDay < $day) {
                return $today->setDate((int)$today->format('Y'), (int)$today->format('n'), $day)->format('Y-m-d');
            } else {
                return $today->modify('first day of next month')
                             ->setDate((int)$today->format('Y'), (int)$today->format('n'), min($day, 28))
                             ->format('Y-m-d');
            }

        default:
            return $today->modify('+7 days')->format('Y-m-d');
    }
}

function getDayOptions($frequency) {
    if ($frequency === 'daily') {
        return [];
    }

    if ($frequency === 'weekly' || $frequency === 'biweekly') {
        return [
            ['value' => 1, 'label' => 'Segunda-feira'],
            ['value' => 2, 'label' => 'Terca-feira'],
            ['value' => 3, 'label' => 'Quarta-feira'],
            ['value' => 4, 'label' => 'Quinta-feira'],
            ['value' => 5, 'label' => 'Sexta-feira'],
            ['value' => 6, 'label' => 'Sabado'],
            ['value' => 7, 'label' => 'Domingo'],
        ];
    }

    // Monthly
    $options = [];
    for ($i = 1; $i <= 28; $i++) {
        $options[] = ['value' => $i, 'label' => "Dia $i"];
    }
    return $options;
}

function maskPixKey($key, $type) {
    if (empty($key)) return null;
    $len = strlen($key);
    if ($len <= 4) return str_repeat('*', $len);
    switch ($type) {
        case 'cpf':
            $clean = preg_replace('/\D/', '', $key);
            return substr($clean, 0, 3) . '.***.***-' . substr($clean, -2);
        case 'cnpj':
            $clean = preg_replace('/\D/', '', $key);
            return substr($clean, 0, 2) . '.***.***/****-' . substr($clean, -2);
        case 'email':
            $parts = explode('@', $key);
            return substr($parts[0], 0, 2) . '***@' . ($parts[1] ?? '***');
        case 'phone':
            return substr($key, 0, 4) . '****' . substr($key, -2);
        default:
            return substr($key, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($key, -4);
    }
}

function validatePixKey($key, $type) {
    switch ($type) {
        case 'cpf':
            return preg_match('/^\d{11}$/', preg_replace('/\D/', '', $key));
        case 'cnpj':
            return preg_match('/^\d{14}$/', preg_replace('/\D/', '', $key));
        case 'email':
            return filter_var($key, FILTER_VALIDATE_EMAIL) !== false;
        case 'phone':
            return preg_match('/^\+?55?\d{10,11}$/', preg_replace('/\D/', '', $key));
        case 'random':
            return preg_match('/^[a-zA-Z0-9\-]{32,36}$/', $key);
        default:
            return true;
    }
}
