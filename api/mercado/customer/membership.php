<?php
/**
 * /api/mercado/customer/membership.php
 * SuperBora Club Membership — Full management endpoint
 *
 * GET                  — Current status + all plans
 * GET action=benefits  — Check specific benefit for customer
 * POST action=subscribe       — Subscribe (Stripe subscription)
 * POST action=cancel          — Cancel at period end
 * POST action=change_plan     — Upgrade/downgrade
 * POST action=reactivate      — Reactivate cancelled subscription
 */
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../helpers/rate-limit.php";
setCorsHeaders();

try {
    $db = getDB();
    $method = $_SERVER['REQUEST_METHOD'];

    // ─── Helper: Load Stripe secret key ─────────────────────────
    function getStripeSecret(): string {
        $envFile = dirname(dirname(__DIR__)) . '/.env.stripe';
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                if (trim($k) === 'STRIPE_SECRET_KEY') return trim($v);
            }
        }
        return getenv('STRIPE_SECRET_KEY') ?: ($_ENV['STRIPE_SECRET_KEY'] ?? '');
    }

    // ─── Helper: Stripe API call ────────────────────────────────
    function stripeRequest(string $method, string $endpoint, array $params = []): array {
        $sk = getStripeSecret();
        if (empty($sk)) {
            error_log("[membership] STRIPE_SECRET_KEY not configured");
            return ['error' => 'Stripe not configured'];
        }

        $url = "https://api.stripe.com/v1/{$endpoint}";
        $ch = curl_init();

        if ($method === 'GET') {
            if ($params) $url .= '?' . http_build_query($params);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $sk . ':',
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($result, true) ?: [];
        if ($httpCode >= 400) {
            error_log("[membership] Stripe error {$httpCode}: " . ($data['error']['message'] ?? $result));
            $data['_http_code'] = $httpCode;
        }
        return $data;
    }

    // ─── Helper: Get or create Stripe customer ──────────────────
    function getOrCreateStripeCustomer(PDO $db, int $customerId): string {
        $stmt = $db->prepare("SELECT stripe_customer_id, name, email, phone FROM om_customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $cust = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cust) return '';

        if (!empty($cust['stripe_customer_id'])) {
            return $cust['stripe_customer_id'];
        }

        // Create Stripe customer
        $result = stripeRequest('POST', 'customers', [
            'email' => $cust['email'] ?? '',
            'name' => $cust['name'] ?? 'Cliente SuperBora',
            'phone' => $cust['phone'] ?? '',
            'metadata[superbora_customer_id]' => $customerId,
        ]);

        if (!empty($result['id'])) {
            $db->prepare("UPDATE om_customers SET stripe_customer_id = ? WHERE customer_id = ?")
                ->execute([$result['id'], $customerId]);
            return $result['id'];
        }

        return '';
    }

    // ─── Helper: Load all active plans ──────────────────────────
    function loadPlans(PDO $db): array {
        $stmt = $db->query("
            SELECT plan_id, name, slug, price, icon, color, sort_order,
                   free_delivery_min, cashback_rate, priority_support,
                   express_discount, express_free, no_service_fee,
                   early_access_offers, exclusive_coupons, benefits_json,
                   trial_days, stripe_price_id
            FROM om_membership_plans
            WHERE status::text IN ('1','active')
            ORDER BY sort_order ASC
        ");
        $plans = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $benefits = [];
            if ($p['benefits_json']) {
                $decoded = json_decode($p['benefits_json'], true);
                if (is_array($decoded)) $benefits = $decoded;
            }
            $plans[] = [
                'id' => $p['slug'],
                'plan_id' => (int)$p['plan_id'],
                'name' => $p['name'],
                'slug' => $p['slug'],
                'price' => (float)$p['price'],
                'icon' => $p['icon'] ?? 'diamond-outline',
                'color' => $p['color'] ?? '#f59e0b',
                'gradient' => match($p['slug']) {
                    'club' => ['#00A868', '#00c278', '#00D68F'],
                    'club_plus' => ['#fbbf24', '#f59e0b', '#d97706'],
                    'club_black' => ['#7c3aed', '#6d28d9', '#4c1d95'],
                    default => ['#00A868', '#00D68F'],
                },
                'recommended' => $p['slug'] === 'club_plus',
                'benefits' => $benefits,
                'free_delivery_min' => $p['free_delivery_min'] !== null ? (float)$p['free_delivery_min'] : null,
                'cashback_rate' => (float)$p['cashback_rate'],
                'priority_support' => $p['priority_support'] ?? 'normal',
                'express_discount' => (float)$p['express_discount'],
                'express_free' => (bool)$p['express_free'],
                'no_service_fee' => (bool)$p['no_service_fee'],
                'trial_days' => (int)$p['trial_days'],
                'stripe_price_id' => $p['stripe_price_id'] ?? null,
            ];
        }
        return $plans;
    }

    // ─── Helper: Get active membership ──────────────────────────
    function getActiveMembership(PDO $db, int $customerId): ?array {
        // Also check superbora_plus for legacy members
        $stmt = $db->prepare("
            SELECT m.*, p.name as plan_name, p.price as plan_price, p.icon as plan_icon,
                   p.color as plan_color, p.cashback_rate, p.free_delivery_min,
                   p.priority_support, p.express_discount, p.express_free,
                   p.no_service_fee, p.benefits_json, p.slug as plan_slug_from_plan
            FROM om_customer_memberships m
            LEFT JOIN om_membership_plans p ON p.slug = m.plan
            WHERE m.customer_id = ?
            AND m.status IN ('active', 'trialing')
            AND (m.current_period_end > NOW() OR m.expires_at > NOW())
            ORDER BY m.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$customerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ─── Helper: Get membership savings this month ──────────────
    function getMonthlySavings(PDO $db, int $customerId): array {
        $savings = ['delivery' => 0, 'cashback' => 0, 'service_fee' => 0, 'total' => 0];
        try {
            // Cashback earned this month
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) FROM om_cashback
                WHERE customer_id = ? AND type = 'earned' AND status IN ('available','used')
                AND created_at >= DATE_TRUNC('month', CURRENT_DATE)
            ");
            $stmt->execute([$customerId]);
            $savings['cashback'] = round((float)$stmt->fetchColumn(), 2);

            // Count orders this month for estimated savings
            $stmt = $db->prepare("
                SELECT COUNT(*), COALESCE(SUM(delivery_fee), 0)
                FROM om_market_orders
                WHERE customer_id = ?
                AND date_added >= DATE_TRUNC('month', CURRENT_DATE)
                AND status IN ('entregue','confirmado','em_entrega','coletando','aceito','preparando')
            ");
            $stmt->execute([$customerId]);
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $orderCount = (int)$row[0];
            $totalDeliveryPaid = (float)$row[1];

            // Estimate: delivery savings = orders with 0 delivery * avg partner fee
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM om_market_orders
                WHERE customer_id = ? AND delivery_fee = 0
                AND date_added >= DATE_TRUNC('month', CURRENT_DATE)
                AND status IN ('entregue','confirmado','em_entrega','coletando','aceito','preparando')
            ");
            $stmt->execute([$customerId]);
            $freeDeliveryOrders = (int)$stmt->fetchColumn();
            $savings['delivery'] = round($freeDeliveryOrders * 6.90, 2); // avg delivery ~R$6.90

            $savings['total'] = round($savings['delivery'] + $savings['cashback'] + $savings['service_fee'], 2);
        } catch (Exception $e) {
            error_log("[membership] savings calc error: " . $e->getMessage());
        }
        return $savings;
    }


    // ═══════════════════════════════════════════════════════════════
    // GET — Status + Plans
    // ═══════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        // Special action: check benefits for a customer (can be used by checkout)
        if ($action === 'benefits') {
            $customer_id = getCustomerIdFromToken();
            if (!$customer_id) response(false, null, "Autenticacao necessaria", 401);

            $membership = getActiveMembership($db, $customer_id);
            $benefitType = $_GET['benefit_type'] ?? '';

            if (!$membership) {
                response(true, [
                    'has_membership' => false,
                    'has_benefit' => false,
                    'plan' => null,
                ]);
            }

            $plan_slug = $membership['plan'] ?? '';
            $result = ['has_membership' => true, 'plan' => $plan_slug, 'has_benefit' => false, 'details' => []];

            switch ($benefitType) {
                case 'free_delivery':
                    $min = $membership['free_delivery_min'];
                    $result['has_benefit'] = true;
                    $result['details'] = [
                        'min_order' => $min !== null ? (float)$min : 0,
                        'all_orders' => $min === null || (float)$min == 0,
                    ];
                    break;
                case 'cashback_rate':
                    $result['has_benefit'] = true;
                    $result['details'] = ['rate' => (float)$membership['cashback_rate']];
                    break;
                case 'priority_support':
                    $ps = $membership['priority_support'] ?? 'normal';
                    $result['has_benefit'] = $ps !== 'normal';
                    $result['details'] = ['level' => $ps];
                    break;
                case 'no_service_fee':
                    $result['has_benefit'] = (bool)$membership['no_service_fee'];
                    break;
                case 'express':
                    $result['has_benefit'] = true;
                    $result['details'] = [
                        'free' => (bool)$membership['express_free'],
                        'discount' => (float)$membership['express_discount'],
                    ];
                    break;
                default:
                    $result['details'] = [
                        'free_delivery_min' => $membership['free_delivery_min'] !== null ? (float)$membership['free_delivery_min'] : 0,
                        'cashback_rate' => (float)$membership['cashback_rate'],
                        'priority_support' => $membership['priority_support'] ?? 'normal',
                        'no_service_fee' => (bool)$membership['no_service_fee'],
                        'express_free' => (bool)$membership['express_free'],
                        'express_discount' => (float)$membership['express_discount'],
                    ];
                    $result['has_benefit'] = true;
            }

            response(true, $result);
        }

        // Default GET: Full membership status + plans
        $customer_id = getCustomerIdFromToken(); // 0 if not logged in

        $plans = loadPlans($db);
        $membership = null;
        $savings = null;
        $history = [];
        $monthsAsMember = 0;

        if ($customer_id > 0) {
            $membership = getActiveMembership($db, $customer_id);

            if ($membership) {
                $savings = getMonthlySavings($db, $customer_id);

                // Months as member
                $startedAt = $membership['started_at'] ?? $membership['created_at'];
                if ($startedAt) {
                    $diff = (new DateTime())->diff(new DateTime($startedAt));
                    $monthsAsMember = $diff->y * 12 + $diff->m + ($diff->d > 15 ? 1 : 0);
                    if ($monthsAsMember < 1) $monthsAsMember = 1;
                }

                // Payment history
                try {
                    $stmtHist = $db->prepare("
                        SELECT id, amount, status, paid_at, created_at
                        FROM om_membership_payments
                        WHERE membership_id = ?
                        ORDER BY created_at DESC
                        LIMIT 12
                    ");
                    $stmtHist->execute([(int)$membership['id']]);
                    $history = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    // Table might not have data yet
                }
            }
        }

        $currentPlanData = null;
        if ($membership) {
            $planSlug = $membership['plan'] ?? '';
            $benefits = [];
            if (!empty($membership['benefits_json'])) {
                $decoded = json_decode($membership['benefits_json'], true);
                if (is_array($decoded)) $benefits = $decoded;
            } elseif (!empty($membership['benefits'])) {
                $decoded = json_decode($membership['benefits'], true);
                if (is_array($decoded)) $benefits = $decoded;
            }

            $currentPlanData = [
                'id' => (int)$membership['id'],
                'plan_slug' => $planSlug,
                'plan_name' => $membership['plan_name'] ?? ucfirst($planSlug),
                'plan_price' => (float)($membership['plan_price'] ?? 0),
                'plan_icon' => $membership['plan_icon'] ?? 'diamond-outline',
                'plan_color' => $membership['plan_color'] ?? '#f59e0b',
                'status' => $membership['status'],
                'started_at' => $membership['started_at'],
                'current_period_start' => $membership['current_period_start'],
                'current_period_end' => $membership['current_period_end'] ?? $membership['expires_at'],
                'cancel_at_period_end' => (bool)($membership['cancel_at_period_end'] ?? false),
                'cancelled_at' => $membership['cancelled_at'],
                'payment_method' => $membership['payment_method'] ?? 'stripe',
                'benefits' => $benefits,
                'cashback_rate' => (float)($membership['cashback_rate'] ?? 0),
                'free_delivery_min' => $membership['free_delivery_min'] !== null ? (float)$membership['free_delivery_min'] : null,
                'priority_support' => $membership['priority_support'] ?? 'normal',
                'no_service_fee' => (bool)($membership['no_service_fee'] ?? false),
                'express_free' => (bool)($membership['express_free'] ?? false),
            ];
        }

        response(true, [
            'current_plan' => $currentPlanData,
            'plans' => $plans,
            'savings' => $savings,
            'months_as_member' => $monthsAsMember,
            'history' => array_map(function($h) {
                return [
                    'id' => (int)$h['id'],
                    'amount' => (float)$h['amount'],
                    'status' => $h['status'],
                    'paid_at' => $h['paid_at'],
                    'date' => $h['created_at'],
                ];
            }, $history),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // POST — Actions (subscribe, cancel, change_plan, reactivate)
    // ═══════════════════════════════════════════════════════════════
    elseif ($method === 'POST') {
        $customer_id = requireCustomerAuth();
        $input = getInput();
        $action = $input['action'] ?? 'subscribe';

        // Rate limit: 10 actions per hour
        if (!checkRateLimit("membership_c{$customer_id}", 10, 3600)) {
            response(false, null, "Muitas requisicoes. Tente novamente em 1 hora.", 429);
        }

        // ─── SUBSCRIBE ──────────────────────────────────────────
        if ($action === 'subscribe') {
            $planSlug = trim($input['plan_slug'] ?? $input['plan_id'] ?? '');
            $paymentIntentId = trim($input['payment_intent_id'] ?? $input['payment_method_id'] ?? '');

            if (!$planSlug) {
                response(false, null, "Plano obrigatorio", 400);
            }

            // Load plan
            $stmtPlan = $db->prepare("
                SELECT * FROM om_membership_plans
                WHERE slug = ? AND status::text IN ('1','active')
            ");
            $stmtPlan->execute([$planSlug]);
            $plan = $stmtPlan->fetch(PDO::FETCH_ASSOC);
            if (!$plan) {
                response(false, null, "Plano nao encontrado", 404);
            }

            // Transaction + FOR UPDATE to prevent race
            $db->beginTransaction();

            $stmtCheck = $db->prepare("
                SELECT id, plan, status, current_period_end, expires_at, cancel_at_period_end
                FROM om_customer_memberships
                WHERE customer_id = ?
                AND status IN ('active', 'trialing')
                AND (current_period_end > NOW() OR expires_at > NOW())
                LIMIT 1
                FOR UPDATE
            ");
            $stmtCheck->execute([$customer_id]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existing && !$existing['cancel_at_period_end']) {
                $db->rollBack();
                response(false, null, "Voce ja possui uma assinatura ativa. Use 'change_plan' para trocar.", 400);
            }

            // If payment_intent provided, verify with Stripe
            $stripeSubId = null;
            $stripeCustomerId = null;

            if ($paymentIntentId && strpos($paymentIntentId, 'pi_') === 0) {
                // Verify Stripe PaymentIntent
                $pi = stripeRequest('GET', "payment_intents/{$paymentIntentId}");
                if (($pi['status'] ?? '') !== 'succeeded') {
                    $db->rollBack();
                    response(false, null, "Pagamento nao confirmado", 400);
                }

                $expectedCents = (int)round((float)$plan['price'] * 100);
                if ((int)($pi['amount'] ?? 0) < $expectedCents) {
                    $db->rollBack();
                    response(false, null, "Valor do pagamento incorreto", 400);
                }

                // Check PI not already used
                $stmtUsed = $db->prepare("SELECT 1 FROM om_membership_payments WHERE stripe_payment_intent_id = ? LIMIT 1");
                $stmtUsed->execute([$paymentIntentId]);
                if ($stmtUsed->fetch()) {
                    $db->rollBack();
                    response(false, null, "Este pagamento ja foi utilizado", 400);
                }

                $stripeCustomerId = $pi['customer'] ?? '';
            }

            // Create or get Stripe customer for future billing
            if (!$stripeCustomerId) {
                $stripeCustomerId = getOrCreateStripeCustomer($db, $customer_id);
            }

            // Set period
            $now = date('Y-m-d H:i:s');
            $trialEnd = null;
            $periodStart = $now;

            // Check if customer ever had a membership (no trial for returning members)
            $stmtEver = $db->prepare("SELECT COUNT(*) FROM om_customer_memberships WHERE customer_id = ?");
            $stmtEver->execute([$customer_id]);
            $hadBefore = (int)$stmtEver->fetchColumn() > 0;

            $trialDays = (!$hadBefore && (int)$plan['trial_days'] > 0) ? (int)$plan['trial_days'] : 0;
            $status = $trialDays > 0 ? 'trialing' : 'active';
            $periodEnd = date('Y-m-d H:i:s', strtotime('+1 month'));

            if ($trialDays > 0) {
                $trialEnd = date('Y-m-d H:i:s', strtotime("+{$trialDays} days"));
            }

            // Deactivate any existing cancelled membership
            if ($existing) {
                $db->prepare("UPDATE om_customer_memberships SET status = 'expired', updated_at = NOW() WHERE id = ?")
                    ->execute([(int)$existing['id']]);
            }

            // Insert membership
            $stmtInsert = $db->prepare("
                INSERT INTO om_customer_memberships
                (customer_id, plan, status, started_at, current_period_start, current_period_end,
                 trial_end, stripe_subscription_id, stripe_customer_id, payment_method, cancel_at_period_end, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, 'stripe', FALSE, NOW(), NOW())
                RETURNING id
            ");
            $stmtInsert->execute([
                $customer_id, $planSlug, $status,
                $periodStart, $periodEnd, $trialEnd,
                $stripeSubId, $stripeCustomerId,
            ]);
            $membershipId = (int)$stmtInsert->fetchColumn();

            // Record payment if PI provided
            if ($paymentIntentId) {
                $db->prepare("
                    INSERT INTO om_membership_payments (membership_id, amount, status, stripe_payment_intent_id, paid_at, created_at)
                    VALUES (?, ?, 'paid', ?, NOW(), NOW())
                ")->execute([$membershipId, (float)$plan['price'], $paymentIntentId]);
            }

            $db->commit();

            // Load benefits
            $benefits = [];
            if (!empty($plan['benefits_json'])) {
                $decoded = json_decode($plan['benefits_json'], true);
                if (is_array($decoded)) $benefits = $decoded;
            }

            response(true, [
                'membership_id' => $membershipId,
                'plan_slug' => $planSlug,
                'plan_name' => $plan['name'],
                'status' => $status,
                'current_period_end' => $periodEnd,
                'trial_end' => $trialEnd,
                'benefits' => $benefits,
                'is_trial' => $trialDays > 0,
            ], $trialDays > 0
                ? "Bem-vindo ao SuperBora Club! Seus {$trialDays} dias gratis comecam agora."
                : "Bem-vindo ao SuperBora Club! Seus beneficios ja estao ativos."
            );
        }

        // ─── CANCEL ─────────────────────────────────────────────
        elseif ($action === 'cancel') {
            $membership = getActiveMembership($db, $customer_id);
            if (!$membership) {
                response(false, null, "Nenhuma assinatura ativa encontrada", 404);
            }

            // Cancel at period end (keeps benefits until expiry)
            $db->prepare("
                UPDATE om_customer_memberships
                SET cancel_at_period_end = TRUE, cancelled_at = NOW(), updated_at = NOW()
                WHERE id = ? AND customer_id = ?
            ")->execute([(int)$membership['id'], $customer_id]);

            // Cancel Stripe subscription if exists
            if (!empty($membership['stripe_subscription_id'])) {
                stripeRequest('POST', "subscriptions/{$membership['stripe_subscription_id']}", [
                    'cancel_at_period_end' => 'true',
                ]);
            }

            $endDate = $membership['current_period_end'] ?? $membership['expires_at'];
            $endFormatted = $endDate ? date('d/m/Y', strtotime($endDate)) : '';

            response(true, [
                'status' => 'cancelled',
                'active_until' => $endDate,
            ], "Assinatura cancelada. Seus beneficios permanecem ativos ate {$endFormatted}.");
        }

        // ─── CHANGE PLAN ────────────────────────────────────────
        elseif ($action === 'change_plan') {
            $newPlanSlug = trim($input['plan_slug'] ?? '');
            $paymentIntentId = trim($input['payment_intent_id'] ?? '');

            if (!$newPlanSlug) {
                response(false, null, "Novo plano obrigatorio", 400);
            }

            $membership = getActiveMembership($db, $customer_id);
            if (!$membership) {
                response(false, null, "Nenhuma assinatura ativa encontrada", 404);
            }

            if ($membership['plan'] === $newPlanSlug) {
                response(false, null, "Voce ja esta neste plano", 400);
            }

            // Load new plan
            $stmtPlan = $db->prepare("SELECT * FROM om_membership_plans WHERE slug = ? AND status::text IN ('1','active')");
            $stmtPlan->execute([$newPlanSlug]);
            $newPlan = $stmtPlan->fetch(PDO::FETCH_ASSOC);
            if (!$newPlan) {
                response(false, null, "Plano nao encontrado", 404);
            }

            // Determine if upgrade or downgrade
            $currentPrice = (float)($membership['plan_price'] ?? 0);
            $newPrice = (float)$newPlan['price'];
            $isUpgrade = $newPrice > $currentPrice;

            // If upgrade and payment provided, verify
            if ($isUpgrade && $paymentIntentId && strpos($paymentIntentId, 'pi_') === 0) {
                $pi = stripeRequest('GET', "payment_intents/{$paymentIntentId}");
                if (($pi['status'] ?? '') !== 'succeeded') {
                    response(false, null, "Pagamento nao confirmado", 400);
                }
            }

            // Update membership
            $db->beginTransaction();
            $db->prepare("
                UPDATE om_customer_memberships
                SET plan = ?, cancel_at_period_end = FALSE, cancelled_at = NULL, updated_at = NOW()
                WHERE id = ? AND customer_id = ?
            ")->execute([$newPlanSlug, (int)$membership['id'], $customer_id]);

            // Record upgrade payment
            if ($paymentIntentId) {
                $proratedAmount = max(0, $newPrice - $currentPrice);
                $db->prepare("
                    INSERT INTO om_membership_payments (membership_id, amount, status, stripe_payment_intent_id, paid_at, created_at)
                    VALUES (?, ?, 'paid', ?, NOW(), NOW())
                ")->execute([(int)$membership['id'], $proratedAmount, $paymentIntentId]);
            }

            $db->commit();

            response(true, [
                'plan_slug' => $newPlanSlug,
                'plan_name' => $newPlan['name'],
                'is_upgrade' => $isUpgrade,
                'new_price' => $newPrice,
            ], $isUpgrade
                ? "Upgrade para {$newPlan['name']} realizado! Novos beneficios ja estao ativos."
                : "Plano alterado para {$newPlan['name']}. Mudanca efetiva no proximo ciclo."
            );
        }

        // ─── REACTIVATE ─────────────────────────────────────────
        elseif ($action === 'reactivate') {
            $stmt = $db->prepare("
                SELECT * FROM om_customer_memberships
                WHERE customer_id = ?
                AND cancel_at_period_end = TRUE
                AND status IN ('active', 'trialing')
                AND (current_period_end > NOW() OR expires_at > NOW())
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$customer_id]);
            $membership = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$membership) {
                response(false, null, "Nenhuma assinatura cancelada encontrada para reativar", 404);
            }

            $db->prepare("
                UPDATE om_customer_memberships
                SET cancel_at_period_end = FALSE, cancelled_at = NULL, updated_at = NOW()
                WHERE id = ? AND customer_id = ?
            ")->execute([(int)$membership['id'], $customer_id]);

            // Reactivate Stripe subscription
            if (!empty($membership['stripe_subscription_id'])) {
                stripeRequest('POST', "subscriptions/{$membership['stripe_subscription_id']}", [
                    'cancel_at_period_end' => 'false',
                ]);
            }

            response(true, [
                'status' => 'active',
                'plan' => $membership['plan'],
            ], "Assinatura reativada! Seus beneficios continuam ativos.");
        }

        else {
            response(false, null, "Acao invalida. Use: subscribe, cancel, change_plan, reactivate", 400);
        }
    }

    // ─── OPTIONS ─────────────────────────────────────────────────
    elseif ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    else {
        response(false, null, "Metodo nao suportado", 405);
    }

} catch (Exception $e) {
    error_log("[membership] Error: " . $e->getMessage());
    response(false, null, "Erro ao processar solicitacao", 500);
}
