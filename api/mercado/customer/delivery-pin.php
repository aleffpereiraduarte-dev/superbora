<?php
/**
 * GET/POST/DELETE /api/mercado/customer/delivery-pin.php
 * Manage customer delivery confirmation PIN (inspired by iFood)
 *
 * GET    — Returns current PIN (masked as **XX) and whether it's custom or auto-generated
 * POST   — Set a custom 4-digit PIN
 * DELETE — Reset to auto-generated (last 4 digits of phone)
 */
require_once __DIR__ . "/../config/database.php";
setCorsHeaders();

$db = getDB();
$customerId = requireCustomerAuth();

$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------------
// Helper: get the customer's phone number and current delivery_pin
// ---------------------------------------------------------------------------
function getCustomerData(PDO $db, int $customerId): ?array {
    $stmt = dbQuery($db, "SELECT phone, whatsapp, delivery_pin FROM om_market_customers WHERE customer_id = ?", [$customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ---------------------------------------------------------------------------
// Helper: derive the auto-generated PIN from phone (last 4 digits)
// ---------------------------------------------------------------------------
function autoPin(?string $phone): string {
    if (!$phone) return '0000';
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 4) return str_pad($digits, 4, '0', STR_PAD_LEFT);
    return substr($digits, -4);
}

// ---------------------------------------------------------------------------
// Helper: mask a PIN as **XX
// ---------------------------------------------------------------------------
function maskPin(string $pin): string {
    return '**' . substr($pin, -2);
}

// ---------------------------------------------------------------------------
// Validation rules for custom PIN
// ---------------------------------------------------------------------------
function validatePin(string $pin, ?string $phone): ?string {
    // Must be exactly 4 digits
    if (!preg_match('/^\d{4}$/', $pin)) {
        return 'O PIN deve conter exatamente 4 digitos numericos.';
    }

    // No fully repetitive PINs (1111, 2222, etc.)
    if (preg_match('/^(\d)\1{3}$/', $pin)) {
        return 'O PIN nao pode ser todos os digitos iguais (ex: 1111).';
    }

    // No sequential ascending (1234, 2345, 3456, etc.)
    $sequential_asc = true;
    for ($i = 1; $i < 4; $i++) {
        if ((int)$pin[$i] !== (int)$pin[$i - 1] + 1) {
            $sequential_asc = false;
            break;
        }
    }
    if ($sequential_asc) {
        return 'O PIN nao pode ser uma sequencia crescente (ex: 1234).';
    }

    // No sequential descending (4321, 9876, etc.)
    $sequential_desc = true;
    for ($i = 1; $i < 4; $i++) {
        if ((int)$pin[$i] !== (int)$pin[$i - 1] - 1) {
            $sequential_desc = false;
            break;
        }
    }
    if ($sequential_desc) {
        return 'O PIN nao pode ser uma sequencia decrescente (ex: 4321).';
    }

    // Must not match last 4 digits of phone
    if ($phone) {
        $phoneDigits = preg_replace('/\D/', '', $phone);
        if (strlen($phoneDigits) >= 4) {
            $last4 = substr($phoneDigits, -4);
            if ($pin === $last4) {
                return 'O PIN nao pode ser igual aos ultimos 4 digitos do seu telefone.';
            }
        }
    }

    return null; // valid
}

// ===========================================================================
// GET — Return current PIN status
// ===========================================================================
if ($method === 'GET') {
    $customer = getCustomerData($db, $customerId);
    if (!$customer) {
        response(false, null, 'Cliente nao encontrado.', 404);
    }

    $phone = $customer['phone'] ?: $customer['whatsapp'];
    $customPin = $customer['delivery_pin'];
    $isCustom = !empty($customPin);

    // The effective PIN: custom if set, otherwise auto-generated from phone
    $effectivePin = $isCustom ? $customPin : autoPin($phone);

    response(true, [
        'pin_masked'  => maskPin($effectivePin),
        'pin'         => $effectivePin,
        'is_custom'   => $isCustom,
        'auto_pin'    => autoPin($phone),
    ]);
}

// ===========================================================================
// POST — Set a custom PIN
// ===========================================================================
if ($method === 'POST') {
    $input = getInput();
    $pin = trim($input['pin'] ?? '');

    $customer = getCustomerData($db, $customerId);
    if (!$customer) {
        response(false, null, 'Cliente nao encontrado.', 404);
    }

    $phone = $customer['phone'] ?: $customer['whatsapp'];

    // Validate
    $error = validatePin($pin, $phone);
    if ($error) {
        response(false, null, $error, 422);
    }

    // Save
    $stmt = dbQuery($db, "UPDATE om_market_customers SET delivery_pin = ?, updated_at = NOW() WHERE customer_id = ?", [$pin, $customerId]);

    response(true, [
        'pin_masked'  => maskPin($pin),
        'pin'         => $pin,
        'is_custom'   => true,
    ], 'PIN de entrega atualizado com sucesso.');
}

// ===========================================================================
// DELETE — Reset to auto-generated
// ===========================================================================
if ($method === 'DELETE') {
    $customer = getCustomerData($db, $customerId);
    if (!$customer) {
        response(false, null, 'Cliente nao encontrado.', 404);
    }

    // Clear custom PIN (NULL means auto-generated)
    $stmt = dbQuery($db, "UPDATE om_market_customers SET delivery_pin = NULL, updated_at = NOW() WHERE customer_id = ?", [$customerId]);

    $phone = $customer['phone'] ?: $customer['whatsapp'];
    $auto = autoPin($phone);

    response(true, [
        'pin_masked'  => maskPin($auto),
        'pin'         => $auto,
        'is_custom'   => false,
        'auto_pin'    => $auto,
    ], 'PIN resetado para padrao (ultimos 4 digitos do telefone).');
}

// If we got here with an unsupported method
response(false, null, 'Metodo nao suportado.', 405);
