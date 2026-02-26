<?php
/**
 * POST /api/pagamento/pix/webhook.php
 * Webhook do Pagar.me para confirmar pagamento PIX
 *
 * Ao confirmar pagamento:
 * 1. Atualiza status do pagamento (om_payments)
 * 2. Atualiza status do pedido (om_market_orders)
 * 3. Notifica cliente via Web Push
 * 4. Notifica parceiro via Web Push
 * 5. Credita cashback ao cliente
 * 6. Atualiza gamificacao do cliente
 * 7. Loga evento para auditoria
 */
require_once __DIR__ . "/../config/database.php";

// Logging helper
function logPix($message, $data = null) {
    $logDir = dirname(__DIR__, 2) . '/logs/pix_webhook/';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . date('Y-m-d') . '.log';
    $entry = date('Y-m-d H:i:s') . ' - ' . $message;
    if ($data) $entry .= ' - ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
}

// ═══════════════════════════════════════════════════════════════
// SECURITY: Validate webhook signature/secret
// ═══════════════════════════════════════════════════════════════
$rawBody = file_get_contents('php://input');

// Load env for webhook secret
$envPath = dirname(__DIR__, 2) . '/.env';
$pixWebhookSecret = '';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            if (trim($key) === 'PIX_WEBHOOK_SECRET') $pixWebhookSecret = trim($value);
        }
    }
}

// Option 1: HMAC signature in header (Pagar.me style)
$receivedSignature = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
// Option 2: Shared secret in header
$receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';

