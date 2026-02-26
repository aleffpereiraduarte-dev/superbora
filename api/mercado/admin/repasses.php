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

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $stmt = $db->query("
            SELECT p.partner_id, p.name, p.email,
                   COALESCE(SUM(s.net_amount), 0) as pending_balance,
                   COUNT(s.id) as pending_orders
            FROM om_market_partners p
            INNER JOIN om_market_sales s ON p.partner_id = s.partner_id
            WHERE s.status = 'completed'
            GROUP BY p.partner_id, p.name, p.email
            HAVING COALESCE(SUM(s.net_amount), 0) > 0
            ORDER BY pending_balance DESC
        ");
        $partners = $stmt->fetchAll();

        response(true, ['repasses' => $partners], "Repasses pendentes");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        // SECURITY: Only manager/rh can process partner repasses
        $admin_role = $payload['data']['role'] ?? $payload['type'] ?? '';
        if (!in_array($admin_role, ['manager', 'rh', 'superadmin'])) {
            http_response_code(403);
            response(false, null, "Apenas manager ou RH podem processar repasses", 403);
        }

        $input = getInput();
        $partner_id = (int)($input['partner_id'] ?? 0);
        $amount = (float)($input['amount'] ?? 0);

        if (!$partner_id || $amount <= 0) response(false, null, "partner_id e amount obrigatorios", 400);

        $db->beginTransaction();

        // Lock rows with FOR UPDATE to prevent double-payment race condition
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(net_amount), 0) as pending_balance
            FROM om_market_sales
            WHERE partner_id = ? AND status = 'completed'
            FOR UPDATE
        ");
        $stmt->execute([$partner_id]);
        $pending = (float)$stmt->fetch()['pending_balance'];

        // Verify requested amount matches actual pending balance (within tolerance)
        if (abs($pending - $amount) > 0.01) {
            $db->rollBack();
            response(false, null, "Valor solicitado (R$ " . number_format($amount, 2, ',', '.') . ") nao confere com saldo pendente (R$ " . number_format($pending, 2, ',', '.') . ")", 400);
        }

        if ($pending <= 0) {
            $db->rollBack();
            response(false, null, "Nenhum saldo pendente para este parceiro", 400);
        }

        // Mark sales as paid
        $stmt = $db->prepare("
            UPDATE om_market_sales
            SET status = 'paid'
            WHERE partner_id = ? AND status = 'completed'
        ");
        $stmt->execute([$partner_id]);
        $affected = $stmt->rowCount();

        $db->commit();

        om_audit()->logPayment('partner', $partner_id, $amount);

        response(true, [
            'partner_id' => $partner_id,
            'amount' => $amount,
            'orders_paid' => $affected
        ], "Repasse processado");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log("[admin/repasses] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
