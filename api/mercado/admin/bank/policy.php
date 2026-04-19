<?php
/**
 * GET  /admin/bank/policy.php  — returns current policy knobs
 * POST /admin/bank/policy.php  — update any policy keys (partial update ok)
 *
 * POST body:
 *   {
 *     "auto_approval_enabled": true,
 *     "min_score_auto_approval": 700,
 *     "max_auto_limit": 5000,
 *     ...
 *   }
 */
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../helpers/bank-brain.php";
require_once dirname(__DIR__, 4) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $admin = om_auth()->requireAdmin();
    $adminId = (int)($admin['uid'] ?? $admin['user_id'] ?? 0);

    $brain = new SuperBoraBankBrain($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        response(true, ['policy' => $brain->getPolicy()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        response(false, null, 'Metodo nao permitido', 405);
    }

    $input = getInput();
    if (!is_array($input)) response(false, null, 'Body invalido', 400);

    $policy = $brain->updatePolicy($input, $adminId);

    // Audit
    try {
        $db->prepare("
            INSERT INTO om_bank_decisions
                (customer_id, action, reasoning, created_at)
            VALUES (0, 'policy_update', ?, NOW())
        ")->execute([substr(json_encode($input, JSON_UNESCAPED_UNICODE), 0, 800)]);
    } catch (Exception $e) { /* ignore */ }

    response(true, ['policy' => $policy], 'Politica atualizada');
} catch (Exception $e) {
    error_log('[admin-bank-policy] ' . $e->getMessage());
    response(false, null, 'Erro ao atualizar politica', 500);
}
