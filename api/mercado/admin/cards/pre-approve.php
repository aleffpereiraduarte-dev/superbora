<?php
/**
 * POST /admin/cards/pre-approve.php
 *
 * Admin creates a pre-approval (offer). A card row is inserted with
 * status = 'pre_approved' and offer_expires_at = now() + offer_expires_days.
 * The customer receives a push + WhatsApp notification and must accept
 * or decline via the mobile app.
 *
 * Body:
 *   {
 *     "customer_id":        123,         // required
 *     "limit":              1500.00,     // required (> 0)
 *     "interest_rate":      12.5,        // optional (default 12.99 monthly %)
 *     "annual_fee":         0.00,        // optional (default 0)
 *     "offer_expires_days": 30,          // optional (default 30, max 90)
 *     "notes":              "..."        // optional admin note
 *   }
 *
 * Response.data:
 *   { card_id, status:'pre_approved', offer_expires_at, limit, customer: {...} }
 */

require_once __DIR__ . "/../../customer/card/_common.php";
require_once __DIR__ . "/../../helpers/notify.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

// Optional WhatsApp fallback
$waHelper = __DIR__ . "/../../helpers/twilio-whatsapp.php";
if (file_exists($waHelper)) require_once $waHelper;

setCorsHeaders();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $adminPayload = om_auth()->requireAdmin();
    $adminId = (int)($adminPayload['uid'] ?? $adminPayload['user_id'] ?? 0);
    ensureCardTables($db);

    $input = getInput();
    $customerId       = (int)($input['customer_id'] ?? 0);
    $limit            = (float)($input['limit'] ?? 0);
    $interestRate     = isset($input['interest_rate']) ? (float)$input['interest_rate'] : 12.99;
    $annualFee        = isset($input['annual_fee'])    ? (float)$input['annual_fee']    : 0.0;
    $offerDays        = (int)($input['offer_expires_days'] ?? 30);
    $notes            = trim((string)($input['notes'] ?? ''));
    $evaluationId     = isset($input['evaluation_id']) ? (int)$input['evaluation_id'] : null;

    if ($customerId <= 0) response(false, null, 'customer_id obrigatorio', 400);
    if ($limit <= 0 || $limit > 50000) response(false, null, 'limite invalido (0 < limite <= 50000)', 400);
    $offerDays = max(1, min(90, $offerDays));

    // Load customer
    $stmt = $db->prepare("SELECT customer_id, name, email, phone, cpf FROM om_customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) response(false, null, 'Cliente nao encontrado', 404);

    // Check existing non-terminal card
    $stmt = $db->prepare("
        SELECT id, status, credit_limit FROM om_credit_cards
        WHERE customer_id = ? AND status IN ('pending_approval','pre_approved','active','blocked')
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$customerId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        response(false, [
            'card_id' => (int)$existing['id'],
            'status'  => $existing['status'],
        ], 'Cliente ja possui cartao ativo ou oferta pendente', 409);
    }

    $expiresAt = (new DateTime("+{$offerDays} days"))->format('Y-m-d H:i:s');

    // Insert pre-approval — no card number until accepted
    $ins = $db->prepare("
        INSERT INTO om_credit_cards (
            customer_id, card_brand, card_number_encrypted, card_last4, cvv_encrypted,
            expires_at, credit_limit, used_limit, status, virtual,
            offer_created_at, offer_expires_at, offer_terms_interest_rate, offer_terms_annual_fee,
            offered_by_admin_id, credit_evaluation_id, offer_notes
        ) VALUES (
            ?, 'Mastercard', '', '0000', '',
            '', ?, 0, 'pre_approved', true,
            NOW(), ?, ?, ?,
            ?, ?, ?
        ) RETURNING id
    ");
    $ins->execute([
        $customerId, $limit, $expiresAt, $interestRate, $annualFee,
        $adminId ?: null, $evaluationId, $notes !== '' ? $notes : null,
    ]);
    $cardId = (int)$ins->fetchColumn();

    logCardEvent($db, $cardId, $customerId, 'pre_approved', 'admin', $adminId, [
        'limit'         => $limit,
        'interest_rate' => $interestRate,
        'annual_fee'    => $annualFee,
        'expires_at'    => $expiresAt,
    ], $notes);

    // Notify customer — push
    $title = 'Cartao SuperBora pre-aprovado!';
    $body  = sprintf('Voce tem R$ %s de limite esperando. Aceite agora no app.', number_format($limit, 2, ',', '.'));
    try {
        notifyCustomer($db, $customerId, $title, $body, '/cartao/oferta', [
            'type'      => 'card_offer',
            'card_id'   => $cardId,
            'limit'     => $limit,
            'deep_link' => '/cartao/oferta',
        ]);
    } catch (Exception $e) {
        error_log('[pre-approve notify push] ' . $e->getMessage());
    }

    // Notify customer — WhatsApp (best effort)
    try {
        if (!empty($customer['phone']) && function_exists('sendWhatsAppOrSMS')) {
            $name = explode(' ', trim((string)$customer['name']))[0] ?: 'Ola';
            $waBody = sprintf(
                "Ola %s! Voce foi pre-aprovado no cartao SuperBora com R\$ %s de limite. Abra o app para aceitar: https://superbora.com.br/app/cartao/oferta",
                $name,
                number_format($limit, 2, ',', '.')
            );
            sendWhatsAppOrSMS($customer['phone'], $waBody);
        }
    } catch (Exception $e) {
        error_log('[pre-approve notify wa] ' . $e->getMessage());
    }

    // In-app notification persistence (if table exists)
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS om_market_notifications (
                id SERIAL PRIMARY KEY,
                customer_id INTEGER NOT NULL,
                title VARCHAR(200),
                message TEXT,
                type VARCHAR(50),
                data JSONB,
                is_read BOOLEAN DEFAULT false,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
        $db->prepare("
            INSERT INTO om_market_notifications (customer_id, title, message, type, data)
            VALUES (?, ?, ?, 'card_offer', ?)
        ")->execute([
            $customerId, $title, $body,
            json_encode(['card_id' => $cardId, 'limit' => $limit, 'deep_link' => '/cartao/oferta'], JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Exception $e) {
        error_log('[pre-approve inbox] ' . $e->getMessage());
    }

    response(true, [
        'card_id'           => $cardId,
        'status'            => 'pre_approved',
        'customer_id'       => $customerId,
        'customer_name'     => $customer['name'],
        'limit'             => $limit,
        'interest_rate'     => $interestRate,
        'annual_fee'        => $annualFee,
        'offer_expires_at'  => $expiresAt,
    ]);
} catch (Exception $e) {
    error_log('[admin-cards-pre-approve] ' . $e->getMessage());
    response(false, null, 'Erro ao criar pre-aprovacao', 500);
}
