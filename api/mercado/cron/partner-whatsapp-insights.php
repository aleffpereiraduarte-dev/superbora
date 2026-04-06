<?php
/**
 * Partner WhatsApp Daily Insights — Cron daily at 9:00 AM
 * iFood Cris-style: sends personalized AI-generated insights via WhatsApp.
 *
 * For each active partner (status=1, has orders in last 7 days):
 *   - Gathers yesterday's stats (orders, revenue, avg ticket, rating)
 *   - Compares with previous day and last week average
 *   - Generates 2-3 short insight messages using Claude AI
 *   - Sends via WhatsApp (Twilio)
 *   - Logs to om_partner_daily_insights table
 *
 * Limit: 50 partners per run (to avoid Twilio rate limits)
 * Only sends if partner has whatsapp/phone number
 *
 * Crontab: 0 9 * * * curl -s -H "X-Cron-Key: SECRET" https://superbora.com.br/api/mercado/cron/partner-whatsapp-insights.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once __DIR__ . '/../helpers/zapi-whatsapp.php';

// Cron auth guard — allow CLI, require secret for HTTP
if (php_sapi_name() !== 'cli') {
    $cronKey = $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    $expectedKey = $_ENV['CRON_SECRET'] ?? getenv('CRON_SECRET') ?: '';
    if (empty($expectedKey) || empty($cronKey) || !hash_equals($expectedKey, $cronKey)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$db = getDB();

// ── Create table if not exists ──
$db->exec("
    CREATE TABLE IF NOT EXISTS om_partner_daily_insights (
        id BIGSERIAL PRIMARY KEY,
        partner_id INT NOT NULL,
        insight_date DATE NOT NULL,
        yesterday_orders INT DEFAULT 0,
        yesterday_revenue NUMERIC(12,2) DEFAULT 0,
        yesterday_avg_ticket NUMERIC(10,2) DEFAULT 0,
        yesterday_rating NUMERIC(3,2) DEFAULT 0,
        comparison_data JSONB DEFAULT '{}',
        insights_text TEXT,
        insights_json JSONB DEFAULT '[]',
        whatsapp_sent BOOLEAN DEFAULT FALSE,
        whatsapp_message_id VARCHAR(100),
        ai_model VARCHAR(50),
        ai_tokens_used INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT NOW(),
        CONSTRAINT uq_partner_daily_insight UNIQUE (partner_id, insight_date)
    )
");
$db->exec("CREATE INDEX IF NOT EXISTS idx_partner_insights_date ON om_partner_daily_insights (insight_date)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_partner_insights_partner ON om_partner_daily_insights (partner_id)");

date_default_timezone_set('America/Sao_Paulo');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$dayBefore = date('Y-m-d', strtotime('-2 days'));
$weekAgoStart = date('Y-m-d', strtotime('-8 days'));
$weekAgoEnd = date('Y-m-d', strtotime('-2 days'));

$processed = 0;
$sent = 0;
$errors = 0;
$maxPartners = 50;

// Claude AI client (low timeout for cron, no retries to avoid slow runs)
$claude = null;
$aiAvailable = false;
try {
    $claude = new ClaudeClient(ClaudeClient::DEFAULT_MODEL, 30, 0);
    if (!empty($_ENV['CLAUDE_API_KEY'])) {
        $aiAvailable = true;
    }
} catch (Exception $e) {
    error_log("[partner-whatsapp-insights] Claude not available: " . $e->getMessage());
}

// ── Get active partners with recent orders and phone numbers ──
$stmtPartners = $db->prepare("
    SELECT DISTINCT p.partner_id,
           COALESCE(p.trade_name, p.name) as business_name,
           p.phone, p.categoria
    FROM om_market_partners p
    JOIN om_market_orders o ON o.partner_id = p.partner_id
    WHERE p.status::text = '1'
      AND p.phone IS NOT NULL AND p.phone != ''
      AND o.date_added >= NOW() - INTERVAL '7 days'
    ORDER BY p.partner_id
    LIMIT ?
");
$stmtPartners->execute([$maxPartners]);

while ($partner = $stmtPartners->fetch(PDO::FETCH_ASSOC)) {
    $pid = (int)$partner['partner_id'];

    // Skip if already sent for this date
    $stmtCheck = $db->prepare("SELECT 1 FROM om_partner_daily_insights WHERE partner_id = ? AND insight_date = ?");
    $stmtCheck->execute([$pid, $yesterday]);
    if ($stmtCheck->fetch()) continue;

    try {
        // ── Yesterday's metrics ──
        $stmtYesterday = $db->prepare("
            SELECT
                COUNT(*) as orders,
                COALESCE(SUM(CASE WHEN status IN ('entregue', 'finalizado') THEN total END), 0) as revenue,
                COALESCE(AVG(CASE WHEN status IN ('entregue', 'finalizado') THEN total END), 0) as avg_ticket,
                COUNT(CASE WHEN status = 'cancelado' THEN 1 END) as cancelled
            FROM om_market_orders
            WHERE partner_id = ? AND DATE(date_added) = ?
        ");
        $stmtYesterday->execute([$pid, $yesterday]);
        $yest = $stmtYesterday->fetch(PDO::FETCH_ASSOC);

        // ── Previous day's metrics (for day-over-day comparison) ──
        $stmtPrevDay = $db->prepare("
            SELECT COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue
            FROM om_market_orders
            WHERE partner_id = ? AND DATE(date_added) = ?
              AND status IN ('entregue', 'finalizado')
        ");
        $stmtPrevDay->execute([$pid, $dayBefore]);
        $prevDay = $stmtPrevDay->fetch(PDO::FETCH_ASSOC);

        // ── Last week average (7 days, same period) ──
        $stmtWeekAvg = $db->prepare("
            SELECT
                COUNT(*) / GREATEST(1, COUNT(DISTINCT DATE(date_added))) as avg_daily_orders,
                COALESCE(SUM(total), 0) / GREATEST(1, COUNT(DISTINCT DATE(date_added))) as avg_daily_revenue
            FROM om_market_orders
            WHERE partner_id = ? AND DATE(date_added) BETWEEN ? AND ?
              AND status IN ('entregue', 'finalizado')
        ");
        $stmtWeekAvg->execute([$pid, $weekAgoStart, $weekAgoEnd]);
        $weekAvg = $stmtWeekAvg->fetch(PDO::FETCH_ASSOC);

        // ── Yesterday's rating ──
        $stmtRating = $db->prepare("
            SELECT COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as review_count
            FROM om_market_reviews
            WHERE partner_id = ? AND DATE(created_at) = ?
        ");
        $stmtRating->execute([$pid, $yesterday]);
        $rating = $stmtRating->fetch(PDO::FETCH_ASSOC);

        // ── Top product yesterday ──
        $stmtTopProd = $db->prepare("
            SELECT oi.product_name, SUM(oi.quantity) as qty
            FROM om_market_order_items oi
            JOIN om_market_orders o ON o.order_id = oi.order_id
            WHERE o.partner_id = ? AND DATE(o.date_added) = ?
              AND o.status IN ('entregue', 'finalizado')
            GROUP BY oi.product_name
            ORDER BY qty DESC
            LIMIT 1
        ");
        $stmtTopProd->execute([$pid, $yesterday]);
        $topProd = $stmtTopProd->fetch(PDO::FETCH_ASSOC);

        $yesterdayOrders = (int)($yest['orders'] ?? 0);
        $yesterdayRevenue = (float)($yest['revenue'] ?? 0);
        $yesterdayAvgTicket = round((float)($yest['avg_ticket'] ?? 0), 2);
        $yesterdayRating = round((float)($rating['avg_rating'] ?? 0), 1);
        $yesterdayCancelled = (int)($yest['cancelled'] ?? 0);
        $topProductName = $topProd['product_name'] ?? '';

        $prevDayOrders = (int)($prevDay['orders'] ?? 0);
        $weekAvgDailyOrders = round((float)($weekAvg['avg_daily_orders'] ?? 0), 1);
        $weekAvgDailyRevenue = round((float)($weekAvg['avg_daily_revenue'] ?? 0), 2);

        // Calculate deltas
        $ordersDeltaWeek = $weekAvgDailyOrders > 0
            ? round(($yesterdayOrders - $weekAvgDailyOrders) / $weekAvgDailyOrders * 100)
            : 0;
        $revenueDeltaPrev = $prevDayOrders > 0
            ? round(($yesterdayOrders - $prevDayOrders) / $prevDayOrders * 100)
            : 0;

        $comparison = [
            'prev_day_orders' => $prevDayOrders,
            'week_avg_orders' => $weekAvgDailyOrders,
            'week_avg_revenue' => $weekAvgDailyRevenue,
            'orders_delta_week_pct' => $ordersDeltaWeek,
            'orders_delta_prev_pct' => $revenueDeltaPrev,
            'top_product' => $topProductName,
            'reviews_count' => (int)($rating['review_count'] ?? 0),
            'cancelled' => $yesterdayCancelled,
        ];

        // ── Generate insights ──
        $insights = [];
        $insightsText = '';
        $aiModel = '';
        $aiTokens = 0;

        if ($aiAvailable && $yesterdayOrders > 0) {
            // Build context for AI
            $dayOfWeek = date('l', strtotime($yesterday));
            $dayNames = ['Monday' => 'segunda', 'Tuesday' => 'terca', 'Wednesday' => 'quarta',
                         'Thursday' => 'quinta', 'Friday' => 'sexta', 'Saturday' => 'sabado', 'Sunday' => 'domingo'];
            $dayNamePt = $dayNames[$dayOfWeek] ?? $dayOfWeek;

            $context = "Loja: {$partner['business_name']} ({$partner['categoria']})\n"
                     . "Dia: {$dayNamePt} ({$yesterday})\n"
                     . "Pedidos ontem: {$yesterdayOrders}\n"
                     . "Receita ontem: R$ " . number_format($yesterdayRevenue, 2, ',', '.') . "\n"
                     . "Ticket medio: R$ " . number_format($yesterdayAvgTicket, 2, ',', '.') . "\n"
                     . "Media semanal: {$weekAvgDailyOrders} pedidos/dia\n"
                     . "Variacao vs semana: {$ordersDeltaWeek}%\n";
            if ($topProductName) {
                $context .= "Produto mais pedido: {$topProductName}\n";
            }
            if ($yesterdayRating > 0) {
                $context .= "Nota media ontem: {$yesterdayRating}\n";
            }
            if ($yesterdayCancelled > 0) {
                $context .= "Cancelamentos: {$yesterdayCancelled}\n";
            }

            $aiResult = $claude->send(
                "Voce e a Cris, assistente IA do SuperBora para parceiros. Gere exatamente 3 mensagens curtas e motivacionais em portugues brasileiro (sem acentos, sem emojis) baseadas nos dados do parceiro. Cada mensagem deve ter no maximo 120 caracteres. Seja positivo, pratico e direto. Responda APENAS em JSON: [\"msg1\", \"msg2\", \"msg3\"]",
                [['role' => 'user', 'content' => $context]],
                512
            );

            if ($aiResult['success']) {
                $parsed = ClaudeClient::parseJson($aiResult['text']);
                if (is_array($parsed) && count($parsed) >= 2) {
                    $insights = array_slice($parsed, 0, 3);
                    $aiModel = $aiResult['model'] ?? '';
                    $aiTokens = $aiResult['total_tokens'] ?? 0;
                }
            }
        }

        // Fallback: generate static insights if AI failed or unavailable
        if (empty($insights)) {
            if ($yesterdayOrders > 0) {
                $deltaSign = $ordersDeltaWeek >= 0 ? '+' : '';
                $insights[] = "Ontem voce teve {$yesterdayOrders} pedidos, {$deltaSign}{$ordersDeltaWeek}% comparado a media da semana!";

                if ($topProductName) {
                    $insights[] = "Seu produto mais pedido foi {$topProductName}. Que tal criar um combo?";
                } else {
                    $insights[] = "Receita de ontem: R$ " . number_format($yesterdayRevenue, 2, ',', '.') . ". Continue assim!";
                }

                if ($yesterdayRating > 0 && $yesterdayRating < 4.5) {
                    $insights[] = "Sua nota media esta {$yesterdayRating}. Responda avaliacoes pra subir!";
                } elseif ($yesterdayCancelled > 0) {
                    $insights[] = "Voce teve {$yesterdayCancelled} cancelamento(s). Revise o fluxo de aceite.";
                } else {
                    $insights[] = "Ticket medio: R$ " . number_format($yesterdayAvgTicket, 2, ',', '.') . ". Tente sugerir acompanhamentos!";
                }
            } else {
                $insights[] = "Ontem nao houve pedidos. Crie uma promocao pra atrair clientes!";
                $insights[] = "Dica: ative o modo 'aberto' no horario certo pra aparecer na vitrine.";
                $insights[] = "Responda avaliacoes e atualize fotos do cardapio pra aumentar visibilidade.";
            }
        }

        $insightsText = implode("\n\n", $insights);

        // ── Build WhatsApp message ──
        $waMessage = "Bom dia, {$partner['business_name']}! Aqui e a Cris do SuperBora.\n\n";
        $waMessage .= "Resumo de ontem ({$yesterday}):\n";
        $waMessage .= "Pedidos: {$yesterdayOrders}\n";
        $waMessage .= "Receita: R$ " . number_format($yesterdayRevenue, 2, ',', '.') . "\n";
        if ($yesterdayAvgTicket > 0) {
            $waMessage .= "Ticket medio: R$ " . number_format($yesterdayAvgTicket, 2, ',', '.') . "\n";
        }
        $waMessage .= "\n";
        foreach ($insights as $i => $insight) {
            $waMessage .= ($i + 1) . ". " . $insight . "\n";
        }
        $waMessage .= "\nBoas vendas hoje!";

        // ── Send WhatsApp ──
        $waSent = false;
        $waMessageId = null;
        if (!empty($partner['phone'])) {
            try {
                $waResult = sendWhatsApp($partner['phone'], $waMessage);
                $waSent = $waResult['success'] ?? false;
                $waMessageId = $waResult['messageId'] ?? null;
                if ($waSent) $sent++;
            } catch (Exception $e) {
                error_log("[partner-whatsapp-insights] WhatsApp error for partner {$pid}: " . $e->getMessage());
                $errors++;
            }
        }

        // ── Log to database ──
        $stmtLog = $db->prepare("
            INSERT INTO om_partner_daily_insights
                (partner_id, insight_date, yesterday_orders, yesterday_revenue, yesterday_avg_ticket,
                 yesterday_rating, comparison_data, insights_text, insights_json,
                 whatsapp_sent, whatsapp_message_id, ai_model, ai_tokens_used)
            VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?::jsonb, ?, ?, ?, ?)
            ON CONFLICT (partner_id, insight_date) DO NOTHING
        ");
        $stmtLog->execute([
            $pid,
            $yesterday,
            $yesterdayOrders,
            $yesterdayRevenue,
            $yesterdayAvgTicket,
            $yesterdayRating,
            json_encode($comparison),
            $insightsText,
            json_encode($insights),
            $waSent ? 't' : 'f',
            $waMessageId,
            $aiModel ?: null,
            $aiTokens,
        ]);

        $processed++;

    } catch (Exception $e) {
        error_log("[partner-whatsapp-insights] Error processing partner {$pid}: " . $e->getMessage());
        $errors++;
    }
}

$summary = date('Y-m-d H:i:s') . " — WhatsApp insights: {$processed} partners processed, {$sent} sent, {$errors} errors\n";
error_log("[partner-whatsapp-insights] " . trim($summary));
echo $summary;
