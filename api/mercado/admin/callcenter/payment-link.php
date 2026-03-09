<?php
/**
 * /api/mercado/admin/callcenter/payment-link.php
 * Payment link management for phone/WhatsApp orders
 *
 * POST action=create       — Create Stripe Checkout Session + send SMS
 * GET ?draft_id=X          — Check payment link status for a draft
 * POST action=check_status — Poll Stripe for payment status update
 */

require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';
require_once __DIR__ . '/../../helpers/callcenter-sms.php';
require_once __DIR__ . '/../../helpers/ws-callcenter-broadcast.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];

    // Get agent
    $agentStmt = $db->prepare("SELECT id, display_name FROM om_callcenter_agents WHERE admin_id = ? LIMIT 1");
    $agentStmt->execute([$adminId]);
    $agent = $agentStmt->fetch();
    $agentId = $agent ? (int)$agent['id'] : null;

    $method = $_SERVER['REQUEST_METHOD'];

    // ════════════════════════════════════════════════════════════════════
    // GET — Check payment link status for a draft
    // ════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        $draftId = (int)($_GET['draft_id'] ?? 0);
        if (!$draftId) {
            response(false, null, 'draft_id obrigatorio', 400);
        }

        $stmt = $db->prepare("
            SELECT id, draft_id, stripe_session_id, stripe_payment_link_url, amount,
                   status, customer_phone, sms_sent, paid_at, expires_at, created_at
            FROM om_callcenter_payment_links
            WHERE draft_id = ?
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$draftId]);
        $link = $stmt->fetch();

        if (!$link) {
            response(false, null, 'Nenhum link encontrado para este rascunho', 404);
        }

        $link['id'] = (int)$link['id'];
        $link['draft_id'] = (int)$link['draft_id'];
        $link['amount'] = (float)$link['amount'];
        $link['sms_sent'] = (bool)$link['sms_sent'];

        response(true, $link);
    }

    // ════════════════════════════════════════════════════════════════════
    // POST — Actions
    // ════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $input = getInput();
        $action = $input['action'] ?? '';

        // ── Create Payment Link ─────────────────────────────────────────
        if ($action === 'create') {
            $draftId = (int)($input['draft_id'] ?? 0);
            $phone = trim($input['phone'] ?? '');

            if (!$draftId) {
                response(false, null, 'draft_id obrigatorio', 400);
            }

            // Get draft details
            $draftStmt = $db->prepare("
                SELECT id, customer_name, customer_phone, partner_name, total, items, status
                FROM om_callcenter_order_drafts WHERE id = ?
            ");
            $draftStmt->execute([$draftId]);
            $draft = $draftStmt->fetch();

            if (!$draft) {
                response(false, null, 'Rascunho nao encontrado', 404);
            }
            if ($draft['status'] === 'submitted') {
                response(false, null, 'Pedido ja foi submetido', 400);
            }

            $total = (float)$draft['total'];
            if ($total <= 0) {
                response(false, null, 'Total deve ser maior que zero', 400);
            }

            $customerPhone = $phone ?: $draft['customer_phone'] ?? '';
            if (empty($customerPhone)) {
                response(false, null, 'Telefone do cliente obrigatorio', 400);
            }

            // ── Create Stripe Checkout Session ──────────────────────────
            $stripeSecretKey = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?: '';
            if (empty($stripeSecretKey)) {
                response(false, null, 'Stripe not configured', 503);
            }

            // Build line items from draft
            $items = is_string($draft['items']) ? json_decode($draft['items'], true) : ($draft['items'] ?? []);
            $lineItems = [];

            if (is_array($items) && count($items) > 0) {
                foreach ($items as $item) {
                    $itemTotal = (float)($item['price'] ?? 0);
                    // Add option prices
                    foreach (($item['options'] ?? []) as $opt) {
                        $itemTotal += (float)($opt['price'] ?? 0);
                    }

                    $lineItems[] = [
                        'price_data' => [
                            'currency' => 'brl',
                            'unit_amount' => (int)round($itemTotal * 100),
                            'product_data' => [
                                'name' => $item['name'],
                            ],
                        ],
                        'quantity' => (int)($item['quantity'] ?? 1),
                    ];
                }
            } else {
                // Single line item for total
                $lineItems[] = [
                    'price_data' => [
                        'currency' => 'brl',
                        'unit_amount' => (int)round($total * 100),
                        'product_data' => [
                            'name' => 'Pedido SuperBora' . ($draft['partner_name'] ? ' - ' . $draft['partner_name'] : ''),
                        ],
                    ],
                    'quantity' => 1,
                ];
            }

            $baseUrl = $_ENV['APP_URL'] ?? 'https://superbora.com.br';

            // Build Stripe API request
            $stripeData = http_build_query([
                'mode' => 'payment',
                'success_url' => $baseUrl . '/pagamento-confirmado?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $baseUrl . '/pagamento-cancelado',
                'expires_after_completion' => 'false',
                'metadata[draft_id]' => $draftId,
                'metadata[source]' => 'callcenter',
                'metadata[agent_id]' => $agentId ?? 0,
                'customer_email' => '',
                'payment_method_types[0]' => 'card',
            ]);

            // Add line items
            foreach ($lineItems as $i => $li) {
                $stripeData .= '&' . http_build_query([
                    "line_items[{$i}][price_data][currency]" => $li['price_data']['currency'],
                    "line_items[{$i}][price_data][unit_amount]" => $li['price_data']['unit_amount'],
                    "line_items[{$i}][price_data][product_data][name]" => $li['price_data']['product_data']['name'],
                    "line_items[{$i}][quantity]" => $li['quantity'],
                ]);
            }

            $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $stripeData,
                CURLOPT_USERPWD => $stripeSecretKey . ':',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);

            $stripeResult = curl_exec($ch);
            $stripeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                error_log("[callcenter/payment-link] Stripe cURL error: {$curlError}");
                response(false, null, 'Erro de conexao com Stripe', 502);
            }

            $stripeResponse = json_decode($stripeResult, true);

            if ($stripeHttpCode < 200 || $stripeHttpCode >= 300) {
                $errorMsg = $stripeResponse['error']['message'] ?? "HTTP {$stripeHttpCode}";
                error_log("[callcenter/payment-link] Stripe error: {$errorMsg}");
                response(false, null, 'Erro Stripe: ' . $errorMsg, 502);
            }

            $sessionId = $stripeResponse['id'] ?? '';
            $paymentUrl = $stripeResponse['url'] ?? '';

            if (empty($sessionId) || empty($paymentUrl)) {
                error_log("[callcenter/payment-link] Stripe response missing id/url");
                response(false, null, 'Resposta invalida do Stripe', 502);
            }

            // ── Store payment link ──────────────────────────────────────
            $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 minutes

            $linkStmt = $db->prepare("
                INSERT INTO om_callcenter_payment_links
                    (draft_id, stripe_session_id, stripe_payment_link_url, amount, customer_phone, expires_at)
                VALUES (?, ?, ?, ?, ?, ?)
                RETURNING id
            ");
            $linkStmt->execute([$draftId, $sessionId, $paymentUrl, $total, $customerPhone, $expiresAt]);
            $linkId = (int)$linkStmt->fetch()['id'];

            // Update draft status
            $db->prepare("
                UPDATE om_callcenter_order_drafts
                SET status = 'awaiting_payment', payment_method = 'link',
                    payment_link_url = ?, payment_link_id = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$paymentUrl, $linkId, $draftId]);

            // ── Send SMS with payment link ──────────────────────────────
            $smsResult = sendPaymentLink($customerPhone, $paymentUrl, $total);
            $smsSent = $smsResult['success'] ?? false;

            if ($smsSent) {
                $db->prepare("UPDATE om_callcenter_payment_links SET sms_sent = true WHERE id = ?")
                   ->execute([$linkId]);
            }

            ccBroadcastDashboard('payment_link_created', [
                'draft_id' => $draftId,
                'link_id' => $linkId,
                'amount' => $total,
                'phone' => $customerPhone,
            ]);

            error_log("[callcenter/payment-link] Created: link_id={$linkId} draft_id={$draftId} amount={$total} sms={$smsSent}");

            response(true, [
                'link_id' => $linkId,
                'payment_url' => $paymentUrl,
                'session_id' => $sessionId,
                'amount' => $total,
                'expires_at' => $expiresAt,
                'sms_sent' => $smsSent,
            ], 'Link de pagamento criado');
        }

        // ── Check Payment Status ────────────────────────────────────────
        if ($action === 'check_status') {
            $linkId = (int)($input['link_id'] ?? 0);
            $draftId = (int)($input['draft_id'] ?? 0);

            if (!$linkId && !$draftId) {
                response(false, null, 'link_id ou draft_id obrigatorio', 400);
            }

            // Get payment link
            if ($linkId) {
                $stmt = $db->prepare("SELECT * FROM om_callcenter_payment_links WHERE id = ?");
                $stmt->execute([$linkId]);
            } else {
                $stmt = $db->prepare("SELECT * FROM om_callcenter_payment_links WHERE draft_id = ? ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$draftId]);
            }
            $link = $stmt->fetch();

            if (!$link) {
                response(false, null, 'Link nao encontrado', 404);
            }

            // Already paid
            if ($link['status'] === 'paid') {
                response(true, [
                    'status' => 'paid',
                    'paid_at' => $link['paid_at'],
                ]);
            }

            // Check if expired locally
            if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
                $db->prepare("UPDATE om_callcenter_payment_links SET status = 'expired' WHERE id = ? AND status = 'pending'")
                   ->execute([(int)$link['id']]);
                response(true, ['status' => 'expired']);
            }

            // Poll Stripe
            $stripeSecretKey = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?: '';
            if (empty($stripeSecretKey)) {
                response(true, ['status' => $link['status'], 'message' => 'Cannot poll Stripe']);
            }

            $sessionId = $link['stripe_session_id'];
            $ch = curl_init("https://api.stripe.com/v1/checkout/sessions/{$sessionId}");
            curl_setopt_array($ch, [
                CURLOPT_USERPWD => $stripeSecretKey . ':',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                response(true, ['status' => $link['status'], 'message' => 'Stripe check failed']);
            }

            $session = json_decode($result, true);
            $paymentStatus = $session['payment_status'] ?? 'unpaid';
            $sessionStatus = $session['status'] ?? 'open';

            if ($paymentStatus === 'paid') {
                // Update to paid
                $db->prepare("
                    UPDATE om_callcenter_payment_links SET status = 'paid', paid_at = NOW() WHERE id = ?
                ")->execute([(int)$link['id']]);

                // Update draft
                $db->prepare("
                    UPDATE om_callcenter_order_drafts SET status = 'review', updated_at = NOW() WHERE id = ?
                ")->execute([(int)$link['draft_id']]);

                ccBroadcastDashboard('payment_confirmed', [
                    'link_id' => (int)$link['id'],
                    'draft_id' => (int)$link['draft_id'],
                    'amount' => (float)$link['amount'],
                ]);

                if ($agentId) {
                    ccBroadcastAgent($agentId, 'payment_confirmed', [
                        'link_id' => (int)$link['id'],
                        'draft_id' => (int)$link['draft_id'],
                    ]);
                }

                error_log("[callcenter/payment-link] Payment confirmed: link_id={$link['id']} draft_id={$link['draft_id']}");

                response(true, ['status' => 'paid', 'paid_at' => date('c')]);
            }

            if ($sessionStatus === 'expired') {
                $db->prepare("UPDATE om_callcenter_payment_links SET status = 'expired' WHERE id = ?")
                   ->execute([(int)$link['id']]);
                response(true, ['status' => 'expired']);
            }

            response(true, ['status' => $link['status'], 'payment_status' => $paymentStatus]);
        }

        response(false, null, 'Acao invalida', 400);
    }

    response(false, null, 'Method not allowed', 405);

} catch (Exception $e) {
    error_log("[callcenter/payment-link] Error: " . $e->getMessage());
    response(false, null, 'Erro interno', 500);
}
