<?php
/**
 * /api/mercado/intelligence/churn-prediction.php
 * Customer Churn Prediction & Retention Actions
 *
 * GET ?customer_id=X    - Get churn score for specific customer (admin)
 * GET ?risk=high        - List high-risk customers (admin)
 * POST { action }       - Trigger retention action for customer (admin)
 */

require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);

    // Admin auth required
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!in_array($payload['type'] ?? '', ['admin', 'partner'])) {
        response(false, null, "Acesso negado", 403);
    }

    $method = $_SERVER["REQUEST_METHOD"];

    // ── GET: churn scores ──
    if ($method === "GET") {
        $customerId = (int)($_GET['customer_id'] ?? 0);

        if ($customerId) {
            // Single customer churn analysis
            $score = getChurnScore($db, $customerId);
            response(true, ['customer' => $score]);
        }

        // List by risk level
        $risk = $_GET['risk'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = "1=1";
        $params = [];

        if ($risk && in_array($risk, ['low', 'medium', 'high', 'critical'])) {
            $where .= " AND cs.risk_level = ?";
            $params[] = $risk;
        }

        $stmt = $db->prepare("
            SELECT cs.*, c.name as customer_name, c.phone as customer_phone
            FROM om_churn_scores cs
            LEFT JOIN om_customers c ON c.customer_id = cs.customer_id
            WHERE {$where}
            ORDER BY cs.score DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $customers = $stmt->fetchAll();

        // Summary stats
        $stats = $db->query("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN risk_level = 'critical' THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN risk_level = 'high' THEN 1 ELSE 0 END) as high,
                SUM(CASE WHEN risk_level = 'medium' THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN risk_level = 'low' THEN 1 ELSE 0 END) as low,
                AVG(score) as avg_score
            FROM om_churn_scores
            WHERE updated_at > NOW() - INTERVAL '7 days'
        ")->fetch();

        response(true, [
            'customers' => $customers,
            'stats' => $stats,
            'page' => $page,
        ]);
    }

    // ── POST: trigger retention action ──
    if ($method === "POST") {
        $input = getInput();
        $customerId = (int)($input['customer_id'] ?? 0);
        $action = $input['action'] ?? '';

        if (!$customerId) response(false, null, "customer_id obrigatorio", 400);
        if (!in_array($action, ['coupon', 'push', 'email', 'whatsapp'])) {
            response(false, null, "action invalida (coupon, push, email, whatsapp)", 400);
        }

        // Get customer info
        $customer = $db->prepare("SELECT * FROM om_customers WHERE customer_id = ?")->execute([$customerId]);
        $customer = $db->prepare("SELECT * FROM om_customers WHERE customer_id = ?");
        $customer->execute([$customerId]);
        $customer = $customer->fetch();
        if (!$customer) response(false, null, "Cliente nao encontrado", 404);

        $result = ['customer_id' => $customerId, 'action' => $action];

        switch ($action) {
            case 'coupon':
                // Create personalized coupon
                $code = 'VOLTA' . strtoupper(substr(md5($customerId . time()), 0, 6));
                $discount = 15; // 15% discount
                $db->prepare("
                    INSERT INTO om_market_coupons (code, type, discount, min_order, max_uses, expires_at, created_at)
                    VALUES (?, 'percent', ?, 20, 1, NOW() + INTERVAL '7 days', NOW())
                ")->execute([$code, $discount]);
                $result['coupon_code'] = $code;
                $result['discount'] = "{$discount}%";
                break;

            case 'push':
                require_once __DIR__ . '/../helpers/NotificationSender.php';
                $sender = NotificationSender::getInstance($db);
                $sender->notifyCustomer(
                    $customerId,
                    'Sentimos sua falta! 💛',
                    'Faz tempo que voce nao pede. Que tal voltar? Temos novidades esperando por voce!',
                    ['type' => 'churn_retention']
                );
                $result['message'] = 'Push enviado';
                break;

            case 'whatsapp':
                require_once __DIR__ . '/../helpers/zapi-whatsapp.php';
                if (!empty($customer['phone'])) {
                    sendWhatsApp($customer['phone'],
                        "Oi {$customer['name']}! 🛒 Sentimos sua falta no SuperBora! " .
                        "Que tal fazer um pedido hoje? Temos ofertas especiais esperando por voce!"
                    );
                    $result['message'] = 'WhatsApp enviado';
                }
                break;

            case 'email':
                require_once __DIR__ . '/../helpers/email.php';
                if (!empty($customer['email'])) {
                    sendEmail(
                        $customer['email'],
                        'Sentimos sua falta no SuperBora!',
                        "<h2>Ola {$customer['name']}!</h2><p>Faz tempo que voce nao faz um pedido. Volte e confira nossas novidades!</p>",
                        $db, $customerId, 'churn_retention'
                    );
                    $result['message'] = 'Email enviado';
                }
                break;
        }

        // Update action taken
        $db->prepare("
            UPDATE om_churn_scores SET action_taken = ?, action_at = NOW(), updated_at = NOW()
            WHERE customer_id = ? AND action_taken = 'none'
        ")->execute([$action . '_sent', $customerId]);

        response(true, $result, "Acao de retencao executada");
    }

} catch (Exception $e) {
    error_log("[ChurnPrediction] Error: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}

/**
 * Calculate churn score for a customer based on behavior signals
 */
function getChurnScore(PDO $db, int $customerId): array {
    // Get order history
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total_orders,
            MAX(created_at) as last_order_at,
            AVG(total) as avg_ticket,
            SUM(total) as total_spent,
            MIN(created_at) as first_order_at
        FROM om_market_orders
        WHERE customer_id = ? AND status IN ('entregue', 'delivered', 'finalizado')
    ");
    $stmt->execute([$customerId]);
    $orders = $stmt->fetch();

    $totalOrders = (int)($orders['total_orders'] ?? 0);
    $lastOrderAt = $orders['last_order_at'] ?? null;
    $avgTicket = round((float)($orders['avg_ticket'] ?? 0), 2);
    $totalSpent = round((float)($orders['total_spent'] ?? 0), 2);

    // Days since last order
    $daysSinceLastOrder = $lastOrderAt
        ? max(0, (int)((time() - strtotime($lastOrderAt)) / 86400))
        : 999;

    // Order frequency (orders per 30 days)
    $firstOrderAt = $orders['first_order_at'] ?? null;
    $daysSinceFirst = $firstOrderAt
        ? max(1, (int)((time() - strtotime($firstOrderAt)) / 86400))
        : 1;
    $frequency = round(($totalOrders / $daysSinceFirst) * 30, 2);

    // Complaints
    $complaints = (int)$db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND status = 'cancelado'")
        ->execute([$customerId]) ? 0 : 0;
    $stmtC = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND status = 'cancelado'");
    $stmtC->execute([$customerId]);
    $complaints = (int)$stmtC->fetchColumn();

    // Low ratings
    $stmtR = $db->prepare("SELECT COUNT(*) FROM om_market_orders WHERE customer_id = ? AND avaliacao_cliente IS NOT NULL AND avaliacao_cliente <= 2");
    $stmtR->execute([$customerId]);
    $lowRatings = (int)$stmtR->fetchColumn();

    // ── Calculate churn score (0-100) ──
    $score = 0;

    // Days since last order (biggest signal) — 0-40 points
    if ($daysSinceLastOrder >= 90) $score += 40;
    elseif ($daysSinceLastOrder >= 60) $score += 30;
    elseif ($daysSinceLastOrder >= 30) $score += 20;
    elseif ($daysSinceLastOrder >= 14) $score += 10;
    elseif ($daysSinceLastOrder >= 7) $score += 5;

    // Low frequency — 0-25 points
    if ($frequency < 0.5) $score += 25;
    elseif ($frequency < 1.0) $score += 15;
    elseif ($frequency < 2.0) $score += 8;

    // Low order count — 0-15 points
    if ($totalOrders <= 1) $score += 15;
    elseif ($totalOrders <= 3) $score += 8;

    // Complaints — 0-10 points
    $score += min(10, $complaints * 3);

    // Low ratings — 0-10 points
    $score += min(10, $lowRatings * 5);

    $score = min(100, max(0, $score));

    // Risk level
    if ($score >= 70) $riskLevel = 'critical';
    elseif ($score >= 50) $riskLevel = 'high';
    elseif ($score >= 30) $riskLevel = 'medium';
    else $riskLevel = 'low';

    return [
        'customer_id' => $customerId,
        'score' => $score,
        'risk_level' => $riskLevel,
        'last_order_days' => $daysSinceLastOrder,
        'order_frequency' => $frequency,
        'avg_ticket' => $avgTicket,
        'total_orders' => $totalOrders,
        'total_spent' => $totalSpent,
        'complaints' => $complaints,
        'low_ratings' => $lowRatings,
    ];
}
