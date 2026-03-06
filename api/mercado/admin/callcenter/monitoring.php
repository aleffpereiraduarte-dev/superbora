<?php
/**
 * /api/mercado/admin/callcenter/monitoring.php
 *
 * Real-time AI Monitoring Dashboard — Admin API
 *
 * GET actions:
 *   ?action=live_conversations         — List all active AI conversations right now
 *   ?action=conversation_detail&type=X&id=X — Full conversation transcript
 *   ?action=alerts                     — Active alerts needing attention
 *   ?action=system_health              — AI system health metrics
 *   ?action=metrics_stream             — Real-time metrics for polling (every 5s)
 *
 * POST actions:
 *   action=intervene                   — Admin takes over a conversation
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAuth.php';
require_once dirname(__DIR__, 4) . '/includes/classes/OmAudit.php';

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();
    $adminId = (int)$payload['uid'];

    $method = $_SERVER['REQUEST_METHOD'];

    // ════════════════════════════════════════════════════════════════════
    // GET actions
    // ════════════════════════════════════════════════════════════════════
    if ($method === 'GET') {
        $action = trim($_GET['action'] ?? '');

        if (!$action) {
            response(false, null, "Informe action: live_conversations, conversation_detail, alerts, system_health, metrics_stream", 400);
        }

        // ── Live AI conversations ──
        if ($action === 'live_conversations') {

            $conversations = [];

            // Active voice calls (AI handling or in progress)
            $voiceStmt = $db->query("
                SELECT
                    c.id,
                    'voice' AS type,
                    c.customer_phone,
                    c.customer_name,
                    c.store_identified AS store_name,
                    c.status,
                    c.ai_sentiment AS sentiment,
                    c.duration_seconds,
                    c.started_at,
                    c.ai_context,
                    c.notes,
                    EXTRACT(EPOCH FROM (NOW() - COALESCE(c.started_at, c.created_at)))::int AS elapsed_seconds
                FROM om_callcenter_calls c
                WHERE c.status IN ('ai_handling', 'in_progress')
                ORDER BY c.created_at DESC
            ");
            while ($row = $voiceStmt->fetch()) {
                $aiCtx = $row['ai_context'] ? json_decode($row['ai_context'], true) : [];
                $notes = $row['notes'] ? json_decode($row['notes'], true) : null;

                $itemsCount = 0;
                $step = 'unknown';
                $lastMessage = '';
                $qualityEstimate = 0;

                if (is_array($aiCtx)) {
                    $itemsCount = count($aiCtx['items'] ?? []);
                    $step = $aiCtx['step'] ?? 'greeting';
                    $qualityEstimate = (int)($aiCtx['quality_estimate'] ?? 0);
                }

                if (is_array($notes) && !empty($notes['history'])) {
                    $history = $notes['history'];
                    $last = end($history);
                    $lastMessage = is_string($last) ? substr($last, 0, 100) : (substr($last['content'] ?? '', 0, 100));
                }

                $conversations[] = [
                    'id'                    => (int)$row['id'],
                    'type'                  => 'voice',
                    'customer_phone'        => $row['customer_phone'],
                    'customer_name'         => $row['customer_name'] ?: 'Desconhecido',
                    'store_name'            => $row['store_name'],
                    'step'                  => $step,
                    'items_count'           => $itemsCount,
                    'duration_seconds'      => (int)$row['elapsed_seconds'],
                    'sentiment'             => $row['sentiment'],
                    'last_message_preview'  => $lastMessage,
                    'quality_score_estimate' => $qualityEstimate,
                    'status'                => $row['status'],
                ];
            }

            // Active WhatsApp conversations (bot mode)
            $waStmt = $db->query("
                SELECT
                    w.id,
                    'whatsapp' AS type,
                    w.phone AS customer_phone,
                    w.customer_name,
                    w.status,
                    w.ai_context,
                    w.last_message_at,
                    w.created_at,
                    EXTRACT(EPOCH FROM (NOW() - w.created_at))::int AS elapsed_seconds,
                    (
                        SELECT m.message FROM om_callcenter_wa_messages m
                        WHERE m.conversation_id = w.id
                        ORDER BY m.created_at DESC LIMIT 1
                    ) AS last_message
                FROM om_callcenter_whatsapp w
                WHERE w.status = 'bot'
                ORDER BY w.last_message_at DESC
            ");
            while ($row = $waStmt->fetch()) {
                $aiCtx = $row['ai_context'] ? json_decode($row['ai_context'], true) : [];

                $itemsCount = 0;
                $step = 'unknown';
                $sentiment = null;
                $storeName = null;
                $qualityEstimate = 0;

                if (is_array($aiCtx)) {
                    $itemsCount = count($aiCtx['items'] ?? []);
                    $step = $aiCtx['step'] ?? 'greeting';
                    $sentiment = $aiCtx['sentiment'] ?? null;
                    $storeName = $aiCtx['store_name'] ?? null;
                    $qualityEstimate = (int)($aiCtx['quality_estimate'] ?? 0);
                }

                // Count turns for this conversation
                $turnStmt = $db->prepare("
                    SELECT COUNT(*) FROM om_callcenter_wa_messages WHERE conversation_id = ?
                ");
                $turnStmt->execute([(int)$row['id']]);
                $turns = (int)$turnStmt->fetchColumn();

                $conversations[] = [
                    'id'                    => (int)$row['id'],
                    'type'                  => 'whatsapp',
                    'customer_phone'        => $row['customer_phone'],
                    'customer_name'         => $row['customer_name'] ?: 'Desconhecido',
                    'store_name'            => $storeName,
                    'step'                  => $step,
                    'items_count'           => $itemsCount,
                    'duration_seconds'      => (int)$row['elapsed_seconds'],
                    'sentiment'             => $sentiment,
                    'last_message_preview'  => $row['last_message'] ? substr($row['last_message'], 0, 100) : '',
                    'quality_score_estimate' => $qualityEstimate,
                    'status'                => $row['status'],
                    'turns'                 => $turns,
                ];
            }

            response(true, [
                'conversations' => $conversations,
                'total'         => count($conversations),
                'timestamp'     => date('c'),
            ]);
        }

        // ── Conversation detail (transcript) ──
        if ($action === 'conversation_detail') {
            $type = trim($_GET['type'] ?? '');
            $id = (int)($_GET['id'] ?? 0);

            if (!in_array($type, ['voice', 'whatsapp'], true)) {
                response(false, null, "type deve ser voice ou whatsapp", 400);
            }
            if ($id <= 0) {
                response(false, null, "Informe id", 400);
            }

            if ($type === 'voice') {
                $stmt = $db->prepare("
                    SELECT c.*, a.display_name AS agent_name
                    FROM om_callcenter_calls c
                    LEFT JOIN om_callcenter_agents a ON a.id = c.agent_id
                    WHERE c.id = ?
                ");
                $stmt->execute([$id]);
                $call = $stmt->fetch();

                if (!$call) {
                    response(false, null, "Chamada nao encontrada", 404);
                }

                $call['id'] = (int)$call['id'];
                $call['duration_seconds'] = (int)($call['duration_seconds'] ?? 0);
                $call['ai_context'] = $call['ai_context'] ? json_decode($call['ai_context'], true) : null;
                $call['ai_tags'] = $call['ai_tags'] ? (is_string($call['ai_tags']) ? json_decode($call['ai_tags'], true) : $call['ai_tags']) : [];

                // Parse transcript/history from notes
                $history = [];
                if ($call['notes']) {
                    $parsed = json_decode($call['notes'], true);
                    if (is_array($parsed) && isset($parsed['history'])) {
                        $history = $parsed['history'];
                    }
                }

                // Check if there is a quality score
                $qStmt = $db->prepare("
                    SELECT * FROM om_ai_quality_scores
                    WHERE conversation_type = 'voice' AND conversation_id = ?
                    ORDER BY created_at DESC LIMIT 1
                ");
                $qStmt->execute([$id]);
                $quality = $qStmt->fetch();

                response(true, [
                    'conversation' => $call,
                    'history'      => $history,
                    'quality'      => $quality ?: null,
                ]);
            }

            if ($type === 'whatsapp') {
                $convStmt = $db->prepare("
                    SELECT w.*, a.display_name AS agent_name
                    FROM om_callcenter_whatsapp w
                    LEFT JOIN om_callcenter_agents a ON a.id = w.agent_id
                    WHERE w.id = ?
                ");
                $convStmt->execute([$id]);
                $conv = $convStmt->fetch();

                if (!$conv) {
                    response(false, null, "Conversa nao encontrada", 404);
                }

                $conv['id'] = (int)$conv['id'];
                $conv['ai_context'] = $conv['ai_context'] ? json_decode($conv['ai_context'], true) : null;

                // Fetch all messages
                $msgStmt = $db->prepare("
                    SELECT id, direction, sender_type, message, message_type, media_url, ai_suggested, created_at
                    FROM om_callcenter_wa_messages
                    WHERE conversation_id = ?
                    ORDER BY created_at ASC
                ");
                $msgStmt->execute([$id]);
                $messages = $msgStmt->fetchAll();

                foreach ($messages as &$m) {
                    $m['id'] = (int)$m['id'];
                    $m['ai_suggested'] = (bool)$m['ai_suggested'];
                }
                unset($m);

                // Check quality score
                $qStmt = $db->prepare("
                    SELECT * FROM om_ai_quality_scores
                    WHERE conversation_type = 'whatsapp' AND conversation_id = ?
                    ORDER BY created_at DESC LIMIT 1
                ");
                $qStmt->execute([$id]);
                $quality = $qStmt->fetch();

                response(true, [
                    'conversation' => $conv,
                    'messages'     => $messages,
                    'quality'      => $quality ?: null,
                ]);
            }
        }

        // ── Active alerts ──
        if ($action === 'alerts') {
            $alerts = [];

            // 1. Frustrated customers in active conversations
            $frustratedVoice = $db->query("
                SELECT id, customer_phone, customer_name, store_identified, duration_seconds
                FROM om_callcenter_calls
                WHERE status IN ('ai_handling', 'in_progress') AND ai_sentiment = 'frustrated'
            ")->fetchAll();
            foreach ($frustratedVoice as $r) {
                $alerts[] = [
                    'type'     => 'frustrated_customer',
                    'severity' => 'critical',
                    'channel'  => 'voice',
                    'conv_id'  => (int)$r['id'],
                    'customer' => $r['customer_name'] ?: $r['customer_phone'],
                    'store'    => $r['store_identified'],
                    'detail'   => 'Cliente frustrado em chamada ativa (duracao: ' . (int)$r['duration_seconds'] . 's)',
                ];
            }

            $frustratedWa = $db->query("
                SELECT w.id, w.phone, w.customer_name, w.ai_context
                FROM om_callcenter_whatsapp w
                WHERE w.status = 'bot'
                  AND w.ai_context::text ILIKE '%frustrated%'
            ")->fetchAll();
            foreach ($frustratedWa as $r) {
                $alerts[] = [
                    'type'     => 'frustrated_customer',
                    'severity' => 'critical',
                    'channel'  => 'whatsapp',
                    'conv_id'  => (int)$r['id'],
                    'customer' => $r['customer_name'] ?: $r['phone'],
                    'detail'   => 'Cliente frustrado no WhatsApp',
                ];
            }

            // 2. Long conversations (>15 turns without resolution)
            $longConvos = $db->query("
                SELECT w.id, w.phone, w.customer_name, w.created_at,
                       (SELECT COUNT(*) FROM om_callcenter_wa_messages m WHERE m.conversation_id = w.id) AS turn_count
                FROM om_callcenter_whatsapp w
                WHERE w.status = 'bot'
                  AND (SELECT COUNT(*) FROM om_callcenter_wa_messages m WHERE m.conversation_id = w.id) > 15
            ")->fetchAll();
            foreach ($longConvos as $r) {
                $alerts[] = [
                    'type'     => 'long_conversation',
                    'severity' => 'warning',
                    'channel'  => 'whatsapp',
                    'conv_id'  => (int)$r['id'],
                    'customer' => $r['customer_name'] ?: $r['phone'],
                    'detail'   => (int)$r['turn_count'] . ' mensagens sem resolucao',
                ];
            }

            // 3. High-value carts without conversion (>R$100)
            $highValueVoice = $db->query("
                SELECT c.id, c.customer_phone, c.customer_name, c.ai_context
                FROM om_callcenter_calls c
                WHERE c.status IN ('ai_handling', 'in_progress')
                  AND c.order_id IS NULL
                  AND c.ai_context IS NOT NULL
            ")->fetchAll();
            foreach ($highValueVoice as $r) {
                $ctx = json_decode($r['ai_context'], true);
                if (!is_array($ctx)) continue;
                $items = $ctx['items'] ?? [];
                $total = 0;
                foreach ($items as $item) {
                    $total += (float)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
                }
                if ($total >= 100) {
                    $alerts[] = [
                        'type'     => 'high_value_no_conversion',
                        'severity' => 'warning',
                        'channel'  => 'voice',
                        'conv_id'  => (int)$r['id'],
                        'customer' => $r['customer_name'] ?: $r['customer_phone'],
                        'detail'   => 'Carrinho R$' . number_format($total, 2, ',', '.') . ' sem conversao',
                    ];
                }
            }

            // 4. Repeated callers (same phone calling back within 1 hour)
            $repeats = $db->query("
                SELECT customer_phone, customer_name, COUNT(*) AS call_count,
                       MAX(id) AS latest_call_id
                FROM om_callcenter_calls
                WHERE created_at >= NOW() - INTERVAL '1 hour'
                  AND customer_phone IS NOT NULL
                GROUP BY customer_phone, customer_name
                HAVING COUNT(*) >= 2
            ")->fetchAll();
            foreach ($repeats as $r) {
                $alerts[] = [
                    'type'     => 'repeat_caller',
                    'severity' => 'warning',
                    'channel'  => 'voice',
                    'conv_id'  => (int)$r['latest_call_id'],
                    'customer' => $r['customer_name'] ?: $r['customer_phone'],
                    'detail'   => (int)$r['call_count'] . ' chamadas na ultima hora',
                ];
            }

            // 5. Circuit breaker / retry errors (last 15 min)
            $retryErrors = $db->query("
                SELECT error_type, COUNT(*) AS count,
                       MAX(created_at) AS last_at
                FROM om_ai_retry_log
                WHERE created_at >= NOW() - INTERVAL '15 minutes'
                  AND success = FALSE
                GROUP BY error_type
                HAVING COUNT(*) >= 3
            ")->fetchAll();
            foreach ($retryErrors as $r) {
                $alerts[] = [
                    'type'     => 'circuit_breaker',
                    'severity' => 'critical',
                    'channel'  => 'system',
                    'detail'   => 'Erro ' . $r['error_type'] . ': ' . (int)$r['count'] . ' falhas nos ultimos 15 min',
                ];
            }

            // Sort by severity (critical first)
            usort($alerts, function ($a, $b) {
                $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
                return ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9);
            });

            response(true, [
                'alerts' => $alerts,
                'total'  => count($alerts),
                'timestamp' => date('c'),
            ]);
        }

        // ── System health ──
        if ($action === 'system_health') {

            // Claude API: avg response time and error rate (last 5 min)
            $aiMetrics = $db->query("
                SELECT
                    COUNT(*) AS total_requests,
                    COUNT(*) FILTER (WHERE success = TRUE) AS successful,
                    COUNT(*) FILTER (WHERE success = FALSE) AS failed,
                    COALESCE(AVG(response_time_ms), 0)::int AS avg_response_ms,
                    COALESCE(MAX(response_time_ms), 0)::int AS max_response_ms
                FROM om_ai_retry_log
                WHERE created_at >= NOW() - INTERVAL '5 minutes'
            ")->fetch();

            $totalReqs = (int)$aiMetrics['total_requests'];
            $failedReqs = (int)$aiMetrics['failed'];
            $errorRate = $totalReqs > 0 ? round(($failedReqs / $totalReqs) * 100, 1) : 0;

            // Circuit breaker status: if >50% errors in last 5 min, consider open
            $circuitBreakerStatus = 'closed';
            if ($totalReqs >= 5 && $errorRate > 50) {
                $circuitBreakerStatus = 'open';
            } elseif ($totalReqs >= 3 && $errorRate > 25) {
                $circuitBreakerStatus = 'half_open';
            }

            // Active conversations count by type
            $activeVoice = (int)$db->query("
                SELECT COUNT(*) FROM om_callcenter_calls WHERE status IN ('ai_handling', 'in_progress')
            ")->fetchColumn();

            $activeWhatsapp = (int)$db->query("
                SELECT COUNT(*) FROM om_callcenter_whatsapp WHERE status IN ('bot', 'assigned')
            ")->fetchColumn();

            // Queue depth
            $queueDepth = (int)$db->query("
                SELECT COUNT(*) FROM om_callcenter_queue
                WHERE picked_at IS NULL AND abandoned_at IS NULL
            ")->fetchColumn();

            // TTS cache check: count recent cache entries (proxy for health)
            $ttsCacheCount = 0;
            $ttsDir = '/tmp/tts-cache';
            if (is_dir($ttsDir)) {
                $files = glob($ttsDir . '/*.mp3');
                $ttsCacheCount = $files ? count($files) : 0;
            }
            $ttsStatus = 'unknown';
            // If there were recent AI calls and cache exists, TTS likely working
            if ($activeVoice > 0 && $ttsCacheCount > 0) {
                $ttsStatus = 'healthy';
            } elseif ($activeVoice === 0) {
                $ttsStatus = 'idle';
            } else {
                $ttsStatus = 'degraded';
            }

            // WebSocket server check (port 8080)
            $wsStatus = 'unknown';
            $wsSocket = @fsockopen('127.0.0.1', 8080, $errno, $errstr, 1);
            if ($wsSocket) {
                $wsStatus = 'healthy';
                fclose($wsSocket);
            } else {
                $wsStatus = 'down';
            }

            response(true, [
                'claude_api' => [
                    'avg_response_ms'   => (int)$aiMetrics['avg_response_ms'],
                    'max_response_ms'   => (int)$aiMetrics['max_response_ms'],
                    'error_rate'        => $errorRate,
                    'total_requests_5m' => $totalReqs,
                    'circuit_breaker'   => $circuitBreakerStatus,
                ],
                'conversations' => [
                    'voice_active'    => $activeVoice,
                    'whatsapp_active' => $activeWhatsapp,
                    'total_active'    => $activeVoice + $activeWhatsapp,
                ],
                'queue' => [
                    'depth' => $queueDepth,
                ],
                'tts' => [
                    'status'      => $ttsStatus,
                    'cache_files' => $ttsCacheCount,
                ],
                'websocket' => [
                    'status' => $wsStatus,
                ],
                'timestamp' => date('c'),
            ]);
        }

        // ── Metrics stream (polling every 5s) ──
        if ($action === 'metrics_stream') {

            $activeConvos = (int)$db->query("
                SELECT
                    (SELECT COUNT(*) FROM om_callcenter_calls WHERE status IN ('ai_handling', 'in_progress'))
                    +
                    (SELECT COUNT(*) FROM om_callcenter_whatsapp WHERE status IN ('bot', 'assigned'))
            ")->fetchColumn();

            $ordersLastHour = (int)$db->query("
                SELECT COUNT(*) FROM om_market_orders
                WHERE source = 'callcenter' AND date_added >= NOW() - INTERVAL '1 hour'
            ")->fetchColumn();

            // AI success rate last 1 hour (calls that resulted in order vs total AI calls)
            $aiStats1h = $db->query("
                SELECT
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE order_id IS NOT NULL) AS with_order
                FROM om_callcenter_calls
                WHERE created_at >= NOW() - INTERVAL '1 hour'
                  AND (status = 'ai_handling' OR notes::text LIKE '%ai_context%')
            ")->fetch();
            $total1h = (int)$aiStats1h['total'];
            $aiSuccessRate = $total1h > 0 ? round(((int)$aiStats1h['with_order'] / $total1h) * 100, 1) : 0;

            // Avg quality score today
            $avgQuality = (float)$db->query("
                SELECT COALESCE(AVG(overall_score), 0) FROM om_ai_quality_scores
                WHERE created_at::date = CURRENT_DATE
            ")->fetchColumn();

            // Active alerts count
            $alertsActive = 0;
            // Frustrated
            $alertsActive += (int)$db->query("
                SELECT COUNT(*) FROM om_callcenter_calls
                WHERE status IN ('ai_handling', 'in_progress') AND ai_sentiment = 'frustrated'
            ")->fetchColumn();
            // Retry errors
            $alertsActive += (int)$db->query("
                SELECT COUNT(DISTINCT error_type) FROM om_ai_retry_log
                WHERE created_at >= NOW() - INTERVAL '15 minutes' AND success = FALSE
                GROUP BY error_type HAVING COUNT(*) >= 3
            ")->fetchColumn();

            // Revenue today from callcenter
            $revenueToday = (float)$db->query("
                SELECT COALESCE(SUM(total), 0) FROM om_market_orders
                WHERE source = 'callcenter' AND date_added::date = CURRENT_DATE
            ")->fetchColumn();

            response(true, [
                'conversations_active'  => $activeConvos,
                'orders_last_hour'      => $ordersLastHour,
                'ai_success_rate_1h'    => $aiSuccessRate,
                'avg_quality_score_today' => round($avgQuality, 1),
                'alerts_active'         => $alertsActive,
                'revenue_today'         => round($revenueToday, 2),
                'timestamp'             => date('c'),
            ]);
        }

        response(false, null, "Action GET invalida. Valores: live_conversations, conversation_detail, alerts, system_health, metrics_stream", 400);
    }

    // ════════════════════════════════════════════════════════════════════
    // POST actions
    // ════════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? ($_POST['action'] ?? '');

        if (!$action) {
            response(false, null, "Informe action: intervene", 400);
        }

        // ── Admin intervention ──
        if ($action === 'intervene') {
            $convType = trim($input['conversation_type'] ?? '');
            $convId = (int)($input['conversation_id'] ?? 0);
            $interventionAction = trim($input['action_type'] ?? $input['intervention'] ?? '');

            if (!in_array($convType, ['voice', 'whatsapp'], true)) {
                response(false, null, "conversation_type deve ser voice ou whatsapp", 400);
            }
            if ($convId <= 0) {
                response(false, null, "conversation_id obrigatorio", 400);
            }

            $validActions = ['observe', 'message', 'takeover'];
            if (!in_array($interventionAction, $validActions, true)) {
                response(false, null, "intervention deve ser: observe, message, takeover", 400);
            }

            // ── Observe: create monitor session ──
            if ($interventionAction === 'observe') {
                // Verify conversation exists
                if ($convType === 'voice') {
                    $check = $db->prepare("SELECT id, status, customer_phone, customer_name FROM om_callcenter_calls WHERE id = ?");
                } else {
                    $check = $db->prepare("SELECT id, status, phone AS customer_phone, customer_name FROM om_callcenter_whatsapp WHERE id = ?");
                }
                $check->execute([$convId]);
                $conv = $check->fetch();

                if (!$conv) {
                    response(false, null, "Conversa nao encontrada", 404);
                }

                $stmt = $db->prepare("
                    INSERT INTO om_ai_monitor_sessions (admin_id, conversation_type, conversation_id, action)
                    VALUES (?, ?, ?, 'observe')
                    RETURNING id
                ");
                $stmt->execute([$adminId, $convType, $convId]);
                $sessionId = (int)$stmt->fetchColumn();

                response(true, [
                    'session_id'   => $sessionId,
                    'conversation' => $conv,
                    'message'      => 'Sessao de monitoramento iniciada',
                ]);
            }

            // ── Message: send admin message as if from bot ──
            if ($interventionAction === 'message') {
                $messageText = trim($input['message'] ?? '');
                if ($messageText === '') {
                    response(false, null, "message e obrigatorio para acao message", 400);
                }

                if ($convType === 'whatsapp') {
                    // Verify conversation
                    $check = $db->prepare("SELECT id, phone, status FROM om_callcenter_whatsapp WHERE id = ?");
                    $check->execute([$convId]);
                    $conv = $check->fetch();

                    if (!$conv) {
                        response(false, null, "Conversa nao encontrada", 404);
                    }

                    // Insert message as bot/outbound
                    $stmt = $db->prepare("
                        INSERT INTO om_callcenter_wa_messages (conversation_id, direction, sender_type, message, message_type)
                        VALUES (?, 'outbound', 'bot', ?, 'text')
                    ");
                    $stmt->execute([$convId, $messageText]);

                    // Update last_message_at
                    $db->prepare("UPDATE om_callcenter_whatsapp SET last_message_at = NOW() WHERE id = ?")->execute([$convId]);

                    // Try to send via Z-API if available
                    if (function_exists('zapiSendText')) {
                        try {
                            zapiSendText($conv['phone'], $messageText);
                        } catch (Exception $e) {
                            error_log("[monitoring/intervene] Z-API send failed: " . $e->getMessage());
                        }
                    }

                    // Log monitor session
                    $db->prepare("
                        INSERT INTO om_ai_monitor_sessions (admin_id, conversation_type, conversation_id, action, notes)
                        VALUES (?, ?, ?, 'intervene', ?)
                    ")->execute([$adminId, $convType, $convId, json_encode(['message_sent' => $messageText])]);

                    response(true, ['message' => 'Mensagem enviada']);
                }

                if ($convType === 'voice') {
                    // For voice, we can only log the intent — real-time voice injection requires Twilio
                    $db->prepare("
                        INSERT INTO om_ai_monitor_sessions (admin_id, conversation_type, conversation_id, action, notes)
                        VALUES (?, ?, ?, 'intervene', ?)
                    ")->execute([$adminId, $convType, $convId, json_encode(['message_queued' => $messageText])]);

                    response(true, ['message' => 'Mensagem registrada (voice injection requer Twilio conference)']);
                }
            }

            // ── Takeover: admin takes control ──
            if ($interventionAction === 'takeover') {

                // Get admin's agent ID
                $agentStmt = $db->prepare("SELECT id FROM om_callcenter_agents WHERE admin_id = ? LIMIT 1");
                $agentStmt->execute([$adminId]);
                $agentRow = $agentStmt->fetch();
                $agentId = $agentRow ? (int)$agentRow['id'] : null;

                if ($convType === 'whatsapp') {
                    $check = $db->prepare("SELECT id, status FROM om_callcenter_whatsapp WHERE id = ?");
                    $check->execute([$convId]);
                    $conv = $check->fetch();

                    if (!$conv) {
                        response(false, null, "Conversa nao encontrada", 404);
                    }

                    // Change status to assigned with admin as agent
                    $stmt = $db->prepare("
                        UPDATE om_callcenter_whatsapp
                        SET status = 'assigned', agent_id = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$agentId, $convId]);

                    // Log takeover
                    $db->prepare("
                        INSERT INTO om_ai_monitor_sessions (admin_id, conversation_type, conversation_id, action, notes)
                        VALUES (?, ?, ?, 'takeover', '{\"reason\":\"admin_takeover\"}')
                    ")->execute([$adminId, $convType, $convId]);

                    response(true, ['message' => 'Conversa WhatsApp assumida pelo admin']);
                }

                if ($convType === 'voice') {
                    // For voice, queue for transfer
                    $check = $db->prepare("SELECT id, status, twilio_call_sid FROM om_callcenter_calls WHERE id = ?");
                    $check->execute([$convId]);
                    $call = $check->fetch();

                    if (!$call) {
                        response(false, null, "Chamada nao encontrada", 404);
                    }

                    // Update call to queue for human agent transfer
                    $stmt = $db->prepare("
                        UPDATE om_callcenter_calls
                        SET status = 'in_progress', agent_id = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$agentId, $convId]);

                    // Create queue entry for the agent
                    $db->prepare("
                        INSERT INTO om_callcenter_queue (call_id, customer_phone, picked_by, picked_at)
                        SELECT ?, customer_phone, ?, NOW()
                        FROM om_callcenter_calls WHERE id = ?
                    ")->execute([$convId, $agentId, $convId]);

                    // Log takeover
                    $db->prepare("
                        INSERT INTO om_ai_monitor_sessions (admin_id, conversation_type, conversation_id, action, notes)
                        VALUES (?, ?, ?, 'takeover', '{\"reason\":\"admin_takeover\"}')
                    ")->execute([$adminId, $convType, $convId]);

                    response(true, ['message' => 'Chamada transferida para admin']);
                }
            }
        }

        response(false, null, "Action POST invalida. Valores: intervene", 400);
    }

    // Method not allowed
    response(false, null, "Metodo nao permitido", 405);

} catch (Exception $e) {
    error_log("[admin/callcenter/monitoring] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
