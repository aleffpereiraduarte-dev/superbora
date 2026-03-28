<?php
/**
 * POST /api/mercado/pedido/reclamacao.php — Customer reports issue after delivery
 * GET  /api/mercado/pedido/reclamacao.php?order_id=456 — List complaints for order
 * GET  /api/mercado/pedido/reclamacao.php — List all customer complaints
 *
 * Validates order ownership, delivery status, 48h window, duplicate prevention.
 * Auto-applies penalties based on om_store_penalty_rules.
 * Auto-refunds via cashback when applicable.
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/notify.php";
require_once __DIR__ . "/../helpers/cashback.php";
require_once dirname(__DIR__, 2) . "/rate-limit/RateLimiter.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";

setCorsHeaders();

header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") exit;

// Rate limiting: 5 complaints per minute
if (!RateLimiter::check(5, 60)) {
    exit;
}

try {
    $db = getDB();

    // Customer auth
    OmAuth::getInstance()->setDb($db);
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, "Autenticacao necessaria", 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || ($payload['type'] ?? '') !== 'customer') response(false, null, "Token invalido", 401);
    $customerId = (int)$payload['uid'];

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        handleGetComplaints($db, $customerId);
    } elseif ($method === 'POST') {
        handlePostComplaint($db, $customerId);
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[reclamacao] DB error: " . $e->getMessage());
    response(false, null, "Erro interno. Tente novamente.", 500);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("[reclamacao] Error: " . $e->getMessage());
    response(false, null, "Erro ao processar reclamacao. Tente novamente.", 500);
}

/* ===================== GET ===================== */

function handleGetComplaints(PDO $db, int $customerId): void {
    $orderId = (int)($_GET['order_id'] ?? 0);

    if ($orderId) {
        // Verify customer owns the order
        $stmtOwner = $db->prepare("SELECT customer_id FROM om_market_orders WHERE order_id = ?");
        $stmtOwner->execute([$orderId]);
        $owner = $stmtOwner->fetch();
        if (!$owner || (int)$owner['customer_id'] !== $customerId) {
            response(false, null, "Pedido nao encontrado", 404);
        }

        $stmt = $db->prepare("
            SELECT sp.id, sp.order_id, sp.category, sp.severity, sp.title, sp.description,
                   sp.photos, sp.order_total, sp.penalty_amount, sp.refund_to_customer,
                   sp.status, sp.store_response, sp.store_disputed, sp.resolution,
                   sp.created_at, sp.updated_at,
                   r.label as category_label
            FROM om_store_penalties sp
            LEFT JOIN om_store_penalty_rules r ON r.category = sp.category
            WHERE sp.order_id = ? AND sp.reported_by_id = ? AND sp.reported_by IN ('customer', 'system')
            ORDER BY sp.created_at DESC
        ");
        $stmt->execute([$orderId, $customerId]);
    } else {
        // All complaints by this customer
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $stmt = $db->prepare("
            SELECT sp.id, sp.order_id, sp.category, sp.severity, sp.title, sp.description,
                   sp.photos, sp.order_total, sp.penalty_amount, sp.refund_to_customer,
                   sp.status, sp.store_response, sp.store_disputed, sp.resolution,
                   sp.created_at, sp.updated_at,
                   o.order_number, o.partner_id,
                   p.trade_name as partner_name,
                   r.label as category_label
            FROM om_store_penalties sp
            LEFT JOIN om_market_orders o ON o.order_id = sp.order_id
            LEFT JOIN om_market_partners p ON p.partner_id = sp.partner_id
            LEFT JOIN om_store_penalty_rules r ON r.category = sp.category
            WHERE sp.reported_by_id = ? AND sp.reported_by IN ('customer', 'system')
            ORDER BY sp.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$customerId, $limit, $offset]);
    }

    $complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode photos JSON for each complaint
    foreach ($complaints as &$c) {
        $c['photos'] = json_decode($c['photos'] ?? '[]', true) ?: [];
        $c['penalty_amount'] = (float)$c['penalty_amount'];
        $c['refund_to_customer'] = (float)$c['refund_to_customer'];
        $c['order_total'] = (float)$c['order_total'];
    }
    unset($c);

    response(true, ['complaints' => $complaints]);
}

