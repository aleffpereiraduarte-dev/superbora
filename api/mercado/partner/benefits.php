<?php
/**
 * GET /api/mercado/partner/benefits.php
 * Get partner benefits configuration
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

    $stmt = $db->prepare("
        SELECT
            id,
            installments_enabled,
            installments_max,
            free_shipping_min,
            cashback_percent,
            warranty_days,
            updated_at
        FROM om_partner_benefits
        WHERE partner_id = ?
    ");
    $stmt->execute([$partner_id]);
    $benefits = $stmt->fetch();

    if ($benefits) {
        $data = [
            "id" => (int)$benefits['id'],
            "installments_enabled" => (bool)$benefits['installments_enabled'],
            "installments_max" => (int)$benefits['installments_max'],
            "free_shipping_min" => (float)$benefits['free_shipping_min'],
            "cashback_percent" => (float)$benefits['cashback_percent'],
            "warranty_days" => (int)$benefits['warranty_days'],
            "updated_at" => $benefits['updated_at']
        ];
    } else {
        // Retornar defaults
        $data = [
            "id" => null,
            "installments_enabled" => false,
            "installments_max" => 1,
            "free_shipping_min" => 0,
            "cashback_percent" => 0,
            "warranty_days" => 0,
            "updated_at" => null
        ];
    }

    response(true, $data, "Beneficios carregados");

} catch (Exception $e) {
    error_log("[partner/benefits] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
