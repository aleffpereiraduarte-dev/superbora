<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $admin_id = $payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] !== "POST") response(false, null, "Metodo nao permitido", 405);

    $input = getInput();
    $shopper_id = (int)($input['shopper_id'] ?? 0);
    $action = $input['action'] ?? '';
    $reason = trim($input['reason'] ?? '');

    if (!$shopper_id) response(false, null, "shopper_id obrigatorio", 400);

    $valid_actions = ['approve', 'reject', 'suspend', 'reactivate'];
    if (!in_array($action, $valid_actions)) {
        response(false, null, "action invalida. Use: approve, reject, suspend, reactivate", 400);
    }

    // Get current status
    $stmt = $db->prepare("SELECT status, name FROM om_market_shoppers WHERE shopper_id = ?");
    $stmt->execute([$shopper_id]);
    $shopper = $stmt->fetch();
    if (!$shopper) response(false, null, "Shopper nao encontrado", 404);

    $old_status = (int)$shopper['status'];

    // Status: 0=pending, 1=approved, 2=rejected, 3=suspended
    $status_map = [
        'approve' => 1,
        'reject' => 2,
        'suspend' => 3,
        'reactivate' => 1
    ];
    $new_status = $status_map[$action];

    // Update shopper
    $stmt = $db->prepare("
        UPDATE om_market_shoppers
        SET status = ?,
            motivo_rejeicao = ?,
            data_aprovacao = CASE WHEN ? IN (1) THEN NOW() ELSE data_aprovacao END,
            aprovado_por = CASE WHEN ? IN (1) THEN ? ELSE aprovado_por END
        WHERE shopper_id = ?
    ");
    $stmt->execute([
        $new_status,
        in_array($action, ['reject', 'suspend']) ? $reason : null,
        $new_status,
        $new_status,
        $admin_id,
        $shopper_id
    ]);

    // Audit log
    om_audit()->log(
        "shopper_{$action}",
        'shopper',
        $shopper_id,
        ['status' => $old_status],
        ['status' => $new_status, 'reason' => $reason],
        "Shopper '{$shopper['name']}' - acao: {$action}"
    );

    response(true, [
        'shopper_id' => $shopper_id,
        'action' => $action,
        'old_status' => $old_status,
        'new_status' => $new_status
    ], "Acao executada: {$action}");
} catch (Exception $e) {
    error_log("[admin/shopper-actions] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