/* ===================== POST ===================== */

function handlePostComplaint(PDO $db, int $customerId): void {
    $input = getInput();

    $orderId = (int)($input['order_id'] ?? 0);
    $category = trim($input['category'] ?? '');
    $description = strip_tags(trim(substr($input['description'] ?? '', 0, 2000)));
    $photos = $input['photos'] ?? [];
    $itemsAffected = $input['items_affected'] ?? [];
    $wantsRefund = (bool)($input['wants_refund'] ?? false);

    // Validate required fields
    if (!$orderId) {
        response(false, null, "order_id e obrigatorio", 400);
    }

    $allowedCategories = [
        'wrong_items', 'missing_items', 'bad_quality', 'late_preparation',
        'wrong_order', 'expired_food', 'unhygienic', 'other'
    ];
    if (!in_array($category, $allowedCategories, true)) {
        response(false, null, "Categoria invalida", 400);
    }

    if (empty($description) || strlen($description) < 5) {
        response(false, null, "Descreva o problema com pelo menos 5 caracteres", 400);
    }

    // Sanitize
    $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

    // Validate photos array (max 5, must be strings)
    if (!is_array($photos)) $photos = [];
    $photos = array_slice($photos, 0, 5);
    $photos = array_map(function($p) {
        return is_string($p) ? substr($p, 0, 500000) : ''; // max ~500KB base64 per photo
    }, $photos);
    $photos = array_filter($photos);

    // Validate items_affected
    if (!is_array($itemsAffected)) $itemsAffected = [];
    $itemsAffected = array_slice($itemsAffected, 0, 50);
    $cleanItems = [];
    foreach ($itemsAffected as $item) {
        if (is_array($item)) {
            $cleanItems[] = [
                'item_id' => (int)($item['item_id'] ?? 0),
                'name' => strip_tags(substr($item['name'] ?? '', 0, 200)),
                'issue' => strip_tags(substr($item['issue'] ?? '', 0, 100)),
            ];
        }
    }

    $db->beginTransaction();

    // 1. Validate: order exists, belongs to customer, status is 'entregue', within 48 hours
    $stmtOrder = $db->prepare("
        SELECT o.order_id, o.customer_id, o.partner_id, o.status, o.subtotal, o.total,
               o.order_number, o.updated_at, o.payment_method,
               p.trade_name as partner_name
        FROM om_market_orders o
        LEFT JOIN om_market_partners p ON p.partner_id = o.partner_id
        WHERE o.order_id = ?
        FOR UPDATE
    ");
    $stmtOrder->execute([$orderId]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order || (int)$order['customer_id'] !== $customerId) {
        $db->rollBack();
        response(false, null, "Pedido nao encontrado", 404);
    }

    if (!in_array($order['status'], ['entregue', 'delivered', 'finalizado', 'retirado'], true)) {
        $db->rollBack();
        response(false, null, "So e possivel reclamar de pedidos ja entregues", 400);
    }

    // Check 48-hour window
    $deliveredAt = strtotime($order['updated_at']);
    if ($deliveredAt && $deliveredAt < strtotime('-48 hours')) {
        $db->rollBack();
        response(false, null, "O prazo para reclamacao expirou (48 horas apos entrega)", 400);
    }

    // 2. Check for duplicate complaint on same order+category
    $stmtDup = $db->prepare("
        SELECT id FROM om_store_penalties
        WHERE order_id = ? AND category = ? AND reported_by = 'customer' AND reported_by_id = ?
        LIMIT 1
    ");
    $stmtDup->execute([$orderId, $category, $customerId]);
    if ($stmtDup->fetch()) {
        $db->rollBack();
        response(false, null, "Voce ja registrou uma reclamacao desta categoria para este pedido", 409);
    }

    // 3. Load penalty rules
    $stmtRule = $db->prepare("
        SELECT * FROM om_store_penalty_rules
        WHERE category = ? AND active = true
        LIMIT 1
    ");
    $stmtRule->execute([$category]);
    $rule = $stmtRule->fetch(PDO::FETCH_ASSOC);

    // 4. Calculate penalty amount
    $orderSubtotal = (float)($order['subtotal'] ?: $order['total'] ?: 0);
    $penaltyAmount = 0;
    $penaltyType = 'fixed';
    $severity = 'medium';

    if ($rule) {
        $penaltyType = $rule['penalty_type'] ?? 'fixed';
        switch ($penaltyType) {
            case 'full_order':
                $penaltyAmount = $orderSubtotal;
                $severity = 'high';
                break;
            case 'percent':
                $percent = (float)($rule['default_penalty_percent'] ?? 0);
                $penaltyAmount = round($orderSubtotal * $percent / 100, 2);
                $severity = $percent >= 80 ? 'high' : ($percent >= 40 ? 'medium' : 'low');
                break;
            case 'fixed':
                $penaltyAmount = (float)($rule['default_penalty_fixed'] ?? 0);
                $severity = $penaltyAmount >= 20 ? 'high' : ($penaltyAmount >= 5 ? 'medium' : 'low');
                break;
        }
    }

    $title = ($rule['label'] ?? ucfirst(str_replace('_', ' ', $category)))
        . ' - Pedido #' . ($order['order_number'] ?? $orderId);

    $autoApply = $rule && (bool)($rule['auto_apply'] ?? false);
    $refundCustomer = $rule && (bool)($rule['refund_customer'] ?? false);
    $status = $autoApply ? 'confirmed' : 'opened';
    $refundAmount = ($refundCustomer && $wantsRefund) ? $penaltyAmount : 0;

    // Build extended description with items
    $fullDescription = $description;
    if (!empty($cleanItems)) {
        $fullDescription .= "\n\nItens afetados:";
        foreach ($cleanItems as $item) {
            $fullDescription .= "\n- " . ($item['name'] ?: 'Item #' . $item['item_id']) . " (" . ($item['issue'] ?: 'problema') . ")";
        }
    }

    // 5. Create penalty entry
    $stmtInsert = $db->prepare("
        INSERT INTO om_store_penalties
            (order_id, partner_id, reported_by, reported_by_id, category, severity, title,
             description, photos, order_total, penalty_amount, refund_to_customer, status)
        VALUES (?, ?, 'customer', ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?)
    ");
    $stmtInsert->execute([
        $orderId,
        (int)$order['partner_id'],
        $customerId,
        $category,
        $severity,
        $title,
        $fullDescription,
        json_encode($photos, JSON_UNESCAPED_UNICODE),
        $orderSubtotal,
        $penaltyAmount,
        $refundAmount,
        $status,
    ]);
    $penaltyId = (int)$db->lastInsertId('om_store_penalties_id_seq');

    // 7. Auto-refund via cashback if rule says auto_apply + refund_customer
    $refundResult = null;
    if ($refundCustomer && $wantsRefund && $refundAmount > 0 && $autoApply) {
        // Credit cashback as refund
        $refundDescription = "Reembolso: " . ($rule['label'] ?? $category) . " - Pedido #" . ($order['order_number'] ?? $orderId);

        // Ensure wallet exists
        $db->prepare("
            INSERT INTO om_cashback_wallet (customer_id, balance, total_earned)
            VALUES (?, ?, ?)
            ON CONFLICT (customer_id) DO UPDATE SET
                balance = om_cashback_wallet.balance + EXCLUDED.balance,
                total_earned = om_cashback_wallet.total_earned + EXCLUDED.total_earned
        ")->execute([$customerId, $refundAmount, $refundAmount]);

        // Create cashback transaction
        $expiresAt = date('Y-m-d', strtotime('+90 days'));
        $db->prepare("
            INSERT INTO om_cashback_transactions
                (customer_id, order_id, partner_id, type, amount, balance_after, description, expires_at)
            VALUES (?, ?, ?, 'credit', ?, 0, ?, ?)
        ")->execute([
            $customerId,
            $orderId,
            (int)$order['partner_id'],
            $refundAmount,
            $refundDescription,
            $expiresAt,
        ]);

        // Get updated balance
        $stmtBal = $db->prepare("SELECT balance FROM om_cashback_wallet WHERE customer_id = ?");
        $stmtBal->execute([$customerId]);
        $newBalance = (float)$stmtBal->fetchColumn();

        // Update balance_after on the transaction
        $db->prepare("
            UPDATE om_cashback_transactions
            SET balance_after = ?
            WHERE customer_id = ? AND order_id = ? AND type = 'credit' AND description LIKE 'Reembolso:%'
            ORDER BY id DESC LIMIT 1
        ")->execute([$newBalance, $customerId, $orderId]);

        $refundResult = [
            'refunded' => true,
            'amount' => $refundAmount,
            'amount_formatted' => 'R$ ' . number_format($refundAmount, 2, ',', '.'),
            'method' => 'cashback',
            'new_balance' => $newBalance,
        ];
    }

    $db->commit();

    // 8. Notify store (outside transaction — non-critical)
    try {
        $partnerTitle = 'Reclamacao recebida - Pedido #' . ($order['order_number'] ?? $orderId);
        $partnerBody = ($rule['label'] ?? $category) . ': ' . substr(strip_tags($description), 0, 200);
        notifyPartner($db, (int)$order['partner_id'], $partnerTitle, $partnerBody, '/painel/mercado/pedidos.php', [
            'type' => 'complaint',
            'order_id' => $orderId,
            'penalty_id' => $penaltyId,
            'category' => $category,
        ]);
    } catch (\Throwable $e) {
        error_log("[reclamacao] Failed to notify partner: " . $e->getMessage());
    }

    // 9. Notify admin via DB notification
    try {
        require_once __DIR__ . '/../config/notify.php';
        $adminBody = "Cliente #$customerId reclamou do pedido #{$order['order_number']} ({$order['partner_name']}): "
            . ($rule['label'] ?? $category) . " - " . substr(strip_tags($description), 0, 150);
        sendNotification($db, 1, 'admin', 'Nova reclamacao de cliente', $adminBody, [
            'type' => 'complaint',
            'order_id' => $orderId,
            'penalty_id' => $penaltyId,
            'category' => $category,
            'customer_id' => $customerId,
        ]);
    } catch (\Throwable $e) {
        error_log("[reclamacao] Failed to notify admin: " . $e->getMessage());
    }

    // 10. Build response
    $responseData = [
        'complaint_id' => $penaltyId,
        'order_id' => $orderId,
        'category' => $category,
        'category_label' => $rule['label'] ?? ucfirst(str_replace('_', ' ', $category)),
        'severity' => $severity,
        'status' => $status,
        'penalty_amount' => $penaltyAmount,
        'penalty_amount_formatted' => 'R$ ' . number_format($penaltyAmount, 2, ',', '.'),
        'auto_applied' => $autoApply,
    ];

    if ($refundResult) {
        $responseData['refund'] = $refundResult;
    }

    $statusMessage = $autoApply
        ? 'Reclamacao registrada e confirmada automaticamente!'
        : 'Reclamacao registrada! Vamos analisar e resolver em ate 24h.';

    if ($refundResult) {
        $statusMessage .= ' Voce recebera R$ ' . number_format($refundAmount, 2, ',', '.') . ' de cashback em instantes!';
    }

    error_log("[reclamacao] Complaint #{$penaltyId} created: order={$orderId} category={$category} penalty={$penaltyAmount} refund={$refundAmount} auto={$autoApply}");

    response(true, $responseData, $statusMessage);
}