if (!empty($pixWebhookSecret)) {
    $valid = false;

    // Check HMAC signature
    if ($receivedSignature) {
        $expectedSig = 'sha256=' . hash_hmac('sha256', $rawBody, $pixWebhookSecret);
        $valid = hash_equals($expectedSig, $receivedSignature);
    }
    // Check shared secret header
    elseif ($receivedSecret) {
        $valid = hash_equals($pixWebhookSecret, $receivedSecret);
    }

    if (!$valid) {
        logPix("SECURITY: Webhook rejeitado — assinatura/secret invalido", [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'has_signature' => !empty($receivedSignature),
            'has_secret' => !empty($receivedSecret),
        ]);
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
} else {
    // PIX_WEBHOOK_SECRET not configured — log warning but allow (for initial setup)
    logPix("WARNING: PIX_WEBHOOK_SECRET nao configurado — webhook sem validacao de assinatura");
}

try {
    $input = json_decode($rawBody, true) ?: [];
    $db = getDB();

    // Criar tabela de log se nao existir
    $db->exec("
        CREATE TABLE IF NOT EXISTS om_pix_webhook_log (
            id SERIAL PRIMARY KEY,
            txid VARCHAR(100),
            status VARCHAR(50),
            payment_id INT DEFAULT NULL,
            order_id INT DEFAULT NULL,
            payload JSON,
            processed SMALLINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_pix_log_txid ON om_pix_webhook_log (txid)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pix_log_order ON om_pix_webhook_log (order_id)");

    // Pegar dados do webhook (ajustar conforme Pagar.me)
    $txid = $input["data"]["id"] ?? $input["txid"] ?? "";
    $status = $input["data"]["status"] ?? $input["status"] ?? "";

    logPix("Webhook recebido", ["txid" => $txid, "status" => $status]);

    if (!$txid) {
        logPix("ERRO: TXID nao informado");
        response(false, null, "TXID nao informado", 400);
    }

    // Buscar pagamento (prepared statement)
    $stmt = $db->prepare("SELECT * FROM om_payments WHERE gateway_id = ?");
    $stmt->execute([$txid]);
    $pagamento = $stmt->fetch();

    if (!$pagamento) {
        // Registrar no log mesmo sem pagamento encontrado
        $stmt = $db->prepare("
            INSERT INTO om_pix_webhook_log (txid, status, payload, processed)
            VALUES (?, ?, ?, 0)
        ");
        $stmt->execute([$txid, $status, json_encode($input, JSON_UNESCAPED_UNICODE)]);
        logPix("Pagamento nao encontrado", ["txid" => $txid]);
        response(false, null, "Pagamento nao encontrado", 404);
    }

    // Registrar no log
    $stmt = $db->prepare("
        INSERT INTO om_pix_webhook_log (txid, status, payment_id, order_id, payload, processed)
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        $txid,
        $status,
        $pagamento["id"],
        $pagamento["origem_id"] ?? null,
        json_encode($input, JSON_UNESCAPED_UNICODE)
    ]);

    if ($status === "paid" || $status === "approved") {
        // Confirmar pagamento
        $stmt = $db->prepare("UPDATE om_payments SET status = 'pago', pago_em = NOW() WHERE id = ?");
        $stmt->execute([$pagamento["id"]]);

        logPix("Pagamento confirmado", ["payment_id" => $pagamento["id"], "tipo" => $pagamento["tipo_origem"]]);

        // Atualizar origem (corrida ou pedido)
        if ($pagamento["tipo_origem"] === "corrida") {
            $stmt = $db->prepare("UPDATE boraum_corridas SET pagamento_status = 'pago', payment_id = ? WHERE id = ?");
            $stmt->execute([$pagamento["id"], $pagamento["origem_id"]]);

        } elseif ($pagamento["tipo_origem"] === "pedido_mercado") {
            $order_id = (int)$pagamento["origem_id"];

            // Atualizar pedido - marcar pagamento como pago
            $stmt = $db->prepare("
                UPDATE om_market_orders
                SET pagamento_status = 'pago', payment_id = ?, date_modified = NOW()
                WHERE order_id = ?
            ");
            $stmt->execute([$pagamento["id"], $order_id]);

            // Buscar dados do pedido para notificacoes
            $stmt = $db->prepare("
                SELECT o.*, p.name as mercado_nome, p.partner_id
                FROM om_market_orders o
                LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
                WHERE o.order_id = ?
            ");
            $stmt->execute([$order_id]);
            $pedido = $stmt->fetch();

            if ($pedido) {
                $customer_id = (int)($pedido['customer_id'] ?? 0);
                $partner_id = (int)($pedido['partner_id'] ?? 0);
                $total = (float)($pedido['total'] ?? 0);
                $mercado_nome = $pedido['mercado_nome'] ?? 'Loja';

                // ═══════════════════════════════════════════════════════
                // NOTIFICACOES PUSH
                // ═══════════════════════════════════════════════════════
                try {
                    require_once dirname(__DIR__, 2) . '/mercado/helpers/notify.php';

                    // Notificar cliente
                    if ($customer_id) {
                        notifyCustomer(
                            $db,
                            $customer_id,
                            'Pagamento PIX confirmado!',
                            sprintf('Seu pagamento de R$ %.2f foi confirmado. Pedido #%d em preparo!', $total, $order_id),
                            '/mercado/vitrine/pedidos/' . $order_id
                        );
                        logPix("Push enviado ao cliente", ["customer_id" => $customer_id, "order_id" => $order_id]);
                    }

                    // Notificar parceiro
                    if ($partner_id) {
                        notifyPartner(
                            $db,
                            $partner_id,
                            'Pagamento PIX confirmado!',
                            sprintf('Pedido #%d - PIX de R$ %.2f confirmado. Prepare o pedido!', $order_id, $total),
                            '/painel/mercado/pedidos.php'
                        );
                        logPix("Push enviado ao parceiro", ["partner_id" => $partner_id, "order_id" => $order_id]);
                    }
                } catch (Exception $pushErr) {
                    logPix("Erro ao enviar push", ["error" => $pushErr->getMessage()]);
                }

                // ═══════════════════════════════════════════════════════
                // CASHBACK
                // ═══════════════════════════════════════════════════════
                if ($customer_id && $total > 0) {
                    try {
                        // Buscar nivel do cliente para multiplicador de cashback
                        $stmt = $db->prepare("SELECT level FROM om_gamification WHERE customer_id = ?");
                        $stmt->execute([$customer_id]);
                        $level = (int)($stmt->fetchColumn() ?: 1);
                        $multiplier = 1 + ($level * 0.1); // 1.1x a 2.0x

                        // 2% de cashback base * multiplicador do nivel
                        $cashbackBase = 0.02;
                        $cashbackAmount = round($total * $cashbackBase * $multiplier, 2);

                        if ($cashbackAmount >= 0.01) {
                            // Criar tabela se nao existir
                            $db->exec("
                                CREATE TABLE IF NOT EXISTS om_cashback (
                                    id SERIAL PRIMARY KEY,
                                    customer_id INT NOT NULL,
                                    order_id INT DEFAULT NULL,
                                    type VARCHAR(50) NOT NULL CHECK (type IN ('earned','used','expired','bonus')),
                                    amount DECIMAL(10,2) NOT NULL,
                                    description VARCHAR(255) DEFAULT '',
                                    status VARCHAR(50) DEFAULT 'pending' CHECK (status IN ('available','pending','used','expired')),
                                    expires_at TIMESTAMP DEFAULT NULL,
                                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                                )
                            ");

                            $db->exec("CREATE INDEX IF NOT EXISTS idx_om_cashback_customer ON om_cashback (customer_id)");
                            $db->exec("CREATE INDEX IF NOT EXISTS idx_om_cashback_status ON om_cashback (customer_id, status)");

                            $stmt = $db->prepare("
                                INSERT INTO om_cashback (customer_id, order_id, type, amount, description, status, expires_at)
                                VALUES (?, ?, 'earned', ?, ?, 'pending', NOW() + INTERVAL '90 days')
                            ");
                            $stmt->execute([
                                $customer_id,
                                $order_id,
                                $cashbackAmount,
                                sprintf('Cashback pedido #%d (%.0f%% nivel %d)', $order_id, $cashbackBase * $multiplier * 100, $level)
                            ]);

                            logPix("Cashback creditado", [
                                "customer_id" => $customer_id,
                                "amount" => $cashbackAmount,
                                "level" => $level,
                                "multiplier" => $multiplier
                            ]);
                        }
                    } catch (Exception $cbErr) {
                        logPix("Erro ao creditar cashback", ["error" => $cbErr->getMessage()]);
                    }
                }

                // ═══════════════════════════════════════════════════════
                // GAMIFICACAO - atualizar pontos
                // ═══════════════════════════════════════════════════════
                if ($customer_id && $total > 0) {
                    try {
                        $db->exec("
                            CREATE TABLE IF NOT EXISTS om_gamification (
                                customer_id INT PRIMARY KEY,
                                points INT DEFAULT 0,
                                level INT DEFAULT 1,
                                streak_days INT DEFAULT 0,
                                last_order_date DATE DEFAULT NULL,
                                total_orders INT DEFAULT 0,
                                total_spent DECIMAL(10,2) DEFAULT 0,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            )
                        ");

                        // Pontos: 100 por pedido + 2x valor gasto
                        $pointsEarned = 100 + (int)($total * 2);

                        // Calcular streak
                        $stmt = $db->prepare("SELECT last_order_date, streak_days FROM om_gamification WHERE customer_id = ?");
                        $stmt->execute([$customer_id]);
                        $gam = $stmt->fetch();

                        $streakDays = 0;
                        $today = date('Y-m-d');
                        if ($gam) {
                            $lastDate = $gam['last_order_date'];
                            $currentStreak = (int)$gam['streak_days'];
                            if ($lastDate === date('Y-m-d', strtotime('-1 day'))) {
                                $streakDays = $currentStreak + 1; // Dia consecutivo
                            } elseif ($lastDate === $today) {
                                $streakDays = $currentStreak; // Mesmo dia, manter
                            } else {
                                $streakDays = 1; // Quebrou streak
                            }
                        } else {
                            $streakDays = 1;
                        }

                        // Bonus de streak: +10% por dia consecutivo (max 50%)
                        $streakBonus = min(0.5, $streakDays * 0.1);
                        $pointsEarned = (int)($pointsEarned * (1 + $streakBonus));

                        $stmt = $db->prepare("
                            INSERT INTO om_gamification (customer_id, points, level, streak_days, last_order_date, total_orders, total_spent)
                            VALUES (?, ?, 1, ?, ?, 1, ?)
                            ON CONFLICT (customer_id) DO UPDATE SET
                                points = om_gamification.points + ?,
                                level = LEAST(10, 1 + FLOOR((om_gamification.points + ?) / 500)),
                                streak_days = ?,
                                last_order_date = ?,
                                total_orders = om_gamification.total_orders + 1,
                                total_spent = om_gamification.total_spent + ?
                        ");
                        $stmt->execute([
                            $customer_id, $pointsEarned, $streakDays, $today, $total,
                            $pointsEarned, $pointsEarned, $streakDays, $today, $total
                        ]);

                        logPix("Gamificacao atualizada", [
                            "customer_id" => $customer_id,
                            "points" => $pointsEarned,
                            "streak" => $streakDays
                        ]);

                        // Auto-grant badges
                        $db->exec("
                            CREATE TABLE IF NOT EXISTS om_badges (
                                id SERIAL PRIMARY KEY,
                                customer_id INT NOT NULL,
                                badge_type VARCHAR(50) NOT NULL,
                                badge_name VARCHAR(100) NOT NULL,
                                badge_icon VARCHAR(50) DEFAULT 'star',
                                earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                UNIQUE (customer_id, badge_type)
                            )
                        ");

                        $db->exec("CREATE INDEX IF NOT EXISTS idx_om_badges_customer ON om_badges (customer_id)");

                        // Buscar stats atualizadas
                        $stmt = $db->prepare("SELECT total_orders, total_spent, streak_days FROM om_gamification WHERE customer_id = ?");
                        $stmt->execute([$customer_id]);
                        $stats = $stmt->fetch();

                        if ($stats) {
                            $badgesToCheck = [];
                            $to = (int)$stats['total_orders'];
                            $ts = (float)$stats['total_spent'];
                            $sd = (int)$stats['streak_days'];

                            if ($to >= 1) $badgesToCheck[] = ['first_order', 'Primeiro Pedido', 'shopping-bag'];
                            if ($to >= 10) $badgesToCheck[] = ['ten_orders', 'Fa de Delivery', 'heart'];
                            if ($to >= 50) $badgesToCheck[] = ['fifty_orders', 'SuperCliente', 'crown'];
                            if ($ts >= 500) $badgesToCheck[] = ['big_spender', 'Grande Gastador', 'wallet'];
                            if ($sd >= 7) $badgesToCheck[] = ['streak_7', 'Semana Inteira', 'fire'];
                            if ($sd >= 30) $badgesToCheck[] = ['streak_30', 'Mes Dedicado', 'trophy'];

                            foreach ($badgesToCheck as $b) {
                                try {
                                    $stmt = $db->prepare("INSERT IGNORE INTO om_badges (customer_id, badge_type, badge_name, badge_icon) VALUES (?, ?, ?, ?)");
                                    $stmt->execute([$customer_id, $b[0], $b[1], $b[2]]);
                                } catch (Exception $e) {
                                    // Ignore duplicate
                                }
                            }
                        }
                    } catch (Exception $gamErr) {
                        logPix("Erro ao atualizar gamificacao", ["error" => $gamErr->getMessage()]);
                    }
                }
            }
        }
    } elseif ($status === "failed" || $status === "refused" || $status === "canceled") {
        // Pagamento falhou/cancelado
        $stmt = $db->prepare("UPDATE om_payments SET status = 'falhou' WHERE id = ?");
        $stmt->execute([$pagamento["id"]]);

        logPix("Pagamento falhou/cancelado", ["payment_id" => $pagamento["id"], "status" => $status]);

        // Notificar cliente se for pedido de mercado
        if ($pagamento["tipo_origem"] === "pedido_mercado") {
            $order_id = (int)$pagamento["origem_id"];
            $stmt = $db->prepare("SELECT customer_id, total FROM om_market_orders WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $pedido = $stmt->fetch();

            if ($pedido && (int)$pedido['customer_id']) {
                try {
                    require_once dirname(__DIR__, 2) . '/mercado/helpers/notify.php';
                    notifyCustomer(
                        $db,
                        (int)$pedido['customer_id'],
                        'Pagamento PIX nao confirmado',
                        sprintf('O pagamento PIX do pedido #%d expirou ou foi recusado. Tente novamente.', $order_id),
                        '/mercado/vitrine/checkout'
                    );
                } catch (Exception $pushErr) {
                    logPix("Erro ao notificar falha", ["error" => $pushErr->getMessage()]);
                }
            }
        }
    }

    response(true, ["status" => "processed"]);

} catch (Exception $e) {
    logPix("ERRO GERAL", ["error" => $e->getMessage()]);
    response(false, null, 'Erro interno do servidor', 500);
}
