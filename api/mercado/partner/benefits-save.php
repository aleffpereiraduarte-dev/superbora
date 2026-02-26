<?php
/**
 * POST /api/mercado/partner/benefits-save.php
 * Save partner benefits configuration
 * Body: {installments_enabled, installments_max, free_shipping_min, cashback_percent, warranty_days}
 */
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requirePartner();
    $partner_id = (int)$payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        response(false, null, "Metodo nao permitido", 405);
    }

    $input = getInput();

    $installments_enabled = (int)(bool)($input['installments_enabled'] ?? false);
    $installments_max = max(1, min(12, (int)($input['installments_max'] ?? 1)));
    $free_shipping_min = max(0, (float)($input['free_shipping_min'] ?? 0));
    $cashback_percent = max(0, min(100, (float)($input['cashback_percent'] ?? 0)));
    $warranty_days = max(0, (int)($input['warranty_days'] ?? 0));

    // Validacoes
    if (!$installments_enabled) {
        $installments_max = 1;
    }

    // Buscar dados antigos para audit
    $stmtOld = $db->prepare("SELECT * FROM om_partner_benefits WHERE partner_id = ?");
    $stmtOld->execute([$partner_id]);
    $oldData = $stmtOld->fetch();

    // Upsert: INSERT ON CONFLICT
    $stmt = $db->prepare("
        INSERT INTO om_partner_benefits
            (partner_id, installments_enabled, installments_max, free_shipping_min, cashback_percent, warranty_days, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, NOW())
        ON CONFLICT (partner_id) DO UPDATE SET
            installments_enabled = EXCLUDED.installments_enabled,
            installments_max = EXCLUDED.installments_max,
            free_shipping_min = EXCLUDED.free_shipping_min,
            cashback_percent = EXCLUDED.cashback_percent,
            warranty_days = EXCLUDED.warranty_days,
            updated_at = NOW()
    ");
    $stmt->execute([$partner_id, $installments_enabled, $installments_max, $free_shipping_min, $cashback_percent, $warranty_days]);

    // Audit log
    $newData = [
        'installments_enabled' => $installments_enabled,
        'installments_max' => $installments_max,
        'free_shipping_min' => $free_shipping_min,
        'cashback_percent' => $cashback_percent,
        'warranty_days' => $warranty_days
    ];

    om_audit()->log(
        OmAudit::ACTION_UPDATE,
        'partner_benefits',
        $partner_id,
        $oldData ?: null,
        $newData,
        "Beneficios do parceiro #{$partner_id} atualizados",
        'partner',
        $partner_id
    );

    response(true, [
        "installments_enabled" => (bool)$installments_enabled,
        "installments_max" => $installments_max,
        "free_shipping_min" => $free_shipping_min,
        "cashback_percent" => $cashback_percent,
        "warranty_days" => $warranty_days
    ], "Beneficios salvos com sucesso");

} catch (Exception $e) {
    error_log("[partner/benefits-save] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
